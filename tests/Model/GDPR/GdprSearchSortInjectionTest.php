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

namespace Pimcore\Bundle\AdminBundle\Tests\Model\GDPR;

use Doctrine\DBAL\Exception as DBALException;
use Pimcore\Bundle\AdminBundle\GDPR\DataProvider\Assets;
use Pimcore\Bundle\AdminBundle\GDPR\DataProvider\DataObjects;
use Pimcore\Tests\Support\Test\ModelTestCase;

/**
 * Regression test for GHSA-g8pm-xq73-xqq8: blind SQL injection through the `sort` property of the
 * GDPR search endpoints. The sort property is placed into the ORDER BY clause, where it cannot be
 * bound as a parameter, so it must be quoted as an identifier. An unquoted value lets an
 * authenticated `gdpr_data_extractor` user run arbitrary SQL expressions in ORDER BY.
 *
 * Each injection case uses `(SELECT 1)` as the sort property: that is a valid SQL expression but
 * not a column name. Against the vulnerable code it is emitted verbatim as `ORDER BY (SELECT 1)`
 * and executes without error, so the test's expected exception is never thrown and the test fails.
 * Against the fixed code it is quoted to `ORDER BY `(SELECT 1)``, which the database rejects as an
 * unknown column, proving the value can no longer break out of the identifier position.
 */
class GdprSearchSortInjectionTest extends ModelTestCase
{
    private const INJECTION_SORT = '[{"property":"(SELECT 1)","direction":"ASC"}]';

    public function testDataObjectsSearchRejectsSortInjection(): void
    {
        $this->expectException(DBALException::class);

        (new DataObjects([]))->searchData(1, '', '', '', 0, 10, self::INJECTION_SORT);
    }

    public function testAssetsSearchRejectsSortInjection(): void
    {
        $this->expectException(DBALException::class);

        (new Assets([]))->searchData(1, '', '', '', 0, 10, self::INJECTION_SORT);
    }

    public function testDataObjectsSearchAllowsLegitimateSort(): void
    {
        $result = (new DataObjects([]))->searchData(1, '', '', '', 0, 10, '[{"property":"id","direction":"ASC"}]');

        $this->assertTrue($result['success']);
    }

    public function testAssetsSearchAllowsLegitimateSort(): void
    {
        $result = (new Assets([]))->searchData(1, '', '', '', 0, 10, '[{"property":"filename","direction":"ASC"}]');

        $this->assertTrue($result['success']);
    }
}
