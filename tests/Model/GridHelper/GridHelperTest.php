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

namespace Pimcore\Bundle\AdminBundle\Tests\Model\GridHelper;

use Pimcore\Bundle\AdminBundle\Helper\GridHelperService;
use Pimcore\Db;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Tests\Support\Test\ModelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

class GridHelperTest extends ModelTestCase
{
    public function testAddGridFeatureJoinsWithTwoFilters(): void
    {
        $name = 'inheritance';
        $class = ClassDefinition::getByName($name);

        $list = new \Pimcore\Model\DataObject\Inheritance\Listing();

        $list->setCondition("(`path` = '/tmp' OR `path` like '/tmp/%') AND 1 = 1");
        $list->setLimit(25);
        $list->setOffset(0);
        $list->setGroupBy('oo_id');
        $list->setOrder('ASC');

        $featureJoins = [];
        $featureJoins[0] = [
            'fieldname' => 'teststore',
            'groupId' => 1,
            'keyId' => 1,
            'language' => 'default',
        ];
        $featureJoins[1] = [
            'fieldname' => 'teststore',
            'groupId' => 1,
            'keyId' => 2,
            'language' => 'default',
        ];

        $featureConditions = [
            'cskey_teststore_1_1' => "`cskey_teststore_1_1` LIKE '%t%'",
            'cskey_teststore_1_2' => "`cskey_teststore_1_2` LIKE '%t77%'",
        ];

        $featureAndSlugFilters = [
            'featureJoins' => $featureJoins,
            'slugJoins' => [],
            'featureConditions' => $featureConditions,
            'slugConditions' => [],
        ];

        $queryBuilder = Db::get()->createQueryBuilder();

        $gridHelperService = new GridHelperService(new EventDispatcher());
        $gridHelperService->addGridFeatureJoins($list, $featureJoins, $class, $featureAndSlugFilters);

        $dao = $list->getDao();

        $method = $this->getPrivateMethod($dao, 'applyListingParametersToQueryBuilder');
        $method->invokeArgs($dao, [$queryBuilder]);

        $expectedJoin0 = [
            'joinType' => 'left',
            'joinTable' => 'object_classificationstore_data_inheritance',
            'joinAlias' => 'cskey_teststore_1_1',
            'joinCondition' => "(cskey_teststore_1_1.id = object_localized_inheritance_en.id and cskey_teststore_1_1.fieldname = 'teststore' and cskey_teststore_1_1.groupId=1 and cskey_teststore_1_1.keyId=1 and cskey_teststore_1_1.language = 'default')",
        ];

        $expectedJoin1 = [
            'joinType' => 'left',
            'joinTable' => 'object_classificationstore_data_inheritance',
            'joinAlias' => 'cskey_teststore_1_2',
            'joinCondition' => "(cskey_teststore_1_2.id = object_localized_inheritance_en.id and cskey_teststore_1_2.fieldname = 'teststore' and cskey_teststore_1_2.groupId=1 and cskey_teststore_1_2.keyId=2 and cskey_teststore_1_2.language = 'default')",
        ];

        $selectParts = $queryBuilder->getQueryPart('select');

        $this->assertTrue(in_array('cskey_teststore_1_1.value AS cskey_teststore_1_1', $selectParts));
        $this->assertTrue(in_array('cskey_teststore_1_2.value AS cskey_teststore_1_2', $selectParts));

        $joins = $queryBuilder->getQueryPart('join')['object_localized_inheritance_en'];

        $this->assertEquals($expectedJoin0, $joins[0]);
        $this->assertEquals($expectedJoin1, $joins[1]);

        $this->assertEquals("`cskey_teststore_1_1` LIKE '%t%' AND `cskey_teststore_1_2` LIKE '%t77%'", $queryBuilder->getQueryPart('having')->__toString());
    }

    public function getPrivateMethod(mixed $className, string $methodName): \ReflectionMethod
    {
        $reflector = new \ReflectionClass($className);
        $method = $reflector->getMethod($methodName);
        $method->setAccessible(true);

        return $method;
    }
}
