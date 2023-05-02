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

namespace Pimcore\Bundle\AdminBundle\EventListener;

use Pimcore;
use Pimcore\Event\SystemEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * @internal
 */
class AdminConfigListener implements EventSubscriberInterface
{
    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            SystemEvents::GET_SYSTEM_CONFIGURATION => 'updateSystemConfiguration',
        ];
    }

    public function updateSystemConfiguration(GenericEvent $event): void
    {
        $arguments = $event->getArguments();
        $config = $arguments['settings'];

        if (!$config || !$container = Pimcore::getContainer()) {
            return;
        }

        $adminConfig = $container->getParameter('pimcore_admin.config');
        $configuration = array_merge_recursive($config, $adminConfig);

        $event->setArgument('settings', $configuration);
    }
}
