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

use Pimcore\Bundle\AdminBundle\Security\Authentication\Token\TwoFactorRequiredToken;
use Pimcore\Cache\RuntimeCache;
use Pimcore\Model\User as UserModel;
use Pimcore\Security\User\User;
use Pimcore\Tool\Admin;
use Pimcore\Tool\Authentication;
use Pimcore\Tool\Session;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @internal
 */
abstract class AdminAbstractAuthenticator extends AbstractAuthenticator implements LoggerAwareInterface
{
    public const PIMCORE_ADMIN_LOGIN = 'pimcore_admin_login';

    public const PIMCORE_ADMIN_LOGIN_CHECK = 'pimcore_admin_login_check';

    use LoggerAwareTrait;

    protected bool $twoFactorRequired = false;

    public function __construct(
        protected EventDispatcherInterface $dispatcher,
        protected RouterInterface $router,
        protected TranslatorInterface $translator
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $requestParameters = [
            'auth_failed' => 'true',
        ];

        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            $requestParameters = [
                'too_many_attempts' => $this->translator->trans($exception->getMessageKey(), $exception->getMessageData(), 'admin'),
            ];
        }
        $url = $this->router->generate(self::PIMCORE_ADMIN_LOGIN, $requestParameters);

        return new RedirectResponse($url);
    }

    /**
     * {@inheritdoc}
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $securityUser = $token->getUser();
        if (!$securityUser instanceof User) {
            throw new \Exception('Invalid user object. User has to be instance of ' . User::class);
        }

        /** @var UserModel $user */
        $user = $securityUser->getUser();

        // set user language
        $request->setLocale($user->getLanguage());
        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($user->getLanguage());
        }

        // set user on runtime cache for legacy compatibility
        RuntimeCache::set('pimcore_admin_user', $user);

        if ($user->isAdmin()) {
            if (Admin::isMaintenanceModeScheduledForLogin()) {
                Admin::activateMaintenanceMode($request->getSession()->getId());
                Admin::unscheduleMaintenanceModeOnLogin();
            }
        }

        // as we authenticate statelessly (short lived sessions) the authentication is called for
        // every request. therefore we only redirect if we're on the login page
        if (!in_array($request->attributes->get('_route'), [
            self::PIMCORE_ADMIN_LOGIN,
            self::PIMCORE_ADMIN_LOGIN_CHECK,
        ])) {
            return null;
        }

        if ($request->get('deeplink') && $request->get('deeplink') !== 'true') {
            $url = $this->router->generate('pimcore_admin_login_deeplink');
            $url .= '?' . $request->get('deeplink');
        } else {
            $url = $this->router->generate('pimcore_admin_index', [
                '_dc' => time(),
                'perspective' => strip_tags($request->get('perspective', '')),
            ]);
        }

        if ($url) {
            $response = new RedirectResponse($url);
            $response->headers->setCookie(new Cookie('pimcore_admin_sid', 'true'));

            return $response;
        }

        return null;
    }

    protected function saveUserToSession(User $user, SessionInterface $session): void
    {
        if (Authentication::isValidUser($user->getUser())) {
            $pimcoreUser = $user->getUser();

            Session::useBag($session, function (AttributeBagInterface $adminSession, SessionInterface $session) use ($pimcoreUser) {
                $session->migrate();
                $adminSession->set('user', $pimcoreUser);
            });
        }
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        if ($this->twoFactorRequired) {
            return new TwoFactorRequiredToken(
                $passport->getUser(),
                $firewallName,
                $passport->getUser()->getRoles()
            );
        } else {
            return parent::createToken($passport, $firewallName);
        }
    }
}
