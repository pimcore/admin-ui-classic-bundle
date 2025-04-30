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
use Pimcore\Bundle\AdminBundle\GDPR\DataProvider\DataObjects;
use Pimcore\Controller\KernelControllerEventInterface;
use Pimcore\Model\DataObject;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Class DataObjectController
 *
 * @internal
 */
#[Route('/data-object')]
class DataObjectController extends AdminAbstractController implements KernelControllerEventInterface
{
    public function onKernelControllerEvent(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->checkActionPermission($event, 'gdpr_data_extractor');
    }

    #[Route('/search-data-objects', name: 'pimcore_admin_gdpr_dataobject_searchdataobjects', methods: ['GET'])]
    public function searchDataObjectsAction(Request $request, DataObjects $service): JsonResponse
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
    #[Route('/export', name: 'pimcore_admin_gdpr_dataobject_exportdataobject', methods: ['GET'])]
    public function exportDataObjectAction(Request $request, DataObjects $service): JsonResponse
    {
        $object = DataObject::getById((int) $request->get('id'));
        if (!$object) {
            throw $this->createNotFoundException('Object not found');
        }
        if (!$object->isAllowed('view')) {
            throw $this->createAccessDeniedException('Export denied');
        }

        $exportResult = $service->doExportData($object);

        $json = $this->encodeJson($exportResult, [], JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_PRETTY_PRINT);
        $jsonResponse = new JsonResponse($json, 200, [
            'Content-Disposition' => 'attachment; filename="export-data-object-' . $object->getId() . '.json"',
        ], true);

        return $jsonResponse;
    }
}
