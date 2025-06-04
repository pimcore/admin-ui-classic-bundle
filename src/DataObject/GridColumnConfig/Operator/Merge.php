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
final class Merge extends AbstractOperator
{
    private bool $flatten;

    private bool $unique;

    public function __construct(\stdClass $config, array $context = [])
    {
        parent::__construct($config, $context);

        $this->flatten = $config->flatten ?? false;
        $this->unique = $config->unique ?? false;
    }

    public function getLabeledValue(array|ElementInterface $element): ResultContainer|\stdClass|null
    {
        $result = new \stdClass();
        $result->label = $this->label;
        $result->isArrayType = true;

        $children = $this->getChildren();
        $resultItems = [];

        foreach ($children as $c) {
            $childResult = $c->getLabeledValue($element);
            $childValues = $childResult->value ?? null;

            if ($this->flatten) {
                if (is_array($childValues)) {
                    foreach ($childValues as $childValue) {
                        if ($childValue) {
                            $resultItems[] = $childValue;
                        }
                    }
                } elseif ($childValues) {
                    $resultItems[] = $childValues;
                }
            } else {
                if ($childValues) {
                    $resultItems[] = $childValues;
                }
            }
        }

        if ($this->getUnique()) {
            $resultItems = array_unique($resultItems);
        }
        $result->value = $resultItems;

        return $result;
    }

    public function getFlatten(): bool
    {
        return $this->flatten;
    }

    public function setFlatten(bool $flatten): void
    {
        $this->flatten = $flatten;
    }

    public function getUnique(): bool
    {
        return $this->unique;
    }

    public function setUnique(bool $unique): void
    {
        $this->unique = $unique;
    }
}
