/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS('pimcore.object.classes.data.geopoint');
/**
 * @private
 */
pimcore.object.classes.data.geopoint = Class.create(pimcore.object.classes.data.geo.abstract, {

    type: 'geopoint',

    initialize: function (treeNode, initData) {
        this.type = "geopoint";

        this.initData(initData);

        this.treeNode = treeNode;
    },

    getTypeName: function () {
        return t("geopoint");
    },

    getGroup: function () {
            return "geo";
    },

    getIconClass: function () {
        return "pimcore_icon_geopoint";
    }

});
