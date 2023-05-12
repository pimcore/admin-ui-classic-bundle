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
use Pimcore\Controller\KernelControllerEventInterface;
use Pimcore\Db;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\ClassDefinition\Data\LayoutDefinitionEnrichmentInterface;
use Pimcore\Model\DataObject\Classificationstore;
use Pimcore\Model\Translation;
use Pimcore\Model\Translation\Listing;
use Pimcore\Model\User;
use Pimcore\Security\SecurityHelper;
use Pimcore\Tool\Admin;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/classificationstore", name="pimcore_admin_dataobject_classificationstore_")
 *
 * @internal
 */
class ClassificationstoreController extends AdminAbstractController implements KernelControllerEventInterface
{
    /**
     * @Route("/delete-collection", name="deletecollection", methods={"DELETE"})
     */
    public function deleteCollectionAction(Request $request): JsonResponse
    {
        $this->checkPermission('classificationstore');

        $id = $request->request->getInt('id');

        $configRelations = new Classificationstore\CollectionGroupRelation\Listing();
        $configRelations->setCondition('colId = ?', $id);
        $list = $configRelations->load();
        foreach ($list as $item) {
            $item->delete();
        }

        $config = Classificationstore\CollectionConfig::getById($id);
        $config->delete();

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/delete-collection-relation", name="deletecollectionrelation", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function deleteCollectionRelationAction(Request $request): JsonResponse
    {
        $this->checkPermission('classificationstore');

        $colId = $request->request->getInt('colId');
        $groupId = $request->request->getInt('groupId');

        $config = new Classificationstore\CollectionGroupRelation();
        $config->setColId($colId);
        $config->setGroupId($groupId);

        $config->delete();

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/delete-relation", name="deleterelation", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function deleteRelationAction(Request $request): JsonResponse
    {
        $this->checkPermission('classificationstore');

        $keyId = (int) $request->get('keyId');
        $groupId = (int) $request->get('groupId');

        $config = new Classificationstore\KeyGroupRelation();
        $config->setKeyId($keyId);
        $config->setGroupId($groupId);

        $config->delete();

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/delete-group", name="deletegroup", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function deleteGroupAction(Request $request): JsonResponse
    {
        $this->checkPermission('classificationstore');

        $id = $request->request->getInt('id');

        $config = Classificationstore\GroupConfig::getById($id);
        $config->delete();

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/create-group", name="creategroup", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function createGroupAction(Request $request): JsonResponse
    {
        $this->checkPermission('classificationstore');

        $name = SecurityHelper::convertHtmlSpecialChars($request->get('name'));
        $storeId = (int) $request->get('storeId');
        $config = Classificationstore\GroupConfig::getByName($name, $storeId);

        if (!$config) {
            $config = new Classificationstore\GroupConfig();
            $config->setStoreId($storeId);
            $config->setName($name);
            $config->save();

            return $this->adminJson(['success' => true, 'id' => $config->getName()]);
        } else {
            return $this->adminJson(['success' => false, 'id' => $config->getName(), 'message' => 'classificationstore_error_group_exists_msg']);
        }
    }

    /**
     * @Route("/create-store", name="createstore", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function createStoreAction(Request $request): JsonResponse
    {
        $this->checkPermission('classificationstore');

        $name = SecurityHelper::convertHtmlSpecialChars($request->get('name'));

        $config = Classificationstore\StoreConfig::getByName($name);

        if (!$config) {
            $config = new Classificationstore\StoreConfig();
            $config->setName($name);
            $config->save();
        } else {
            throw new \Exception('Store with the given name exists');
        }

        return $this->adminJson(['success' => true, 'storeId' => $config->getId()]);
    }

    /**
     * @Route("/create-collection", name="createcollection", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function createCollectionAction(Request $request): JsonResponse
    {
        $this->checkPermission('classificationstore');

        $name = SecurityHelper::convertHtmlSpecialChars($request->get('name'));
        $storeId = (int) $request->get('storeId');
        $config = Classificationstore\CollectionConfig::getByName($name, $storeId);

        if (!$config) {
            $config = new Classificationstore\CollectionConfig();
            $config->setName($name);
            $config->setStoreId($storeId);
            $config->save();
        }

        return $this->adminJson(['success' => true, 'id' => $config->getName()]);
    }

    /**
     * @Route("/collections", name="collectionsactionget", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function collectionsActionGet(Request $request): JsonResponse
    {
        $this->checkPermission('objects');

        $start = 0;
        $limit = $request->get('limit') ? (int) $request->get('limit') : 15;

        $orderKey = 'name';
        $order = 'ASC';

        if ($request->get('dir')) {
            $order = $request->get('dir');
        }

        if ($request->get('start')) {
            $start = (int) $request->get('start');
        }

        $allParams = array_merge($request->request->all(), $request->query->all());
        $sortingSettings = \Pimcore\Bundle\AdminBundle\Helper\QueryParams::extractSortingSettings($allParams);
        if ($sortingSettings['orderKey'] && $sortingSettings['order']) {
            $orderKey = $sortingSettings['orderKey'];
            $order = $sortingSettings['order'];
        }

        if ($request->query->getBoolean('overrideSort')) {
            $orderKey = 'id';
            $order = 'DESC';
        }

        $storeIdFromDefinition = 0;
        $allowedCollectionIds = [];
        if ($oid = $request->get('oid')) {
            $object = DataObject\Concrete::getById((int) $oid);
            $class = $object->getClass();
            /** @var DataObject\ClassDefinition\Data\Classificationstore $fd */
            $fd = $class->getFieldDefinition($request->get('fieldname'));
            $allowedGroupIds = $fd->getAllowedGroupIds();

            if ($allowedGroupIds) {
                $db = \Pimcore\Db::get();
                $query = 'select * from classificationstore_collectionrelations where groupId in (' . implode(',', $allowedGroupIds) .')';
                $relationList = $db->fetchAllAssociative($query);

                if (is_array($relationList)) {
                    foreach ($relationList as $item) {
                        $allowedCollectionIds[] = $item['colId'];
                    }
                }
            }

            $storeIdFromDefinition = $fd->getStoreId();
        }

        $list = new Classificationstore\CollectionConfig\Listing();

        $list->setLimit($limit);
        $list->setOffset($start);
        $list->setOrder($order);
        $list->setOrderKey($orderKey);

        $conditionParts = [];
        $db = Db::get();

        $searchfilter = $request->get('searchfilter');
        if ($searchfilter) {
            $searchFilterConditions = [];

            $searchTerms = array_merge([$searchfilter], $this->getTranslatedSearchFilterTerms($searchfilter));
            foreach ($searchTerms as $searchFilterTerm) {
                $searchFilterConditions[] = 'name LIKE '.$db->quote('%'.$searchFilterTerm.'%').' OR description LIKE '.$db->quote('%'.$searchFilterTerm.'%');
            }

            $conditionParts[] = '('.implode(' OR ', $searchFilterConditions).')';
        }

        $storeId = $request->get('storeId');
        $storeId = $storeId ? (int) $storeId : $storeIdFromDefinition;

        $conditionParts[] = ' (storeId = ' . $db->quote($storeId) . ')';

        if ($request->get('filter')) {
            $filterString = $request->get('filter');
            $filters = json_decode($filterString);
            /** @var \stdClass $f */
            foreach ($filters as $f) {
                if (!isset($f->value)) {
                    continue;
                }

                $conditionParts[] = $db->quoteIdentifier($f->property) . ' LIKE ' . $db->quote('%' . $f->value . '%');
            }
        }

        if ($allowedCollectionIds) {
            $conditionParts[] = ' id in (' . implode(',', $allowedCollectionIds) . ')';
        }

        $condition = implode(' AND ', $conditionParts);

        $list->setCondition($condition);

        $list->load();
        $configList = $list->getList();

        $rootElement = [];

        $data = [];
        foreach ($configList as $config) {
            $name = $config->getName();
            if (!$name) {
                $name = 'EMPTY';
            }
            $item = [
                'storeId' => $config->getStoreId(),
                'id' => $config->getId(),
                'name' => $name,
                'description' => $config->getDescription(),
            ];
            if ($config->getCreationDate()) {
                $item['creationDate'] = $config->getCreationDate();
            }

            if ($config->getModificationDate()) {
                $item['modificationDate'] = $config->getModificationDate();
            }

            $data[] = $item;
        }
        $rootElement['data'] = $data;
        $rootElement['success'] = true;
        $rootElement['total'] = $list->getTotalCount();

        return $this->adminJson($rootElement);
    }

    /**
     * @Route("/collections", name="collections", methods={"POST", "PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function collectionsAction(Request $request): JsonResponse
    {
        if ($request->get('data')) {
            $dataParam = $request->get('data');
            $data = $this->decodeJson($dataParam);

            $id = $data['id'];
            $config = Classificationstore\CollectionConfig::getById($id);

            foreach ($data as $key => $value) {
                if ($key != 'id') {
                    $setter = 'set' . $key;
                    $config->$setter($value);
                }
            }

            $config->save();

            return $this->adminJson(['success' => true, 'data' => $this->getConfigItem($config)]);
        }

        return $this->adminJson(['success' => false]);
    }

    /**
     * @Route("/groups", name="groupsactionget", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function groupsActionGet(Request $request): JsonResponse
    {
        $this->checkPermission('objects');

        $start = 0;
        $limit = 15;
        $orderKey = 'name';
        $order = 'ASC';

        if ($request->get('dir')) {
            $order = $request->get('dir');
        }

        if ($request->get('sort')) {
            $orderKey = $request->get('sort');
        }

        if ($request->get('limit')) {
            $limit = (int) $request->get('limit');
        }
        if ($request->get('start')) {
            $start = (int) $request->get('start');
        }

        $allParams = array_merge($request->request->all(), $request->query->all());
        $sortingSettings = \Pimcore\Bundle\AdminBundle\Helper\QueryParams::extractSortingSettings($allParams);
        if ($sortingSettings['orderKey'] && $sortingSettings['order']) {
            $orderKey = $sortingSettings['orderKey'];
            $order = $sortingSettings['order'];
        }

        if ($request->query->getBoolean('overrideSort')) {
            $orderKey = 'id';
            $order = 'DESC';
        }

        $list = new Classificationstore\GroupConfig\Listing();

        $list->setLimit($limit);
        $list->setOffset($start);
        $list->setOrder($order);
        $list->setOrderKey($orderKey);

        $conditionParts = [];
        $db = Db::get();

        $searchfilter = $request->get('searchfilter');
        if ($searchfilter) {
            $searchFilterConditions = [];

            $searchTerms = array_merge([$searchfilter], $this->getTranslatedSearchFilterTerms($searchfilter));
            foreach ($searchTerms as $searchFilterTerm) {
                $searchFilterConditions[] = 'name LIKE '.$db->quote('%'.$searchFilterTerm.'%').' OR description LIKE '.$db->quote('%'.$searchFilterTerm.'%');
            }

            $conditionParts[] = '('.implode(' OR ', $searchFilterConditions).')';
        }

        if ($storeId = $request->query->getInt('storeId')) {
            $conditionParts[] = '(storeId = ' . $db->quote($storeId) . ')';
        }

        if ($request->get('filter')) {
            $filterString = $request->get('filter');
            $filters = json_decode($filterString);
            /** @var \stdClass $f */
            foreach ($filters as $f) {
                if (!isset($f->value)) {
                    continue;
                }

                $conditionParts[] = $db->quoteIdentifier($f->property) . ' LIKE ' . $db->quote('%' . $f->value . '%');
            }
        }

        if ($oid = $request->get('oid')) {
            $object = DataObject\Concrete::getById((int) $oid);
            $class = $object->getClass();
            /** @var DataObject\ClassDefinition\Data\Classificationstore $fd */
            $fd = $class->getFieldDefinition($request->get('fieldname'));
            $allowedGroupIds = $fd->getAllowedGroupIds();

            if ($allowedGroupIds) {
                $conditionParts[] = 'ID in (' . implode(',', $allowedGroupIds) . ')';
            }
        }

        $condition = implode(' AND ', $conditionParts);
        $list->setCondition($condition);

        $list->load();
        $configList = $list->getList();

        $rootElement = [];

        $data = [];
        foreach ($configList as $config) {
            $name = $config->getName();
            if (!$name) {
                $name = 'EMPTY';
            }
            $item = [
                'storeId' => $config->getStoreId(),
                'id' => $config->getId(),
                'name' => $name,
                'description' => $config->getDescription(),
            ];
            if ($config->getCreationDate()) {
                $item['creationDate'] = $config->getCreationDate();
            }

            if ($config->getModificationDate()) {
                $item['modificationDate'] = $config->getModificationDate();
            }

            $data[] = $item;
        }
        $rootElement['data'] = $data;
        $rootElement['success'] = true;
        $rootElement['total'] = $list->getTotalCount();

        return $this->adminJson($rootElement);
    }

    /**
     * @Route("/groups", name="groupsaction", methods={"POST", "PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function groupsAction(Request $request): JsonResponse
    {
        if ($request->get('data')) {
            $dataParam = $request->get('data');
            $data = $this->decodeJson($dataParam);

            $id = $data['id'];
            $config = Classificationstore\GroupConfig::getById($id);

            foreach ($data as $key => $value) {
                if ($key != 'id') {
                    $setter = 'set' . $key;
                    $config->$setter($value);
                }
            }

            $config->save();

            return $this->adminJson(['success' => true, 'data' => $this->getConfigItem($config)]);
        }

        return $this->adminJson(['success' => false]);
    }

    /**
     * @Route("/collection-relations", name="collectionrelationsget", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function collectionRelationsGetAction(Request $request): JsonResponse
    {
        $mapping = ['groupName' => 'name', 'groupDescription' => 'description'];

        $start = 0;
        $limit = 15;
        $orderKey = 'sorter';
        $order = 'ASC';

        if ($request->get('dir')) {
            $order = $request->get('dir');
        }

        $allParams = array_merge($request->request->all(), $request->query->all());
        $sortingSettings = \Pimcore\Bundle\AdminBundle\Helper\QueryParams::extractSortingSettings($allParams);
        if ($sortingSettings['orderKey'] && $sortingSettings['order']) {
            $orderKey = $sortingSettings['orderKey'];
            $order = $sortingSettings['order'];
        }

        if ($request->query->getBoolean('overrideSort')) {
            $orderKey = 'id';
            $order = 'DESC';
        }

        if ($request->get('limit')) {
            $limit = (int) $request->get('limit');
        }
        if ($request->get('start')) {
            $start = (int) $request->get('start');
        }

        $list = new Classificationstore\CollectionGroupRelation\Listing();

        if ($limit > 0) {
            $list->setLimit($limit);
        }
        $list->setOffset($start);
        $list->setOrder($order);
        $list->setOrderKey($orderKey);
        $condition = '';

        if ($request->get('filter')) {
            $db = Db::get();
            $filterString = $request->get('filter');
            $filters = json_decode($filterString);

            $count = 0;
            /** @var \stdClass $f */
            foreach ($filters as $f) {
                if (!isset($f->value)) {
                    continue;
                }

                if ($count > 0) {
                    $condition .= ' AND ';
                }
                $count++;
                $fieldname = $mapping[$f->field];
                $condition .= $db->quoteIdentifier($fieldname) . ' LIKE ' . $db->quote('%' . $f->value . '%');
            }
        }

        $colId = $request->query->getInt('colId');
        if ($condition) {
            $condition = '( ' . $condition . ' ) AND';
        }
        $condition .= ' colId = ' . $list->quote($colId);

        $list->setCondition($condition);

        $listItems = $list->load();

        $rootElement = [];

        $data = [];
        foreach ($listItems as $config) {
            $item = [
                'colId' => $config->getColId(),
                'groupId' => $config->getGroupId(),
                'groupName' => $config->getName(),
                'groupDescription' => $config->getDescription(),
                'id' => $config->getColId() . '-' . $config->getGroupId(),
                'sorter' => $config->getSorter(),
            ];
            $data[] = $item;
        }
        $rootElement['data'] = $data;
        $rootElement['success'] = true;
        $rootElement['total'] = $list->getTotalCount();

        return $this->adminJson($rootElement);
    }

    /**
     * @Route("/collection-relations", name="collectionrelations", methods={"POST", "PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function collectionRelationsAction(Request $request): JsonResponse
    {
        if ($request->get('data')) {
            $dataParam = $request->get('data');
            $data = $this->decodeJson($dataParam);

            if (count($data) == count($data, 1)) {
                $data = [$data];
            }

            foreach ($data as &$row) {
                $colId = $row['colId'];
                $groupId = $row['groupId'];
                $sorter = $row['sorter'];

                $config = new Classificationstore\CollectionGroupRelation();
                $config->setGroupId($groupId);
                $config->setColId($colId);
                $config->setSorter((int) $sorter);

                $config->save();

                $row['id'] = $config->getColId() . '-' . $config->getGroupId();
            }

            return $this->adminJson(['success' => true, 'data' => $data]);
        }

        return $this->adminJson(['success' => false]);
    }

    /**
     * @Route("/list-stores", name="liststores", methods={"GET"})
     *
     * @return JsonResponse
     */
    public function listStoresAction(): JsonResponse
    {
        $storeConfigs = [];
        $storeConfigListing = new Classificationstore\StoreConfig\Listing();
        $storeConfigListing->load();

        foreach ($storeConfigListing as $storeConfig) {
            $storeConfigs[] = $storeConfig->getObjectVars();
        }

        return $this->adminJson($storeConfigs);
    }

    /**
     * @Route("/search-relations", name="searchrelations", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function searchRelationsAction(Request $request): JsonResponse
    {
        $db = Db::get();

        $storeId = $request->get('storeId');

        $mapping = [
            'groupName' => DataObject\Classificationstore\GroupConfig\Dao::TABLE_NAME_GROUPS .'.name',
            'keyName' => DataObject\Classificationstore\KeyConfig\Dao::TABLE_NAME_KEYS .'.name',
            'keyDescription' => DataObject\Classificationstore\KeyConfig\Dao::TABLE_NAME_KEYS. '.description',
        ];

        $start = 0;
        $limit = 15;
        $orderKey = 'name';
        $order = 'ASC';

        if ($request->get('dir')) {
            $order = $request->get('dir');
        }

        $allParams = array_merge($request->request->all(), $request->query->all());
        $sortingSettings = \Pimcore\Bundle\AdminBundle\Helper\QueryParams::extractSortingSettings($allParams);
        if ($sortingSettings['orderKey'] && $sortingSettings['order']) {
            $orderKey = $sortingSettings['orderKey'];
            if ($orderKey == 'keyName') {
                $orderKey = 'name';
            }
            $order = $sortingSettings['order'];
        }

        if ($request->query->getBoolean('overrideSort')) {
            $orderKey = 'id';
            $order = 'DESC';
        }

        if ($request->get('limit')) {
            $limit = (int) $request->get('limit');
        }
        if ($request->get('start')) {
            $start = (int) $request->get('start');
        }

        $list = new Classificationstore\KeyGroupRelation\Listing();

        if ($limit > 0) {
            $list->setLimit($limit);
        }
        $list->setOffset($start);
        $list->setOrder($order);
        $list->setOrderKey($orderKey);

        $conditionParts = [];

        if ($request->get('filter')) {
            $db = Db::get();
            $filterString = $request->get('filter');
            $filters = json_decode($filterString);
            /** @var \stdClass $f */
            foreach ($filters as $f) {
                if (!isset($f->value)) {
                    continue;
                }

                $fieldname = $mapping[$f->property];
                $conditionParts[] = $fieldname . ' LIKE ' . $db->quote('%' . $f->value . '%');
            }
        }

        $conditionParts[] = '  groupId IN (select id from classificationstore_groups where storeId = ' . $db->quote($storeId) . ')';

        $searchfilter = $request->get('searchfilter');
        if ($searchfilter) {
            $searchFilterConditions = [];

            $searchTerms = array_merge([$searchfilter], $this->getTranslatedSearchFilterTerms($searchfilter));
            foreach ($searchTerms as $searchFilterTerm) {
                $searchFilterConditions[] = Classificationstore\KeyConfig\Dao::TABLE_NAME_KEYS.'.name LIKE '.$db->quote('%'.$searchFilterTerm.'%')
                    .' OR '.Classificationstore\GroupConfig\Dao::TABLE_NAME_GROUPS.'.name LIKE '.$db->quote('%'.$searchFilterTerm.'%')
                    .' OR '.Classificationstore\KeyConfig\Dao::TABLE_NAME_KEYS.'.description LIKE '.$db->quote('%'.$searchFilterTerm.'%');
            }

            $conditionParts[] = '('.implode(' OR ', $searchFilterConditions).')';
        }

        $condition = implode(' AND ', $conditionParts);
        $list->setCondition($condition);
        $list->setResolveGroupName(true);

        $rootElement = [];

        $data = [];
        foreach ($list->getList() as $config) {
            $item = [
                'keyId' => $config->getKeyId(),
                'groupId' => $config->getGroupId(),
                'keyName' => $config->getName(),
                'keyDescription' => $config->getDescription(),
                'id' => $config->getGroupId() . '-' . $config->getKeyId(),
                'sorter' => $config->getSorter(),
            ];

            $groupConfig = Classificationstore\GroupConfig::getById($config->getGroupId());
            if ($groupConfig) {
                $item['groupName'] = $groupConfig->getName();
            }

            $data[] = $item;
        }
        $rootElement['data'] = $data;
        $rootElement['success'] = true;
        $rootElement['total'] = $list->getTotalCount();

        return $this->adminJson($rootElement);
    }

    /**
     * @Route("/relations", name="relationsactionget", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function relationsActionGet(Request $request): JsonResponse
    {
        $mapping = ['keyName' => 'name', 'keyDescription' => 'description'];

        $start = 0;
        $limit = 15;
        $orderKey = 'name';
        $order = 'ASC';
        $relationIds = $request->get('relationIds');

        if ($relationIds) {
            $relationIds = json_decode($relationIds, true);
        }

        if ($request->get('dir')) {
            $order = $request->get('dir');
        }

        $allParams = array_merge($request->request->all(), $request->query->all());
        $sortingSettings = \Pimcore\Bundle\AdminBundle\Helper\QueryParams::extractSortingSettings($allParams);

        if ($sortingSettings['orderKey'] && $sortingSettings['order']) {
            $orderKey = $sortingSettings['orderKey'];
            $order = $sortingSettings['order'];
        }

        if ($request->query->getBoolean('overrideSort')) {
            $orderKey = 'id';
            $order = 'DESC';
        }

        if ($request->get('limit')) {
            $limit = (int) $request->get('limit');
        } elseif (is_array($relationIds)) {
            $limit = count($relationIds);
        }

        if ($request->get('start')) {
            $start = (int) $request->get('start');
        }

        $list = new Classificationstore\KeyGroupRelation\Listing();

        if ($limit > 0) {
            $list->setLimit($limit);
        }

        $list->setOffset($start);
        $list->setOrder($order);
        $list->setOrderKey($orderKey);
        $conditionParts = [];

        if ($request->get('filter')) {
            $db = Db::get();
            $filterString = $request->get('filter');
            $filters = json_decode($filterString);
            /** @var \stdClass $f */
            foreach ($filters as $f) {
                if (!isset($f->value)) {
                    continue;
                }

                $fieldname = $mapping[$f->field];
                $conditionParts[] = $db->quoteIdentifier($fieldname) . ' LIKE ' . $db->quote('%' . $f->value . '%');
            }
        }

        if (!$request->get('relationIds')) {
            $groupId = $request->get('groupId');
            $conditionParts[] = ' groupId = ' . $list->quote($groupId);
        }

        if ($relationIds) {
            $relationParts = [];

            foreach ($relationIds as $relationId) {
                $keyId = $relationId['keyId'];
                $groupId = $relationId['groupId'];
                $relationParts[] = '(keyId = ' . $list->quote($keyId) . ' AND groupId = ' . $list->quote($groupId) . ')';
            }

            $conditionParts[] = '(' . implode(' OR ', $relationParts) . ')';
        }

        $condition = implode(' AND ', $conditionParts);

        $list->setCondition($condition);

        $listItems = $list->load();

        $rootElement = [];

        $data = [];
        foreach ($listItems as $config) {
            $type = $config->getType();
            $definition = json_decode($config->getDefinition(), true);
            $definition = \Pimcore\Model\DataObject\Classificationstore\Service::getFieldDefinitionFromJson($definition, $type);
            DataObject\Service::enrichLayoutDefinition($definition);

            $item = [
                'keyId' => $config->getKeyId(),
                'groupId' => $config->getGroupId(),
                'keyName' => $config->getName(),
                'keyDescription' => $config->getDescription(),
                'id' => $config->getGroupId() . '-' . $config->getKeyId(),
                'sorter' => $config->getSorter(),
                'layout' => $definition,
                'mandatory' => $config->isMandatory(),
            ];

            $data[] = $item;
        }
        $rootElement['data'] = $data;
        $rootElement['success'] = true;
        $rootElement['total'] = $list->getTotalCount();

        return $this->adminJson($rootElement);
    }

    /**
     * @Route("/relations", name="relations", methods={"POST", "PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function relationsAction(Request $request): JsonResponse
    {
        if ($request->get('data')) {
            $dataParam = $request->get('data');
            $data = $this->decodeJson($dataParam);

            $keyId = $data['keyId'];
            $groupId = $data['groupId'];
            $sorter = $data['sorter'];
            $mandatory = $data['mandatory'];

            $config = new Classificationstore\KeyGroupRelation();
            $config->setGroupId((int) $groupId);
            $config->setKeyId((int) $keyId);
            $config->setSorter($sorter);
            $config->setMandatory($mandatory);

            $config->save();
            $data['id'] = $config->getGroupId() . '-' . $config->getKeyId();

            return $this->adminJson(['success' => true, 'data' => $data]);
        }

        return $this->adminJson(['success' => false]);
    }

    /**
     * @Route("/add-collections", name="addcollections", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function addCollectionsAction(Request $request): JsonResponse
    {
        $this->checkPermission('objects');

        $ids = $this->decodeJson($request->get('collectionIds'));
        $data = [];

        if ($ids) {
            $db = \Pimcore\Db::get();
            $mappedData = [];
            $groupsData = $db->fetchAllAssociative('select * from classificationstore_groups g, classificationstore_collectionrelations c where colId IN (:ids) and g.id = c.groupId', [
                'ids' => implode(',', array_filter($ids, 'intval')),
            ]);

            foreach ($groupsData as $groupData) {
                $mappedData[$groupData['id']] = $groupData;
            }

            $groupIdList = [];
            $groupId = null;

            $allowedGroupIds = null;

            $oid = $request->request->getInt('oid');
            $object = DataObject\Concrete::getById($oid);
            if ($object) {
                $class = $object->getClass();
                /** @var DataObject\ClassDefinition\Data\Classificationstore $fd */
                $fd = $class->getFieldDefinition($request->get('fieldname'));
                $allowedGroupIds = $fd->getAllowedGroupIds();
            }

            foreach ($groupsData as $groupItem) {
                $groupId = $groupItem['groupId'];
                if (!$allowedGroupIds || in_array($groupId, $allowedGroupIds)) {
                    $groupIdList[] = $groupId;
                }
            }

            if ($groupIdList) {
                $fieldname = $request->get('fieldname');
                $groupList = new Classificationstore\GroupConfig\Listing();
                $groupCondition = 'id in (' . implode(',', $groupIdList) . ')';
                $groupList->setCondition($groupCondition);

                $groupList = $groupList->load();

                $keyCondition = 'groupId in (' . implode(',', $groupIdList) . ')';

                $keyList = new Classificationstore\KeyGroupRelation\Listing();
                $keyList->setCondition($keyCondition);
                $keyList->setOrderKey(['sorter', 'id']);
                $keyList->setOrder(['ASC', 'ASC']);
                $keyList = $keyList->load();

                foreach ($groupList as $groupData) {
                    $data[$groupData->getId()] = [
                        'name' => $groupData->getName(),
                        'id' => $groupData->getId(),
                        'description' => $groupData->getDescription(),
                        'keys' => [],
                        'sorter' => intval($mappedData[$groupData->getId()]['sorter']),
                        'collectionId' => $mappedData[$groupId]['colId'],
                    ];
                }

                foreach ($keyList as $keyData) {
                    $groupId = $keyData->getGroupId();

                    $keyList = $data[$groupId]['keys'];
                    $type = $keyData->getType();
                    $definition = json_decode($keyData->getDefinition(), true);
                    $definition = \Pimcore\Model\DataObject\Classificationstore\Service::getFieldDefinitionFromJson($definition, $type);

                    if (method_exists($definition, '__wakeup')) {
                        $definition->__wakeup();
                    }

                    $context['object'] = $object;
                    $context['class'] = $object ? $object->getClass() : null;
                    $context['ownerType'] = 'classificationstore';
                    $context['ownerName'] = $fieldname;
                    $context['keyId'] = $keyData->getKeyId();
                    $context['groupId'] = $groupId;
                    $context['keyDefinition'] = $definition;

                    if ($definition instanceof LayoutDefinitionEnrichmentInterface) {
                        $definition = $definition->enrichLayoutDefinition($object, $context);
                    }

                    $keyList[] = [
                        'name' => $keyData->getName(),
                        'id' => $keyData->getKeyId(),
                        'description' => $keyData->getDescription(),
                        'definition' => $definition,
                    ];
                    $data[$groupId]['keys'] = $keyList;
                }
            }
        }

        return $this->adminJson($data);
    }

    /**
     * @Route("/add-groups", name="addgroups", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function addGroupsAction(Request $request): JsonResponse
    {
        $this->checkPermission('objects');

        $ids = $this->decodeJson($request->get('groupIds'));
        $oid = $request->request->getInt('oid');
        $object = DataObject\Concrete::getById($oid);
        $fieldname = $request->get('fieldname');

        $keyCondition = 'groupId in (' . implode(',', array_fill(0, count($ids), '?')) . ')';

        $keyList = new Classificationstore\KeyGroupRelation\Listing();
        $keyList->setCondition($keyCondition, $ids);
        $keyList->setOrderKey(['sorter', 'id']);
        $keyList->setOrder(['ASC', 'ASC']);
        $keyList = $keyList->load();

        $groupCondition = 'id in (' . implode(',', array_fill(0, count($ids), '?')) . ')';

        $groupList = new Classificationstore\GroupConfig\Listing();
        $groupList->setCondition($groupCondition, $ids);
        $groupList->setOrder('ASC');
        $groupList->setOrderKey('id');
        $groupList = $groupList->load();

        $data = [];

        foreach ($groupList as $groupData) {
            $data[$groupData->getId()] = [
                'name' => $groupData->getName(),
                'id' => $groupData->getId(),
                'description' => $groupData->getDescription(),
                'keys' => [],
            ];
        }

        foreach ($keyList as $keyData) {
            $groupId = $keyData->getGroupId();

            $keyList = $data[$groupId]['keys'];
            $type = $keyData->getType();
            $definition = json_decode($keyData->getDefinition(), true);
            $definition = \Pimcore\Model\DataObject\Classificationstore\Service::getFieldDefinitionFromJson($definition, $type);

            if (method_exists($definition, '__wakeup')) {
                $definition->__wakeup();
            }

            $context['object'] = $object;
            $context['class'] = $object ? $object->getClass() : null;
            $context['ownerType'] = 'classificationstore';
            $context['ownerName'] = $fieldname;
            $context['keyId'] = $keyData->getKeyId();
            $context['groupId'] = $groupId;
            $context['keyDefinition'] = $definition;

            if ($definition instanceof LayoutDefinitionEnrichmentInterface) {
                $definition = $definition->enrichLayoutDefinition($object, $context);
            }

            $keyList[] = [
                'name' => $keyData->getName(),
                'id' => $keyData->getKeyId(),
                'description' => $keyData->getDescription(),
                'definition' => $definition,
            ];
            $data[$groupId]['keys'] = $keyList;
        }

        return $this->adminJson($data);
    }

    /**
     * @Route("/properties", name="propertiesget", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function propertiesGetAction(Request $request): JsonResponse
    {
        $storeId = (int) $request->get('storeId');
        $frameName = $request->get('frameName');
        $db = \Pimcore\Db::get();

        $conditionParts = [];

        if ($frameName) {
            $keyCriteria = ' FALSE ';
            $frameConfig = Classificationstore\CollectionConfig::getByName($frameName, $storeId);
            if ($frameConfig) {
                // get all keys within that collection / frame
                $frameId = $frameConfig->getId();
                $groupList = new Classificationstore\CollectionGroupRelation\Listing();
                $groupList->setCondition('colId = ' . $db->quote($frameId));
                $groupList = $groupList->load();
                $groupIdList = [];
                foreach ($groupList as $groupEntry) {
                    $groupIdList[] = $groupEntry->getGroupId();
                }

                if ($groupIdList) {
                    $keyIdList = new Classificationstore\KeyGroupRelation\Listing();
                    $keyIdList->setCondition('groupId in (' . implode(',', $groupIdList) . ')');
                    $keyIdList = $keyIdList->load();
                    if ($keyIdList) {
                        $keyIds = [];
                        foreach ($keyIdList as $keyEntry) {
                            $keyIds[] = $keyEntry->getKeyId();
                        }

                        $keyCriteria = ' id in (' . implode(',', $keyIds) . ')';
                    }
                }
            }

            $conditionParts[] = $keyCriteria;
        }

        $start = 0;
        $limit = 15;
        $orderKey = 'name';
        $order = 'ASC';

        if ($request->get('dir')) {
            $order = $request->get('dir');
        }

        $allParams = array_merge($request->request->all(), $request->query->all());
        $sortingSettings = \Pimcore\Bundle\AdminBundle\Helper\QueryParams::extractSortingSettings($allParams);
        if ($sortingSettings['orderKey'] && $sortingSettings['order']) {
            $orderKey = $sortingSettings['orderKey'];
            $order = $sortingSettings['order'];
        }

        if ($request->query->getBoolean('overrideSort')) {
            $orderKey = 'id';
            $order = 'DESC';
        }

        if ($request->get('limit')) {
            $limit = (int) $request->get('limit');
        }
        if ($request->get('start')) {
            $start = (int) $request->get('start');
        }

        $list = new Classificationstore\KeyConfig\Listing();

        if ($limit > 0 && !$request->get('groupIds') && !$request->get('keyIds')) {
            $list->setLimit($limit);
        }
        $list->setOffset($start);
        $list->setOrder($order);
        $list->setOrderKey($orderKey);

        $searchfilter = $request->get('searchfilter');
        if ($searchfilter) {
            $conditionParts[] = '(name LIKE ' . $db->quote('%' . $searchfilter . '%') . ' OR description LIKE ' . $db->quote('%'. $searchfilter . '%') . ')';
        }

        if ($storeId) {
            $conditionParts[] = '(storeId = '. $db->quote($storeId) . ')';
        }

        if ($request->get('filter')) {
            $filterString = $request->get('filter');
            $filters = json_decode($filterString);
            /** @var \stdClass $f */
            foreach ($filters as $f) {
                if (!isset($f->value)) {
                    continue;
                }

                $conditionParts[] = $db->quoteIdentifier($f->property) . ' LIKE ' . $db->quote('%' . $f->value . '%');
            }
        }
        $condition = implode(' AND ', $conditionParts);
        $list->setCondition($condition);

        if ($request->get('groupIds') || $request->get('keyIds')) {
            $db = Db::get();

            if ($request->get('groupIds')) {
                $ids = $this->decodeJson($request->get('groupIds'));
                $col = 'group';
            } else {
                $ids = $this->decodeJson($request->get('keyIds'));
                $col = 'id';
            }

            $condition = $db->quoteIdentifier($col) . ' IN (';
            $count = 0;
            foreach ($ids as $theId) {
                if ($count > 0) {
                    $condition .= ',';
                }
                $condition .= $theId;
                $count++;
            }

            $condition .= ')';
            $list->setCondition($condition);
        }

        $list->load();
        $configList = $list->getList();

        $rootElement = [];

        $data = [];
        foreach ($configList as $config) {
            $item = $this->getKeyConfigItem($config);
            $data[] = $item;
        }
        $rootElement['data'] = $data;
        $rootElement['success'] = true;
        $rootElement['total'] = $list->getTotalCount();

        return $this->adminJson($rootElement);
    }

    /**
     * @Route("/properties", name="properties", methods={"POST", "PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function propertiesAction(Request $request): JsonResponse
    {
        if ($request->get('data')) {
            $dataParam = $request->get('data');
            $data = $this->decodeJson($dataParam);

            $id = $data['id'];
            $config = Classificationstore\KeyConfig::getById($id);

            foreach ($data as $key => $value) {
                if ($key != 'id') {
                    $setter = 'set' . $key;
                    if (method_exists($config, $setter)) {
                        $config->$setter($value);
                    }
                }
            }

            $config->save();
            $item = $this->getKeyConfigItem($config);

            return $this->adminJson(['success' => true, 'data' => $item]);
        }

        return $this->adminJson(['success' => false]);
    }

    protected function getConfigItem(Classificationstore\KeyConfig|Classificationstore\CollectionConfig|Classificationstore\GroupConfig $config): array
    {
        $name = $config->getName();

        $item = [
            'storeId' => $config->getStoreId(),
            'id' => $config->getId(),
            'name' => $name,
            'description' => $config->getDescription(),
        ];

        if ($config->getCreationDate()) {
            $item['creationDate'] = $config->getCreationDate();
        }

        if ($config->getModificationDate()) {
            $item['modificationDate'] = $config->getModificationDate();
        }

        return $item;
    }

    protected function getKeyConfigItem(Classificationstore\KeyConfig $config): array
    {
        $item = $this->getConfigItem($config);
        $item['type'] = $config->getType() ? $config->getType() : 'input';
        $definition = $config->getDefinition();
        $item['definition'] = $definition;

        if ($definition) {
            $definition = json_decode($definition, true);
            if ($definition) {
                $item['title'] = $definition['title'];
            }
        }

        return $item;
    }

    /**
     * @Route("/add-property", name="addproperty", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function addPropertyAction(Request $request): JsonResponse
    {
        $name = $request->get('name');
        $storeId = (int) $request->get('storeId');

        $definition = [
            'fieldtype' => 'input',
            'name' => $name,
            'title' => $name,
            'datatype' => 'data',
        ];
        $config = new Classificationstore\KeyConfig();
        $config->setName($name);
        $config->setTitle($name);
        $config->setType('input');
        $config->setStoreId($storeId);
        $config->setEnabled(true);
        $config->setDefinition(json_encode($definition));
        $config->save();

        return $this->adminJson(['success' => true, 'id' => $config->getName()]);
    }

    /**
     * @Route("/delete-property", name="deleteproperty", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function deletePropertyAction(Request $request): JsonResponse
    {
        $id = $request->request->getInt('id');

        $config = Classificationstore\KeyConfig::getById($id);
        //        $config->delete();
        $config->setEnabled(false);
        $config->save();

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/edit-store", name="editstore", methods={"PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function editStoreAction(Request $request): JsonResponse
    {
        $id = $request->request->getInt('id');
        $data = json_decode($request->request->get('data'), true);
        $name = $data['name'];
        if (!$name) {
            throw new \Exception('Name must not be empty');
        }

        $description = $data['description'];

        $config = Classificationstore\StoreConfig::getByName($name);
        if ($config && $config->getId() != $id) {
            throw new \Exception('There is already a config with the same name');
        }

        $config = Classificationstore\StoreConfig::getById($id);

        if (!$config) {
            throw new \Exception('Configuration does not exist');
        }

        $config->setName($name);
        $config->setDescription($description);
        $config->save();

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/storetree", name="storetree", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function storetreeAction(Request $request): JsonResponse
    {
        $result = [];
        $list = new Classificationstore\StoreConfig\Listing();
        $list = $list->load();
        foreach ($list as $item) {
            $resultItem = [
                'id' => $item->getId(),
                'text' => htmlspecialchars($item->getName(), ENT_QUOTES),
                'expandable' => false,
                'leaf' => true,
                'expanded' => true,
                'description' => htmlspecialchars($item->getDescription() ?? '', ENT_QUOTES),
                'iconCls' => 'pimcore_icon_classificationstore',
            ];

            $resultItem['qtitle'] = 'ID: ' . $item->getId();

            if ($item->getDescription()) {
            }
            $resultItem['qtip'] = $item->getDescription() ? htmlspecialchars($item->getDescription(), ENT_QUOTES) : ' ';
            $result[] = $resultItem;
        }

        return $this->adminJson($result);
    }

    /**
     * @Route("/get-page", name="getpage", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getPageAction(Request $request): JsonResponse
    {
        $tableSuffix = $request->get('table');
        if (!in_arrayi($tableSuffix, ['keys', 'groups'])) {
            $tableSuffix = 'keys';
        }

        $table = 'classificationstore_' . $tableSuffix;
        $db = \Pimcore\Db::get();
        $id = (int) $request->get('id');
        $storeId = (int) $request->get('storeId');
        $pageSize = (int) $request->get('pageSize');

        if ($request->get('sortKey')) {
            $sortKey = $request->get('sortKey');
            $sortDir = $request->get('sortDir');
        } else {
            $sortKey = 'name';
            $sortDir = 'ASC';
        }

        if (!in_arrayi($sortDir, ['DESC', 'ASC'])) {
            $sortDir = 'DESC';
        }

        if (!in_arrayi($sortKey, ['name', 'title', 'description', 'id', 'type', 'creationDate', 'modificationDate', 'enabled', 'parentId', 'storeId'])) {
            $sortKey = 'name';
        }

        $sorter = ' order by `' . $sortKey .  '` ' . $sortDir;

        if ($table == 'keys') {
            $query = '
                select *, (item.pos - 1)/ ' . $pageSize . ' + 1  as page from (
                    select * from (
                        select @rownum := @rownum + 1 as pos,  id, name, `type`
                        from `' . $table . '`
                        where enabled = 1 and storeId = ' . $storeId . $sorter . '
                      ) all_rows) item where id = ' . $id . ';';
        } else {
            $query = '
            select *, (item.pos - 1)/ ' . $pageSize . ' + 1  as page from (
                select * from (
                    select @rownum := @rownum + 1 as pos,  id, name
                    from `' . $table . '`
                    where storeId = ' . $storeId . $sorter . '
                  ) all_rows) item where id = ' .  $id . ';';
        }

        $db->executeQuery('select @rownum := 0;');
        $result = $db->fetchAllAssociative($query);

        $page = (int) $result[0]['page'] ;

        return $this->adminJson(['success' => true, 'page' => $page]);
    }

    public function onKernelControllerEvent(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $unrestrictedActions = [
            'collectionsActionGet',
            'groupsActionGet',
            'relationsActionGet',
            'addGroupsAction',
            'addCollectionsAction',
            'searchRelationsAction',
        ];
        $this->checkActionPermission($event, 'classes', $unrestrictedActions);
    }

    /**
     * @return string[]
     */
    private function getTranslatedSearchFilterTerms(string $searchTerm): array
    {
        $terms = [];

        $user = Admin::getCurrentUser();
        if ($user instanceof User) {
            $translationListing = new Listing();
            $translationListing->setDomain(Translation::DOMAIN_ADMIN);
            $translationListing->setCondition('language=? AND text LIKE ?', [$user->getLanguage(), '%'.$searchTerm.'%']);

            foreach ($translationListing as $translation) {
                $terms[] = $translation->getKey();
            }
        }

        return $terms;
    }
}
