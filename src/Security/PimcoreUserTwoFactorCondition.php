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

namespace Pimcore\Bundle\AdminBundle\Security;

use Pimcore\Security\User\User;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Condition\TwoFactorConditionInterface;

/**
 * @internal
 */
class PimcoreUserTwoFactorCondition implements TwoFactorConditionInterface
{
    public function shouldPerformTwoFactorAuthentication(AuthenticationContextInterface $context): bool
    {
        //return true for performing two factor for firewalls other than admin
        if ($context->getFirewallName() !== 'pimcore_admin') {
            return true;
        }

        $user = $context->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $user->getUser()->getTwoFactorAuthentication('required');
    }
}
