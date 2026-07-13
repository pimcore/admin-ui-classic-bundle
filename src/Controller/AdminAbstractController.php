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
use Pimcore\Model\Element;
use Pimcore\Model\User;
use Pimcore\Model\Version;
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

    /**
     * Ensures the current admin user has 'versions' permission on the element a version
     * belongs to. Version-management endpoints act on an attacker-suppliable version id,
     * so this must be checked per-request rather than relying on the /admin firewall
     * (which only requires ROLE_PIMCORE_USER for every backend user).
     *
     * Type-specific endpoints (e.g. the document/asset/object publish-version actions) must
     * pass $expectedCtype so a version belonging to one element type can't be used to pass
     * authorization there and then be applied against an unrelated element of another type
     * that happens to share the same numeric id.
     */
    protected function checkVersionAuthorization(Version $version, ?string $expectedCtype = null): void
    {
        if ($expectedCtype !== null && $version->getCtype() !== $expectedCtype) {
            throw $this->createAccessDeniedException('Permission denied, version id [' . $version->getId() . ']');
        }

        $element = Element\Service::getElementById($version->getCtype(), $version->getCid());
        if (!$element || !$element->isAllowed('versions')) {
            throw $this->createAccessDeniedException('Permission denied, version id [' . $version->getId() . ']');
        }
    }
}
