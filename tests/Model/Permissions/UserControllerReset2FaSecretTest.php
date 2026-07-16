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

namespace Pimcore\Bundle\AdminBundle\Tests\Model\Controller;

use Codeception\Stub;
use Pimcore\Bundle\AdminBundle\Controller\Admin\UserController;
use Pimcore\Model\User;
use Pimcore\Tests\Support\Test\ModelTestCase;
use Pimcore\Tests\Support\Util\TestHelper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UserControllerReset2FaSecretTest extends ModelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestHelper::cleanUp();
    }

    protected function tearDown(): void
    {
        TestHelper::cleanUp();
        parent::tearDown();
    }

    private function buildController(User $actingUser): UserController
    {
        return Stub::construct(UserController::class, [], [
            'getAdminUser' => function () use ($actingUser) {
                return $actingUser;
            },
            'adminJson' => function ($data) {
                return new JsonResponse($data);
            },
        ]);
    }

    private function createUser(string $name, bool $isAdmin): User
    {
        $user = new User();
        $user->setName($name);
        $user->setAdmin($isAdmin);
        $user->setPermissions(['users']);
        $user->setTwoFactorAuthentication('enabled', true);
        $user->setTwoFactorAuthentication('secret', 'some-secret-value');
        $user->save();

        return $user;
    }

    private function resetSecretRequest(int $targetUserId): Request
    {
        return new Request([], ['id' => $targetUserId]);
    }

    public function testNonAdminCannotResetAnotherUsersSecret(): void
    {
        $actor = $this->createUser('reset2fa-actor', false);
        $target = $this->createUser('reset2fa-target', false);

        $controller = $this->buildController($actor);

        $this->expectException(AccessDeniedHttpException::class);
        $controller->reset2FaSecretAction($this->resetSecretRequest($target->getId()));
    }

    public function testNonAdminCannotResetAnAdminsSecret(): void
    {
        $actor = $this->createUser('reset2fa-actor', false);
        $admin = $this->createUser('reset2fa-admin-target', true);

        $controller = $this->buildController($actor);

        $this->expectException(AccessDeniedHttpException::class);
        $controller->reset2FaSecretAction($this->resetSecretRequest($admin->getId()));
    }

    public function testNonAdminCanResetTheirOwnSecret(): void
    {
        $actor = $this->createUser('reset2fa-self', false);

        $controller = $this->buildController($actor);
        $response = $controller->reset2FaSecretAction($this->resetSecretRequest($actor->getId()));

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        $reloaded = User::getById($actor->getId(), ['force' => true]);
        $this->assertFalse($reloaded->getTwoFactorAuthentication('enabled'));
        $this->assertSame('', $reloaded->getTwoFactorAuthentication('secret'));
    }

    public function testAdminCanResetAnyUsersSecret(): void
    {
        $admin = $this->createUser('reset2fa-admin-actor', true);
        $target = $this->createUser('reset2fa-other-target', false);

        $controller = $this->buildController($admin);
        $response = $controller->reset2FaSecretAction($this->resetSecretRequest($target->getId()));

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        $reloaded = User::getById($target->getId(), ['force' => true]);
        $this->assertFalse($reloaded->getTwoFactorAuthentication('enabled'));
        $this->assertSame('', $reloaded->getTwoFactorAuthentication('secret'));
    }
}
