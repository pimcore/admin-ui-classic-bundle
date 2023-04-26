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

namespace Pimcore\Bundle\AdminBundle\Security;

use Pimcore\Config;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @internal
 */
class ContentSecurityPolicyHandler implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private ?string $nonce = null;

    public const DEFAULT_OPT = 'default-src';

    public const IMG_OPT = 'img-src';

    public const SCRIPT_OPT = 'script-src';

    public const STYLE_OPT = 'style-src';

    public const CONNECT_OPT = 'connect-src';

    public const FONT_OPT = 'font-src';

    public const MEDIA_OPT = 'media-src';

    public const FRAME_OPT = 'frame-src';

    private array $allowedUrls = [
        self::CONNECT_OPT => [
            'https://liveupdate.pimcore.org/', // AdminBundle statistics & update-check service
            'https://nominatim.openstreetmap.org/', // CoreBundle geocoding_url_template
        ],
        self::SCRIPT_OPT => [
            'https://buttons.github.io/buttons.js', // GitHub star button on login page
        ],
    ];

    public function __construct(protected Config $config, protected array $cspHeaderOptions = [])
    {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);

        $this->cspHeaderOptions = $resolver->resolve($cspHeaderOptions);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            self::DEFAULT_OPT => "'self'",
            self::IMG_OPT => '* data: blob:',
            self::MEDIA_OPT => "'self' data:",
            self::SCRIPT_OPT => "'self' 'nonce-" . $this->getNonce() . "' 'unsafe-inline' 'unsafe-eval'",
            self::STYLE_OPT => "'self' 'unsafe-inline'",
            self::FRAME_OPT => "'self' data:",
            self::CONNECT_OPT => "'self' blob:",
            self::FONT_OPT => "'self'",
        ]);
    }

    public function getCspHeader(): string
    {
        $cspHeaderOptions = array_map(function ($k, $v) {
            return "$k $v " . $this->getAllowedUrls($k);
        }, array_keys($this->cspHeaderOptions), array_values($this->cspHeaderOptions));

        return implode(';', $cspHeaderOptions);
    }

    private function getAllowedUrls(string $key, bool $flatten = true): array|string
    {
        if (!$flatten) {
            return $this->allowedUrls[$key] ?? [];
        }

        return isset($this->allowedUrls[$key]) && is_array($this->allowedUrls[$key]) ? implode(' ', $this->allowedUrls[$key]) : '';
    }

    /**
     * @return $this
     */
    public function addAllowedUrls(string $key, array $value): static
    {
        if (!isset($this->allowedUrls[$key])) {
            $this->allowedUrls[$key] = [];
        }

        foreach ($value as $val) {
            $this->allowedUrls[$key][] = $val;
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function setCspHeader(string $key, string $value): static
    {
        $this->cspHeaderOptions[$key] = $value;

        return $this;
    }

    public function getNonceHtmlAttribute(): string
    {
        return $this->config['admin_csp_header']['enabled'] ? ' nonce="' . $this->getNonce() . '"' : '';
    }

    /**
     * Generates a random nonce parameter.
     */
    private function getNonce(): string
    {
        if (!$this->nonce) {
            $this->nonce = generateRandomSymfonySecret();
        }

        return $this->nonce;
    }
}
