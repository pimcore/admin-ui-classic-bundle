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

namespace Pimcore\Bundle\AdminBundle\Service;


use Pimcore\Bundle\AdminBundle\CustomView\Config;

class ElementService
{
    /**
     * @param string $id
     *
     * @return array|null
     *
     * @internal
     */
    public static function getCustomViewById(string $id): ?array
    {
        $customViews = Config::get();
        if ($customViews) {
            foreach ($customViews as $customView) {
                if ($customView['id'] == $id) {
                    return $customView;
                }
            }
        }

        return null;
    }

    /**
     * Returns the first perspective name
     *
     * @internal
     */
    public function getFirstAllowedPerspective(): string
    {
        $perspectives = $this->getMergedPerspectives();
        if (!empty($perspectives)) {
            return $perspectives[0];
        } else {
            // all perspectives are allowed
            $perspectives = \Pimcore\Bundle\AdminBundle\Perspective\Config::getAvailablePerspectives($this);

            return $perspectives[0]['name'];
        }
    }
}
