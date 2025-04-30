<?php

/**
 * This source file is available under the terms of the
 * Pimcore Open Core License (POCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (https://www.pimcore.com)
 *  @license    Pimcore Open Core License (POCL)
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
