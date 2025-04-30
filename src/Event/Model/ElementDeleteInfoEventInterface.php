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

namespace Pimcore\Bundle\AdminBundle\Event\Model;

use Pimcore\Event\Model\ElementEventInterface;

interface ElementDeleteInfoEventInterface extends ElementEventInterface
{
    public function getDeletionAllowed(): bool;

    public function setDeletionAllowed(bool $deletionAllowed): void;

    public function getReason(): string;

    public function setReason(string $reason): void;
}
