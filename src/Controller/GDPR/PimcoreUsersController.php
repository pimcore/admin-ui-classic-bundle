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
use Pimcore\Bundle\AdminBundle\GDPR\DataProvider\PimcoreUsers;
use Pimcore\Controller\KernelControllerEventInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Class PimcoreUsersController
 *
 * @internal
 */
#[Route('/pimcore-users')]
class PimcoreUsersController extends AdminAbstractController implements KernelControllerEventInterface
{
    public function onKernelControllerEvent(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->checkActionPermission($event, 'gdpr_data_extractor');
    }

    #[Route('/search-users', name: 'pimcore_admin_gdpr_pimcoreusers_searchusers', methods: ['GET'])]
    public function searchUsersAction(Request $request, PimcoreUsers $pimcoreUsers): JsonResponse
    {
        $allParams = array_merge($request->request->all(), $request->query->all());

        $result = $pimcoreUsers->searchData(
            (int)$allParams['id'],
            strip_tags($allParams['firstname']),
            strip_tags($allParams['lastname']),
            strip_tags($allParams['email']),
            (int)$allParams['start'],
            (int)$allParams['limit'],
            $allParams['sort'] ?? null
        );

        return $this->adminJson($result);
    }

    #[Route('/export-user-data', name: 'pimcore_admin_gdpr_pimcoreusers_exportuserdata', methods: ['GET'])]
    public function exportUserDataAction(Request $request, PimcoreUsers $pimcoreUsers): JsonResponse
    {
        $this->checkPermission('users');
        $userData = $pimcoreUsers->getExportData((int)$request->get('id'));

        $json = $this->encodeJson($userData, [], JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_PRETTY_PRINT);
        $jsonResponse = new JsonResponse($json, 200, [
            'Content-Disposition' => 'attachment; filename="export-userdata-' . $userData['id'] . '.json"',
        ], true);

        return $jsonResponse;
    }
}
