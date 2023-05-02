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

use Pimcore\Model\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @internal
 */
trait UserNameTrait
{
    protected TranslatorInterface $translator;

    /**
     * @required
     *
     * @param TranslatorInterface $translator
     */
    public function setTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

    /**
     * @param int $userId The User ID.
     *
     * @return array{userName: string, fullName: string}
     */
    protected function getUserName(int $userId): array
    {
        /** @var User|null $user */
        $user = User::getById($userId);

        if (empty($user)) {
            $data = [
                'userName' => '',
                'fullName' => $this->translator->trans('user_unknown', [], 'admin'),
            ];
        } else {
            $data = [
                'userName' => $user->getName(),
                'fullName' => (empty($user->getFullName()) ? $user->getName() : $user->getFullName()),
            ];
        }

        return $data;
    }
}
