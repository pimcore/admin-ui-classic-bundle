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
