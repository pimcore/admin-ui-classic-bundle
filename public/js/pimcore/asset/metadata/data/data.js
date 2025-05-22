/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.asset.metadata.data.data");
/**
 * @private
 */
pimcore.asset.metadata.data.data = Class.create({

    allowIn: {
        predefined: true,
        custom: true
    },

    getType: function () {
        return this.type;
    },

    getIconClass: function () {
        return "pimcore_icon_" + this.getType();
    },

    getTypeName: function () {
        return t(this.getType());
    }
});
