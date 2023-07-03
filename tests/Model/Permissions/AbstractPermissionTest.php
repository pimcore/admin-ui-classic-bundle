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
use Pimcore\Bundle\AdminBundle\Service\ElementService;
use Pimcore\Config;
use Pimcore\Model\User;
use Pimcore\Security\User\UserLoader;
use Pimcore\Tests\Support\Helper\Pimcore;
use Pimcore\Tests\Support\Test\ModelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

abstract class AbstractPermissionTest extends ModelTestCase
{
    protected function buildController(string $classname, User $user): mixed
    {
        $pimcoreModule = $this->getModule('\\'.Pimcore::class);
        $config = $pimcoreModule->grabService(Config::class);
        $elementService = Stub::construct(
            ElementService::class,
            [
                Stub::makeEmpty(UrlGeneratorInterface::class),
                $config,
                Stub::makeEmpty(UserLoader::class, [
                    'getUser' => function () use ($user) {
                        return $user;
                    },
                ]),
            ]
        );

        return Stub::construct($classname, [$elementService], [
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
    }

    abstract public function testTreeGetChildrenById(): void;
}
