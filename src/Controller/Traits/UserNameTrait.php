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

use Pimcore\Model\User;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @internal
 */
trait UserNameTrait
{
    protected TranslatorInterface $translator;

    #[Required]
    public function setTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

    /**
     * @param int|null $userId The User ID.
     *
     * @return array{userName: string, fullName: string}
     */
    protected function getUserName(?int $userId = null): array
    {
        if ($userId === null) {
            return [
                'userName' => '',
                'fullName' => $this->translator->trans('user_unknown', [], 'admin'),
            ];
        }

        $user = User::getById($userId);

        if (empty($user)) {
            return [
                'userName' => '',
                'fullName' => $this->translator->trans('user_unknown', [], 'admin'),
            ];
        }

        return [
            'userName' => $user->getName(),
            'fullName' => (empty($user->getFullName()) ? $user->getName() : $user->getFullName()),
        ];
    }
}
