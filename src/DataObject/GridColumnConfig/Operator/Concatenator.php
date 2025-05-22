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
final class Concatenator extends AbstractOperator
{
    private string $glue;

    private bool $forceValue;

    public function __construct(\stdClass $config, array $context = [])
    {
        parent::__construct($config, $context);

        $this->glue = $config->glue ?? '';
        $this->forceValue = $config->forceValue ?? false;
    }

    public function getLabeledValue(array|ElementInterface $element): ResultContainer|\stdClass|null
    {
        $result = new \stdClass();
        $result->label = $this->label;

        $hasValue = true;
        if (!$this->forceValue) {
            $hasValue = false;
        }

        $children = $this->getChildren();
        $valueArray = [];

        foreach ($children as $c) {
            $childResult = $c->getLabeledValue($element);
            $childValues = (array)($childResult->value ?? []);

            foreach ($childValues as $value) {
                if (!$hasValue) {
                    if (is_object($value) && method_exists($value, 'isEmpty')) {
                        $hasValue = !$value->isEmpty();
                    } else {
                        $hasValue = !empty($value);
                    }
                }

                if ($value !== null) {
                    $valueArray[] = $value;
                }
            }
        }

        if ($hasValue) {
            $result->value = implode($this->glue, $valueArray);

            return $result;
        }

        $result->empty = true;

        return $result;
    }
}
