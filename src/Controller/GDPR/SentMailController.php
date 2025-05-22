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
use Pimcore\Controller\KernelControllerEventInterface;
use Pimcore\Model\Tool\Email\Log;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Class SentMailController
 *
 * @internal
 */
#[Route('/sent-mail')]
class SentMailController extends AdminAbstractController implements KernelControllerEventInterface
{
    public function onKernelControllerEvent(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->checkActionPermission($event, 'gdpr_data_extractor');
    }

    #[Route('/export', name: 'pimcore_admin_gdpr_sentmail_exportdataobject', methods: ['GET'])]
    public function exportDataObjectAction(Request $request): JsonResponse
    {
        $this->checkPermission('emails');

        $sentMail = Log::getById((int) $request->get('id'));
        if (!$sentMail) {
            throw $this->createNotFoundException();
        }

        $sentMailArray = (array)$sentMail;
        $sentMailArray['htmlBody'] = $sentMail->getHtmlLog();
        $sentMailArray['textBody'] = $sentMail->getTextLog();

        $json = $this->encodeJson($sentMailArray, [], JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_PRETTY_PRINT);
        $jsonResponse = new JsonResponse($json, 200, [
            'Content-Disposition' => 'attachment; filename="export-mail-' . $sentMail->getId() . '.json"',
        ], true);

        return $jsonResponse;
    }
}
