<?php

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

namespace Pimcore\Bundle\AdminBundle\Tests\Support\Helper;

// here you can define custom actions
// all public methods declared in helper class will be available in $I

class Model extends \Pimcore\Tests\Support\Helper\Model
{
    public function initializeDefinitions(): void
    {
        $this->setupPimcoreClass_Unittest();
        $this->setupPimcoreClass_Inheritance();
    }
}
