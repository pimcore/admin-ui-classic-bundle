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

namespace Pimcore\Bundle\AdminBundle\Controller\GDPR;

use Pimcore\Bundle\AdminBundle\Controller\AdminAbstractController;
use Pimcore\Bundle\AdminBundle\GDPR\DataProvider\Manager;
use Pimcore\Controller\KernelControllerEventInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Attribute\Route;

/**
 *
 * @internal
 */
class AdminController extends AdminAbstractController implements KernelControllerEventInterface
{
    #[Route('/get-data-providers', name: 'pimcore_admin_gdpr_admin_getdataproviders', methods: ['GET'])]
    public function getDataProvidersAction(Manager $manager): JsonResponse
    {
        $response = [];
        foreach ($manager->getServices() as $service) {
            $response[] = [
                'name' => $service->getName(),
                'jsClass' => $service->getJsClassName(),
            ];
        }

        return $this->adminJson($response);
    }

    public function onKernelControllerEvent(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->checkActionPermission($event, 'gdpr_data_extractor');
    }
}
