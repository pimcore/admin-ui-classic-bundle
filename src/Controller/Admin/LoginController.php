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

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Pimcore\Bundle\AdminBundle\Controller\AdminAbstractController;
use Pimcore\Bundle\AdminBundle\Event\AdminEvents;
use Pimcore\Bundle\AdminBundle\Event\Login\LoginRedirectEvent;
use Pimcore\Bundle\AdminBundle\Event\Login\LostPasswordEvent;
use Pimcore\Bundle\AdminBundle\Security\CsrfProtectionHandler;
use Pimcore\Bundle\AdminBundle\System\AdminConfig;
use Pimcore\Config;
use Pimcore\Controller\KernelControllerEventInterface;
use Pimcore\Controller\KernelResponseEventInterface;
use Pimcore\Extension\Bundle\PimcoreBundleManager;
use Pimcore\Http\ResponseHelper;
use Pimcore\Logger;
use Pimcore\Model\User;
use Pimcore\Security\SecurityHelper;
use Pimcore\Tool;
use Pimcore\Tool\Authentication;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Google\GoogleAuthenticatorInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @internal
 */
class LoginController extends AdminAbstractController implements KernelControllerEventInterface, KernelResponseEventInterface
{
    public function __construct(
        protected ResponseHelper $responseHelper,
        protected TranslatorInterface $translator,
        protected PimcoreBundleManager $bundleManager,
        protected EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function onKernelControllerEvent(ControllerEvent $event): void
    {
        // use browser language for login page if possible
        $locale = 'en';

        $availableLocales = Tool\Admin::getLanguages();
        foreach ($event->getRequest()->getLanguages() as $userLocale) {
            if (in_array($userLocale, $availableLocales)) {
                $locale = $userLocale;

                break;
            }
        }

        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($locale);
        }
    }

    public function onKernelResponseEvent(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $response->headers->set('X-Frame-Options', 'deny', true);
        $this->responseHelper->disableCache($response, true);
    }

    /**
     * @Route("/login", name="pimcore_admin_login")
     * @Route("/login/", name="pimcore_admin_login_fallback")
     */
    public function loginAction(
        Request $request,
        AuthenticationUtils $authenticationUtils,
        CsrfProtectionHandler $csrfProtection,
        Config $config
    ): RedirectResponse|Response {
        $queryParams = $request->query->all();
        if ($request->get('_route') === 'pimcore_admin_login_fallback') {
            return $this->redirectToRoute('pimcore_admin_login', $queryParams, Response::HTTP_MOVED_PERMANENTLY);
        }

        $redirectUrl = $this->dispatchLoginRedirect($queryParams);
        if ($this->generateUrl('pimcore_admin_login', $queryParams) != $redirectUrl) {
            return new RedirectResponse($redirectUrl);
        }

        $csrfProtection->regenerateCsrfToken($request->getSession());

        $user = $this->getUser();
        if ($user instanceof UserInterface) {
            return $this->redirectToRoute('pimcore_admin_index');
        }

        $params = $this->buildLoginPageViewParams($config);

        $session_gc_maxlifetime = ini_get('session.gc_maxlifetime');
        if (empty($session_gc_maxlifetime)) {
            $session_gc_maxlifetime = 120;
        }

        $params['csrfTokenRefreshInterval'] = ((int)$session_gc_maxlifetime - 60) * 1000;

        if ($request->get('too_many_attempts')) {
            $params['error'] = SecurityHelper::convertHtmlSpecialChars($request->get('too_many_attempts'));
        }
        if ($request->get('auth_failed')) {
            $params['error'] = 'error_auth_failed';
        }
        if ($request->get('session_expired')) {
            $params['error'] = 'error_session_expired';
        }
        if ($request->get('deeplink')) {
            $params['deeplink'] = true;
        }

        $params['browserSupported'] = $this->detectBrowser();
        $params['debug'] = \Pimcore::inDebugMode();

        $params['includeTemplates'] = [];
        $event = new GenericEvent($this, [
            'parameters' => $params,
            'config' => $config,
            'request' => $request,
        ]);

        $this->eventDispatcher->dispatch($event, AdminEvents::LOGIN_BEFORE_RENDER);
        $params = $event->getArgument('parameters');

        $params['login_error'] = $authenticationUtils->getLastAuthenticationError();

        return $this->render('@PimcoreAdmin/admin/login/login.html.twig', $params);
    }

    /**
     * @Route("/login/csrf-token", name="pimcore_admin_login_csrf_token")
     */
    public function csrfTokenAction(Request $request, CsrfProtectionHandler $csrfProtection): \Symfony\Component\HttpFoundation\JsonResponse
    {
        if (!$this->getAdminUser()) {
            $csrfProtection->regenerateCsrfToken($request->getSession());
        }

        return $this->json([
           'csrfToken' => $csrfProtection->getCsrfToken($request->getSession()),
        ]);
    }

    /**
     * @Route("/logout", name="pimcore_admin_logout" , methods={"POST"})
     */
    public function logoutAction(): void
    {
        // this route will never be matched, but will be handled by the logout handler
    }

    /**
     * Dummy route used to check authentication
     *
     * @Route("/login/login", name="pimcore_admin_login_check")
     */
    public function loginCheckAction(Request $request): RedirectResponse
    {
        // just in case the authenticator didn't redirect
        return new RedirectResponse($this->generateUrl('pimcore_admin_login', ['perspective' => strip_tags($request->get('perspective', ''))]));
    }

    /**
     * @Route("/login/lostpassword", name="pimcore_admin_login_lostpassword")
     */
    public function lostpasswordAction(Request $request, CsrfProtectionHandler $csrfProtection, Config $config, RateLimiterFactory $resetPasswordLimiter): Response
    {
        $params = $this->buildLoginPageViewParams($config);
        $error = null;

        if ($request->getMethod() === 'POST' && $username = $request->get('username')) {
            $user = User::getByName($username);
            if (!$user instanceof User) {
                $error = 'user_unknown';
            }

            $limiter = $resetPasswordLimiter->create($request->getClientIp());

            if (false === $limiter->consume(1)->isAccepted()) {
                $error = 'user_reset_password_too_many_attempts';
            }

            if (!$error) {
                if (!$user->isActive()) {
                    $error = 'user_inactive';
                }
                if (!$user->getEmail()) {
                    $error = 'user_no_email_address';
                }
                if (!$user->getPassword()) {
                    $error = 'user_no_password';
                }
            }

            if (!$error) {
                $token = Authentication::generateToken($user->getName());

                $loginUrl = $this->generateUrl('pimcore_admin_login_check', [
                    'token' => $token,
                    'reset' => 'true',
                ], UrlGeneratorInterface::ABSOLUTE_URL);

                try {
                    $event = new LostPasswordEvent($user, $loginUrl);
                    $this->eventDispatcher->dispatch($event, AdminEvents::LOGIN_LOSTPASSWORD);

                    // only send mail if it wasn't prevented in event
                    if ($event->getSendMail()) {
                        $mail = Tool::getMail([$user->getEmail()], 'Pimcore lost password service');
                        $mail->setIgnoreDebugMode(true);
                        $mail->text("Login to pimcore and change your password using the following link. This temporary login link will expire in 24 hours: \r\n\r\n" . $loginUrl);
                        $mail->send();
                    }

                    // directly return event response
                    if ($event->hasResponse()) {
                        return $event->getResponse();
                    }
                } catch (\Exception $e) {
                    Logger::error('Error sending password recovery email: ' . $e->getMessage());
                    $error = 'lost_password_email_error';
                }
            }

            if ($error) {
                Logger::error('Lost password service: ' . $error);
            }
        }

        $csrfProtection->regenerateCsrfToken($request->getSession());

        if ($error) {
            $params['reset_error'] = 'Please make sure you are entering a correct input.';
            if ($error === 'user_reset_password_too_many_attempts') {
                $params['reset_error'] = 'Too many attempts. Please retry later.';
            }
        }

        return $this->render('@PimcoreAdmin/admin/login/lost_password.html.twig', $params);
    }

    /**
     * @Route("/login/deeplink", name="pimcore_admin_login_deeplink")
     */
    public function deeplinkAction(Request $request): Response
    {
        // check for deeplink
        $queryString = $_SERVER['QUERY_STRING'];

        if (preg_match('/(document|asset|object)_([0-9]+)_([a-z]+)/', $queryString, $deeplink)) {
            $deeplink = $deeplink[0];
            $perspective = strip_tags($request->get('perspective', ''));

            if (strpos($queryString, 'token')) {
                $url = $this->dispatchLoginRedirect([
                    'deeplink' => $deeplink,
                    'perspective' => $perspective,
                ]);
                $url .= '&' . $queryString;

                return $this->redirect($url);
            } elseif ($queryString) {
                $url = $this->dispatchLoginRedirect([
                    'deeplink' => 'true',
                    'perspective' => $perspective,
                ]);

                return $this->render('@PimcoreAdmin/admin/login/deeplink.html.twig', [
                    'tab' => $deeplink,
                    'redirect' => $url,
                ]);
            }
        }

        throw $this->createNotFoundException();
    }

    /**
     * @return array{config: Config, pluginCssPaths: string[]}
     */
    protected function buildLoginPageViewParams(Config $config): array
    {
        return [
            'config' => $config,
            'adminSettings' => AdminConfig::get(),
            'pluginCssPaths' => $this->bundleManager->getCssPaths(),
        ];
    }

    /**
     * @Route("/login/2fa", name="pimcore_admin_2fa")
     */
    public function twoFactorAuthenticationAction(Request $request, Config $config): Response
    {
        $params = $this->buildLoginPageViewParams($config);

        if ($request->hasSession()) {
            $session = $request->getSession();
            $authException = $session->get(Security::AUTHENTICATION_ERROR);
            if ($authException instanceof AuthenticationException) {
                $session->remove(Security::AUTHENTICATION_ERROR);

                $params['error'] = $authException->getMessage();
            }
        } else {
            $params['error'] = 'No session available, it either timed out or cookies are not enabled.';
        }

        return $this->render('@PimcoreAdmin/admin/login/two_factor_authentication.html.twig', $params);
    }

    /**
     * @Route("/login/2fa-setup", name="pimcore_admin_2fa_setup")
     */
    public function twoFactorSetupAuthenticationAction(
        Request $request,
        Config $config,
        GoogleAuthenticatorInterface $twoFactor
    ): Response {
        $params = $this->buildLoginPageViewParams($config);
        $params['setup'] = true;

        $user = $this->getAdminUser();
        $proxyUser = $this->getAdminUser(true);

        if ($request->query->get('error')) {
            $params['error'] = $request->query->get('error');
        }

        if ($request->isMethod('post')) {
            $secret = $request->getSession()->get('2fa_secret');

            if (!$secret) {
                throw new \Exception('2fa secret not found');
            }

            $user->setTwoFactorAuthentication('enabled', true);
            $user->setTwoFactorAuthentication('type', 'google');
            $user->setTwoFactorAuthentication('secret', $secret);

            if (!$twoFactor->checkCode($proxyUser, $request->request->get('_auth_code'))) {
                return new RedirectResponse($this->generateUrl('pimcore_admin_2fa_setup', ['error' => '2fa_wrong']));
            }

            $user->save();

            return new RedirectResponse($this->generateUrl('pimcore_admin_login'));
        }

        $newSecret = $twoFactor->generateSecret();

        $request->getSession()->set('2fa_secret', $newSecret);

        $user->setTwoFactorAuthentication('enabled', true);
        $user->setTwoFactorAuthentication('type', 'google');
        $user->setTwoFactorAuthentication('secret', $newSecret);

        $url = $twoFactor->getQRContent($proxyUser);

        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($url)
            ->size(200)
            ->build();

        $params['image'] = $result->getDataUri();

        return $this->render('@PimcoreAdmin/admin/login/two_factor_setup.html.twig', $params);
    }

    /**
     * @Route("/login/2fa-verify", name="pimcore_admin_2fa-verify")
     *
     * @param Request $request
     */
    public function twoFactorAuthenticationVerifyAction(Request $request): void
    {
    }

    public function detectBrowser(): bool
    {
        $supported = false;
        $browser = new \Browser();
        $browserVersion = (int)$browser->getVersion();

        if ($browser->getBrowser() == \Browser::BROWSER_FIREFOX && $browserVersion >= 72) {
            $supported = true;
        }
        if ($browser->getBrowser() == \Browser::BROWSER_CHROME && $browserVersion >= 84) {
            $supported = true;
        }
        if ($browser->getBrowser() == \Browser::BROWSER_SAFARI && $browserVersion >= 13.1) {
            $supported = true;
        }
        if ($browser->getBrowser() == \Browser::BROWSER_EDGE && $browserVersion >= 90) {
            $supported = true;
        }

        return $supported;
    }

    private function dispatchLoginRedirect(array $routeParams = []): string
    {
        $event = new LoginRedirectEvent('pimcore_admin_login', $routeParams);
        $this->eventDispatcher->dispatch($event, AdminEvents::LOGIN_REDIRECT);

        return $this->generateUrl($event->getRouteName(), $event->getRouteParams());
    }
}
