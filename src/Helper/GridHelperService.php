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

namespace Pimcore\Bundle\AdminBundle\Helper;

use Doctrine\DBAL\Query\QueryBuilder as DoctrineQueryBuilder;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Exception;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Pimcore\Bundle\AdminBundle\Event\AdminEvents;
use Pimcore\Logger;
use Pimcore\Model;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\Objectbrick;
use Pimcore\Model\User;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
class GridHelperService
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher
    ) {

    }

    public function getFeatureAndSlugFilters(string $filterJson, ClassDefinition $class, string $requestedLanguage): array
    {
        $featureJoins = [];
        $slugJoins = [];
        $slugConditions = [];
        $featureConditions = [];

        if ($filterJson) {
            $filters = json_decode($filterJson, true);
            foreach ($filters as $filter) {
                $operator = '=';

                $filterField = $filter['property'];
                $filterOperator = $filter['operator'];

                if ($filter['type'] == 'string') {
                    $operator = 'LIKE';
                } elseif ($filter['type'] == 'numeric') {
                    if ($filterOperator == 'lt') {
                        $operator = '<';
                    } elseif ($filterOperator == 'gt') {
                        $operator = '>';
                    } elseif ($filterOperator == 'eq') {
                        $operator = '=';
                    }
                } elseif ($filter['type'] == 'date') {
                    if ($filterOperator == 'lt') {
                        $operator = '<';
                    } elseif ($filterOperator == 'gt') {
                        $operator = '>';
                    } elseif ($filterOperator == 'eq') {
                        $operator = '=';
                    }
                    $filter['value'] = strtotime($filter['value']);
                } elseif ($filter['type'] == 'list') {
                    $operator = '=';
                } elseif ($filter['type'] == 'boolean') {
                    $operator = '=';
                    $filter['value'] = (int)$filter['value'];
                }

                $keyParts = explode('~', $filterField);

                $slugFd = null;
                $field = null;
                $slugKey = null;
                $mappedKey = null;

                if (substr($filterField, 0, 1) == '~') {
                    $type = $keyParts[1];
                    if ($type != 'classificationstore') {
                        continue;
                    }

                    $fieldName = $keyParts[2];
                    $groupKeyId = explode('-', $keyParts[3]);

                    /** @var Model\DataObject\ClassDefinition\Data\Classificationstore $csFieldDefinition */
                    $csFieldDefinition = $class->getFieldDefinition($fieldName);

                    $language = $requestedLanguage;
                    if (!$csFieldDefinition->isLocalized()) {
                        $language = 'default';
                    }

                    $groupId = (int) $groupKeyId[0];
                    $keyid = (int) $groupKeyId[1];

                    $keyConfig = Model\DataObject\Classificationstore\KeyConfig::getById($keyid);
                    $type = $keyConfig->getType();
                    $definition = json_decode($keyConfig->getDefinition(), true);
                    $field = \Pimcore\Model\DataObject\Classificationstore\Service::getFieldDefinitionFromJson($definition, $type);

                    if ($field instanceof Model\DataObject\ClassDefinition\Data) {
                        $mappedKey = 'cskey_' . $fieldName . '_' . $groupId . '_' . $keyid;
                        $featureJoins[] = ['fieldname' => $fieldName, 'groupId' => $groupId, 'keyId' => $keyid, 'language' => $language];
                        if (isset($filter['value'])) {
                            $featureCondition = $field->getFilterConditionExt(
                                $filter['value'],
                                $operator,
                                [
                                    'name' => $mappedKey, ]
                            );

                            if (!empty($featureCondition)) {
                                $featureConditions[$mappedKey] = $featureCondition;
                            }
                        }
                    }
                } elseif (count($keyParts) > 1) {
                    $brickType = $keyParts[0];
                    $brickKey = $keyParts[1];

                    if (strpos($brickType, '?') !== false) {
                        $brickDescriptor = substr($brickType, 1);
                        $brickDescriptor = json_decode($brickDescriptor, true);
                        $brickType = $brickDescriptor['containerKey'];
                    }

                    $brickDef = Objectbrick\Definition::getByKey($brickType);
                    if ($slugFd = $brickDef->getFieldDefinition($brickKey) instanceof ClassDefinition\Data\UrlSlug) {
                        $slugKey = $brickKey;
                        $slugJoins[] = ['fieldname' => $brickKey];
                    }
                } else {
                    if ($slugFd = $class->getFieldDefinition($filterField) instanceof ClassDefinition\Data\UrlSlug) {
                        $slugKey = $filterField;
                        $slugJoins[] = ['fieldname' => $filterField];
                    }
                }

                if ($field && $slugFd) {
                    $slugCondition = $field->getFilterConditionExt(
                        $filter['value'],
                        $operator,
                        [
                            'name' => $slugKey, ]
                    );

                    $slugConditions[$mappedKey] = $slugCondition;
                }
            }
        }

        $result = [
            'featureJoins' => $featureJoins,
            'slugJoins' => $slugJoins,
            'featureConditions' => $featureConditions,
            'slugConditions' => $slugConditions,
        ];

        return $result;
    }

    public function getFilterCondition(string $filterJson, ClassDefinition $class, ?string $tablePrefix = null): string
    {
        $systemFields = Model\DataObject\Service::getSystemFields();

        // create filter condition
        $conditionPartsFilters = [];

        if ($filterJson) {
            $db = \Pimcore\Db::get();
            $filters = json_decode($filterJson, true);

            foreach ($filters as $filter) {
                if (isset($filter['value'])) {
                    $operator = '=';

                    $filterField = $filter['property'];
                    $filterOperator = $filter['operator'];

                    if ($filter['type'] == 'string') {
                        $operator = 'LIKE';
                    } elseif ($filter['type'] == 'date') {
                        if ($filterOperator == 'lt') {
                            $operator = '<';
                        } elseif ($filterOperator == 'gt') {
                            $operator = '>';
                        } elseif ($filterOperator == 'eq') {
                            $operator = '=';
                        }
                        $filter['value'] = strtotime($filter['value']);
                    } elseif ($filter['type'] == 'list') {
                        $operator = '=';
                    } elseif ($filter['type'] == 'boolean') {
                        $operator = '=';
                        $filter['value'] = (int)$filter['value'];
                    } elseif ($filterOperator == 'in' && is_array($filter['value'])) {
                        $operator = 'in';
                        $matches = preg_split('/[^0-9\.]+/', $filter['value'][0][0] ?? [], -1, PREG_SPLIT_NO_EMPTY);
                        if (is_array($matches) && count($matches) > 0) {
                            $filter['value'][0][0] = implode(',', array_unique(array_map(floatval(...), $matches)));
                        } else {
                            continue;
                        }
                    } elseif ($filterOperator == 'in' && !is_array($filter['value'])) {
                        $operator = 'in';
                        $matches = preg_split('/[^0-9\.]+/', $filter['value'], -1, PREG_SPLIT_NO_EMPTY);
                        if (is_array($matches) && count($matches) > 0) {
                            $filter['value'] = implode(',', array_unique(array_map(floatval(...), $matches)));
                        } else {
                            continue;
                        }
                    } else {
                        if ($filterOperator == 'lt') {
                            $operator = '<';
                        } elseif ($filterOperator == 'gt') {
                            $operator = '>';
                        } elseif ($filterOperator == 'eq') {
                            $operator = '=';
                        }
                    }

                    $field = $class->getFieldDefinition($filterField);
                    $brickField = null;
                    $brickKey = null;
                    $brickType = null;
                    $brickDescriptor = null;
                    $isLocalized = false;
                    if (!$field) {
                        // if the definition doesn't exist check for a localized field
                        $localized = $class->getFieldDefinition('localizedfields');
                        if ($localized instanceof ClassDefinition\Data\Localizedfields) {
                            $field = $localized->getFieldDefinition($filterField);
                        }

                        //if the definition doesn't exist check for object brick
                        $keyParts = explode('~', $filterField);

                        if (substr($filterField, 0, 1) === '~') {
                            // not needed for now
                            //                            $type = $keyParts[1];
                            //                            $field = $keyParts[2];
                            //                            $keyid = $keyParts[3];
                        } elseif (count($keyParts) > 1) {
                            $brickType = $keyParts[0];
                            $brickKey = $keyParts[1];

                            if (strpos($brickType, '?') !== false) {
                                $brickDescriptor = substr($brickType, 1);
                                $brickDescriptor = json_decode($brickDescriptor, true);
                                $brickType = $brickDescriptor['containerKey'];
                            }

                            $key = Model\DataObject\Service::getFieldForBrickType($class, $brickType);
                            $field = $class->getFieldDefinition($key);

                            $brickClass = Objectbrick\Definition::getByKey($brickType);

                            $brickFieldKey = $brickDescriptor ? $brickDescriptor['brickfield'] : $brickKey;

                            $brickClassDefinitions = $brickClass->getFieldDefinitions();
                            if (array_key_exists($brickFieldKey, $brickClassDefinitions)) {
                                $brickField = $brickClass->getFieldDefinition($brickFieldKey);
                            } else {
                                /** @var ClassDefinition\Data\Localizedfields|null $localizedFields */
                                $localizedFields = $brickClass->getFieldDefinition('localizedfields');
                                if ($localizedFields) {
                                    $brickField = $localizedFields->getFieldDefinition($brickFieldKey);
                                    $isLocalized = true;
                                }
                            }
                        }
                    }
                    if ($field instanceof ClassDefinition\Data\Objectbricks || $brickDescriptor) {
                        // custom field
                        if ($brickDescriptor) {
                            $brickFilterField = $brickDescriptor['fieldname'];
                        } else {
                            $brickFilterField = $field->getName();
                        }

                        $db = \Pimcore\Db::get();

                        if ($isLocalized) {
                            $brickPrefix = $db->quoteIdentifier($brickType . '_localized') . '.';
                        } else {
                            if ($brickField instanceof ClassDefinition\Data\UrlSlug) {
                                $brickPrefix = $db->quoteIdentifier($brickKey) . '.';
                            } else {
                                $brickPrefix = $db->quoteIdentifier($brickType) . '.';
                            }
                        }

                        if (is_array($filter['value'])) {
                            $fieldConditions = [];
                            foreach ($filter['value'] as $filterValue) {
                                $brickCondition = '(' . $brickField->getFilterCondition($filterValue, $operator,
                                    ['brickPrefix' => $brickPrefix]
                                ) . ' AND ' . $brickType . '.fieldname = ' . $db->quote($brickFilterField) . ')';
                                $fieldConditions[] = $brickCondition;
                            }

                            if (!empty($fieldConditions)) {
                                $conditionPartsFilters[] = '(' . implode(' OR ', $fieldConditions) . ')';
                            }
                        } else {
                            $brickCondition = '(' . $brickField->getFilterCondition($filter['value'], $operator,
                                ['brickPrefix' => $brickPrefix]) . ' AND ' . $brickType . '.fieldname = ' . $db->quote($brickFilterField) . ')';
                            $conditionPartsFilters[] = $brickCondition;
                        }
                    } elseif ($field instanceof ClassDefinition\Data\UrlSlug) {
                        $conditionPartsFilters[] = $db->quoteIdentifier($field->getName()) . '.' . $field->getFilterCondition($filter['value'], $operator);
                    } elseif ($field instanceof ClassDefinition\Data) {
                        // custom field
                        if (is_array($filter['value'] ?? false)) {
                            $fieldConditions = [];
                            foreach ($filter['value'] as $filterValue) {
                                $fieldConditions[] = $field->getFilterCondition($filterValue, $operator, ['brickPrefix' => ($tablePrefix ? $tablePrefix . '.' : null)]);
                            }

                            if (!empty($fieldConditions)) {
                                $conditionPartsFilters[] = '(' . implode(' OR ', $fieldConditions) . ')';
                            }
                        } else {
                            $conditionPartsFilters[] = $field->getFilterCondition($filter['value'] ?? null, $operator, ['brickPrefix' => ($tablePrefix ? $tablePrefix . '.' : null)]);
                        }
                    } elseif (in_array($filterField, $systemFields)) {
                        // system field
                        if ($filterField == 'fullpath') {
                            $conditionPartsFilters[] = 'concat(`path`, `key`) ' . $operator . ' ' . $db->quote('%' . $filter['value'] . '%');
                        } elseif ($filterField == 'key') {
                            $conditionPartsFilters[] = '`key` ' . $operator . ' ' . $db->quote('%' . $filter['value'] . '%');
                        } elseif ($filterField == 'id' && $operator !== 'in') {
                            $conditionPartsFilters[] = 'oo_id ' . $operator . ' ' . $db->quote($filter['value']);
                        } elseif ($filterField == 'id' && $operator === 'in') {
                            $conditionPartsFilters[] = 'oo_id ' . $operator . ' (' . $filter['value'] . ')';
                        } else {
                            $filterField = $db->quoteIdentifier($filterField);
                            if ($filter['type'] == 'date' && $operator == '=') {
                                //if the equal operator is chosen with the date type, condition has to be changed
                                $maxTime = $filter['value'] + (86400 - 1); //specifies the top point of the range used in the condition
                                $conditionPartsFilters[] = $filterField . ' BETWEEN ' . $db->quote($filter['value']) . ' AND ' . $db->quote($maxTime);
                            } else {
                                // @see \Pimcore\Model\DataObject\ClassDefinition\Data\Checkbox::getFilterConditionExt()
                                if ($filter['type'] === 'boolean') {
                                    $filterField = 'IFNULL(' . $filterField . ', 0)';
                                }
                                $conditionPartsFilters[] = $filterField . ' ' . $operator . ' ' . $db->quote($filter['value']);
                            }
                        }
                    }
                } elseif ($filter['property'] !== 'fullpath') {
                    $conditionPartsFilters[] = '( ' .
                        $db->quoteIdentifier($filter['property']) . ' IS NULL OR ' .
                        $db->quoteIdentifier($filter['property']) . " = '' )";
                }
            }
        }

        $conditionFilters = '1 = 1';
        if (count($conditionPartsFilters) > 0) {
            $conditionFilters = '(' . implode(' AND ', $conditionPartsFilters) . ')';
        }
        Logger::log('DataObjectController filter condition:' . $conditionFilters);

        return $conditionFilters;
    }

    protected function extractBricks(array $fields): array
    {
        $bricks = [];
        if ($fields) {
            foreach ($fields as $f) {
                $fieldName = $f;
                $parts = explode('~', $f);
                if (substr($f, 0, 1) == '~') {
                    // key value, ignore for now
                } elseif (count($parts) > 1) {
                    $brickType = $parts[0];

                    if (strpos($brickType, '?') !== false) {
                        $brickDescriptor = substr($brickType, 1);
                        $brickDescriptor = json_decode($brickDescriptor, true);
                        $brickType = $brickDescriptor['containerKey'];
                    }

                    $bricks[$brickType] = $brickType;
                }
                $newFields[] = $fieldName;
            }
        }

        return $bricks;
    }

    /**
     * Adds all the query stuff that is needed for displaying, filtering and exporting the feature grid data.
     */
    public function addGridFeatureJoins(DataObject\Listing\Concrete $list, array $featureJoins, ClassDefinition $class, array $featureAndSlugFilters): void
    {
        if ($featureJoins) {
            $me = $list;

            $list->onCreateQueryBuilder(function (DoctrineQueryBuilder $select) use (
                $featureJoins,
                $class,
                $featureAndSlugFilters,
                $me
            ) {
                $db = \Pimcore\Db::get();

                $alreadyJoined = [];

                foreach ($featureJoins as $featureJoin) {
                    $fieldname = $featureJoin['fieldname'];
                    $mappedKey = 'cskey_' . $fieldname . '_' . $featureJoin['groupId'] . '_' . $featureJoin['keyId'];
                    if (isset($alreadyJoined[$mappedKey])) {
                        continue;
                    }
                    $alreadyJoined[$mappedKey] = 1;

                    $table = $me->getDao()->getTableName();
                    $select->addSelect($mappedKey . '.value AS ' . $mappedKey);
                    $select->leftJoin(
                        $table,
                        'object_classificationstore_data_' . $class->getId(),
                        $mappedKey,
                        '('
                        . $mappedKey . '.id = ' . $table . '.id'
                        . ' and ' . $mappedKey . '.fieldname = ' . $db->quote($fieldname)
                        . ' and ' . $mappedKey . '.groupId=' . $featureJoin['groupId']
                        . ' and ' . $mappedKey . '.keyId=' . $featureJoin['keyId']
                        . ' and ' . $mappedKey . '.language = ' . $db->quote($featureJoin['language'])
                        . ')'
                    );
                }

                $havings = $featureAndSlugFilters['featureConditions'] ?? null;
                if ($havings) {
                    $havings = implode(' AND ', $havings);
                    $select->having($havings);
                }
            });
        }
    }

    /**
     * Adds all the query stuff that is needed for displaying, filtering and exporting the slug grid data.
     */
    public function addSlugJoins(DataObject\Listing\Concrete $list, array $slugJoins, array $featureAndSlugFilters): void
    {
        if ($slugJoins) {
            $me = $list;

            $list->onCreateQueryBuilder(function (DoctrineQueryBuilder $select) use (
                $slugJoins,
                $featureAndSlugFilters,
                $me
            ) {
                $db = \Pimcore\Db::get();

                $alreadyJoined = [];

                foreach ($slugJoins as $slugJoin) {
                    $fieldname = $slugJoin['fieldname'];

                    $mappedKey = $fieldname;
                    $alreadyJoined[$mappedKey] = 1;
                    $table = $me->getDao()->getTableName();

                    $select->addSelect('slug AS ' . $mappedKey);
                    $select->leftJoin(
                        $table,
                        DataObject\Data\UrlSlug::TABLE_NAME,
                        $mappedKey,
                        '('
                        . $mappedKey . '.objectId = ' . $table . '.id'
                        . ' and ' . $mappedKey . '.fieldname = ' . $db->quote($fieldname)
                        . ')'
                    );
                }

                $havings = $featureAndSlugFilters['slugConditions'];
                if ($havings) {
                    $havings = implode(' AND ', $havings);
                    $select->having($havings);
                }
            });
        }
    }

    public function prepareListingForGrid(array $requestParams, string $requestedLanguage, User $adminUser): DataObject\Listing\Concrete
    {
        $folder = Model\DataObject::getById((int) $requestParams['folderId']);
        $class = ClassDefinition::getById((string) $requestParams['classId']);
        $className = $class->getName();

        $listClass = '\\Pimcore\\Model\\DataObject\\' . ucfirst($className) . '\\Listing';
        /** @var DataObject\Listing\Concrete $list */
        $list = new $listClass();

        $colMappings = [
            'key' => 'key',
            'filename' => 'key',
            'id' => 'oo_id',
            'published' => 'published',
            'modificationDate' => 'modificationDate',
            'creationDate' => 'creationDate',
        ];

        $start = 0;
        $limit = 20;
        $orderKey = 'id';
        $order = 'ASC';

        $fields = [];
        $bricks = [];
        if (!empty($requestParams['fields'])) {
            $fields = $requestParams['fields'];
            $bricks = $this->extractBricks($fields);
        }

        if (isset($requestParams['limit'])) {
            $limit = $requestParams['limit'];
        }
        if (isset($requestParams['start'])) {
            $start = $requestParams['start'];
        }

        $sortingSettings = \Pimcore\Bundle\AdminBundle\Helper\QueryParams::extractSortingSettings($requestParams);
        $doNotQuote = false;

        if ($sortingSettings['order']) {
            $order = $sortingSettings['order'];
        }
        if ($sortingSettings['orderKey'] !== null && strlen($sortingSettings['orderKey']) > 0) {
            $orderKey = $sortingSettings['orderKey'];
            if (substr($orderKey, 0, 1) !== '~') {
                if (array_key_exists($orderKey, $colMappings)) {
                    $orderKey = $colMappings[$orderKey];
                } elseif ($orderKey === 'fullpath') {
                    $orderKey = 'CAST(CONCAT(`path`, `key`) AS CHAR CHARACTER SET utf8) COLLATE utf8_general_ci';
                    $doNotQuote = true;
                } elseif ($class->getFieldDefinition($orderKey) instanceof ClassDefinition\Data\QuantityValue) {
                    $orderKey = 'concat(' . $orderKey . '__unit, ' . $orderKey . '__value)';
                    $doNotQuote = true;
                } elseif ($class->getFieldDefinition($orderKey) instanceof ClassDefinition\Data\RgbaColor) {
                    $orderKey = 'concat(' . $orderKey . '__rgb, ' . $orderKey . '__a)';
                    $doNotQuote = true;
                } elseif (strpos($orderKey, '~') !== false) {
                    $orderKeyParts = explode('~', $orderKey);

                    if (strpos($orderKey, '?') !== false) {
                        $brickDescriptor = substr($orderKeyParts[0], 1);
                        $brickDescriptor = json_decode($brickDescriptor, true);
                        $orderKey = $list->quoteIdentifier($brickDescriptor['containerKey'] . '_localized')
                            . '.' . $list->quoteIdentifier($brickDescriptor['brickfield']);
                        $doNotQuote = true;
                    } elseif (count($orderKeyParts) === 2) {
                        $orderKey = $list->quoteIdentifier($orderKeyParts[0])
                            . '.' . $list->quoteIdentifier($orderKeyParts[1]);
                        $doNotQuote = true;
                    }
                } else {
                    $orderKey = $list->getDao()->getTableName() . '.' . $list->quoteIdentifier($orderKey);
                    $doNotQuote = true;
                }
            }
        }

        $conditionFilters = [];

        if (($requestParams['specificId'] ?? false) && is_numeric($requestParams['specificId'])) {
            $conditionFilters[] = 'oo_id = ' . (int) $requestParams['specificId'];
        }

        if (isset($requestParams['only_direct_children']) && $requestParams['only_direct_children'] === 'true') {
            $conditionFilters[] = 'parentId = ' . $folder->getId();
        } else {
            $quotedPath = $list->quote($folder->getRealFullPath());
            $quotedWildcardPath = $list->quote($list->escapeLike(str_replace('//', '/', $folder->getRealFullPath() . '/')) . '%');
            $conditionFilters[] = '(`path` = ' . $quotedPath . ' OR `path` like ' . $quotedWildcardPath . ')';
        }

        if (!$adminUser->isAdmin()) {
            $userIds = $adminUser->getRoles();
            $userIds[] = $adminUser->getId();
            $conditionFilters[] = ' (
                                                    (select list from users_workspaces_object where userId in (' . implode(',', $userIds) . ') and LOCATE(CONCAT(`path`,`key`),cpath)=1  ORDER BY LENGTH(cpath) DESC LIMIT 1)=1
                                                    OR
                                                    (select list from users_workspaces_object where userId in (' . implode(',', $userIds) . ') and LOCATE(cpath,CONCAT(`path`,`key`))=1  ORDER BY LENGTH(cpath) DESC LIMIT 1)=1
                                                 )';
        }

        $featureJoins = [];
        $slugJoins = [];
        $featureAndSlugFilters = [];

        // create filter condition
        if (!empty($requestParams['filter'])) {
            $conditionFilters[] = $this->getFilterCondition($requestParams['filter'], $class, $list->getDao()->getTableName());
            $featureAndSlugFilters = $this->getFeatureAndSlugFilters($requestParams['filter'], $class, $requestedLanguage);
            if ($featureAndSlugFilters) {
                $featureJoins = array_merge($featureJoins, $featureAndSlugFilters['featureJoins']);
                $slugJoins = array_merge($slugJoins, $featureAndSlugFilters['slugJoins']);
            }
        }
        if (!empty($requestParams['condition']) && $adminUser->isAdmin()) {
            $conditionFilters[] = '(' . $requestParams['condition'] . ')';
        }

        if (!empty($requestParams['query'])) {
            $query = $this->filterQueryParam($requestParams['query']);
            if (!empty($query)) {
                $handleFullTextQueryEvent = new GenericEvent($this, [
                    'query' => $query,
                    'condition' => null,
                    'list' => $list,
                ]);
                $this->eventDispatcher->dispatch(
                    $handleFullTextQueryEvent,
                    AdminEvents::OBJECT_LIST_HANDLE_FULLTEXT_QUERY
                );

                $condition = $handleFullTextQueryEvent->getArgument('condition');
                if ($condition !== null) {
                    $conditionFilters[] = $condition;
                }
            }
        }

        if (!empty($bricks)) {
            foreach ($bricks as $b) {
                $brickType = $b;
                if (is_array($brickType)) {
                    $brickType = $brickType['containerKey'];
                }
                $list->addObjectbrick($brickType);
            }
        }

        $list->setCondition(implode(' AND ', $conditionFilters));
        if (empty($requestParams['batch']) && empty($requestParams['ids'])) {
            $list->setLimit($limit);
            $list->setOffset($start);
        }

        if (isset($sortingSettings['isFeature']) && $sortingSettings['isFeature']) {
            $orderKey = 'cskey_' . $sortingSettings['fieldname'] . '_' . $sortingSettings['groupId'] . '_' . $sortingSettings['keyId'];
            $list->setOrderKey($orderKey);
            $list->setGroupBy('oo_id');

            $parts = explode('_', $orderKey);

            $fieldname = $parts[1];
            /** @var DataObject\ClassDefinition\Data\Classificationstore $csFieldDefinition */
            $csFieldDefinition = $class->getFieldDefinition($fieldname);
            $sortingSettings['language'] = $csFieldDefinition->isLocalized() ? $requestedLanguage : 'default';
            $featureJoins[] = $sortingSettings;
        } else {
            $list->setOrderKey($orderKey, !$doNotQuote);
        }
        $list->setOrder($order);

        //parameters specified in the objects grid
        if (!empty($requestParams['ids'])) {
            $quotedIds = [];
            foreach ($requestParams['ids'] as $id) {
                $quotedIds[] = $list->quote($id);
            }
            if (!empty($quotedIds)) {
                //add a condition if id numbers are specified
                $list->addConditionParam('oo_id IN (' . implode(',', $quotedIds) . ')');
            }
        }

        if ($class->getAllowVariants()) {
            if ($class->getShowVariants()) {
                $list->setObjectTypes([DataObject::OBJECT_TYPE_OBJECT, DataObject::OBJECT_TYPE_VARIANT]);
            }
            if (isset($requestParams['filter_by_object_type'])) {
                if ($requestParams['filter_by_object_type'] === 'only_objects') {
                    $list->setObjectTypes([DataObject::OBJECT_TYPE_OBJECT]);
                } elseif ($requestParams['filter_by_object_type'] === 'only_variant_objects') {
                    $list->setObjectTypes([DataObject::OBJECT_TYPE_VARIANT]);
                }
            }
        }

        $this->addGridFeatureJoins($list, $featureJoins, $class, $featureAndSlugFilters);
        $this->addSlugJoins($list, $slugJoins, $featureAndSlugFilters);

        $list->setLocale($requestedLanguage);

        if (empty($requestParams['filter']) && empty($requestParams['condition']) && empty($requestParams['sort'])) {
            $list->setIgnoreLocalizedFields(true);
        }

        return $list;
    }

    public function prepareAssetListingForGrid(array $allParams, User $adminUser): Model\Asset\Listing
    {
        $db = \Pimcore\Db::get();
        $folder = Model\Asset::getById((int) $allParams['folderId']);

        $start = 0;
        $limit = null;
        $orderKey = 'id';
        $order = 'ASC';

        if (isset($allParams['limit'])) {
            $limit = $allParams['limit'];
        }
        if (isset($allParams['start'])) {
            $start = $allParams['start'];
        }

        $orderKeyQuote = true;
        $sortingSettings = \Pimcore\Bundle\AdminBundle\Helper\QueryParams::extractSortingSettings($allParams);
        if ($sortingSettings['orderKey']) {
            $orderKey = explode('~', $sortingSettings['orderKey'])[0];
            if ($orderKey === 'fullpath') {
                $orderKey = 'CAST(CONCAT(`path`,filename) AS CHAR CHARACTER SET utf8) COLLATE utf8_general_ci';
                $orderKeyQuote = false;
            } elseif ($orderKey === 'filename') {
                $orderKey = 'CAST(filename AS CHAR CHARACTER SET utf8) COLLATE utf8_general_ci';
                $orderKeyQuote = false;
            }

            $order = $sortingSettings['order'];
        }

        $list = new Model\Asset\Listing();

        $conditionFilters = [];
        if (isset($allParams['only_direct_children']) && $allParams['only_direct_children'] == 'true') {
            $conditionFilters[] = 'parentId = ' . $folder->getId();
        } else {
            $conditionFilters[] = 'path LIKE ' . ($folder->getRealFullPath() === '/' ? "'/%'" : $list->quote($list->escapeLike($folder->getRealFullPath()) . '/%'));
        }

        if (isset($allParams['only_unreferenced']) && $allParams['only_unreferenced'] === 'true') {
            $conditionFilters[] = 'id NOT IN (SELECT targetid FROM dependencies WHERE targettype=\'asset\')';
        }

        $conditionFilters[] = "type != 'folder'";
        $filterJson = $allParams['filter'] ?? null;
        if ($filterJson) {
            $filters = json_decode($filterJson, true);
            foreach ($filters as $filter) {
                $operator = '=';

                $filterDef = explode('~', $filter['property']);
                $filterField = $filterDef[0];
                $filterOperator = $filter['operator'];
                $filterType = $filter['type'];

                if ($filterType == 'string') {
                    $operator = 'LIKE';
                } elseif ($filterType == 'numeric') {
                    if ($filterOperator == 'lt') {
                        $operator = '<';
                    } elseif ($filterOperator == 'gt') {
                        $operator = '>';
                    } elseif ($filterOperator == 'eq') {
                        $operator = '=';
                    }
                } elseif ($filterType == 'date') {
                    $filter['value'] = strtotime($filter['value']);
                    if ($filterOperator == 'lt') {
                        $operator = '<';
                    } elseif ($filterOperator == 'gt') {
                        $operator = '>';
                    } elseif ($filterOperator == 'eq') {
                        $operator = 'BETWEEN';
                        //if the equal operator is chosen with the date type, condition has to be changed
                        $maxTime = $filter['value'] + (86400 - 1); //specifies the top point of the range used in the condition
                        $filter['value'] = $db->quote($filter['value']) . ' AND ' . $db->quote($maxTime);
                    }
                } elseif ($filterType == 'list') {
                    $operator = 'IN';
                } elseif ($filterType == 'boolean') {
                    $operator = '=';
                    $filter['value'] = (int) $filter['value'];
                }
                // system field
                $value = $filter['value'] ?? '';
                if ($operator == 'LIKE') {
                    $value = $db->quote('%' . $value . '%');
                } elseif ($operator == 'IN') {
                    if (empty($value)) {
                        continue;
                    }
                    $quoted = array_map(function ($val) use ($db) {
                        return $db->quote($val);
                    }, $value);
                    $value = '(' . implode(',', $quoted) . ')';
                } elseif ($operator == 'BETWEEN') {
                } else {
                    $value = $db->quote($value);
                }

                if (isset($filterDef[1]) && $filterDef[1] == 'system') {
                    if ($filterField == 'fullpath') {
                        $filterField = 'CONCAT(`path`,filename)';
                    } else {
                        $filterField = $db->quoteIdentifier($filterField);
                    }
                    $conditionFilters[] = $filterField . ' ' . $operator . ' ' . $value;
                } else {
                    $language = $allParams['language'];
                    if (isset($filterDef[1])) {
                        $language = $filterDef[1];
                    }
                    $language = str_replace(['none', 'default'], '', $language);
                    $conditionFilters[] = 'id IN (SELECT cid FROM assets_metadata WHERE `name` = ' . $db->quote($filterField) . ' AND `data` ' . $operator . ' ' . $value . ' AND `language` = ' . $db->quote($language). ')';
                }
            }
        }

        if (!$adminUser->isAdmin()) {
            $userIds = $adminUser->getRoles();
            $userIds[] = $adminUser->getId();
            $conditionFilters[] = ' (
                                                    (select list from users_workspaces_asset where userId in (' . implode(',', $userIds) . ') and LOCATE(CONCAT(`path`, filename),cpath)=1  ORDER BY LENGTH(cpath) DESC LIMIT 1)=1
                                                    OR
                                                    (select list from users_workspaces_asset where userId in (' . implode(',', $userIds) . ') and LOCATE(cpath,CONCAT(`path`, filename))=1  ORDER BY LENGTH(cpath) DESC LIMIT 1)=1
                                                 )';
        }

        //filtering for tags
        if (!empty($allParams['tagIds'])) {
            $tagIds = $allParams['tagIds'];
            foreach ($tagIds as $tagId) {
                if ($allParams['considerChildTags'] ?? false) {
                    $tag = Model\Element\Tag::getById($tagId);
                    if ($tag) {
                        $tagPath = $tag->getFullIdPath();
                        $conditionFilters[] = 'id IN (SELECT cId FROM `tags_assignment` INNER JOIN `tags` ON tags.id = tags_assignment.tagid WHERE `ctype` = "asset" AND (`id` = ' .(int)$tagId. ' OR `idPath` LIKE ' . $db->quote($tagPath . '%') . '))';
                    }
                } else {
                    $conditionFilters[] = 'id IN (SELECT cId FROM `tags_assignment` WHERE `ctype` = "asset" AND tagid = ' .(int)$tagId. ')';
                }
            }
        }

        $condition = implode(' AND ', $conditionFilters);

        $list->setCondition($condition);
        $list->setLimit($limit);
        $list->setOffset($start);
        $list->setOrder($order);
        $list->setOrderKey($orderKey, $orderKeyQuote);

        return $list;
    }

    protected function filterQueryParam(string $query): string
    {
        if ($query == '*') {
            $query = '';
        }

        $query = str_replace('%', '*', $query);
        $query = str_replace('@', '#', $query);
        $query = preg_replace("@([^ ])\-@", '$1 ', $query);

        $query = str_replace(['<', '>', '(', ')', '~'], ' ', $query);

        // it is not allowed to have * behind another *
        $query = preg_replace('#[*]+#', '*', $query);

        // no boolean operators at the end of the query
        $query = rtrim($query, '+- ');

        return $query;
    }

    /**
     * @throws FilesystemException
     * @throws Exception|\PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function createXlsxExportFile(FilesystemOperator $storage, string $fileHandle, string $csvFile): BinaryFileResponse
    {
        $csvReader = new Csv();
        $csvReader->setDelimiter(';');
        $csvReader->setSheetIndex(0);

        $spreadsheet = $csvReader->loadSpreadsheetFromString($storage->read($csvFile));
        $writer = new Xlsx($spreadsheet);
        $xlsxFilename = PIMCORE_SYSTEM_TEMP_DIRECTORY. '/' .$fileHandle. '.xlsx';
        $writer->save($xlsxFilename);

        $response = new BinaryFileResponse($xlsxFilename);
        $response->headers->set('Content-Type', 'application/xlsx');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'export.xlsx');
        $response->deleteFileAfterSend(true);

        $storage->delete($csvFile);

        return $response;
    }
}
