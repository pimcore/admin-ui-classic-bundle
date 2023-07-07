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

namespace Pimcore\Bundle\AdminBundle\Controller\Admin;

use Pimcore\Bundle\AdminBundle\Controller\AdminAbstractController;
use Pimcore\Bundle\AdminBundle\Event\AssetEvents;
use Pimcore\Bundle\AdminBundle\Event\Model\AssetDeleteInfoEvent;
use Pimcore\Bundle\AdminBundle\Event\Model\DataObjectDeleteInfoEvent;
use Pimcore\Bundle\AdminBundle\Event\Model\DocumentDeleteInfoEvent;
use Pimcore\Bundle\AdminBundle\Event\Model\ElementDeleteInfoEventInterface;
use Pimcore\Bundle\AdminBundle\Service\ElementServiceInterface;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\DocumentEvents;
use Pimcore\Logger;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Document;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Element\Service;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
abstract class ElementControllerBase extends AdminAbstractController
{
    public function __construct(
        protected ElementServiceInterface $elementService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    protected function getTreeNodeConfig(ElementInterface $element): array
    {
        return [];
    }

    /**
     * @Route("/tree-get-root", name="treegetroot", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function treeGetRootAction(Request $request): JsonResponse
    {
        $type = $request->get('elementType');
        $allowedTypes = ['asset', 'document', 'object'];

        $id = 1;
        if ($request->get('id')) {
            $id = (int)$request->get('id');
        }

        if (in_array($type, $allowedTypes)) {
            /** @var Document|Asset|AbstractObject $root */
            $root = Service::getElementById($type, $id);
            if ($root->isAllowed('list')) {
                return $this->adminJson($this->getTreeNodeConfig($root));
            }
        }

        return $this->adminJson(['success' => false, 'message' => 'missing_permission']);
    }

    /**
     * @Route("/delete-info", name="deleteinfo", methods={"GET"})
     *
     * @param Request $request
     * @param EventDispatcherInterface $eventDispatcher
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function deleteInfoAction(Request $request, EventDispatcherInterface $eventDispatcher): JsonResponse
    {
        $hasDependency = false;
        $errors = false;
        $deleteJobs = [];
        $itemResults = [];

        $totalChildren = 0;

        $ids = $request->get('id');
        $ids = explode(',', $ids);
        $type = $request->get('type');

        foreach ($ids as $id) {
            try {
                $element = Service::getElementById($type, (int) $id);
                if (!$element) {
                    continue;
                }

                if (!$hasDependency) {
                    $hasDependency = $element->getDependencies()->isRequired();
                }
            } catch (\Exception $e) {
                Logger::err('failed to access element with id: ' . $id);

                continue;
            }

            // check for children
            if ($element instanceof ElementInterface) {
                $event = null;
                $eventName = null;

                if ($element instanceof Asset) {
                    $event = new AssetDeleteInfoEvent($element);
                    $eventName = AssetEvents::DELETE_INFO;
                } elseif ($element instanceof Document) {
                    $event = new DocumentDeleteInfoEvent($element);
                    $eventName = DocumentEvents::DELETE_INFO;
                } elseif ($element instanceof AbstractObject) {
                    $event = new DataObjectDeleteInfoEvent($element);
                    $eventName = DataObjectEvents::DELETE_INFO;
                }

                if ($event instanceof ElementDeleteInfoEventInterface) {
                    $eventDispatcher->dispatch($event, $eventName);

                    if (!$event->getDeletionAllowed()) {
                        $itemResults[] = [
                            'id' => $element->getId(),
                            'type' => $element->getType(),
                            'key' => $element->getKey(),
                            'reason' => $event->getReason(),
                            'allowed' => false,
                        ];
                        $errors |= true;

                        continue;
                    }
                }

                $itemResults[] = [
                    'id' => $element->getId(),
                    'type' => $element->getType(),
                    'key' => $element->getKey(),
                    'allowed' => true,
                ];

                $deleteJobs[] = [[
                    'url' => $this->generateUrl('pimcore_admin_recyclebin_add'),
                    'method' => 'POST',
                    'params' => [
                        'type' => $type,
                        'id' => $element->getId(),
                    ],
                ]];

                $hasChildren = $element->hasChildren();
                if (!$hasDependency) {
                    $hasDependency = $hasChildren;
                }

                if ($hasChildren) {
                    // get amount of children
                    $list = $element::getList(['unpublished' => true]);
                    $pathColumn = 'path';
                    $list->setCondition($pathColumn . ' LIKE ?', [$element->getRealFullPath() . '/%']);
                    $children = $list->getTotalCount();
                    $totalChildren += $children;

                    if ($children > 0) {
                        $deleteObjectsPerRequest = 5;
                        for ($i = 0, $iMax = ceil($children / $deleteObjectsPerRequest); $i < $iMax; $i++) {
                            $deleteJobs[] = [[
                                'url' => $request->getBaseUrl() . '/admin/' . $type . '/delete',
                                'method' => 'DELETE',
                                'params' => [
                                    'step' => $i,
                                    'amount' => $deleteObjectsPerRequest,
                                    'type' => 'children',
                                    'id' => $element->getId(),
                                ],
                            ]];
                        }
                    }
                }

                // the element itself is the last one
                $deleteJobs[] = [[
                    'url' => $request->getBaseUrl() . '/admin/' . $type . '/delete',
                    'method' => 'DELETE',
                    'params' => [
                        'id' => $element->getId(),
                    ],
                ]];
            }
        }

        // get the element key in case of just one
        $elementKey = false;
        if (count($ids) === 1) {
            $element = Service::getElementById($type, (int) $ids[0]);

            if ($element instanceof ElementInterface) {
                $elementKey = $element->getKey();
            }
        }

        return $this->adminJson([
            'hasDependencies' => $hasDependency,
            'children' => $totalChildren,
            'deletejobs' => $deleteJobs,
            'batchDelete' => count($ids) > 1,
            'elementKey' => $elementKey,
            'errors' => $errors,
            'itemResults' => $itemResults,
        ]);
    }
}
