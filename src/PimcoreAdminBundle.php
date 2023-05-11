<?php
declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\AdminBundle;

use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\ContentSecurityPolicyUrlsPass;
use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\GDPRDataProviderPass;
use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\ImportExportLocatorsPass;
use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\SerializerPass;
use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\TranslatorPass;
use Pimcore\Bundle\AdminBundle\GDPR\DataProvider\DataProviderInterface;
use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\HttpKernel\Bundle\DependentBundleInterface;
use Pimcore\HttpKernel\BundleCollection\BundleCollection;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\WebpackEncoreBundle\WebpackEncoreBundle;

class PimcoreAdminBundle extends AbstractPimcoreBundle implements DependentBundleInterface
{
    /**
     * {@inheritdoc}
     */
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
