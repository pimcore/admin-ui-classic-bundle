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

namespace Pimcore\Bundle\AdminBundle\Controller\Traits;

use Pimcore\Bundle\AdminBundle\Service\ElementService;
use Pimcore\Model\Element\ElementInterface;

/**
 * @internal
 *
 * @deprecated
 */
trait DocumentTreeConfigTrait
{
    use AdminStyleTrait;

    protected ElementService $elementService;

    /**
     * @required
     * @param ElementService $elementService
     */
    public function setElementService(ElementService $elementService): void
    {
        $this->elementService = $elementService;
    }

    /**
     * @param ElementInterface $element
     *
     * @return array
     *
     * @throws \Exception
     */
    public function getTreeNodeConfig(ElementInterface $element): array
    {
        return $this->elementService->getElementTreeNodeConfig($element, $this->getPimcoreUser());
    }
}
