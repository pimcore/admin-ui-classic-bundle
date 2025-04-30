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
final class Trimmer extends AbstractOperator
{
    const LEFT = 1;

    const RIGHT = 2;

    const BOTH = 3;

    private int $trim;

    public function __construct(\stdClass $config, array $context = [])
    {
        parent::__construct($config, $context);

        $this->trim = $config->trim ?? 0;
    }

    public function getLabeledValue(array|ElementInterface $element): ResultContainer|\stdClass|null
    {
        $result = new \stdClass();
        $result->label = $this->label;

        $children = $this->getChildren();

        if (!$children) {
            return $result;
        } else {
            $c = $children[0];

            $valueArray = [];

            $childResult = $c->getLabeledValue($element);
            $isArrayType = $childResult->isArrayType ?? false;
            $childValues = $childResult->value ?? null;
            if ($childValues && !$isArrayType) {
                $childValues = [$childValues];
            }

            if ($childValues) {
                /** @var string $childValue */
                foreach ($childValues as $childValue) {
                    if ($this->trim == self::LEFT) {
                        $childValue = ltrim($childValue);
                    } elseif ($this->trim == self::RIGHT) {
                        $childValue = rtrim($childValue);
                    } elseif ($this->trim == self::BOTH) {
                        $childValue = trim($childValue);
                    }
                    $valueArray[] = $childValue;
                }
            }

            $result->isArrayType = $isArrayType;
            if ($isArrayType) {
                $result->value = $valueArray;
            } else {
                $result->value = $valueArray[0] ?? null;
            }
        }

        return $result;
    }
}
