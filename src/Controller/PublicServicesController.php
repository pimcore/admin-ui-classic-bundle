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

namespace Pimcore\Bundle\AdminBundle\Controller;

use Pimcore\Controller\Controller;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
class PublicServicesController extends Controller
{
    public function customAdminEntryPointAction(Request $request): RedirectResponse
    {
        $params = $request->query->all();

        $url = match (true) {
            isset($params['token'])    => $this->generateUrl('pimcore_admin_login_check', $params),
            isset($params['deeplink']) => $this->generateUrl('pimcore_admin_login_deeplink', $params),
            default                    => $this->generateUrl('pimcore_admin_login', $params)
        };

        $redirect = new RedirectResponse($url);

        $customAdminPathIdentifier = $this->getParameter('pimcore_admin.custom_admin_path_identifier');
        if (!empty($customAdminPathIdentifier) && $request->cookies->get('pimcore_custom_admin') != $customAdminPathIdentifier) {
            $redirect->headers->setCookie(new Cookie('pimcore_custom_admin', $customAdminPathIdentifier, strtotime('+1 year')));
        }

        return $redirect;
    }
}
