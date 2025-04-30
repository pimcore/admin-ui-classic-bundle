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

use Pimcore\DependencyInjection\CollectionServiceLocator;

/**
 * @internal
 */
class Manager
{
    private ?CollectionServiceLocator $services = null;

    private ?array $sortedServices = null;

    public function __construct(CollectionServiceLocator $services)
    {
        $this->services = $services;
    }

    /**
     * Returns registered services in sorted order
     *
     * @return DataProviderInterface[]
     */
    public function getServices(): array
    {
        if (null !== $this->sortedServices) {
            return $this->sortedServices;
        }

        $this->sortedServices = $this->services->all();

        usort($this->sortedServices, function (DataProviderInterface $left, DataProviderInterface $right): int {
            if ($left->getSortPriority() === $right->getSortPriority()) {
                return 0;
            }

            return ($left->getSortPriority() < $right->getSortPriority()) ? -1 : 1;
        });

        return $this->sortedServices;
    }
}
