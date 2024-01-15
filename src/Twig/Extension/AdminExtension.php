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

namespace Pimcore\Bundle\AdminBundle\Twig\Extension;

use Exception;
use Pimcore\Bundle\AdminBundle\System\AdminConfig;
use Pimcore\Bundle\AdminBundle\Tool;
use Pimcore\Config;
use Pimcore\Http\Request\Resolver\EditmodeResolver;
use Pimcore\Security\User\UserLoader;
use Pimcore\Tool\Admin;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @internal
 */
class AdminExtension extends AbstractExtension
{
    public function __construct(
        private UrlGeneratorInterface $generator,
        private EditmodeResolver $editmodeResolver,
        private UserLoader $userLoader
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pimcore_language_flag', [Tool::class, 'getLanguageFlagFile']),
            new TwigFunction('pimcore_minimize_scripts', [$this, 'minimize']),
            new TwigFunction('pimcore_editmode_admin_language', [$this, 'getAdminLanguage']),
            new TwigFunction('pimcore_login_background_image', [$this, 'getLoginBackgroundImage']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('pimcore_inline_icon', [$this, 'inlineIcon']),
            new TwigFilter('pimcore_twemoji_variant_icon', [$this, 'twemojiVariantIcon']),
        ];
    }

    public function getAdminLanguage(): ?string
    {
        $pimcoreUser = null;
        if ($this->editmodeResolver->isEditmode()) {
            $pimcoreUser = $this->userLoader->getUser();
        }

        return $pimcoreUser?->getLanguage();
    }

    public function minimize(array $paths): string
    {
        $returnHtml = '';
        $scriptContents = '';
        foreach ($paths as $path) {
            $found = false;
            foreach ([
                PIMCORE_WEB_ROOT . '/bundles/pimcoreadmin/js/' . $path,
                PIMCORE_WEB_ROOT . $path,
            ] as $fullPath) {
                if (is_file($fullPath)) {
                    $scriptContents .= file_get_contents($fullPath) . "\n\n\n";
                    $found = true;
                }
            }

            if (!$found) {
                $returnHtml .= $this->getScriptTag($path);
            }
        }

        $parameters = Admin::getMinimizedScriptPath($scriptContents);
        $url = $this->generator->generate('pimcore_admin_misc_scriptproxy', $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);

        $returnHtml .= $this->getScriptTag($url);

        return $returnHtml;
    }

    private function getScriptTag(string $url): string
    {
        return '<script src="' . $url . '"></script>' . "\n";
    }

    public function getLoginBackgroundImage(string $overwrite = ''): string
    {
        $possibleDefaultImages = [
            '/bundles/pimcoreadmin/img/login/pimconaut-ecommerce.svg',
            '/bundles/pimcoreadmin/img/login/pimconaut-world.svg',
            '/bundles/pimcoreadmin/img/login/pimconaut-engineer.svg',
            '/bundles/pimcoreadmin/img/login/pimconaut-moon.svg',
            '/bundles/pimcoreadmin/img/login/pimconaut-rocket.svg',
        ];
        $backgroundImageUrl = $possibleDefaultImages[array_rand($possibleDefaultImages)];

        if (empty($overwrite) === false) {
            $backgroundImageUrl = $overwrite;
        }

        $customImage = AdminConfig::get()['branding']['login_screen_custom_image'];

        if (empty($customImage) === true) {
            return $backgroundImageUrl;
        }

        if (
            preg_match('@^https?://@', $customImage) === 1
            || is_file(PIMCORE_WEB_ROOT . '/var/assets' . $customImage) === true
            || is_file(PIMCORE_WEB_ROOT . $customImage) === true
        ) {
            return $customImage;
        }

        $assetSource = Config::getSystemConfiguration('assets')['frontend_prefixes']['source'];

        if (empty($assetSource) === false) {
            $url = sprintf('%s/%s', $assetSource, $customImage);

            try {
                // Check if the image exists
                getimagesize($url);

                return $url;
            } catch (Exception) {
                return $backgroundImageUrl;
            }
        }

        return $backgroundImageUrl;
    }

    public function inlineIcon(string $icon): string
    {
        $content = file_get_contents($icon);

        return sprintf(
            '<img src="data:%s;base64,%s" title="%s" data-imgpath="%s" />',
            mime_content_type($icon),
            base64_encode($content),
            basename($icon),
            str_replace(PIMCORE_WEB_ROOT, '', $icon)
        );
    }

    public function twemojiVariantIcon(string $icon): string
    {
        return sprintf(
            '<img title="%s" data-imgpath="%s" />',
            basename($icon),
            str_replace(PIMCORE_WEB_ROOT, '', $icon)
        );
    }
}
