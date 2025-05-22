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

namespace Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Operator\Factory;

use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Operator\LocaleSwitcher;
use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Operator\OperatorInterface;
use Pimcore\Localization\LocaleServiceInterface;

/**
 * @internal
 */
final class LocaleSwitcherFactory implements OperatorFactoryInterface
{
    private LocaleServiceInterface $localeService;

    public function __construct(LocaleServiceInterface $localeService)
    {
        $this->localeService = $localeService;
    }

    public function build(\stdClass $configElement, array $context = []): OperatorInterface
    {
        return new LocaleSwitcher($this->localeService, $configElement, $context);
    }
}
