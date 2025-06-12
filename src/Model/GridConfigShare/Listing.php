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

namespace Pimcore\Bundle\AdminBundle\Model\GridConfigShare;

use Pimcore\Bundle\AdminBundle\Model\GridConfigShare;
use Pimcore\Model;

/**
 * @method GridConfigShare\Listing\Dao getDao()
 * @method GridConfigShare[] load()
 * @method GridConfigShare|false current()
 *
 * @internal
 */
class Listing extends Model\Listing\AbstractListing
{
    /**
     * @return GridConfigShare[]
     */
    public function getGridconfigShares(): array
    {
        return $this->getData();
    }

    /**
     * @param GridConfigShare[]|null $gridconfigShares
     *
     * @return $this
     */
    public function setGridconfigShares(?array $gridconfigShares): static
    {
        return $this->setData($gridconfigShares);
    }
}
