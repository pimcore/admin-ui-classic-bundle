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

use Pimcore\Bundle\AdminBundle\GDPR\DataProvider\Manager;
use Pimcore\DependencyInjection\CollectionServiceLocator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 */
final class GDPRDataProviderPass implements CompilerPassInterface
{
    /**
     * Registers each service with tag pimcore.gdpr.data-provider as dataprovider for gdpr data extractor
     */
    public function process(ContainerBuilder $container): void
    {
        $providers = $container->findTaggedServiceIds('pimcore.gdpr.data-provider');

        $mapping = [];
        foreach ($providers as $id => $tags) {
            $mapping[$id] = new Reference($id);
        }

        $collectionLocator = new Definition(CollectionServiceLocator::class, [$mapping]);
        $collectionLocator->setPublic(false);
        $collectionLocator->addTag('container.service_locator');

        $manager = $container->getDefinition(Manager::class);
        $manager->setArgument('$services', $collectionLocator);
    }
}
