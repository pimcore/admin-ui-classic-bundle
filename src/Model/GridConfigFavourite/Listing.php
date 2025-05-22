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
use Pimcore\Model;

/**
 * @method GridConfigFavourite\Listing\Dao getDao()
 * @method GridConfigFavourite[] load()
 * @method GridConfigFavourite|false current()
 *
 * @internal
 */
class Listing extends Model\Listing\AbstractListing
{
    /**
     * @return GridConfigFavourite[]
     */
    public function getGridconfigFavourites(): array
    {
        return $this->getData();
    }

    /**
     * @param GridConfigFavourite[]|null $gridconfigFavourites
     *
     * @return $this
     */
    public function setGridconfigFavourites(?array $gridconfigFavourites): static
    {
        return $this->setData($gridconfigFavourites);
    }
}
