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

namespace Pimcore\Bundle\AdminBundle\Tests\Model\Controller;

use Codeception\Stub;
use Pimcore\Model\Asset;
use Pimcore\Model\Property;
use Pimcore\Model\User;
use Pimcore\Tests\Support\Test\ModelTestCase;
use Pimcore\Tests\Support\Util\TestHelper;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ModelAssetPermissionsTest extends ModelTestCase
{
    protected Asset\Folder $permissionfoo;

    protected Asset\Folder $permissionbar;

    protected Asset\Folder $groupfolder;

    protected Asset\Folder $userfolder;

    protected Asset\Folder $bars;

    protected Asset $usertestobject;

    protected Asset $grouptestobject;

    protected User $userPermissionTest1;

    protected User $userPermissionTest2;

    protected Asset $hugo;

    protected Asset\Folder $foo;

    protected Asset $hiddenobject;

    protected function setUp(): void
    {
        parent::setUp();
        TestHelper::cleanUp();

        $this->prepareObjectTree();
        $this->prepareUsers();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        TestHelper::cleanUp();
        User::getByName('Permissiontest1')->delete();
        User::getByName('Permissiontest2')->delete();
        User\Role::getByName('Testrole')->delete();
        User\Role::getByName('Dummyrole')->delete();
    }

    protected function prepareObjectTree(): void
    {
        //example based on https://github.com/pimcore/pimcore/issues/11540

        $this->permissionfoo = $this->createFolder('permissionfoo', 1);
        $this->permissionbar = $this->createFolder('permissionbar', 1);
        $this->foo = $this->createFolder('foo', $this->permissionbar->getId());

        $property = new Property();
        $property->setType('input');
        $property->setName('foobar');
        $property->setData('bars');
        $property->setInherited(false);
        $property->setInheritable(true);

        $this->bars = $this->createFolder('bars', $this->permissionfoo->getId(), [
            'foobar' => $property,
        ]);

        $this->userfolder = $this->createFolder('userfolder', $this->bars->getId());
        $this->groupfolder = $this->createFolder('groupfolder', $this->bars->getId());

        $this->hiddenobject = $this->createAsset('hiddenobject.gif', $this->foo->getId());
        $this->hugo = $this->createAsset('hugo.gif', $this->bars->getId());
        $this->usertestobject = $this->createAsset('usertestobject.gif', $this->userfolder->getId());
        $this->grouptestobject = $this->createAsset('grouptestobject.gif', $this->groupfolder->getId());
    }

    protected function prepareUsers(): void
    {
        //create role
        $role = new User\Role();
        $role->setName('Testrole');
        $role->setWorkspacesAsset([
            (new User\Workspace\Asset())->setValues(['cId' => $this->groupfolder->getId(), 'cPath' => $this->groupfolder->getFullpath(), 'list' => true, 'view' => true]),
        ]);
        $role->save();

        $role2 = new User\Role();
        $role2->setName('dummyRole');
        $role2->setWorkspacesAsset([
            (new User\Workspace\Asset())->setValues(['cId' => $this->groupfolder->getId(), 'cPath' => $this->groupfolder->getFullpath(), 'list' => false, 'view' => false, 'delete'=>false, 'publish'=>false ]),
        ]);
        $role2->save();

        //create user 1
        $this->userPermissionTest1 = new User();
        $this->userPermissionTest1->setName('Permissiontest1');
        $this->userPermissionTest1->setPermissions(['assets']);
        $this->userPermissionTest1->setRoles([$role->getId(), $role2->getId()]);
        $this->userPermissionTest1->setWorkspacesAsset([
            (new User\Workspace\Asset())->setValues(['cId' => $this->permissionfoo->getId(), 'cPath' => $this->permissionfoo->getFullpath(), 'list' => true, 'view' => true]),
            (new User\Workspace\Asset())->setValues(['cId' => $this->permissionbar->getId(), 'cPath' => $this->permissionbar->getFullpath(), 'list' => true, 'view' => true]),
            (new User\Workspace\Asset())->setValues(['cId' => $this->foo->getId(), 'cPath' => $this->foo->getFullpath(), 'list' => false, 'view' => false]),
            (new User\Workspace\Asset())->setValues(['cId' => $this->bars->getId(), 'cPath' => $this->bars->getFullpath(), 'list' => false, 'view' => false]),
            (new User\Workspace\Asset())->setValues(['cId' => $this->userfolder->getId(), 'cPath' => $this->userfolder->getFullpath(), 'list' => true, 'view' => true, 'create'=> true, 'rename'=> true]),
        ]);
        $this->userPermissionTest1->save();

        //create user 2
        $this->userPermissionTest2 = new User();
        $this->userPermissionTest2->setName('Permissiontest2');
        $this->userPermissionTest2->setPermissions(['assets']);
        $this->userPermissionTest2->setRoles([$role->getId(), $role2->getId()]);
        $this->userPermissionTest2->setWorkspacesAsset([
            (new User\Workspace\Asset())->setValues(['cId' => $this->permissionfoo->getId(), 'cPath' => $this->permissionfoo->getFullpath(), 'list' => true, 'view' => true]),
            (new User\Workspace\Asset())->setValues(['cId' => $this->permissionbar->getId(), 'cPath' => $this->permissionbar->getFullpath(), 'list' => true, 'view' => true]),
            (new User\Workspace\Asset())->setValues(['cId' => $this->foo->getId(), 'cPath' => $this->foo->getFullpath(), 'list' => false, 'view' => false]),
            (new User\Workspace\Asset())->setValues(['cId' => $this->bars->getId(), 'cPath' => $this->bars->getFullpath(), 'list' => false, 'view' => false]),
            (new User\Workspace\Asset())->setValues(['cId' => $this->userfolder->getId(), 'cPath' => $this->userfolder->getFullpath(), 'list' => true, 'view' => true]),
            (new User\Workspace\Asset())->setValues(['cId' => $this->groupfolder->getId(), 'cPath' => $this->groupfolder->getFullpath(), 'list' => false, 'view' => false, 'delete'=>true, 'publish'=>true]),
        ]);
        $this->userPermissionTest2->save();
    }

    protected function createFolder(string $key, int $parentId, array $properties = []): Asset\Folder
    {
        $folder = new Asset\Folder();
        $folder->setKey($key);
        $folder->setParentId($parentId);
        $folder->setProperties($properties);
        $folder->save();

        return $folder;
    }

    protected function createAsset(string $key, int $parentId): Asset
    {
        $asset = new Asset\Image();

        $asset->setKey($key);
        $asset->setParentId($parentId);
        $asset->setType('image');
        $asset->setData('data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==');
        $asset->setFilename($key);
        $asset->save();

        return $asset;
    }

    public function testTreeGetChildrenById(): void
    {
        $admin = User::getByName('admin');

        // test /permissionfoo
        $this->doTestTreeGetChildrenById(
            $this->permissionfoo,
            $admin,
            [$this->bars->getFullpath()]
        );

        $this->doTestTreeGetChildrenById( //did not work before (count vs. total)
            $this->permissionfoo,
            $this->userPermissionTest1,
            [$this->bars->getFullpath()]
        );

        $this->doTestTreeGetChildrenById( //did not work before
            $this->permissionfoo,
            $this->userPermissionTest2,
            [$this->bars->getFullpath()]
        );

        // test /permissionfoo/bars
        $this->doTestTreeGetChildrenById(
            $this->bars,
            $admin,
            [$this->hugo->getFullpath(), $this->userfolder->getFullpath(), $this->groupfolder->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->bars,
            $this->userPermissionTest1,
            [$this->userfolder->getFullpath(), $this->groupfolder->getFullpath()]
        );

        $this->doTestTreeGetChildrenById( //did not work before (count vs. total)
            $this->bars,
            $this->userPermissionTest2,
            [$this->userfolder->getFullpath()]
        );

        // test /permissionfoo/bars/userfolder
        $this->doTestTreeGetChildrenById(
            $this->userfolder,
            $admin,
            [$this->usertestobject->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->userfolder,
            $this->userPermissionTest1,
            [$this->usertestobject->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->userfolder,
            $this->userPermissionTest2,
            [$this->usertestobject->getFullpath()]
        );

        // test /permissionfoo/bars/groupfolder
        $this->doTestTreeGetChildrenById(
            $this->groupfolder,
            $admin,
            [$this->grouptestobject->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->groupfolder,
            $this->userPermissionTest1,
            [$this->grouptestobject->getFullpath()]
        );

        $this->doTestTreeGetChildrenById( //did not work before (count vs. total)
            $this->groupfolder,
            $this->userPermissionTest2,
            []
        );

        // test /permissionbar
        $this->doTestTreeGetChildrenById(
            $this->permissionbar,
            $admin,
            [$this->foo->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->permissionbar,
            $this->userPermissionTest1,
            []
        );

        $this->doTestTreeGetChildrenById(
            $this->permissionbar,
            $this->userPermissionTest2,
            []
        );

        // test /permissionbar/foo
        $this->doTestTreeGetChildrenById(
            $this->foo,
            $admin,
            [$this->hiddenobject->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->foo,
            $this->userPermissionTest1,
            []
        );

        $this->doTestTreeGetChildrenById(
            $this->foo,
            $this->userPermissionTest2,
            []
        );
    }

    protected function doTestTreeGetChildrenById(Asset $element, User $user, array $expectedChildren): void
    {
        $controller = $this->buildController('\\Pimcore\\Bundle\\AdminBundle\\Controller\\Admin\\Asset\\AssetController', $user);

        $request = new Request([
            'node' => $element->getId(),
            'limit' => 100,
            'view' => 0,
        ]);
        $eventDispatcher = new EventDispatcher();

        $responseData = $controller->treeGetChildrenByIdAction(
            $request,
            $eventDispatcher
        );
        $responsePaths = [];
        $responseData = json_decode($responseData->getContent(), true);
        foreach ($responseData['nodes'] as $node) {
            $responsePaths[] = $node['path'];
        }

        $this->assertCount(
            $responseData['total'],
            $responseData['nodes'],
            'Assert total count of response matches count of nodes array for `' . $element->getFullpath() . '` for user `' . $user->getName() . '`'
        );

        $this->assertCount(
            count($expectedChildren),
            $responseData['nodes'],
            'Assert number of expected result matches count of nodes array for `' . $element->getFullpath() . '` for user `' . $user->getName() . '` (' . print_r($responsePaths, true) . ')'
        );

        foreach ($expectedChildren as $path) {
            $this->assertContains(
                $path,
                $responsePaths,
                'Children of `' . $element->getFullpath() . '` do to not contain `' . $path . '` for user `' . $user->getName() . '`'
            );
        }
    }

    protected function buildController(string $classname, User $user): mixed
    {
        $AssetController = Stub::construct($classname, [], [
            'getAdminUser' => function () use ($user) {
                return $user;
            },
            'getPimcoreUser' => function () use ($user) {
                return $user;
            },
            'adminJson' => function ($data) {
                return new JsonResponse($data);
            },
            'getThumbnailUrl' => function ($asset) {
                return '';
            },
        ]);

        return $AssetController;
    }
}
