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

use Pimcore\Model\Element\ElementInterface;

/**
 * @internal
 */
abstract class AbstractRenderer extends AbstractOperator
{
    public function getLabeledValue(array|ElementInterface $element): \Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\ResultContainer|\stdClass|null
    {
        $result = new \stdClass();
        $result->label = $this->label;

        $children = $this->getChildren();

        if (!$children) {
            return $result;
        } else {
            $c = $children[0];
            $childResult = $c->getLabeledValue($element);
            if ($childResult) {
                $result->value = $childResult->value ?? null;
            }
        }

        return $result;
    }
}
