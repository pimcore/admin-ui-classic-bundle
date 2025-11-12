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

namespace Pimcore\Bundle\AdminBundle\Controller\Admin\Document;

use Pimcore\Bundle\AdminBundle\Controller\AdminAbstractController;
use Pimcore\Document\Editable\EditableHandler;
use Pimcore\Event\DocumentEvents;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\Document;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Element\Service;
use Pimcore\Templating\Renderer\ActionRenderer;
use Symfony\Cmf\Bundle\RoutingBundle\Routing\DynamicRouter;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
class RenderletController extends AdminAbstractController
{
    /**
     * Handles editmode preview for renderlets
     */
    #[Route('/document_tag/renderlet', name: 'pimcore_admin_document_renderlet_renderlet')]
    public function renderletAction(
        Request $request,
        ActionRenderer $actionRenderer,
        EditableHandler $editableHandler,
        LocaleServiceInterface $localeService,
        EventDispatcherInterface $eventDispatcher
    ): Response {
        $query = $request->query->all();
        $attributes = [];

        // load element to make sure the request is valid
        $element = $this->loadElement($request);

        $event = new GenericEvent($this, [
            'requestParams' => array_merge($request->request->all(), $request->query->all()),
            'element' => $element,
        ]);
        $eventDispatcher->dispatch($event, DocumentEvents::EDITABLE_RENDERLET_PRE_RENDER);

        $controller = $request->get('controller');

        // set document if set in request
        if ($documentId = $request->get('pimcore_parentDocument')) {
            $document = Document\PageSnippet::getById((int) $documentId);
            if ($document) {
                $attributes = $actionRenderer->addDocumentAttributes($document, $attributes);
                unset($attributes[DynamicRouter::CONTENT_TEMPLATE]);
            }
        }

        // override template if set
        if ($template = $request->get('template')) {
            $attributes[DynamicRouter::CONTENT_TEMPLATE] = $template;
        }

        foreach (['controller', 'action', 'module', 'bundle'] as $key) {
            if (isset($query[$key])) {
                unset($query[$key]);
            }
        }

        // setting locale manually here before rendering the action to make sure editables use the right locale - if this
        // is needed in multiple places, move this to the tag handler instead (see #1834)
        if (isset($attributes['_locale'])) {
            $localeService->setLocale($attributes['_locale']);
        }

        $result = $editableHandler->renderAction($controller, $attributes, $query);

        return new Response($result);
    }

    private function loadElement(Request $request): ElementInterface
    {
        $element = null;

        $id = $request->get('id');
        $type = $request->get('type');

        if ($id && $type) {
            $element = Service::getElementById($type, (int)$id);
        }

        if (!$element instanceof ElementInterface) {
            throw $this->createNotFoundException(sprintf('Element with type %s and ID %d was not found', $type ?: 'null', $id ?: 'null'));
        }

        if (!$element->isAllowed('view')) {
            throw $this->createAccessDeniedException(sprintf('Access to element with type %s and ID %d is not allowed', $type, $id));
        }

        return $element;
    }
}
