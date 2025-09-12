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

namespace Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Value;

abstract class AbstractValue implements ValueInterface
{
    protected string $attribute;

    protected string $label;

    protected mixed $context;

    public function __construct(\stdClass $config, mixed $context = null)
    {
        $this->attribute = $config->attribute;
        $this->label = $config->label;
        $this->context = $context;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getRenderer(): ?string
    {
        return null;
    }
}
