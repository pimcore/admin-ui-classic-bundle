/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.object.classes.data.imageGallery");
/**
 * @private
 */
pimcore.object.classes.data.imageGallery = Class.create(pimcore.object.classes.data.hotspotimage, {

    type: "imageGallery",
    /**
     * define where this datatype is allowed
     */
    allowIn: {
        object: true,
        objectbrick: true,
        fieldcollection: true,
        localizedfield: true,
        classificationstore : false,
        block: true
    },

    initialize: function (treeNode, initData) {
        this.type = "imageGallery";

        this.initData(initData);

        // overwrite default settings
        this.availableSettingsFields = ["name","title","tooltip","mandatory","noteditable","invisible",
                                        "visibleGridView","visibleSearch","style"];

        this.treeNode = treeNode;
    },

    getTypeName: function () {
        return t("imageGallery");
    },

    getIconClass: function () {
        return "pimcore_icon_imageGallery";
    },

    getGroup: function () {
        return "media";
    },

    getLayout: function ($super) {

        $super();

        return this.layout;
    }
});
