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

namespace Pimcore\Bundle\AdminBundle\Event\Model;

use Pimcore\Bundle\AdminBundle\Event\Traits\ElementDeleteInfoEventTrait;
use Pimcore\Event\Model\AssetEvent;

/**
 * Class AssetDeleteInfoEvent
 *
 * @package Pimcore\Event\Model
 */
class AssetDeleteInfoEvent extends AssetEvent implements ElementDeleteInfoEventInterface
{
    use ElementDeleteInfoEventTrait;
}
