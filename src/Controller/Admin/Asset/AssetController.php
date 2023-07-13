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

namespace Pimcore\Bundle\AdminBundle\Controller\Admin\Asset;

use Pimcore\Bundle\AdminBundle\Controller\Admin\ElementControllerBase;
use Pimcore\Bundle\AdminBundle\Controller\Traits\AdminStyleTrait;
use Pimcore\Bundle\AdminBundle\Controller\Traits\ApplySchedulerDataTrait;
use Pimcore\Bundle\AdminBundle\Controller\Traits\UserNameTrait;
use Pimcore\Bundle\AdminBundle\Event\AdminEvents;
use Pimcore\Bundle\AdminBundle\Event\ElementAdminStyleEvent;
use Pimcore\Bundle\AdminBundle\Helper\GridHelperService;
use Pimcore\Bundle\AdminBundle\Security\CsrfProtectionHandler;
use Pimcore\Bundle\AdminBundle\Service\ElementService;
use Pimcore\Config;
use Pimcore\Controller\KernelControllerEventInterface;
use Pimcore\Controller\Traits\ElementEditLockHelperTrait;
use Pimcore\Db\Helper;
use Pimcore\Event\AssetEvents;
use Pimcore\Event\Model\Asset\ResolveUploadTargetEvent;
use Pimcore\File;
use Pimcore\Loader\ImplementationLoader\Exception\UnsupportedException;
use Pimcore\Logger;
use Pimcore\Messenger\AssetPreviewImageMessage;
use Pimcore\Model;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\ClassDefinition\Data\ManyToManyRelation;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Element\ValidationException;
use Pimcore\Model\Metadata;
use Pimcore\Model\Schedule\Task;
use Pimcore\Tool;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("/asset")
 *
 * @internal
 */
class AssetController extends ElementControllerBase implements KernelControllerEventInterface
{
    use AdminStyleTrait;
    use ElementEditLockHelperTrait;
    use ApplySchedulerDataTrait;
    use UserNameTrait;

    protected Asset\Service $_assetService;

    /**
     * @Route("/tree-get-root", name="pimcore_admin_asset_treegetroot", methods={"GET"})
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
     * @Route("/delete-info", name="pimcore_admin_asset_deleteinfo", methods={"GET"})
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
     * @Route("/get-data-by-id", name="pimcore_admin_asset_getdatabyid", methods={"GET"})
     */
    public function getDataByIdAction(Request $request, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        $assetId = $request->query->getInt('id');
        $type = $request->query->get('type');

        $asset = Asset::getById($assetId);
        if (!$asset instanceof Asset) {
            return $this->adminJson(['success' => false, 'message' => "asset doesn't exist"]);
        }

        // check for lock on non-folder items only.
        if ($type !== 'folder' && ($asset->isAllowed('publish') || $asset->isAllowed('delete'))) {
            if (Element\Editlock::isLocked($assetId, 'asset', $request->getSession()->getId())) {
                return $this->getEditLockResponse($assetId, 'asset');
            }

            Element\Editlock::lock($assetId, 'asset', $request->getSession()->getId());
        }

        $asset = clone $asset;
        $asset->setParent(null);

        $asset->setStream(null);
        $data = $asset->getObjectVars();
        $data['locked'] = $asset->isLocked();

        if ($asset instanceof Asset\Text) {
            if ($asset->getFileSize() < 2000000) {
                // it doesn't make sense to show a preview for files bigger than 2MB
                $data['data'] = \ForceUTF8\Encoding::toUTF8($asset->getData());
            } else {
                $data['data'] = false;
            }
        } elseif ($asset instanceof Asset\Document) {
            $data['pdfPreviewAvailable'] = (bool)$this->getDocumentPreviewPdf($asset);
        } elseif ($asset instanceof Asset\Video) {
            $videoInfo = [];

            if (\Pimcore\Video::isAvailable()) {
                $config = Asset\Video\Thumbnail\Config::getPreviewConfig();
                $thumbnail = $asset->getThumbnail($config, ['mp4']);
                if ($thumbnail) {
                    if ($thumbnail['status'] == 'finished') {
                        $videoInfo['previewUrl'] = $thumbnail['formats']['mp4'];
                        $videoInfo['width'] = $asset->getWidth();
                        $videoInfo['height'] = $asset->getHeight();

                        $metaData = $asset->getSphericalMetaData();
                        if (isset($metaData['ProjectionType']) && strtolower($metaData['ProjectionType']) == 'equirectangular') {
                            $videoInfo['isVrVideo'] = true;
                        }
                    }
                }
            }

            $data['videoInfo'] = $videoInfo;
        } elseif ($asset instanceof Asset\Image) {
            $imageInfo = [];

            $previewUrl = $this->generateUrl('pimcore_admin_asset_getimagethumbnail', [
                'id' => $asset->getId(),
                'treepreview' => true,
                '_dc' => time(),
            ]);

            if ($asset->isAnimated()) {
                $previewUrl = $this->generateUrl('pimcore_admin_asset_getasset', [
                    'id' => $asset->getId(),
                    '_dc' => time(),
                ]);
            }

            $imageInfo['previewUrl'] = $previewUrl;

            if ($asset->getWidth() && $asset->getHeight()) {
                $imageInfo['dimensions'] = [];
                $imageInfo['dimensions']['width'] = $asset->getWidth();
                $imageInfo['dimensions']['height'] = $asset->getHeight();
            }

            $imageInfo['exiftoolAvailable'] = (bool)\Pimcore\Tool\Console::getExecutable('exiftool');

            if (!$asset->getEmbeddedMetaData(false)) {
                $asset->getEmbeddedMetaData(true, false); // read Exif, IPTC and XPM like in the old days ...
            }

            $data['imageInfo'] = $imageInfo;
        }

        $predefinedMetaData = Metadata\Predefined\Listing::getByTargetType('asset', [$asset->getType()]);
        $predefinedMetaDataGroups = [];
        /** @var Metadata\Predefined $item */
        foreach ($predefinedMetaData as $item) {
            if ($item->getGroup()) {
                $predefinedMetaDataGroups[$item->getGroup()] = true;
            }
        }
        $data['predefinedMetaDataGroups'] = array_keys($predefinedMetaDataGroups);
        $data['properties'] = Element\Service::minimizePropertiesForEditmode($asset->getProperties());
        $data['metadata'] = Asset\Service::expandMetadataForEditmode($asset->getMetadata());
        $data['versionDate'] = $asset->getModificationDate();
        $data['filesizeFormatted'] = $asset->getFileSize(true);
        $data['filesize'] = $asset->getFileSize();
        $data['fileExtension'] = pathinfo($asset->getFilename(), PATHINFO_EXTENSION);
        $data['idPath'] = Element\Service::getIdPath($asset);
        $data['userPermissions'] = $asset->getUserPermissions($this->getAdminUser());
        $frontendPath = $asset->getFrontendFullPath();
        $data['url'] = preg_match('/^http(s)?:\\/\\/.+/', $frontendPath) ?
            $frontendPath :
            $request->getSchemeAndHttpHost() . $frontendPath;

        $data['scheduledTasks'] = array_map(
            static function (Task $task) {
                return $task->getObjectVars();
            },
            $asset->getScheduledTasks()
        );

        $userOwnerName = $this->getUserName($asset->getUserOwner());
        $userModificationName = ($asset->getUserOwner() == $asset->getUserModification()) ? $userOwnerName : $this->getUserName($asset->getUserModification());
        $data['userOwnerUsername'] = $userOwnerName['userName'];
        $data['userOwnerFullname'] = $userOwnerName['fullName'];
        $data['userModificationUsername'] = $userModificationName['userName'];
        $data['userModificationFullname'] = $userModificationName['fullName'];

        $this->addAdminStyle($asset, ElementAdminStyleEvent::CONTEXT_EDITOR, $data);

        $data['php'] = [
            'classes' => array_merge([get_class($asset)], array_values(class_parents($asset))),
            'interfaces' => array_values(class_implements($asset)),
        ];

        $event = new GenericEvent($this, [
            'data' => $data,
            'asset' => $asset,
        ]);
        $eventDispatcher->dispatch($event, AdminEvents::ASSET_GET_PRE_SEND_DATA);
        $data = $event->getArgument('data');

        if ($asset->isAllowed('view')) {
            return $this->adminJson($data);
        }

        throw $this->createAccessDeniedHttpException();
    }

    /**
     * @Route("/tree-get-children-by-id", name="pimcore_admin_asset_treegetchildrenbyid", methods={"GET"})
     *
     *
     */
    public function treeGetChildrenByIdAction(Request $request, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        $allParams = array_merge($request->request->all(), $request->query->all());

        $assets = [];
        $cv = [];
        $asset = Asset::getById($allParams['node']);

        $filter = $request->get('filter');
        $limit = (int)$allParams['limit'];
        if (!is_null($filter)) {
            if (substr($filter, -1) != '*') {
                $filter .= '*';
            }
            $filter = str_replace('*', '%', $filter);

            $limit = 100;
            $offset = 0;
        } elseif (!$allParams['limit']) {
            $limit = 100000000;
        }

        $offset = isset($allParams['start']) ? (int)$allParams['start'] : 0;

        $filteredTotalCount = 0;

        if ($asset->hasChildren()) {
            if ($allParams['view']) {
                $cv = ElementService::getCustomViewById($allParams['view']);
            }

            // get assets
            $childrenList = new Asset\Listing();
            $childrenList->addConditionParam('parentId = ?', [$asset->getId()]);
            $childrenList->filterAccessibleByUser($this->getAdminUser(), $asset);

            if (!is_null($filter)) {
                $childrenList->addConditionParam('CAST(assets.filename AS CHAR CHARACTER SET utf8) COLLATE utf8_general_ci LIKE ?', [$filter]);
            }

            $childrenList->setLimit($limit);
            $childrenList->setOffset($offset);
            $childrenList->setOrderKey("FIELD(assets.type, 'folder') DESC, CAST(assets.filename AS CHAR CHARACTER SET utf8) COLLATE utf8_general_ci ASC", false);

            \Pimcore\Model\Element\Service::addTreeFilterJoins($cv, $childrenList);

            $beforeListLoadEvent = new GenericEvent($this, [
                'list' => $childrenList,
                'context' => $allParams,
            ]);
            $eventDispatcher->dispatch($beforeListLoadEvent, AdminEvents::ASSET_LIST_BEFORE_LIST_LOAD);
            /** @var Asset\Listing $childrenList */
            $childrenList = $beforeListLoadEvent->getArgument('list');

            $children = $childrenList->load();

            $filteredTotalCount = $childrenList->getTotalCount();

            foreach ($children as $childAsset) {
                $assetTreeNode = $this->getTreeNodeConfig($childAsset);
                if ($assetTreeNode['permissions']['list'] == 1) {
                    $assets[] = $assetTreeNode;
                }
            }
        }

        //Hook for modifying return value - e.g. for changing permissions based on asset data
        $event = new GenericEvent($this, [
            'assets' => $assets,
        ]);
        $eventDispatcher->dispatch($event, AdminEvents::ASSET_TREE_GET_CHILDREN_BY_ID_PRE_SEND_DATA);
        $assets = $event->getArgument('assets');

        if ($allParams['limit']) {
            return $this->adminJson([
                'offset' => $offset,
                'limit' => $limit,
                'total' => $asset->getChildAmount($this->getAdminUser()),
                'overflow' => !is_null($filter) && ($filteredTotalCount > $limit),
                'nodes' => $assets,
                'filter' => $request->get('filter') ? $request->get('filter') : '',
                'inSearch' => (int)$request->get('inSearch'),
            ]);
        } else {
            return $this->adminJson($assets);
        }
    }

    /**
     * @Route("/add-asset", name="pimcore_admin_asset_addasset", methods={"POST"})
     *
     * @param Request $request
     * @param Config $config
     *
     * @return JsonResponse
     */
    public function addAssetAction(Request $request, Config $config): JsonResponse
    {
        try {
            $res = $this->addAsset($request, $config);

            $response = [
                'success' => $res['success'],
            ];

            if ($res['success']) {
                $response['asset'] = [
                    'id' => $res['asset']->getId(),
                    'path' => $res['asset']->getFullPath(),
                    'type' => $res['asset']->getType(),
                ];
            }

            return $this->adminJson($response);
        } catch (\Exception $e) {
            return $this->adminJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @Route("/add-asset-compatibility", name="pimcore_admin_asset_addassetcompatibility", methods={"POST"})
     *
     * @param Request $request
     * @param Config $config
     *
     * @return JsonResponse
     */
    public function addAssetCompatibilityAction(Request $request, Config $config): JsonResponse
    {
        try {
            // this is a special action for the compatibility mode upload (without flash)
            $res = $this->addAsset($request, $config);

            $response = $this->adminJson([
                'success' => $res['success'],
                'msg' => $res['success'] ? 'Success' : 'Error',
                'id' => $res['asset'] ? $res['asset']->getId() : null,
                'fullpath' => $res['asset'] ? $res['asset']->getRealFullPath() : null,
                'type' => $res['asset'] ? $res['asset']->getType() : null,
            ]);
            $response->headers->set('Content-Type', 'text/html');

            return $response;
        } catch (\Exception $e) {
            return $this->adminJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @Route("/exists", name="pimcore_admin_asset_exists", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function existsAction(Request $request): JsonResponse
    {
        $parentAsset = \Pimcore\Model\Asset::getById((int)$request->get('parentId'));

        return new JsonResponse([
            'exists' => Asset\Service::pathExists($parentAsset->getRealFullPath().'/'.$request->get('filename')),
        ]);
    }

    /**
     * @param Request $request
     * @param Config $config
     *
     * @return array{success: bool, asset: ?Asset}
     *
     * @throws \Exception
     */
    protected function addAsset(Request $request, Config $config): array
    {
        $defaultUploadPath = $config['assets']['default_upload_path'] ?? '/';

        if (array_key_exists('Filedata', $_FILES)) {
            $filename = $_FILES['Filedata']['name'];
            $sourcePath = $_FILES['Filedata']['tmp_name'];
        } elseif ($request->get('type') == 'base64') {
            $filename = $request->get('filename');
            $sourcePath = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/upload-base64' . uniqid() . '.tmp';
            $data = preg_replace('@^data:[^,]+;base64,@', '', $request->get('data'));
            $filesystem = new Filesystem();
            $filesystem->dumpFile($sourcePath, base64_decode($data));
        } else {
            throw new \Exception('The filename of the asset is empty');
        }

        $parentId = $request->query->getInt('parentId');
        $parentPath = $request->query->get('parentPath');

        if ($request->get('dir') && $request->get('parentId')) {
            // this is for uploading folders with Drag&Drop
            // param "dir" contains the relative path of the file
            $parent = Asset::getById((int) $request->get('parentId'));
            $dir = $request->get('dir');
            if (strpos($dir, '..') !== false) {
                throw new \Exception('not allowed');
            }

            $newPath = $parent->getRealFullPath() . '/' . trim($dir, '/ ');

            $maxRetries = 5;
            $newParent = null;
            for ($retries = 0; $retries < $maxRetries; $retries++) {
                try {
                    $newParent = Asset\Service::createFolderByPath($newPath);

                    break;
                } catch (\Exception $e) {
                    if ($retries < ($maxRetries - 1)) {
                        $waitTime = rand(100000, 900000); // microseconds
                        usleep($waitTime); // wait specified time until we restart the transaction
                    } else {
                        // if the transaction still fail after $maxRetries retries, we throw out the exception
                        throw $e;
                    }
                }
            }
            if ($newParent) {
                $parentId = $newParent->getId();
            }
        } elseif (!$request->get('parentId') && $parentPath) {
            $parent = Asset::getByPath($parentPath);
            if ($parent instanceof Asset\Folder) {
                $parentId = $parent->getId();
            } else { //create defined parent folder, if doesn't exist.
                $parentId = Asset\Service::createFolderByPath($parentPath)->getId();
            }
        }

        $filename = Element\Service::getValidKey($filename, 'asset');
        if (empty($filename)) {
            throw new \Exception('The filename of the asset is empty');
        }

        $context = $request->get('context');
        if ($context) {
            $context = json_decode($context, true);
            $context = $context ?: [];

            $this->validateManyToManyRelationAssetType($context, $filename, $sourcePath);

            $event = new ResolveUploadTargetEvent($parentId, $filename, $context);
            \Pimcore::getEventDispatcher()->dispatch($event, AssetEvents::RESOLVE_UPLOAD_TARGET);
            $filename = Element\Service::getValidKey($event->getFilename(), 'asset');
            $parentId = $event->getParentId();
        }

        if (!$parentId) {
            $parentId = Asset\Service::createFolderByPath($defaultUploadPath)->getId();
        }

        $parentAsset = Asset::getById((int)$parentId);

        if (!$request->get('allowOverwrite')) {
            // check for duplicate filename
            $filename = $this->getSafeFilename($parentAsset->getRealFullPath(), $filename);
        }

        if (!$parentAsset->isAllowed('create')) {
            throw $this->createAccessDeniedHttpException(
                'Missing the permission to create new assets in the folder: ' . $parentAsset->getRealFullPath()
            );
        }

        if (is_file($sourcePath) && filesize($sourcePath) < 1) {
            throw new \Exception('File is empty!');
        } elseif (!is_file($sourcePath)) {
            throw new \Exception('Something went wrong, please check upload_max_filesize and post_max_size in your php.ini as well as the write permissions of your temporary directories.');
        }

        // check if there is a requested type and if matches the asset type of the uploaded file
        $uploadAssetType = $request->get('uploadAssetType');
        if ($uploadAssetType) {
            $mimetype = MimeTypes::getDefault()->guessMimeType($sourcePath);
            $assetType = Asset::getTypeFromMimeMapping($mimetype, $filename);

            if ($uploadAssetType !== $assetType) {
                throw new \Exception("Mime type $mimetype does not match with asset type: $uploadAssetType");
            }
        }

        if ($request->get('allowOverwrite') && Asset\Service::pathExists($parentAsset->getRealFullPath().'/'.$filename)) {
            $asset = Asset::getByPath($parentAsset->getRealFullPath().'/'.$filename);
            $asset->setStream(fopen($sourcePath, 'rb', false, File::getContext()));
            $asset->save();
        } else {
            $asset = Asset::create($parentId, [
                'filename' => $filename,
                'sourcePath' => $sourcePath,
                'userOwner' => $this->getAdminUser()->getId(),
                'userModification' => $this->getAdminUser()->getId(),
            ]);
        }

        @unlink($sourcePath);

        return [
            'success' => true,
            'asset' => $asset,
        ];
    }

    protected function getSafeFilename(string $targetPath, string $filename): string
    {
        $pathinfo = pathinfo($filename);
        $originalFilename = $pathinfo['filename'];
        $originalFileextension = empty($pathinfo['extension']) ? '' : '.' . $pathinfo['extension'];
        $count = 1;

        if ($targetPath == '/') {
            $targetPath = '';
        }

        while (true) {
            if (Asset\Service::pathExists($targetPath . '/' . $filename)) {
                $filename = $originalFilename . '_' . $count . $originalFileextension;
                $count++;
            } else {
                return $filename;
            }
        }
    }

    /**
     * @Route("/replace-asset", name="pimcore_admin_asset_replaceasset", methods={"POST", "PUT"})
     *
     * @param Request $request
     * @param TranslatorInterface $translator
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function replaceAssetAction(Request $request, TranslatorInterface $translator): JsonResponse
    {
        $asset = Asset::getById((int) $request->get('id'));

        $newFilename = Element\Service::getValidKey($_FILES['Filedata']['name'], 'asset');
        $mimetype = MimeTypes::getDefault()->guessMimeType($_FILES['Filedata']['tmp_name']);
        $newType = Asset::getTypeFromMimeMapping($mimetype, $newFilename);

        if ($newType != $asset->getType()) {
            return $this->adminJson([
                'success' => false,
                'message' => sprintf($translator->trans('asset_type_change_not_allowed', [], 'admin'), $asset->getType(), $newType),
            ]);
        }

        $stream = fopen($_FILES['Filedata']['tmp_name'], 'r+');
        $asset->setStream($stream);
        $asset->setCustomSetting('thumbnails', null);
        $asset->setUserModification($this->getAdminUser()->getId());

        $newFileExt = pathinfo($newFilename, PATHINFO_EXTENSION);
        $currentFileExt = pathinfo($asset->getFilename(), PATHINFO_EXTENSION);
        if ($newFileExt != $currentFileExt) {
            $newFilename = preg_replace('/\.' . $currentFileExt . '$/i', '.' . $newFileExt, $asset->getFilename());
            $newFilename = Element\Service::getSafeCopyName($newFilename, $asset->getParent());
            $asset->setFilename($newFilename);
        }

        if ($asset->isAllowed('publish')) {
            $asset->save();

            $response = $this->adminJson([
                'id' => $asset->getId(),
                'path' => $asset->getRealFullPath(),
                'success' => true,
            ]);

            // set content-type to text/html, otherwise (when application/json is sent) chrome will complain in
            // Ext.form.Action.Submit and mark the submission as failed
            $response->headers->set('Content-Type', 'text/html');

            return $response;
        } else {
            throw new \Exception('missing permission');
        }
    }

    /**
     * @Route("/add-folder", name="pimcore_admin_asset_addfolder", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function addFolderAction(Request $request): JsonResponse
    {
        $success = false;
        $parentAsset = Asset::getById((int)$request->get('parentId'));
        $equalAsset = Asset::getByPath($parentAsset->getRealFullPath() . '/' . $request->get('name'));

        if ($parentAsset->isAllowed('create')) {
            if (!$equalAsset) {
                $asset = Asset::create($request->get('parentId'), [
                    'filename' => $request->get('name'),
                    'type' => 'folder',
                    'userOwner' => $this->getAdminUser()->getId(),
                    'userModification' => $this->getAdminUser()->getId(),
                ]);
                $success = true;
            }
        } else {
            Logger::debug('prevented creating asset because of missing permissions');
        }

        return $this->adminJson(['success' => $success]);
    }

    /**
     * @Route("/delete", name="pimcore_admin_asset_delete", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function deleteAction(Request $request): JsonResponse
    {
        $type = $request->get('type');

        if ($type === 'children') {
            $parentAsset = Asset::getById((int) $request->get('id'));

            $list = new Asset\Listing();
            $list->setCondition('`path` LIKE ?', [Helper::escapeLike($parentAsset->getRealFullPath()) . '/%']);
            $list->setLimit((int)$request->get('amount'));
            $list->setOrderKey('LENGTH(`path`)', false);
            $list->setOrder('DESC');

            $deletedItems = [];
            foreach ($list as $asset) {
                $deletedItems[$asset->getId()] = $asset->getRealFullPath();
                if ($asset->isAllowed('delete') && !$asset->isLocked()) {
                    $asset->delete();
                }
            }

            return $this->adminJson(['success' => true, 'deleted' => $deletedItems]);
        }
        if ($request->get('id')) {
            $asset = Asset::getById((int) $request->get('id'));
            if ($asset && $asset->isAllowed('delete')) {
                if ($asset->isLocked()) {
                    return $this->adminJson([
                        'success' => false,
                        'message' => 'prevented deleting asset, because it is locked: ID: ' . $asset->getId(),
                    ]);
                }

                $asset->delete();

                return $this->adminJson(['success' => true]);
            }
        }

        throw $this->createAccessDeniedHttpException();
    }

    protected function getTreeNodeConfig(ElementInterface $element): array
    {
        /** @var Asset $asset */
        $asset = $element;

        $permissions =  $asset->getUserPermissions($this->getAdminUser());

        $tmpAsset = [
            'id' => $asset->getId(),
            'key' => $element->getKey(),
            'text' => htmlspecialchars($asset->getFilename()),
            'type' => $asset->getType(),
            'path' => $asset->getRealFullPath(),
            'basePath' => $asset->getRealPath(),
            'locked' => $asset->isLocked(),
            'lockOwner' => $asset->getLocked() ? true : false,
            'elementType' => 'asset',
            'permissions' => [
                'remove' => $permissions['delete'],
                'settings' => $permissions['settings'],
                'rename' => $permissions['rename'],
                'publish' => $permissions['publish'],
                'view' => $permissions['view'],
                'list' => $permissions['list'],
            ],
        ];

        $hasChildren = $asset->getDao()->hasChildren($this->getAdminUser());

        // set type specific settings
        if ($asset instanceof Asset\Folder) {
            $tmpAsset['leaf'] = false;
            $tmpAsset['expanded'] = !$hasChildren;
            $tmpAsset['loaded'] = !$hasChildren;
            $tmpAsset['permissions']['create'] = $permissions['create'];
            $tmpAsset['thumbnail'] = $this->getThumbnailUrl($asset, ['origin' => 'treeNode']);
        } else {
            $tmpAsset['leaf'] = true;
            $tmpAsset['expandable'] = false;
            $tmpAsset['expanded'] = false;
        }

        $this->addAdminStyle($asset, ElementAdminStyleEvent::CONTEXT_TREE, $tmpAsset);

        if ($asset instanceof Asset\Image) {
            try {
                $tmpAsset['thumbnail'] = $this->getThumbnailUrl($asset, ['origin' => 'treeNode']);

                // we need the dimensions for the wysiwyg editors, so that they can resize the image immediately
                if ($asset->getCustomSetting('imageDimensionsCalculated')) {
                    $tmpAsset['imageWidth'] = $asset->getCustomSetting('imageWidth');
                    $tmpAsset['imageHeight'] = $asset->getCustomSetting('imageHeight');
                }
            } catch (\Exception $e) {
                Logger::debug('Cannot get dimensions of image, seems to be broken.');
            }
        } elseif ($asset->getType() == 'video') {
            try {
                if (\Pimcore\Video::isAvailable()) {
                    $tmpAsset['thumbnail'] = $this->getThumbnailUrl($asset, ['origin' => 'treeNode']);
                }
            } catch (\Exception $e) {
                Logger::debug('Cannot get dimensions of video, seems to be broken.');
            }
        } elseif ($asset->getType() == 'document') {
            try {
                // add the PDF check here, otherwise the preview layer in admin is shown without content
                if (\Pimcore\Document::isAvailable() && \Pimcore\Document::isFileTypeSupported($asset->getFilename())) {
                    $tmpAsset['thumbnail'] = $this->getThumbnailUrl($asset, ['origin' => 'treeNode']);
                }
            } catch (\Exception $e) {
                Logger::debug('Cannot get dimensions of video, seems to be broken.');
            }
        }

        $tmpAsset['cls'] = '';
        if ($asset->isLocked()) {
            $tmpAsset['cls'] .= 'pimcore_treenode_locked ';
        }
        if ($asset->getLocked()) {
            $tmpAsset['cls'] .= 'pimcore_treenode_lockOwner ';
        }

        return $tmpAsset;
    }

    protected function getThumbnailUrl(Asset $asset, array $params = []): ?string
    {
        $defaults = [
            'id' => $asset->getId(),
            'treepreview' => true,
            '_dc' => $asset->getModificationDate(),
        ];

        $params = array_merge($defaults, $params);

        if ($asset instanceof Asset\Image) {
            return $this->generateUrl('pimcore_admin_asset_getimagethumbnail', $params);
        }

        if ($asset instanceof Asset\Folder) {
            return $this->generateUrl('pimcore_admin_asset_getfolderthumbnail', $params);
        }

        if ($asset instanceof Asset\Video && \Pimcore\Video::isAvailable()) {
            return $this->generateUrl('pimcore_admin_asset_getvideothumbnail', $params);
        }

        if ($asset instanceof Asset\Document && \Pimcore\Document::isAvailable() && $asset->getPageCount()) {
            return $this->generateUrl('pimcore_admin_asset_getdocumentthumbnail', $params);
        }

        if ($asset instanceof Asset\Audio) {
            return '/bundles/pimcoreadmin/img/flat-color-icons/speaker.svg';
        }

        if ($asset instanceof Asset) {
            return '/bundles/pimcoreadmin/img/filetype-not-supported.svg';
        }
    }

    /**
     * @Route("/update", name="pimcore_admin_asset_update", methods={"PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function updateAction(Request $request): JsonResponse
    {
        $success = false;
        $allowUpdate = true;

        $updateData = array_merge($request->request->all(), $request->query->all());

        $asset = Asset::getById((int) $request->get('id'));
        if ($asset->isAllowed('settings')) {
            $asset->setUserModification($this->getAdminUser()->getId());

            // if the position is changed the path must be changed || also from the children
            if ($parentId = $request->get('parentId')) {
                $parentAsset = Asset::getById((int) $parentId);

                //check if parent is changed i.e. asset is moved
                if ($asset->getParentId() != $parentAsset->getId()) {
                    if (!$parentAsset->isAllowed('create')) {
                        throw new \Exception('Prevented moving asset - no create permission on new parent ');
                    }

                    $intendedPath = $parentAsset->getRealPath();
                    $pKey = $parentAsset->getKey();
                    if (!empty($pKey)) {
                        $intendedPath .= $parentAsset->getKey() . '/';
                    }

                    $assetWithSamePath = Asset::getByPath($intendedPath . $asset->getKey());

                    if ($assetWithSamePath != null) {
                        $allowUpdate = false;
                    }

                    if ($asset->isLocked()) {
                        $allowUpdate = false;
                    }
                }
            }

            if ($allowUpdate) {
                if ($request->get('filename') != $asset->getFilename() && !$asset->isAllowed('rename')) {
                    unset($updateData['filename']);
                    Logger::debug('prevented renaming asset because of missing permissions ');
                }

                $asset->setValues($updateData);

                try {
                    $asset->save();
                    $success = true;
                } catch (\Exception $e) {
                    return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
                }
            } else {
                $msg = 'prevented moving asset, asset with same path+key already exists at target location or the asset is locked. ID: ' . $asset->getId();
                Logger::debug($msg);

                return $this->adminJson(['success' => $success, 'message' => $msg]);
            }
        } elseif ($asset->isAllowed('rename') && $request->get('filename')) {
            //just rename
            try {
                $asset->setFilename($request->get('filename'));
                $asset->save();
                $success = true;
            } catch (\Exception $e) {
                return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
            }
        } else {
            Logger::debug('prevented update asset because of missing permissions ');
        }

        return $this->adminJson(['success' => $success]);
    }

    /**
     * @Route("/save", name="pimcore_admin_asset_save", methods={"PUT","POST"})
     *
     * @param Request $request
     * @param EventDispatcherInterface $eventDispatcher
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function saveAction(Request $request, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        $asset = Asset::getById((int) $request->get('id'));

        if (!$asset) {
            throw $this->createNotFoundException('Asset not found');
        }

        if ($asset->isAllowed('publish')) {
            // metadata
            if ($request->get('metadata')) {
                $metadata = $this->decodeJson($request->get('metadata'));

                $metadataEvent = new GenericEvent($this, [
                    'id' => $asset->getId(),
                    'metadata' => $metadata,
                ]);
                $eventDispatcher->dispatch($metadataEvent, AdminEvents::ASSET_METADATA_PRE_SET);

                $metadata = $metadataEvent->getArgument('metadata');
                $metadataValues = $metadata['values'];

                $metadataValues = Asset\Service::minimizeMetadata($metadataValues, 'editor');
                $asset->setMetadataRaw($metadataValues);
            }

            // properties
            if ($request->get('properties')) {
                $properties = [];
                $propertiesData = $this->decodeJson($request->get('properties'));

                if (is_array($propertiesData)) {
                    foreach ($propertiesData as $propertyName => $propertyData) {
                        $value = $propertyData['data'];

                        try {
                            $property = new Model\Property();
                            $property->setType($propertyData['type']);
                            $property->setName($propertyName);
                            $property->setCtype('asset');
                            $property->setDataFromEditmode($value);
                            $property->setInheritable($propertyData['inheritable']);

                            $properties[$propertyName] = $property;
                        } catch (\Exception $e) {
                            Logger::err("Can't add " . $propertyName . ' to asset ' . $asset->getRealFullPath());
                        }
                    }

                    $asset->setProperties($properties);
                }
            }

            $this->applySchedulerDataToElement($request, $asset);

            if ($request->get('data')) {
                $asset->setData($request->get('data'));
            }

            // image specific data
            if ($asset instanceof Asset\Image) {
                if ($request->get('image')) {
                    $imageData = $this->decodeJson($request->get('image'));
                    if (isset($imageData['focalPoint'])) {
                        $asset->setCustomSetting('focalPointX', $imageData['focalPoint']['x']);
                        $asset->setCustomSetting('focalPointY', $imageData['focalPoint']['y']);
                    }
                } else {
                    // wipe all data
                    $asset->removeCustomSetting('focalPointX');
                    $asset->removeCustomSetting('focalPointY');
                }
            }

            $asset->setUserModification($this->getAdminUser()->getId());
            if ($request->get('task') === 'session') {
                // save to session only
                Asset\Service::saveElementToSession($asset, $request->getSession()->getId());
            } else {
                $asset->save();
            }

            $treeData = $this->getTreeNodeConfig($asset);

            return $this->adminJson([
                'success' => true,
                'data' => [
                    'versionDate' => $asset->getModificationDate(),
                    'versionCount' => $asset->getVersionCount(),
                ],
                'treeData' => $treeData,
            ]);
        } else {
            throw $this->createAccessDeniedHttpException();
        }
    }

    /**
     * @Route("/publish-version", name="pimcore_admin_asset_publishversion", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function publishVersionAction(Request $request): JsonResponse
    {
        $id = (int)$request->get('id');
        $version = Model\Version::getById($id);
        $asset = $version?->loadData();

        if (!$asset) {
            throw $this->createNotFoundException('Version with id [' . $id . "] doesn't exist");
        }

        $currentAsset = Asset::getById($asset->getId());
        if ($currentAsset->isAllowed('publish')) {
            try {
                $asset->setUserModification($this->getAdminUser()->getId());
                $asset->save();

                $treeData = $this->getTreeNodeConfig($asset);

                return $this->adminJson(['success' => true, 'treeData' => $treeData]);
            } catch (\Exception $e) {
                return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }

        throw $this->createAccessDeniedHttpException();
    }

    /**
     * @Route("/show-version", name="pimcore_admin_asset_showversion", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function showVersionAction(Request $request): Response
    {
        $id = (int)$request->get('id');
        $version = Model\Version::getById($id);
        $asset = $version?->loadData();
        if (!$asset) {
            throw $this->createNotFoundException('Version with id [' . $id . "] doesn't exist");
        }

        if (!$asset->isAllowed('versions')) {
            throw $this->createAccessDeniedHttpException('Permission denied, version id [' . $id . ']');
        }

        $loader = \Pimcore::getContainer()->get('pimcore.implementation_loader.asset.metadata.data');

        return $this->render(
            '@PimcoreAdmin/admin/asset/show_version_' . strtolower($asset->getType()) . '.html.twig',
            [
                'asset' => $asset,
                'version' => $version,
                'loader' => $loader,
            ]
        );
    }

    /**
     * @Route("/download", name="pimcore_admin_asset_download", methods={"GET"})
     *
     * @param Request $request
     *
     * @return StreamedResponse
     */
    public function downloadAction(Request $request): StreamedResponse
    {
        $asset = Asset::getById((int) $request->get('id'));

        if (!$asset) {
            throw $this->createNotFoundException('Asset not found');
        }

        if (!$asset->isAllowed('view')) {
            throw $this->createAccessDeniedException('not allowed to view asset');
        }

        $stream = $asset->getStream();

        if (!is_resource($stream)) {
            throw $this->createNotFoundException('Unable to get resource for asset ' . $asset->getId());
        }

        return new StreamedResponse(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $asset->getMimeType(),
            'Content-Disposition' => sprintf('attachment; filename="%s"', $asset->getFilename()),
            'Content-Length' => $asset->getFileSize(),
        ]);
    }

    /**
     * @Route("/download-image-thumbnail", name="pimcore_admin_asset_downloadimagethumbnail", methods={"GET"})
     *
     * @param Request $request
     *
     * @return BinaryFileResponse
     */
    public function downloadImageThumbnailAction(Request $request): BinaryFileResponse
    {
        $image = Asset\Image::getById((int) $request->get('id'));

        if (!$image) {
            throw $this->createNotFoundException('Asset not found');
        }

        if (!$image->isAllowed('view')) {
            throw $this->createAccessDeniedException('not allowed to view thumbnail');
        }

        $config = null;
        $thumbnail = null;
        $thumbnailName = $request->get('thumbnail');
        $thumbnailFile = null;
        $deleteThumbnail = true;

        if ($request->get('config')) {
            $config = $this->decodeJson($request->get('config'));
        } elseif ($request->get('type')) {
            $predefined = [
                'web' => [
                    'resize_mode' => 'scaleByWidth',
                    'width' => 3500,
                    'dpi' => 72,
                    'format' => 'JPEG',
                    'quality' => 85,
                ],
                'print' => [
                    'resize_mode' => 'scaleByWidth',
                    'width' => 6000,
                    'dpi' => 300,
                    'format' => 'JPEG',
                    'quality' => 95,
                ],
                'office' => [
                    'resize_mode' => 'scaleByWidth',
                    'width' => 1190,
                    'dpi' => 144,
                    'format' => 'JPEG',
                    'quality' => 90,
                ],
            ];

            $config = $predefined[$request->get('type')];
        } elseif ($thumbnailName) {
            $thumbnail = $image->getThumbnail($thumbnailName);
            $deleteThumbnail = false;
        }

        if ($config) {
            $thumbnailConfig = new Asset\Image\Thumbnail\Config();
            $thumbnailConfig->setName('pimcore-download-' . $image->getId() . '-' . md5($request->get('config')));

            if ($config['resize_mode'] == 'scaleByWidth') {
                $thumbnailConfig->addItem('scaleByWidth', [
                    'width' => $config['width'],
                ]);
            } elseif ($config['resize_mode'] == 'scaleByHeight') {
                $thumbnailConfig->addItem('scaleByHeight', [
                    'height' => $config['height'],
                ]);
            } else {
                $thumbnailConfig->addItem('resize', [
                    'width' => $config['width'],
                    'height' => $config['height'],
                ]);
            }

            if (!empty($config['quality']) && $config['quality'] <= 100 && $config['quality'] > 0) {
                $thumbnailConfig->setQuality($config['quality']);
            }

            if (!empty($config['format'])) {
                $thumbnailConfig->setFormat($config['format']);
            }

            $thumbnailConfig->setRasterizeSVG(true);

            if ($thumbnailConfig->getFormat() == 'JPEG') {
                $thumbnailConfig->setPreserveMetaData(true);

                if (empty($config['quality'])) {
                    $thumbnailConfig->setPreserveColor(true);
                }
            }

            $thumbnail = $image->getThumbnail($thumbnailConfig);
            $thumbnailFile = $thumbnail->getLocalFile();

            $exiftool = \Pimcore\Tool\Console::getExecutable('exiftool');
            if ($thumbnailConfig->getFormat() == 'JPEG' && $exiftool && isset($config['dpi']) && $config['dpi']) {
                $process = new Process([$exiftool, '-overwrite_original', '-xresolution=' . (int)$config['dpi'], '-yresolution=' . (int)$config['dpi'], '-resolutionunit=inches', $thumbnailFile]);
                $process->run();
            }
        }

        if ($thumbnail) {
            $thumbnailFile = $thumbnailFile ?: $thumbnail->getLocalFile();

            $downloadFilename = preg_replace(
                '/\.' . preg_quote(pathinfo($image->getFilename(), PATHINFO_EXTENSION)) . '$/i',
                '.' . $thumbnail->getFileExtension(),
                $image->getFilename()
            );
            $downloadFilename = strtolower($downloadFilename);

            clearstatcache();

            $response = new BinaryFileResponse($thumbnailFile);
            $response->headers->set('Content-Type', $thumbnail->getMimeType());
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $downloadFilename);
            $this->addThumbnailCacheHeaders($response);
            $response->deleteFileAfterSend($deleteThumbnail);

            return $response;
        }

        throw $this->createNotFoundException('Thumbnail not found');
    }

    /**
     * @Route("/get-asset", name="pimcore_admin_asset_getasset", methods={"GET"})
     *
     * @param Request $request
     *
     * @return StreamedResponse
     */
    public function getAssetAction(Request $request): StreamedResponse
    {
        $image = Asset::getById((int)$request->get('id'));

        if (!$image) {
            throw $this->createNotFoundException('Asset not found');
        }

        if (!$image->isAllowed('view')) {
            throw $this->createAccessDeniedException('not allowed to view asset');
        }

        $stream = $image->getStream();

        if (!is_resource($stream)) {
            throw $this->createNotFoundException('Unable to get resource for asset ' . $image->getId());
        }

        $response = new StreamedResponse(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $image->getMimeType(),
            'Access-Control-Allow-Origin' => '*',
        ]);
        $this->addThumbnailCacheHeaders($response);

        return $response;
    }

    /**
     * @Route("/get-image-thumbnail", name="pimcore_admin_asset_getimagethumbnail", methods={"GET"})
     *
     * @param Request $request
     *
     * @return StreamedResponse|JsonResponse|BinaryFileResponse
     */
    public function getImageThumbnailAction(Request $request): BinaryFileResponse|JsonResponse|StreamedResponse
    {
        $fileinfo = $request->get('fileinfo');
        $image = Asset\Image::getById((int)$request->get('id'));

        if (!$image) {
            throw $this->createNotFoundException('Asset not found');
        }

        if (!$image->isAllowed('view')) {
            throw $this->createAccessDeniedException('not allowed to view thumbnail');
        }

        $thumbnailConfig = null;

        if ($request->get('thumbnail')) {
            $thumbnailConfig = $image->getThumbnailConfig($request->get('thumbnail'));
        }
        if (!$thumbnailConfig) {
            if ($request->get('config')) {
                $thumbnailConfig = $image->getThumbnailConfig($this->decodeJson($request->get('config')));
            } else {
                $thumbnailConfig = $image->getThumbnailConfig(array_merge($request->request->all(), $request->query->all()));
            }
        } else {
            // no high-res images in admin mode (editmode)
            // this is mostly because of the document's image editable, which doesn't know anything about the thumbnail
            // configuration, so the dimensions would be incorrect (double the size)
            $thumbnailConfig->setHighResolution(1);
        }

        $format = strtolower($thumbnailConfig->getFormat());
        if ($format == 'source' || $format == 'print') {
            $thumbnailConfig->setFormat('PNG');
            $thumbnailConfig->setRasterizeSVG(true);
        }

        if ($request->get('treepreview')) {
            $thumbnailConfig = Asset\Image\Thumbnail\Config::getPreviewConfig();
            if ($request->get('origin') === 'treeNode' && !$image->getThumbnail($thumbnailConfig)->exists()) {
                \Pimcore::getContainer()->get('messenger.bus.pimcore-core')->dispatch(
                    new AssetPreviewImageMessage($image->getId())
                );

                throw $this->createNotFoundException(sprintf('Tree preview thumbnail not available for asset %s', $image->getId()));
            }
        }

        $cropPercent = $request->get('cropPercent');
        if ($cropPercent && filter_var($cropPercent, FILTER_VALIDATE_BOOLEAN)) {
            $thumbnailConfig->addItemAt(0, 'cropPercent', [
                'width' => $request->get('cropWidth'),
                'height' => $request->get('cropHeight'),
                'y' => $request->get('cropTop'),
                'x' => $request->get('cropLeft'),
            ]);

            $thumbnailConfig->generateAutoName();
        }

        $thumbnail = $image->getThumbnail($thumbnailConfig);

        if ($fileinfo) {
            return $this->adminJson([
                'width' => $thumbnail->getWidth(),
                'height' => $thumbnail->getHeight(), ]);
        }

        $stream = $thumbnail->getStream();

        if (!$stream) {
            return new BinaryFileResponse(PIMCORE_WEB_ROOT . '/bundles/pimcoreadmin/img/filetype-not-supported.svg');
        }

        $response = new StreamedResponse(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $thumbnail->getMimeType(),
            'Access-Control-Allow-Origin', '*',
        ]);

        $this->addThumbnailCacheHeaders($response);

        return $response;
    }

    /**
     * @Route("/get-folder-thumbnail", name="pimcore_admin_asset_getfolderthumbnail", methods={"GET"})
     *
     * @param Request $request
     *
     * @return StreamedResponse
     */
    public function getFolderThumbnailAction(Request $request): StreamedResponse
    {
        $folder = null;

        if ($request->get('id')) {
            $folder = Asset\Folder::getById((int)$request->get('id'));
            if ($folder instanceof  Asset\Folder) {
                if (!$folder->isAllowed('view')) {
                    throw $this->createAccessDeniedException('not allowed to view thumbnail');
                }

                $stream = $folder->getPreviewImage();
                if (!$stream) {
                    throw $this->createNotFoundException(sprintf('Tree preview thumbnail not available for asset %s', $folder->getId()));
                } else {
                    $response = new StreamedResponse(function () use ($stream) {
                        fpassthru($stream);
                    }, 200, [
                        'Content-Type' => 'image/jpg',
                    ]);
                }

                $this->addThumbnailCacheHeaders($response);

                return $response;
            }
        }

        throw $this->createNotFoundException('could not load asset folder');
    }

    /**
     * @Route("/get-video-thumbnail", name="pimcore_admin_asset_getvideothumbnail", methods={"GET"})
     *
     * @param Request $request
     *
     * @return StreamedResponse
     */
    public function getVideoThumbnailAction(Request $request): StreamedResponse
    {
        $video = null;

        if ($request->get('id')) {
            $video = Asset\Video::getById((int)$request->get('id'));
        } elseif ($request->get('path')) {
            $video = Asset\Video::getByPath($request->get('path'));
        }

        if (!$video) {
            throw $this->createNotFoundException('could not load video asset');
        }

        if (!$video->isAllowed('view')) {
            throw $this->createAccessDeniedException('not allowed to view thumbnail');
        }

        $thumbnail = array_merge($request->request->all(), $request->query->all());

        if ($request->get('treepreview')) {
            $thumbnail = Asset\Image\Thumbnail\Config::getPreviewConfig();
        }

        $time = null;
        if (is_numeric($request->get('time'))) {
            $time = (int)$request->get('time');
        }

        if ($request->get('settime')) {
            $video->removeCustomSetting('image_thumbnail_asset');
            $video->setCustomSetting('image_thumbnail_time', $time);
            $video->save();
        }

        $image = null;
        if ($request->get('image')) {
            $image = Asset\Image::getById((int)$request->get('image'));
        }

        if ($request->get('setimage') && $image) {
            $video->removeCustomSetting('image_thumbnail_time');
            $video->setCustomSetting('image_thumbnail_asset', $image->getId());
            $video->save();
        }

        $thumb = $video->getImageThumbnail($thumbnail, $time, $image);

        if ($request->get('origin') === 'treeNode' && !$thumb->exists()) {
            \Pimcore::getContainer()->get('messenger.bus.pimcore-core')->dispatch(
                new AssetPreviewImageMessage($video->getId())
            );

            throw $this->createNotFoundException(sprintf('Tree preview thumbnail not available for asset %s', $video->getId()));
        }

        $stream = $thumb->getStream();
        if (!$stream) {
            throw $this->createNotFoundException('Unable to get video thumbnail for video ' . $video->getId());
        }

        $response = new StreamedResponse(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => 'image/' . $thumb->getFileExtension(),
        ]);

        $this->addThumbnailCacheHeaders($response);

        return $response;
    }

    /**
     * @Route("/get-document-thumbnail", name="pimcore_admin_asset_getdocumentthumbnail", methods={"GET"})
     *
     * @param Request $request
     *
     * @return StreamedResponse|BinaryFileResponse
     */
    public function getDocumentThumbnailAction(Request $request): BinaryFileResponse|StreamedResponse
    {
        $document = Asset\Document::getById((int)$request->get('id'));

        if (!$document) {
            throw $this->createNotFoundException('could not load document asset');
        }

        if (!$document->isAllowed('view')) {
            throw $this->createAccessDeniedException('not allowed to view thumbnail');
        }

        $thumbnail = Asset\Image\Thumbnail\Config::getByAutoDetect(array_merge($request->request->all(), $request->query->all()));

        $format = strtolower($thumbnail->getFormat());
        if ($format == 'source') {
            $thumbnail->setFormat('jpeg'); // default format for documents is JPEG not PNG (=too big)
        }

        if ($request->get('treepreview')) {
            $thumbnail = Asset\Image\Thumbnail\Config::getPreviewConfig();
        }

        $page = 1;
        if (is_numeric($request->get('page'))) {
            $page = (int)$request->get('page');
        }

        $thumb = $document->getImageThumbnail($thumbnail, $page);

        if ($request->get('origin') === 'treeNode' && !$thumb->exists()) {
            \Pimcore::getContainer()->get('messenger.bus.pimcore-core')->dispatch(
                new AssetPreviewImageMessage($document->getId())
            );

            throw $this->createNotFoundException(sprintf('Tree preview thumbnail not available for asset %s', $document->getId()));
        }

        $stream = $thumb->getStream();
        if ($stream) {
            $response = new StreamedResponse(function () use ($stream) {
                fpassthru($stream);
            }, 200, [
                'Content-Type' => 'image/' . $thumb->getFileExtension(),
            ]);
        } else {
            $response = new BinaryFileResponse(PIMCORE_WEB_ROOT . '/bundles/pimcoreadmin/img/filetype-not-supported.svg');
        }

        $this->addThumbnailCacheHeaders($response);

        return $response;
    }

    protected function addThumbnailCacheHeaders(Response $response): void
    {
        $lifetime = 300;
        $date = new \DateTime('now');
        $date->add(new \DateInterval('PT' . $lifetime . 'S'));

        $response->setMaxAge($lifetime);
        $response->setPublic();
        $response->setExpires($date);
        $response->headers->set('Pragma', '');
    }

    /**
     * @Route("/get-preview-document", name="pimcore_admin_asset_getpreviewdocument", methods={"GET"})
     *
     * @param Request $request
     *
     * @return StreamedResponse
     */
    public function getPreviewDocumentAction(Request $request): StreamedResponse
    {
        $asset = Asset\Document::getById((int) $request->get('id'));

        if (!$asset) {
            throw $this->createNotFoundException('could not load document asset');
        }

        if ($asset->isAllowed('view')) {
            $stream = $this->getDocumentPreviewPdf($asset);
            if ($stream) {
                return new StreamedResponse(function () use ($stream) {
                    fpassthru($stream);
                }, 200, [
                    'Content-Type' => 'application/pdf',
                ]);
            } else {
                throw $this->createNotFoundException('Unable to get preview for asset ' . $asset->getId());
            }
        } else {
            throw $this->createAccessDeniedException('Access to asset ' . $asset->getId() . ' denied');
        }
    }

    /**
     * @param Asset\Document $asset
     *
     * @return resource|null
     */
    protected function getDocumentPreviewPdf(Asset\Document $asset)
    {
        $stream = null;

        if ($asset->getMimeType() == 'application/pdf') {
            $stream = $asset->getStream();
        }

        if (!$stream && $asset->getPageCount() && \Pimcore\Document::isAvailable() && \Pimcore\Document::isFileTypeSupported($asset->getFilename())) {
            try {
                $document = \Pimcore\Document::getInstance();
                $stream = $document->getPdf($asset);
            } catch (\Exception $e) {
                // nothing to do
            }
        }

        return $stream;
    }

    /**
     * @Route("/get-preview-video", name="pimcore_admin_asset_getpreviewvideo", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function getPreviewVideoAction(Request $request): Response
    {
        $asset = Asset\Video::getById((int) $request->get('id'));
        $configName = $request->get('config');

        if (!$asset) {
            throw $this->createNotFoundException('could not load video asset');
        }

        if (!$asset->isAllowed('view')) {
            throw $this->createAccessDeniedException('not allowed to preview');
        }

        $previewData = ['asset' => $asset];

        $config = Asset\Video\Thumbnail\Config::getByName($configName);

        if (!$config instanceof Asset\Video\Thumbnail\Config) {
            $config = Asset\Video\Thumbnail\Config::getPreviewConfig();
        }

        $thumbnail = $asset->getThumbnail($config, ['mp4']);

        if ($thumbnail) {
            $previewData['asset'] = $asset;
            $previewData['thumbnail'] = $thumbnail;
            $previewData['config'] = $config->getName();

            if ($thumbnail['status'] == 'finished') {
                return $this->render(
                    '@PimcoreAdmin/admin/asset/get_preview_video_display.html.twig',
                    $previewData
                );
            } else {
                return $this->render(
                    '@PimcoreAdmin/admin/asset/get_preview_video_error.html.twig',
                    $previewData
                );
            }
        } else {
            return $this->render(
                '@PimcoreAdmin/admin/asset/get_preview_video_error.html.twig',
                $previewData
            );
        }
    }

    /**
     * @Route("/serve-video-preview", name="pimcore_admin_asset_servevideopreview", methods={"GET"})
     *
     * @param Request $request
     *
     * @return StreamedResponse
     */
    public function serveVideoPreviewAction(Request $request): StreamedResponse
    {
        $asset = Asset\Video::getById((int) $request->get('id'));
        $configName = $request->get('config');

        if (!$asset) {
            throw $this->createNotFoundException('could not load video asset');
        }

        if (!$asset->isAllowed('view')) {
            throw $this->createAccessDeniedException('not allowed to preview');
        }

        $config = Asset\Video\Thumbnail\Config::getByName($configName);

        if (!$config instanceof Asset\Video\Thumbnail\Config) {
            $config = Asset\Video\Thumbnail\Config::getPreviewConfig();
        }

        $thumbnail = $asset->getThumbnail($config, ['mp4']);
        $storagePath = $asset->getRealPath() . '/' . preg_replace('@^' . preg_quote($asset->getPath(), '@') . '@', '', urldecode($thumbnail['formats']['mp4']));

        $storage = Tool\Storage::get('thumbnail');
        if ($storage->fileExists($storagePath)) {
            $fs = $storage->fileSize($storagePath);
            $stream = $storage->readStream($storagePath);

            return new StreamedResponse(function () use ($stream) {
                fpassthru($stream);
            }, 200, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => $fs,
                'Accept-Ranges' => 'bytes',
            ]);
        } else {
            throw $this->createNotFoundException('Video thumbnail not found');
        }
    }

    /**
     * @Route("/image-editor", name="pimcore_admin_asset_imageeditor", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function imageEditorAction(Request $request): Response
    {
        $asset = Asset::getById((int) $request->get('id'));

        if (!$asset->isAllowed('view')) {
            throw $this->createAccessDeniedException('Not allowed to preview');
        }

        return $this->render(
            '@PimcoreAdmin/admin/asset/image_editor.html.twig',
            ['asset' => $asset]
        );
    }

    /**
     * @Route("/image-editor-save", name="pimcore_admin_asset_imageeditorsave", methods={"PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function imageEditorSaveAction(Request $request): JsonResponse
    {
        $asset = Asset::getById((int) $request->get('id'));

        if (!$asset) {
            throw $this->createNotFoundException('Asset not found');
        }

        if (!$asset->isAllowed('publish')) {
            throw $this->createAccessDeniedException('not allowed to publish');
        }

        $data = $request->get('dataUri');
        $data = substr($data, strpos($data, ','));
        $data = base64_decode($data);
        $asset->setData($data);
        $asset->setUserModification($this->getAdminUser()->getId());
        $asset->save();

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/get-folder-content-preview", name="pimcore_admin_asset_getfoldercontentpreview", methods={"GET"})
     *
     *
     */
    public function getFolderContentPreviewAction(Request $request, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        $allParams = array_merge($request->request->all(), $request->query->all());

        $filterPrepareEvent = new GenericEvent($this, [
            'requestParams' => $allParams,
        ]);
        $eventDispatcher->dispatch($filterPrepareEvent, AdminEvents::ASSET_LIST_BEFORE_FILTER_PREPARE);

        $allParams = $filterPrepareEvent->getArgument('requestParams');

        $folder = Asset::getById((int) $allParams['id']);

        $start = 0;
        $limit = 10;

        if ($allParams['limit']) {
            $limit = $allParams['limit'];
        }
        if ($allParams['start']) {
            $start = $allParams['start'];
        }

        $conditionFilters = [];
        $list = new Asset\Listing();
        $conditionFilters[] = '`path` LIKE ' . ($folder->getRealFullPath() == '/' ? "'/%'" : $list->quote(Helper::escapeLike($folder->getRealFullPath()) . '/%')) . " AND `type` != 'folder'";

        if (!$this->getAdminUser()->isAdmin()) {
            $userIds = $this->getAdminUser()->getRoles();
            $userIds[] = $this->getAdminUser()->getId();
            $conditionFilters[] = ' (
                                                    (select list from users_workspaces_asset where userId in (' . implode(',', $userIds) . ') and LOCATE(CONCAT(`path`, filename),cpath)=1  ORDER BY LENGTH(cpath) DESC LIMIT 1)=1
                                                    OR
                                                    (select list from users_workspaces_asset where userId in (' . implode(',', $userIds) . ') and LOCATE(cpath,CONCAT(`path`, filename))=1  ORDER BY LENGTH(cpath) DESC LIMIT 1)=1
                                                 )';
        }

        $condition = implode(' AND ', $conditionFilters);

        $list->setCondition($condition);
        $list->setLimit($limit);
        $list->setOffset($start);
        $list->setOrderKey('CAST(filename AS CHAR CHARACTER SET utf8) COLLATE utf8_general_ci ASC', false);

        $beforeListLoadEvent = new GenericEvent($this, [
            'list' => $list,
            'context' => $allParams,
        ]);
        $eventDispatcher->dispatch($beforeListLoadEvent, AdminEvents::ASSET_LIST_BEFORE_LIST_LOAD);
        /** @var Asset\Listing $list */
        $list = $beforeListLoadEvent->getArgument('list');

        $list->load();

        $assets = [];

        foreach ($list as $asset) {
            $filenameDisplay = $asset->getFilename();
            if (strlen($filenameDisplay) > 32) {
                $filenameDisplay = substr($filenameDisplay, 0, 25) . '...' . pathinfo($filenameDisplay, PATHINFO_EXTENSION);
            }

            // Like for treeGetChildrenByIdAction, so we respect isAllowed method which can be extended (object DI) for custom permissions, so relying only users_workspaces_asset is insufficient and could lead security breach
            if ($asset->isAllowed('list')) {
                $assets[] = [
                    'id' => $asset->getId(),
                    'type' => $asset->getType(),
                    'filename' => $asset->getFilename(),
                    'filenameDisplay' => htmlspecialchars($filenameDisplay),
                    'url' => $this->getThumbnailUrl($asset),
                    'idPath' => $data['idPath'] = Element\Service::getIdPath($asset),
                ];
            }
        }

        // We need to temporary use data key to be compatible with the ASSET_LIST_AFTER_LIST_LOAD global event
        $result = ['data' => $assets, 'success' => true, 'total' => $list->getTotalCount()];

        $afterListLoadEvent = new GenericEvent($this, [
            'list' => $result,
            'context' => $allParams,
        ]);
        $eventDispatcher->dispatch($afterListLoadEvent, AdminEvents::ASSET_LIST_AFTER_LIST_LOAD);
        $result = $afterListLoadEvent->getArgument('list');

        // Here we revert to assets key
        return $this->adminJson(['assets' => $result['data'], 'success' => $result['success'], 'total' => $result['total']]);
    }

    /**
     * @Route("/copy-info", name="pimcore_admin_asset_copyinfo", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function copyInfoAction(Request $request): JsonResponse
    {
        $transactionId = time();
        $pasteJobs = [];

        Tool\Session::useBag($request->getSession(), function (AttributeBagInterface $session) use ($transactionId) {
            $session->set((string) $transactionId, []);
        }, 'pimcore_copy');

        if ($request->get('type') == 'recursive') {
            $asset = Asset::getById((int) $request->get('sourceId'));

            if (!$asset) {
                throw $this->createNotFoundException('Source not found');
            }

            // first of all the new parent
            $pasteJobs[] = [[
                'url' => $this->generateUrl('pimcore_admin_asset_copy'),
                'method' => 'POST',
                'params' => [
                    'sourceId' => $request->get('sourceId'),
                    'targetId' => $request->get('targetId'),
                    'type' => 'child',
                    'transactionId' => $transactionId,
                    'saveParentId' => true,
                ],
            ]];

            if ($asset->hasChildren()) {
                // get amount of children
                $list = new Asset\Listing();
                $list->setCondition('`path` LIKE ?', [$list->escapeLike($asset->getRealFullPath()) . '/%']);
                $list->setOrderKey('LENGTH(`path`)', false);
                $list->setOrder('ASC');
                $childIds = $list->loadIdList();

                if (count($childIds) > 0) {
                    foreach ($childIds as $id) {
                        $pasteJobs[] = [[
                            'url' => $this->generateUrl('pimcore_admin_asset_copy'),
                            'method' => 'POST',
                            'params' => [
                                'sourceId' => $id,
                                'targetParentId' => $request->get('targetId'),
                                'sourceParentId' => $request->get('sourceId'),
                                'type' => 'child',
                                'transactionId' => $transactionId,
                            ],
                        ]];
                    }
                }
            }
        } elseif ($request->get('type') == 'child' || $request->get('type') == 'replace') {
            // the object itself is the last one
            $pasteJobs[] = [[
                'url' => $this->generateUrl('pimcore_admin_asset_copy'),
                'method' => 'POST',
                'params' => [
                    'sourceId' => $request->get('sourceId'),
                    'targetId' => $request->get('targetId'),
                    'type' => $request->get('type'),
                    'transactionId' => $transactionId,
                ],
            ]];
        }

        return $this->adminJson([
            'pastejobs' => $pasteJobs,
        ]);
    }

    /**
     * @Route("/copy", name="pimcore_admin_asset_copy", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function copyAction(Request $request): JsonResponse
    {
        $success = false;
        $sourceId = (int)$request->get('sourceId');
        $source = Asset::getById($sourceId);

        $session = Tool\Session::getSessionBag($request->getSession(), 'pimcore_copy');
        $sessionBag = $session->get($request->get('transactionId'));

        $targetId = (int)$request->get('targetId');
        if ($request->get('targetParentId')) {
            $sourceParent = Asset::getById((int) $request->get('sourceParentId'));

            // this is because the key can get the prefix "_copy" if the target does already exists
            if ($sessionBag['parentId']) {
                $targetParent = Asset::getById($sessionBag['parentId']);
            } else {
                $targetParent = Asset::getById((int) $request->get('targetParentId'));
            }

            $targetPath = preg_replace('@^' . $sourceParent->getRealFullPath() . '@', $targetParent . '/', $source->getRealPath());
            $target = Asset::getByPath($targetPath);
        } else {
            $target = Asset::getById($targetId);
        }

        if (!$target) {
            throw $this->createNotFoundException('Target not found');
        }

        if ($target->isAllowed('create')) {
            $source = Asset::getById($sourceId);
            if ($source != null) {
                if ($request->get('type') == 'child') {
                    $newAsset = $this->_assetService->copyAsChild($target, $source);

                    // this is because the key can get the prefix "_copy" if the target does already exists
                    if ($request->get('saveParentId')) {
                        $sessionBag['parentId'] = $newAsset->getId();
                    }
                } elseif ($request->get('type') == 'replace') {
                    $this->_assetService->copyContents($target, $source);
                }

                $session->set($request->get('transactionId'), $sessionBag);

                $success = true;
            } else {
                Logger::debug('prevended copy/paste because asset with same path+key already exists in this location');
            }
        } else {
            Logger::error('could not execute copy/paste because of missing permissions on target [ ' . $targetId . ' ]');

            throw $this->createAccessDeniedHttpException();
        }

        return $this->adminJson(['success' => $success]);
    }

    /**
     * @Route("/download-as-zip-jobs", name="pimcore_admin_asset_downloadaszipjobs", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function downloadAsZipJobsAction(Request $request): JsonResponse
    {
        $jobId = uniqid();
        $filesPerJob = 5;
        $jobs = [];
        $asset = Asset::getById((int) $request->get('id'));

        if (!$asset) {
            throw $this->createNotFoundException('Asset not found');
        }

        if ($asset->isAllowed('view')) {
            $parentPath = $asset->getRealFullPath();
            if ($asset->getId() == 1) {
                $parentPath = '';
            }

            $db = \Pimcore\Db::get();
            $conditionFilters = [];
            $selectedIds = explode(',', $request->get('selectedIds', ''));
            $quotedSelectedIds = [];
            foreach ($selectedIds as $selectedId) {
                if ($selectedId) {
                    $quotedSelectedIds[] = $db->quote($selectedId);
                }
            }
            if (!empty($quotedSelectedIds)) {
                //add a condition if id numbers are specified
                $conditionFilters[] = 'id IN (' . implode(',', $quotedSelectedIds) . ')';
            }
            $conditionFilters[] = '`path` LIKE ' . $db->quote(Helper::escapeLike($parentPath) . '/%') . ' AND `type` != ' . $db->quote('folder');
            if (!$this->getAdminUser()->isAdmin()) {
                $userIds = $this->getAdminUser()->getRoles();
                $userIds[] = $this->getAdminUser()->getId();
                $conditionFilters[] = ' (
                                                    (select list from users_workspaces_asset where userId in (' . implode(',', $userIds) . ') and LOCATE(CONCAT(`path`, filename),cpath)=1  ORDER BY LENGTH(cpath) DESC LIMIT 1)=1
                                                    OR
                                                    (select list from users_workspaces_asset where userId in (' . implode(',', $userIds) . ') and LOCATE(cpath,CONCAT(`path`, filename))=1  ORDER BY LENGTH(cpath) DESC LIMIT 1)=1
                                                 )';
            }

            $condition = implode(' AND ', $conditionFilters);

            $assetList = new Asset\Listing();
            $assetList->setCondition($condition);
            $assetList->setOrderKey('LENGTH(`path`)', false);
            $assetList->setOrder('ASC');

            for ($i = 0; $i < ceil($assetList->getTotalCount() / $filesPerJob); $i++) {
                $jobs[] = [[
                    'url' => $this->generateUrl('pimcore_admin_asset_downloadaszipaddfiles'),
                    'method' => 'GET',
                    'params' => [
                        'id' => $asset->getId(),
                        'selectedIds' => implode(',', $selectedIds),
                        'offset' => $i * $filesPerJob,
                        'limit' => $filesPerJob,
                        'jobId' => $jobId,
                    ],
                ]];
            }
        }

        return $this->adminJson([
            'success' => true,
            'jobs' => $jobs,
            'jobId' => $jobId,
        ]);
    }

    /**
     * @Route("/download-as-zip-add-files", name="pimcore_admin_asset_downloadaszipaddfiles", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function downloadAsZipAddFilesAction(Request $request): JsonResponse
    {
        $zipFile = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/download-zip-' . $request->get('jobId') . '.zip';
        $asset = Asset::getById((int) $request->get('id'));
        $success = false;

        if (!$asset) {
            throw $this->createNotFoundException('Asset not found');
        }

        if ($asset->isAllowed('view')) {
            $zip = new \ZipArchive();
            if (!is_file($zipFile)) {
                $zipState = $zip->open($zipFile, \ZipArchive::CREATE);
            } else {
                $zipState = $zip->open($zipFile);
            }

            if ($zipState === true) {
                $parentPath = $asset->getRealFullPath();
                if ($asset->getId() == 1) {
                    $parentPath = '';
                }

                $db = \Pimcore\Db::get();
                $conditionFilters = [];

                $selectedIds = $request->get('selectedIds', []);

                if (!empty($selectedIds)) {
                    $selectedIds = explode(',', $selectedIds);
                    //add a condition if id numbers are specified
                    $conditionFilters[] = 'id IN (' . implode(',', $selectedIds) . ')';
                }
                $conditionFilters[] = "`type` != 'folder' AND `path` like " . $db->quote(Helper::escapeLike($parentPath) . '/%');
                if (!$this->getAdminUser()->isAdmin()) {
                    $userIds = $this->getAdminUser()->getRoles();
                    $userIds[] = $this->getAdminUser()->getId();
                    $conditionFilters[] = ' (
                                                    (select list from users_workspaces_asset where userId in (' . implode(',', $userIds) . ') and LOCATE(CONCAT(`path`, filename),cpath)=1  ORDER BY LENGTH(cpath) DESC LIMIT 1)=1
                                                    OR
                                                    (select list from users_workspaces_asset where userId in (' . implode(',', $userIds) . ') and LOCATE(cpath,CONCAT(`path`, filename))=1  ORDER BY LENGTH(cpath) DESC LIMIT 1)=1
                                                 )';
                }

                $condition = implode(' AND ', $conditionFilters);

                $assetList = new Asset\Listing();
                $assetList->setCondition($condition);
                $assetList->setOrderKey('LENGTH(`path`) ASC, id ASC', false);
                $assetList->setOffset((int)$request->get('offset'));
                $assetList->setLimit((int)$request->get('limit'));

                foreach ($assetList as $a) {
                    if ($a->isAllowed('view')) {
                        if (!$a instanceof Asset\Folder) {
                            // add the file with the relative path to the parent directory
                            $zip->addFile($a->getLocalFile(), preg_replace('@^' . preg_quote($asset->getRealPath(), '@') . '@i', '', $a->getRealFullPath()));
                        }
                    }
                }

                $zip->close();
                $success = true;
            }
        }

        return $this->adminJson([
            'success' => $success,
        ]);
    }

    /**
     * @Route("/download-as-zip", name="pimcore_admin_asset_downloadaszip", methods={"GET"})
     *
     * @param Request $request
     *
     * @return BinaryFileResponse
     * Download all assets contained in the folder with parameter id as ZIP file.
     * The suggested filename is either [folder name].zip or assets.zip for the root folder.
     */
    public function downloadAsZipAction(Request $request): BinaryFileResponse
    {
        $asset = Asset::getById((int) $request->get('id'));
        if (!$asset) {
            throw $this->createNotFoundException('Asset not found');
        }
        $zipFile = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/download-zip-' . $request->get('jobId') . '.zip';
        $suggestedFilename = $asset->getFilename();
        if (empty($suggestedFilename)) {
            $suggestedFilename = 'assets';
        }

        $response = new BinaryFileResponse($zipFile);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $suggestedFilename . '.zip');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * @Route("/import-zip", name="pimcore_admin_asset_importzip", methods={"POST"})
     *
     * @param Request $request
     * @param TranslatorInterface $translator
     *
     * @return Response
     */
    public function importZipAction(Request $request, TranslatorInterface $translator): Response
    {
        $jobId = uniqid();
        $filesPerJob = 5;
        $jobs = [];
        $asset = Asset::getById((int) $request->get('parentId'));

        if (!is_file($_FILES['Filedata']['tmp_name'])) {
            return $this->adminJson([
                'success' => false,
                'message' => 'Something went wrong, please check upload_max_filesize and post_max_size in your php.ini as well as the write permissions on the file system',
            ]);
        }

        if (!$asset) {
            throw $this->createNotFoundException('Parent asset not found');
        }

        if (!$asset->isAllowed('create')) {
            throw $this->createAccessDeniedException('not allowed to create');
        }

        $zipFile = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/' . $jobId . '.zip';

        copy($_FILES['Filedata']['tmp_name'], $zipFile);

        $zip = new \ZipArchive;
        $retCode = $zip->open($zipFile);
        if ($retCode === true) {
            $jobAmount = ceil($zip->numFiles / $filesPerJob);
            for ($i = 0; $i < $jobAmount; $i++) {
                $jobs[] = [[
                    'url' => $this->generateUrl('pimcore_admin_asset_importzipfiles'),
                    'method' => 'POST',
                    'params' => [
                        'parentId' => $asset->getId(),
                        'offset' => $i * $filesPerJob,
                        'limit' => $filesPerJob,
                        'jobId' => $jobId,
                        'last' => (($i + 1) >= $jobAmount) ? 'true' : '',
                    ],
                ]];
            }
            $zip->close();

            // here we have to use this method and not the JSON action helper ($this->_helper->json()) because this will add
            // Content-Type: application/json which fires a download window in most browsers, because this is a normal POST
            // request and not XHR where the content-type doesn't matter
            $responseJson = $this->encodeJson([
                'success' => true,
                'jobs' => $jobs,
                'jobId' => $jobId,
            ]);

            return new Response($responseJson);
        } else {
            return $this->adminJson([
                'success' => false,
                'message' => $translator->trans('could_not_open_zip_file', [], 'admin'),
            ]);
        }
    }

    /**
     * @Route("/import-zip-files", name="pimcore_admin_asset_importzipfiles", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function importZipFilesAction(Request $request, Filesystem $filesystem): JsonResponse
    {
        $jobId = $request->get('jobId');
        $limit = (int)$request->get('limit');
        $offset = (int)$request->get('offset');
        $importAsset = Asset::getById((int) $request->get('parentId'));
        $zipFile = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/' . $jobId . '.zip';
        $tmpDir = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/zip-import';

        if (!is_dir($tmpDir)) {
            $filesystem->mkdir($tmpDir);
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipFile) === true) {
            for ($i = $offset; $i < ($offset + $limit); $i++) {
                $path = $zip->getNameIndex($i);

                if (str_starts_with($path, '__MACOSX/') || str_ends_with($path, '/Thumbs.db')) {
                    continue;
                }

                if ($path !== false) {
                    if ($zip->extractTo($tmpDir . '/', $path)) {
                        $tmpFile = $tmpDir . '/' . preg_replace('@^/@', '', $path);

                        $filename = Element\Service::getValidKey(basename($path), 'asset');

                        $relativePath = '';
                        if (dirname($path) != '.') {
                            $relativePath = dirname($path);
                        }

                        $parentPath = $importAsset->getRealFullPath() . '/' . preg_replace('@^/@', '', $relativePath);
                        $parent = Asset\Service::createFolderByPath($parentPath);

                        // check for duplicate filename
                        $filename = $this->getSafeFilename($parent->getRealFullPath(), $filename);

                        if ($parent->isAllowed('create')) {
                            $asset = Asset::create($parent->getId(), [
                                'filename' => $filename,
                                'sourcePath' => $tmpFile,
                                'userOwner' => $this->getAdminUser()->getId(),
                                'userModification' => $this->getAdminUser()->getId(),
                            ]);

                            @unlink($tmpFile);
                        } else {
                            Logger::debug('prevented creating asset because of missing permissions');
                        }
                    }
                }
            }
            $zip->close();
        }

        if ($request->get('last')) {
            unlink($zipFile);
        }

        return $this->adminJson([
            'success' => true,
        ]);
    }

    /**
     * @Route("/clear-thumbnail", name="pimcore_admin_asset_clearthumbnail", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function clearThumbnailAction(Request $request): JsonResponse
    {
        $success = false;

        if ($asset = Asset::getById((int) $request->get('id'))) {
            if (method_exists($asset, 'clearThumbnails')) {
                if (!$asset->isAllowed('publish')) {
                    throw $this->createAccessDeniedException('not allowed to publish');
                }

                $asset->clearThumbnails(true); // force clear
                $asset->save();

                $success = true;
            }
        }

        return $this->adminJson(['success' => $success]);
    }

    /**
     * @Route("/grid-proxy", name="pimcore_admin_asset_gridproxy", methods={"GET", "POST", "PUT"})
     *
     * @param Request $request
     * @param EventDispatcherInterface $eventDispatcher
     * @param GridHelperService $gridHelperService
     * @param CsrfProtectionHandler $csrfProtection
     *
     * @return JsonResponse
     */
    public function gridProxyAction(Request $request, EventDispatcherInterface $eventDispatcher, GridHelperService $gridHelperService, CsrfProtectionHandler $csrfProtection): JsonResponse
    {
        $allParams = array_merge($request->request->all(), $request->query->all());

        $filterPrepareEvent = new GenericEvent($this, [
            'requestParams' => $allParams,
        ]);
        $language = $request->get('language') != 'default' ? $request->get('language') : null;

        $eventDispatcher->dispatch($filterPrepareEvent, AdminEvents::ASSET_LIST_BEFORE_FILTER_PREPARE);

        $allParams = $filterPrepareEvent->getArgument('requestParams');

        $loader = \Pimcore::getContainer()->get('pimcore.implementation_loader.asset.metadata.data');

        if (isset($allParams['data']) && $allParams['data']) {
            $csrfProtection->checkCsrfToken($request);
            if ($allParams['xaction'] == 'update') {
                try {
                    $data = $this->decodeJson($allParams['data']);

                    $updateEvent = new GenericEvent($this, [
                        'data' => $data,
                        'processed' => false,
                    ]);

                    $eventDispatcher->dispatch($updateEvent, AdminEvents::ASSET_LIST_BEFORE_UPDATE);

                    $processed = $updateEvent->getArgument('processed');

                    if ($processed) {
                        // update already processed by event handler
                        return $this->adminJson(['success' => true]);
                    }

                    $data = $updateEvent->getArgument('data');

                    // save
                    $asset = Asset::getById($data['id']);

                    if (!$asset) {
                        throw $this->createNotFoundException('Asset not found');
                    }

                    if (!$asset->isAllowed('publish')) {
                        throw $this->createAccessDeniedException("Permission denied. You don't have the rights to save this asset.");
                    }

                    $metadata = $asset->getMetadata(null, null, false, true);
                    $dirty = false;

                    unset($data['id']);
                    foreach ($data as $key => $value) {
                        $fieldDef = explode('~', $key);
                        $key = $fieldDef[0];
                        if (isset($fieldDef[1])) {
                            $language = ($fieldDef[1] == 'none' ? '' : $fieldDef[1]);
                        }

                        foreach ($metadata as $idx => &$em) {
                            if ($em['name'] == $key && $em['language'] == $language) {
                                try {
                                    $dataImpl = $loader->build($em['type']);
                                    $value = $dataImpl->getDataFromListfolderGrid($value, $em);
                                } catch (UnsupportedException $le) {
                                    Logger::error('could not resolve metadata implementation for ' . $em['type']);
                                }

                                $em['data'] = $value;
                                $dirty = true;

                                break;
                            }
                        }

                        if (!$dirty) {
                            $defaulMetadata = ['title', 'alt', 'copyright'];
                            if (in_array($key, $defaulMetadata)) {
                                $newEm = [
                                    'name' => $key,
                                    'language' => $language,
                                    'type' => 'input',
                                    'data' => $value,
                                ];

                                try {
                                    $dataImpl = $loader->build($newEm['type']);
                                    $newEm['data'] = $dataImpl->getDataFromListfolderGrid($value, $newEm);
                                } catch (UnsupportedException $le) {
                                    Logger::error('could not resolve metadata implementation for ' . $newEm['type']);
                                }

                                $metadata[] = $newEm;

                                $dirty = true;
                            } else {
                                $predefined = Model\Metadata\Predefined::getByName($key);
                                if ($predefined && (empty($predefined->getTargetSubtype())
                                        || $predefined->getTargetSubtype() == $asset->getType())) {
                                    $newEm = [
                                        'name' => $key,
                                        'language' => $language,
                                        'type' => $predefined->getType(),
                                        'data' => $value,
                                    ];

                                    try {
                                        $dataImpl = $loader->build($newEm['type']);
                                        $newEm['data'] = $dataImpl->getDataFromListfolderGrid($value, $newEm);
                                    } catch (UnsupportedException $le) {
                                        Logger::error('could not resolve metadata implementation for ' . $newEm['type']);
                                    }

                                    $metadata[] = $newEm;
                                    $dirty = true;
                                }
                            }
                        }
                    }

                    if ($dirty) {
                        // $metadata = Asset\Service::minimizeMetadata($metadata, "grid");
                        $asset->setMetadataRaw($metadata);
                        $asset->save();

                        return $this->adminJson(['success' => true]);
                    }

                    return $this->adminJson(['success' => false, 'message' => 'something went wrong.']);
                } catch (\Exception $e) {
                    return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
                }
            }
        } else {
            $list = $gridHelperService->prepareAssetListingForGrid($allParams, $this->getAdminUser());

            $beforeListLoadEvent = new GenericEvent($this, [
                'list' => $list,
                'context' => $allParams,
            ]);
            $eventDispatcher->dispatch($beforeListLoadEvent, AdminEvents::ASSET_LIST_BEFORE_LIST_LOAD);
            /** @var Asset\Listing $list */
            $list = $beforeListLoadEvent->getArgument('list');

            $list->load();

            $assets = [];
            foreach ($list->getAssets() as $index => $asset) {
                // Like for treeGetChildrenByIdAction, so we respect isAllowed method which can be extended (object DI) for custom permissions, so relying only users_workspaces_asset is insufficient and could lead security breach
                if ($asset->isAllowed('list')) {
                    $a = Asset\Service::gridAssetData($asset, $allParams['fields'], $allParams['language'] ?? '');
                    $assets[] = $a;
                }
            }

            $result = ['data' => $assets, 'success' => true, 'total' => $list->getTotalCount()];

            $afterListLoadEvent = new GenericEvent($this, [
                'list' => $result,
                'context' => $allParams,
            ]);
            $eventDispatcher->dispatch($afterListLoadEvent, AdminEvents::ASSET_LIST_AFTER_LIST_LOAD);
            $result = $afterListLoadEvent->getArgument('list');

            return $this->adminJson($result);
        }

        return $this->adminJson(['success' => false]);
    }

    /**
     * @Route("/get-text", name="pimcore_admin_asset_gettext", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getTextAction(Request $request): JsonResponse
    {
        $asset = Asset::getById((int) $request->get('id'));

        if (!$asset) {
            throw $this->createNotFoundException('Asset not found');
        }

        if (!$asset->isAllowed('view')) {
            throw $this->createAccessDeniedException('not allowed to view');
        }

        $page = $request->get('page');
        $text = null;
        if ($asset instanceof Asset\Document) {
            $text = $asset->getText($page);
        }

        return $this->adminJson(['success' => 'true', 'text' => $text]);
    }

    public function onKernelControllerEvent(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->checkActionPermission($event, 'assets', [
            'getImageThumbnailAction', 'getVideoThumbnailAction', 'getDocumentThumbnailAction',
        ]);

        $this->_assetService = new Asset\Service($this->getAdminUser());
    }

    /**
     * @throws ValidationException
     */
    private function validateManyToManyRelationAssetType(array $context, string $filename, string $sourcePath): void
    {
        if (isset($context['containerType'], $context['objectId'], $context['fieldname'])
            && 'object' === $context['containerType']
            && $object = Concrete::getById($context['objectId'])
        ) {
            $fieldDefinition = $object->getClass()->getFieldDefinition($context['fieldname']);
            if (!$fieldDefinition instanceof ManyToManyRelation) {
                return;
            }

            $mimeType = MimeTypes::getDefault()->guessMimeType($sourcePath);
            $type = Asset::getTypeFromMimeMapping($mimeType, $filename);

            $allowedAssetTypes = $fieldDefinition->getAssetTypes();
            $allowedAssetTypes = array_column($allowedAssetTypes, 'assetTypes');

            if (
                !(
                    $fieldDefinition->getAssetsAllowed()
                    && ($allowedAssetTypes === [] || in_array($type, $allowedAssetTypes, true))
                )
            ) {
                throw new ValidationException(sprintf('Invalid relation in field `%s` [type: %s]', $context['fieldname'], $type));
            }
        }
    }
}
