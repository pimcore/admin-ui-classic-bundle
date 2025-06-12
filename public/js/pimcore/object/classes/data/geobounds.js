/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS('pimcore.object.classes.data.geobounds');
/**
 * @private
 */
pimcore.object.classes.data.geobounds = Class.create(pimcore.object.classes.data.geo.abstract, {

    type: "geobounds",

    initialize: function (treeNode, initData) {
        this.type = 'geobounds';

        this.initData(initData);

        // overwrite default settings
        this.availableSettingsFields = ["name","title","mandatory","noteditable","invisible","index","style"];

        this.treeNode = treeNode;
    },

    getTypeName: function () {
        return t('geobounds');
    },

    getGroup: function () {
            return 'geo';
    },

    getIconClass: function () {
        return 'pimcore_icon_geobounds';
    }

});
