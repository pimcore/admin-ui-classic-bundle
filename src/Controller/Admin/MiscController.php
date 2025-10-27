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

namespace Pimcore\Bundle\AdminBundle\Controller\Admin;

use Pimcore\Bundle\AdminBundle\Controller\AdminAbstractController;
use Pimcore\Bundle\AdminBundle\System\AdminConfig;
use Pimcore\Bundle\AdminBundle\Tool as AdminTool;
use Pimcore\Config;
use Pimcore\Controller\Config\ControllerDataProvider;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Tool;
use Pimcore\Tool\Storage;
use Pimcore\Translation\Translator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("/misc")
 *
 * @internal
 */
class MiscController extends AdminAbstractController
{
    /**
     * @Route("/get-available-controller-references", name="pimcore_admin_misc_getavailablecontroller_references", methods={"GET"})
     */
    public function getAvailableControllerReferencesAction(Request $request, ControllerDataProvider $provider): JsonResponse
    {
        $controllerReferences = $provider->getControllerReferences();

        $result = array_map(function ($controller) {
            return [
                'name' => $controller,
            ];
        }, $controllerReferences);

        return $this->adminJson([
            'success' => true,
            'data' => $result,
            'total' => count($result),
        ]);
    }

    /**
     * @Route("/get-available-templates", name="pimcore_admin_misc_getavailabletemplates", methods={"GET"})
     */
    public function getAvailableTemplatesAction(ControllerDataProvider $provider): JsonResponse
    {
        $templates = $provider->getTemplates();

        sort($templates, SORT_NATURAL | SORT_FLAG_CASE);

        $result = array_map(static function ($template) {
            return [
                'path' => $template,
            ];
        }, $templates);

        return $this->adminJson([
            'data' => $result,
        ]);
    }

    /**
     * @Route("/json-translations-system", name="pimcore_admin_misc_jsontranslationssystem", methods={"GET"})
     */
    public function jsonTranslationsSystemAction(Request $request, TranslatorInterface $translator): Response
    {
        $language = $request->get('language');

        /** @var Translator $translator */
        $translator->lazyInitialize('admin', $language);

        $translations = [];

        $fallbackLanguages = [];
        if (null !== \Locale::getRegion($language)) {
            // if language is region specific, add the primary language as fallback
            $fallbackLanguages[] = \Locale::getPrimaryLanguage($language);
        }
        if ($language != 'en') {
            // add en as a fallback
            $fallbackLanguages[] = 'en';
        }

        foreach (['admin', 'admin_ext'] as $domain) {
            $translations = array_replace($translations, $translator->getCatalogue($language)->all($domain));

            foreach ($fallbackLanguages as $fallbackLanguage) {
                $translator->lazyInitialize($domain, $fallbackLanguage);
                foreach ($translator->getCatalogue($fallbackLanguage)->all($domain) as $key => $value) {
                    if (empty($translations[$key])) {
                        $translations[$key] = $value;
                    }
                }
            }
        }

        $response = new Response('pimcore.system_i18n = ' . $this->encodeJson($translations) . ';');
        $response->headers->set('Content-Type', 'text/javascript');

        return $response;
    }

    /**
     * @Route("/script-proxy", name="pimcore_admin_misc_scriptproxy", methods={"GET"})
     *
     * @internal
     */
    public function scriptProxyAction(Request $request): Response
    {
        $storageFile = $request->get('storageFile');
        if (!$storageFile) {
            throw new \InvalidArgumentException('The parameter storageFile is required');
        }

        $fileExtension = pathinfo($storageFile, PATHINFO_EXTENSION);
        $storage = Storage::get('admin');
        $scriptsContent = $storage->read($storageFile);

        if (!empty($scriptsContent)) {
            $contentType = 'text/javascript';
            if ($fileExtension == 'css') {
                $contentType = 'text/css';
            }

            $lifetime = 86400;

            $response = new Response($scriptsContent);
            $response->headers->set('Cache-Control', 'max-age=' . $lifetime);
            $response->headers->set('Pragma', '');
            $response->headers->set('Content-Type', $contentType);
            $response->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + $lifetime) . ' GMT');

            return $response;
        } else {
            throw $this->createNotFoundException('Scripts not found');
        }
    }

    /**
     * @Route("/admin-css", name="pimcore_admin_misc_admincss", methods={"GET"})
     */
    public function adminCssAction(Request $request, Config $config): Response
    {
        // customviews config
        $cvData = \Pimcore\Bundle\AdminBundle\CustomView\Config::get();

        // languages
        $languages = \Pimcore\Tool::getValidLanguages();
        $adminLanguages = \Pimcore\Tool\Admin::getLanguages();
        $languages = array_unique(array_merge($languages, $adminLanguages));

        $response = $this->render('@PimcoreAdmin/admin/misc/admin_css.html.twig', [
            'customviews' => $cvData,
            'adminSettings' => AdminConfig::get(),
            'languages' => $languages,
        ]);
        $response->headers->set('Content-Type', 'text/css; charset=UTF-8');

        return $response;
    }

    /**
     * @Route("/ping", name="pimcore_admin_misc_ping", methods={"GET"})
     */
    public function pingAction(Request $request): JsonResponse
    {
        $response = [
            'success' => true,
        ];

        return $this->adminJson($response);
    }

    /**
     * @Route("/available-languages", name="pimcore_admin_misc_availablelanguages", methods={"GET"})
     */
    public function availableLanguagesAction(Request $request): Response
    {
        $locales = Tool::getSupportedLocales();
        $response = new Response('pimcore.available_languages = ' . $this->encodeJson($locales) . ';');
        $response->headers->set('Content-Type', 'text/javascript');

        return $response;
    }

    /**
     * @Route("/get-valid-filename", name="pimcore_admin_misc_getvalidfilename", methods={"GET"})
     */
    public function getValidFilenameAction(Request $request): JsonResponse
    {
        return $this->adminJson([
            'filename' => \Pimcore\Model\Element\Service::getValidKey($request->get('value'), $request->get('type')),
        ]);
    }

    /**
     * @Route("/maintenance", name="pimcore_admin_misc_maintenance", methods={"POST"})
     */
    public function maintenanceAction(Request $request, Tool\MaintenanceModeHelperInterface $maintenanceModeHelper): JsonResponse
    {
        $this->checkPermission('maintenance_mode');

        if ($request->get('activate')) {
            $maintenanceModeHelper->activate($request->getSession()->getId());
        }

        if ($request->get('deactivate')) {
            if (Tool\Admin::isInMaintenanceMode()) {
                Tool\Admin::deactivateMaintenanceMode();
            }
            $maintenanceModeHelper->deactivate();
        }

        return $this->adminJson([
            'success' => true,
        ]);
    }

    /**
     * @Route("/country-list", name="pimcore_admin_misc_countrylist", methods={"GET"})
     */
    public function countryListAction(LocaleServiceInterface $localeService): JsonResponse
    {
        $countries = $localeService->getDisplayRegions();
        asort($countries);
        $options = [];

        foreach ($countries as $short => $translation) {
            if (strlen($short) == 2) {
                $options[] = [
                    'name' => $translation,
                    'code' => $short,
                ];
            }
        }

        return $this->adminJson(['data' => $options]);
    }

    /**
     * @Route("/language-list", name="pimcore_admin_misc_languagelist", methods={"GET"})
     */
    public function languageListAction(Request $request): JsonResponse
    {
        $locales = Tool::getSupportedLocales();
        $options = [];

        foreach ($locales as $short => $translation) {
            $options[] = [
                'name' => $translation,
                'code' => $short,
            ];
        }

        return $this->adminJson(['data' => $options]);
    }

    /**
     * @Route("/get-language-flag", name="pimcore_admin_misc_getlanguageflag", methods={"GET"})
     */
    public function getLanguageFlagAction(Request $request): BinaryFileResponse
    {
        $iconPath = AdminTool::getLanguageFlagFile($request->get('language'));
        $response = new BinaryFileResponse($iconPath);
        $response->headers->set('Content-Type', 'image/svg+xml');

        return $response;
    }

    /**
     * @Route("/icon-list", name="pimcore_admin_misc_iconlist", methods={"GET"})
     */
    public function iconListAction(Request $request, ?Profiler $profiler): Response
    {
        if ($profiler) {
            $profiler->disable();
        }

        $type = $request->get('type');
        $publicDir = PIMCORE_WEB_ROOT . '/bundles/pimcoreadmin';
        $iconDir = $publicDir . '/img';
        $extraInfo = null;

        $icons = match ($type) {
            'color' => rscandir($iconDir . '/flat-color-icons/'),
            'white' => rscandir($iconDir . '/flat-white-icons/'),
            'twemoji' => rscandir($iconDir . '/twemoji/'),
            'flags' => $this->getFlags(),
            default => []
        };

        $source = match ($type) {
            'color', 'white' =>
                'based on the ' .
                '<a href="https://github.com/google/material-design-icons/blob/master/LICENSE" target="_blank">Material Design Icons</a>',
            'twemoji' =>
                'based on the ' .
                '<a href="https://github.com/twitter/twemoji/blob/master/LICENSE" target="_blank">Twemoji icons</a>',
            default => ''
        };

        if ($type === 'twemoji') {
            $extraInfo = 'ℹ Click on icon with green border to display all its related variants. Click on the letter to display flags with the clicked initial';
        }

        $iconsCss = file_get_contents($publicDir . '/css/icons.css');

        if ($type === null) {
            return $this->render('@PimcoreAdmin/admin/misc/icon_library_reload.html.twig');
        }

        return $this->render('@PimcoreAdmin/admin/misc/icon_list.html.twig', [
            'icons' => $icons,
            'iconsCss' => $iconsCss,
            'type' => $type,
            'extraInfo' => $extraInfo,
            'source' => $source,
        ]);
    }

    private function getFlags(): array
    {
        $locales = Tool::getSupportedLocales();
        $languageOptions = [];
        foreach ($locales as $short => $translation) {
            if (!empty($short)) {
                $flag = AdminTool::getLanguageFlagFile($short, true, false);
                if ($flag) {
                    $languageOptions[] = $flag;
                }
            }
        }

        $languageOptions = array_unique($languageOptions);
        sort($languageOptions);

        return $languageOptions;
    }

    /**
     * @Route("/test", name="pimcore_admin_misc_test")
     */
    public function testAction(Request $request): Response
    {
        return new Response('done');
    }
}
