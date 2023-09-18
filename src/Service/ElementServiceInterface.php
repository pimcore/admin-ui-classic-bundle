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

namespace Pimcore\Bundle\AdminBundle\Service;

use Pimcore\Model\Asset;
use Pimcore\Model\Element\ElementInterface;

interface ElementServiceInterface
{
    public function getCustomViewById(string $id): ?array;

    /**
     * @throws \Exception
     */
    public function getElementTreeNodeConfig(ElementInterface $element): array;

    public function getThumbnailUrl(Asset $asset, array $params = []): ?string;
}
