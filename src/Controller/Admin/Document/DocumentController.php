<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\AdminBundle\Controller\Admin\Document;

use Exception;
use Imagick;
use Pimcore;
use Pimcore\Bundle\AdminBundle\Controller\Admin\ElementControllerBase;
use Pimcore\Bundle\AdminBundle\Controller\Traits\DocumentTreeConfigTrait;
use Pimcore\Bundle\AdminBundle\Controller\Traits\UserNameTrait;
use Pimcore\Bundle\AdminBundle\Event\AdminEvents;
use Pimcore\Bundle\AdminBundle\Event\ElementAdminStyleEvent;
use Pimcore\Bundle\AdminBundle\Service\ElementService;
use Pimcore\Cache\RuntimeCache;
use Pimcore\Config;
use Pimcore\Controller\KernelControllerEventInterface;
use Pimcore\Db;
use Pimcore\Event\Traits\RecursionBlockingEventDispatchHelperTrait;
use Pimcore\Image\Chromium;
use Pimcore\Logger;
use Pimcore\Model\Document;
use Pimcore\Model\Document\DocType;
use Pimcore\Model\Element\Service;
use Pimcore\Model\Exception\ConfigWriteException;
use Pimcore\Model\Site;
use Pimcore\Model\Version;
use Pimcore\Tool;
use Pimcore\Tool\Session;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @Route("/document")
 *
 * @internal
 */
class DocumentController extends ElementControllerBase implements KernelControllerEventInterface
{
    use DocumentTreeConfigTrait;
    use UserNameTrait;
    use RecursionBlockingEventDispatchHelperTrait;

    protected Document\Service $_documentService;

    /**
     * @Route("/tree-get-root", name="pimcore_admin_document_document_treegetroot", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function treeGetRootAction(Request $request): JsonResponse
    {
        return parent::treeGetRootAction($request);
    }

    /**
     * @Route("/delete-info", name="pimcore_admin_document_document_deleteinfo", methods={"GET"})
     *
     * @param Request $request
     * @param EventDispatcherInterface $eventDispatcher
     *
     * @return JsonResponse
     */
    public function deleteInfoAction(Request $request, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        return parent::deleteInfoAction($request, $eventDispatcher);
    }

    /**
     * @Route("/get-data-by-id", name="pimcore_admin_document_document_getdatabyid", methods={"GET"})
     *
     *
     */
    public function getDataByIdAction(Request $request, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        $document = Document::getById((int) $request->get('id'));

        if (!$document) {
            throw $this->createNotFoundException('Document not found');
        }

        $document = clone $document;
        $data = $document->getObjectVars();
        $data['versionDate'] = $document->getModificationDate();

        $userOwnerName = $this->getUserName($document->getUserOwner());
        $userModificationName = ($document->getUserOwner() == $document->getUserModification()) ? $userOwnerName : $this->getUserName($document->getUserModification());
        $data['userOwnerUsername'] = $userOwnerName['userName'];
        $data['userOwnerFullname'] = $userOwnerName['fullName'];
        $data['userModificationUsername'] = $userModificationName['userName'];
        $data['userModificationFullname'] = $userModificationName['fullName'];

        $data['php'] = [
            'classes' => array_merge([get_class($document)], array_values(class_parents($document))),
            'interfaces' => array_values(class_implements($document)),
        ];

        $this->addAdminStyle($document, ElementAdminStyleEvent::CONTEXT_EDITOR, $data);

        $event = new GenericEvent($this, [
            'data' => $data,
            'document' => $document,
        ]);
        $eventDispatcher->dispatch($event, AdminEvents::DOCUMENT_GET_PRE_SEND_DATA);
        $data = $event->getArgument('data');

        if ($document->isAllowed('view')) {
            return $this->adminJson($data);
        }

        throw $this->createAccessDeniedHttpException();
    }

    /**
     * @Route("/tree-get-children-by-id", name="pimcore_admin_document_document_treegetchildrenbyid", methods={"GET"})
     *
     *
     */
    public function treeGetChildrenByIdAction(Request $request, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        $allParams = array_merge($request->request->all(), $request->query->all());

        $filter = $request->get('filter');
        $limit = (int)($allParams['limit'] ?? 100000000);
        $offset = (int)($allParams['start'] ?? 0);

        if (!is_null($filter)) {
            if (substr($filter, -1) != '*') {
                $filter .= '*';
            }
            $filter = str_replace('*', '%', $filter);
            $limit = 100;
            $offset = 0;
        }

        $document = Document::getById($allParams['node']);
        if (!$document) {
            throw $this->createNotFoundException('Document was not found');
        }

        $documents = [];
        $cv = [];
        if ($document->hasChildren()) {
            if ($allParams['view']) {
                $cv = ElementService::getCustomViewById($allParams['view']);
            }

            $db = Db::get();

            $list = new Document\Listing();

            $condition = 'parentId =  ' . $db->quote($document->getId());

            if (!$this->getAdminUser()->isAdmin()) {
                $userIds = $this->getAdminUser()->getRoles();
                $currentUserId = $this->getAdminUser()->getId();
                $userIds[] = $currentUserId;

                $inheritedPermission = $document->getDao()->isInheritingPermission('list', $userIds);

                $anyAllowedRowOrChildren = 'EXISTS(SELECT list FROM users_workspaces_document uwd WHERE userId IN (' . implode(',', $userIds) . ') AND list=1 AND LOCATE(CONCAT(`path`,`key`),cpath)=1 AND
                NOT EXISTS(SELECT list FROM users_workspaces_document WHERE userId =' . $currentUserId . '  AND list=0 AND cpath = uwd.cpath))';
                $isDisallowedCurrentRow = 'EXISTS(SELECT list FROM users_workspaces_document WHERE userId IN (' . implode(',', $userIds) . ')  AND cid = id AND list=0)';

                $condition .= ' AND IF(' . $anyAllowedRowOrChildren . ',1,IF(' . $inheritedPermission . ', ' . $isDisallowedCurrentRow . ' = 0, 0)) = 1';
            }

            if ($filter) {
                $condition = '(' . $condition . ')' . ' AND CAST(documents.key AS CHAR CHARACTER SET utf8) COLLATE utf8_general_ci LIKE ' . $db->quote($filter);
            }

            $list->setCondition($condition);

            $list->setOrderKey(['index', 'id']);
            $list->setOrder(['asc', 'asc']);

            $list->setLimit($limit);
            $list->setOffset($offset);

            Service::addTreeFilterJoins($cv, $list);

            $beforeListLoadEvent = new GenericEvent($this, [
                'list' => $list,
                'context' => $allParams,
            ]);

            $eventDispatcher->dispatch($beforeListLoadEvent, AdminEvents::DOCUMENT_LIST_BEFORE_LIST_LOAD);
            /** @var Document\Listing $list */
            $list = $beforeListLoadEvent->getArgument('list');

            $childrenList = $list->load();

            foreach ($childrenList as $childDocument) {
                $documentTreeNode = $this->getTreeNodeConfig($childDocument);
                // the !isset is for printContainer case, there are no permissions sets there
                if (!isset($documentTreeNode['permissions']['list']) || $documentTreeNode['permissions']['list'] == 1) {
                    $documents[] = $documentTreeNode;
                }
            }
        }

        //Hook for modifying return value - e.g. for changing permissions based on document data
        $event = new GenericEvent($this, [
            'documents' => $documents,
        ]);
        $eventDispatcher->dispatch($event, AdminEvents::DOCUMENT_TREE_GET_CHILDREN_BY_ID_PRE_SEND_DATA);
        $documents = $event->getArgument('documents');

        if ($allParams['limit']) {
            return $this->adminJson([
                'offset' => $offset,
                'limit' => $limit,
                'total' => $document->getChildAmount($this->getAdminUser()),
                'nodes' => $documents,
                'filter' => $request->get('filter') ? $request->get('filter') : '',
                'inSearch' => (int)$request->get('inSearch'),
            ]);
        } else {
            return $this->adminJson($documents);
        }
    }

    /**
     * @Route("/add", name="pimcore_admin_document_document_add", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function addAction(Request $request): JsonResponse
    {
        $success = false;
        $errorMessage = '';

        // check for permission
        $parentDocument = Document::getById((int)$request->get('parentId'));
        $document = null;
        if ($parentDocument->isAllowed('create')) {
            $intendedPath = $parentDocument->getRealFullPath() . '/' . $request->get('key');

            if (!Document\Service::pathExists($intendedPath)) {
                $createValues = [
                    'userOwner' => $this->getAdminUser()->getId(),
                    'userModification' => $this->getAdminUser()->getId(),
                    'published' => false,
                ];

                $createValues['key'] = Service::getValidKey($request->get('key'), 'document');

                // check for a docType
                $docType = Document\DocType::getById($request->get('docTypeId', ''));

                if ($docType) {
                    $createValues['template'] = $docType->getTemplate();
                    $createValues['controller'] = $docType->getController();
                    $createValues['staticGeneratorEnabled'] = $docType->getStaticGeneratorEnabled();
                } elseif ($translationsBaseDocumentId = $request->get('translationsBaseDocument')) {
                    $translationsBaseDocument = Document::getById((int) $translationsBaseDocumentId);
                    if ($translationsBaseDocument instanceof Document\PageSnippet) {
                        $createValues['template'] = $translationsBaseDocument->getTemplate();
                        $createValues['controller'] = $translationsBaseDocument->getController();
                    }
                } elseif ($request->get('type') == 'page' || $request->get('type') == 'snippet' || $request->get('type') == 'email') {
                    $createValues['controller'] = $this->getParameter('pimcore.documents.default_controller');
                }

                if ($request->get('inheritanceSource')) {
                    $createValues['contentMainDocumentId'] = $request->get('inheritanceSource');
                }

                switch ($request->get('type')) {
                    case 'page':
                        $document = Document\Page::create($parentDocument->getId(), $createValues, false);
                        $document->setTitle($request->get('title', null));
                        $document->setProperty('navigation_name', 'text', $request->get('name', null), false, false);
                        $document->save();
                        $success = true;

                        break;
                    case 'snippet':
                        $document = Document\Snippet::create($parentDocument->getId(), $createValues);
                        $success = true;

                        break;
                    case 'email': //ckogler
                        $document = Document\Email::create($parentDocument->getId(), $createValues);
                        $success = true;

                        break;
                    case 'link':
                        $document = Document\Link::create($parentDocument->getId(), $createValues);
                        $success = true;

                        break;
                    case 'hardlink':
                        $document = Document\Hardlink::create($parentDocument->getId(), $createValues);
                        $success = true;

                        break;
                    case 'folder':
                        $document = Document\Folder::create($parentDocument->getId(), $createValues);
                        $document->setPublished(true);

                        try {
                            $document->save();
                            $success = true;
                        } catch (Exception $e) {
                            return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
                        }

                        break;
                    default:
                        $classname = \Pimcore::getContainer()->get('pimcore.class.resolver.document')->resolve($request->get('type'));

                        if (Tool::classExists($classname)) {
                            $document = $classname::create($parentDocument->getId(), $createValues);

                            try {
                                $document->save();
                                $success = true;
                            } catch (Exception $e) {
                                return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
                            }

                            break;
                        } else {
                            Logger::debug("Unknown document type, can't add [ " . $request->get('type') . ' ] ');
                        }

                        break;
                }
            } else {
                $errorMessage = "prevented adding a document because document with same path+key [ $intendedPath ] already exists";
                Logger::debug($errorMessage);
            }
        } else {
            $errorMessage = 'prevented adding a document because of missing permissions';
            Logger::debug($errorMessage);
        }

        if ($success && $document instanceof Document) {
            if ($translationsBaseDocumentId = $request->get('translationsBaseDocument')) {
                $translationsBaseDocument = Document::getById((int) $translationsBaseDocumentId);

                $properties = $translationsBaseDocument->getProperties();
                $properties = array_merge($properties, $document->getProperties());
                $document->setProperties($properties);
                $document->setProperty('language', 'text', $request->get('language'), false, true);
                $document->save();

                $service = new Document\Service();
                $service->addTranslation($translationsBaseDocument, $document);
            }

            return $this->adminJson([
                'success' => $success,
                'id' => $document->getId(),
                'type' => $document->getType(),
            ]);
        }

        return $this->adminJson([
            'success' => $success,
            'message' => $errorMessage,
        ]);
    }

    /**
     * @Route("/delete", name="pimcore_admin_document_document_delete", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function deleteAction(Request $request): JsonResponse
    {
        $type = $request->get('type');

        if ($type === 'children') {
            $parentDocument = Document::getById((int) $request->get('id'));

            $list = new Document\Listing();
            $list->setCondition('`path` LIKE ?', [$list->escapeLike($parentDocument->getRealFullPath()) . '/%']);
            $list->setLimit((int)$request->get('amount'));
            $list->setOrderKey('LENGTH(`path`)', false);
            $list->setOrder('DESC');

            $documents = $list->load();

            $deletedItems = [];
            foreach ($documents as $document) {
                $deletedItems[$document->getId()] = $document->getRealFullPath();
                if ($document->isAllowed('delete') && !$document->isLocked()) {
                    $document->delete();
                }
            }

            return $this->adminJson(['success' => true, 'deleted' => $deletedItems]);
        }
        if ($id = $request->get('id')) {
            $document = Document::getById((int) $id);
            if ($document && $document->isAllowed('delete')) {
                try {
                    if ($document->isLocked()) {
                        throw new Exception('prevented deleting document, because it is locked: ID: ' . $document->getId());
                    }
                    $document->delete();

                    return $this->adminJson(['success' => true]);
                } catch (Exception $e) {
                    Logger::err((string) $e);

                    return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
                }
            }
        }

        throw $this->createAccessDeniedHttpException();
    }

    /**
     * @Route("/update", name="pimcore_admin_document_document_update", methods={"PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws Exception
     */
    public function updateAction(Request $request): JsonResponse
    {
        $success = false;
        $allowUpdate = true;

        $document = Document::getById((int) $request->get('id'));

        $oldPath = $document->getDao()->getCurrentFullPath();
        $oldDocument = Document::getById($document->getId(), ['force' => true]);

        // this prevents the user from renaming, relocating (actions in the tree) if the newest version isn't the published one
        // the reason is that otherwise the content of the newer not published version will be overwritten
        if ($document instanceof Document\PageSnippet) {
            $latestVersion = $document->getLatestVersion();
            if ($latestVersion && $latestVersion->getData()->getModificationDate() != $document->getModificationDate()) {
                return $this->adminJson(['success' => false, 'message' => "You can't rename or relocate if there's a newer not published version"]);
            }
        }

        if ($document->isAllowed('settings')) {
            // if the position is changed the path must be changed || also from the children
            if ($parentId = $request->get('parentId')) {
                $parentDocument = Document::getById((int) $parentId);

                //check if parent is changed
                if ($document->getParentId() != $parentDocument->getId()) {
                    if (!$parentDocument->isAllowed('create')) {
                        throw new Exception('Prevented moving document - no create permission on new parent ');
                    }

                    $intendedPath = $parentDocument->getRealPath();
                    $pKey = $parentDocument->getKey();
                    if (!empty($pKey)) {
                        $intendedPath .= $parentDocument->getKey() . '/';
                    }

                    $documentWithSamePath = Document::getByPath($intendedPath . $document->getKey());

                    if ($documentWithSamePath != null) {
                        $allowUpdate = false;
                    }

                    if ($document->isLocked()) {
                        $allowUpdate = false;
                    }
                }
            }

            if ($allowUpdate) {
                $blockedVars = ['controller', 'action', 'module'];

                if (!$document->isAllowed('rename') && $request->get('key')) {
                    $blockedVars[] = 'key';
                    Logger::debug('prevented renaming document because of missing permissions ');
                }

                $updateData = array_merge($request->request->all(), $request->query->all());

                foreach ($updateData as $key => $value) {
                    if (!in_array($key, $blockedVars)) {
                        $document->setValue($key, $value);
                    }
                }

                $document->setUserModification($this->getAdminUser()->getId());

                try {
                    $document->save();

                    if ($request->get('index') !== null) {
                        $this->updateIndexesOfDocumentSiblings($document, $request->get('index'));
                    }

                    $success = true;
                    if ($oldPath && $oldPath != $document->getRealFullPath()) {
                        $this->firePostMoveEvent($document, $oldDocument, $oldPath);
                    }
                } catch (Exception $e) {
                    return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
                }
            } else {
                $msg = 'Prevented moving document, because document with same path+key already exists or the document is locked. ID: ' . $document->getId();
                Logger::debug($msg);

                return $this->adminJson(['success' => false, 'message' => $msg]);
            }
        } elseif ($document->isAllowed('rename') && $request->get('key')) {
            //just rename
            try {
                $document->setKey($request->get('key'));
                $document->setUserModification($this->getAdminUser()->getId());
                $document->save();
                $success = true;

                if ($oldPath && $oldPath != $document->getRealFullPath()) {
                    $this->firePostMoveEvent($document, $oldDocument, $oldPath);
                }
            } catch (Exception $e) {
                return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
            }
        } else {
            Logger::debug('Prevented update document, because of missing permissions.');
        }

        return $this->adminJson(['success' => $success]);
    }

    private function firePostMoveEvent(Document $document, Document $oldDocument, string $oldPath): void
    {
        $arguments = [
            'oldPath' => $oldPath,
            'oldDocument' => $oldDocument,
        ];
        $documentEvent = new Pimcore\Event\Model\DocumentEvent($document, $arguments);
        $this->dispatchEvent($documentEvent, Pimcore\Event\DocumentEvents::POST_MOVE_ACTION);
    }

    protected function updateIndexesOfDocumentSiblings(Document $document, int $newIndex): void
    {
        $updateLatestVersionIndex = function ($document, $newIndex) {
            if ($document instanceof Document\PageSnippet && $latestVersion = $document->getLatestVersion()) {
                $document = $latestVersion->loadData();
                $document->setIndex($newIndex);
                $latestVersion->save();
            }
        };

        // if changed the index change also all documents on the same level

        $document->saveIndex($newIndex);

        $list = new Document\Listing();
        $list->setCondition('parentId = ? AND id != ?', [$document->getParentId(), $document->getId()]);
        $list->setOrderKey('index');
        $list->setOrder('asc');
        $childrenList = $list->load();

        $count = 0;
        foreach ($childrenList as $child) {
            if ($count == $newIndex) {
                $count++;
            }
            $child->saveIndex($count);
            $updateLatestVersionIndex($child, $count);
            $count++;
        }
    }

    /**
     * @Route("/doc-types", name="pimcore_admin_document_document_doctypesget", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function docTypesGetAction(Request $request): JsonResponse
    {
        // get list of types
        $list = new DocType\Listing();

        $docTypes = [];
        foreach ($list->getDocTypes() as $type) {
            if ($this->getAdminUser()->isAllowed($type->getId(), 'docType')) {
                $data = $type->getObjectVars();
                $data['writeable'] = $type->isWriteable();
                $docTypes[] = $data;
            }
        }

        return $this->adminJson(['data' => $docTypes, 'success' => true, 'total' => count($docTypes)]);
    }

    /**
     * @Route("/doc-types", name="pimcore_admin_document_document_doctypes", methods={"PUT", "POST", "DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function docTypesAction(Request $request): JsonResponse
    {
        if ($request->get('data')) {
            $this->checkPermission('document_types');

            $data = $this->decodeJson($request->get('data'));

            if ($request->get('xaction') === 'destroy') {
                $type = Document\DocType::getById($data['id']);
                if (!$type->isWriteable()) {
                    throw new ConfigWriteException();
                }
                $type->delete();

                return $this->adminJson(['success' => true, 'data' => []]);
            } elseif ($request->get('xaction') === 'update') {
                // save type
                $type = Document\DocType::getById($data['id']);

                if (!$type->isWriteable()) {
                    throw new ConfigWriteException();
                }

                $type->setValues($data);
                $type->save();

                $responseData = $type->getObjectVars();
                $responseData['writeable'] = $type->isWriteable();

                return $this->adminJson(['data' => $responseData, 'success' => true]);
            } elseif ($request->get('xaction') === 'create') {
                if (!(new DocType())->isWriteable()) {
                    throw new ConfigWriteException();
                }

                unset($data['id']);

                // save type
                $type = Document\DocType::create();
                $type->setValues($data);

                $type->save();

                $responseData = $type->getObjectVars();
                $responseData['writeable'] = $type->isWriteable();

                return $this->adminJson(['data' => $responseData, 'success' => true]);
            }
        }

        return $this->adminJson(false);
    }

    /**
     * @Route("/get-doc-types", name="pimcore_admin_document_document_getdoctypes", methods={"GET"})
     *
     * @param Request $request
     *
     * @throws BadRequestHttpException If type is invalid
     *
     * @return JsonResponse
     */
    public function getDocTypesAction(Request $request): JsonResponse
    {
        $list = new DocType\Listing();
        if ($type = $request->get('type')) {
            if (!Document\Service::isValidType($type)) {
                throw new BadRequestHttpException('Invalid type: ' . $type);
            }
            $list->setFilter(static function (DocType $docType) use ($type) {
                return $docType->getType() === $type;
            });
        }

        $docTypes = [];
        foreach ($list->getDocTypes() as $type) {
            $docTypes[] = $type->getObjectVars();
        }

        return $this->adminJson(['docTypes' => $docTypes]);
    }

    /**
     * @Route("/version-to-session", name="pimcore_admin_document_document_versiontosession", methods={"POST"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function versionToSessionAction(Request $request): Response
    {
        $id = (int)$request->get('id');
        $version = Version::getById($id);
        $document = $version?->loadData();
        if (!$document) {
            throw $this->createNotFoundException('Version with id [' . $id . "] doesn't exist");
        }
        Document\Service::saveElementToSession($document, $request->getSession()->getId());

        return new Response();
    }

    /**
     * @Route("/publish-version", name="pimcore_admin_document_document_publishversion", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function publishVersionAction(Request $request): JsonResponse
    {
        $this->versionToSessionAction($request);

        $id = (int)$request->get('id');
        $version = Version::getById($id);
        $document = $version?->loadData();
        if (!$document) {
            throw $this->createNotFoundException('Version with id [' . $id . "] doesn't exist");
        }

        $currentDocument = Document::getById($document->getId());
        if ($currentDocument->isAllowed('publish')) {
            $document->setPublished(true);

            try {
                $document->setKey($currentDocument->getKey());
                $document->setPath($currentDocument->getRealPath());
                $document->setUserModification($this->getAdminUser()->getId());

                $document->save();
            } catch (Exception $e) {
                return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }

        $treeData = [];
        $this->addAdminStyle($document, ElementAdminStyleEvent::CONTEXT_EDITOR, $treeData);

        return $this->adminJson(['success' => true, 'treeData' => $treeData]);
    }

    /**
     * @Route("/update-site", name="pimcore_admin_document_document_updatesite", methods={"PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function updateSiteAction(Request $request): JsonResponse
    {
        $domains = $request->get('domains');
        $domains = str_replace(' ', '', $domains);
        $domains = explode("\n", $domains);

        if (!$site = Site::getByRootId((int)$request->get('id'))) {
            $site = Site::create([
                'rootId' => (int)$request->get('id'),
            ]);
        }

        $localizedErrorDocuments = [];
        $validLanguages = Tool::getValidLanguages();

        foreach ($validLanguages as $language) {
            // localized error pages
            $requestValue = $request->get('errorDocument_localized_' . $language);

            if (isset($requestValue)) {
                $localizedErrorDocuments[$language] = $requestValue;
            }
        }

        $site->setDomains($domains);
        $site->setMainDomain($request->get('mainDomain'));
        $site->setErrorDocument($request->get('errorDocument'));
        $site->setLocalizedErrorDocuments($localizedErrorDocuments);
        $site->setRedirectToMainDomain(($request->get('redirectToMainDomain') == 'true') ? true : false);
        $site->save();

        $site->setRootDocument(null); // do not send the document to the frontend

        return $this->adminJson($site->getObjectVars());
    }

    /**
     * @Route("/remove-site", name="pimcore_admin_document_document_removesite", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function removeSiteAction(Request $request): JsonResponse
    {
        $site = Site::getByRootId((int)$request->get('id'));
        $site->delete();

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/copy-info", name="pimcore_admin_document_document_copyinfo", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function copyInfoAction(Request $request): JsonResponse
    {
        $transactionId = time();
        $pasteJobs = [];

        Session::useBag($request->getSession(), function (AttributeBagInterface $session) use ($transactionId) {
            $session->set((string) $transactionId, ['idMapping' => []]);
        }, 'pimcore_copy');

        if ($request->get('type') == 'recursive' || $request->get('type') == 'recursive-update-references') {
            $document = Document::getById((int) $request->get('sourceId'));

            // first of all the new parent
            $pasteJobs[] = [[
                'url' => $this->generateUrl('pimcore_admin_document_document_copy'),
                'method' => 'POST',
                'params' => [
                    'sourceId' => $request->get('sourceId'),
                    'targetId' => $request->get('targetId'),
                    'type' => 'child',
                    'language' => $request->get('language'),
                    'enableInheritance' => $request->get('enableInheritance'),
                    'transactionId' => $transactionId,
                    'saveParentId' => true,
                    'resetIndex' => true,
                ],
            ]];

            $childIds = [];
            if ($document->hasChildren()) {
                // get amount of children
                $list = new Document\Listing();
                $list->setCondition('`path` LIKE ?', [$list->escapeLike($document->getRealFullPath()) . '/%']);
                $list->setOrderKey('LENGTH(`path`)', false);
                $list->setOrder('ASC');
                $childIds = $list->loadIdList();

                if (count($childIds) > 0) {
                    foreach ($childIds as $id) {
                        $pasteJobs[] = [[
                            'url' => $this->generateUrl('pimcore_admin_document_document_copy'),
                            'method' => 'POST',
                            'params' => [
                                'sourceId' => $id,
                                'targetParentId' => $request->get('targetId'),
                                'sourceParentId' => $request->get('sourceId'),
                                'type' => 'child',
                                'language' => $request->get('language'),
                                'enableInheritance' => $request->get('enableInheritance'),
                                'transactionId' => $transactionId,
                            ],
                        ]];
                    }
                }
            }

            // add id-rewrite steps
            if ($request->get('type') == 'recursive-update-references') {
                for ($i = 0; $i < (count($childIds) + 1); $i++) {
                    $pasteJobs[] = [[
                        'url' => $this->generateUrl('pimcore_admin_document_document_copyrewriteids'),
                        'method' => 'PUT',
                        'params' => [
                            'transactionId' => $transactionId,
                            'enableInheritance' => $request->get('enableInheritance'),
                            '_dc' => uniqid(),
                        ],
                    ]];
                }
            }
        } elseif ($request->get('type') == 'child' || $request->get('type') == 'replace') {
            // the object itself is the last one
            $pasteJobs[] = [[
                'url' => $this->generateUrl('pimcore_admin_document_document_copy'),
                'method' => 'POST',
                'params' => [
                    'sourceId' => $request->get('sourceId'),
                    'targetId' => $request->get('targetId'),
                    'type' => $request->get('type'),
                    'language' => $request->get('language'),
                    'enableInheritance' => $request->get('enableInheritance'),
                    'transactionId' => $transactionId,
                    'resetIndex' => ($request->get('type') == 'child'),
                ],
            ]];
        }

        return $this->adminJson([
            'pastejobs' => $pasteJobs,
        ]);
    }

    /**
     * @Route("/copy-rewrite-ids", name="pimcore_admin_document_document_copyrewriteids", methods={"PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function copyRewriteIdsAction(Request $request): JsonResponse
    {
        $transactionId = $request->get('transactionId');

        $idStore = Session::useBag($request->getSession(), function (AttributeBagInterface $session) use ($transactionId) {
            return $session->get($transactionId);
        }, 'pimcore_copy');

        if (!array_key_exists('rewrite-stack', $idStore)) {
            $idStore['rewrite-stack'] = array_values($idStore['idMapping']);
        }

        $id = array_shift($idStore['rewrite-stack']);
        $document = Document::getById($id);

        if ($document) {
            // create rewriteIds() config parameter
            $rewriteConfig = ['document' => $idStore['idMapping']];

            $document = Document\Service::rewriteIds($document, $rewriteConfig, [
                'enableInheritance' => ($request->get('enableInheritance') == 'true') ? true : false,
            ]);

            $document->setUserModification($this->getAdminUser()->getId());
            $document->save();
        }

        // write the store back to the session
        Session::useBag($request->getSession(), function (AttributeBagInterface $session) use ($transactionId, $idStore) {
            $session->set($transactionId, $idStore);
        }, 'pimcore_copy');

        return $this->adminJson([
            'success' => true,
            'id' => $id,
        ]);
    }

    /**
     * @Route("/copy", name="pimcore_admin_document_document_copy", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function copyAction(Request $request): JsonResponse
    {
        $success = false;
        $sourceId = (int)$request->get('sourceId');
        $source = Document::getById($sourceId);
        $session = Session::getSessionBag($request->getSession(), 'pimcore_copy');

        $targetId = (int)$request->get('targetId');

        $sessionBag = $session->get($request->get('transactionId'));

        if ($request->get('targetParentId')) {
            $sourceParent = Document::getById((int) $request->get('sourceParentId'));

            // this is because the key can get the prefix "_copy" if the target does already exists
            if ($sessionBag['parentId']) {
                $targetParent = Document::getById($sessionBag['parentId']);
            } else {
                $targetParent = Document::getById((int) $request->get('targetParentId'));
            }

            $targetPath = preg_replace('@^' . $sourceParent->getRealFullPath() . '@', $targetParent . '/', $source->getRealPath());
            $target = Document::getByPath($targetPath);
        } else {
            $target = Document::getById($targetId);
        }

        if ($target instanceof Document) {
            if ($target->isAllowed('create')) {
                if ($source != null) {
                    if ($source instanceof Document\PageSnippet && $latestVersion = $source->getLatestVersion()) {
                        $source = $latestVersion->loadData();
                        $source->setPublished(false); //as latest version is used which is not published
                    }

                    if ($request->get('type') == 'child') {
                        $enableInheritance = ($request->get('enableInheritance') == 'true') ? true : false;

                        $language = (string) $request->request->get('language') ?: null;
                        if ($language && !Tool::isValidLanguage($language)) {
                            throw new BadRequestHttpException('Invalid language: ' . $language);
                        }

                        $resetIndex = ($request->get('resetIndex') == 'true') ? true : false;

                        $newDocument = $this->_documentService->copyAsChild($target, $source, $enableInheritance, $resetIndex, $language);

                        $sessionBag['idMapping'][(int)$source->getId()] = (int)$newDocument->getId();

                        // this is because the key can get the prefix "_copy" if the target does already exists
                        if ($request->get('saveParentId')) {
                            $sessionBag['parentId'] = $newDocument->getId();
                        }
                        $session->set($request->get('transactionId'), $sessionBag);
                    } elseif ($request->get('type') == 'replace') {
                        $this->_documentService->copyContents($target, $source);
                    }

                    $success = true;
                } else {
                    Logger::error('prevended copy/paste because document with same path+key already exists in this location');
                }
            } else {
                Logger::error('could not execute copy/paste because of missing permissions on target [ ' . $targetId . ' ]');

                throw $this->createAccessDeniedHttpException();
            }
        }

        return $this->adminJson(['success' => $success]);
    }

    /**
     * @Route("/diff-versions/from/{from}/to/{to}", name="pimcore_admin_document_document_diffversions", requirements={"from": "\d+", "to": "\d+"}, methods={"GET"})
     *
     * @param Request $request
     * @param int $from
     * @param int $to
     *
     * @return Response
     */
    public function diffVersionsAction(Request $request, int $from, int $to): Response
    {
        // return with error if prerequisites do not match
        if (!Chromium::isSupported() || !class_exists('Imagick')) {
            return $this->render('@PimcoreAdmin/admin/document/document/diff_versions_unsupported.html.twig');
        }

        $versionFrom = Version::getById($from);
        $docFrom = $versionFrom?->loadData();

        if (!$docFrom) {
            throw $this->createNotFoundException('Version with id [' . $from . "] doesn't exist");
        }

        $prefix = Config::getSystemConfiguration('documents')['preview_url_prefix'];
        if (empty($prefix)) {
            $prefix = $request->getSchemeAndHttpHost();
        }

        $prefix .= $docFrom->getRealFullPath() . '?pimcore_version=';

        $fromUrl = $prefix . $from;
        $toUrl = $prefix . $to;

        $toFileId = uniqid();
        $fromFileId = uniqid();
        $diffFileId = uniqid();
        $fromFile = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/version-diff-tmp-' . $fromFileId . '.png';
        $toFile = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/version-diff-tmp-' . $toFileId . '.png';
        $diffFile = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/version-diff-tmp-' . $diffFileId . '.png';

        $viewParams = [];

        $session = $request->getSession();

        Chromium::convert($fromUrl, $fromFile, $session->getName(), $session->getId());
        Chromium::convert($toUrl, $toFile, $session->getName(), $session->getId());

        $image1 = new Imagick($fromFile);
        $image2 = new Imagick($toFile);

        if ($image1->getImageWidth() == $image2->getImageWidth() && $image1->getImageHeight() == $image2->getImageHeight()) {
            $result = $image1->compareImages($image2, Imagick::METRIC_MEANSQUAREERROR);
            $result[0]->setImageFormat('png');

            $result[0]->writeImage($diffFile);
            $result[0]->clear();
            $result[0]->destroy();

            $viewParams['image'] = $diffFileId;
        } else {
            $viewParams['image1'] = $fromFileId;
            $viewParams['image2'] = $toFileId;
        }

        // cleanup
        $image1->clear();
        $image1->destroy();
        $image2->clear();
        $image2->destroy();

        return $this->render('@PimcoreAdmin/admin/document/document/diff_versions.html.twig', $viewParams);
    }

    /**
     * @Route("/diff-versions-image", name="pimcore_admin_document_document_diffversionsimage", methods={"GET"})
     *
     * @param Request $request
     *
     * @return BinaryFileResponse
     */
    public function diffVersionsImageAction(Request $request): BinaryFileResponse
    {
        $file = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/version-diff-tmp-' . $request->get('id') . '.png';
        if (file_exists($file)) {
            $response = new BinaryFileResponse($file);
            $response->headers->set('Content-Type', 'image/png');

            return $response;
        }

        throw $this->createNotFoundException('Version diff file not found');
    }

    /**
     * @Route("/get-id-for-path", name="pimcore_admin_document_document_getidforpath", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getIdForPathAction(Request $request): JsonResponse
    {
        if ($doc = Document::getByPath($request->get('path'))) {
            return $this->adminJson([
                'id' => $doc->getId(),
                'type' => $doc->getType(),
            ]);
        } else {
            return $this->adminJson(false);
        }
    }

    /**
     * @Route("/language-tree", name="pimcore_admin_document_document_languagetree", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function languageTreeAction(Request $request): JsonResponse
    {
        $document = Document::getById((int) $request->query->get('node'));

        $languages = explode(',', $request->get('languages'));

        $result = [];
        foreach ($document->getChildren() as $child) {
            $result[] = $this->getTranslationTreeNodeConfig($child, $languages);
        }

        return $this->adminJson($result);
    }

    /**
     * @Route("/language-tree-root", name="pimcore_admin_document_document_languagetreeroot", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws Exception
     */
    public function languageTreeRootAction(Request $request): JsonResponse
    {
        $document = Document::getById((int) $request->query->get('id'));

        if (!$document) {
            return $this->adminJson([
                'success' => false,
            ]);
        }
        $service = new Document\Service();

        $locales = Tool::getSupportedLocales();

        $lang = $document->getProperty('language');

        $columns = [
            [
                'xtype' => 'treecolumn',
                'text' => $lang ? $locales[$lang] : '',
                'dataIndex' => 'text',
                'cls' => $lang ? 'x-column-header_' . strtolower($lang) : null,
                'width' => 300,
                'sortable' => false,
            ],
        ];

        $translations = $service->getTranslations($document);

        $combinedTranslations = $translations;

        if ($parentDocument = $document->getParent()) {
            $parentTranslations = $service->getTranslations($parentDocument);
            foreach ($parentTranslations as $language => $languageDocumentId) {
                $combinedTranslations[$language] = $translations[$language] ?? $languageDocumentId;
            }
        }

        foreach ($combinedTranslations as $language => $languageDocumentId) {
            $languageDocument = Document::getById($languageDocumentId);

            if ($languageDocument && $languageDocument->isAllowed('list') && $language != $document->getProperty('language')) {
                $columns[] = [
                    'text' => $locales[$language],
                    'dataIndex' => $language,
                    'cls' => 'x-column-header_' . strtolower($language),
                    'width' => 300,
                    'sortable' => false,
                ];
            }
        }

        return $this->adminJson([
            'root' => $this->getTranslationTreeNodeConfig($document, array_keys($translations), $translations),
            'columns' => $columns,
            'languages' => array_keys($translations),
        ]);
    }

    private function getTranslationTreeNodeConfig(Document $document, array $languages, array $translations = null): array
    {
        $service = new Document\Service();

        $config = $this->getTreeNodeConfig($document);

        $translations = is_null($translations) ? $service->getTranslations($document) : $translations;

        foreach ($languages as $language) {
            if ($languageDocument = $translations[$language] ?? false) {
                $languageDocument = Document::getById($languageDocument);
                $config[$language] = [
                    'text' => $languageDocument->getKey(),
                    'id' => $languageDocument->getId(),
                    'type' => $languageDocument->getType(),
                    'fullPath' => $languageDocument->getFullPath(),
                    'published' => $languageDocument->getPublished(),
                    'itemType' => 'document',
                    'permissions' => $languageDocument->getUserPermissions($this->getAdminUser()),
                ];
            } elseif (!$document instanceof Document\Folder) {
                $config[$language] = [
                    'text' => '--',
                    'itemType' => 'empty',
                ];
            }
        }

        return $config;
    }

    /**
     * @Route("/convert", name="pimcore_admin_document_document_convert", methods={"PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function convertAction(Request $request): JsonResponse
    {
        $document = Document::getById((int) $request->get('id'));
        if (!$document) {
            throw $this->createNotFoundException();
        }

        $type = $request->get('type');
        $class = '\\Pimcore\\Model\\Document\\' . ucfirst($type);
        if (Tool::classExists($class)) {
            $new = new $class;

            // overwrite internal store to avoid "duplicate full path" error
            RuntimeCache::set('document_' . $document->getId(), $new);

            $props = $document->getObjectVars();
            foreach ($props as $name => $value) {
                $new->setValue($name, $value);
            }

            if ($type == 'hardlink' || $type == 'folder') {
                // remove navigation settings
                foreach (['name', 'title', 'target', 'exclude', 'class', 'anchor', 'parameters', 'relation', 'accesskey', 'tabindex'] as $propertyName) {
                    $new->removeProperty('navigation_' . $propertyName);
                }
            }

            $new->setType($type);
            $new->save();
        }

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/translation-determine-parent", name="pimcore_admin_document_document_translationdetermineparent", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function translationDetermineParentAction(Request $request): JsonResponse
    {
        $success = false;
        $targetDocument = null;

        $document = Document::getById((int) $request->get('id'));
        if ($document) {
            $service = new Document\Service();
            $document = $document->getId() === 1 ? $document : $document->getParent();

            $translations = $service->getTranslations($document);
            if (isset($translations[$request->get('language')])) {
                $targetDocument = Document::getById($translations[$request->get('language')]);
                $success = true;
            }
        }

        return $this->adminJson([
            'success' => $success,
            'targetPath' => $targetDocument ? $targetDocument->getRealFullPath() : null,
            'targetId' => $targetDocument ? $targetDocument->getid() : null,
        ]);
    }

    /**
     * @Route("/translation-add", name="pimcore_admin_document_document_translationadd", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function translationAddAction(Request $request): JsonResponse
    {
        $sourceDocument = Document::getById((int) $request->get('sourceId'));
        $targetDocument = Document::getByPath($request->get('targetPath'));

        if ($sourceDocument && $targetDocument) {
            if (empty($sourceDocument->getProperty('language'))) {
                throw new Exception(sprintf('Source Document(ID:%s) Language(Properties) missing', $sourceDocument->getId()));
            }

            if (empty($targetDocument->getProperty('language'))) {
                throw new Exception(sprintf('Target Document(ID:%s) Language(Properties) missing', $sourceDocument->getId()));
            }

            $service = new Document\Service;
            if ($service->getTranslationSourceId($targetDocument) != $targetDocument->getId()) {
                throw new Exception('Target Document already linked to Source Document ID('.$service->getTranslationSourceId($targetDocument).'). Please unlink existing relation first.');
            }
            $service->addTranslation($sourceDocument, $targetDocument);
        }

        return $this->adminJson([
            'success' => true,
        ]);
    }

    /**
     * @Route("/translation-remove", name="pimcore_admin_document_document_translationremove", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function translationRemoveAction(Request $request): JsonResponse
    {
        $sourceDocument = Document::getById((int) $request->get('sourceId'));
        $targetDocument = Document::getById((int) $request->get('targetId'));
        if ($sourceDocument && $targetDocument) {
            $service = new Document\Service;
            $service->removeTranslationLink($sourceDocument, $targetDocument);
        }

        return $this->adminJson([
            'success' => true,
        ]);
    }

    /**
     * @Route("/translation-check-language", name="pimcore_admin_document_document_translationchecklanguage", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function translationCheckLanguageAction(Request $request): JsonResponse
    {
        $success = false;
        $language = null;
        $translationLinks = null;

        $document = Document::getByPath($request->get('path'));
        if ($document) {
            $language = $document->getProperty('language');
            if ($language) {
                $success = true;
            }

            //check if document is already linked to other langauges
            $translationLinks = array_keys($this->_documentService->getTranslations($document));
        }

        return $this->adminJson([
            'success' => $success,
            'language' => $language,
            'translationLinks' => $translationLinks,
        ]);
    }

    public function onKernelControllerEvent(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // check permissions
        $this->checkActionPermission($event, 'documents', ['docTypesGetAction']);

        $this->_documentService = new Document\Service($this->getAdminUser());
    }
}
