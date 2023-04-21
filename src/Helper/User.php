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

/**
 * @internal
 */
final class User
{
    protected const DEFAULT_KEY_BINDINGS = 'default_key_bindings';

    /**
     * @internal
     *
     * @return string
     */
    public static function getDefaultKeyBindings(): string
    {
        $container = Pimcore::getContainer();
        $userConfig = $container->getParameter('pimcore_admin.user');
        // make sure the default key binding node is in the config
        if (is_array($userConfig) && array_key_exists(self::DEFAULT_KEY_BINDINGS, $userConfig)) {
            $defaultKeyBindingsConfig = $userConfig[self::DEFAULT_KEY_BINDINGS];
            $defaultKeyBindings = [];
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

        if (!empty($defaultKeyBindings)) {
            return json_encode($defaultKeyBindings);
        }

        // keep for legacy reasons

        $bindings = [
            [
                'action' => 'save',
                'key' => ord('S'),
                'ctrl' => true,
            ],
            [
                'action' => 'publish',
                'key' => ord('P'),
                'ctrl' => true,
                'shift' => true,
            ],
            [
                'action' => 'unpublish',
                'key' => ord('U'),
                'ctrl' => true,
                'shift' => true,
            ],
            [
                'action' => 'rename',
                'key' => ord('R'),
                'alt' => true,
                'shift' => true,
            ],
            [
                'action' => 'refresh',
                'key' => 116,
            ],
            [
                'action' => 'openAsset',
                'key' => ord('A'),
                'ctrl' => true,
                'shift' => true,
            ],
            [
                'action' => 'openObject',
                'key' => ord('O'),
                'ctrl' => true,
                'shift' => true,
            ],
            [
                'action' => 'openDocument',
                'key' => ord('D'),
                'ctrl' => true,
                'shift' => true,
            ],
            [
                'action' => 'openClassEditor',
                'key' => ord('C'),
                'ctrl' => true,
                'shift' => true,

            ],
            [
                'action' => 'openInTree',
                'key' => ord('L'),
                'ctrl' => true,
                'shift' => true,

            ],
            [
                'action' => 'showMetaInfo',
                'key' => ord('I'),
                'alt' => true,
            ],
            [
                'action' => 'searchDocument',
                'key' => ord('W'),
                'alt' => true,
            ],
            [
                'action' => 'searchAsset',
                'key' => ord('A'),
                'alt' => true,
            ],
            [
                'action' => 'searchObject',
                'key' => ord('O'),
                'alt' => true,
            ],
            [
                'action' => 'showElementHistory',
                'key' => ord('H'),
                'alt' => true,
            ],
            [
                'action' => 'closeAllTabs',
                'key' => ord('T'),
                'alt' => true,
            ],
            [
                'action' => 'searchAndReplaceAssignments',
                'key' => ord('S'),
                'alt' => true,
            ],
            [
                'action' => 'redirects',
                'key' => ord('R'),
                'ctrl' => false,
                'alt' => true,
            ],
            [
                'action' => 'sharedTranslations',
                'key' => ord('T'),
                'ctrl' => true,
                'alt' => true,
            ],
            [
                'action' => 'recycleBin',
                'key' => ord('R'),
                'ctrl' => true,
                'alt' => true,
            ],
            [
                'action' => 'notesEvents',
                'key' => ord('N'),
                'ctrl' => true,
                'alt' => true,
            ],
            [
                'action' => 'applicationLogger',
                'key' => ord('L'),
                'ctrl' => true,
                'alt' => true,
            ],
            [
                'action' => 'tagManager',
                'key' => ord('H'),
                'ctrl' => true,
                'alt' => true,
            ],
            [
                'action' => 'seoDocumentEditor',
                'key' => ord('S'),
                'ctrl' => true,
                'alt' => true,
            ],
            [
                'action' => 'robots',
                'key' => ord('J'),
                'ctrl' => true,
                'alt' => true,
            ],
            [
                'action' => 'httpErrorLog',
                'key' => ord('O'),
                'ctrl' => true,
                'alt' => true,
            ],
            [
                'action' => 'tagConfiguration',
                'key' => ord('N'),
                'ctrl' => true,
                'alt' => true,
            ],
            [
                'action' => 'users',
                'key' => ord('U'),
                'ctrl' => true,
                'alt' => true,
            ],
            [
                'action' => 'roles',
                'key' => ord('P'),
                'ctrl' => true,
                'alt' => true,
            ],
            [
                'action' => 'clearAllCaches',
                'key' => ord('Q'),
                'ctrl' => false,
                'alt' => true,
            ],
            [
                'action' => 'clearDataCache',
                'key' => ord('C'),
                'ctrl' => false,
                'alt' => true,
            ],
            [
                'action' => 'quickSearch',
                'key' => ord('F'),
                'ctrl' => true,
                'shift' => true,
            ],
        ];

        return json_encode(self::strictKeybinds($bindings));
    }


    /**
     * @param list<array{action: string, key: int, alt?: bool, ctrl?: bool, shift?: bool}> $bindings
     *
     * @return list<array{action: string, key: int, alt: bool, ctrl: bool, shift: bool}>
     */
    public static function strictKeybinds(array $bindings): array
    {
        foreach ($bindings as $ind => $binding) {
            $bindings[$ind]['ctrl'] ??= false;
            $bindings[$ind]['alt'] ??= false;
            $bindings[$ind]['shift'] ??= false;
        }

        return $bindings;
    }
}
