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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Pimcore\Extension\Bundle\Installer\SettingsStoreAwareInstaller;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

class Installer extends SettingsStoreAwareInstaller
{
    protected const USER_PERMISSIONS_CATEGORY = 'Pimcore Admin Bundle';

    const USER_PERMISSIONS = [
        'admin_translations',
        'gdpr_data_extractor',
        'system_appearance_settings',
    ];

    private array $tablesToInstall = [
        'translations_admin' =>
            "CREATE TABLE `translations_admin` (
              `key` varchar(190) NOT NULL DEFAULT '' COLLATE 'utf8mb4_bin',
              `type` varchar(10) DEFAULT NULL,
              `language` varchar(10) NOT NULL DEFAULT '',
              `text` text,
              `creationDate` int(11) unsigned DEFAULT NULL,
              `modificationDate` int(11) unsigned DEFAULT NULL,
              `userOwner` int(11) unsigned DEFAULT NULL,
              `userModification` int(11) unsigned DEFAULT NULL,
              PRIMARY KEY (`key`,`language`),
              KEY `language` (`language`)
            ) DEFAULT CHARSET=utf8mb4;",
    ];

    protected ?Schema $schema = null;

    public function __construct(
        protected BundleInterface $bundle,
        protected Connection $db
    ) {
        parent::__construct($bundle);
    }

    protected function addPermissions(): void
    {
        $db = \Pimcore\Db::get();

        foreach (self::USER_PERMISSIONS as $permission) {
            $db->insert('users_permission_definitions', [
                $db->quoteIdentifier('key') => $permission,
                $db->quoteIdentifier('category') => self::USER_PERMISSIONS_CATEGORY,
            ]);
        }
    }

    protected function removePermissions(): void
    {
        $db = \Pimcore\Db::get();

        foreach (self::USER_PERMISSIONS as $permission) {
            $db->delete('users_permission_definitions', [
                $db->quoteIdentifier('key') => $permission,
            ]);
        }
    }

    public function install(): void
    {
        $this->addPermissions();
        $this->installTables();
        parent::install();
    }

    private function installTables(): void
    {
        foreach ($this->tablesToInstall as $name => $statement) {
            if ($this->getSchema()->hasTable($name)) {
                $this->output->write(sprintf(
                    '     <comment>WARNING:</comment> Skipping table "%s" as it already exists',
                    $name
                ));

                continue;
            }

            $this->db->executeQuery($statement);
        }
    }

    private function uninstallTables(): void
    {
        foreach (array_keys($this->tablesToInstall) as $table) {
            if (!$this->getSchema()->hasTable($table)) {
                $this->output->write(sprintf(
                    '     <comment>WARNING:</comment> Not dropping table "%s" as it doesn\'t exist',
                    $table
                ));

                continue;
            }

            $this->db->executeQuery("DROP TABLE IF EXISTS $table");
        }
    }

    public function uninstall(): void
    {
        $this->removePermissions();
        $this->uninstallTables();

        parent::uninstall();
    }

    protected function getSchema(): Schema
    {
        return $this->schema ??= $this->db->createSchemaManager()->introspectSchema();
    }
}
