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

use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToReadFile;
use Pimcore\Bundle\AdminBundle\Controller\AdminAbstractController;
use Pimcore\Bundle\AdminBundle\Event\AdminEvents;
use Pimcore\Bundle\AdminBundle\Helper\GridHelperService;
use Pimcore\Bundle\AdminBundle\Model\GridConfig;
use Pimcore\Bundle\AdminBundle\Model\GridConfigFavourite;
use Pimcore\Bundle\AdminBundle\Model\GridConfigShare;
use Pimcore\Config;
use Pimcore\Db;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Logger;
use Pimcore\Model\DataObject;
use Pimcore\Model\User;
use Pimcore\Security\SecurityHelper;
use Pimcore\Tool;
use Pimcore\Tool\Storage;
use Pimcore\Version;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @Route("/object-helper", name="pimcore_admin_dataobject_dataobjecthelper_")
 *
 * @internal
 */
class DataObjectHelperController extends AdminAbstractController
{
    const SYSTEM_COLUMNS = ['id', 'fullpath', 'key', 'published', 'creationDate', 'modificationDate', 'filename', 'classname'];

    /**
     * @Route("/load-object-data", name="loadobjectdata", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function loadObjectDataAction(Request $request): JsonResponse
    {
        $object = DataObject::getById((int) $request->get('id'));
        $result = [];
        if ($object) {
            $result['success'] = true;
            $fields = $request->get('fields');
            $result['fields'] = DataObject\Service::gridObjectData($object, $fields);
        } else {
            $result['success'] = false;
        }

        return $this->adminJson($result);
    }

    /**
     * @param int $userId
     * @param string $classId
     * @param string|null $searchType
     *
     * @return array
     */
    public function getMyOwnGridColumnConfigs(int $userId, string $classId, string $searchType = null): array
    {
        $db = Db::get();
        $configListingConditionParts = [];
        $configListingConditionParts[] = 'ownerId = ' . $userId;
        $configListingConditionParts[] = 'classId = ' . $db->quote($classId);

        if ($searchType) {
            $configListingConditionParts[] = 'searchType = ' . $db->quote($searchType);
        }

        $configCondition = implode(' AND ', $configListingConditionParts);
        $configListing = new GridConfig\Listing();
        $configListing->setOrderKey('name');
        $configListing->setOrder('ASC');
        $configListing->setCondition($configCondition);
        $configListing = $configListing->load();

        $configData = [];
        if (is_array($configListing)) {
            foreach ($configListing as $config) {
                $configData[] = $config->getObjectVars();
            }
        }

        return $configData;
    }

    /**
     * @param User $user
     * @param string $classId
     * @param string|null $searchType
     *
     * @return array
     */
    public function getSharedGridColumnConfigs(User $user, string $classId, string $searchType = null): array
    {
        $configListing = [];

        $userIds = [$user->getId()];
        // collect all roles
        $userIds = array_merge($userIds, $user->getRoles());
        $userIds = implode(',', $userIds);
        $db = Db::get();

        $query = 'select distinct c1.id from gridconfigs c1, gridconfig_shares s
                    where (c1.searchType = ' . $db->quote($searchType) . ' and ((c1.id = s.gridConfigId and s.sharedWithUserId IN (' . $userIds . '))) and c1.classId = ' . $db->quote($classId) . ')
                            UNION distinct select c2.id from gridconfigs c2 where shareGlobally = 1 and c2.classId = '. $db->quote($classId) . '  and c2.ownerId != ' . $db->quote($user->getId());

        $ids = $db->fetchFirstColumn($query);

        if ($ids) {
            $ids = implode(',', $ids);
            $configListing = new GridConfig\Listing();
            $configListing->setOrderKey('name');
            $configListing->setOrder('ASC');
            $configListing->setCondition('id in (' . $ids . ')');
            $configListing = $configListing->load();
        }

        $configData = [];
        if (is_array($configListing)) {
            foreach ($configListing as $config) {
                $configData[] = $config->getObjectVars();
            }
        }

        return $configData;
    }

    /**
     * @Route("/get-export-configs", name="getexportconfigs", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getExportConfigsAction(Request $request): JsonResponse
    {
        $classId = $request->get('classId');
        $list = $this->getMyOwnGridColumnConfigs($this->getAdminUser()->getId(), $classId);
        if (!is_array($list)) {
            $list = [];
        }
        $list = array_merge($list, $this->getSharedGridColumnConfigs($this->getAdminUser(), $classId));
        $result = [];

        $result[] = [
            'id' => -1,
            'name' => '--default--',
        ];

        if ($list) {
            /** @var GridConfig $config */
            foreach ($list as $config) {
                $result[] = [
                    'id' => $config['id'],
                    'name' => $config['name'],
                ];
            }
        }

        return $this->adminJson(['success' => true, 'data' => $result]);
    }

    /**
     * @Route("/grid-delete-column-config", name="griddeletecolumnconfig", methods={"DELETE"})
     *
     * @param Request $request
     * @param EventDispatcherInterface $eventDispatcher
     * @param Config $config
     *
     * @return JsonResponse
     */
    public function gridDeleteColumnConfigAction(Request $request, EventDispatcherInterface $eventDispatcher, Config $config): JsonResponse
    {
        $gridConfigId = (int)$request->get('gridConfigId');
        $gridConfig = GridConfig::getById($gridConfigId);
        $success = false;
        if ($gridConfig) {
            if ($gridConfig->getOwnerId() != $this->getAdminUser()->getId() && !$this->getAdminUser()->isAdmin()) {
                throw new \Exception("don't mess with someone elses grid config");
            }

            $gridConfig->delete();
            $success = true;
        }

        $newGridConfig = $this->doGetGridColumnConfig($request, $config, true);
        $newGridConfig['deleteSuccess'] = $success;

        $event = new GenericEvent($this, [
            'data' => $newGridConfig,
            'request' => $request,
            'config' => $config,
            'context' => 'delete',
        ]);

        $eventDispatcher->dispatch($event, AdminEvents::OBJECT_GRID_GET_COLUMN_CONFIG_PRE_SEND_DATA);
        $newGridConfig = $event->getArgument('data');

        return $this->adminJson($newGridConfig);
    }

    /**
     * @Route("/grid-get-column-config", name="gridgetcolumnconfig", methods={"GET"})
     *
     * @param Request $request
     * @param EventDispatcherInterface $eventDispatcher
     * @param Config $config
     *
     * @return JsonResponse
     */
    public function gridGetColumnConfigAction(Request $request, EventDispatcherInterface $eventDispatcher, Config $config): JsonResponse
    {
        $result = $this->doGetGridColumnConfig($request, $config);

        $event = new GenericEvent($this, [
            'data' => $result,
            'request' => $request,
            'config' => $config,
            'context' => 'get',
        ]);

        $eventDispatcher->dispatch($event, AdminEvents::OBJECT_GRID_GET_COLUMN_CONFIG_PRE_SEND_DATA);
        $result = $event->getArgument('data');

        return $this->adminJson($result);
    }

    public function doGetGridColumnConfig(Request $request, Config $config, bool $isDelete = false): array
    {
        $class = null;
        $fields = null;

        if ($request->get('id')) {
            $class = DataObject\ClassDefinition::getById($request->get('id'));
        } elseif ($request->get('name')) {
            $class = DataObject\ClassDefinition::getByName($request->get('name'));
        }

        $gridConfigId = null;
        $gridType = 'search';
        if ($request->get('gridtype')) {
            $gridType = $request->get('gridtype');
        }

        $objectId = (int) $request->get('objectId');

        if ($objectId) {
            $fields = DataObject\Service::getCustomGridFieldDefinitions($class->getId(), $objectId);
        }

        $context = ['purpose' => 'gridconfig'];
        if ($class) {
            $context['class'] = $class;
        }

        if ($objectId) {
            $object = DataObject::getById($objectId);
            $context['object'] = $object;
        }

        if (!$fields && $class) {
            $fields = $class->getFieldDefinitions();
        }

        $types = [];
        if ($request->get('types')) {
            $types = explode(',', $request->get('types'));
        }

        $userId = $this->getAdminUser()->getId();

        $requestedGridConfigId = $isDelete ? null : $request->get('gridConfigId');

        // grid config
        $gridConfig = [];
        $searchType = $request->get('searchType');

        if (strlen($requestedGridConfigId ?? '') == 0 && $class) {
            // check if there is a favourite view
            $favourite = GridConfigFavourite::getByOwnerAndClassAndObjectId($userId, $class->getId(), $objectId ?: 0, $searchType);
            if (!$favourite && $objectId) {
                $favourite = GridConfigFavourite::getByOwnerAndClassAndObjectId($userId, $class->getId(), 0, $searchType);
            }

            if ($favourite) {
                $requestedGridConfigId = $favourite->getGridConfigId();
            }
        }

        if (is_numeric($requestedGridConfigId) && $requestedGridConfigId > 0) {
            $db = Db::get();
            $savedGridConfig = GridConfig::getById((int) $requestedGridConfigId);

            if ($savedGridConfig) {
                $shared = false;
                if (!$this->getAdminUser()->isAdmin()) {
                    $userIds = [$this->getAdminUser()->getId()];
                    $userIds = array_merge($userIds, $this->getAdminUser()->getRoles());
                    $userIds = implode(',', $userIds);
                    $shared = ($savedGridConfig->getOwnerId() != $userId && $savedGridConfig->isShareGlobally()) || $db->fetchOne('select 1 from gridconfig_shares where sharedWithUserId IN ('.$userIds.') and gridConfigId = '.$savedGridConfig->getId());
                    //                  $shared = $savedGridConfig->isShareGlobally() || GridConfigShare::getByGridConfigAndSharedWithId($savedGridConfig->getId(), $this->getUser()->getId());

                    if (!$shared && $savedGridConfig->getOwnerId() != $this->getAdminUser()->getId()) {
                        throw new \Exception('You are neither the owner of this config nor it is shared with you');
                    }
                }

                $gridConfigId = $savedGridConfig->getId();
                $gridConfig = $savedGridConfig->getConfig();
                $gridConfig = json_decode($gridConfig, true);
                $gridConfigName = SecurityHelper::convertHtmlSpecialChars($savedGridConfig->getName());
                $owner = $savedGridConfig->getOwnerId();
                $ownerObject = User::getById($owner);
                if ($ownerObject instanceof User) {
                    $owner = $ownerObject->getName();
                }
                $modificationDate = $savedGridConfig->getModificationDate();
                $gridConfigDescription = SecurityHelper::convertHtmlSpecialChars($savedGridConfig->getDescription());
                $sharedGlobally = $savedGridConfig->isShareGlobally();
                $setAsFavourite = $savedGridConfig->isSetAsFavourite();
                $saveFilters = $savedGridConfig->isSaveFilters();

                foreach($gridConfig['columns'] as &$column) {
                    if (array_key_exists('isOperator', $column) && $column['isOperator']) {
                        $colAttributes = &$column['fieldConfig']['attributes'];
                        SecurityHelper::convertHtmlSpecialCharsArrayKeys($colAttributes, ['label', 'attribute', 'param1']);
                    }
                }
            }
        }

        $localizedFields = [];
        $objectbrickFields = [];
        if (is_array($fields)) {
            foreach ($fields as $key => $field) {
                if ($field instanceof DataObject\ClassDefinition\Data\Localizedfields) {
                    $localizedFields[] = $field;
                } elseif ($field instanceof DataObject\ClassDefinition\Data\Objectbricks) {
                    $objectbrickFields[] = $field;
                }
            }
        }

        $availableFields = [];

        if (empty($gridConfig)) {
            $availableFields = $this->getDefaultGridFields(
                $request->query->getBoolean('no_system_columns'),
                $class,
                $gridType,
                $request->query->getBoolean('no_brick_columns'),
                $fields,
                $context,
                $objectId,
                $types
            );
        } else {
            $savedColumns = $gridConfig['columns'];
            foreach ($savedColumns as $key => $sc) {
                if (!$sc['hidden']) {
                    if (in_array($key, self::SYSTEM_COLUMNS)) {
                        $colConfig = [
                            'key' => $key,
                            'type' => 'system',
                            'label' => $key,
                            'locked' => $sc['locked'] ?? null,
                            'position' => $sc['position'],
                        ];
                        if (isset($sc['width'])) {
                            $colConfig['width'] = $sc['width'];
                        }
                        $availableFields[] = $colConfig;
                    } else {
                        $keyParts = explode('~', $key);

                        if (substr($key, 0, 1) == '~') {
                            // not needed for now
                            $type = $keyParts[1];
                            //                            $field = $keyParts[2];
                            $groupAndKeyId = explode('-', $keyParts[3]);
                            $keyId = (int) $groupAndKeyId[1];

                            if ($type == 'classificationstore') {
                                $keyDef = DataObject\Classificationstore\KeyConfig::getById($keyId);
                                if ($keyDef) {
                                    $keyFieldDef = json_decode($keyDef->getDefinition(), true);
                                    if ($keyFieldDef) {
                                        $keyFieldDef = \Pimcore\Model\DataObject\Classificationstore\Service::getFieldDefinitionFromJson($keyFieldDef, $keyDef->getType());
                                        $fieldConfig = $this->getFieldGridConfig($keyFieldDef, $gridType, (string)$sc['position'], true, null, $class, $objectId);
                                        if ($fieldConfig) {
                                            $fieldConfig['key'] = $key;
                                            $fieldConfig['label'] = '#' . $keyFieldDef->getTitle();
                                            if (isset($sc['locked'])) {
                                                $fieldConfig['locked'] = $sc['locked'];
                                            }
                                            $availableFields[] = $fieldConfig;
                                        }
                                    }
                                }
                            }
                        } elseif (count($keyParts) > 1) {
                            $brick = $keyParts[0];
                            $brickDescriptor = null;

                            if (strpos($brick, '?') !== false) {
                                $brickDescriptor = substr($brick, 1);
                                $brickDescriptor = json_decode($brickDescriptor, true);
                                $keyPrefix = $brick . '~';
                                $brick = $brickDescriptor['containerKey'];
                            } else {
                                $keyPrefix = $brick . '~';
                            }

                            $fieldname = $keyParts[1];

                            $brickClass = DataObject\Objectbrick\Definition::getByKey($brick);

                            $fd = null;
                            if ($brickClass instanceof DataObject\Objectbrick\Definition) {
                                if ($brickDescriptor) {
                                    $innerContainer = $brickDescriptor['innerContainer'] ?? 'localizedfields';
                                    /** @var DataObject\ClassDefinition\Data\Localizedfields $localizedFields */
                                    $localizedFields = $brickClass->getFieldDefinition($innerContainer);
                                    $fd = $localizedFields->getFieldDefinition($brickDescriptor['brickfield']);
                                } else {
                                    $fd = $brickClass->getFieldDefinition($fieldname);
                                }
                            }

                            if ($fd !== null) {
                                $fieldConfig = $this->getFieldGridConfig($fd, $gridType, (string)$sc['position'], true, $keyPrefix, $class, $objectId);
                                if (!empty($fieldConfig)) {
                                    if (isset($sc['width'])) {
                                        $fieldConfig['width'] = $sc['width'];
                                    }
                                    if (isset($sc['locked'])) {
                                        $fieldConfig['locked'] = $sc['locked'];
                                    }
                                    $availableFields[] = $fieldConfig;
                                }
                            }
                        } else {
                            if (DataObject\Service::isHelperGridColumnConfig($key)) {
                                $calculatedColumnConfig = $this->getCalculatedColumnConfig($request, $savedColumns[$key]);
                                if ($calculatedColumnConfig) {
                                    $availableFields[] = $calculatedColumnConfig;
                                }
                            } else {
                                $fd = $class->getFieldDefinition($key);
                                //if not found, look for localized fields
                                if (empty($fd)) {
                                    foreach ($localizedFields as $lf) {
                                        $fd = $lf->getFieldDefinition($key);
                                        if (!empty($fd)) {
                                            break;
                                        }
                                    }
                                }

                                if (!empty($fd)) {
                                    $fieldConfig = $this->getFieldGridConfig($fd, $gridType, (string)$sc['position'], true, null, $class, $objectId);
                                    if (!empty($fieldConfig)) {
                                        if (isset($sc['width'])) {
                                            $fieldConfig['width'] = $sc['width'];
                                        }
                                        if (isset($sc['locked'])) {
                                            $fieldConfig['locked'] = $sc['locked'];
                                        }
                                        $availableFields[] = $fieldConfig;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        usort($availableFields, function ($a, $b) {
            if ($a['position'] == $b['position']) {
                return 0;
            }

            return ($a['position'] < $b['position']) ? -1 : 1;
        });

        $frontendLanguages = Tool\Admin::reorderWebsiteLanguages(\Pimcore\Tool\Admin::getCurrentUser(), $config['general']['valid_languages']);
        if ($frontendLanguages) {
            $language = $frontendLanguages[0];
        } else {
            $language = $request->getLocale();
        }

        if (!Tool::isValidLanguage($language)) {
            $validLanguages = Tool::getValidLanguages();
            $language = $validLanguages[0];
        }

        if (!empty($gridConfig) && !empty($gridConfig['language'])) {
            $language = $gridConfig['language'];
        }

        $availableConfigs = $class ? $this->getMyOwnGridColumnConfigs($userId, $class->getId(), $searchType) : [];
        $sharedConfigs = $class ? $this->getSharedGridColumnConfigs($this->getAdminUser(), $class->getId(), $searchType) : [];
        $settings = $this->getShareSettings((int)$gridConfigId);
        $settings['gridConfigId'] = (int)$gridConfigId;
        $settings['gridConfigName'] = $gridConfigName ?? null;
        $settings['gridConfigDescription'] = $gridConfigDescription ?? null;
        $settings['owner'] = $owner ?? null;
        $settings['modificationDate'] = $modificationDate ?? null;
        $settings['shareGlobally'] = $sharedGlobally ?? null;
        $settings['setAsFavourite'] = $setAsFavourite ?? null;
        $settings['saveFilters'] = $saveFilters ?? null;
        $settings['isShared'] = !$gridConfigId || ($shared ?? null);

        $context = $gridConfig['context'] ?? null;
        if ($context) {
            $context = json_decode($context, true);
        }

        return [
            'sortinfo' => $gridConfig['sortinfo'] ?? false,
            'language' => $language,
            'availableFields' => $availableFields,
            'settings' => $settings,
            'onlyDirectChildren' => $gridConfig['onlyDirectChildren'] ?? false,
            'pageSize' => $gridConfig['pageSize'] ?? false,
            'availableConfigs' => $availableConfigs,
            'sharedConfigs' => $sharedConfigs,
            'context' => $context,
            'searchFilter' => $gridConfig['searchFilter'] ?? '',
            'filter' => $gridConfig['filter'] ?? [],
        ];
    }

    /**
     * @param DataObject\ClassDefinition\Data[]|null $fields
     */
    public function getDefaultGridFields(bool $noSystemColumns, ?DataObject\ClassDefinition $class, string $gridType, bool $noBrickColumns, ?array $fields, array $context, int $objectId, array $types = []): array
    {
        $count = 0;
        $availableFields = [];

        if (!$noSystemColumns && $class) {
            $vis = $class->getPropertyVisibility();
            foreach (self::SYSTEM_COLUMNS as $sc) {
                $key = $sc;
                if ($key === 'fullpath') {
                    $key = 'path';
                }

                if (empty($types) && (!empty($vis[$gridType][$key]) || $gridType === 'all')) {
                    $availableFields[] = [
                        'key' => $sc,
                        'type' => 'system',
                        'label' => $sc,
                        'position' => $count, ];
                    $count++;
                }
            }
        }

        $includeBricks = !$noBrickColumns;

        if (is_array($fields)) {
            foreach ($fields as $key => $field) {
                if ($field instanceof DataObject\ClassDefinition\Data\Localizedfields) {
                    foreach ($field->getFieldDefinitions($context) as $fd) {
                        if (empty($types) || in_array($fd->getFieldType(), $types)) {
                            $fieldConfig = $this->getFieldGridConfig($fd, $gridType, (string)$count, false, null, $class, $objectId);
                            if (!empty($fieldConfig)) {
                                $availableFields[] = $fieldConfig;
                                $count++;
                            }
                        }
                    }
                } elseif ($field instanceof DataObject\ClassDefinition\Data\Objectbricks && $includeBricks) {
                    if (in_array($field->getFieldType(), $types)) {
                        $fieldConfig = $this->getFieldGridConfig($field, $gridType, (string)$count, false, null, $class, $objectId);
                        if (!empty($fieldConfig)) {
                            $availableFields[] = $fieldConfig;
                            $count++;
                        }
                    } else {
                        $allowedTypes = $field->getAllowedTypes();
                        if (!empty($allowedTypes)) {
                            foreach ($allowedTypes as $t) {
                                $brickClass = DataObject\Objectbrick\Definition::getByKey($t);
                                $brickFields = $brickClass->getFieldDefinitions($context);

                                $this->appendBrickFields($field, $brickFields, $availableFields, $gridType, $count, $t, $class, $objectId);
                            }
                        }
                    }
                } else {
                    if (empty($types) || in_array($field->getFieldType(), $types)) {
                        $fieldConfig = $this->getFieldGridConfig($field, $gridType, (string)$count, !empty($types), null, $class, $objectId);
                        if (!empty($fieldConfig)) {
                            $availableFields[] = $fieldConfig;
                            $count++;
                        }
                    }
                }
            }
        }

        return $availableFields;
    }

    /**
     * @param DataObject\ClassDefinition\Data $field
     * @param DataObject\ClassDefinition\Data[] $brickFields
     * @param array $availableFields
     * @param string $gridType
     * @param int $count
     * @param string $brickType
     * @param DataObject\ClassDefinition $class
     * @param int $objectId
     * @param array|null $context
     */
    protected function appendBrickFields(DataObject\ClassDefinition\Data $field, array $brickFields, array &$availableFields, string $gridType, int &$count, string $brickType, DataObject\ClassDefinition $class, int $objectId, array $context = null): void
    {
        if (!empty($brickFields)) {
            foreach ($brickFields as $bf) {
                if ($bf instanceof DataObject\ClassDefinition\Data\Localizedfields) {
                    $localizedFieldDefinitions = $bf->getFieldDefinitions();

                    $localizedContext = [
                        'containerKey' => $brickType,
                        'fieldname' => $field->getName(),
                    ];

                    $this->appendBrickFields($bf, $localizedFieldDefinitions, $availableFields, $gridType, $count, $brickType, $class, $objectId, $localizedContext);
                } else {
                    if ($context) {
                        $context['brickfield'] = $bf->getName();
                        $keyPrefix = '?' . json_encode($context) . '~';
                    } else {
                        $keyPrefix = $brickType . '~';
                    }
                    $fieldConfig = $this->getFieldGridConfig($bf, $gridType, (string)$count, false, $keyPrefix, $class, $objectId);
                    if (!empty($fieldConfig)) {
                        $availableFields[] = $fieldConfig;
                        $count++;
                    }
                }
            }
        }
    }

    protected function getCalculatedColumnConfig(Request $request, array $config): mixed
    {
        try {
            $calculatedColumnConfig = Tool\Session::useBag($request->getSession(), function (AttributeBagInterface $session) use ($config) {
                //otherwise create a new one

                $calculatedColumn = [];
                // note that we have to generate a new key!

                $existingKey = $config['fieldConfig']['key'];
                $calculatedColumnConfig['key'] = $existingKey;
                $calculatedColumnConfig['position'] = $config['position'];
                $calculatedColumnConfig['isOperator'] = true;
                $calculatedColumnConfig['attributes'] = $config['fieldConfig']['attributes'];
                $calculatedColumnConfig['width'] = $config['width'];
                $calculatedColumnConfig['locked'] = $config['locked'];

                $existingColumns = $session->get('helpercolumns', []);

                if (isset($existingColumns[$existingKey])) {
                    // if the configuration is still in the session, then reuse it
                    return $calculatedColumnConfig;
                }

                $newKey = '#' . uniqid();
                $calculatedColumnConfig['key'] = $newKey;

                // prepare a column config on the fly
                $phpConfig = json_encode($config['fieldConfig']);
                $phpConfig = json_decode($phpConfig);
                $helperColumns = [];
                $helperColumns[$newKey] = $phpConfig;

                $helperColumns = array_merge($helperColumns, $existingColumns);
                $session->set('helpercolumns', $helperColumns);

                return $calculatedColumnConfig;
            }, 'pimcore_gridconfig');

            return $calculatedColumnConfig;
        } catch (\Exception $e) {
            Logger::error((string) $e);
        }

        return null;
    }

    /**
     * @Route("/prepare-helper-column-configs", name="preparehelpercolumnconfigs", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function prepareHelperColumnConfigs(Request $request): JsonResponse
    {
        $helperColumns = [];
        $newData = [];
        /** @var \stdClass[] $data */
        $data = json_decode($request->get('columns'));
        foreach ($data as $item) {
            if (!empty($item->isOperator)) {
                $itemKey = '#' . uniqid();

                $item->key = $itemKey;
                $newData[] = $item;
                $helperColumns[$itemKey] = $item;
            } else {
                $newData[] = $item;
            }
        }

        Tool\Session::useBag($request->getSession(), function (AttributeBagInterface $session) use ($helperColumns) {
            $existingColumns = $session->get('helpercolumns', []);
            $helperColumns = array_merge($helperColumns, $existingColumns);
            $session->set('helpercolumns', $helperColumns);
        }, 'pimcore_gridconfig');

        return $this->adminJson(['success' => true, 'columns' => $newData]);
    }

    /**
     * @Route("/grid-config-apply-to-all", name="gridconfigapplytoall", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function gridConfigApplyToAllAction(Request $request): JsonResponse
    {
        $objectId = $request->request->getInt('objectId');
        $object = DataObject::getById($objectId);

        if ($object->isAllowed('list')) {
            $classId = $request->get('classId');
            $searchType = $request->get('searchType');
            $user = $this->getAdminUser();
            $db = Db::get();
            $db->executeQuery('delete from gridconfig_favourites where '
                . 'ownerId = ' . $user->getId()
                . ' and classId = ' . $db->quote($classId) .
                ' and searchType = ' . $db->quote($searchType)
                . ' and objectId != ' . $objectId . ' and objectId != 0');

            return $this->adminJson(['success' => true]);
        }

        throw $this->createAccessDeniedHttpException();
    }

    /**
     * @Route("/grid-mark-favourite-column-config", name="gridmarkfavouritecolumnconfig", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function gridMarkFavouriteColumnConfigAction(Request $request): JsonResponse
    {
        $objectId = (int)$request->get('objectId');
        $object = DataObject::getById($objectId);

        if ($object->isAllowed('list')) {
            $classId = $request->get('classId');
            $gridConfigId = $request->get('gridConfigId');
            $searchType = $request->get('searchType');
            $global = $request->get('global');
            $user = $this->getAdminUser();
            $type = $request->get('type');

            $favourite = new GridConfigFavourite();
            $favourite->setOwnerId($user->getId());
            $class = DataObject\ClassDefinition::getById($classId);
            if (!$class) {
                throw new \Exception('class ' . $classId . ' does not exist anymore');
            }
            $favourite->setClassId($classId);
            $favourite->setSearchType($searchType);
            $favourite->setType($type);
            $specializedConfigs = false;

            try {
                if ($gridConfigId != 0) {
                    $gridConfig = GridConfig::getById((int)$gridConfigId);
                    $favourite->setGridConfigId($gridConfig->getId());
                }
                $favourite->setObjectId($objectId);
                $favourite->save();

                if ($global) {
                    $favourite->setObjectId(0);
                    $favourite->save();
                }
                $db = Db::get();
                $count = $db->fetchOne('select * from gridconfig_favourites where '
                    . 'ownerId = ' . $user->getId()
                    . ' and classId = ' . $db->quote($classId).
                    ' and searchType = ' . $db->quote($searchType)
                    . ' and objectId != ' . $objectId . ' and objectId != 0'
                    . ' and `type` != ' . $db->quote($type));
                $specializedConfigs = $count > 0;
            } catch (\Exception $e) {
                $favourite->delete();
            }

            return $this->adminJson(['success' => true, 'spezializedConfigs' => $specializedConfigs]);
        }

        throw $this->createAccessDeniedHttpException();
    }

    protected function getShareSettings(int $gridConfigId): array
    {
        $result = [
            'sharedUserIds' => [],
            'sharedRoleIds' => [],
        ];

        $db = Db::get();
        $allShares = $db->fetchAllAssociative('select s.sharedWithUserId, u.type from gridconfig_shares s, users u
                      where s.sharedWithUserId = u.id and s.gridConfigId = ' . $gridConfigId);

        if ($allShares) {
            foreach ($allShares as $share) {
                $type = $share['type'];
                $key = 'shared' . ucfirst($type) . 'Ids';
                $result[$key][] = $share['sharedWithUserId'];
            }
        }

        foreach ($result as $idx => $value) {
            $value = $value ? implode(',', $value) : '';
            $result[$idx] = $value;
        }

        return $result;
    }

    /**
     * @Route("/grid-save-column-config", name="gridsavecolumnconfig", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function gridSaveColumnConfigAction(Request $request): JsonResponse
    {
        $objectId = $request->request->getInt('id');
        $object   = DataObject::getById($objectId);

        if ($object->isAllowed('list')) {
            try {
                $classId = $request->get('class_id');
                $context = $request->get('context');

                $searchType = $request->get('searchType');

                // grid config
                $gridConfigData = $this->decodeJson($request->get('gridconfig'));
                $gridConfigData['pimcore_version'] = Version::getVersion();
                $gridConfigData['pimcore_revision'] = Version::getRevision();

                $gridConfigData['context'] = $context;

                unset($gridConfigData['settings']['isShared']);

                $metadata = $request->get('settings');
                $metadata = json_decode($metadata, true);

                $gridConfigId = $metadata['gridConfigId'];
                $gridConfig = null;
                if ($gridConfigId) {
                    $gridConfig = GridConfig::getById($gridConfigId);
                }

                if ($gridConfig && $gridConfig->getOwnerId() != $this->getAdminUser()->getId() && !$this->getAdminUser()->isAdmin()) {
                    throw new \Exception("don't mess around with somebody elses configuration");
                }

                $this->updateGridConfigShares($gridConfig, $metadata);

                if ($metadata['setAsFavourite'] && $this->getAdminUser()->isAdmin()) {
                    $this->updateGridConfigFavourites($gridConfig, $metadata, $objectId);
                }

                if (!$gridConfig) {
                    $gridConfig = new GridConfig();
                    $gridConfig->setName(date('c'));
                    $gridConfig->setClassId($classId);
                    $gridConfig->setSearchType($searchType);

                    $gridConfig->setOwnerId($this->getAdminUser()->getId());
                }

                if ($metadata) {
                    $gridConfig->setName(SecurityHelper::convertHtmlSpecialChars($metadata['gridConfigName']));
                    $gridConfig->setDescription(SecurityHelper::convertHtmlSpecialChars($metadata['gridConfigDescription']));
                    $gridConfig->setShareGlobally($metadata['shareGlobally'] && $this->getAdminUser()->isAdmin());
                    $gridConfig->setSetAsFavourite($metadata['setAsFavourite'] && $this->getAdminUser()->isAdmin());
                    $gridConfig->setSaveFilters($metadata['saveFilters'] ?? false);
                }

                $gridConfigData = json_encode($gridConfigData);
                $gridConfig->setConfig($gridConfigData);
                $gridConfig->save();

                $userId = $this->getAdminUser()->getId();

                $availableConfigs = $this->getMyOwnGridColumnConfigs($userId, $classId, $searchType);
                $sharedConfigs = $this->getSharedGridColumnConfigs($this->getAdminUser(), $classId, $searchType);

                $settings = $this->getShareSettings($gridConfig->getId());
                $settings['gridConfigId'] = (int)$gridConfig->getId();
                $settings['gridConfigName'] = SecurityHelper::convertHtmlSpecialChars($gridConfig->getName());
                $settings['gridConfigDescription'] = SecurityHelper::convertHtmlSpecialChars($gridConfig->getDescription());
                $settings['shareGlobally'] = $gridConfig->isShareGlobally();
                $settings['setAsFavourite'] = $gridConfig->isSetAsFavourite();
                $settings['saveFilters'] = $gridConfig->isSaveFilters();
                $settings['isShared'] = $gridConfig->getOwnerId() != $this->getAdminUser()->getId() && !$this->getAdminUser()->isAdmin();

                return $this->adminJson([
                    'success' => true,
                    'settings' => $settings,
                    'availableConfigs' => $availableConfigs,
                    'sharedConfigs' => $sharedConfigs,
                ]);
            } catch (\Exception $e) {
                return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }

        throw $this->createAccessDeniedHttpException();
    }

    /**
     * @param GridConfig|null $gridConfig
     * @param array $metadata
     *
     * @throws \Exception
     */
    protected function updateGridConfigShares(?GridConfig $gridConfig, array $metadata): void
    {
        $user = $this->getAdminUser();
        if (!$gridConfig || !$user->isAllowed('share_configurations')) {
            // nothing to do
            return;
        }

        if ($gridConfig->getOwnerId() != $user->getId() && !$user->isAdmin()) {
            throw new \Exception("don't mess with someone elses grid config");
        }
        $combinedShares = [];
        $sharedUserIds = $metadata['sharedUserIds'];
        $sharedRoleIds = $metadata['sharedRoleIds'];

        if ($sharedUserIds) {
            $combinedShares = explode(',', $sharedUserIds);
        }

        if ($sharedRoleIds) {
            $sharedRoleIds = explode(',', $sharedRoleIds);
            $combinedShares = array_merge($combinedShares, $sharedRoleIds);
        }

        $db = Db::get();
        $db->delete('gridconfig_shares', ['gridConfigId' => $gridConfig->getId()]);

        foreach ($combinedShares as $id) {
            $share = new GridConfigShare();
            $share->setGridConfigId($gridConfig->getId());
            $share->setSharedWithUserId((int) $id);
            $share->save();
        }
    }

    /**
     * @param GridConfig|null $gridConfig
     * @param array $metadata
     * @param int $objectId
     *
     * @throws \Exception
     */
    protected function updateGridConfigFavourites(?GridConfig $gridConfig, array $metadata, int $objectId): void
    {
        $currentUser = $this->getAdminUser();

        if (!$gridConfig || $currentUser === null || !$currentUser->isAllowed('share_configurations')) {
            // nothing to do
            return;
        }

        if (!$currentUser->isAdmin() && (int) $gridConfig->getOwnerId() !== $currentUser->getId()) {
            throw new \Exception("don't mess with someone elses grid config");
        }

        $sharedUsers = [];

        if ($metadata['shareGlobally'] === false) {
            $sharedUserIds = $metadata['sharedUserIds'];

            if ($sharedUserIds) {
                $sharedUsers = explode(',', $sharedUserIds);
            }
        }

        if ($metadata['shareGlobally'] === true) {
            $users = new User\Listing();
            $users->setCondition('id = ?', $currentUser->getId());

            foreach ($users as $user) {
                $sharedUsers[] = $user->getId();
            }
        }

        foreach ($sharedUsers as $id) {
            $global    = true;
            $favourite = GridConfigFavourite::getByOwnerAndClassAndObjectId(
                (int) $id,
                $gridConfig->getClassId(),
                $objectId,
                $gridConfig->getSearchType()
            );

            // If the user has already a favourite for that object we check the current favourite and decide if we update
            if ($favourite instanceof GridConfigFavourite) {
                $favouriteGridConfig = GridConfig::getById($favourite->getGridConfigId());

                if ($favouriteGridConfig instanceof GridConfig) {
                    // Check if the grid config was shared globally if that is *not* the case we also not update
                    if ($favouriteGridConfig->isShareGlobally() === false) {
                        continue;
                    }

                    // Check if the user is the owner. If that is the case we do not update the favourite
                    if ((int) $favouriteGridConfig->getOwnerId() === (int) $id) {
                        continue;
                    }
                }
            }

            // Check if the user has already a global favourite then we do not save the favourite as global
            $favourite = GridConfigFavourite::getByOwnerAndClassAndObjectId(
                (int) $id,
                $gridConfig->getClassId(),
                0,
                $gridConfig->getSearchType()
            );

            if ($favourite instanceof GridConfigFavourite) {
                $favouriteGridConfig = GridConfig::getById($favourite->getGridConfigId());

                if ($favouriteGridConfig instanceof GridConfig) {
                    // Check if the grid config was shared globally if that is *not* the case we also not update
                    if ($favouriteGridConfig->isShareGlobally() === false) {
                        $global = false;
                    }

                    // Check if the user is the owner. If that is the case we do not update the global favourite
                    if ($favouriteGridConfig->getOwnerId() === (int) $id) {
                        $global = false;
                    }
                }
            }

            $favourite = new GridConfigFavourite();
            $favourite->setGridConfigId($gridConfig->getId());
            $favourite->setClassId($gridConfig->getClassId());
            $favourite->setObjectId($objectId);
            $favourite->setOwnerId($id);
            $favourite->setType($gridConfig->getType());
            $favourite->setSearchType($gridConfig->getSearchType());
            $favourite->save();

            if ($global === true) {
                $favourite->setObjectId(0);
                $favourite->save();
            }
        }
    }

    /**
     * @param DataObject\ClassDefinition\Data $field
     * @param string $gridType
     * @param string $position
     * @param bool $force
     * @param string|null $keyPrefix
     * @param DataObject\ClassDefinition|null $class
     * @param int|null $objectId
     *
     * @return array|null
     */
    protected function getFieldGridConfig(DataObject\ClassDefinition\Data $field, string $gridType, string $position, bool $force = false, string $keyPrefix = null, DataObject\ClassDefinition $class = null, int $objectId = null): ?array
    {
        $key = $keyPrefix . $field->getName();
        $config = null;
        $title = $field->getName();
        if (method_exists($field, 'getTitle')) {
            if ($field->getTitle()) {
                $title = $field->getTitle();
            }
        }

        if ($field instanceof DataObject\ClassDefinition\Data\Slider) {
            $config['minValue'] = $field->getMinValue();
            $config['maxValue'] = $field->getMaxValue();
            $config['increment'] = $field->getIncrement();
        }

        if (method_exists($field, 'getWidth')) {
            $config['width'] = $field->getWidth();
        }
        if (method_exists($field, 'getHeight')) {
            $config['height'] = $field->getHeight();
        }

        $visible = false;
        if ($gridType == 'search') {
            $visible = $field->getVisibleSearch();
        } elseif ($gridType == 'grid') {
            $visible = $field->getVisibleGridView();
        } elseif ($gridType == 'all') {
            $visible = true;
        }

        if (!$field->getInvisible() && ($force || $visible)) {
            $context = ['purpose' => 'gridconfig'];
            if ($class) {
                $context['class'] = $class;
            }

            if ($objectId) {
                $object = DataObject::getById($objectId);
                $context['object'] = $object;
            }
            DataObject\Service::enrichLayoutDefinition($field, null, $context);

            $result = [
                'key' => $key,
                'type' => $field->getFieldType(),
                'label' => $title,
                'config' => $config,
                'layout' => $field,
                'position' => $position,
            ];

            if ($field instanceof DataObject\ClassDefinition\Data\EncryptedField) {
                $result['delegateDatatype'] = $field->getDelegateDatatype();
            }

            return $result;
        } else {
            return null;
        }
    }

    /**
     * IMPORTER
     */

    /**
     * @Route("/import-upload", name="importupload", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function importUploadAction(Request $request, Filesystem $filesystem): JsonResponse
    {
        $data = file_get_contents($_FILES['Filedata']['tmp_name']);
        $data = Tool\Text::convertToUTF8($data);

        $importId = $request->get('importId');
        $importId = str_replace('..', '', $importId);
        $importFile = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/import_' . $importId;
        $filesystem->dumpFile($importFile, $data);

        $importFileOriginal = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/import_' . $importId . '_original';
        $filesystem->dumpFile($importFileOriginal, $data);

        $response = $this->adminJson([
            'success' => true,
        ]);

        // set content-type to text/html, otherwise (when application/json is sent) chrome will complain in
        // Ext.form.Action.Submit and mark the submission as failed
        $response->headers->set('Content-Type', 'text/html');

        return $response;
    }

    protected function extractLanguage(Request $request): string
    {
        $requestedLanguage = $request->get('language');
        if ($requestedLanguage) {
            if ($requestedLanguage != 'default') {
                $request->setLocale($requestedLanguage);
            }
        } else {
            $requestedLanguage = $request->getLocale();
        }

        return $requestedLanguage;
    }

    protected function getCsvFile(string $fileHandle): string
    {
        return $fileHandle . '.csv';
    }

    /**
     * @Route("/get-export-jobs", name="getexportjobs", methods={"POST"})
     *
     * @param Request $request
     * @param GridHelperService $gridHelperService
     * @param EventDispatcherInterface $eventDispatcher
     *
     * @return JsonResponse
     */
    public function getExportJobsAction(Request $request, GridHelperService $gridHelperService, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        $requestedLanguage = $this->extractLanguage($request);
        $allParams = array_merge($request->request->all(), $request->query->all());

        $list = $gridHelperService->prepareListingForGrid($allParams, $requestedLanguage, $this->getAdminUser());

        $beforeListPrepareEvent = new GenericEvent($this, [
            'list' => $list,
            'context' => $allParams,
        ]);
        $eventDispatcher->dispatch($beforeListPrepareEvent, AdminEvents::OBJECT_LIST_BEFORE_EXPORT_PREPARE);

        $list = $beforeListPrepareEvent->getArgument('list');

        $ids = $list->loadIdList();

        $jobs = array_chunk($ids, 20);

        $fileHandle = uniqid('export-');

        $storage = Storage::get('temp');
        $storage->write($this->getCsvFile($fileHandle), '');

        return $this->adminJson(['success' => true, 'jobs' => $jobs, 'fileHandle' => $fileHandle]);
    }

    /**
     * @Route("/do-export", name="doexport", methods={"POST"})
     *
     * @param Request $request
     * @param LocaleServiceInterface $localeService
     * @param EventDispatcherInterface $eventDispatcher
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function doExportAction(Request $request, LocaleServiceInterface $localeService, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        $fileHandle = \Pimcore\File::getValidFilename($request->get('fileHandle'));
        $ids = $request->get('ids');
        $settings = $request->get('settings');
        $settings = json_decode($settings, true);
        $delimiter = $settings['delimiter'] ?? ';';
        $header = $settings['header'] ?? 'title';

        $allParams = array_merge($request->request->all(), $request->query->all());

        $enableInheritance = $settings['enableInheritance'] ?? null;
        DataObject\Concrete::setGetInheritedValues($enableInheritance);

        $class = DataObject\ClassDefinition::getById($request->get('classId'));

        if (!$class) {
            throw new \Exception('No class definition found');
        }

        $className = $class->getName();
        $listClass = '\\Pimcore\\Model\\DataObject\\' . ucfirst($className) . '\\Listing';

        /** @var \Pimcore\Model\DataObject\Listing $list */
        $list = new $listClass();

        $quotedIds = [];
        foreach ($ids as $id) {
            $quotedIds[] = $list->quote($id);
        }

        $list->setObjectTypes(DataObject::$types);
        $list->setCondition('id IN (' . implode(',', $quotedIds) . ')');
        $list->setOrderKey(' FIELD(id, ' . implode(',', $quotedIds) . ')', false);

        $beforeListExportEvent = new GenericEvent($this, [
            'list' => $list,
            'context' => $allParams,
        ]);
        $eventDispatcher->dispatch($beforeListExportEvent, AdminEvents::OBJECT_LIST_BEFORE_EXPORT);

        $list = $beforeListExportEvent->getArgument('list');

        $fields = json_decode($request->get('fields')[0], true);

        $addTitles = (bool) $request->get('initial');

        $requestedLanguage = $this->extractLanguage($request);

        $contextFromRequest = $request->get('context');
        if ($contextFromRequest) {
            $contextFromRequest = json_decode($contextFromRequest, true);
        }

        $context = [
            'source' => 'pimcore-export',
        ];

        if (is_array($contextFromRequest)) {
            $context = array_merge($context, $contextFromRequest);
        }

        $csv = DataObject\Service::getCsvData($requestedLanguage, $localeService, $list, $fields, $header, $addTitles, $context);

        $storage = Storage::get('temp');
        $csvFile = $this->getCsvFile($fileHandle);

        $fileStream = $storage->readStream($csvFile);

        $temp = tmpfile();
        stream_copy_to_stream($fileStream, $temp, null, 0);

        $firstLine = true;

        if ($request->get('initial') && $header === 'no_header') {
            array_shift($csv);
            $firstLine = false;
        }

        $lineCount = count($csv);

        if (!$addTitles && $lineCount > 0) {
            fwrite($temp, "\r\n");
        }

        for ($i = 0; $i < $lineCount; $i++) {
            $line = $csv[$i];
            if ($addTitles && $firstLine) {
                $firstLine = false;
                $line = implode($delimiter, $line);
                fwrite($temp, $line);
            } else {
                fwrite($temp, implode($delimiter, array_map([$this, 'encodeFunc'], $line)));
            }
            if ($i < $lineCount - 1) {
                fwrite($temp, "\r\n");
            }
        }
        $storage->writeStream($csvFile, $temp);

        return $this->adminJson(['success' => true]);
    }

    public function encodeFunc(string $value): string
    {
        $value = str_replace('"', '""', $value);

        //force wrap value in quotes and return
        return '"' . $value . '"';
    }

    /**
     * @Route("/download-csv-file", name="downloadcsvfile", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function downloadCsvFileAction(Request $request): Response
    {
        $storage = Storage::get('temp');
        $fileHandle = \Pimcore\File::getValidFilename($request->get('fileHandle'));
        $csvFile = $this->getCsvFile($fileHandle);

        try {
            $csvData = $storage->read($csvFile);
            $response = new Response($csvData);
            $response->headers->set('Content-Type', 'application/csv');
            $disposition = HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                'export.csv'
            );

            $response->headers->set('Content-Disposition', $disposition);
            $storage->delete($csvFile);

            return $response;
        } catch (FilesystemException | UnableToReadFile $exception) {
            // handle the error
            throw $this->createNotFoundException('CSV file not found');
        }
    }

    /**
     * @Route("/download-xlsx-file", name="downloadxlsxfile", methods={"GET"})
     *
     * @param Request $request
     * @param GridHelperService $gridHelperService
     *
     * @return BinaryFileResponse
     */
    public function downloadXlsxFileAction(Request $request, GridHelperService $gridHelperService): BinaryFileResponse
    {
        $storage = Storage::get('temp');
        $fileHandle = \Pimcore\File::getValidFilename($request->get('fileHandle'));
        $csvFile = $this->getCsvFile($fileHandle);

        try {
            return $gridHelperService->createXlsxExportFile($storage, $fileHandle, $csvFile);
        } catch (\Exception | FilesystemException | UnableToReadFile $exception) {
            // handle the error
            throw $this->createNotFoundException('XLSX file not found');
        }
    }

    /**
     * Flattens object data to an array with key=>value where
     * value is simply a string representation of the value (for objects, hrefs and assets the full path is used)
     *
     * @param DataObject\Concrete $object
     *
     * @return array
     */
    protected function csvObjectData(DataObject\Concrete $object): array
    {
        $o = [];
        foreach ($object->getClass()->getFieldDefinitions() as $key => $value) {
            $o[$key] = $value->getForCsvExport($object);
        }

        $o['id (system)'] = $object->getId();
        $o['key (system)'] = $object->getKey();
        $o['fullpath (system)'] = $object->getRealFullPath();
        $o['published (system)'] = $object->isPublished();
        $o['type (system)'] = $object->getType();

        return $o;
    }

    /**
     * @Route("/get-batch-jobs", name="getbatchjobs", methods={"POST"})
     *
     *
     */
    public function getBatchJobsAction(Request $request, GridHelperService $gridHelperService): JsonResponse
    {
        if ($request->get('language')) {
            $request->setLocale($request->get('language'));
        }

        $allParams = array_merge($request->request->all(), $request->query->all());
        $list = $gridHelperService->prepareListingForGrid($allParams, $request->getLocale(), $this->getAdminUser());

        $jobs = $list->loadIdList();

        return $this->adminJson(['success' => true, 'jobs' => $jobs]);
    }

    /**
     * @Route("/batch", name="batch", methods={"PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function batchAction(Request $request): JsonResponse
    {
        $success = true;

        try {
            if ($request->get('data')) {
                $params = $this->decodeJson($request->get('data'), true);
                $object = DataObject\Concrete::getById($params['job']);

                if ($object) {
                    $name = $params['name'];

                    if (!$object->isAllowed('save') || ($name === 'published' && !$object->isAllowed('publish'))) {
                        throw new \Exception("Permission denied. You don't have the rights to save this object.");
                    }

                    $append = $params['append'] ?? false;
                    $remove = $params['remove'] ?? false;

                    $className = $object->getClassName();
                    $class = DataObject\ClassDefinition::getByName($className);
                    $value = $params['value'];
                    if ($params['valueType'] == 'object') {
                        $value = $this->decodeJson($value);
                    }

                    $parts = explode('~', $name);

                    if (substr($name, 0, 1) == '~') {
                        $type = $parts[1];
                        $field = $parts[2];
                        $keyId = $parts[3];

                        if ($type == 'classificationstore') {
                            $requestedLanguage = $params['language'];
                            if ($requestedLanguage) {
                                if ($requestedLanguage != 'default') {
                                    $request->setLocale($requestedLanguage);
                                }
                            } else {
                                $requestedLanguage = $request->getLocale();
                            }

                            $groupKeyId = explode('-', $keyId);
                            $groupId = (int) $groupKeyId[0];
                            $keyId = (int) $groupKeyId[1];

                            $getter = 'get' . ucfirst($field);
                            if (method_exists($object, $getter)) {
                                /** @var DataObject\ClassDefinition\Data\Classificationstore $csFieldDefinition */
                                $csFieldDefinition = $object->getClass()->getFieldDefinition($field);
                                $csLanguage = $requestedLanguage;
                                if (!$csFieldDefinition->isLocalized()) {
                                    $csLanguage = 'default';
                                }

                                /** @var DataObject\ClassDefinition\Data\Classificationstore $fd */
                                $fd = $class->getFieldDefinition($field);
                                $keyConfig = $fd->getKeyConfiguration($keyId);
                                $dataDefinition = DataObject\Classificationstore\Service::getFieldDefinitionFromKeyConfig($keyConfig);

                                /** @var DataObject\Classificationstore $classificationStoreData */
                                $classificationStoreData = $object->$getter();
                                if ($append) {
                                    $oldValue = $classificationStoreData->getLocalizedKeyValue($groupId, $keyId);
                                    $value = $dataDefinition->appendData($oldValue, $value);
                                }
                                if ($remove) {
                                    $oldValue = $classificationStoreData->getLocalizedKeyValue($groupId, $keyId);
                                    $value = $dataDefinition->removeData($oldValue, $value);
                                }
                                $classificationStoreData->setLocalizedKeyValue(
                                    $groupId,
                                    $keyId,
                                    $dataDefinition->getDataFromEditmode($value),
                                    $csLanguage
                                );
                            }
                        }
                    } elseif (count($parts) > 1) {
                        // check for bricks
                        $brickType = $parts[0];

                        if (strpos($brickType, '?') !== false) {
                            $brickDescriptor = substr($brickType, 1);
                            $brickDescriptor = json_decode($brickDescriptor, true);
                            $brickType = $brickDescriptor['containerKey'];
                        }
                        $brickKey = $parts[1];
                        $brickField = DataObject\Service::getFieldForBrickType($object->getClass(), $brickType);

                        $fieldGetter = 'get' . ucfirst($brickField);
                        $brickGetter = 'get' . ucfirst($brickType);
                        $valueSetter = 'set' . ucfirst($brickKey);

                        $brick = $object->$fieldGetter()->$brickGetter();
                        if (empty($brick)) {
                            $classname = '\\Pimcore\\Model\\DataObject\\Objectbrick\\Data\\' . ucfirst($brickType);
                            $brickSetter = 'set' . ucfirst($brickType);
                            $brick = new $classname($object);
                            $object->$fieldGetter()->$brickSetter($brick);
                        }

                        $brickClass = DataObject\Objectbrick\Definition::getByKey($brickType);
                        $field = $brickClass->getFieldDefinition($brickKey);

                        $newData = $field->getDataFromEditmode($value, $object);

                        if ($append) {
                            $valueGetter = 'get' . ucfirst($brickKey);
                            $existingData = $brick->$valueGetter();
                            $newData = $field->appendData($existingData, $newData);
                        }
                        if ($remove) {
                            $valueGetter = 'get' . ucfirst($brickKey);
                            $existingData = $brick->$valueGetter();
                            $newData = $field->removeData($existingData, $newData);
                        }

                        $localizedFields = $brickClass->getFieldDefinition('localizedfields');
                        $isLocalizedField = false;
                        if ($localizedFields instanceof DataObject\ClassDefinition\Data\Localizedfields) {
                            if ($localizedFields->getFieldDefinition($brickKey)) {
                                $isLocalizedField = true;
                            }
                        }

                        if ($isLocalizedField) {
                            $brick->$valueSetter($newData, $params['language']);
                        } else {
                            $brick->$valueSetter($newData);
                        }
                    } else {
                        // everything else
                        $field = $class->getFieldDefinition($name);
                        if ($field) {
                            $newData = $field->getDataFromEditmode($value, $object);

                            if ($append) {
                                $existingData = $object->{'get' . $name}();
                                $newData = $field->appendData($existingData, $newData);
                            }
                            if ($remove) {
                                $existingData = $object->{'get' . $name}();
                                $newData = $field->removeData($existingData, $newData);
                            }
                            $object->setValue($name, $newData);
                        } else {
                            // check if it is a localized field
                            if ($params['language']) {
                                $localizedField = $class->getFieldDefinition('localizedfields');
                                if ($localizedField instanceof DataObject\ClassDefinition\Data\Localizedfields) {
                                    $field = $localizedField->getFieldDefinition($name);
                                    if ($field) {
                                        $getter = 'get' . $name;
                                        $setter = 'set' . $name;
                                        $newData = $field->getDataFromEditmode($value, $object);
                                        if ($append) {
                                            $existingData = $object->$getter($params['language']);
                                            $newData = $field->appendData($existingData, $newData);
                                        }
                                        if ($remove) {
                                            $existingData = $object->$getter($request->get('language'));
                                            $newData = $field->removeData($existingData, $newData);
                                        }

                                        $object->$setter($newData, $params['language']);
                                    }
                                }
                            }

                            // seems to be a system field, this is actually only possible for the "published" field yet
                            if ($name == 'published') {
                                if ($value === 'false' || empty($value)) {
                                    $object->setPublished(false);
                                } else {
                                    $object->setPublished(true);
                                }
                            }
                        }
                    }

                    try {
                        // don't check for mandatory fields here
                        $object->setOmitMandatoryCheck(!$object->isPublished());
                        $object->setUserModification($this->getAdminUser()->getId());
                        $object->save();
                        $success = true;
                    } catch (\Exception $e) {
                        return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
                    }
                } else {
                    Logger::debug('DataObjectController::batchAction => There is no object left to update.');

                    return $this->adminJson(['success' => false, 'message' => 'DataObjectController::batchAction => There is no object left to update.']);
                }
            }
        } catch (\Exception $e) {
            Logger::err((string) $e);

            return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
        }

        return $this->adminJson(['success' => $success]);
    }

    /**
     * @Route("/get-available-visible-vields", name="getavailablevisiblefields", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getAvailableVisibleFieldsAction(Request $request): JsonResponse
    {
        $class = null;
        $fields = null;

        $classList = [];
        $classNameList = [];

        if ($request->get('classes')) {
            $classNameList = $request->get('classes');
            $classNameList = explode(',', $classNameList);
            foreach ($classNameList as $className) {
                $class = DataObject\ClassDefinition::getByName($className);
                if ($class) {
                    $classList[] = $class;
                }
            }
        }

        if (!$classList) {
            return $this->adminJson(['availableFields' => []]);
        }
        $availableFields = [];
        foreach (self::SYSTEM_COLUMNS as $field) {
            $availableFields[] = [
                'key' => $field,
                'value' => $field,
            ];
        }

        /** @var DataObject\ClassDefinition\Data[] $commonFields */
        $commonFields = [];

        $firstOne = true;
        foreach ($classNameList as $className) {
            $class = DataObject\ClassDefinition::getByName($className);
            if ($class) {
                $fds = $class->getFieldDefinitions();

                $additionalFieldNames = array_keys($fds);
                $localizedFields = $class->getFieldDefinition('localizedfields');
                if ($localizedFields instanceof DataObject\ClassDefinition\Data\Localizedfields) {
                    $lfNames = array_keys($localizedFields->getFieldDefinitions());
                    $additionalFieldNames = array_merge($additionalFieldNames, $lfNames);
                }

                foreach ($commonFields as $commonFieldKey => $commonFieldDefinition) {
                    if (!in_array($commonFieldKey, $additionalFieldNames)) {
                        unset($commonFields[$commonFieldKey]);
                    }
                }

                $this->processAvailableFieldDefinitions($fds, $firstOne, $commonFields);

                $firstOne = false;
            }
        }

        $commonFieldKeys = array_keys($commonFields);
        foreach ($commonFieldKeys as $field) {
            $availableFields[] = [
                'key' => $field,
                'value' => $field,
            ];
        }

        return $this->adminJson(['availableFields' => $availableFields]);
    }

    /**
     * @param DataObject\ClassDefinition\Data[] $fds
     * @param bool $firstOne
     * @param DataObject\ClassDefinition\Data[] $commonFields
     */
    protected function processAvailableFieldDefinitions(array $fds, bool &$firstOne, array &$commonFields): void
    {
        foreach ($fds as $fd) {
            if ($fd instanceof DataObject\ClassDefinition\Data\Fieldcollections || $fd instanceof DataObject\ClassDefinition\Data\Objectbricks
                || $fd instanceof DataObject\ClassDefinition\Data\Block) {
                continue;
            }

            if ($fd instanceof DataObject\ClassDefinition\Data\Localizedfields) {
                $lfDefs = $fd->getFieldDefinitions();
                $this->processAvailableFieldDefinitions($lfDefs, $firstOne, $commonFields);
            } elseif ($firstOne || (isset($commonFields[$fd->getName()]) && $commonFields[$fd->getName()]->getFieldtype() == $fd->getFieldtype())) {
                $commonFields[$fd->getName()] = $fd;
            }
        }
    }
}
