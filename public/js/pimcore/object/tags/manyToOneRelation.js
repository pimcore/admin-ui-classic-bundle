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

pimcore.registerNS("pimcore.object.tags.manyToOneRelation");
/**
 * @private
 */
pimcore.object.tags.manyToOneRelation = Class.create(pimcore.object.tags.abstract, {

    type: "manyToOneRelation",
    dataChanged: false,
    dataObjectFolderAllowed: false,

    initialize: function (data, fieldConfig) {

        this.data = {};

        if (data) {
            this.data = data;
        }
        this.fieldConfig = fieldConfig;

        this.fieldConfig.classes = this.fieldConfig.classes.filter(function (x) {
            if (x.classes == 'folder') {
                this.dataObjectFolderAllowed = true;
                return false;
            }
            return true;
        }.bind(this));

        this.fieldConfig.visibleFields = "fullpath";

        let storeConfig = {
            data: this.data,
            listeners: {
                add: function () {
                    this.dataChanged = true;
                }.bind(this),
                remove: function () {
                    this.dataChanged = true;
                }.bind(this),
                clear: function () {
                    this.dataChanged = true;
                }.bind(this)
            },
        };

        if (pimcore.helpers.hasSearchImplementation() && this.fieldConfig.displayMode === 'combo') {
            storeConfig.proxy = {
                type: 'ajax',
                url: pimcore.helpers.getObjectRelationInlineSearchRoute(),
                extraParams: {
                    fieldConfig: JSON.stringify(this.fieldConfig),
                    data: JSON.stringify(
                        (this.data.id && this.data.type) ? [{id: this.data.id, type: this.data.type}] : []
                    )
                },
                reader: {
                    type: 'json',
                    rootProperty: 'options',
                    successProperty: 'success',
                    messageProperty: 'message'
                }
            };
            storeConfig.fields = [
                'id',
                'type',
                'label',
                {
                    name: 'path',
                    convert: function (v, rec) {
                        return rec.data.label;
                    },
                    depends: ['label']
                },
                {
                    name: 'nicePathKey',
                    convert: function (v, rec) {
                        return rec.data.type + '_' + rec.data.id;
                    },
                    depends: ['type', 'id']
                }
            ];
            storeConfig.autoLoad = true;
            storeConfig.listeners = {
                beforeload: function (store) {
                    store.getProxy().setExtraParam('unsavedChanges', this.object && typeof this.object.getSaveData === "function" ? this.object.getSaveData().data : {});
                    store.getProxy().setExtraParam('context', JSON.stringify(this.getContext()));
                }.bind(this),
                load: function () {
                    this.requestNicePathData();
                }.bind(this)
            };
        }

        this.store = new Ext.data.JsonStore(storeConfig);
    },


    getGridColumnConfig: function (field) {
        const renderer = function (key, value, metaData, record) {
            this.applyPermissionStyle(key, value, metaData, record);

            if (record.data.inheritedFields?.[key]?.inherited) {
                metaData.tdCls += " grid_value_inherited";
            }

            if (value?.path) {
                return value.path;

            }
            return value;

        }.bind(this, field.key);

        return {
            text: t(field.label), sortable: false, dataIndex: field.key, renderer: renderer,
            getRelationFilter: this.getRelationFilter,
            getEditor: this.getWindowCellEditor.bind(this, field)
        };
    },

    getRelationFilter: function (dataIndex, editor) {
        const filterValue = editor.data && editor.data.id !== undefined ? editor.data.type + "|" + editor.data.id : null;
        return new Ext.util.Filter({
            operator: "=",
            type: "int",
            id: "x-gridfilter-" + dataIndex,
            property: dataIndex,
            dataIndex: dataIndex,
            value: filterValue === null ? 'null' : filterValue
        });
    },

    getLayoutEdit: function () {

        const href = {};

        const labelWidth = this.fieldConfig.labelWidth ? this.fieldConfig.labelWidth : 100;

        if (this.data) {
            if (this.data.path) {
                href.value = this.data.path;
            }
        }

        if (this.fieldConfig.width) {
            href.width = this.fieldConfig.width;
        } else {
            href.width = 300;
        }

        if (pimcore.helpers.hasSearchImplementation() && this.fieldConfig.displayMode === 'combo') {
            Object.assign(href, {
                store: this.store,
                autoLoadOnValue: true,
                labelWidth: labelWidth,
                forceSelection: true,
                height: 'auto',
                value: this.data.id,
                typeAhead: true,
                filterPickList: true,
                triggerAction: "all",
                displayField: "label",
                valueField: "id",
                listeners: {
                    change: function (comboBox, newValue) {
                        if (newValue) {
                            let record = this.store.getById(newValue);
                            if (record) {
                                this.dataChanged = true;
                                this.data.id = record.get('id');
                                this.data.type = record.get('type');
                            }
                        }
                    }.bind(this)
                }
            });

            this.component = Ext.create('Ext.form.field.ComboBox', href);
        } else {
            href.cls = 'pimcore_droptarget_display_edit';
            href.fieldBodyCls = 'pimcore_droptarget_display x-form-trigger-wrap';
            this.component = new Ext.form.field.Display(href);
            this.component.on('afterrender', function (el) {
                el.inputEl.setWidth(href.width);
                el.inputEl.setStyle({
                    'overflow': 'hidden'
                });
            });
        }

        if (this.data.published === false) {
            this.component.addCls("strikeThrough");
        }
        this.component.on("render", function (el) {
            // add drop zone
            new Ext.dd.DropZone(el.getEl(), {
                reference: this,
                ddGroup: "element",
                getTargetFromEvent: function (e) {
                    return this.reference.component.getEl();
                },

                onNodeOver: function (target, dd, e, data) {
                    if (data.records.length === 1 && this.dndAllowed(data.records[0].data)) {
                        return Ext.dd.DropZone.prototype.dropAllowed;
                    }
                }.bind(this),

                onNodeDrop: this.onNodeDrop.bind(this)
            });

            el.getEl().on("contextmenu", this.onContextMenu.bind(this));

            el.getEl().on('dblclick', function () {
                let subtype = this.data.subtype;
                if (this.data.type === "object" && this.data.subtype !== "folder" && this.data.subtype !== null) {
                    subtype = "object";
                }

                pimcore.helpers.openElement(this.data.id, this.data.type, subtype);
            }.bind(this));
        }.bind(this));

        const items = [this.component, {
            xtype: "button",
            iconCls: "pimcore_icon_open",
            tooltip: t("open"),
            style: "margin-left: 5px",
            handler: this.openElement.bind(this)
        }];

        if (this.fieldConfig.assetInlineDownloadAllowed) {
            items.push({
                xtype: "button",
                iconCls: "pimcore_icon_download",
                tooltip: t("download"),
                cls: "pimcore_inline_download",
                style: "margin-left: 5px",
                handler: this.downloadAsset.bind(this)
            });
        }

        if (this.fieldConfig.allowToClearRelation) {
            items.push({
                xtype: "button",
                iconCls: "pimcore_icon_delete",
                tooltip: t("empty"),
                style: "margin-left: 5px",
                handler: this.empty.bind(this)
            });
        }

        // add upload button when assets are allowed
        if (this.fieldConfig.assetsAllowed) {
            items.push({
                xtype: "button",
                iconCls: "pimcore_icon_upload",
                tooltip: t("upload"),
                cls: "pimcore_inline_upload",
                style: "margin-left: 5px",
                handler: this.uploadDialog.bind(this)
            });
        }

        if(pimcore.helpers.hasSearchImplementation()) {
            items.push({
                xtype: "button",
                iconCls: "pimcore_icon_search",
                tooltip: t("search"),
                style: "margin-left: 5px",
                handler: this.openSearchEditor.bind(this)
            });
        }

        const compositeCfg = {
            fieldLabel: this.fieldConfig.title,
            labelWidth: labelWidth,
            layout: 'hbox',
            items: items,
            componentCls: this.getWrapperClassNames(),
            border: false,
            style: {
                padding: 0
            },
            listeners: {
                afterrender: function () {
                    this.requestNicePathData();
                }.bind(this)
            }
        };

        if (this.fieldConfig.labelAlign) {
            compositeCfg.labelAlign = this.fieldConfig.labelAlign;
        }

        this.composite = Ext.create('Ext.form.FieldContainer', compositeCfg);

        return this.composite;
    },


    getLayoutShow: function () {

        const href = {
            name: this.fieldConfig.name
        };
        const labelWidth = this.fieldConfig.labelWidth ? this.fieldConfig.labelWidth : 100;

        if (this.data) {
            if (this.data.path) {
                href.value = this.data.path;
            }
        }

        if (this.fieldConfig.width) {
            href.width = this.fieldConfig.width;
        } else {
            href.width = 300;
        }
        href.disabled = true;

        this.component = new Ext.form.TextField(href);

        if (this.data.published === false) {
            this.component.addCls("strikeThrough");
        }

        const compositeCfg = {
            fieldLabel: this.fieldConfig.title,
            labelWidth: labelWidth,
            layout: 'hbox',
            items: [this.component, {
                xtype: "button",
                iconCls: "pimcore_icon_open",
                handler: this.openElement.bind(this)
            }],
            componentCls: this.getWrapperClassNames(),
            border: false,
            style: {
                padding: 0
            },
            listeners: {
                afterrender: function () {
                    this.requestNicePathData();
                }.bind(this)
            }
        };

        if (this.fieldConfig.labelAlign) {
            compositeCfg.labelAlign = this.fieldConfig.labelAlign;
        }

        this.composite = Ext.create('Ext.form.FieldContainer', compositeCfg);

        return this.composite;

    },

    uploadDialog: function () {
        pimcore.helpers.assetSingleUploadDialog(this.fieldConfig.assetUploadPath, "path", function (res) {
            try {
                const data = Ext.decode(res.response.responseText);
                if (data["id"]) {
                    this.data.id = data["id"];
                    this.data.type = "asset";
                    this.data.subtype = data["type"];
                    this.data.path = data["fullpath"];
                    this.dataChanged = true;
                    if (this.fieldConfig.displayMode == 'combo') {
                        if (!this.component.getStore().getById(data.id)) {
                            this.component.getStore().getProxy().setExtraParam('data', JSON.stringify([{id: this.data.id, type: this.data.type}]));
                            this.component.getStore().on('load', function(){
                                this.component.setValue(this.data.id);
                            }.bind(this), this, { single: true });
                            this.component.getStore().load();
                        }
                        this.component.setValue(this.data.id);
                    } else {
                        this.component.setValue(data["fullpath"]);
                    }
                    this.requestNicePathData();
                }
            } catch (e) {
                console.log(e);
            }
        }.bind(this),
        function (res) {
            const response = Ext.decode(res.response.responseText);
            if (response && response.success === false) {
                pimcore.helpers.showNotification(
                    t("error"),
                    response.message,
                    "error",
                    res.response.responseText
                );
            } else {
                pimcore.helpers.showNotification(
                    t("error"),
                    res,
                    "error",
                    res.response.responseText
                );
            }
        }, this.context);
    },

    onNodeDrop: function (target, dd, e, data) {

        if(!pimcore.helpers.dragAndDropValidateSingleItem(data)) {
            return false;
        }

        data = data.records[0].data;

        if (this.dndAllowed(data)) {
            this.data.id = data.id;
            this.data.type = data.elementType;
            this.data.subtype = data.type;
            this.data.path = data.path;
            this.dataChanged = true;

            this.component.removeCls("strikeThrough");
            if (data.published === false) {
                this.component.addCls("strikeThrough");
            }
            if (this.fieldConfig.displayMode == 'combo') {
                if (!this.component.getStore().getById(data.id)) {
                    this.component.getStore().getProxy().setExtraParam('data', JSON.stringify([{id: data.id, type: data.elementType}]));
                    this.component.getStore().on('load', function(){
                        this.component.setValue(data.id);
                    }.bind(this), this, { single: true });
                    this.component.getStore().load();
                }
                this.component.setValue(data.id);
            } else {
                this.component.setValue(data.path);
            }
            this.requestNicePathData();

            return true;
        } else {
            return false;
        }
    },

    onContextMenu: function (e) {

        const menu = new Ext.menu.Menu();
        menu.add(new Ext.menu.Item({
            text: t('empty'),
            iconCls: "pimcore_icon_delete",
            handler: function (item) {
                item.parentMenu.destroy();

                this.empty();
            }.bind(this)
        }));

        menu.add(new Ext.menu.Item({
            text: t('open'),
            iconCls: "pimcore_icon_open",
            handler: function (item) {
                item.parentMenu.destroy();
                this.openElement();
            }.bind(this)
        }));


        if(pimcore.helpers.hasSearchImplementation()) {
            menu.add(new Ext.menu.Item({
                text: t('search'),
                iconCls: "pimcore_icon_search",
                handler: function (item) {
                    item.parentMenu.destroy();
                    this.openSearchEditor();
                }.bind(this)
            }));
        }

        // add upload button when assets are allowed
        if (this.fieldConfig.assetsAllowed) {
            menu.add(new Ext.menu.Item({
                text: t('upload'),
                cls: "pimcore_inline_upload",
                iconCls: "pimcore_icon_upload",
                handler: function (item) {
                    item.parentMenu.destroy();
                    this.uploadDialog();
                }.bind(this)
            }));
        }

        menu.showAt(e.getXY());

        e.stopEvent();
    },

    openSearchEditor: function () {
        const allowedTypes = [];
        const allowedSpecific = {};
        const allowedSubtypes = {};
        let i;

        if (this.fieldConfig.objectsAllowed) {
            allowedTypes.push("object");
            allowedSubtypes.object = [];
            if (this.fieldConfig.classes != null && this.fieldConfig.classes.length > 0) {
                allowedSpecific.classes = [];
                allowedSubtypes.object.push("object", "variant");
                for (i = 0; i < this.fieldConfig.classes.length; i++) {
                    allowedSpecific.classes.push(this.fieldConfig.classes[i].classes);

                }
            }
            if(this.dataObjectFolderAllowed) {
                allowedSubtypes.object.push("folder");
            }

            if(allowedSubtypes.length == 0) {
                allowedSubtypes.object = ["object", "folder", "variant"];
            }
        }
        if (this.fieldConfig.assetsAllowed) {
            allowedTypes.push("asset");
            if (this.fieldConfig.assetTypes != null && this.fieldConfig.assetTypes.length > 0) {
                allowedSubtypes.asset = [];
                for (i = 0; i < this.fieldConfig.assetTypes.length; i++) {
                    allowedSubtypes.asset.push(this.fieldConfig.assetTypes[i].assetTypes);
                }
            }
        }
        if (this.fieldConfig.documentsAllowed) {
            allowedTypes.push("document");
            if (this.fieldConfig.documentTypes != null && this.fieldConfig.documentTypes.length > 0) {
                allowedSubtypes.document = [];
                for (i = 0; i < this.fieldConfig.documentTypes.length; i++) {
                    allowedSubtypes.document.push(this.fieldConfig.documentTypes[i].documentTypes);
                }
            }
        }

        pimcore.helpers.itemselector(false, this.addDataFromSelector.bind(this), {
            type: allowedTypes,
            subtype: allowedSubtypes,
            specific: allowedSpecific
        }, {
            context: Ext.apply({scope: "objectEditor"}, this.getContext())
        });
    },

    addDataFromSelector: function (data) {
        this.data.id = data.id;
        this.data.type = data.type;
        this.data.subtype = data.subtype;
        this.data.path = data.fullpath;
        this.dataChanged = true;
        this.component.removeCls("strikeThrough");
        if (data.published === false) {
            this.component.addCls("strikeThrough");
        }

        if (this.fieldConfig.displayMode == 'combo') {
            this.component.setValue(data.id);
        } else {
            this.component.setValue(data.fullpath);
        }

        this.requestNicePathData();
    },

    openElement: function () {
        if (this.data.id && this.data.type) {
            pimcore.helpers.openElement(this.data.id, this.data.type, this.data.subtype);
        }
    },

    downloadAsset: function () {
        if (this.data.id && this.data.type && this.data.type === "asset") {
            if (this.data.subtype === "folder") {
                pimcore.elementservice.downloadAssetFolderAsZip(this.data.id)
            } else {
                pimcore.helpers.download(Routing.generate('pimcore_admin_asset_download', {id: this.data.id}));
            }
        }
    },

    empty: function () {
        this.data = {};
        this.dataChanged = true;
        this.component.setValue("");
    },

    getValue: function () {
        return this.data;
    },

    getName: function () {
        return this.fieldConfig.name;
    },

    dndAllowed: function (data) {

        const elementType = data.elementType;
        let i;
        let subType;
        let isAllowed = false;
        if (elementType == "object" && this.fieldConfig.objectsAllowed) {

            if (data.type == 'folder') {
                if (this.dataObjectFolderAllowed || this.fieldConfig.classes.length <= 0) {
                    isAllowed = true;
                }
            } else {
                const classname = data.className;

                if (this.fieldConfig.classes != null && this.fieldConfig.classes.length > 0) {
                    for (i = 0; i < this.fieldConfig.classes.length; i++) {
                        if (this.fieldConfig.classes[i].classes == classname) {
                            isAllowed = true;
                            break;
                        }
                    }
                } else if (!this.dataObjectFolderAllowed) {
                    isAllowed = true;
                }
            }
        } else if (elementType == "asset" && this.fieldConfig.assetsAllowed) {
            subType = data.type;
            if (this.fieldConfig.assetTypes != null && this.fieldConfig.assetTypes.length > 0) {
                for (i = 0; i < this.fieldConfig.assetTypes.length; i++) {
                    if (this.fieldConfig.assetTypes[i].assetTypes == subType) {
                        isAllowed = true;
                        break;
                    }
                }
            } else {
                //no asset types configured - allow all
                isAllowed = true;
            }

        } else if (elementType == "document" && this.fieldConfig.documentsAllowed) {
            subType = data.type;
            if (this.fieldConfig.documentTypes != null && this.fieldConfig.documentTypes.length > 0) {
                for (i = 0; i < this.fieldConfig.documentTypes.length; i++) {
                    if (this.fieldConfig.documentTypes[i].documentTypes == subType) {
                        isAllowed = true;
                        break;
                    }
                }
            } else {
                //no document types configured - allow all
                isAllowed = true;
            }
        }
        return isAllowed;
    },

    requestNicePathData: function () {
        if (!this.object) {
            return;
        }

        let targets, responseHandler;
        if (pimcore.helpers.hasSearchImplementation() && this.fieldConfig.displayMode === 'combo' && !this.fieldConfig.noteditable) {
            targets = this.store.data;
            responseHandler = function (responseData) {
                this.component.removeCls('grid_nicepath_requested');

                this.store.ignoreDataChanged = true;
                this.store.each(function (record, id) {
                    const recordId = record.data.nicePathKey;
                    if (typeof responseData[recordId] !== 'undefined') {
                        record.set('label', responseData[recordId], {dirty: false});
                    }
                });
                this.store.ignoreDataChanged = false;
            }.bind(this);
        } else {
            if (!this.data.id) {
                return;
            }
            targets = new Ext.util.Collection();
            const target = Ext.clone(this.data)
            target.nicePathKey = target.type + '_' + target.id;
            const targetRecord = {
                id: 0,
                data: target
            };
            targets.add(targetRecord);
            responseHandler = function (target, responseData) {
                this.component.removeCls('grid_nicepath_requested');

                if (typeof responseData[target['nicePathKey']] !== 'undefined') {
                    this.component.setValue(responseData[target['nicePathKey']]);
                }
            }.bind(this, target);
        }

        pimcore.helpers.requestNicePathData(
            {
                type: 'object',
                id: this.object.id
            },
            targets,
            {
                idProperty: 'nicePathKey'
            },
            this.fieldConfig,
            this.getContext(),
            function () {
                this.component.addCls('grid_nicepath_requested');
            }.bind(this),
            responseHandler
        );
    },

    getCellEditValue: function () {
        return this.getValue();
    },

    isDirty:function () {
        if (this.component) {
            if (!this.component.rendered) {
                return false;
            } else {
                return this.dataChanged;
            }
        }
    }
});
