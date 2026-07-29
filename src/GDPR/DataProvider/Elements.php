<?php

declare(strict_types=1);

/**
 * This source file is available under the terms of the
 * Pimcore Open Core License (POCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (https://www.pimcore.com)
 *  @license    Pimcore Open Core License (POCL)
 */

namespace Pimcore\Bundle\AdminBundle\GDPR\DataProvider;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/**
 * @internal
 */
abstract class Elements implements DataProviderInterface
{
    /**
     * Validates a user-supplied ORDER BY column against the real columns of the
     * given table.
     *
     * Doctrine DBAL's QueryBuilder::orderBy() concatenates the column name into
     * the SQL string without quoting or binding, so a value taken straight from
     * the request allows SQL injection via the sort parameter. Only a value that
     * matches an existing column of $table is returned; every other value
     * (including any injection payload) yields null so the caller can safely omit
     * the ordering. Schema introspection failures fail closed for the same reason.
     */
    protected function getValidSortColumn(Connection $db, string $table, string $column): ?string
    {
        try {
            $tableColumns = $db->createSchemaManager()->listTableColumns($table);
        } catch (Exception) {
            return null;
        }

        return array_key_exists(strtolower($column), $tableColumns) ? $column : null;
    }

    protected function prepareQueryString(string $query): string
    {
        if ($query == '*') {
            $query = '';
        }

        $query = str_replace('%', '*', $query);
        $query = str_replace('@', '#', $query);
        $query = preg_replace("@([^ ])\-@", '$1 ', $query);

        return $query;
    }
}
