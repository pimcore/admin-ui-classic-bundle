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

namespace Pimcore\Bundle\AdminBundle\Helper;

use Carbon\Carbon;

/**
 * @internal
 */
class QueryParams
{
    /**
     * @param array $params
     *
     * @return array  [orderKey => null|string, order => null|string]
     */
    public static function extractSortingSettings(array $params): array
    {
        $orderKey = null;
        $order = null;

        $sortParam = isset($params['sort']) ? $params['sort'] : false;
        if ($sortParam) {
            $sortParam = json_decode($sortParam, true);
            $sortParam = $sortParam[0];

            $order = strtoupper($sortParam['direction']) === 'DESC' ? 'DESC' : 'ASC';

            if (substr($sortParam['property'], 0, 1) != '~') {
                $orderKey = $sortParam['property'];
            } else {
                $orderKey = $sortParam['property'];

                $parts = explode('~', $orderKey);

                $fieldname = $parts[2];
                $groupKeyId = $parts[3];
                $groupKeyId = explode('-', $groupKeyId);
                $groupId = (int) $groupKeyId[0];
                $keyid = (int) $groupKeyId[1];

                return ['orderKey' => $sortParam['property'], 'fieldname' => $fieldname, 'groupId' => $groupId, 'keyId' => $keyid, 'order' => $order, 'isFeature' => 1];
            }
        }

        return ['orderKey' => $orderKey, 'order' => $order];
    }

    public static function getRecordIdForGridRequest(string $param): int
    {
        $param = json_decode($param, true);

        return $param['id'];
    }

    /**
     * Creates a condition string from the passed ExtJs filter definitions
     *
     * @param string $filterString
     * @param array $matchExact
     * @param bool $returnString
     * @param array $callbacks
     *
     * @return array|string
     *
     * @throws \Exception
     */
    public static function getFilterCondition(string $filterString, array $matchExact = ['id', 'id'], bool $returnString = true, array $callbacks = []): array|string
    {
        if (!$filterString) {
            return '';
        }
        $conditions = [];

        $filters = json_decode($filterString);
        $db = \Pimcore\Db::get();
        foreach ($filters as $f) {
            if ($f->type == 'string') {
                if (in_array($f->property, $matchExact)) {
                    $conditions[$f->property][] = ' ' . $db->quoteIdentifier($f->property) . ' = ' . $db->quote($f->value) . ' ';
                } else {
                    $conditions[$f->property][] = ' ' . $db->quoteIdentifier($f->property) . ' LIKE ' . $db->quote('%' . $f->value . '%') . ' ';
                }
            } elseif ($f->type == 'numeric') {
                $symbol = null;
                if ($f->operator == 'eq') {
                    $symbol = ' = ';
                } elseif ($f->operator == 'lt') {
                    $symbol = ' < ';
                } elseif ($f->operator == 'gt') {
                    $symbol = ' > ';
                }
                $conditions[$f->property][] = ' ' . $db->quoteIdentifier($f->property)  . ' ' . $symbol . $db->quote($f->value) . ' ';
            } elseif ($f->type == 'date') {
                /**
                 * make sure you pass the date as timestamp
                 *
                 * filter: {type : 'date',dateFormat: 'timestamp'}
                 */
                $date = Carbon::createFromTimestamp($f->value)->setTime(0, 0, 0);

                if ($f->operator == 'eq') {
                    $conditions[$f->property][] = ' ' . $f->property . ' >= ' . $db->quote($date->getTimestamp());
                    $conditions[$f->property][] = ' ' . $f->property . ' <= ' . $db->quote($date->addDay()->subSecond()->getTimestamp());
                } elseif ($f->operator == 'lt') {
                    $conditions[$f->property][] = ' ' . $f->property . ' < ' . $db->quote($date->getTimestamp());
                } elseif ($f->operator == 'gt') {
                    $conditions[$f->property][] = ' ' . $f->property . ' > ' . $db->quote($date->addDay()->subSecond()->getTimestamp());
                }
            } else {
                throw new \Exception('Filer of type ' . $f->type . ' not jet supported.');
            }
        }

        $conditionsGrouped = [];
        foreach ($conditions as $fieldName => $fieldConditions) {
            if (count($fieldConditions) > 1) {
                $conditionsGrouped[$fieldName] = ' (' . implode(' AND ', $fieldConditions) . ') ';
            } else {
                $conditionsGrouped[$fieldName] = $fieldConditions[0];
            }
        }
        if ($returnString) {
            return implode(' OR ', $conditionsGrouped);
        } else {
            return $conditionsGrouped;
        }
    }
}
