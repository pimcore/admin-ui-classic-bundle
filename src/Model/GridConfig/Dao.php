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

namespace Pimcore\Bundle\AdminBundle\Model\GridConfig;

use Pimcore\Bundle\AdminBundle\Model\GridConfig;
use Pimcore\Db\Helper;
use Pimcore\Model;
use Pimcore\Model\Exception\NotFoundException;

/**
 * @internal
 *
 * @property GridConfig $model
 */
class Dao extends Model\Dao\AbstractDao
{
    /**
     * @throws NotFoundException
     */
    public function getById(int $id): void
    {
        $data = $this->db->fetchAssociative('SELECT * FROM gridconfigs WHERE id = ?', [$id]);

        if (!$data) {
            throw new NotFoundException('gridconfig with id ' . $id . ' not found');
        }

        $this->assignVariablesToModel($data);
    }

    /**
     * Save object to database
     */
    public function save(): int
    {
        $gridconfigs = $this->model->getObjectVars();
        $data = [];

        foreach ($gridconfigs as $key => $value) {
            if (in_array($key, $this->getValidTableColumns('gridconfigs'))) {
                if (is_bool($value)) {
                    $value = (int) $value;
                }

                $data[$key] = $value;
            }
        }

        if (!$gridconfigs['saveFilters']) {
            $configData = json_decode($data['config'], true);
            unset($configData['filter']);
            $data['config'] = json_encode($configData);
        }

        $lastInsertId = Helper::upsert($this->db, 'gridconfigs', $data, $this->getPrimaryKey('gridconfigs'));
        if ($lastInsertId !== null && !$this->model->getId()) {
            $this->model->setId((int) $lastInsertId);
        }

        return $this->model->getId();
    }

    /**
     * Deletes object from database
     */
    public function delete(): void
    {
        $this->db->delete('gridconfigs', ['id' => $this->model->getId()]);
    }
}
