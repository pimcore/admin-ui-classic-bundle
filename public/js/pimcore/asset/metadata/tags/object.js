/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

pimcore.registerNS("pimcore.asset.metadata.tags.object");
/**
 * @private
 */
pimcore.asset.metadata.tags.object = Class.create(pimcore.asset.metadata.tags.manyToOneRelation, {

    type: "object",
    dataChanged: false,
    dataObjectFolderAllowed: false,

    initialize: function (data, fieldConfig) {

        this.type = "object";
        this.data = null;

        if (data) {
            this.data = data;
        }
        this.fieldConfig = fieldConfig;
    }
});
