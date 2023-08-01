<?php

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

namespace Pimcore\Bundle\AdminBundle\Model\GridConfig\Listing;

use Pimcore\Bundle\AdminBundle\Model\GridConfig;
use Pimcore\Model;

/**
 * @internal
 *
 * @property GridConfig\Listing $model
 */
class Dao extends Model\Listing\Dao\AbstractDao
{
    /**
     * Loads a list of gridconfigs for the specicified parameters, returns an array of GridConfig elements
     *
     * @return array
     */
    public function load(): array
    {
        $gridConfigs = [];
        $data = $this->db->fetchAllAssociative('SELECT * FROM gridconfigs ' . $this->getCondition() . $this->getOrder() . $this->getOffsetLimit(), $this->model->getConditionVariables());

        foreach ($data as $configData) {
            $configData['shareGlobally'] = (bool)$configData['shareGlobally'];
            $configData['setAsFavourite'] = (bool)$configData['setAsFavourite'];
            $gridConfig = new GridConfig();
            $gridConfig->setValues($configData);
            $gridConfigs[] = $gridConfig;
        }

        $this->model->setGridConfigs($gridConfigs);

        return $gridConfigs;
    }

    public function getTotalCount(): int
    {
        try {
            return (int) $this->db->fetchOne('SELECT COUNT(*) FROM gridconfigs ' . $this->getCondition(), $this->model->getConditionVariables());
        } catch (\Exception $e) {
            return 0;
        }
    }
}
