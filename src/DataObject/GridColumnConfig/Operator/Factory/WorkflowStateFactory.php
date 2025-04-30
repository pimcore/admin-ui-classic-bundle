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

namespace Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Operator\Factory;

use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Operator\OperatorInterface;
use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Operator\WorkflowState;
use Pimcore\Workflow\Place\StatusInfo;

/**
 * @internal
 */
final class WorkflowStateFactory implements OperatorFactoryInterface
{
    private StatusInfo $workflowStatusInfo;

    public function __construct(StatusInfo $workflowStatusInfo)
    {
        $this->workflowStatusInfo = $workflowStatusInfo;
    }

    public function build(\stdClass $configElement, array $context = []): OperatorInterface
    {
        $operator = new WorkflowState($configElement, $context);
        $operator->setWorkflowStatusInfo($this->workflowStatusInfo);

        return $operator;
    }
}
