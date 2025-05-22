/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.object.classes.data.password");
/**
 * @private
 */
pimcore.object.classes.data.password = Class.create(pimcore.object.classes.data.data, {

    type: "password",
    /**
     * define where this datatype is allowed
     */
    allowIn: {
        object: true,
        objectbrick: true,
        fieldcollection: true,
        localizedfield: true,
        classificationstore : true,
        block: true
    },
	statics : {
		CONFIG_DATA : [
			['front', 'Front'],
			['back', 'Back']
		]
	},

    initialize: function (treeNode, initData) {
        this.type = "password";

        this.initData(initData);

        // overwrite default settings
        this.availableSettingsFields = ["name","title","tooltip","noteditable","invisible","visibleGridView",
                                        "visibleSearch","index","style"];

        this.treeNode = treeNode;
    },

    getTypeName: function () {
        return t("password");
    },

    getGroup: function () {
        return "text";
    },

    getIconClass: function () {
        return "pimcore_icon_password";
    },

    getLayout: function ($super) {

        $super();

        this.specificPanel.removeAll();
        this.specificPanel.add([
            {
                xtype: "textfield",
                fieldLabel: t("width"),
                name: "width",
                value: this.datax.width
            },
            {
                xtype: "displayfield",
                hideLabel: true,
                value: t('width_explanation')
            }
        ]);

        if(!this.isInCustomLayoutEditor()) {
            this.specificPanel.add([
                {
                    xtype: "numberfield",
                    fieldLabel: t("min_length"),
                    name: "minimumLength",
                    minValue: 0,
                    value: this.datax.minimumLength
                },
            ]);
        }

        return this.layout;
    },

    applySpecialData: function(source) {
        if (source.datax) {
            if (!this.datax) {
                this.datax =  {};
            }
            Ext.apply(this.datax,
                {
                    width: source.datax.width,
                    minimumLength: source.datax.minimumLength
                });
        }
    }

});
