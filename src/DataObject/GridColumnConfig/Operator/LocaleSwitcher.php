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

namespace Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Operator;

use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\ResultContainer;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\Element\ElementInterface;

/**
 * @internal
 */
final class LocaleSwitcher extends AbstractOperator
{
    private LocaleServiceInterface $localeService;

    private ?string $locale;

    public function __construct(LocaleServiceInterface $localeService, \stdClass $config, array $context = [])
    {
        parent::__construct($config, $context);

        $this->localeService = $localeService;
        $this->locale = $config->locale ?? null;
    }

    public function getLabeledValue(array|ElementInterface $element): ResultContainer|\stdClass|null
    {
        $result = new \stdClass();
        $result->label = $this->label;

        $children = $this->getChildren();

        if (!$children) {
            return $result;
        } else {
            $c = $children[0];

            $currentLocale = $this->localeService->getLocale();

            $this->localeService->setLocale($this->locale);

            $result = $c->getLabeledValue($element);

            $this->localeService->setLocale($currentLocale);
        }

        return $result;
    }
}
