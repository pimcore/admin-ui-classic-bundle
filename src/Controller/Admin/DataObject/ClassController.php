<?php
declare(strict_types=1);

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

namespace Pimcore\Bundle\AdminBundle\Controller\Admin\DataObject;

use Pimcore\Bundle\AdminBundle\Controller\AdminAbstractController;
use Pimcore\Bundle\AdminBundle\Event\AdminEvents;
use Pimcore\Controller\KernelControllerEventInterface;
use Pimcore\Db;
use Pimcore\Logger;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\Document;
use Pimcore\Model\Exception\ConfigWriteException;
use Pimcore\Model\Translation;
use Pimcore\Tool\Session;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("/class", name="pimcore_admin_dataobject_class_")
 *
 * @internal
 */
class ClassController extends AdminAbstractController implements KernelControllerEventInterface
{
    /**
     * @Route("/get-document-types", name="getdocumenttypes", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getDocumentTypesAction(Request $request): JsonResponse
    {
        $documentTypes = Document::getTypes();
        $typeItems = [];
        foreach ($documentTypes as $documentType) {
            $typeItems[] = [
                'text' => $documentType,
            ];
        }

        return $this->adminJson($typeItems);
    }

    /**
     * @Route("/get-asset-types", name="getassettypes", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getAssetTypesAction(Request $request): JsonResponse
    {
        $assetTypes = Asset::getTypes();
        $typeItems = [];
        foreach ($assetTypes as $assetType) {
            $typeItems[] = [
                'text' => $assetType,
            ];
        }

        return $this->adminJson($typeItems);
    }

    /**
     * @Route("/get-tree", name="gettree", methods={"GET", "POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getTreeAction(Request $request): JsonResponse
    {
        try {
            // we need to check objects permission for listing in pimcore.model.objecttypes ext model
            $this->checkPermission('objects');
        } catch (AccessDeniedHttpException $e) {
            Logger::log('[Startup] Object types are not loaded as "objects" permission is missing');

            //return empty string to avoid error on startup
            return $this->adminJson([]);
        }

        $defaultIcon = '/bundles/pimcoreadmin/img/flat-color-icons/class.svg';

        $classesList = new DataObject\ClassDefinition\Listing();
        $classesList->setOrderKey('name');
        $classesList->setOrder('asc');
        $classes = $classesList->load();

        // filter classes
        if ($request->get('createAllowed')) {
            $tmpClasses = [];
            foreach ($classes as $class) {
                if ($this->getAdminUser()->isAllowed($class->getId(), 'class')) {
                    $tmpClasses[] = $class;
                }
            }
            $classes = $tmpClasses;
        }

        $withId = $request->get('withId');
        $useTitle = $request->get('useTitle');
        $getClassConfig = function ($class) use ($defaultIcon, $withId, $useTitle) {
            $text = $class->getName();
            if ($useTitle) {
                $text = $class->getTitle() ?: $class->getName();
            }
            if ($withId) {
                $text .= ' (' . $class->getId() . ')';
            }

            $hasBrickField = false;
            foreach ($class->getFieldDefinitions() as $fieldDefinition) {
                if ($fieldDefinition instanceof DataObject\ClassDefinition\Data\Objectbricks) {
                    $hasBrickField = true;

                    break;
                }
            }

            return [
                'id' => $class->getId(),
                'text' => $text,
                'leaf' => true,
                'icon' => $class->getIcon() ? htmlspecialchars($class->getIcon()) : $defaultIcon,
                'cls' => 'pimcore_class_icon',
                'propertyVisibility' => $class->getPropertyVisibility(),
                'enableGridLocking' => $class->isEnableGridLocking(),
                'hasBrickField' => $hasBrickField,
            ];
        };

        // build groups
        $groups = [];
        foreach ($classes as $class) {
            $groupName = null;

            if ($class->getGroup()) {
                $type = 'manual';
                $groupName = $class->getGroup();
            } else {
                $type = 'auto';
                if (preg_match('@^([A-Za-z])([^A-Z]+)@', $class->getName(), $matches)) {
                    $groupName = $matches[0];
                }

                if (!$groupName) {
                    // this is eg. the case when class name uses only capital letters
                    $groupName = $class->getName();
                }
            }

            $groupName = Translation::getByKeyLocalized($groupName, Translation::DOMAIN_ADMIN, true, true);

            if (!isset($groups[$groupName])) {
                $groups[$groupName] = [
                    'classes' => [],
                    'type' => $type,
                ];
            }
            $groups[$groupName]['classes'][] = $class;
        }

        $treeNodes = [];
        if (!empty($groups)) {
            $types = array_column($groups, 'type');
            array_multisort($types, SORT_ASC, array_keys($groups), SORT_ASC, $groups);
        }

        if (!$request->get('grouped')) {
            // list output
            foreach ($groups as $groupName => $groupData) {
                foreach ($groupData['classes'] as $class) {
                    $node = $getClassConfig($class);
                    if (count($groupData['classes']) > 1 || $groupData['type'] == 'manual') {
                        $node['group'] = $groupName;
                    }
                    $treeNodes[] = $node;
                }
            }
        } else {
            // create json output
            foreach ($groups as $groupName => $groupData) {
                if (count($groupData['classes']) === 1 && $groupData['type'] == 'auto') {
                    // no group, only one child
                    $node = $getClassConfig($groupData['classes'][0]);
                } else {
                    // group classes
                    $node = [
                        'id' => 'folder_' . $groupName,
                        'text' => $groupName,
                        'leaf' => false,
                        'expandable' => true,
                        'allowChildren' => true,
                        'iconCls' => 'pimcore_icon_folder',
                        'children' => [],
                    ];

                    foreach ($groupData['classes'] as $class) {
                        $node['children'][] = $getClassConfig($class);
                    }
                }

                $treeNodes[] = $node;
            }
        }

        return $this->adminJson($treeNodes);
    }

    /**
     * @Route("/get", name="get", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getAction(Request $request): JsonResponse
    {
        $class = DataObject\ClassDefinition::getById($request->get('id'));
        if (!$class) {
            throw $this->createNotFoundException();
        }
        $class->setFieldDefinitions([]);
        $isWriteable = $class->isWritable();
        $class = $class->getObjectVars();
        $class['isWriteable'] = $isWriteable;

        return $this->adminJson($class);
    }

    /**
     * @Route("/get-custom-layout", name="getcustomlayout", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getCustomLayoutAction(Request $request): JsonResponse
    {
        $customLayout = DataObject\ClassDefinition\CustomLayout::getById($request->get('id'));
        if (!$customLayout) {
            $brickLayoutSeparator = strpos($request->get('id'), '.brick.');
            if ($brickLayoutSeparator !== false) {
                $customLayout = DataObject\ClassDefinition\CustomLayout::getById(substr($request->get('id'), 0, $brickLayoutSeparator));
                if ($customLayout instanceof DataObject\ClassDefinition\CustomLayout) {
                    $customLayout = DataObject\ClassDefinition\CustomLayout::create(
                        [
                            'name' => $customLayout->getName().' '.substr($request->get('id'), $brickLayoutSeparator+strlen('.brick.')),
                            'userOwner' => $this->getAdminUser()->getId(),
                            'classId' => $customLayout->getClassId(),
                        ]
                    );

                    $customLayout->setId($request->get('id'));
                    if (!$customLayout->isWriteable()) {
                        throw new ConfigWriteException();
                    }
                    $customLayout->save();
                }
            }

            if (!$customLayout) {
                throw $this->createNotFoundException();
            }
        }
        $isWriteable = $customLayout->isWriteable();
        $customLayout = $customLayout->getObjectVars();
        $customLayout['isWriteable'] = $isWriteable;

        return $this->adminJson(['success' => true, 'data' => $customLayout]);
    }

    /**
     * @Route("/add", name="add", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function addAction(Request $request): JsonResponse
    {
        $className = $request->get('className');
        $className = $this->correctClassname($className);

        $classId = $request->get('classIdentifier');
        $existingClass = DataObject\ClassDefinition::getById($classId);
        if ($existingClass) {
            throw new \Exception('Class identifier already exists');
        }

        $class = DataObject\ClassDefinition::create(
            ['name' => $className,
                'userOwner' => $this->getAdminUser()->getId(), ]
        );

        $class->setId($classId);

        $class->save(true);

        return $this->adminJson(['success' => true, 'id' => $class->getId()]);
    }

    /**
     * @Route("/add-custom-layout", name="addcustomlayout", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function addCustomLayoutAction(Request $request): JsonResponse
    {
        $layoutId = $request->get('layoutIdentifier');
        $existingLayout = DataObject\ClassDefinition\CustomLayout::getById($layoutId);
        if ($existingLayout) {
            throw new \Exception('Custom Layout identifier already exists');
        }

        $customLayout = DataObject\ClassDefinition\CustomLayout::create(
            [
                'name' => $request->get('layoutName'),
                'userOwner' => $this->getAdminUser()->getId(),
                'classId' => $request->get('classId'),
            ]
        );

        $customLayout->setId($layoutId);
        if (!$customLayout->isWriteable()) {
            throw new ConfigWriteException();
        }
        $customLayout->save();

        $isWriteable = $customLayout->isWriteable();
        $data = $customLayout->getObjectVars();
        $data['isWriteable'] = $isWriteable;

        return $this->adminJson(['success' => true, 'id' => $customLayout->getId(), 'name' => $customLayout->getName(),
                                 'data' => $data, ]);
    }

    /**
     * @Route("/delete", name="delete", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function deleteAction(Request $request): Response
    {
        $class = DataObject\ClassDefinition::getById($request->get('id'));
        if ($class) {
            $class->delete();
        }

        return new Response();
    }

    /**
     * @Route("/delete-custom-layout", name="deletecustomlayout", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function deleteCustomLayoutAction(Request $request): JsonResponse
    {
        $customLayouts = new DataObject\ClassDefinition\CustomLayout\Listing();
        $id = $request->get('id');
        $customLayouts->setFilter(function (DataObject\ClassDefinition\CustomLayout $layout) use ($id) {
            $currentLayoutId = $layout->getId();

            return $currentLayoutId === $id || str_starts_with($currentLayoutId, $id . '.brick.');
        });

        foreach ($customLayouts->getLayoutDefinitions() as $customLayout) {
            $customLayout->delete();
        }

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/save-custom-layout", name="savecustomlayout", methods={"PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function saveCustomLayoutAction(Request $request): JsonResponse
    {
        $customLayout = DataObject\ClassDefinition\CustomLayout::getById($request->get('id'));
        if (!$customLayout) {
            throw $this->createNotFoundException();
        }

        $configuration = $this->decodeJson($request->get('configuration'));
        $values = $this->decodeJson($request->get('values'));

        $modificationDate = (int)$values['modificationDate'];
        if ($modificationDate < $customLayout->getModificationDate()) {
            return $this->adminJson(['success' => false, 'msg' => 'custom_layout_changed']);
        }

        $configuration['datatype'] = 'layout';
        $configuration['fieldtype'] = 'panel';
        $configuration['name'] = 'pimcore_root';

        try {
            $layout = DataObject\ClassDefinition\Service::generateLayoutTreeFromArray($configuration, true);
            $customLayout->setLayoutDefinitions($layout);
            $customLayout->setName($values['name']);
            $customLayout->setDescription($values['description']);
            $customLayout->setDefault($values['default']);
            if (!$customLayout->isWriteable()) {
                throw new ConfigWriteException();
            }
            $customLayout->save();

            return $this->adminJson(['success' => true, 'id' => $customLayout->getId(), 'data' => $customLayout->getObjectVars()]);
        } catch (\Exception $e) {
            Logger::error($e->getMessage());

            return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * @Route("/save", name="save", methods={"PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function saveAction(Request $request): JsonResponse
    {
        $class = DataObject\ClassDefinition::getById($request->get('id'));
        if (!$class) {
            throw $this->createNotFoundException();
        }

        $configuration = $this->decodeJson($request->get('configuration'));
        $values = $this->decodeJson($request->get('values'));

        // check if the class was changed during editing in the frontend
        if ($class->getModificationDate() != $values['modificationDate']) {
            throw new \Exception('The class was modified during editing, please reload the class and make your changes again');
        }

        if ($values['name'] != $class->getName()) {
            $classByName = DataObject\ClassDefinition::getByName($values['name']);
            if ($classByName && $classByName->getId() != $class->getId()) {
                throw new \Exception('Class name already exists');
            }

            $values['name'] = $this->correctClassname($values['name']);
            $class->rename($values['name']);
        }

        if ($values['compositeIndices']) {
            foreach ($values['compositeIndices'] as $index => $compositeIndex) {
                if ($compositeIndex['index_key'] !== ($sanitizedKey = preg_replace('/[^a-za-z0-9_\-+]/', '', $compositeIndex['index_key']))) {
                    $values['compositeIndices'][$index]['index_key'] = $sanitizedKey;
                }
            }
        }

        unset($values['creationDate']);
        unset($values['userOwner']);
        unset($values['layoutDefinitions']);
        unset($values['fieldDefinitions']);

        $configuration['datatype'] = 'layout';
        $configuration['fieldtype'] = 'panel';
        $configuration['name'] = 'pimcore_root';

        $class->setValues($values);

        try {
            $layout = DataObject\ClassDefinition\Service::generateLayoutTreeFromArray($configuration, true);

            $class->setLayoutDefinitions($layout);

            $class->setUserModification($this->getAdminUser()->getId());
            $class->setModificationDate(time());

            $propertyVisibility = [];
            foreach ($values as $key => $value) {
                if (preg_match('/propertyVisibility/i', $key)) {
                    if (preg_match("/\.grid\./i", $key)) {
                        $propertyVisibility['grid'][preg_replace("/propertyVisibility\.grid\./i", '', $key)] = (bool) $value;
                    } elseif (preg_match("/\.search\./i", $key)) {
                        $propertyVisibility['search'][preg_replace("/propertyVisibility\.search\./i", '', $key)] = (bool) $value;
                    }
                }
            }
            if (!empty($propertyVisibility)) {
                $class->setPropertyVisibility($propertyVisibility);
            }

            $class->save();

            // set the fielddefinitions to [] because we don't need them in the response
            $class->setFieldDefinitions([]);

            return $this->adminJson(['success' => true, 'class' => $class]);
        } catch (\Exception $e) {
            Logger::error($e->getMessage());

            return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    protected function correctClassname(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]+/', '', $name);
        $name = preg_replace('/^[0-9]+/', '', $name);

        return $name;
    }

    /**
     * @Route("/import-class", name="importclass", methods={"POST", "PUT"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function importClassAction(Request $request): Response
    {
        $class = DataObject\ClassDefinition::getById($request->get('id'));
        if (!$class) {
            throw $this->createNotFoundException();
        }
        $json = file_get_contents($_FILES['Filedata']['tmp_name']);

        $success = DataObject\ClassDefinition\Service::importClassDefinitionFromJson($class, $json, false, true);

        $response = $this->adminJson([
            'success' => $success,
        ]);
        // set content-type to text/html, otherwise (when application/json is sent) chrome will complain in
        // Ext.form.Action.Submit and mark the submission as failed
        $response->headers->set('Content-Type', 'text/html');

        return $response;
    }

    /**
     * @Route("/import-custom-layout-definition", name="importcustomlayoutdefinition", methods={"POST", "PUT"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function importCustomLayoutDefinitionAction(Request $request): Response
    {
        $success = false;
        $responseContent = [];
        $json = file_get_contents($_FILES['Filedata']['tmp_name']);
        $importData = $this->decodeJson($json);

        $existingLayout = null;
        if (isset($importData['name'])) {
            $existingLayout = DataObject\ClassDefinition\CustomLayout::getByName($importData['name']);

            if ($existingLayout instanceof DataObject\ClassDefinition\CustomLayout) {
                $responseContent['nameAlreadyInUse'] = true;
            }
        }

        if (!$existingLayout instanceof DataObject\ClassDefinition\CustomLayout) {
            $customLayoutId = $request->get('id');
            $customLayout = DataObject\ClassDefinition\CustomLayout::getById($customLayoutId);
            if ($customLayout) {
                try {
                    $layout = DataObject\ClassDefinition\Service::generateLayoutTreeFromArray($importData['layoutDefinitions'], true);
                    $customLayout->setLayoutDefinitions($layout);
                    if (isset($importData['name']) === true) {
                        $customLayout->setName($importData['name']);
                    }
                    $customLayout->setDescription($importData['description']);
                    if (!$customLayout->isWriteable()) {
                        throw new ConfigWriteException();
                    }
                    $customLayout->save();
                    $success = true;
                } catch (\Exception $e) {
                    Logger::error($e->getMessage());
                }
            }

            $responseContent['success'] = $success;
        }

        $response = $this->adminJson($responseContent);

        // set content-type to text/html, otherwise (when application/json is sent) chrome will complain in
        // Ext.form.Action.Submit and mark the submission as failed
        $response->headers->set('Content-Type', 'text/html');

        return $response;
    }

    /**
     * @Route("/get-custom-layout-definitions", name="getcustomlayoutdefinitions", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getCustomLayoutDefinitionsAction(Request $request): JsonResponse
    {
        $classIds = explode(',', $request->get('classId'));
        $list = new DataObject\ClassDefinition\CustomLayout\Listing();

        $list->setFilter(function (DataObject\ClassDefinition\CustomLayout $layout) use ($classIds) {
            return in_array($layout->getClassId(), $classIds) && !str_contains($layout->getId(), '.brick.');
        });
        $list = $list->load();
        $result = [];
        foreach ($list as $item) {
            $result[] = [
                'id' => $item->getId(),
                'name' => $item->getName(),
                'default' => $item->getDefault(),
            ];
        }

        return $this->adminJson(['success' => true, 'data' => $result]);
    }

    /**
     * @Route("/get-all-layouts", name="getalllayouts", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getAllLayoutsAction(Request $request): JsonResponse
    {
        // get all classes
        $resultList = [];
        $mapping = [];

        $customLayouts = new DataObject\ClassDefinition\CustomLayout\Listing();
        $customLayouts->setFilter(function (DataObject\ClassDefinition\CustomLayout $layout) {
            return !str_contains($layout->getId(), '.brick.');
        });
        $customLayouts->setOrder(function (DataObject\ClassDefinition\CustomLayout $a, DataObject\ClassDefinition\CustomLayout $b) {
            return strcmp($a->getName(), $b->getName());
        });

        $customLayouts = $customLayouts->load();
        foreach ($customLayouts as $layout) {
            $mapping[$layout->getClassId()][] = $layout;
        }

        $classList = new DataObject\ClassDefinition\Listing();
        $classList->setOrder('ASC');
        $classList->setOrderKey('name');
        $classList = $classList->load();

        foreach ($classList as $class) {
            if (isset($mapping[$class->getId()])) {
                $classMapping = $mapping[$class->getId()];
                $resultList[] = [
                    'type' => 'main',
                    'id' => $class->getId() . '_' . 0,
                    'name' => $class->getName(),
                ];

                foreach ($classMapping as $layout) {
                    $resultList[] = [
                        'type' => 'custom',
                        'id' => $class->getId() . '_' . $layout->getId(),
                        'name' => $class->getName() . ' - ' . $layout->getName(),
                    ];
                }
            }
        }

        return $this->adminJson(['data' => $resultList]);
    }

    /**
     * @Route("/export-class", name="exportclass", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function exportClassAction(Request $request): Response
    {
        $id = $request->get('id');
        $class = DataObject\ClassDefinition::getById($id);

        if (!$class instanceof DataObject\ClassDefinition) {
            $errorMessage = ': Class with id [ ' . $id . ' not found. ]';
            Logger::error($errorMessage);

            throw $this->createNotFoundException($errorMessage);
        }

        $json = DataObject\ClassDefinition\Service::generateClassDefinitionJson($class);

        $response = new Response($json);
        $response->headers->set('Content-type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="class_' . $class->getName() . '_export.json"');

        return $response;
    }

    /**
     * @Route("/export-custom-layout-definition", name="exportcustomlayoutdefinition", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function exportCustomLayoutDefinitionAction(Request $request): Response
    {
        $id = $request->get('id');

        if ($id) {
            $customLayout = DataObject\ClassDefinition\CustomLayout::getById($id);
            if ($customLayout) {
                $name = $customLayout->getName();
                $json = DataObject\ClassDefinition\Service::generateCustomLayoutJson($customLayout);

                $response = new Response($json);
                $response->headers->set('Content-type', 'application/json');
                $response->headers->set('Content-Disposition', 'attachment; filename="custom_definition_' . $name . '_export.json"');

                return $response;
            }
        }

        $errorMessage = ': Custom Layout with id [ ' . $id . ' not found. ]';
        Logger::error($errorMessage);

        throw $this->createNotFoundException($errorMessage);
    }

    /**
     * FIELDCOLLECTIONS
     */

    /**
     * @Route("/fieldcollection-get", name="fieldcollectionget", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function fieldcollectionGetAction(Request $request): JsonResponse
    {
        $fc = DataObject\Fieldcollection\Definition::getByKey($request->get('id'));

        $isWriteable = $fc->isWritable();
        $fc = $fc->getObjectVars();
        $fc['isWriteable'] = $isWriteable;

        return $this->adminJson($fc);
    }

    /**
     * @Route("/fieldcollection-update", name="fieldcollectionupdate", methods={"PUT", "POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function fieldcollectionUpdateAction(Request $request): JsonResponse
    {
        try {
            $key = $request->get('key');
            $title = $request->get('title');
            $group = $request->get('group');

            if ($request->get('task') == 'add') {
                // check for existing fieldcollection with same name with different lower/upper cases
                $list = new DataObject\Fieldcollection\Definition\Listing();
                $list = $list->load();

                foreach ($list as $item) {
                    if (strtolower($key) === strtolower($item->getKey())) {
                        throw new \Exception('FieldCollection with the same name already exists (lower/upper cases may be different)');
                    }
                }
            }

            $fcDef = new DataObject\Fieldcollection\Definition();
            $fcDef->setKey($key);
            $fcDef->setTitle($title);
            $fcDef->setGroup($group);

            if ($request->get('values')) {
                $values = $this->decodeJson($request->get('values'));
                $fcDef->setParentClass($values['parentClass']);
                $fcDef->setImplementsInterfaces($values['implementsInterfaces']);
            }

            if ($request->get('configuration')) {
                $configuration = $this->decodeJson($request->get('configuration'));

                $configuration['datatype'] = 'layout';
                $configuration['fieldtype'] = 'panel';

                $layout = DataObject\ClassDefinition\Service::generateLayoutTreeFromArray($configuration, true);
                $fcDef->setLayoutDefinitions($layout);
            }

            $fcDef->save();

            return $this->adminJson(['success' => true, 'id' => $fcDef->getKey()]);
        } catch (\Exception $e) {
            Logger::error($e->getMessage());

            return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * @Route("/import-fieldcollection", name="importfieldcollection", methods={"POST"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function importFieldcollectionAction(Request $request): Response
    {
        $this->checkPermission('fieldcollections');

        $fieldCollection = DataObject\Fieldcollection\Definition::getByKey($request->get('id'));

        $data = file_get_contents($_FILES['Filedata']['tmp_name']);

        $success = DataObject\ClassDefinition\Service::importFieldCollectionFromJson($fieldCollection, $data);

        $response = $this->adminJson([
            'success' => $success,
        ]);

        // set content-type to text/html, otherwise (when application/json is sent) chrome will complain in
        // Ext.form.Action.Submit and mark the submission as failed
        $response->headers->set('Content-Type', 'text/html');

        return $response;
    }

    /**
     * @Route("/export-fieldcollection", name="exportfieldcollection", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function exportFieldcollectionAction(Request $request): Response
    {
        $this->checkPermission('fieldcollections');

        $fieldCollection = DataObject\Fieldcollection\Definition::getByKey($request->get('id'));

        if (!$fieldCollection instanceof DataObject\Fieldcollection\Definition) {
            $errorMessage = ': Field-Collection with id [ ' . $request->get('id') . ' not found. ]';
            Logger::error($errorMessage);

            throw $this->createNotFoundException($errorMessage);
        }

        $json = DataObject\ClassDefinition\Service::generateFieldCollectionJson($fieldCollection);
        $response = new Response($json);
        $response->headers->set('Content-type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="fieldcollection_' . $fieldCollection->getKey() . '_export.json"');

        return $response;
    }

    /**
     * @Route("/fieldcollection-delete", name="fieldcollectiondelete", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function fieldcollectionDeleteAction(Request $request): JsonResponse
    {
        $this->checkPermission('fieldcollections');

        $fc = DataObject\Fieldcollection\Definition::getByKey($request->get('id'));
        $fc->delete();

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/fieldcollection-tree", name="fieldcollectiontree", methods={"GET", "POST"})
     *
     * @param Request $request
     * @param EventDispatcherInterface $eventDispatcher
     *
     * @return JsonResponse
     */
    public function fieldcollectionTreeAction(Request $request, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        $list = new DataObject\Fieldcollection\Definition\Listing();
        $list = $list->load();

        $forObjectEditor = $request->get('forObjectEditor');

        $layoutDefinitions = [];

        $definitions = [];

        $allowedTypes = null;
        if ($request->query->has('allowedTypes')) {
            $allowedTypes = explode(',', $request->get('allowedTypes'));
        }
        $object = DataObject\Concrete::getById((int) $request->get('object_id'));

        $currentLayoutId = $request->get('layoutId', null);
        $user = \Pimcore\Tool\Admin::getCurrentUser();

        $groups = [];
        foreach ($list as $item) {
            if ($allowedTypes && !in_array($item->getKey(), $allowedTypes)) {
                continue;
            }

            if ($item->getGroup()) {
                if (!isset($groups[$item->getGroup()])) {
                    $groups[$item->getGroup()] = [
                        'id' => 'group_' . $item->getKey(),
                        'text' => htmlspecialchars($item->getGroup()),
                        'expandable' => true,
                        'leaf' => false,
                        'allowChildren' => true,
                        'iconCls' => 'pimcore_icon_folder',
                        'group' => $item->getGroup(),
                        'children' => [],
                    ];
                }
                if ($forObjectEditor) {
                    $itemLayoutDefinitions = $item->getLayoutDefinitions();
                    DataObject\Service::enrichLayoutDefinition($itemLayoutDefinitions, $object);

                    if ($currentLayoutId == -1 && $user->isAdmin()) {
                        DataObject\Service::createSuperLayout($itemLayoutDefinitions);
                    }
                    $layoutDefinitions[$item->getKey()] = $itemLayoutDefinitions;
                }
                $groups[$item->getGroup()]['children'][] =
                    [
                        'id' => $item->getKey(),
                        'text' => $item->getKey(),
                        'title' => $item->getTitle(),
                        'key' => $item->getKey(),
                        'leaf' => true,
                        'iconCls' => 'pimcore_icon_fieldcollection',
                    ];
            } else {
                if ($forObjectEditor) {
                    $itemLayoutDefinitions = $item->getLayoutDefinitions();
                    DataObject\Service::enrichLayoutDefinition($itemLayoutDefinitions, $object);

                    if ($currentLayoutId == -1 && $user->isAdmin()) {
                        DataObject\Service::createSuperLayout($itemLayoutDefinitions);
                    }

                    $layoutDefinitions[$item->getKey()] = $itemLayoutDefinitions;
                }
                $definitions[] = [
                    'id' => $item->getKey(),
                    'text' => $item->getKey(),
                    'title' => $item->getTitle(),
                    'key' => $item->getKey(),
                    'leaf' => true,
                    'iconCls' => 'pimcore_icon_fieldcollection',
                ];
            }
        }

        foreach ($groups as $group) {
            $definitions[] = $group;
        }

        $event = new GenericEvent($this, [
            'list' => $definitions,
            'objectId' => $request->get('object_id'),
            'layoutDefinitions' => $layoutDefinitions,
        ]);
        $eventDispatcher->dispatch($event, AdminEvents::CLASS_FIELDCOLLECTION_LIST_PRE_SEND_DATA);
        $definitions = $event->getArgument('list');
        $layoutDefinitions = $event->getArgument('layoutDefinitions');

        if ($forObjectEditor) {
            return $this->adminJson(['fieldcollections' => $definitions, 'layoutDefinitions' => $layoutDefinitions]);
        }

        return $this->adminJson($definitions);
    }

    /**
     * @Route("/fieldcollection-list", name="fieldcollectionlist", methods={"GET"})
     *
     * @param Request $request
     * @param EventDispatcherInterface $eventDispatcher
     *
     * @return JsonResponse
     */
    public function fieldcollectionListAction(Request $request, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        $user = \Pimcore\Tool\Admin::getCurrentUser();
        $currentLayoutId = $request->get('layoutId');

        $list = new DataObject\Fieldcollection\Definition\Listing();
        $list = $list->load();

        if ($request->query->has('allowedTypes')) {
            $filteredList = [];
            $allowedTypes = explode(',', $request->get('allowedTypes'));
            foreach ($list as $type) {
                if (in_array($type->getKey(), $allowedTypes)) {
                    $filteredList[] = $type;

                    // mainly for objects-meta data-type
                    $layoutDefinitions = $type->getLayoutDefinitions();
                    $context = [
                        'containerType' => 'fieldcollection',
                        'containerKey' => $type->getKey(),
                        'outerFieldname' => $request->get('field_name'),
                    ];

                    $object = DataObject\Concrete::getById((int) $request->get('object_id'));

                    DataObject\Service::enrichLayoutDefinition($layoutDefinitions, $object, $context);

                    if ($currentLayoutId == -1 && $user->isAdmin()) {
                        DataObject\Service::createSuperLayout($layoutDefinitions);
                    }
                }
            }

            $list = $filteredList;
        }

        $event = new GenericEvent($this, [
            'list' => $list,
            'objectId' => $request->get('object_id'),
        ]);
        $eventDispatcher->dispatch($event, AdminEvents::CLASS_FIELDCOLLECTION_LIST_PRE_SEND_DATA);
        $list = $event->getArgument('list');

        return $this->adminJson(['fieldcollections' => $list]);
    }

    /**
     * @Route("/get-class-definition-for-column-config", name="getclassdefinitionforcolumnconfig", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getClassDefinitionForColumnConfigAction(Request $request): JsonResponse
    {
        $class = DataObject\ClassDefinition::getById($request->get('id'));
        if (!$class) {
            throw $this->createNotFoundException();
        }
        $objectId = (int)$request->get('oid');

        $filteredDefinitions = DataObject\Service::getCustomLayoutDefinitionForGridColumnConfig($class, $objectId);

        /** @var DataObject\ClassDefinition\Layout $layoutDefinitions */
        $layoutDefinitions = isset($filteredDefinitions['layoutDefinition']) ? $filteredDefinitions['layoutDefinition'] : false;
        $filteredFieldDefinition = isset($filteredDefinitions['fieldDefinition']) ? $filteredDefinitions['fieldDefinition'] : false;

        $class->setFieldDefinitions([]);

        $result = [];

        DataObject\Service::enrichLayoutDefinition($layoutDefinitions);

        $result['objectColumns']['children'] = $layoutDefinitions->getChildren();
        $result['objectColumns']['nodeLabel'] = 'object_columns';
        $result['objectColumns']['nodeType'] = 'object';

        // array("id", "fullpath", "published", "creationDate", "modificationDate", "filename", "classname");
        $systemColumnNames = DataObject\Concrete::SYSTEM_COLUMN_NAMES;
        $systemColumns = [];
        foreach ($systemColumnNames as $systemColumn) {
            $systemColumns[] = ['title' => $systemColumn, 'name' => $systemColumn, 'datatype' => 'data', 'fieldtype' => 'system'];
        }
        $result['systemColumns']['nodeLabel'] = 'system_columns';
        $result['systemColumns']['nodeType'] = 'system';
        $result['systemColumns']['children'] = $systemColumns;

        $list = new DataObject\Objectbrick\Definition\Listing();
        $list = $list->load();

        foreach ($list as $brickDefinition) {
            $classDefs = $brickDefinition->getClassDefinitions();
            if (!empty($classDefs)) {
                foreach ($classDefs as $classDef) {
                    if ($classDef['classname'] == $class->getName()) {
                        $fieldName = $classDef['fieldname'];
                        if ($filteredFieldDefinition && !$filteredFieldDefinition[$fieldName]) {
                            continue;
                        }

                        $key = $brickDefinition->getKey();

                        $brickLayoutDefinitions = $brickDefinition->getLayoutDefinitions();
                        $context = [
                            'containerType' => 'objectbrick',
                            'containerKey' => $key,
                            'outerFieldname' => $fieldName,
                        ];
                        DataObject\Service::enrichLayoutDefinition($brickLayoutDefinitions, null, $context);

                        $result[$key]['nodeLabel'] = $key;
                        $result[$key]['brickField'] = $fieldName;
                        $result[$key]['nodeType'] = 'objectbricks';
                        $result[$key]['children'] = $brickLayoutDefinitions->getChildren();

                        break;
                    }
                }
            }
        }

        return $this->adminJson($result);
    }

    /**
     * OBJECT BRICKS
     */

    /**
     * @Route("/objectbrick-get", name="objectbrickget", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function objectbrickGetAction(Request $request): JsonResponse
    {
        $fc = DataObject\Objectbrick\Definition::getByKey($request->get('id'));

        $isWriteable = $fc->isWritable();
        $fc = $fc->getObjectVars();
        $fc['isWriteable'] = $isWriteable;

        return $this->adminJson($fc);
    }

    /**
     * @Route("/objectbrick-update", name="objectbrickupdate", methods={"PUT", "POST"})
     *
     * @param Request $request
     * @param EventDispatcherInterface $eventDispatcher
     *
     * @return JsonResponse
     */
    public function objectbrickUpdateAction(Request $request, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        try {
            $key = $request->get('key');
            $title = $request->get('title');
            $group = $request->get('group');

            if ($request->get('task') == 'add') {
                // check for existing brick with same name with different lower/upper cases
                $list = new DataObject\Objectbrick\Definition\Listing();
                $list = $list->load();

                foreach ($list as $item) {
                    if (strtolower($key) === strtolower($item->getKey())) {
                        throw new \Exception('Brick with the same name already exists (lower/upper cases may be different)');
                    }
                }
            }

            // now we create a new definition
            $brickDef = new DataObject\Objectbrick\Definition();
            $brickDef->setKey($key);
            $brickDef->setTitle($title);
            $brickDef->setGroup($group);

            if ($request->get('values')) {
                $values = $this->decodeJson($request->get('values'));

                $brickDef->setParentClass($values['parentClass']);
                $brickDef->setImplementsInterfaces($values['implementsInterfaces']);
                $brickDef->setClassDefinitions($values['classDefinitions']);
            }

            if ($request->get('configuration')) {
                $configuration = $this->decodeJson($request->get('configuration'));

                $configuration['datatype'] = 'layout';
                $configuration['fieldtype'] = 'panel';

                $layout = DataObject\ClassDefinition\Service::generateLayoutTreeFromArray($configuration, true);
                $brickDef->setLayoutDefinitions($layout);
            }

            $event = new GenericEvent($this, [
                'brickDefinition' => $brickDef,
            ]);
            $eventDispatcher->dispatch($event, AdminEvents::CLASS_OBJECTBRICK_UPDATE_DEFINITION);
            $brickDef = $event->getArgument('brickDefinition');

            $brickDef->save();

            return $this->adminJson(['success' => true, 'id' => $brickDef->getKey()]);
        } catch (\Exception $e) {
            Logger::error($e->getMessage());

            return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * @Route("/import-objectbrick", name="importobjectbrick", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function importObjectbrickAction(Request $request): JsonResponse
    {
        $this->checkPermission('objectbricks');

        $objectBrick = DataObject\Objectbrick\Definition::getByKey($request->get('id'));

        $data = file_get_contents($_FILES['Filedata']['tmp_name']);
        $success = DataObject\ClassDefinition\Service::importObjectBrickFromJson($objectBrick, $data);

        $response = $this->adminJson([
            'success' => $success,
        ]);

        // set content-type to text/html, otherwise (when application/json is sent) chrome will complain in
        // Ext.form.Action.Submit and mark the submission as failed
        $response->headers->set('Content-Type', 'text/html');

        return $response;
    }

    /**
     * @Route("/export-objectbrick", name="exportobjectbrick", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function exportObjectbrickAction(Request $request): Response
    {
        $this->checkPermission('objectbricks');

        $objectBrick = DataObject\Objectbrick\Definition::getByKey($request->get('id'));

        if (!$objectBrick instanceof DataObject\Objectbrick\Definition) {
            $errorMessage = ': Object-Brick with id [ ' . $request->get('id') . ' not found. ]';
            Logger::error($errorMessage);

            throw $this->createNotFoundException($errorMessage);
        }

        $xml = DataObject\ClassDefinition\Service::generateObjectBrickJson($objectBrick);
        $response = new Response($xml);
        $response->headers->set('Content-type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="objectbrick_' . $objectBrick->getKey() . '_export.json"');

        return $response;
    }

    /**
     * @Route("/objectbrick-delete", name="objectbrickdelete", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function objectbrickDeleteAction(Request $request): JsonResponse
    {
        $this->checkPermission('objectbricks');

        $fc = DataObject\Objectbrick\Definition::getByKey($request->get('id'));
        $fc->delete();

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/objectbrick-tree", name="objectbricktree", methods={"GET", "POST"})
     *
     * @param Request $request
     * @param EventDispatcherInterface $eventDispatcher
     *
     * @return JsonResponse
     */
    public function objectbrickTreeAction(Request $request, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        $list = new DataObject\Objectbrick\Definition\Listing();
        $list = $list->load();

        $forObjectEditor = $request->get('forObjectEditor');

        $context = [];
        $layoutDefinitions = [];
        $groups = [];
        $definitions = [];
        $fieldname = null;
        $className = null;

        $object = DataObject\Concrete::getById((int) $request->get('object_id'));

        if ($request->query->has('class_id') && $request->query->has('field_name')) {
            $classId = $request->get('class_id');
            $fieldname = $request->get('field_name');
            $classDefinition = DataObject\ClassDefinition::getById($classId);
            $className = $classDefinition->getName();
        }

        foreach ($list as $item) {
            if ($forObjectEditor) {
                $context = [
                    'containerType' => 'objectbrick',
                    'containerKey' => $item->getKey(),
                    'outerFieldname' => $fieldname,
                ];
            }
            if ($request->query->has('class_id') && $request->query->has('field_name')) {
                $keep = false;
                $clsDefs = $item->getClassDefinitions();
                if (!empty($clsDefs)) {
                    foreach ($clsDefs as $cd) {
                        if ($cd['classname'] == $className && $cd['fieldname'] == $fieldname) {
                            $keep = true;

                            continue;
                        }
                    }
                }
                if (!$keep) {
                    continue;
                }
            }

            if ($item->getGroup()) {
                if (!isset($groups[$item->getGroup()])) {
                    $groups[$item->getGroup()] = [
                        'id' => 'group_' . $item->getKey(),
                        'text' => htmlspecialchars($item->getGroup()),
                        'expandable' => true,
                        'leaf' => false,
                        'allowChildren' => true,
                        'iconCls' => 'pimcore_icon_folder',
                        'group' => $item->getGroup(),
                        'children' => [],
                    ];
                }
                if ($forObjectEditor) {
                    $layoutId = $request->get('layoutId');
                    $itemLayoutDefinitions = null;
                    if ($layoutId) {
                        $layout = DataObject\ClassDefinition\CustomLayout::getById($layoutId.'.brick.'.$item->getKey());
                        if ($layout instanceof DataObject\ClassDefinition\CustomLayout) {
                            $itemLayoutDefinitions = $layout->getLayoutDefinitions();
                        }
                    }

                    if ($itemLayoutDefinitions === null) {
                        $itemLayoutDefinitions = $item->getLayoutDefinitions();
                    }

                    DataObject\Service::enrichLayoutDefinition($itemLayoutDefinitions, $object, $context);

                    $layoutDefinitions[$item->getKey()] = $itemLayoutDefinitions;
                }
                $groups[$item->getGroup()]['children'][] =
                    [
                        'id' => $item->getKey(),
                        'text' => $item->getKey(),
                        'title' => $item->getTitle(),
                        'key' => $item->getKey(),
                        'leaf' => true,
                        'iconCls' => 'pimcore_icon_objectbricks',
                    ];
            } else {
                if ($forObjectEditor) {
                    $layout = $item->getLayoutDefinitions();

                    $currentLayoutId = $request->get('layoutId', null);

                    $user = $this->getAdminUser();
                    if ($currentLayoutId == -1 && $user->isAdmin()) {
                        DataObject\Service::createSuperLayout($layout);
                    } elseif ($currentLayoutId) {
                        $customLayout = DataObject\ClassDefinition\CustomLayout::getById($currentLayoutId.'.brick.'.$item->getKey());
                        if ($customLayout instanceof DataObject\ClassDefinition\CustomLayout) {
                            $layout = $customLayout->getLayoutDefinitions();
                        }
                    }

                    DataObject\Service::enrichLayoutDefinition($layout, $object, $context);

                    $layoutDefinitions[$item->getKey()] = $layout;
                }
                $definitions[] = [
                    'id' => $item->getKey(),
                    'text' => $item->getKey(),
                    'title' => $item->getTitle(),
                    'key' => $item->getKey(),
                    'leaf' => true,
                    'iconCls' => 'pimcore_icon_objectbricks',
                ];
            }
        }

        foreach ($groups as $group) {
            $definitions[] = $group;
        }

        $event = new GenericEvent($this, [
            'list' => $definitions,
            'objectId' => $request->get('object_id'),
            'forObjectEditor' => $forObjectEditor,
            'layoutDefinitions' => $layoutDefinitions,
            'fieldName' => $request->get('field_name'),
            'object' => $object,
        ]);
        $eventDispatcher->dispatch($event, AdminEvents::CLASS_OBJECTBRICK_LIST_PRE_SEND_DATA);
        $definitions = $event->getArgument('list');
        $layoutDefinitions = $event->getArgument('layoutDefinitions');

        if ($forObjectEditor) {
            return $this->adminJson(['objectbricks' => $definitions, 'layoutDefinitions' => $layoutDefinitions]);
        } else {
            return $this->adminJson($definitions);
        }
    }

    /**
     * @Route("/objectbrick-list", name="objectbricklist", methods={"GET"})
     *
     * @param Request $request
     * @param EventDispatcherInterface $eventDispatcher
     *
     * @return JsonResponse
     */
    public function objectbrickListAction(Request $request, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        $list = new DataObject\Objectbrick\Definition\Listing();
        $list = $list->load();

        if ($request->query->has('class_id') && $request->query->has('field_name')) {
            $filteredList = [];
            $classId = $request->get('class_id');
            $fieldname = $request->get('field_name');
            $classDefinition = DataObject\ClassDefinition::getById($classId);
            $className = $classDefinition->getName();

            foreach ($list as $type) {
                $clsDefs = $type->getClassDefinitions();
                if (!empty($clsDefs)) {
                    foreach ($clsDefs as $cd) {
                        if ($cd['classname'] == $className && $cd['fieldname'] == $fieldname) {
                            $filteredList[] = $type;

                            continue;
                        }
                    }
                }

                $layout = $type->getLayoutDefinitions();

                $currentLayoutId = $request->get('layoutId', null);

                $user = $this->getAdminUser();
                if ($currentLayoutId == -1 && $user->isAdmin()) {
                    DataObject\Service::createSuperLayout($layout);
                    $objectData['layout'] = $layout;
                }

                $context = [
                    'containerType' => 'objectbrick',
                    'containerKey' => $type->getKey(),
                    'outerFieldname' => $request->get('field_name'),
                ];

                $object = DataObject\Concrete::getById((int) $request->get('object_id'));

                DataObject\Service::enrichLayoutDefinition($layout, $object, $context);
                $type->setLayoutDefinitions($layout);
            }

            $list = $filteredList;
        }

        $event = new GenericEvent($this, [
            'list' => $list,
            'objectId' => $request->get('object_id'),
        ]);
        $eventDispatcher->dispatch($event, AdminEvents::CLASS_OBJECTBRICK_LIST_PRE_SEND_DATA);
        $list = $event->getArgument('list');

        return $this->adminJson(['objectbricks' => $list]);
    }

    /**
     * See http://www.pimcore.org/issues/browse/PIMCORE-2358
     * Add option to export/import all class definitions/brick definitions etc. at once
     */

    /**
     * @Route("/bulk-import", name="bulkimport", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function bulkImportAction(Request $request): JsonResponse
    {
        $result = [];

        $tmpName = $_FILES['Filedata']['tmp_name'];
        $json = file_get_contents($tmpName);

        $tmpName = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/bulk-import-' . uniqid() . '.tmp';
        file_put_contents($tmpName, $json);

        Session::useBag($request->getSession(), function (AttributeBagInterface $session) use ($tmpName) {
            $session->set('class_bulk_import_file', $tmpName);
        }, 'pimcore_objects');

        $json = json_decode($json, true);

        foreach ($json as $groupName => $group) {
            foreach ($group as $groupItem) {
                $displayName = null;
                $icon = null;

                if ($groupName == 'class') {
                    $name = $groupItem['name'];
                    $icon = 'class';
                } elseif ($groupName == 'customlayout') {
                    $className = $groupItem['className'];

                    $layoutData = ['className' => $className, 'name' => $groupItem['name']];
                    $name = base64_encode(json_encode($layoutData));
                    $displayName = $className . ' / ' . $groupItem['name'];
                    $icon = 'custom_views';
                } else {
                    if ($groupName == 'objectbrick') {
                        $icon = 'objectbricks';
                    } elseif ($groupName == 'fieldcollection') {
                        $icon = 'fieldcollection';
                    }
                    $name = $groupItem['key'];
                }

                if (!$displayName) {
                    $displayName = $name;
                }
                $result[] = ['icon' => $icon, 'checked' => true, 'type' => $groupName, 'name' => $name, 'displayName' => $displayName];
            }
        }

        $response = $this->adminJson(['success' => true, 'data' => $result]);
        $response->headers->set('Content-Type', 'text/html');

        return $response;
    }

    /**
     * See http://www.pimcore.org/issues/browse/PIMCORE-2358
     * Add option to export/import all class definitions/brick definitions etc. at once
     */

    /**
     * @Route("/bulk-commit", name="bulkcommit", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function bulkCommitAction(Request $request): JsonResponse
    {
        $data = json_decode($request->get('data'), true);

        $session = Session::getSessionBag($request->getSession(), 'pimcore_objects');
        $filename = $session->get('class_bulk_import_file');
        $json = @file_get_contents($filename);
        $json = json_decode($json, true);

        $type = $data['type'];
        $name = $data['name'];
        $list = $json[$type];

        foreach ($list as $item) {
            unset($item['creationDate']);
            unset($item['modificationDate']);
            unset($item['userOwner']);
            unset($item['userModification']);

            if ($type === 'class' && $item['name'] == $name) {
                $this->checkPermission('classes');
                $class = DataObject\ClassDefinition::getByName($name);
                if (!$class) {
                    $class = new DataObject\ClassDefinition();
                    $class->setName($name);
                }
                $success = DataObject\ClassDefinition\Service::importClassDefinitionFromJson($class, json_encode($item), true);

                return $this->adminJson(['success' => $success !== false]);
            } elseif ($type === 'objectbrick' && $item['key'] == $name) {
                $this->checkPermission('objectbricks');
                if (!$brick = DataObject\Objectbrick\Definition::getByKey($name)) {
                    $brick = new DataObject\Objectbrick\Definition();
                    $brick->setKey($name);
                }

                $success = DataObject\ClassDefinition\Service::importObjectBrickFromJson($brick, json_encode($item), true);

                return $this->adminJson(['success' => $success !== false]);
            } elseif ($type === 'fieldcollection' && $item['key'] == $name) {
                $this->checkPermission('fieldcollections');
                if (!$fieldCollection = DataObject\Fieldcollection\Definition::getByKey($name)) {
                    $fieldCollection = new DataObject\Fieldcollection\Definition();
                    $fieldCollection->setKey($name);
                }

                $success = DataObject\ClassDefinition\Service::importFieldCollectionFromJson($fieldCollection, json_encode($item), true);

                return $this->adminJson(['success' => $success !== false]);
            } elseif ($type === 'customlayout') {
                $this->checkPermission('classes');
                $layoutData = json_decode(base64_decode($data['name']), true);
                $className = $layoutData['className'];
                $layoutName = $layoutData['name'];

                if ($item['name'] == $layoutName && $item['className'] == $className) {
                    $class = DataObject\ClassDefinition::getByName($className);
                    if (!$class) {
                        throw new \Exception('Class does not exist');
                    }

                    $classId = $class->getId();

                    $layoutList = new DataObject\ClassDefinition\CustomLayout\Listing();
                    $layoutList->setFilter(function (DataObject\ClassDefinition\CustomLayout $layout) use ($layoutName, $classId) {
                        return $layout->getName() === $layoutName && $layout->getClassId() === $classId;
                    });
                    $layoutList = $layoutList->load();

                    $layoutDefinition = null;
                    if ($layoutList) {
                        $layoutDefinition = $layoutList[0];
                    }

                    if (!$layoutDefinition) {
                        $layoutDefinition = new DataObject\ClassDefinition\CustomLayout();
                        $layoutDefinition->setName($layoutName);
                        $layoutDefinition->setClassId($classId);
                    }

                    try {
                        $layoutDefinition->setDescription($item['description']);
                        $layoutDef = DataObject\ClassDefinition\Service::generateLayoutTreeFromArray($item['layoutDefinitions'], true);
                        $layoutDefinition->setLayoutDefinitions($layoutDef);
                        $layoutDefinition->save();
                    } catch (\Exception $e) {
                        Logger::error($e->getMessage());

                        return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
                    }
                }
            }
        }

        return $this->adminJson(['success' => true]);
    }

    /**
     * See http://www.pimcore.org/issues/browse/PIMCORE-2358
     * Add option to export/import all class definitions/brick definitions etc. at once
     */

    /**
     * @Route("/bulk-export-prepare", name="bulkexportprepare", methods={"POST"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function bulkExportPrepareAction(Request $request): Response
    {
        $data = $request->get('data');

        Session::useBag($request->getSession(), function (AttributeBagInterface $session) use ($data) {
            $session->set('class_bulk_export_settings', $data);
        }, 'pimcore_objects');

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/bulk-export", name="bulkexport", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function bulkExportAction(Request $request): JsonResponse
    {
        $result = [];

        if ($this->getAdminUser()->isAllowed('fieldcollections')) {
            $fieldCollections = new DataObject\Fieldcollection\Definition\Listing();
            $fieldCollections = $fieldCollections->load();

            foreach ($fieldCollections as $fieldCollection) {
                $result[] = [
                    'icon' => 'fieldcollection',
                    'checked' => true,
                    'type' => 'fieldcollection',
                    'name' => $fieldCollection->getKey(),
                    'displayName' => $fieldCollection->getKey(),
                ];
            }
        }

        if ($this->getAdminUser()->isAllowed('classes')) {
            $classes = new DataObject\ClassDefinition\Listing();
            $classes->setOrder('ASC');
            $classes->setOrderKey('id');
            $classes = $classes->load();

            foreach ($classes as $class) {
                $result[] = [
                    'icon' => 'class',
                    'checked' => true,
                    'type' => 'class',
                    'name' => $class->getName(),
                    'displayName' => $class->getName(),
                ];
            }
        }

        if ($this->getAdminUser()->isAllowed('objectbricks')) {
            $objectBricks = new DataObject\Objectbrick\Definition\Listing();
            $objectBricks = $objectBricks->load();

            foreach ($objectBricks as $objectBrick) {
                $result[] = [
                    'icon' => 'objectbricks',
                    'checked' => true,
                    'type' => 'objectbrick',
                    'name' => $objectBrick->getKey(),
                    'displayName' => $objectBrick->getKey(),
                ];
            }
        }

        if ($this->getAdminUser()->isAllowed('classes')) {
            $customLayouts = new DataObject\ClassDefinition\CustomLayout\Listing();
            $customLayouts = $customLayouts->load();
            foreach ($customLayouts as $customLayout) {
                $class = DataObject\ClassDefinition::getById($customLayout->getClassId());
                $displayName = $class->getName().' / '.$customLayout->getName();

                $result[] = [
                    'icon' => 'custom_views',
                    'checked' => true,
                    'type' => 'customlayout',
                    'name' => $customLayout->getId(),
                    'displayName' => $displayName,
                ];
            }
        }

        return new JsonResponse(['success' => true, 'data' => $result]);
    }

    /**
     * @Route("/do-bulk-export", name="dobulkexport", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function doBulkExportAction(Request $request): Response
    {
        $session = Session::getSessionBag($request->getSession(), 'pimcore_objects');
        $list = $session->get('class_bulk_export_settings');
        $list = json_decode($list, true);
        $result = [];

        foreach ($list as $item) {
            if ($item['type'] === 'fieldcollection' && $this->getAdminUser()->isAllowed('fieldcollections')) {
                if ($fieldCollection = DataObject\Fieldcollection\Definition::getByKey($item['name'])) {
                    $fieldCollectionJson = json_decode(DataObject\ClassDefinition\Service::generateFieldCollectionJson($fieldCollection));
                    $fieldCollectionJson->key = $item['name'];
                    $result['fieldcollection'][] = $fieldCollectionJson;
                }
            } elseif ($item['type'] === 'class' && $this->getAdminUser()->isAllowed('classes')) {
                if ($class = DataObject\ClassDefinition::getByName($item['name'])) {
                    $data = json_decode(DataObject\ClassDefinition\Service::generateClassDefinitionJson($class));
                    $data->name = $item['name'];
                    $result['class'][] = $data;
                }
            } elseif ($item['type'] === 'objectbrick' && $this->getAdminUser()->isAllowed('objectbricks')) {
                if ($objectBrick = DataObject\Objectbrick\Definition::getByKey($item['name'])) {
                    $objectBrickJson = json_decode(DataObject\ClassDefinition\Service::generateObjectBrickJson($objectBrick));
                    $objectBrickJson->key = $item['name'];
                    $result['objectbrick'][] = $objectBrickJson;
                }
            } elseif ($item['type'] === 'customlayout' && $this->getAdminUser()->isAllowed('classes')) {
                if ($customLayout = DataObject\ClassDefinition\CustomLayout::getById($item['name'])) {
                    $classId = $customLayout->getClassId();
                    $class = DataObject\ClassDefinition::getById($classId);
                    $customLayoutJson = json_decode(DataObject\ClassDefinition\Service::generateCustomLayoutJson($customLayout));
                    $customLayoutJson->name = $customLayout->getName();
                    $customLayoutJson->className = $class->getName();
                    $result['customlayout'][] = $customLayoutJson;
                }
            }
        }

        $result = json_encode($result, JSON_PRETTY_PRINT);
        $response = new Response($result);
        $response->headers->set('Content-type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="bulk_export.json"');

        return $response;
    }

    public function onKernelControllerEvent(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // check permissions
        $unrestrictedActions = [
            'getTreeAction', 'fieldcollectionListAction', 'fieldcollectionTreeAction', 'fieldcollectionGetAction',
            'getClassDefinitionForColumnConfigAction', 'objectbrickListAction', 'objectbrickTreeAction', 'objectbrickGetAction',
            'objectbrickDeleteAction', 'objectbrickUpdateAction', 'importObjectbrickAction', 'exportObjectbrickAction', 'bulkCommitAction', 'doBulkExportAction', 'bulkExportAction', 'importFieldcollectionAction', 'exportFieldcollectionAction', // permissions for listed write operations handled separately in action methods
        ];

        $this->checkActionPermission($event, 'classes', $unrestrictedActions);
    }

    /**
     * @Route("/get-fieldcollection-usages", name="getfieldcollectionusages", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function getFieldcollectionUsagesAction(Request $request): Response
    {
        $key = $request->get('key');
        $result = [];

        $classes = new DataObject\ClassDefinition\Listing();
        $classes = $classes->load();
        foreach ($classes as $class) {
            $fieldDefs = $class->getFieldDefinitions();
            foreach ($fieldDefs as $fieldDef) {
                if ($fieldDef instanceof DataObject\ClassDefinition\Data\Fieldcollections) {
                    $allowedKeys = $fieldDef->getAllowedTypes();
                    if (is_array($allowedKeys) && in_array($key, $allowedKeys)) {
                        $result[] = [
                            'class' => $class->getName(),
                            'field' => $fieldDef->getName(),
                        ];
                    }
                }
            }
        }

        return $this->adminJson($result);
    }

    /**
     * @Route("/get-bricks-usages", name="getbrickusages", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function getBrickUsagesAction(Request $request): Response
    {
        $classId = $request->get('classId');
        $myclass = DataObject\ClassDefinition::getById($classId);

        $result = [];

        $brickDefinitions = new DataObject\Objectbrick\Definition\Listing();
        $brickDefinitions = $brickDefinitions->load();
        foreach ($brickDefinitions as $brickDefinition) {
            $classes = $brickDefinition->getClassDefinitions();
            foreach ($classes as $class) {
                if ($myclass->getName() == $class['classname']) {
                    $result[] = [
                        'objectbrick' => $brickDefinition->getKey(),
                        'field' => $class['fieldname'],
                    ];
                }
            }
        }

        return $this->adminJson($result);
    }

    /**
     * @Route("/get-icons", name="geticons", methods={"GET"})
     *
     * @param Request $request
     * @param EventDispatcherInterface $eventDispatcher
     *
     * @return Response
     */
    public function getIconsAction(Request $request, EventDispatcherInterface $eventDispatcher): Response
    {
        $classId = $request->get('classId');

        $iconDir = PIMCORE_WEB_ROOT . '/bundles/pimcoreadmin/img';
        $classIcons = rscandir($iconDir . '/object-icons/');
        $colorIcons = rscandir($iconDir . '/flat-color-icons/');
        $twemoji = rscandir($iconDir . '/twemoji/');

        $icons = array_merge($classIcons, $colorIcons, $twemoji);

        foreach ($icons as &$icon) {
            $icon = str_replace(PIMCORE_WEB_ROOT, '', $icon);
        }

        $event = new GenericEvent($this, [
            'icons' => $icons,
            'classId' => $classId,
        ]);
        $eventDispatcher->dispatch($event, AdminEvents::CLASS_OBJECT_ICONS_PRE_SEND_DATA);
        $icons = $event->getArgument('icons');

        $result = [];
        foreach ($icons as $icon) {
            $content = file_get_contents(PIMCORE_WEB_ROOT . $icon);
            $result[] = [
                'text' => sprintf('<img src="data:%s;base64,%s"/>', mime_content_type(PIMCORE_WEB_ROOT . $icon), base64_encode($content)),
                'value' => $icon,
            ];
        }

        return $this->adminJson($result);
    }

    /**
     * @Route("/suggest-class-identifier", name="suggestclassidentifier")
     *
     * @return Response
     */
    public function suggestClassIdentifierAction(): Response
    {
        $db = Db::get();
        $maxId = $db->fetchOne('SELECT MAX(CAST(id AS SIGNED)) FROM classes;');

        $existingIds = $db->fetchFirstColumn('select LOWER(id) from classes');

        $result = [
            'suggestedIdentifier' => $maxId ? $maxId + 1 : 1,
            'existingIds' => $existingIds,
            ];

        return $this->adminJson($result);
    }

    /**
     * @Route("/suggest-custom-layout-identifier", name="suggestcustomlayoutidentifier")
     *
     * @param Request $request
     *
     * @return Response
     */
    public function suggestCustomLayoutIdentifierAction(Request $request): Response
    {
        $classId = $request->get('classId');

        $identifier = DataObject\ClassDefinition\CustomLayout::getIdentifier($classId);

        $list = new DataObject\ClassDefinition\CustomLayout\Listing();

        $list = $list->load();
        $existingIds = [];
        $existingNames = [];

        foreach ($list as $item) {
            $existingIds[] = $item->getId();
            if ($item->getClassId() == $classId) {
                $existingNames[] = $item->getName();
            }
        }

        $result = [
            'suggestedIdentifier' => $identifier,
            'existingIds' => $existingIds,
            'existingNames' => $existingNames,
            ];

        return $this->adminJson($result);
    }

    /**
     * @Route("/text-layout-preview", name="textlayoutpreview")
     *
     * @param Request $request
     *
     * @return Response
     */
    public function textLayoutPreviewAction(Request $request): Response
    {
        $objPath = $request->get('previewObject', '');
        $className = '\\Pimcore\\Model\\DataObject\\' . $request->get('className');
        $obj = DataObject::getByPath($objPath) ?? new $className();

        $textLayout = new DataObject\ClassDefinition\Layout\Text();

        $context = [
          'data' => $request->get('renderingData'),
        ];

        if ($renderingClass = $request->get('renderingClass')) {
            $textLayout->setRenderingClass($renderingClass);
        }

        if ($staticHtml = $request->get('html')) {
            $textLayout->setHtml($staticHtml);
        }

        $html = $textLayout->enrichLayoutDefinition($obj, $context)->getHtml();

        $content =
            "<html>\n" .
            "<head>\n" .
            '<style type="text/css">' . "\n" .
            file_get_contents(PIMCORE_WEB_ROOT . '/bundles/pimcoreadmin/css/admin.css') .
            "</style>\n" .
            "</head>\n\n" .
            "<body class='objectlayout_element_text'>\n" .
            $html .
            "\n\n</body>\n" .
            "</html>\n";

        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/html');

        return $response;
    }

    /**
     * @Route("/video-supported-types", name="videosupportedTypestypes")
     *
     * @param Request $request
     * @param TranslatorInterface $translator
     *
     * @return Response
     */
    public function videoAllowedTypesAction(Request $request, TranslatorInterface $translator): Response
    {
        $videoDef = new DataObject\ClassDefinition\Data\Video();
        $res = [];

        foreach ($videoDef->getSupportedTypes() as $type) {
            $res[] = [
                'key' => $type,
                'value' => $translator->trans($type, [], 'admin'),
            ];
        }

        return $this->adminJson($res);
    }
}
