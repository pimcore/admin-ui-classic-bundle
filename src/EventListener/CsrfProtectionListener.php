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

namespace Pimcore\Bundle\AdminBundle\EventListener;

use Pimcore\Bundle\AdminBundle\Security\CsrfProtectionHandler;
use Pimcore\Bundle\CoreBundle\EventListener\Traits\PimcoreContextAwareTrait;
use Pimcore\Http\Request\Resolver\PimcoreContextResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * @internal
 */
class CsrfProtectionListener implements EventSubscriberInterface
{
    use PimcoreContextAwareTrait;

    protected Environment $twig;

    protected CsrfProtectionHandler $csrfProtectionHandler;

    public function __construct(CsrfProtectionHandler $csrfProtectionHandler)
    {
        $this->csrfProtectionHandler = $csrfProtectionHandler;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['handleRequest', 11],
        ];
    }

    public function handleRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->matchesPimcoreContext($request, PimcoreContextResolver::CONTEXT_ADMIN)) {
            return;
        }

        if ($event->getRequest()->hasSession()) {
            $this->csrfProtectionHandler->generateCsrfToken($event->getRequest()->getSession());
        }

        if ($request->isMethodCacheable()) {
            return;
        }

        $exludedRoutes = [
            // WebDAV
            'pimcore_admin_webdav',

            // external applications
            'pimcore_bundle_systeminfo_opcache_index',
        ];

        $route = $request->attributes->get('_route');
        if (in_array($route, $exludedRoutes) || in_array($route, $this->csrfProtectionHandler->getExcludedRoutes())) {
            return;
        }

        $this->csrfProtectionHandler->checkCsrfToken($request);
    }
}
