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

namespace Pimcore\Bundle\AdminBundle\Controller\Admin\Document;

use Pimcore\Model\Document;
use Pimcore\Model\Schedule\Task;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @internal
 */
#[Route('/hardlink', name: 'pimcore_admin_document_hardlink_')]
class HardlinkController extends DocumentControllerBase
{
    /**
     * @throws \Exception
     */
    #[Route('/get-data-by-id', name: 'getdatabyid', methods: ['GET'])]
    public function getDataByIdAction(Request $request): JsonResponse
    {
        $link = Document\Hardlink::getById((int)$request->get('id'));

        if (!$link) {
            throw $this->createNotFoundException('Hardlink not found');
        }

        if (($lock = $this->checkForLock($link, $request->getSession()->getId())) instanceof JsonResponse) {
            return $lock;
        }

        $link = clone $link;
        $link->setParent(null);

        $data = $link->getObjectVars();
        $data['locked'] = $link->isLocked();
        $data['scheduledTasks'] = array_map(
            static function (Task $task) {
                return $task->getObjectVars();
            },
            $link->getScheduledTasks()
        );

        $this->addTranslationsData($link, $data);
        $this->minimizeProperties($link, $data);
        $this->populateUsersNames($link, $data);

        if ($link->getSourceDocument()) {
            $data['sourcePath'] = $link->getSourceDocument()->getRealFullPath();
        }

        return $this->preSendDataActions($data, $link);
    }

    /**
     * @throws \Exception
     */
    #[Route('/save', name: 'save', methods: ['PUT', 'POST'])]
    public function saveAction(Request $request): JsonResponse
    {
        $link = Document\Hardlink::getById((int) $request->get('id'));
        if (!$link) {
            throw $this->createNotFoundException('Hardlink not found');
        }

        $result = $this->saveDocument($link, $request);
        /** @var Document\Hardlink $link */
        $link = $result[1];
        $treeData = $this->getTreeNodeConfig($link);

        return $this->adminJson([
            'success' => true,
            'data' => [
                'versionDate' => $link->getModificationDate(),
                'versionCount' => $link->getVersionCount(),
            ],
            'treeData' => $treeData,
        ]);
    }

    /**
     * @param Document\Hardlink $document
     */
    protected function setValuesToDocument(Request $request, Document $document): void
    {
        // data
        if ($request->get('data')) {
            $data = $this->decodeJson($request->get('data'));

            $sourceId = null;
            if ($sourceDocument = Document::getByPath($data['sourcePath'])) {
                $sourceId = $sourceDocument->getId();
            }
            $document->setSourceId($sourceId);
            $document->setValues($data);
        }

        $this->addPropertiesToDocument($request, $document);
        $this->applySchedulerDataToElement($request, $document);
    }
}
