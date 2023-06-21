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

use Pimcore\Bundle\AdminBundle\CustomView\Config;
use Pimcore\Bundle\AdminBundle\Event\ElementAdminStyleEvent;
use Pimcore\Bundle\AdminBundle\Service\ElementService;
use Pimcore\Model\Document;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Site;
use Pimcore\Tool\Admin;
use Pimcore\Tool\Frontend;

/**
 * @internal
 */
trait DocumentTreeConfigTrait
{
    use AdminStyleTrait;

    public function __construct(protected ElementService $elementService)
    {

    }
    /**
     * @param ElementInterface $element
     *
     * @return array
     *
     * @throws \Exception
     */
    public function getTreeNodeConfig(ElementInterface $element)
    {

    }
}
