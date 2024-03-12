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
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\AdminBundle\Service\GridData;

use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Element\Service;

abstract class Element
{
    /**
     * /**
     * @internal
     *
     *
     */
    public static function gridElementData(ElementInterface $element): array
    {
        $data = [
            'id' => $element->getId(),
            'fullpath' => $element->getRealFullPath(),
            'type' => Service::getElementType($element),
            'subtype' => $element->getType(),
            'filename' => $element->getKey(),
            'creationDate' => $element->getCreationDate(),
            'modificationDate' => $element->getModificationDate(),
        ];

        if (method_exists($element, 'isPublished')) {
            $data['published'] = $element->isPublished();
        } else {
            $data['published'] = true;
        }

        return $data;
    }
}
