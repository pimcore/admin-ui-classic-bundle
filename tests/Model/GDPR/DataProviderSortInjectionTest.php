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

use Pimcore\Bundle\AdminBundle\GDPR\DataProvider\Assets;
use Pimcore\Bundle\AdminBundle\GDPR\DataProvider\DataObjects;
use Pimcore\Tests\Support\Test\ModelTestCase;

/**
 * Regression test for GHSA-j67g-8q5g-jpwc: SQL injection via the unsanitized
 * ORDER BY `property` of the sort parameter in the GDPR data providers.
 *
 * The crafted `property` below is read-only and syntactically invalid as an
 * ORDER BY expression. Against the vulnerable code it is concatenated straight
 * into the SQL by DBAL's orderBy(), so executeQuery() raises a DBAL exception
 * and these tests fail. Against the fixed code the value is rejected because it
 * is not a real column of the queried table, the ordering is skipped, and the
 * search completes normally.
 */
class DataProviderSortInjectionTest extends ModelTestCase
{
    /**
     * An injection payload that is invalid SQL when placed in an ORDER BY clause
     * (unbalanced parenthesis) and touches no data, so it deterministically errors
     * against the vulnerable code without any destructive side effect.
     */
    private const INJECTION_PROPERTY = '(SELECT 1 FROM information_schema.tables WHERE 1=1';

    private function sortParam(string $property): string
    {
        return json_encode([['property' => $property, 'direction' => 'ASC']], JSON_THROW_ON_ERROR);
    }

    public function testAssetsRejectsSortColumnInjection(): void
    {
        // A non-empty firstname bypasses the early return so the query (and its
        // ORDER BY) is actually built and executed.
        $result = (new Assets())->searchData(0, 'no-such-gdpr-term', '', '', 0, 25, $this->sortParam(self::INJECTION_PROPERTY));

        $this->assertTrue($result['success']);
    }

    public function testAssetsAppliesValidSortColumn(): void
    {
        $result = (new Assets())->searchData(0, 'no-such-gdpr-term', '', '', 0, 25, $this->sortParam('id'));

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['data']);
    }

    public function testDataObjectsRejectsSortColumnInjection(): void
    {
        $result = (new DataObjects([]))->searchData(0, 'no-such-gdpr-term', '', '', 0, 25, $this->sortParam(self::INJECTION_PROPERTY));

        $this->assertTrue($result['success']);
    }

    public function testDataObjectsAppliesValidSortColumn(): void
    {
        $result = (new DataObjects([]))->searchData(0, 'no-such-gdpr-term', '', '', 0, 25, $this->sortParam('id'));

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['data']);
    }
}
