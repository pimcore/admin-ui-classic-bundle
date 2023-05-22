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

namespace Pimcore\Bundle\AdminBundle\Security\Event;

use Pimcore\Bundle\AdminBundle\Event\AdminEvents;
use Pimcore\Bundle\AdminBundle\Event\Login\LogoutEvent as PimcoreLogoutEvent;
use Pimcore\Model\Element\Editlock;
use Pimcore\Model\User;
use Pimcore\Tool\Authentication;
use Pimcore\Tool\Session;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Handle logout. This was originally implemented as LogoutHandler, but wasn't triggered as the token was empty at call
 * time in LogoutListener::handle was called. As the logout success handler is always triggered it is now implemented as
 * success handler.
 *
 *
 * @internal
 */
class LogoutListener implements EventSubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public static function getSubscribedEvents(): array
    {
        return [
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function __construct(
        protected TokenStorageInterface $tokenStorage,
        protected RouterInterface $router,
        protected EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();

        $event->setResponse($this->onLogoutSuccess($request));
    }

    public function onLogoutSuccess(Request $request): RedirectResponse|Response
    {
        $this->logger->debug('Logging out');

        $this->tokenStorage->setToken(null);

        // clear open edit locks for this session
        Editlock::clearSession($request->getSession()->getId());

        /** @var PimcoreLogoutEvent|null $event */
        $event = Session::useBag($request->getSession(), function (AttributeBagInterface $adminSession) use ($request) {
            $event = null;

            $user = Authentication::authenticateSession($request);
            if ($user && $user instanceof User) {
                $event = new PimcoreLogoutEvent($request, $user);
                $this->eventDispatcher->dispatch($event, AdminEvents::LOGIN_LOGOUT);

                $adminSession->remove('user');
            }

            $adminSession->clear();

            return $event;
        });

        if ($event && $event->hasResponse()) {
            $response = $event->getResponse();
        } else {
            $response = new RedirectResponse($this->router->generate('pimcore_admin_index'));
        }

        // cleanup pimcore-cookies => 315554400 => strtotime('1980-01-01')
        $response->headers->setCookie(new Cookie('pimcore_opentabs', null, 315554400));
        // clear cookie -> we can't use $response->headers->clearCookie() because it doesn't allow $secure = null
        $response->headers->setCookie(new Cookie('pimcore_admin_sid', null, 1));

        if ($response instanceof RedirectResponse) {
            $this->logger->debug('Logout succeeded, redirecting to ' . $response->getTargetUrl());
        }

        return $response;
    }
}
