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

namespace Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig;

use Pimcore\Model\Element\ElementInterface;

interface ConfigElementInterface
{
    public function getLabel(): string;

    /**
     * @param ElementInterface|ElementInterface[] $element
     */
    public function getLabeledValue(array|ElementInterface $element): ResultContainer|\stdClass|null;

    public function getRenderer(): ?string;
}
