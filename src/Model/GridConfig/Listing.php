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
use Pimcore\Model;

/**
 * @method GridConfig\Listing\Dao getDao()
 * @method GridConfig[] load()
 * @method GridConfig|false current()
 *
 * @internal
 */
class Listing extends Model\Listing\AbstractListing
{
    /**
     * @return GridConfig[]
     */
    public function getGridConfigs(): array
    {
        return $this->getData();
    }

    /**
     * @param GridConfig[]|null $gridConfigs
     *
     * @return $this
     */
    public function setGridConfigs(?array $gridConfigs): static
    {
        return $this->setData($gridConfigs);
    }
}
