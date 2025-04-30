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

namespace Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler;

use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Service as GridColumnService;
use Symfony\Component\Config\Definition\Exception\InvalidDefinitionException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * @internal
 */
final class ImportExportLocatorsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->processGridColumns($container);
    }

    private function processGridColumns(ContainerBuilder $container): void
    {
        $gridColumnService = $container->getDefinition(GridColumnService::class);

        $this->createLocatorForTaggedServices(
            $container,
            $gridColumnService,
            'grid column operator factory',
            'pimcore.data_object.grid_column_config.operator_factory',
            '$operatorFactories'
        );

        $this->createLocatorForTaggedServices(
            $container,
            $gridColumnService,
            'grid column value factory',
            'pimcore.data_object.grid_column_config.value_factory',
            '$valueFactories'
        );
    }

    private function createLocatorForTaggedServices(
        ContainerBuilder $container,
        Definition $definition,
        string $type,
        string $tag,
        string $argument
    ): void {
        $resolvers = $container->findTaggedServiceIds($tag);
        $mapping = [];

        foreach ($resolvers as $id => $tagEntries) {
            foreach ($tagEntries as $tagEntry) {
                if (!isset($tagEntry['id'])) {
                    throw new InvalidDefinitionException(sprintf(
                        'The %s "%s" does not define an ID on the "%s" tag.',
                        $type,
                        $id,
                        $tag
                    ));
                }

                $mapping[$tagEntry['id']] = new Reference($id);
            }
        }

        $serviceLocator = new Definition(ServiceLocator::class, [$mapping]);
        $serviceLocator->setPublic(false);
        $serviceLocator->addTag('container.service_locator');

        $definition->setArgument($argument, $serviceLocator);
    }
}
