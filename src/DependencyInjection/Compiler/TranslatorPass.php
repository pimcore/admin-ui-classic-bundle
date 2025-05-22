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

use Pimcore\Translation\Translator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 */
final class TranslatorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $translationPath = $container->getParameter('pimcore_admin.translations.path');
        $translationMapping = $container->getParameter('pimcore.translations.admin_translation_mapping');
        $container
            ->getDefinition(Translator::class)
            ->addMethodCall('setAdminPath', [$translationPath])
            ->addMethodCall('setAdminTranslationMapping', [$translationMapping]);

        $editableHandlerDefinition = $container->getDefinition('Pimcore\\Document\\Editable\\EditableHandler');
        $adminUserTranslatorReference = new Reference('Pimcore\\Bundle\\AdminBundle\\Translation\\AdminUserTranslator');
        $editableHandlerDefinition->setArgument('$translator', $adminUserTranslatorReference);
    }
}
