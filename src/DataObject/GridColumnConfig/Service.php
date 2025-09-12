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

namespace Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig;

use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Operator\Factory\OperatorFactoryInterface;
use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Operator\OperatorInterface;
use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Value\Factory\ValueFactoryInterface;
use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Value\ValueInterface;
use Psr\Container\ContainerInterface;

/**
 * @internal
 */
final class Service
{
    private ContainerInterface $operatorFactories;

    private ContainerInterface $valueFactories;

    public function __construct(
        ContainerInterface $operatorFactories,
        ContainerInterface $valueFactories
    ) {
        $this->operatorFactories = $operatorFactories;
        $this->valueFactories = $valueFactories;
    }

    /**
     * @param \stdClass[] $jsonConfigs
     *
     * @return ConfigElementInterface[]
     */
    public function buildOutputDataConfig(array $jsonConfigs, array $context = []): array
    {
        return $this->doBuildConfig($jsonConfigs, [], $context);
    }

    /**
     * @param \stdClass[] $jsonConfigs
     *
     * @return ConfigElementInterface[]
     */
    private function doBuildConfig(array $jsonConfigs, array $config, array $context = []): array
    {
        if (empty($jsonConfigs)) {
            return $config;
        }

        foreach ($jsonConfigs as $configElement) {
            if ('value' === $configElement->type) {
                $config[] = $this->buildValue($configElement->class, $configElement, $context);
            } elseif ('operator' === $configElement->type) {
                if (!empty($configElement->children)) {
                    $configElement->children = $this->doBuildConfig($configElement->children, [], $context);
                }

                $operator = $this->buildOperator($configElement->class, $configElement, $context);
                if ($operator) {
                    $config[] = $operator;
                }
            }
        }

        return $config;
    }

    private function buildOperator(string $name, \stdClass $configElement, array $context = []): ?OperatorInterface
    {
        if (!$this->operatorFactories->has($name)) {
            throw new \InvalidArgumentException(sprintf('Operator "%s" is not supported', $name));
        }

        /** @var OperatorFactoryInterface $factory */
        $factory = $this->operatorFactories->get($name);

        return $factory->build($configElement, $context);
    }

    private function buildValue(string $name, \stdClass $configElement, mixed $context = null): ValueInterface
    {
        if (!$this->valueFactories->has($name)) {
            throw new \InvalidArgumentException(sprintf('Value "%s" is not supported', $name));
        }

        /** @var ValueFactoryInterface $factory */
        $factory = $this->valueFactories->get($name);

        return $factory->build($configElement, $context);
    }
}
