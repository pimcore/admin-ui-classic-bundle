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
