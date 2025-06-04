<?php

/**
 * This source file is available under the terms of the
 * Pimcore Open Core License (POCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (https://www.pimcore.com)
 *  @license    Pimcore Open Core License (POCL)
 */

namespace Pimcore\Bundle\AdminBundle\Controller;

use Pimcore\Controller\Traits\JsonHelperTrait;
use Pimcore\Controller\UserAwareController;
use Pimcore\Model\User;
use Pimcore\Security\User\User as UserProxy;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @internal
 */
abstract class AdminAbstractController extends UserAwareController
{
    use JsonHelperTrait;

    /**
     * Returns a JsonResponse that uses the admin serializer
     */
    protected function adminJson(mixed $data, int $status = 200, array $headers = [], array $context = [], bool $useAdminSerializer = true): JsonResponse
    {
        return $this->jsonResponse($data, $status, $headers, $context, $useAdminSerializer);
    }

    /**
     * Get user from user proxy object which is registered on security component
     */
    protected function getAdminUser(bool $proxyUser = false): UserProxy|User|null
    {
        return $this->getPimcoreUser($proxyUser);
    }
}
