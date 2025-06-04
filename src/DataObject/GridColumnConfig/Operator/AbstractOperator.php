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

use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\ConfigElementInterface;
use Pimcore\Tool;

abstract class AbstractOperator implements OperatorInterface
{
    protected string $label;

    protected array $context = [];

    /**
     * @var ConfigElementInterface[]
     */
    protected array $children;

    public function __construct(\stdClass $config, array $context = [])
    {
        $this->label = $config->label;
        $this->children = $config->children;
        $this->context = $context;
    }

    /**
     * @return ConfigElementInterface[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function expandLocales(): bool
    {
        return false;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    /**
     * @return string[]
     */
    public function getValidLanguages(): array
    {
        return Tool::getValidLanguages();
    }

    public function getRenderer(): ?string
    {
        return null;
    }
}
