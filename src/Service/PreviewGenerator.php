<?php

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

use Pimcore\Model\DataObject\ClassDefinition\LinkGeneratorInterface;
use Pimcore\Model\DataObject\ClassDefinition\PreviewGeneratorInterface;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Site;
use Pimcore\Model\Translation;
use Pimcore\Tool;
use Pimcore\Translation\Translator;
use Symfony\Contracts\Service\Attribute\Required;

class PreviewGenerator implements PreviewGeneratorInterface
{
    protected Translator $translator;

    public function generatePreviewUrl(Concrete $object, array $params): string
    {
        $linkGenerator = $object->getClass()->getLinkGenerator();

        if ($linkGenerator instanceof LinkGeneratorInterface) {
            $filteredParameters = $this->filterParameters($object, $params);

            $locale = $filteredParameters[PreviewGeneratorInterface::PARAMETER_LOCALE] ?? Tool::getDefaultLanguage();
            $site = array_key_exists(PreviewGeneratorInterface::PARAMETER_SITE, $filteredParameters) ? Site::getById($filteredParameters[PreviewGeneratorInterface::PARAMETER_SITE]) : (new Site\Listing())->current();

            return $linkGenerator->generate($object, [
                PreviewGeneratorInterface::PARAMETER_LOCALE => $locale,
                PreviewGeneratorInterface::PARAMETER_SITE => $site,
            ]);
        }

        throw new \LogicException("No link generator given for element of type {$object->getClassName()}");
    }

    /**
     * @return array only parameters that are part of the preview generator config and are not empty
     */
    protected function filterParameters(Concrete $object, array $parameters): array
    {
        $previewConfig = $this->getPreviewConfig($object);

        $filteredParameters = [];
        foreach ($previewConfig as $config) {
            $name = $config['name'];
            $selectedValue = $parameters[$name] ?? $config['defaultValue'];

            if (!empty($selectedValue)) {
                $filteredParameters[$name] = $selectedValue;
            }
        }

        return $filteredParameters;
    }

    public function getPreviewConfig(Concrete $object): array
    {
        return array_filter([
            $this->getLocalePreviewConfig($object),
            $this->getSitePreviewConfig($object),
        ]);
    }

    protected function getLocalePreviewConfig(Concrete $object): array
    {
        $user = Tool\Authentication::authenticateSession();
        $userLocale = $user->getLanguage();

        $locales = [];
        foreach (Tool::getValidLanguages() as $locale) {
            $label = \Locale::getDisplayLanguage($locale, $userLocale);
            $locales[$label] = $locale;
        }

        return [
            'name' => PreviewGeneratorInterface::PARAMETER_LOCALE,
            'label' => $this->translator->trans('preview_generator_locale', [], Translation::DOMAIN_ADMIN),
            'values' => $locales,
            'defaultValue' => in_array($userLocale, Tool::getValidLanguages()) ? $userLocale : Tool::getDefaultLanguage(),
        ];
    }

    protected function getSitePreviewConfig(Concrete $object): array
    {
        $sites = new Site\Listing();
        $sites->setOrderKey('mainDomain')->setOrder('ASC');

        if ($sites->count() == 0) {
            return [];
        }

        $sitesOptions = [
            $this->translator->trans('main_site', [], Translation::DOMAIN_ADMIN) => '0',
        ];

        $preSelectedSite = null;
        foreach ($sites as $site) {
            $label = $site->getRootDocument()?->getKey();
            $sitesOptions[$label] = $site->getId();

            $domains = $site->getDomains();
            array_unshift($domains, $site->getMainDomain());

            if (is_null($preSelectedSite) && in_array(Tool::getHostname(), $domains)) {
                $preSelectedSite = $sitesOptions[$label];
            }
        }

        return [
            'name' => PreviewGeneratorInterface::PARAMETER_SITE,
            'label' => $this->translator->trans('preview_generator_site', [], Translation::DOMAIN_ADMIN),
            'values' => $sitesOptions,
            'defaultValue' => $preSelectedSite ?? reset($sitesOptions),
        ];
    }

    /**
     * @internal
     */
    #[Required]
    public function setTranslator(Translator $translator): void
    {
        $this->translator = $translator;
    }
}
