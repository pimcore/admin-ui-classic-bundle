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

use Pimcore\Bundle\AdminBundle\Controller\Admin\DataObject\DataObjectController;
use Pimcore\Model\DataObject;
use Pimcore\Model\User;
use Pimcore\Tests\Support\Util\TestHelper;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ModelDataObjectPermissionsTest extends AbstractPermissionTest
{
    protected DataObject\Folder $permissionfoo;

    protected DataObject\Folder $permissionbar;

    protected DataObject\Folder $foo;

    protected DataObject\Folder $bars;

    protected User $userPermissionTest1;

    protected User $userPermissionTest2;

    protected User $userPermissionTest3;

    protected User $userPermissionTest4;

    protected User $userPermissionTest5;

    protected User $userPermissionTest6;

    protected DataObject\AbstractObject $hugo;

    protected DataObject\Folder $userfolder;

    protected DataObject\Folder $groupfolder;

    protected DataObject\AbstractObject $usertestobject;

    protected DataObject\AbstractObject $grouptestobject;

    protected DataObject\AbstractObject $hiddenobject;

    public function setUp(): void
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
        User::getByName('Permissiontest3')->delete();
        User::getByName('Permissiontest4')->delete();
        User::getByName('Permissiontest5')->delete();
        User::getByName('Permissiontest6')->delete();
        User\Role::getByName('Testrole')->delete();
        User\Role::getByName('Dummyrole')->delete();
    }

    protected function prepareObjectTree(): void
    {
        $this->permissionfoo = $this->createFolder('permissionfoo', 1);
        $this->permissionbar = $this->createFolder('permissionbar', 1);
        $this->foo = $this->createFolder('foo', $this->permissionbar->getId());
        $this->bars = $this->createFolder('bars', $this->permissionfoo->getId());
        $this->userfolder = $this->createFolder('userfolder', $this->bars->getId());
        $this->groupfolder = $this->createFolder('groupfolder', $this->bars->getId());

        $this->hiddenobject = $this->createObject('hiddenobject', $this->foo->getId());
        $this->hugo = $this->createObject('hugo', $this->bars->getId());
        $this->usertestobject = $this->createObject('usertestobject', $this->userfolder->getId());
        $this->grouptestobject = $this->createObject('grouptestobject', $this->groupfolder->getId());
    }

    protected function prepareUsers(): void
    {
        //create role
        $role = new User\Role();
        $role->setName('Testrole');
        $role->setWorkspacesObject([
            (new User\Workspace\DataObject())->setValues(['cId' => $this->groupfolder->getId(), 'cPath' => $this->groupfolder->getFullpath(), 'list' => true, 'view' => true, 'save'=>true, 'publish'=>false ]),
        ]);
        $role->save();

        $role2 = new User\Role();
        $role2->setName('dummyRole');
        $role2->setWorkspacesObject([
            (new User\Workspace\DataObject())->setValues(['cId' => $this->groupfolder->getId(), 'cPath' => $this->groupfolder->getFullpath(), 'list' => false, 'view' => false, 'save'=>false, 'publish'=>false, 'settings' => true ]),
        ]);
        $role2->save();

        //create user 1
        $this->userPermissionTest1 = new User();
        $this->userPermissionTest1->setName('Permissiontest1');
        $this->userPermissionTest1->setPermissions(['objects']);
        $this->userPermissionTest1->setRoles([$role->getId(), $role2->getId()]);
        $this->userPermissionTest1->setWorkspacesObject([
            (new User\Workspace\DataObject())->setValues(['cId' => $this->permissionfoo->getId(), 'cPath' => $this->permissionfoo->getFullpath(), 'list' => true, 'view' => true]),
            (new User\Workspace\DataObject())->setValues(['cId' => $this->permissionbar->getId(), 'cPath' => $this->permissionbar->getFullpath(), 'list' => true, 'view' => true]),
            (new User\Workspace\DataObject())->setValues(['cId' => $this->foo->getId(), 'cPath' => $this->foo->getFullpath(), 'list' => false, 'view' => false]),
            (new User\Workspace\DataObject())->setValues(['cId' => $this->bars->getId(), 'cPath' => $this->bars->getFullpath(), 'list' => false, 'view' => false]),
            (new User\Workspace\DataObject())->setValues(['cId' => $this->userfolder->getId(), 'cPath' => $this->userfolder->getFullpath(), 'list' => true, 'view' => true, 'create'=> true, 'rename'=> true]),
        ]);
        $this->userPermissionTest1->save();

        //create user 2
        $this->userPermissionTest2 = new User();
        $this->userPermissionTest2->setName('Permissiontest2');
        $this->userPermissionTest2->setPermissions(['objects']);
        $this->userPermissionTest2->setRoles([$role->getId(), $role2->getId()]);
        $this->userPermissionTest2->setWorkspacesObject([
            (new User\Workspace\DataObject())->setValues(['cId' => $this->permissionfoo->getId(), 'cPath' => $this->permissionfoo->getFullpath(), 'list' => true, 'view' => true]),
            (new User\Workspace\DataObject())->setValues(['cId' => $this->permissionbar->getId(), 'cPath' => $this->permissionbar->getFullpath(), 'list' => true, 'view' => true]),
            (new User\Workspace\DataObject())->setValues(['cId' => $this->foo->getId(), 'cPath' => $this->foo->getFullpath(), 'list' => false, 'view' => false]),
            (new User\Workspace\DataObject())->setValues(['cId' => $this->bars->getId(), 'cPath' => $this->bars->getFullpath(), 'list' => false, 'view' => false]),
            (new User\Workspace\DataObject())->setValues(['cId' => $this->userfolder->getId(), 'cPath' => $this->userfolder->getFullpath(), 'list' => true, 'view' => true]),
            (new User\Workspace\DataObject())->setValues(['cId' => $this->groupfolder->getId(), 'cPath' => $this->groupfolder->getFullpath(), 'list' => false, 'view' => false, 'save'=>true, 'publish'=>true, 'settings' => false]),
        ]);
        $this->userPermissionTest2->save();

        //create user 3, with no roles, only usertestobject allowed
        $this->userPermissionTest3 = new User();
        $this->userPermissionTest3->setName('Permissiontest3');
        $this->userPermissionTest3->setPermissions(['objects']);
        $this->userPermissionTest3->setWorkspacesObject([
            (new User\Workspace\DataObject())->setValues(['cId' => $this->usertestobject->getId(), 'cPath' => $this->usertestobject->getFullpath(), 'list' => true, 'view' => true]),
        ]);
        $this->userPermissionTest3->save();

        //create user 4, with no user workspace rules, only from roles
        $this->userPermissionTest4 = new User();
        $this->userPermissionTest4->setName('Permissiontest4');
        $this->userPermissionTest4->setPermissions(['objects']);
        $this->userPermissionTest4->setRoles([$role->getId(), $role2->getId()]);
        $this->userPermissionTest4->save();

        //create user 5, with no roles, assets and data objects allowed in parallel
        $this->userPermissionTest5 = new User();
        $this->userPermissionTest5->setName('Permissiontest5');
        $this->userPermissionTest5->setPermissions(['assets', 'objects']);
        $this->userPermissionTest5->setWorkspacesObject([
            (new User\Workspace\DataObject())->setValues(['cId' => $this->usertestobject->getId(), 'cPath' => $this->usertestobject->getFullpath(), 'list' => true, 'view' => true]),
        ]);
        $this->userPermissionTest5->save();

        //create user 6, with no roles, with no permissions set but workspaces configured --> should not find anything
        $this->userPermissionTest6 = new User();
        $this->userPermissionTest6->setName('Permissiontest6');
        $this->userPermissionTest6->setPermissions([]);
        $this->userPermissionTest6->setWorkspacesObject([
            (new User\Workspace\DataObject())->setValues(['cId' => $this->usertestobject->getId(), 'cPath' => $this->usertestobject->getFullpath(), 'list' => true, 'view' => true]),
        ]);

        $this->userPermissionTest6->save();
    }

    protected function createFolder(string $key, int $parentId): DataObject\Folder
    {
        $folder = new DataObject\Folder();
        $folder->setKey($key);
        $folder->setParentId($parentId);
        $folder->save();

        return $folder;
    }

    protected function createObject(string $key, int $parentId): DataObject\AbstractObject
    {
        $object = TestHelper::createEmptyObject();

        $object->setKey($key);
        $object->setInput($key);
        $object->setParentId($parentId);
        $object->setPublished(true);

        $object->save();

        return $object;
    }

    /**
     * @param array|null $expectedChildren When null,the main permission is disabled
     *
     * @throws \ReflectionException
     */
    protected function doTestTreeGetChildrenById(
        DataObject\AbstractObject $element,
        User $user,
        ?array $expectedChildren
    ): void {
        $controller = $this->buildController(DataObjectController::class, $user);

        $request = new Request([
            'node' => $element->getId(),
        ]);
        $eventDispatcher = new EventDispatcher();

        try {
            TestHelper::callMethod($controller, 'checkPermission', ['objects']);
            $responseData = $controller->treeGetChildrenByIdAction(
                $request,
                $eventDispatcher
            );
        } catch (\Exception $e) {
            if (is_null($expectedChildren)) {
                $this->assertInstanceOf(AccessDeniedHttpException::class, $e, 'Assert main object permission');

                return;
            }
        }

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

    public function testTreeGetChildrenById(): void
    {
        $admin = User::getByName('admin');

        // test /permissionfoo
        $this->doTestTreeGetChildrenById(
            $this->permissionfoo,
            $admin,
            [$this->bars->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->permissionfoo,
            $this->userPermissionTest1,
            [$this->bars->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->permissionfoo,
            $this->userPermissionTest2,
            [$this->bars->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->permissionfoo,
            $this->userPermissionTest3,
            [$this->bars->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->permissionfoo,
            $this->userPermissionTest4,
            [$this->bars->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->permissionfoo,
            $this->userPermissionTest5,
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

        $this->doTestTreeGetChildrenById(
            $this->bars,
            $this->userPermissionTest2,
            [$this->userfolder->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->bars,
            $this->userPermissionTest3,
            [$this->userfolder->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->bars,
            $this->userPermissionTest4,
            [$this->groupfolder->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->bars,
            $this->userPermissionTest5,
            [$this->userfolder->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->bars,
            $this->userPermissionTest6,
            null
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

        $this->doTestTreeGetChildrenById(
            $this->userfolder,
            $this->userPermissionTest3,
            [$this->usertestobject->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->userfolder,
            $this->userPermissionTest4,
            []
        );

        $this->doTestTreeGetChildrenById(
            $this->userfolder,
            $this->userPermissionTest5,
            [$this->usertestobject->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->userfolder,
            $this->userPermissionTest6,
            null
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

        $this->doTestTreeGetChildrenById(
            $this->groupfolder,
            $this->userPermissionTest2,
            []
        );

        $this->doTestTreeGetChildrenById(
            $this->groupfolder,
            $this->userPermissionTest3,
            []
        );

        $this->doTestTreeGetChildrenById(
            $this->groupfolder,
            $this->userPermissionTest4,
            [$this->grouptestobject->getFullpath()]
        );

        $this->doTestTreeGetChildrenById(
            $this->groupfolder,
            $this->userPermissionTest5,
            []
        );

        $this->doTestTreeGetChildrenById(
            $this->groupfolder,
            $this->userPermissionTest6,
            null
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

        $this->doTestTreeGetChildrenById(
            $this->permissionbar,
            $this->userPermissionTest3,
            []
        );

        $this->doTestTreeGetChildrenById(
            $this->permissionbar,
            $this->userPermissionTest4,
            []
        );

        $this->doTestTreeGetChildrenById(
            $this->permissionbar,
            $this->userPermissionTest5,
            []
        );

        $this->doTestTreeGetChildrenById(
            $this->permissionbar,
            $this->userPermissionTest6,
            null
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

        $this->doTestTreeGetChildrenById(
            $this->foo,
            $this->userPermissionTest3,
            []
        );

        $this->doTestTreeGetChildrenById(
            $this->foo,
            $this->userPermissionTest4,
            []
        );

        $this->doTestTreeGetChildrenById(
            $this->foo,
            $this->userPermissionTest5,
            []
        );

        $this->doTestTreeGetChildrenById(
            $this->foo,
            $this->userPermissionTest6,
            null
        );
    }
}
