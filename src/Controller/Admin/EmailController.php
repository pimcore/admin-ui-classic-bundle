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
use Pimcore\Http\RequestHelper;
use Pimcore\Logger;
use Pimcore\Mail;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Tool;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/email")
 *
 * @internal
 */
class EmailController extends AdminAbstractController
{
    /**
     * @Route("/email-logs", name="pimcore_admin_email_emaillogs", methods={"GET", "POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function emailLogsAction(Request $request): JsonResponse
    {
        if (!$this->getAdminUser()->isAllowed('emails') && !$this->getAdminUser()->isAllowed('gdpr_data_extractor')) {
            throw new \Exception("Permission denied, user needs 'emails' permission.");
        }

        $list = new Tool\Email\Log\Listing();
        if ($request->get('documentId')) {
            $list->setCondition('documentId = ' . (int)$request->get('documentId'));
        }
        $list->setLimit((int)$request->get('limit', 50));
        $list->setOffset((int)$request->get('start', 0));
        $list->setOrderKey('sentDate');

        if ($request->get('filter')) {
            $filterTerm = $request->get('filter');
            if ($filterTerm == '*') {
                $filterTerm = '';
            }

            $filterTerm = str_replace('%', '*', $filterTerm);
            $filterTerm = htmlspecialchars($filterTerm, ENT_QUOTES);

            if (strpos($filterTerm, '@')) {
                $parts = explode(' ', $filterTerm);
                $parts = array_map(function ($part) {
                    if (strpos($part, '@')) {
                        $part = '"' . $part . '"';
                    }

                    return $part;
                }, $parts);
                $filterTerm = implode(' ', $parts);
            }

            $condition = '( MATCH (`from`,`to`,`cc`,`bcc`,`subject`,`params`) AGAINST (' . $list->quote($filterTerm) . ' IN BOOLEAN MODE) )';

            if ($request->get('documentId')) {
                $condition .= 'AND documentId = ' . (int)$request->get('documentId');
            }

            $list->setCondition($condition);
        }

        $list->setOrder('DESC');

        $data = $list->load();
        $jsonData = [];

        if (is_array($data)) {
            foreach ($data as $entry) {
                $tmp = $entry->getObjectVars();
                unset($tmp['bodyHtml']);
                unset($tmp['bodyText']);
                $jsonData[] = $tmp;
            }
        }

        return $this->adminJson([
            'data' => $jsonData,
            'success' => true,
            'total' => $list->getTotalCount(),
        ]);
    }

    /**
     * @Route("/show-email-log", name="pimcore_admin_email_showemaillog", methods={"GET"})
     *
     * @param Request $request
     * @param Profiler|null $profiler
     *
     * @return JsonResponse|Response
     *
     * @throws \Exception
     */
    public function showEmailLogAction(Request $request, ?Profiler $profiler): JsonResponse|Response
    {
        if ($profiler) {
            $profiler->disable();
        }

        if (!$this->getAdminUser()->isAllowed('emails')) {
            throw $this->createAccessDeniedHttpException("Permission denied, user needs 'emails' permission.");
        }

        $type = $request->get('type');
        $emailLog = Tool\Email\Log::getById((int) $request->get('id'));

        if (!$emailLog) {
            throw $this->createNotFoundException();
        }

        if ($type === 'text') {
            return $this->render('@PimcoreAdmin/admin/email/text.html.twig', ['log' => $emailLog->getTextLog()]);
        } elseif ($type === 'html') {
            return new Response($emailLog->getHtmlLog(), 200, [
                'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src * data:",
            ]);
        } elseif ($type === 'params') {
            try {
                $params = $emailLog->getParams();
            } catch (\Exception $e) {
                Logger::warning('Could not decode JSON param string');
                $params = [];
            }
            foreach ($params as &$entry) {
                $this->enhanceLoggingData($entry);
            }

            return $this->adminJson($params);
        } elseif ($type === 'details') {
            $data = $emailLog->getObjectVars();

            return $this->adminJson($data);
        } else {
            return new Response('No Type specified');
        }
    }

    /**
     * @param array $data
     * @param array|null $fullEntry
     */
    protected function enhanceLoggingData(array &$data, array &$fullEntry = null): void
    {
        if (!empty($data['objectClass'])) {
            $class = '\\' . ltrim($data['objectClass'], '\\');
            $reflection = new \ReflectionClass($class);

            if (!empty($data['objectId']) && $reflection->implementsInterface(ElementInterface::class)) {
                $obj = $class::getById($data['objectId']);
                if (is_null($obj)) {
                    $data['objectPath'] = '';
                } else {
                    $data['objectPath'] = $obj->getRealFullPath();
                }
                //check for classmapping
                if (stristr($class, '\\Pimcore\\Model') === false) {
                    $niceClassName = '\\' . ltrim($reflection->getParentClass()->getName(), '\\');
                } else {
                    $niceClassName = $class;
                }
                $niceClassName = str_replace('\\Pimcore\\Model\\', '', $niceClassName);
                $niceClassName = str_replace('_', '\\', $niceClassName);

                $tmp = explode('\\', $niceClassName);
                if (in_array($tmp[0], ['DataObject', 'Document', 'Asset'])) {
                    $data['objectClassBase'] = $tmp[0];
                    $data['objectClassSubType'] = $tmp[1];
                }
            }
        }

        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->enhanceLoggingData($value, $data);
            }
        }
        if ($data['children'] ?? false) {
            foreach ($data['children'] as $key => $entry) {
                if (is_string($key)) { //key must be integers
                    unset($data['children'][$key]);
                }
            }
            $data['iconCls'] = 'pimcore_icon_folder';
            $data['data'] = ['type' => 'simple', 'value' => 'Children (' . count($data['children']) . ')'];
        } else {
            //setting the icon class
            if (empty($data['iconCls'])) {
                if (($data['objectClassBase'] ?? '') == 'DataObject') {
                    $fullEntry['iconCls'] = 'pimcore_icon_object';
                } elseif (($data['objectClassBase'] ?? '') == 'Asset') {
                    switch ($data['objectClassSubType']) {
                        case 'Image':
                            $fullEntry['iconCls'] = 'pimcore_icon_image';

                            break;
                        case 'Video':
                            $fullEntry['iconCls'] = 'pimcore_icon_wmv';

                            break;
                        case 'Text':
                            $fullEntry['iconCls'] = 'pimcore_icon_txt';

                            break;
                        case 'Document':
                            $fullEntry['iconCls'] = 'pimcore_icon_pdf';

                            break;
                        default:
                            $fullEntry['iconCls'] = 'pimcore_icon_asset';
                    }
                } elseif (strpos($data['objectClass'] ?? '', 'Document') === 0) {
                    $fullEntry['iconCls'] = 'pimcore_icon_' . strtolower($data['objectClassSubType']);
                } else {
                    $data['iconCls'] = 'pimcore_icon_text';
                }
            }

            $data['leaf'] = true;
        }
    }

    /**
     * @Route("/delete-email-log", name="pimcore_admin_email_deleteemaillog", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function deleteEmailLogAction(Request $request): JsonResponse
    {
        if (!$this->getAdminUser()->isAllowed('emails')) {
            throw $this->createAccessDeniedHttpException("Permission denied, user needs 'emails' permission.");
        }

        $success = false;
        $emailLog = Tool\Email\Log::getById((int) $request->get('id'));
        if ($emailLog instanceof Tool\Email\Log) {
            $emailLog->delete();
            $success = true;
        }

        return $this->adminJson([
            'success' => $success,
        ]);
    }

    /**
     * @Route("/resend-email", name="pimcore_admin_email_resendemail", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function resendEmailAction(Request $request): JsonResponse
    {
        if (!$this->getAdminUser()->isAllowed('emails')) {
            throw $this->createAccessDeniedHttpException("Permission denied, user needs 'emails' permission.");
        }

        $success = false;
        $emailLog = Tool\Email\Log::getById((int) $request->get('id'));

        if ($emailLog instanceof Tool\Email\Log) {
            $mail = new Mail();
            $mail->preventDebugInformationAppending();
            $mail->setIgnoreDebugMode(true);

            if (!empty($request->get('to'))) {
                $emailLog->setTo(null);
                $emailLog->setCc(null);
                $emailLog->setBcc(null);
            } else {
                $mail->disableLogging();
            }

            if ($html = $emailLog->getHtmlLog()) {
                $mail->html($html);
            }

            if ($text = $emailLog->getTextLog()) {
                $mail->text($text);
            }

            foreach (['From', 'To', 'Cc', 'Bcc', 'ReplyTo'] as $field) {
                if (!$values = $request->get(strtolower($field))) {
                    $getter = 'get' . $field;
                    $values = $emailLog->{$getter}();
                }

                $values = \Pimcore\Helper\Mail::parseEmailAddressField($values);

                if ($values) {
                    [$value] = $values;
                    $prefix = 'add';
                    $mail->{$prefix . $field}(new Address($value['email'], $value['name']));
                }
            }

            $mail->subject($emailLog->getSubject());

            // add document
            if ($emailLog->getDocumentId()) {
                $mail->setDocument($emailLog->getDocumentId());
            }

            // re-add params
            try {
                $params = $emailLog->getParams();
            } catch (\Exception $e) {
                Logger::warning('Could not decode JSON param string');
                $params = [];
            }

            foreach ($params as $entry) {
                $data = null;
                $hasChildren = isset($entry['children']) && is_array($entry['children']);

                if ($hasChildren) {
                    $childData = [];
                    foreach ($entry['children'] as $childParam) {
                        $childData[$childParam['key']] = $this->parseLoggingParamObject($childParam);
                    }
                    $data = $childData;
                } else {
                    $data = $this->parseLoggingParamObject($entry);
                }

                $mail->setParam($entry['key'], $data);
            }

            $mail->send();
            $success = true;
        }

        return $this->adminJson([
            'success' => $success,
        ]);
    }

    /**
     * @Route("/send-test-email", name="pimcore_admin_email_sendtestemail", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function sendTestEmailAction(Request $request): JsonResponse
    {
        if (!$this->getAdminUser()->isAllowed('emails')) {
            throw new \Exception("Permission denied, user needs 'emails' permission.");
        }

        // Simulate a frontend request to prefix assets
        $request->attributes->set(RequestHelper::ATTRIBUTE_FRONTEND_REQUEST, true);

        $mail = new Mail();

        if ($request->get('emailType') == 'text') {
            $mail->text($request->get('content'));
        } elseif ($request->get('emailType') == 'html') {
            $mail->html($request->get('content'));
        } elseif ($request->get('emailType') == 'document') {
            $doc = \Pimcore\Model\Document::getByPath($request->get('documentPath'));

            if ($doc instanceof \Pimcore\Model\Document\Email) {
                $mail->setDocument($doc);

                if ($request->get('mailParamaters')) {
                    if ($mailParamsArray = json_decode($request->get('mailParamaters'), true)) {
                        foreach ($mailParamsArray as $mailParam) {
                            if ($mailParam['key']) {
                                $mail->setParam($mailParam['key'], $mailParam['value']);
                            }
                        }
                    }
                }
            } else {
                throw new \Exception('Email document not found!');
            }
        }

        if ($from = $request->get('from')) {
            $addressArray = \Pimcore\Helper\Mail::parseEmailAddressField($from);
            if ($addressArray) {
                //use the first address only
                [$cleanedFromAddress] = $addressArray;
                $mail->from(new Address($cleanedFromAddress['email'], $cleanedFromAddress['name']));
            }
        }

        $toAddresses = \Pimcore\Helper\Mail::parseEmailAddressField($request->get('to'));
        foreach ($toAddresses as $cleanedToAddress) {
            $mail->addTo($cleanedToAddress['email'], $cleanedToAddress['name']);
        }

        $mail->subject($request->get('subject'));
        $mail->setIgnoreDebugMode(true);

        $mail->send();

        return $this->adminJson([
            'success' => true,
        ]);
    }

    /**
     * @Route("/blocklist", name="pimcore_admin_email_blocklist", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function blocklistAction(Request $request): JsonResponse
    {
        if (!$this->getAdminUser()->isAllowed('emails')) {
            throw new \Exception("Permission denied, user needs 'emails' permission.");
        }

        if ($request->get('data')) {
            $data = $this->decodeJson($request->get('data'));

            if (is_array($data)) {
                foreach ($data as $key => &$value) {
                    if (is_string($value)) {
                        if ($key === 'address') {
                            $value = filter_var($value, FILTER_SANITIZE_EMAIL);
                        }

                        $value = trim($value);
                    }
                }
            }

            if ($request->get('xaction') == 'destroy') {
                $address = Tool\Email\Blocklist::getByAddress($data['address']);
                $address->delete();

                return $this->adminJson(['success' => true, 'data' => []]);
            } elseif ($request->get('xaction') == 'update') {
                $address = Tool\Email\Blocklist::getByAddress($data['address']);
                $address->setValues($data);
                $address->save();

                return $this->adminJson(['data' => $address->getObjectVars(), 'success' => true]);
            } elseif ($request->get('xaction') == 'create') {
                unset($data['id']);

                $address = new Tool\Email\Blocklist();
                $address->setValues($data);
                $address->save();

                return $this->adminJson(['data' => $address->getObjectVars(), 'success' => true]);
            }
        } else {
            // get list of routes

            $list = new Tool\Email\Blocklist\Listing();

            $list->setLimit((int) $request->get('limit', 50));
            $list->setOffset((int) $request->get('start', 0));

            $sortingSettings = \Pimcore\Bundle\AdminBundle\Helper\QueryParams::extractSortingSettings($request->query->all());
            if ($sortingSettings['orderKey']) {
                $orderKey = $sortingSettings['orderKey'];
            }
            if ($sortingSettings['order']) {
                $order = $sortingSettings['order'];
            }

            if ($request->get('filter')) {
                $list->setCondition('`address` LIKE ' . $list->quote('%'.$request->get('filter').'%'));
            }

            $data = $list->load();
            $jsonData = [];
            if (is_array($data)) {
                foreach ($data as $entry) {
                    $jsonData[] = $entry->getObjectVars();
                }
            }

            return $this->adminJson([
                'success' => true,
                'data' => $jsonData,
                'total' => $list->getTotalCount(),
            ]);
        }

        return $this->adminJson(['success' => false]);
    }

    protected function parseLoggingParamObject(array $params): ?array
    {
        $data = null;
        if ($params['data']['type'] === 'object') {
            $class = '\\' . ltrim($params['data']['objectClass'], '\\');
            $reflection = new \ReflectionClass($class);

            if (!empty($params['data']['objectId']) && $reflection->implementsInterface(ElementInterface::class)) {
                $obj = $class::getById($params['data']['objectId']);
                if (!is_null($obj)) {
                    $data = $obj;
                }
            }
        } else {
            $data = $params['data']['value'];
        }

        return $data;
    }
}
