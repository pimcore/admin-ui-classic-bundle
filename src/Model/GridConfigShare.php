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

namespace Pimcore\Bundle\AdminBundle\Model;

use Pimcore\Model\AbstractModel;
use Pimcore\Model\Exception\NotFoundException;

/**
 * @method GridConfigShare\Dao getDao()
 *
 * @internal
 */
class GridConfigShare extends AbstractModel
{
    protected int $gridConfigId;

    protected int $sharedWithUserId;

    public static function getByGridConfigAndSharedWithId(int $gridConfigId, int $sharedWithUserId): ?GridConfigShare
    {
        try {
            $share = new self();
            $share->getDao()->getByGridConfigAndSharedWithId($gridConfigId, $sharedWithUserId);

            return $share;
        } catch (NotFoundException $e) {
            return null;
        }
    }

    /**
     * @throws \Exception
     */
    public function save(): void
    {
        $this->getDao()->save();
    }

    /**
     * Delete this share
     */
    public function delete(): void
    {
        $this->getDao()->delete();
    }

    public function getGridConfigId(): int
    {
        return $this->gridConfigId;
    }

    public function setGridConfigId(int $gridConfigId): void
    {
        $this->gridConfigId = $gridConfigId;
    }

    public function getSharedWithUserId(): int
    {
        return $this->sharedWithUserId;
    }

    public function setSharedWithUserId(int $sharedWithUserId): void
    {
        $this->sharedWithUserId = $sharedWithUserId;
    }
}
