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

namespace Pimcore\Bundle\AdminBundle\Controller\Admin\Asset;

use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToReadFile;
use Pimcore\Bundle\AdminBundle\Controller\AdminAbstractController;
use Pimcore\Bundle\AdminBundle\Event\AdminEvents;
use Pimcore\Bundle\AdminBundle\Helper\GridHelperService;
use Pimcore\Bundle\AdminBundle\Model\GridConfig;
use Pimcore\Bundle\AdminBundle\Model\GridConfigFavourite;
use Pimcore\Bundle\AdminBundle\Model\GridConfigShare;
use Pimcore\Bundle\AdminBundle\Tool;
use Pimcore\Db;
use Pimcore\Loader\ImplementationLoader\Exception\UnsupportedException;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Logger;
use Pimcore\Model\Asset;
use Pimcore\Model\Element;
use Pimcore\Model\Metadata;
use Pimcore\Model\User;
use Pimcore\Security\SecurityHelper;
use Pimcore\Tool\Session;
use Pimcore\Tool\Storage;
use Pimcore\Version;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @Route("/asset-helper")
 *
 * @internal
 */
class AssetHelperController extends AdminAbstractController
{
    public function getMyOwnGridColumnConfigs(int $userId, string $classId, string $searchType): array
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
        $db = Db::get();

        $configListing = [];

        $userIds = [$user->getId()];
        // collect all roles
        $userIds = array_merge($userIds, $user->getRoles());
        $userIds = implode(',', $userIds);

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
     * @Route("/grid-delete-column-config", name="pimcore_admin_asset_assethelper_griddeletecolumnconfig", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function gridDeleteColumnConfigAction(Request $request): JsonResponse
    {
        $gridConfigId = (int) $request->get('gridConfigId');
        $gridConfig = GridConfig::getById($gridConfigId);
        $success = false;
        if ($gridConfig) {
            if ($gridConfig->getOwnerId() != $this->getAdminUser()->getId()) {
                throw new \Exception("don't mess with someone elses grid config");
            }

            $gridConfig->delete();
            $success = true;
        }

        $newGridConfig = $this->doGetGridColumnConfig($request, true);
        $newGridConfig['deleteSuccess'] = $success;

        return $this->adminJson($newGridConfig);
    }

    /**
     * @Route("/grid-get-column-config", name="pimcore_admin_asset_assethelper_gridgetcolumnconfig", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function gridGetColumnConfigAction(Request $request): JsonResponse
    {
        $result = $this->doGetGridColumnConfig($request);

        return $this->adminJson($result);
    }

    public function doGetGridColumnConfig(Request $request, bool $isDelete = false): array
    {
        $gridConfigId = null;

        $classId = $request->get('id');
        $type = $request->get('type');

        $context = ['purpose' => 'gridconfig'];

        $types = [];
        if ($request->get('types')) {
            $types = explode(',', $request->get('types'));
        }

        $userId = $this->getAdminUser()->getId();

        $requestedGridConfigId = $isDelete ? '' : $request->get('gridConfigId', '');

        // grid config
        $gridConfig = [];
        $searchType = $request->get('searchType');

        if (strlen($requestedGridConfigId) == 0) {
            // check if there is a favourite view
            $favourite = GridConfigFavourite::getByOwnerAndClassAndObjectId($userId, $classId, 0, $searchType);

            if ($favourite) {
                $requestedGridConfigId = $favourite->getGridConfigId();
            }
        }

        if (is_numeric($requestedGridConfigId) && $requestedGridConfigId > 0) {
            $db = Db::get();
            $savedGridConfig = GridConfig::getById((int) $requestedGridConfigId);

            if ($savedGridConfig) {
                $shared = null;

                try {
                    $userIds = [$this->getAdminUser()->getId()];
                    $userIds = array_merge($userIds, $this->getAdminUser()->getRoles());
                    $userIds = implode(',', $userIds);
                    $shared = ($savedGridConfig->getOwnerId() != $userId && $savedGridConfig->isShareGlobally()) || $db->fetchOne('select * from gridconfig_shares where sharedWithUserId IN (' . $userIds . ') and gridConfigId = ' . $savedGridConfig->getId());
                } catch (\Exception $e) {
                }

                if (!$shared && $savedGridConfig->getOwnerId() != $this->getAdminUser()->getId()) {
                    throw new \Exception('You are neither the owner of this config nor it is shared with you');
                }
                $gridConfigId = $savedGridConfig->getId();
                $gridConfig = $savedGridConfig->getConfig();
                $gridConfig = json_decode($gridConfig, true);
                $gridConfigName = $savedGridConfig->getName();
                $gridConfigDescription = $savedGridConfig->getDescription();
                $sharedGlobally = $savedGridConfig->isShareGlobally();
                $setAsFavourite = $savedGridConfig->isSetAsFavourite();
            }
        }

        $availableFields = [];
        $language = '';

        if (empty($gridConfig)) {
            $availableFields = $this->getDefaultGridFields(
                $request->query->getBoolean('no_system_columns'),
                [], //maybe required for types other than metadata
                $context,
                $types
            );
        } else {
            $savedColumns = $gridConfig['columns'];

            foreach ($savedColumns as $key => $sc) {
                if (!$sc['hidden']) {
                    $colConfig = $this->getFieldGridConfig($sc, $language, null);
                    if ($colConfig) {
                        $availableFields[] = $colConfig;
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

        $availableConfigs = $classId ? $this->getMyOwnGridColumnConfigs($userId, $classId, $searchType) : [];
        $sharedConfigs = $classId ? $this->getSharedGridColumnConfigs($this->getAdminUser(), $classId, $searchType) : [];
        $settings = $this->getShareSettings((int)$gridConfigId);
        $settings['gridConfigId'] = (int)$gridConfigId;
        $settings['gridConfigName'] = $gridConfigName ?? null;
        $settings['gridConfigDescription'] = $gridConfigDescription ?? null;
        $settings['shareGlobally'] = $sharedGlobally ?? null;
        $settings['setAsFavourite'] = $setAsFavourite ?? null;
        $settings['isShared'] = !$gridConfigId || ($shared ?? null);

        $context = $gridConfig['context'] ?? null;
        if ($context) {
            $context = json_decode($context, true);
        }

        return [
            'sortinfo' => isset($gridConfig['sortinfo']) ? $gridConfig['sortinfo'] : false,
            'availableFields' => $availableFields,
            'settings' => $settings,
            'onlyDirectChildren' => isset($gridConfig['onlyDirectChildren']) ? $gridConfig['onlyDirectChildren'] : false,
            'onlyUnreferenced' => isset($gridConfig['onlyUnreferenced']) ? $gridConfig['onlyUnreferenced'] : false,
            'pageSize' => isset($gridConfig['pageSize']) ? $gridConfig['pageSize'] : false,
            'availableConfigs' => $availableConfigs,
            'sharedConfigs' => $sharedConfigs,
            'context' => $context,
        ];
    }

    /**
     * @param array $field
     * @param string $language
     * @param string|null $keyPrefix
     *
     * @return array|null
     */
    protected function getFieldGridConfig(array $field, string $language = '', string $keyPrefix = null): ?array
    {
        $defaulMetadataFields = ['copyright', 'alt', 'title'];
        $predefined = null;

        if (isset($field['fieldConfig']['layout']['name'])) {
            $predefined = Metadata\Predefined::getByName($field['fieldConfig']['layout']['name']);
        }

        $key = $field['name'];
        if ($keyPrefix) {
            $key = $keyPrefix . $key;
        }

        $fieldDef = explode('~', $field['name']);
        $field['name'] = $fieldDef[0];

        if (isset($fieldDef[1]) && $fieldDef[1] === 'system') {
            $type = 'system';
        } elseif (in_array($fieldDef[0], $defaulMetadataFields)) {
            $type = 'input';
        } else {
            $type = $field['fieldConfig']['type'];
            if (isset($fieldDef[1])) {
                $field['fieldConfig']['label'] = $field['fieldConfig']['layout']['title'] = $fieldDef[0] . ' (' . $fieldDef[1] . ')';
                $field['fieldConfig']['layout']['icon'] = Tool::getLanguageFlagFile($fieldDef[1], true);
            }
        }

        $result = [
            'key' => $key,
            'type' => $type,
            'label' => $field['fieldConfig']['label'] ?? $key,
            'width' => $field['width'],
            'position' => $field['position'],
            'language' => $field['fieldConfig']['language'] ?? null,
            'layout' => $field['fieldConfig']['layout'] ?? null,
        ];

        if (isset($field['locked'])) {
            $result['locked'] = $field['locked'];
        }

        if ($type === 'select' && $predefined) {
            $field['fieldConfig']['layout']['config'] = $predefined->getConfig();
            $result['layout'] = $field['fieldConfig']['layout'];
        } elseif ($type === 'document' || $type === 'asset' || $type === 'object') {
            $result['layout']['fieldtype'] = 'manyToOneRelation';
            $result['layout']['subtype'] = $type;
        }

        return $result;
    }

    public function getDefaultGridFields(bool $noSystemColumns, array $fields, array $context, array $types = []): array
    {
        $count = 0;
        $availableFields = [];

        if (!$noSystemColumns) {
            foreach (Asset\Service::GRID_SYSTEM_COLUMNS as $sc) {
                if (empty($types)) {
                    $availableFields[] = [
                        'key' => $sc . '~system',
                        'type' => 'system',
                        'label' => $sc,
                        'position' => $count, ];
                    $count++;
                }
            }
        }

        return $availableFields;
    }

    /**
     * @Route("/prepare-helper-column-configs", name="pimcore_admin_asset_assethelper_preparehelpercolumnconfigs", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function prepareHelperColumnConfigs(Request $request): JsonResponse
    {
        $helperColumns = [];
        $newData = [];
        $data = json_decode($request->get('columns'));
        /** @var \stdClass $item */
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

        Session::useBag($request->getSession(), function (AttributeBagInterface $session) use ($helperColumns) {
            $existingColumns = $session->get('helpercolumns', []);
            $helperColumns = array_merge($helperColumns, $existingColumns);
            $session->set('helpercolumns', $helperColumns);
        }, 'pimcore_gridconfig');

        return $this->adminJson(['success' => true, 'columns' => $newData]);
    }

    /**
     * @Route("/grid-mark-favourite-column-config", name="pimcore_admin_asset_assethelper_gridmarkfavouritecolumnconfig", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function gridMarkFavouriteColumnConfigAction(Request $request): JsonResponse
    {
        $classId = $request->get('classId');
        $asset = Asset::getById($classId);

        if ($asset->isAllowed('list')) {
            $gridConfigId = (int) $request->get('gridConfigId');
            $searchType = $request->get('searchType');
            $type = $request->get('type');
            $user = $this->getAdminUser();

            $favourite = new GridConfigFavourite();
            $favourite->setOwnerId($user->getId());
            $favourite->setClassId($classId);
            $favourite->setSearchType($searchType);
            $favourite->setType($type);
            $specializedConfigs = false;

            try {
                if ($gridConfigId != 0) {
                    $gridConfig = GridConfig::getById($gridConfigId);
                    $favourite->setGridConfigId($gridConfig->getId());
                }

                $favourite->setObjectId(0);
                $favourite->save();
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
     * @Route("/grid-save-column-config", name="pimcore_admin_asset_assethelper_gridsavecolumnconfig", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function gridSaveColumnConfigAction(Request $request): JsonResponse
    {
        $asset = Asset::getById((int) $request->get('id'));

        if (!$asset) {
            throw $this->createNotFoundException();
        }

        if ($asset->isAllowed('list')) {
            try {
                $classId = $request->get('class_id');
                $context = $request->get('context');

                $searchType = $request->get('searchType');
                $type = $request->get('type');

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

                if ($gridConfig && $gridConfig->getOwnerId() != $this->getAdminUser()->getId()) {
                    throw new \Exception("don't mess around with somebody else's configuration");
                }

                $this->updateGridConfigShares($gridConfig, $metadata);

                if ($metadata['setAsFavourite'] && $this->getAdminUser()->isAdmin()) {
                    $this->updateGridConfigFavourites($gridConfig, $metadata);
                }

                if (!$gridConfig) {
                    $gridConfig = new GridConfig();
                    $gridConfig->setName(date('c'));
                    $gridConfig->setClassId($classId);
                    $gridConfig->setSearchType($searchType);
                    $gridConfig->setType($type);

                    $gridConfig->setOwnerId($this->getAdminUser()->getId());
                }

                if ($metadata) {
                    $gridConfig->setName($metadata['gridConfigName']);
                    $gridConfig->setDescription($metadata['gridConfigDescription']);
                    $gridConfig->setShareGlobally($metadata['shareGlobally'] && $this->getAdminUser()->isAdmin());
                    $gridConfig->setSetAsFavourite($metadata['setAsFavourite'] && $this->getAdminUser()->isAdmin());
                }

                $gridConfigData = json_encode($gridConfigData);
                $gridConfig->setConfig($gridConfigData);
                $gridConfig->save();

                $userId = $this->getAdminUser()->getId();

                $availableConfigs = $this->getMyOwnGridColumnConfigs($userId, $classId, $searchType);
                $sharedConfigs = $this->getSharedGridColumnConfigs($this->getAdminUser(), $classId, $searchType);

                $settings = $this->getShareSettings($gridConfig->getId());
                $settings['gridConfigId'] = (int)$gridConfig->getId();
                $settings['gridConfigName'] = $gridConfig->getName();
                $settings['gridConfigDescription'] = $gridConfig->getDescription();
                $settings['shareGlobally'] = $gridConfig->isShareGlobally();
                $settings['setAsFavourite'] = $gridConfig->isSetAsFavourite();
                $settings['isShared'] = $gridConfig->getOwnerId() != $this->getAdminUser()->getId();

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

        if ($gridConfig->getOwnerId() != $this->getAdminUser()->getId()) {
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
     *
     * @throws \Exception
     */
    protected function updateGridConfigFavourites(?GridConfig $gridConfig, array $metadata): void
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
            // Check if the user has already a favourite
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
                        continue;
                    }

                    // Check if the user is the owner. If that is the case we do not update the favourite
                    if ((int) $favouriteGridConfig->getOwnerId() === (int) $id) {
                        continue;
                    }
                }
            }

            $favourite = new GridConfigFavourite();
            $favourite->setGridConfigId($gridConfig->getId());
            $favourite->setClassId($gridConfig->getClassId());
            $favourite->setObjectId(0);
            $favourite->setOwnerId($id);
            $favourite->setType($gridConfig->getType());
            $favourite->setSearchType($gridConfig->getSearchType());
            $favourite->save();
        }
    }

    /**
     * @Route("/get-export-jobs", name="pimcore_admin_asset_assethelper_getexportjobs", methods={"POST"})
     *
     * @param Request $request
     * @param GridHelperService $gridHelperService
     *
     * @return JsonResponse
     */
    public function getExportJobsAction(Request $request, GridHelperService $gridHelperService): JsonResponse
    {
        $allParams = array_merge($request->request->all(), $request->query->all());
        $list = $gridHelperService->prepareAssetListingForGrid($allParams, $this->getAdminUser());

        if (empty($ids = $allParams['ids'] ?? '')) {
            $ids = $list->loadIdList();
        }

        $jobs = array_chunk($ids, 20);

        $fileHandle = uniqid('asset-export-');
        $storage = Storage::get('temp');
        $storage->write($this->getCsvFile($fileHandle), '');

        return $this->adminJson(['success' => true, 'jobs' => $jobs, 'fileHandle' => $fileHandle]);
    }

    /**
     * @Route("/do-export", name="pimcore_admin_asset_assethelper_doexport", methods={"POST"})
     *
     * @param Request $request
     * @param LocaleServiceInterface $localeService
     *
     * @return JsonResponse
     */
    public function doExportAction(Request $request, LocaleServiceInterface $localeService): JsonResponse
    {
        $fileHandle = \Pimcore\File::getValidFilename($request->get('fileHandle'));
        $ids = $request->get('ids');
        $settings = $request->get('settings');
        $settings = json_decode($settings, true);
        $delimiter = $settings['delimiter'] ?? ';';
        $language = str_replace('default', '', $request->get('language'));

        $list = new Asset\Listing();

        $quotedIds = [];
        foreach ($ids as $id) {
            $quotedIds[] = $list->quote($id);
        }

        $list->setCondition('id IN (' . implode(',', $quotedIds) . ')');
        $list->setOrderKey(' FIELD(id, ' . implode(',', $quotedIds) . ')', false);

        $fields = $request->get('fields');

        $addTitles = (bool) $request->get('initial');

        $csv = $this->getCsvData($request, $language, $list, $fields, $addTitles);

        $storage = Storage::get('temp');
        $csvFile = $this->getCsvFile($fileHandle);

        $fileStream = $storage->readStream($csvFile);

        $temp = tmpfile();
        stream_copy_to_stream($fileStream, $temp, null, 0);

        $firstLine = true;
        foreach ($csv as $line) {
            if ($addTitles && $firstLine) {
                $firstLine = false;
                $line = implode($delimiter, $line) . "\r\n";
                fwrite($temp, $line);
            } else {
                fwrite($temp, implode($delimiter, array_map([$this, 'encodeFunc'], $line)) . "\r\n");
            }
        }

        $storage->writeStream($csvFile, $temp);

        return $this->adminJson(['success' => true]);
    }

    public function encodeFunc(?string $value): string
    {
        $value = str_replace('"', '""', $value ?? '');
        //force wrap value in quotes and return
        return '"' . $value . '"';
    }

    protected function getCsvData(Request $request, string $language, Asset\Listing $list, array $fields, bool $addTitles = true): array
    {
        //create csv
        $csv = [];

        $unsupportedFields = ['preview~system', 'size~system'];
        $fields = array_diff($fields, $unsupportedFields);

        if ($addTitles) {
            $columns = $fields;
            foreach ($columns as $columnIdx => $columnKey) {
                $columns[$columnIdx] = '"' . $columnKey . '"';
            }
            $csv[] = $columns;
        }

        foreach ($list->load() as $asset) {
            if ($fields) {
                $dataRows = [];
                foreach ($fields as $field) {
                    $fieldDef = explode('~', $field);
                    $getter = 'get' . ucfirst($fieldDef[0]);

                    if (isset($fieldDef[1])) {
                        if ($fieldDef[1] == 'system' && method_exists($asset, $getter)) {
                            $data = $asset->$getter($language);
                        } else {
                            $fieldDef[1] = str_replace('none', '', $fieldDef[1]);
                            $data = $asset->getMetadata($fieldDef[0], $fieldDef[1], true);
                        }
                    } else {
                        $data = $asset->getMetadata($field, $language, true);
                    }

                    if ($data instanceof Element\ElementInterface) {
                        $data = $data->getRealFullPath();
                    }
                    $dataRows[] = $data;
                }
                $dataRows = Element\Service::escapeCsvRecord($dataRows);
                $csv[] = $dataRows;
            }
        }

        return $csv;
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
     * @Route("/download-csv-file", name="pimcore_admin_asset_assethelper_downloadcsvfile", methods={"GET"})
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
     * @Route("/download-xlsx-file", name="pimcore_admin_asset_assethelper_downloadxlsxfile", methods={"GET"})
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
     * @Route("/get-metadata-for-column-config", name="pimcore_admin_asset_assethelper_getmetadataforcolumnconfig", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getMetadataForColumnConfigAction(Request $request): JsonResponse
    {
        $result = [];

        //default metadata
        $defaultMetadataNames = ['copyright', 'alt', 'title'];
        foreach ($defaultMetadataNames as $defaultMetadata) {
            $defaultColumns[] = ['title' => $defaultMetadata, 'name' => $defaultMetadata, 'datatype' => 'data', 'fieldtype' => 'input'];
        }
        $result['defaultColumns']['nodeLabel'] = 'default_metadata';
        $result['defaultColumns']['nodeType'] = 'image';
        $result['defaultColumns']['children'] = $defaultColumns;

        //predefined metadata
        $list = Metadata\Predefined\Listing::getByTargetType('asset');
        $metadataItems = [];
        $tmp = [];
        foreach ($list as $item) {
            //only allow unique metadata columns with subtypes
            $uniqueKey = $item->getName().'_'.$item->getTargetSubtype();
            if (!in_array($uniqueKey, $tmp) && !in_array($item->getName(), $defaultMetadataNames)) {
                $tmp[] = $uniqueKey;
                $item->expand();
                $name = SecurityHelper::convertHtmlSpecialChars($item->getName());
                $metadataItems[] = [
                    'title' => $name,
                    'name' => $name,
                    'subtype' => $item->getTargetSubtype(),
                    'datatype' => 'data',
                    'fieldtype' => $item->getType(),
                    'config' => $item->getConfig(),
                ];
            }
        }

        $result['metadataColumns']['children'] = $metadataItems;
        $result['metadataColumns']['nodeLabel'] = 'predefined_metadata';
        $result['metadataColumns']['nodeType'] = 'metadata';

        //system columns
        $systemColumnNames = Asset\Service::GRID_SYSTEM_COLUMNS;
        $systemColumns = [];
        foreach ($systemColumnNames as $systemColumn) {
            $systemColumns[] = ['title' => $systemColumn, 'name' => $systemColumn, 'datatype' => 'data', 'fieldtype' => 'system'];
        }
        $result['systemColumns']['nodeLabel'] = 'system_columns';
        $result['systemColumns']['nodeType'] = 'system';
        $result['systemColumns']['children'] = $systemColumns;

        return $this->adminJson($result);
    }

    /**
     * @Route("/get-batch-jobs", name="pimcore_admin_asset_assethelper_getbatchjobs", methods={"GET"})
     *
     *
     */
    public function getBatchJobsAction(Request $request, GridHelperService $gridHelperService): JsonResponse
    {
        if ($request->get('language')) {
            $request->setLocale($request->get('language'));
        }

        $allParams = array_merge($request->request->all(), $request->query->all());
        $list = $gridHelperService->prepareAssetListingForGrid($allParams, $this->getAdminUser());

        $jobs = $list->loadIdList();

        return $this->adminJson(['success' => true, 'jobs' => $jobs]);
    }

    /**
     * @Route("/batch", name="pimcore_admin_asset_assethelper_batch", methods={"PUT"})
     *
     * @param Request $request
     * @param EventDispatcherInterface $eventDispatcher
     *
     * @return JsonResponse
     */
    public function batchAction(Request $request, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        try {
            if ($request->get('data')) {
                $loader = \Pimcore::getContainer()->get('pimcore.implementation_loader.asset.metadata.data');

                $data = $this->decodeJson($request->get('data'), true);

                $updateEvent = new GenericEvent($this, [
                    'data' => $data,
                    'processed' => false,
                ]);

                $eventDispatcher->dispatch($updateEvent, AdminEvents::ASSET_LIST_BEFORE_BATCH_UPDATE);

                $processed = $updateEvent->getArgument('processed');

                if ($processed) {
                    return $this->adminJson(['success' => true]);
                }

                $language = null;
                if (isset($data['language'])) {
                    $language = $data['language'] != 'default' ? $data['language'] : null;
                }

                $asset = Asset::getById($data['job']);

                if ($asset) {
                    if (!$asset->isAllowed('publish')) {
                        throw new \Exception("Permission denied. You don't have the rights to save this asset.");
                    }

                    $metadata = $asset->getMetadata(null, null, false, true);
                    $dirty = false;

                    $name = $data['name'];
                    $value = $data['value'];

                    if ($data['valueType'] == 'object') {
                        $value = $this->decodeJson($value);
                    }

                    $fieldDef = explode('~', $name);
                    $name = $fieldDef[0];
                    if (count($fieldDef) > 1) {
                        $language = ($fieldDef[1] == 'none' ? '' : $fieldDef[1]);
                    }

                    foreach ($metadata as $idx => &$em) {
                        if ($em['name'] == $name && $em['language'] == $language) {
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
                        if (in_array($name, $defaulMetadata)) {
                            $newEm = [
                                'name' => $name,
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
                            $predefined = Metadata\Predefined::getByName($name);
                            if ($predefined && (empty($predefined->getTargetSubtype())
                                    || $predefined->getTargetSubtype() == $asset->getType())) {
                                $newEm = [
                                    'name' => $name,
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

                    try {
                        if ($dirty) {
                            // $metadata = Asset\Service::minimizeMetadata($metadata, "grid");
                            $asset->setMetadataRaw($metadata);
                            $asset->save();

                            return $this->adminJson(['success' => true]);
                        }
                    } catch (\Exception $e) {
                        return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
                    }
                } else {
                    Logger::debug('AssetHelperController::batchAction => There is no asset left to update.');

                    return $this->adminJson(['success' => false, 'message' => 'AssetHelperController::batchAction => There is no asset left to update.']);
                }
            }
        } catch (\Exception $e) {
            Logger::err((string)$e);

            return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
        }

        return $this->adminJson(['success' => false, 'message' => 'something went wrong.']);
    }
}
