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

namespace Pimcore\Bundle\AdminBundle\System;

use Pimcore\Cache\RuntimeCache;
use Pimcore\Config\LocationAwareConfigRepository;
use Pimcore\Helper\SystemConfig;

/**
 * @internal
 */
final class AdminConfig
{
    private const CONFIG_ID = 'admin_system_settings';

    private const BRANDING = 'branding';

    private const ASSETS = 'assets';

    private const SCOPE = 'pimcore_admin_system_settings';

    private static ?LocationAwareConfigRepository $locationAwareConfigRepository = null;

    private static function getRepository(): LocationAwareConfigRepository
    {
        if (!self::$locationAwareConfigRepository) {
            $containerConfig = \Pimcore::getContainer()->getParameter('pimcore_admin.config');
            $config[self::CONFIG_ID][self::BRANDING] = $containerConfig[self::BRANDING];
            $config[self::CONFIG_ID][self::ASSETS] = $containerConfig[self::ASSETS];
            $storageConfig = $containerConfig['config_location'][self::CONFIG_ID];

            self::$locationAwareConfigRepository = new LocationAwareConfigRepository(
                $config,
                self::SCOPE,
                $storageConfig
            );
        }

        return self::$locationAwareConfigRepository;
    }

    public static function get(): array
    {
        $repository = self::getRepository();

        $data = SystemConfig::getConfigDataByKey($repository, self::CONFIG_ID);
        $loadType = $repository->getReadTargets()[0] ?? null;

        // If the read target is settings-store and no data is found there,
        // load the data from the container config
        if(!$data && $loadType === $repository::LOCATION_SETTINGS_STORE) {
            $containerConfig = \Pimcore::getContainer()->getParameter('pimcore_admin.config');
            $data[self::BRANDING] = $containerConfig[self::BRANDING];
            $data[self::ASSETS] = $containerConfig[self::ASSETS];
            $data['writeable'] = $repository->isWriteable();
        }

        return $data;
    }

    public function save(array $values): void
    {
        $repository = self::getRepository();

        $data[self::BRANDING] = [
            'login_screen_invert_colors' => $values['branding.login_screen_invert_colors'],
            'color_login_screen' => $values['branding.color_login_screen'],
            'color_admin_interface' => $values['branding.color_admin_interface'],
            'color_admin_interface_background' => $values['branding.color_admin_interface_background'],
            'login_screen_custom_image' => str_replace('%', '%%', $values['branding.login_screen_custom_image']),
        ];

        $data[self::ASSETS] = [
            'hide_edit_image' => $values['assets.hide_edit_image'],
            'disable_tree_preview' => $values['assets.disable_tree_preview'],
        ];

        $repository->saveConfig(self::CONFIG_ID, $data, function ($key, $data) {
            return [
                'pimcore_admin' => $data,
            ];
        });
    }

    /**
     *
     * @internal
     */
    public function getAdminSystemSettingsConfig(): array
    {
        if (RuntimeCache::isRegistered('pimcore_admin_system_settings_config')) {
            $config = RuntimeCache::get('pimcore_admin_system_settings_config');
        } else {
            $config = $this->get();
            $this->setAdminSystemSettingsConfig($config);
        }

        return $config;
    }

    /**
     *
     * @internal
     */
    public function setAdminSystemSettingsConfig(array $config): void
    {
        RuntimeCache::set('pimcore_admin_system_settings_config', $config);
    }
}
