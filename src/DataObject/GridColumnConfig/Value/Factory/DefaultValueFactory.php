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

namespace Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Value\Factory;

use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Value\ValueInterface;
use Pimcore\Localization\LocaleServiceInterface;

final class DefaultValueFactory implements ValueFactoryInterface
{
    private string $className;

    private LocaleServiceInterface $localeService;

    public function __construct(string $className, LocaleServiceInterface $localeService)
    {
        $this->className = $className;
        $this->localeService = $localeService;
    }

    public function build(\stdClass $configElement, mixed $context = null): ValueInterface
    {
        $value = new $this->className($configElement, $context);

        if (method_exists($value, 'setLocaleService')) {
            $value->setLocaleService($this->localeService);
        }

        return $value;
    }
}
