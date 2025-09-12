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

use Pimcore\Bundle\AdminBundle\Security\ContentSecurityPolicyHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
final class ContentSecurityPolicyUrlsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $definition = $container->getDefinition(ContentSecurityPolicyHandler::class);

        $config = $container->getParameter('pimcore_admin.config');

        foreach ($config['admin_csp_header']['additional_urls'] as $additionalUrlsKey => $additionalUrlsArr) {
            $definition->addMethodCall('addAllowedUrls', [$additionalUrlsKey, $additionalUrlsArr]);
        }
    }
}
