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
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element\ElementInterface;

/**
 * @internal
 */
final class VersionGetter extends AbstractOperator
{
    public function getLabeledValue(array|ElementInterface $element): ResultContainer|\stdClass|null
    {
        $result = new \stdClass();
        $result->label = $this->label;

        if (!$element instanceof Concrete) {
            // TODO: Should we handle arrays?
            return $result;
        }

        $children = $this->getChildren();

        if (!$children) {
            return $result;
        }

        $c = $children[0];

        $valueArray = [];

        $latestVersion = $element->getLatestVersion(null, false);
        if ($latestVersion) {
            $element = $latestVersion->loadData();
        }

        $childResult = $c->getLabeledValue($element);
        $isArrayType = $childResult->isArrayType ?? null;
        $childValues = $childResult->value;
        if ($childValues && !$isArrayType) {
            $childValues = [$childValues];
        }

        if ($childValues) {
            /** @var string $childValue */
            foreach ($childValues as $childValue) {
                $valueArray[] = $childValue;
            }
        }

        $result->isArrayType = $isArrayType;
        if ($isArrayType) {
            $result->value = $valueArray;
        } else {
            $result->value = $valueArray[0] ?? null;
        }

        return $result;
    }
}
