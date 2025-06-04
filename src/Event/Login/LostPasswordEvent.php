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

namespace Pimcore\Bundle\AdminBundle\Event\Login;

use Pimcore\Event\Traits\ResponseAwareTrait;
use Pimcore\Model\User;
use Symfony\Contracts\EventDispatcher\Event;

class LostPasswordEvent extends Event
{
    use ResponseAwareTrait;

    protected User $user;

    protected string $loginUrl;

    protected bool $sendMail = true;

    public function __construct(User $user, string $loginUrl)
    {
        $this->user = $user;
        $this->loginUrl = $loginUrl;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLoginUrl(): string
    {
        return $this->loginUrl;
    }

    /**
     * Determines if lost password mail should be sent
     */
    public function getSendMail(): bool
    {
        return $this->sendMail;
    }

    /**
     * Sets flag whether to send lost password mail or not
     *
     *
     * @return $this
     */
    public function setSendMail(bool $sendMail): static
    {
        $this->sendMail = $sendMail;

        return $this;
    }
}
