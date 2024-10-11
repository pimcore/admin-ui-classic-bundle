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
use Pimcore\Model\Element\Service;
use Pimcore\Model\Notification\Service\NotificationService;
use Pimcore\Model\Notification\Service\NotificationServiceFilterParser;
use Pimcore\Model\Notification\Service\UserService;
use Pimcore\Model\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @internal
 */
#[Route(path: '/notification', name: 'pimcore_admin_notification_')]
class NotificationController extends AdminAbstractController
{
    #[Route(path: '/recipients', name: 'recipients', methods: ['GET'])]
    public function recipientsAction(UserService $service, TranslatorInterface $translator): JsonResponse
    {
        $this->checkPermission('notifications_send');

        $data = [];

        foreach ($service->findAll($this->getAdminUser()) as $recipient) {
            $group = $translator->trans('group', [], 'admin');
            $prefix = $recipient->getType() == 'role' ? $group . ' - ' : '';

            $data[] = [
                'id' => $recipient->getId(),
                'text' => $prefix . $recipient->getName(),
            ];
        }

        return $this->adminJson($data);
    }

    #[Route(path: '/send', name: 'send', methods: ['POST'])]
    public function sendAction(Request $request, NotificationService $service): JsonResponse
    {
        $this->checkPermission('notifications_send');

        $recipientId = $request->request->getInt('recipientId');
        $fromUser = (int) $this->getAdminUser()->getId();
        $title = $request->request->get('title', '');
        $message = $request->request->get('message', '');
        $element = null;
        $elementId = $request->request->getInt('elementId');
        $elementType = $request->request->get('elementType');

        if ($elementId && $elementType) {
            $element = Service::getElementById($elementType, $elementId);
        }

        if (User::getById($recipientId) instanceof User) {
            $service->sendToUser($recipientId, $fromUser, $title, $message, $element);
        } else {
            $service->sendToGroup($recipientId, $fromUser, $title, $message, $element);
        }

        return $this->adminJson(['success' => true]);
    }

    #[Route(path: '/find', name: 'find', methods: ['GET'])]
    public function findAction(Request $request, NotificationService $service): JsonResponse
    {
        $this->checkPermission('notifications');

        $id = $request->query->getInt('id');

        try {
            $notification = $service->findAndMarkAsRead($id, $this->getAdminUser()->getId());
        } catch (\UnexpectedValueException) {
            return $this->adminJson(
                [
                    'success' => false,
                ]
            );
        }

        $data = $service->format($notification);

        return $this->adminJson([
            'success' => true,
            'data' => $data,
        ]);
    }

    #[Route(path: '/find-all', name: 'findall', methods: ['POST'])]
    public function findAllAction(Request $request, NotificationService $service): JsonResponse
    {
        $this->checkPermission('notifications');

        $filter = ['recipient' => (int) $this->getAdminUser()->getId()];
        $parser = new NotificationServiceFilterParser($request);

        foreach ($parser->parse() as $key => $val) {
            $filter[$key] = $val;
        }

        $options = [
            'offset' => $request->request->getInt('start'),
            'limit' => $request->request->getInt('limit', 40),
        ];

        $result = $service->findAll($filter, $options);

        $data = [];

        foreach ($result['data'] as $notification) {
            $data[] = $service->format($notification);
        }

        return $this->adminJson([
            'success' => true,
            'total' => $result['total'],
            'data' => $data,
        ]);
    }

    #[Route(path: '/find-last-unread', name: 'findlastunread', methods: ['GET'])]
    public function findLastUnreadAction(Request $request, NotificationService $service): JsonResponse
    {
        $this->checkPermission('notifications');

        $user = $this->getAdminUser();
        $lastUpdate = $request->query->getInt('lastUpdate', time());
        $result = $service->findLastUnread((int) $user->getId(), $lastUpdate);
        $unread = $service->countAllUnread((int) $user->getId());

        $data = [];

        foreach ($result['data'] as $notification) {
            $data[] = $service->format($notification);
        }

        return $this->adminJson([
            'success' => true,
            'total' => $result['total'],
            'data' => $data,
            'unread' => $unread,
        ]);
    }

    #[Route(path: '/mark-as-read', name: 'markasread', methods: ['PUT'])]
    public function markAsReadAction(Request $request, NotificationService $service): JsonResponse
    {
        $this->checkPermission('notifications');

        $id = $request->query->getInt('id');
        $service->findAndMarkAsRead($id, $this->getAdminUser()->getId());

        return $this->adminJson(['success' => true]);
    }

    #[Route(path: '/delete', name: 'delete', methods: ['DELETE'])]
    public function deleteAction(Request $request, NotificationService $service): JsonResponse
    {
        $this->checkPermission('notifications');

        $id = $request->query->getInt('id');
        $service->delete($id, $this->getAdminUser()->getId());

        return $this->adminJson(['success' => true]);
    }

    #[Route(path: '/delete-all', name: 'deleteall', methods: ['DELETE'])]
    public function deleteAllAction(NotificationService $service): JsonResponse
    {
        $this->checkPermission('notifications');

        $user = $this->getAdminUser();
        $service->deleteAll((int) $user->getId());

        return $this->adminJson(['success' => true]);
    }
}
