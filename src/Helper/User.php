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

namespace Pimcore\Bundle\AdminBundle\Helper;

use Pimcore;
use Pimcore\Security\User\User as UserProxy;

/**
 * @internal
 */
final class User
{
    protected const DEFAULT_KEY_BINDINGS = 'default_key_bindings';

    /**
     * @internal
     */
    public static function getDefaultKeyBindings(Pimcore\Model\User|UserProxy|null $user = null): string
    {
        if ($user instanceof Pimcore\Model\User && $user->getKeyBindings()) {
            return $user->getKeyBindings();
        }

        $defaultKeyBindings = [];
        $container = Pimcore::getContainer();
        $userConfig = $container->getParameter('pimcore_admin.user');
        // make sure the default key binding node is in the config
        if (is_array($userConfig) && array_key_exists(self::DEFAULT_KEY_BINDINGS, $userConfig)) {
            $defaultKeyBindingsConfig = $userConfig[self::DEFAULT_KEY_BINDINGS];
            if (!empty($defaultKeyBindingsConfig)) {
                foreach ($defaultKeyBindingsConfig as $keys) {
                    $defaultKeyBinding = [];
                    // we do not check if the keys are empty because key is required
                    foreach ($keys as $index => $value) {
                        if ($index === 'key') {
                            $value = ord($value);
                        }
                        $defaultKeyBinding[$index] = $value;
                    }
                    $defaultKeyBindings[] = $defaultKeyBinding;
                }
            }
        }

        return json_encode($defaultKeyBindings);
    }
}
