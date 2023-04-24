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

namespace Pimcore\Bundle\AdminBundle\Controller\Traits;

use Pimcore\Bundle\AdminBundle\Controller\AdminAbstractController;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Schedule\Task;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
trait ApplySchedulerDataTrait
{
    protected function applySchedulerDataToElement(Request $request, ElementInterface $element): void
    {
        /** @var AdminAbstractController $this */

        // scheduled tasks
        if ($request->get('scheduler')) {
            $tasks = [];
            $tasksData = $this->decodeJson($request->get('scheduler'));

            if (!empty($tasksData)) {
                foreach ($tasksData as $taskData) {
                    $taskData['userId'] = $this->getAdminUser()->getId();

                    $task = new Task($taskData);
                    $tasks[] = $task;
                }
            }

            if ($element->isAllowed('settings') && method_exists($element, 'setScheduledTasks')) {
                $element->setScheduledTasks($tasks);
            }
        }
    }
}
