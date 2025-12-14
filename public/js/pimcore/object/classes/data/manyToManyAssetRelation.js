/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.object.classes.data.manyToManyAssetRelation");
/**
 * @private
 */
pimcore.object.classes.data.manyToManyAssetRelation = Class.create(pimcore.object.classes.data.manyToManyRelation, {

    type: "manyToManyAssetRelation",
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
        pimcore.object.classes.data.manyToManyRelation.prototype.initialize.call(this, treeNode, initData);

        this.type = "manyToManyAssetRelation";
        this.datax.fieldtype = this.getType();
        this.datax.objectsAllowed = false;
        this.datax.documentsAllowed = false;
        this.datax.assetsAllowed = true;

        pimcore.helpers.sanitizeAllowedTypes(this.datax, "assetTypes");

        this.availableSettingsFields = ["name","title","tooltip","mandatory","noteditable","invisible",
                                        "visibleGridView","visibleSearch","style"];

        this.treeNode = treeNode;
    },

    getTypeName: function () {
        return t("many_to_many_asset_relation");
    },

    getGroup: function () {
        return "relation";
    },

    getIconClass: function () {
        return "pimcore_icon_manyToManyAssetRelation";
    },

    getLayout: function () {
        pimcore.object.classes.data.manyToManyRelation.prototype.getLayout.call(this);

        this.datax.assetsAllowed = true;
        this.datax.objectsAllowed = false;
        this.datax.documentsAllowed = false;

        if (!this.isInCustomLayoutEditor()) {
            const removeItems = [];
            this.specificPanel.items.each(function(item) {
                if (item.down && (item.down('checkbox[name=documentsAllowed]') || item.down('checkbox[name=objectsAllowed]'))) {
                    removeItems.push(item);
                }
            }.bind(this));

            removeItems.forEach(function(item) {
                this.specificPanel.remove(item, true);
            }.bind(this));

            const assetsAllowedField = this.specificPanel.down('checkbox[name=assetsAllowed]');
            if (assetsAllowedField) {
                assetsAllowedField.setValue(true);
                assetsAllowedField.setDisabled(true);
            }
        }

        const visibleFieldsInput = {
            xtype: "textfield",
            width: 600,
            fieldLabel: t("objectsMetadata_visible_fields"),
            name: "visibleFields",
            value: this.datax.visibleFields
        };

        const layoutFieldset = this.specificPanel.items.first();
        if (layoutFieldset) {
            layoutFieldset.add(visibleFieldsInput);
        } else {
            this.specificPanel.add(visibleFieldsInput);
        }

        return this.layout;
    },

    applySpecialData: function(source) {
        pimcore.object.classes.data.manyToManyRelation.prototype.applySpecialData.call(this, source);

        if (source.datax) {
            this.datax.visibleFields = source.datax.visibleFields;
            this.datax.assetsAllowed = true;
            this.datax.objectsAllowed = false;
            this.datax.documentsAllowed = false;
        }
    }

});
