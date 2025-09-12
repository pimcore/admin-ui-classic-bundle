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

namespace Pimcore\Bundle\AdminBundle\Model\GridConfigFavourite;

use Pimcore\Bundle\AdminBundle\Model\GridConfigFavourite;
use Pimcore\Db\Helper;
use Pimcore\Model;

/**
 * @internal
 *
 * @property GridConfigFavourite $model
 */
class Dao extends Model\Dao\AbstractDao
{
    /**
     * @throws Model\Exception\NotFoundException
     */
    public function getByOwnerAndClassAndObjectId(int $ownerId, string $classId, ?int $objectId = null, ?string $searchType = null): void
    {
        $query = 'SELECT * FROM gridconfig_favourites WHERE ownerId = ? AND classId = ? AND searchType = ?';
        $params = [$ownerId, $classId, $searchType];
        if (!is_null($objectId)) {
            $query .= ' AND objectId = ?';
            $params[] = $objectId;
        }

        $data = $this->db->fetchAssociative($query, $params);

        if (!$data) {
            throw new Model\Exception\NotFoundException('gridconfig favourite with ownerId ' . $ownerId . ' and class id ' . $classId . ' not found');
        }

        $this->assignVariablesToModel($data);
    }

    /**
     * Save object to database
     */
    public function save(): GridConfigFavourite
    {
        $gridConfigFavourite = $this->model->getObjectVars();
        $data = [];

        foreach ($gridConfigFavourite as $key => $value) {
            if (in_array($key, $this->getValidTableColumns('gridconfig_favourites'))) {
                if (is_bool($value)) {
                    $value = (int) $value;
                }

                $data[$key] = $value;
            }
        }

        Helper::upsert($this->db, 'gridconfig_favourites', $data, $this->getPrimaryKey('gridconfig_favourites'));

        return $this->model;
    }

    /**
     * Deletes object from database
     */
    public function delete(): void
    {
        $params = ['ownerId' => $this->model->getOwnerId(), 'classId' => $this->model->getClassId()];
        if ($this->model->getSearchType()) {
            $params['searchType'] = $this->model->getSearchType();
        }

        if ($this->model->getObjectId()) {
            $params['objectId'] = $this->model->getSearchType();
        }

        $this->db->delete('gridconfig_favourites', $params);
    }
}
