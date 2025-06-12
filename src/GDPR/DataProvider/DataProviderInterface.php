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

namespace Pimcore\Bundle\AdminBundle\GDPR\DataProvider;

interface DataProviderInterface
{
    /**
     * Returns sort priority - higher is sorted first
     */
    public function getSortPriority(): int;

    /**
     * Returns name of DataProvider
     */
    public function getName(): string;

    /**
     * Returns JavaScript class name of frontend implementation
     */
    public function getJsClassName(): string;
}
