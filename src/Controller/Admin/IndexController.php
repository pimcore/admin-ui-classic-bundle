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

use Doctrine\DBAL\Connection;
use Exception;
use GuzzleHttp\ClientInterface;
use Pimcore\Bundle\AdminBundle\Controller\AdminAbstractController;
use Pimcore\Bundle\AdminBundle\Event\AdminEvents;
use Pimcore\Bundle\AdminBundle\Event\IndexActionSettingsEvent;
use Pimcore\Bundle\AdminBundle\Helper\Dashboard;
use Pimcore\Bundle\AdminBundle\Security\CsrfProtectionHandler;
use Pimcore\Bundle\AdminBundle\System\AdminConfig;
use Pimcore\Bundle\CoreBundle\OptionsProvider\SelectOptionsOptionsProvider;
use Pimcore\Config;
use Pimcore\Controller\KernelResponseEventInterface;
use Pimcore\Extension\Bundle\PimcoreBundleManager;
use Pimcore\Image\HtmlToImage;
use Pimcore\Maintenance\Executor;
use Pimcore\Maintenance\ExecutorInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\ClassDefinition\CustomLayout;
use Pimcore\Model\Document;
use Pimcore\Model\Document\DocType;
use Pimcore\Model\Element\Service;
use Pimcore\Model\Property\Predefined;
use Pimcore\Model\User;
use Pimcore\SystemSettingsConfig;
use Pimcore\Tool;
use Pimcore\Tool\Admin;
use Pimcore\Version;
use Pimcore\Video;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @internal
 */
class IndexController extends AdminAbstractController implements KernelResponseEventInterface
{
    public function __construct(
        protected EventDispatcherInterface $eventDispatcher,
        protected TranslatorInterface $translator,
        protected ClientInterface $httpClient
    ) {
    }

    /**
     * @Route("/", name="pimcore_admin_index", methods={"GET"})
     *
     * @throws \Exception
     */
    public function indexAction(
        Request $request,
        KernelInterface $kernel,
        Executor $maintenanceExecutor,
        CsrfProtectionHandler $csrfProtection,
        Config $config,
        PimcoreBundleManager $bundleManager,
        Tool\MaintenanceModeHelperInterface $maintenanceModeHelper
    ): Response {
        $user = $this->getAdminUser();
        $perspectiveConfig = new \Pimcore\Bundle\AdminBundle\Perspective\Config();
        $templateParams = [
            'config' => $config,
            'systemSettings' => SystemSettingsConfig::get(),
            'adminSettings' => AdminConfig::get(),
            'perspectiveConfig' => $perspectiveConfig,
        ];

        $this
            ->setAdminLanguage($request, $user)
            ->addRuntimePerspective($templateParams, $user)
            ->addPluginAssets($bundleManager, $templateParams);

        $this->buildPimcoreSettings(
            $request,
            $templateParams,
            $user,
            $kernel,
            $maintenanceExecutor,
            $csrfProtection,
            $maintenanceModeHelper
        );

        if ($user->getTwoFactorAuthentication('required') && !$user->getTwoFactorAuthentication('enabled')) {
            return $this->redirectToRoute('pimcore_admin_2fa_setup');
        }

        // allow to alter settings via an event
        $settingsEvent = new IndexActionSettingsEvent($templateParams['settings'] ?? []);
        $this->eventDispatcher->dispatch($settingsEvent, AdminEvents::INDEX_ACTION_SETTINGS);
        $templateParams['settings'] = $settingsEvent->getSettings();

        return $this->render($settingsEvent->getTemplate() ?: '@PimcoreAdmin/admin/index/index.html.twig', $templateParams);
    }

    /**
     * @Route("/index/statistics", name="pimcore_admin_index_statistics", methods={"GET"})
     *
     * @throws \Exception
     */
    public function statisticsAction(Request $request, Connection $db, KernelInterface $kernel): JsonResponse
    {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createAccessDeniedHttpException();
        }

        // DB
        try {
            $tables = $db->fetchAllAssociative('SELECT TABLE_NAME as name,TABLE_ROWS as `rows` from information_schema.TABLES
                WHERE TABLE_ROWS IS NOT NULL AND TABLE_SCHEMA = ?', [$db->getDatabase()]);
        } catch (\Exception $e) {
            $tables = [];
        }

        try {
            $mysqlVersion = $db->fetchOne('SELECT VERSION()');
        } catch (\Exception $e) {
            $mysqlVersion = null;
        }

        try {
            $data = [
                'instanceId' => $this->getInstanceId(),
                'pimcore_major_version' => Version::getMajorVersion(),
                'pimcore_version' => Version::getVersion(),
                'pimcore_hash' => Version::getRevision(),
                'pimcore_platform_version' => Version::getPlatformVersion(),
                'php_version' => PHP_VERSION,
                'mysql_version' => $mysqlVersion,
                'bundles' => array_keys($kernel->getBundles()),
                'tables' => $tables,
            ];
        } catch (\Exception $e) {
            $data = [];
        }

        if ($this->getAdminUser()->isAdmin()) {
            return $this->adminJson($data);
        }

        $response = $this->httpClient->request(
            'POST',
            'https://liveupdate.pimcore.org/statistics',
            [
                'body' => json_encode($data),
            ]
        );

        return $this->adminJson([
            'success' => ($response->getStatusCode() >= 200 && $response->getStatusCode() < 400),
        ]);
    }

    protected function addRuntimePerspective(array &$templateParams, User $user): static
    {
        $runtimePerspective = \Pimcore\Bundle\AdminBundle\Perspective\Config::getRuntimePerspective($user);
        $templateParams['runtimePerspective'] = $runtimePerspective;

        return $this;
    }

    protected function addPluginAssets(PimcoreBundleManager $bundleManager, array &$templateParams): static
    {
        $templateParams['pluginJsPaths'] = $bundleManager->getJsPaths();
        $templateParams['pluginCssPaths'] = $bundleManager->getCssPaths();

        return $this;
    }

    protected function setAdminLanguage(Request $request, User $user): static
    {
        // set user language
        $request->setLocale($user->getLanguage());
        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($user->getLanguage());
        }

        return $this;
    }

    protected function buildPimcoreSettings(
        Request $request,
        array &$templateParams,
        User $user, KernelInterface $kernel,
        ExecutorInterface $maintenanceExecutor,
        CsrfProtectionHandler $csrfProtection,
        Tool\MaintenanceModeHelperInterface $maintenanceModeHelper
    ): static {
        $config = $templateParams['config'];
        $systemSettings = $templateParams['systemSettings'];
        $adminSettings = $templateParams['adminSettings'];
        $requiredLanguages = $systemSettings['general']['valid_languages'];
        $dashboardHelper = new Dashboard($user);
        $customAdminEntrypoint = $this->getParameter('pimcore_admin.custom_admin_route_name');

        try {
            $adminEntrypointUrl = $this->generateUrl($customAdminEntrypoint, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (Exception) {
            // if the custom admin entrypoint is not defined, return null in the settings
            $adminEntrypointUrl = null;
        }

        if (array_key_exists('required_languages', $systemSettings['general'])) {
            $requiredLanguages = $systemSettings['general']['required_languages'];
        }

        $settings = [
            'instanceId'          => $this->getInstanceId(),
            'version'             => Version::getVersion(),
            'build'               => Version::getRevision(),
            'platform_version'    => Version::getPlatformVersion(),
            'debug'               => \Pimcore::inDebugMode(),
            'devmode'             => \Pimcore::inDevMode(),
            'disableMinifyJs'     => \Pimcore::disableMinifyJs(),
            'environment'         => $kernel->getEnvironment(),
            'cached_environments' => Tool::getCachedSymfonyEnvironments(),
            'sessionId'           => htmlentities($request->getSession()->getId(), ENT_QUOTES, 'UTF-8'),

            // languages
            'language'         => $request->getLocale(),
            'websiteLanguages' => Admin::reorderWebsiteLanguages(
                $this->getAdminUser(),
                $systemSettings['general']['valid_languages'],
                true
            ),
            'requiredLanguages' => $requiredLanguages,

            // flags
            'showCloseConfirmation'          => true,
            'debug_admin_translations'       => (bool)$systemSettings['general']['debug_admin_translations'],
            'document_generatepreviews'      => (bool)$config['documents']['generate_preview'],
            'asset_disable_tree_preview'     => (bool)$adminSettings['assets']['disable_tree_preview'],
            'asset_hide_edit'                => (bool)$adminSettings['assets']['hide_edit_image'],
            'asset_tree_paging_limit'        => $config['assets']['tree_paging_limit'],
            'asset_default_upload_path'      => $config['assets']['default_upload_path'],
            'chromium'                       => HtmlToImage::isSupported(),
            'videoconverter'                 => Video::isAvailable(),
            'main_domain'                    => $systemSettings['general']['domain'],
            'custom_admin_entrypoint_url'    => $adminEntrypointUrl,
            'timezone'                       => $config['general']['timezone'] ?: date_default_timezone_get(),
            'tile_layer_url_template'        => $config['maps']['tile_layer_url_template'],
            'geocoding_url_template'         => $config['maps']['geocoding_url_template'],
            'reverse_geocoding_url_template' => $config['maps']['reverse_geocoding_url_template'],
            'document_tree_paging_limit'     => $config['documents']['tree_paging_limit'],
            'object_tree_paging_limit'       => $config['objects']['tree_paging_limit'],
            'hostname'                       => htmlentities(\Pimcore\Tool::getHostname(), ENT_QUOTES, 'UTF-8'),
            'dependency'                     => $config['dependency']['enabled'],

            'document_auto_save_interval' => $config['documents']['auto_save_interval'],
            'object_auto_save_interval'   => $config['objects']['auto_save_interval'],

            // perspective and portlets
            'perspective'           => $templateParams['runtimePerspective'],
            'availablePerspectives' => \Pimcore\Bundle\AdminBundle\Perspective\Config::getAvailablePerspectives($user),
            'disabledPortlets'      => $dashboardHelper->getDisabledPortlets(),

            // this stuff is used to decide whether the "add" button should be grayed out or not
            'image-thumbnails-writeable'          => (new Asset\Image\Thumbnail\Config())->isWriteable(),
            'video-thumbnails-writeable'          => (new Asset\Video\Thumbnail\Config())->isWriteable(),
            'document-types-writeable'            => (new DocType())->isWriteable(),
            'predefined-properties-writeable'     => (new Predefined())->isWriteable(),
            'predefined-asset-metadata-writeable' => (new \Pimcore\Model\Metadata\Predefined())->isWriteable(),
            'perspectives-writeable'              => \Pimcore\Bundle\AdminBundle\Perspective\Config::isWriteable(),
            'custom-views-writeable'              => \Pimcore\Bundle\AdminBundle\CustomView\Config::isWriteable(),
            'class-definition-writeable'          => !isset($_SERVER['PIMCORE_CLASS_DEFINITION_WRITABLE']) ||
                (bool) $_SERVER['PIMCORE_CLASS_DEFINITION_WRITABLE'],
            'object-custom-layout-writeable' => (new CustomLayout())->isWriteable(),
            'select-options-writeable' => (new \Pimcore\Model\DataObject\SelectOptions\Config())->isWriteable(),

            // search types
            'asset_search_types' => Asset::getTypes(),

            // document types
            'document_types_configuration' => Document::getTypesConfiguration(),
            'document_search_types' => Document::getTypes(),
            'document_valid_types' => array_values(array_filter(Document::getTypes(), function ($type) {
                return $type !== 'folder';
            })),
            // email search compatible document types
            'document_email_search_types' => $config['documents']['email_search'],
            'select_options_provider_class' => SelectOptionsOptionsProvider::class,
        ];

        $this
            ->addSystemVarSettings($settings)
            ->addMaintenanceSettings($settings, $maintenanceExecutor, $maintenanceModeHelper)
            ->addMailSettings($settings, $config, $systemSettings)
            ->addCustomViewSettings($settings)
            ->addNotificationSettings($settings, $config);

        $settings['csrfToken'] = $csrfProtection->getCsrfToken($request->getSession());

        $templateParams['settings'] = $settings;

        return $this;
    }

    private function getInstanceId(): string
    {
        $instanceId = 'not-set';

        try {
            $instanceId = $this->getParameter('secret');
            $instanceId = sha1(substr($instanceId, 3, -3));
        } catch (\Exception $e) {
            // nothing to do
        }

        return $instanceId;
    }

    protected function addSystemVarSettings(array &$settings): static
    {
        // upload limit
        $max_upload = filesize2bytes(ini_get('upload_max_filesize') . 'B');
        $max_post = filesize2bytes(ini_get('post_max_size') . 'B');
        $upload_mb = min($max_upload, $max_post) ?: $max_upload;

        $settings['upload_max_filesize'] = (int) $upload_mb;

        // session lifetime (gc)
        $session_gc_maxlifetime = ini_get('session.gc_maxlifetime');
        if (empty($session_gc_maxlifetime)) {
            $session_gc_maxlifetime = 120;
        }

        $settings['session_gc_maxlifetime'] = (int)$session_gc_maxlifetime;

        return $this;
    }

    protected function addMaintenanceSettings(
        array &$settings,
        ExecutorInterface $maintenanceExecutor,
        Tool\MaintenanceModeHelperInterface $maintenanceModeHelper
    ): static {
        // check maintenance
        $maintenance_active = false;
        if ($lastExecution = $maintenanceExecutor->getLastExecution()) {
            // maintenance script should run at least every hour + a little tolerance
            if ((time() - $lastExecution) < 3660) {
                $maintenance_active = true;
            }
        }

        $settings['maintenance_active'] = $maintenance_active;
        $settings['maintenance_mode'] = $maintenanceModeHelper->isActive() || Admin::isInMaintenanceMode();

        return $this;
    }

    protected function addMailSettings(array &$settings, Config $config, array $systemSettings): static
    {
        //mail settings
        $mailIncomplete = false;
        if (isset($config['email']) && $systemSettings['email']) {
            if (\Pimcore::inDebugMode() && empty($systemSettings['email']['debug']['email_addresses'])) {
                $mailIncomplete = true;
            }
            if (empty($config['email']['sender']['email'])) {
                $mailIncomplete = true;
            }
        }

        $settings['mail'] = !$mailIncomplete;
        $settings['mailDefaultAddress'] = $config['email']['sender']['email'] ?? null;

        return $this;
    }

    protected function addCustomViewSettings(array &$settings): static
    {
        $cvData = [];

        // still needed when publishing objects
        $cvConfig = \Pimcore\Bundle\AdminBundle\CustomView\Config::get();

        if ($cvConfig) {
            foreach ($cvConfig as $node) {
                $tmpData = $node;
                // backwards compatibility
                $treeType = $tmpData['treetype'] ? $tmpData['treetype'] : 'object';
                $rootNode = Service::getElementByPath($treeType, $tmpData['rootfolder']);

                if ($rootNode) {
                    $tmpData['rootId'] = $rootNode->getId();
                    $tmpData['allowedClasses'] = $tmpData['classes'] ?? null;
                    $tmpData['showroot'] = (bool)$tmpData['showroot'];

                    // Check if a user has privileges to that node
                    if ($rootNode->isAllowed('list')) {
                        $cvData[] = $tmpData;
                    }
                }
            }
        }

        $settings['customviews'] = $cvData;

        return $this;
    }

    /**
     * @return $this
     */
    protected function addNotificationSettings(array &$settings, Config $config): static
    {
        $enabled = (bool)$config['notifications']['enabled'];

        $settings['notifications_enabled'] = $enabled;
        $settings['checknewnotification_enabled'] = $enabled && (bool) $config['notifications']['check_new_notification']['enabled'];

        // convert the config parameter interval (seconds) in milliseconds
        $settings['checknewnotification_interval'] = $config['notifications']['check_new_notification']['interval'] * 1000;

        return $this;
    }

    public function onKernelResponseEvent(ResponseEvent $event): void
    {
        $event->getResponse()->headers->set('X-Frame-Options', 'deny', true);
    }
}
