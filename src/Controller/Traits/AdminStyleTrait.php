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

namespace Pimcore\Bundle\AdminBundle\Controller\Traits;

use Pimcore\Bundle\AdminBundle\Event\AdminEvents;
use Pimcore\Bundle\AdminBundle\Event\ElementAdminStyleEvent;
use Pimcore\Model\Element\AdminStyle;
use Pimcore\Model\Element\ElementInterface;

/**
 * @internal
 */
trait AdminStyleTrait
{
    /**
     * @throws \Exception
     */
    protected function addAdminStyle(ElementInterface $element, ?int $context = null, array &$data = []): void
    {
        $event = new ElementAdminStyleEvent($element, new AdminStyle($element), $context);
        \Pimcore::getEventDispatcher()->dispatch($event, AdminEvents::RESOLVE_ELEMENT_ADMIN_STYLE);
        $adminStyle = $event->getAdminStyle();

        $data['iconCls'] = $adminStyle->getElementIconClass() !== false ? $adminStyle->getElementIconClass() : null;
        if (!$data['iconCls']) {
            $data['icon'] = $adminStyle->getElementIcon() !== false ? $adminStyle->getElementIcon() : null;
        } else {
            $data['icon'] = null;
        }
        if ($adminStyle->getElementCssClass() !== false) {
            if (!isset($data['cls'])) {
                $data['cls'] = '';
            }
            $data['cls'] .= $adminStyle->getElementCssClass() . ' ';
        }
        $data['qtipCfg'] = $adminStyle->getElementQtipConfig();

        $elementText = $adminStyle->getElementText();
        if ($elementText !== null) {
            $data['text'] = $elementText;
        }

    }
}
