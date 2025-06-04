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

namespace Pimcore\Bundle\AdminBundle\EventListener;

use Pimcore\Db;
use Pimcore\Event\DataObjectClassDefinitionEvents;
use Pimcore\Event\Model\DataObject\ClassDefinitionEvent;
use Pimcore\Event\Model\UserRoleEvent;
use Pimcore\Event\UserRoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @internal
 */
class ImportConfigListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            DataObjectClassDefinitionEvents::POST_DELETE => 'onClassDelete',
            UserRoleEvents::POST_DELETE => 'onUserDelete',
        ];
    }

    public function onClassDelete(ClassDefinitionEvent $event): void
    {
        $class = $event->getClassDefinition();
        $classId = $class->getId();

        // collect gridConfigs for that class id
        $db = Db::get();
        $importConfigIds = $db->fetchFirstColumn('select id from importconfigs where classId = ?', [$classId]);
        if ($importConfigIds) {
            $db->executeQuery('delete from importconfig_shares where importConfigId in (' . implode($importConfigIds) . ')');
        }

        $this->cleanupImportConfigs('classId = ' . $db->quote($classId));
    }

    public function onUserDelete(UserRoleEvent $event): void
    {
        $user = $event->getUserRole();
        $userId = $user->getId();

        $db = Db::get();

        $importConfigIds = $db->fetchFirstColumn('select id from importconfigs where ownerId = ?', [$userId]);
        if ($importConfigIds) {
            $db->executeQuery('delete from importconfig_shares where importConfigId in (' . implode($importConfigIds) . ')');
        }

        $this->cleanupImportConfigs('ownerId = ' . $userId);
    }

    protected function cleanupImportConfigs(string $condition): void
    {
        $db = Db::get();
        $db->executeQuery('DELETE FROM importconfigs where ' . $condition);
    }
}
