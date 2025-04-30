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

    #[Required]
    public function setElementService(ElementServiceInterface $elementService): void
    {
        $this->elementService = $elementService;
    }

    /**
     * @throws \Exception
     */
    public function getTreeNodeConfig(ElementInterface $element): array
    {
        return $this->elementService->getElementTreeNodeConfig($element);
    }
}
