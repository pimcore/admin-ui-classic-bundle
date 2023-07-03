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

use Pimcore\Bundle\AdminBundle\Service\ElementServiceInterface;
use Pimcore\Model\Element\ElementInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @internal
 *
 * @deprecated Use elementService instead.
 */
trait DocumentTreeConfigTrait
{
    use AdminStyleTrait;

    protected ElementServiceInterface $elementService;

    /**
     * @param ElementServiceInterface $elementService
     */
    #[Required]
    public function setElementService(ElementServiceInterface $elementService): void
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
        return $this->elementService->getElementTreeNodeConfig($element);
    }
}
