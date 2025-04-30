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

namespace Pimcore\Bundle\AdminBundle\Service\GridData;

use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Element\Service;

/**
 *
 * @internal
 */
abstract class Element
{
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
