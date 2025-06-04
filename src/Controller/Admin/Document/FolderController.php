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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @internal
 */
#[Route('/folder', name: 'pimcore_admin_document_folder_')]
class FolderController extends DocumentControllerBase
{
    /**
     * @throws \Exception
     */
    #[Route('/get-data-by-id', name: 'getdatabyid', methods: ['GET'])]
    public function getDataByIdAction(Request $request): JsonResponse
    {
        $folder = Document\Folder::getById((int)$request->get('id'));
        if (!$folder) {
            throw $this->createNotFoundException('Folder not found');
        }

        $folder = clone $folder;
        $folder->setParent(null);

        $data = $folder->getObjectVars();
        $data['locked'] = $folder->isLocked();

        $this->addTranslationsData($folder, $data);
        $this->minimizeProperties($folder, $data);
        $this->populateUsersNames($folder, $data);

        return $this->preSendDataActions($data, $folder);
    }

    /**
     * @throws \Exception
     */
    #[Route('/save', name: 'save', methods: ['PUT', 'POST'])]
    public function saveAction(Request $request): JsonResponse
    {
        $folder = Document\Folder::getById((int) $request->get('id'));
        if (!$folder) {
            throw $this->createNotFoundException('Folder not found');
        }

        $result = $this->saveDocument($folder, $request, false, self::TASK_PUBLISH);
        /** @var Document\Folder $folder */
        $folder = $result[1];
        $treeData = $this->getTreeNodeConfig($folder);

        return $this->adminJson(['success' => true, 'treeData' => $treeData]);
    }

    protected function setValuesToDocument(Request $request, Document $document): void
    {
        $this->addPropertiesToDocument($request, $document);
    }
}
