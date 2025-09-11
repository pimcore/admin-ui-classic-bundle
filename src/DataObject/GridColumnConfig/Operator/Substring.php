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
final class Substring extends AbstractOperator
{
    private int $start;

    private int $length;

    private bool $ellipses;

    public function __construct(\stdClass $config, array $context = [])
    {
        parent::__construct($config, $context);

        $this->start = $config->start ?? 0;
        $this->length = $config->length ?? 0;
        $this->ellipses = $config->ellipses ?? false;
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

            if (is_array($childValues)) {
                $start = $this->getStart();
                $length = $this->getLength();
                $useEllipses = $this->getEllipses();

                /** @var string|array $childValue */
                foreach ($childValues as $childValue) {

                    if (!$childValue) {
                        continue;
                    }

                    if (!is_string($childValue)) {
                        $childValue = $this->convertToString($childValue);
                    }

                    $showEllipses = $useEllipses && mb_strlen($childValue) > ($start + $length);

                    $childValue = mb_substr($childValue, $start, $length);
                    if ($showEllipses) {
                        $childValue .= '...';
                    }

                    $valueArray[] = $childValue;
                }
            } else {
                $valueArray[] = $childResult->value;
            }

            $result->isArrayType = $isArrayType;
            if ($isArrayType) {
                $result->value = $valueArray;
            } else {
                $result->value = $valueArray[0] ?? '';
            }
        }

        return $result;
    }

    private function convertToString(mixed $value): string
    {
        if (is_array($value)) {
            $output = implode(
                ' ',
                array_map(
                    fn ($v) => is_scalar($v) || $v instanceof \Stringable ? (string)$v : '',
                    $value
                )
            );
        } elseif (is_object($value)) {
            $output = $value instanceof \Stringable
                ? (string) $value
                : '';
        } else {
            // fallback for other types (int, float, bool)
            $output = (string) $value;
        }

        return $output;
    }

    public function getStart(): int
    {
        return $this->start;
    }

    public function setStart(int $start): void
    {
        $this->start = $start;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function setLength(int $length): void
    {
        $this->length = $length;
    }

    public function getEllipses(): bool
    {
        return $this->ellipses;
    }

    public function setEllipses(bool $ellipses): void
    {
        $this->ellipses = $ellipses;
    }
}
