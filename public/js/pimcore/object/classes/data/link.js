/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.object.classes.data.link");
/**
 * @private
 */
pimcore.object.classes.data.link = Class.create(pimcore.object.classes.data.data, {
    targets: ['', '_blank', '_self', '_top', '_parent'],
    types: ['asset', 'document', 'object'],
    fields: ['text', 'target', 'parameters', 'anchor', 'title', 'accesskey', 'rel', 'tabindex', 'class', 'attributes'],
    type: "link",
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
        this.type = "link";

        this.initData(initData);

        // overwrite default settings
        this.availableSettingsFields = ["name","title","tooltip","noteditable","invisible","visibleGridView",
                                        "visibleSearch","style"];

        this.treeNode = treeNode;
    },

    getTypeName: function () {
        return t("link");
    },

    getIconClass: function () {
        return "pimcore_icon_link";
    },
    getLayout: function ($super) {
        $super();

        this.specificPanel.add([
            {
                xtype: "multiselect",
                fieldLabel: t("allowed_types") + '<br />' + t('allowed_types_hint'),
                name: "allowedTypes",
                id: 'allowedTypes',
                store: this.types.map((text) => ({text})),
                value: this.datax.allowedTypes,
                displayField: "text",
                valueField: "text",
                width: 400
            },
            {
                xtype: "multiselect",
                fieldLabel: t("allowed_asset_subtypes") + '<br />' + t('allowed_types_hint'),
                name: "allowedAssetSubtypes",
                id: 'allowedAssetSubtypes',
                store: pimcore.globalmanager.get('asset_search_types').filter(v => v !== "folder").map((text) => ({text})),
                value: this.datax.allowedAssetSubtypes,
                displayField: "text",
                valueField: "text",
                width: 400
            },
            {
                xtype: "multiselect",
                fieldLabel: t("allowed_document_subtypes") + '<br />' + t('allowed_types_hint'),
                name: "allowedDocumentSubtypes",
                id: 'allowedDocumentSubtypes',
                store: pimcore.globalmanager.get('document_search_types').filter(v => v !== "folder").map((text) => ({text})),
                value: this.datax.allowedDocumentSubtypes,
                displayField: "text",
                valueField: "text",
                width: 400
            },
            {
                xtype: "multiselect",
                fieldLabel: t("allowed_object_subtypes") + '<br />' + t('allowed_types_hint'),
                name: "allowedObjectSubtypes",
                id: 'allowedObjectSubtypes',
                store: pimcore.globalmanager.get('object_search_types').filter(v => v !== "folder").map((text) => ({text})),
                value: this.datax.allowedObjectSubtypes,
                displayField: "text",
                valueField: "text",
                width: 400
            },
            {
                xtype: "multiselect",
                fieldLabel: t("allowed_classes") + '<br />' + t('allowed_types_hint'),
                name: "allowedClasses",
                id: 'allowedClasses',
                store: pimcore.globalmanager.get("object_types_store"),
                value: this.datax.allowedClasses,
                displayField: "text",
                valueField: "text",
                width: 400
            },
            {
                xtype: "multiselect",
                fieldLabel: t("allowed_targets") + '<br />' + t('allowed_types_hint'),
                name: "allowedTargets",
                id: 'allowedTargets',
                store: this.targets.map((text) => ({text})),
                value: this.datax.allowedTargets,
                displayField: "text",
                valueField: "text",
                width: 400
            },
            {
                xtype: "multiselect",
                fieldLabel: t("disabled_fields") + '<br />' + t('allowed_types_hint'),
                name: "disabledFields",
                id: 'disabledFields',
                store: this.fields.map((text) => ({text})),
                value: this.datax.disabledFields,
                displayField: "text",
                valueField: "text",
                width: 400
            }
        ]);

        return this.layout;
    }
});
