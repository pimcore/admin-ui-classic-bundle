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
