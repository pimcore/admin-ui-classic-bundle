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

namespace Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Operator;

use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\ResultContainer;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Workflow\Place\StatusInfo;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @internal
 */
final class WorkflowState extends AbstractOperator
{
    private StatusInfo $statusInfo;

    public function getLabeledValue(array|ElementInterface $element): ResultContainer|\stdClass|null
    {
        $result = new \stdClass();
        $result->label = $this->label;

        $context = $this->getContext();
        $purpose = $context['purpose'] ?? null;

        if ($purpose === 'gridview') {
            $result->value = $this->statusInfo->getAllPalacesHtml($element);
        } else {
            $result->value = $this->statusInfo->getAllPlacesForCsv($element);
        }

        return $result;
    }

    #[Required]
    public function setWorkflowStatusInfo(StatusInfo $statusInfo): void
    {
        $this->statusInfo = $statusInfo;
    }
}
