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

/**
 * @internal
 */
final class IsEqual extends AbstractOperator
{
    private bool $skipNull;

    public function __construct(\stdClass $config, array $context = [])
    {
        parent::__construct($config, $context);

        $this->skipNull = $config->skipNull ?? false;
    }

    public function getLabeledValue(array|ElementInterface $element): ResultContainer|\stdClass|null
    {
        $result = new \stdClass();
        $result->label = $this->label;

        $children = $this->getChildren();

        if (!$children) {
            return $result;
        } else {
            $isEqual = true;
            $valueArray = [];
            foreach ($children as $c) {
                $childResult = $c->getLabeledValue($element);
                $isArrayType = $childResult->isArrayType ?? false;
                $childValues = $childResult->value ?? null;
                if ($childValues && !$isArrayType) {
                    $childValues = [$childValues];
                }

                if (is_array($childValues)) {
                    foreach ($childValues as $value) {
                        if (is_null($value) && $this->skipNull) {
                            continue;
                        }
                        $valueArray[] = $value;
                    }
                } else {
                    if (!$this->skipNull) {
                        $valueArray[] = null;
                    }
                }
            }

            $firstValue = current($valueArray);
            foreach ($valueArray as $val) {
                if ($firstValue !== $val) {
                    $isEqual = false;

                    break;
                }
            }
            $result->value = $isEqual;
        }

        return $result;
    }

    public function getSkipNull(): bool
    {
        return $this->skipNull;
    }

    public function setSkipNull(bool $skipNull): void
    {
        $this->skipNull = $skipNull;
    }
}
