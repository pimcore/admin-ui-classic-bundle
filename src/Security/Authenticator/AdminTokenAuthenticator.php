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

namespace Pimcore\Bundle\AdminBundle\Security\Authenticator;

use Pimcore\Security\User\User;
use Pimcore\Tool\Authentication;
use Pimcore\Tool\Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * @internal
 */
class AdminTokenAuthenticator extends AdminAbstractAuthenticator
{
    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === self::PIMCORE_ADMIN_LOGIN_CHECK
            && $request->get('token');
    }

    public function authenticate(Request $request): Passport
    {
        $pimcoreUser = Authentication::authenticateToken($request->get('token'));

        if ($pimcoreUser) {
            $pimcoreUser->setTwoFactorAuthentication('required', false);

            $userBadge = new UserBadge($pimcoreUser->getUsername(), function () use ($pimcoreUser) {
                return new User($pimcoreUser);
            });

            if ($request->get('reset', false)) {
                // save the information to session when the user want's to reset the password
                // this is because otherwise the old password is required => see also PIMCORE-1468

                Session::useBag($request->getSession(), function (AttributeBagInterface $adminSession) {
                    $adminSession->set('password_reset', true);
                });
            }

            return new SelfValidatingPassport($userBadge);
        }

        throw new AuthenticationException('Failed to authenticate with username and token');
    }
}
