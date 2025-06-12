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

namespace Pimcore\Bundle\AdminBundle\EventListener\Traits;

use Symfony\Component\HttpKernel\Event\ControllerEvent;

/**
 * @internal
 */
trait ControllerTypeTrait
{
    /**
     * Get controller of specified type
     */
    protected function getControllerType(ControllerEvent $event, string $type): mixed
    {
        $callable = $event->getController();

        if (!is_array($callable) || count($callable) === 0) {
            return null;
        }

        $controller = $callable[0];
        if ($controller instanceof $type) {
            return $controller;
        }

        return null;
    }

    /**
     * Test if event controller is of the given type
     */
    protected function isControllerType(ControllerEvent $event, string $type): bool
    {
        $controller = $this->getControllerType($event, $type);

        return $controller && $controller instanceof $type;
    }
}
