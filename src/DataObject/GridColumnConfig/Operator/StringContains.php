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
final class StringContains extends AbstractOperator
{
    private string $search;

    private bool $insensitive;

    public function __construct(\stdClass $config, array $context = [])
    {
        parent::__construct($config, $context);

        $this->search = $config->search ?? '';
        $this->insensitive = $config->insensitive ?? false;
    }

    public function getLabeledValue(array|ElementInterface $element): ResultContainer|\stdClass|null
    {
        $result = new \stdClass();
        $result->label = $this->label;
        $result->value = null;

        $children = $this->getChildren();

        if ($children) {
            $newChildrenResult = [];

            foreach ($children as $c) {
                $childResult = $c->getLabeledValue($element);
                $childValues = $childResult->value;
                if ($childValues && !is_array($childValues)) {
                    $childValues = [$childValues];
                }

                $newValue = null;

                if (is_array($childValues)) {
                    foreach ($childValues as $value) {
                        if (is_array($value)) {
                            $newSubValues = [];
                            foreach ($value as $subValue) {
                                $subValue = $this->contains($subValue);
                                $newSubValues[] = $subValue;
                            }
                            $newValue = $newSubValues;
                        } else {
                            $newValue = $this->contains($value);
                        }
                    }
                }

                $newChildrenResult[] = $newValue;
            }

            $result->value = $newChildrenResult;
        }

        return $result;
    }

    public function contains(string $value): bool
    {
        $needle = $this->getSearch();
        if (empty($needle)) {
            return false;
        }
        if ($this->getInsensitive()) {
            return stripos($value, $this->getSearch()) !== false;
        } else {
            return strpos($value, $this->getSearch()) !== false;
        }
    }

    public function getSearch(): string
    {
        return $this->search;
    }

    public function setSearch(string $search): void
    {
        $this->search = $search;
    }

    public function getInsensitive(): bool
    {
        return $this->insensitive;
    }

    public function setInsensitive(bool $insensitive): void
    {
        $this->insensitive = $insensitive;
    }
}
