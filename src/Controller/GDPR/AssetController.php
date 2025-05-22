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
use Pimcore\Bundle\AdminBundle\GDPR\DataProvider\Assets;
use Pimcore\Controller\KernelControllerEventInterface;
use Pimcore\Model\Asset;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Class AssetController
 *
 * @package GDPRDataExtractorBundle\Controller
 *
 * @internal
 */
#[Route('/asset')]
class AssetController extends AdminAbstractController implements KernelControllerEventInterface
{
    public function onKernelControllerEvent(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->checkActionPermission($event, 'gdpr_data_extractor');
    }

    #[Route('/search-assets', name: 'pimcore_admin_gdpr_asset_searchasset', methods: ['GET'])]
    public function searchAssetAction(Request $request, Assets $service): JsonResponse
    {
        $allParams = array_merge($request->request->all(), $request->query->all());

        $result = $service->searchData(
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

    /**
     * @throws \Exception
     */
    #[Route('/export', name: 'pimcore_admin_gdpr_asset_exportassets', methods: ['GET'])]
    public function exportAssetsAction(Request $request, Assets $service): Response
    {
        $asset = Asset::getById((int) $request->get('id'));
        if (!$asset) {
            throw $this->createNotFoundException('Asset not found');
        }
        if (!$asset->isAllowed('view')) {
            throw $this->createAccessDeniedException('Export denied');
        }
        $exportResult = $service->doExportData($asset);

        return $exportResult;
    }
}
