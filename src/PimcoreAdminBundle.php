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

namespace Pimcore\Bundle\AdminBundle;

use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\ContentSecurityPolicyUrlsPass;
use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\GDPRDataProviderPass;
use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\ImportExportLocatorsPass;
use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\SerializerPass;
use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\TranslatorPass;
use Pimcore\Bundle\AdminBundle\DependencyInjection\PimcoreAdminExtension;
use Pimcore\Bundle\AdminBundle\GDPR\DataProvider\DataProviderInterface;
use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\Extension\Bundle\Traits\PackageVersionTrait;
use Pimcore\HttpKernel\Bundle\DependentBundleInterface;
use Pimcore\HttpKernel\BundleCollection\BundleCollection;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\WebpackEncoreBundle\WebpackEncoreBundle;

/**
 * @deprecated version 2.3
 */
class PimcoreAdminBundle extends AbstractPimcoreBundle implements DependentBundleInterface
{
    use PackageVersionTrait;

    public function __construct()
    {
        trigger_deprecation(
            'pimcore/admin-ui-classic-bundle',
            '2.3',
            'The AdminUiClassicBundle is deprecated and will be discontinued with Pimcore Studio.'
        );
    }

    public function getComposerPackageName(): string
    {
        return 'pimcore/admin-ui-classic-bundle';
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new PimcoreAdminExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // auto-tag GDPR data providers
        $container
            ->registerForAutoconfiguration(DataProviderInterface::class)
            ->addTag('pimcore.gdpr.data-provider');

        $container->addCompilerPass(new SerializerPass());
        $container->addCompilerPass(new GDPRDataProviderPass());
        $container->addCompilerPass(new ImportExportLocatorsPass());
        $container->addCompilerPass(new TranslatorPass());
        $container->addCompilerPass(new ContentSecurityPolicyUrlsPass());
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public static function registerDependentBundles(BundleCollection $collection): void
    {
        $collection->addBundle(new WebpackEncoreBundle());
    }

    public function getInstaller(): ?Installer
    {
        return $this->container->get(Installer::class);
    }
}
