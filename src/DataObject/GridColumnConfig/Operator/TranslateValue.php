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
use Pimcore\Model\Element\ElementInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @internal
 */
final class TranslateValue extends AbstractOperator
{
    private LocaleAwareInterface|\stdClass|TranslatorInterface $translator;

    private string $prefix;

    /**
     * @var string|null
     */
    private mixed $locale = null;

    public function __construct(TranslatorInterface $translator, \stdClass $config, array $context = [])
    {
        parent::__construct($config, $context);

        $this->translator = $translator;
        $this->prefix = $config->prefix ?? '';
        if (isset($context['language'])) {
            $this->locale = $context['language'];
        }
    }

    public function getLabeledValue(array|ElementInterface $element): ResultContainer|\stdClass|null
    {
        $children = $this->getChildren();
        if (isset($children[0])) {
            $value = $children[0]->getLabeledValue($element);
            if ((string)$value->value != '') {
                $currentLocale = $this->translator->getLocale();
                if (null != $this->locale) {
                    $this->translator->setLocale($this->locale);
                }

                $value->value = $this->translator->trans($this->prefix .(string)$value->value, []);

                $this->translator->setLocale($currentLocale);
            }

            return $value;
        }

        return null;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }
}
