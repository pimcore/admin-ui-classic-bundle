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
final class Text extends AbstractOperator
{
    private string $textValue;

    public function __construct(\stdClass $config, array $context = [])
    {
        parent::__construct($config, $context);

        $this->textValue = $config->textValue ?? '';
    }

    public function getLabeledValue(array|ElementInterface $element): ResultContainer|\stdClass|null
    {
        $result = new \stdClass();
        $result->label = $this->label;
        $result->value = $this->textValue;

        return $result;
    }
}
