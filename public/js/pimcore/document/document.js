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

pimcore.registerNS("pimcore.document.document");
/**
 * @private
 */
pimcore.document.document = Class.create(pimcore.element.abstract, {
    willClose: false,

    getData: function () {
        var options = this.options || {};
        Ext.Ajax.request({
            url: Routing.generate(this.getDataRoute()),
            params: {id: this.id} ,
            ignoreErrors: options.ignoreNotFoundError,
            success: this.getDataComplete.bind(this),
            failure: function () {
                pimcore.helpers.forgetOpenTab("document_" + this.id + "_" + this.type);
                pimcore.helpers.closeDocument(this.id);
            }.bind(this)
        });
    },

    getDataComplete: function (response) {
        try {
            this.data = Ext.decode(response.responseText);

            if (typeof this.data.editlock == "object") {
                pimcore.helpers.lockManager(this.id, "document", this.getType(), this.data);
                throw "document is locked";
            }

            if (this.isAllowed("view")) {
                this.init();
                this.addTab();

                if (this.getAddToHistory()) {
                    pimcore.helpers.recordElement(this.id, "document", this.data.path + this.data.key);
                }

                //update published state in trees
                pimcore.elementservice.setElementPublishedState({
                    elementType: "document",
                    id: this.id,
                    published: this.data.published
                });

                this.startChangeDetector();
            } else {
                pimcore.helpers.closeDocument(this.id);
            }
        } catch (e) {
            console.log(e);
            pimcore.helpers.closeDocument(this.id);
        }

    },

    selectInTree: function () {
        try {
            pimcore.treenodelocator.showInTree(this.id, "document");
        } catch (e) {
            console.log(e);
        }
    },

    activate: function () {
        var tabId = "document_" + this.id;
        var tabPanel = Ext.getCmp("pimcore_panel_tabs");
        tabPanel.setActiveItem(tabId);
    },

    save: function (task, only, callback, successCallback) {

        if (this.tab.disabled || (this.tab.isMasked() && task != 'autoSave')) {
            return;
        }

        if (this.saveInProgress()){
            pimcore.helpers.showNotification(t("warning"), t("Another saving process is in progress, please wait and retry again"), "info");
            return;
        }

        if(typeof task !== 'string') {
            task = '';
        }

        if(task != 'autoSave'){
            this.tab.mask();
        }

        var saveData = this.getSaveData(only);

        if (saveData) {
            if (this.data.missingRequiredEditable !== null) {
                saveData.missingRequiredEditable = this.data.missingRequiredEditable;
            }

            const preSaveDocument = new CustomEvent(pimcore.events.preSaveDocument, {
                detail: {
                    document: this,
                    type: this.getType(),
                    task: task,
                    onlySaveVersion: only
                },
                cancelable: true
            });

            this.saving = true;

            const isAllowed = document.dispatchEvent(preSaveDocument);
            if (!isAllowed) {
                this.tab.unmask();
                this.saving = false;
                return false;
            }

            Ext.Ajax.request({
                url: Routing.generate(this.getSaveRoute(), {'task': task}),
                method: "PUT",
                params: saveData,
                success: function (response) {
                    try {
                        var rdata = Ext.decode(response.responseText);
                        if (typeof successCallback == 'function') {
                            // the successCallback function retrieves response data information
                            successCallback(rdata);
                        }
                        if (rdata && rdata.success) {
                            // check for version notification
                            if (this.draftVersionNotification) {
                                if (task == "publish" || task == "unpublish") {
                                    this.draftVersionNotification.hide();
                                } else if (task === 'version' || task === 'autoSave') {
                                    this.draftVersionNotification.show();
                                }
                            }

                            if(task !== "autoSave") {
                                pimcore.helpers.showNotification(t("success"), t("saved_successfully"), "success");
                            }

                            this.resetChanges(task);
                            Ext.apply(this.data, rdata.data);

                            if(rdata['draft']) {
                                this.data['draft'] = rdata['draft'];
                            }

                            const postSaveDocument = new CustomEvent(pimcore.events.postSaveDocument, {
                                detail: {
                                    document: this,
                                    type: this.getType(),
                                    task: task,
                                    onlySaveVersion: only
                                }
                            });

                            document.dispatchEvent(postSaveDocument);

                            pimcore.helpers.updateTreeElementStyle('document', this.id, rdata.treeData);
                        }
                    } catch (e) {
                        pimcore.helpers.showNotification(t("error"), t("saving_failed"), "error");
                    }

                    // reload versions
                    if (task !== 'autoSave' && this.versions) {
                        if (typeof this.versions.reload == "function") {
                            this.versions.reload();
                        }
                    }

                    this.tab.unmask();

                    if (typeof callback == "function") {
                        callback();
                    }

                    if (this.willClose){
                        this.close();
                    }

                }.bind(this),
                failure: function () {
                    this.tab.unmask();
                }.bind(this),
                callback: function (){
                    this.saving = false;
                }.bind(this),
            });
        } else {
            this.tab.unmask();
        }
    },

    isAllowed: function (key) {
        return this.data.userPermissions[key];
    },

    remove: function () {
        var options = {
            "elementType": "document",
            "id": this.id
        };
        pimcore.elementservice.deleteElement(options);
    },

    close: function() {
        pimcore.helpers.closeDocument(this.id);
    },

    saveClose: function (only) {
        this.willClose = true;
        this.save('version', only);
    },

    publishClose: function () {
        this.willClose = true;
        this.publish(null);
    },

    publish: function (only, callback) {
        this.save("publish", only, callback, function (rdata) {
            if (rdata && rdata.success) {
                this.data.published = true;

                // toggle buttons
                if(this.toolbarButtons.unpublish) {
                    this.toolbarButtons.unpublish.show();
                }

                if (this.toolbarButtons.save) {
                    this.toolbarButtons.save.hide();
                }

                if (this.toolbarButtons.save && this.toolbarButtons.publish) {
                    if (this.isAllowed("save")) {
                        const menuItem = this.toolbarButtons.publish.menu.items.items.find(
                            element => element.text === t('save_draft')
                        )
                        menuItem.setHidden(false)
                    }
                    if (this.isAllowed("settings")) {
                        const menuItem = this.toolbarButtons.publish.menu.items.items.find(
                            element => element.text === t('save_only_scheduled_tasks')
                        )
                        menuItem.setHidden(false)
                    }

                    this.toolbarButtons.publish.show();
                }

                pimcore.elementservice.setElementPublishedState({
                    elementType: "document",
                    id: this.id,
                    published: true
                });
            }
        }.bind(this));
    },

    unpublish: function (only, callback) {
        this.save("unpublish", only, callback, function (rdata) {
            if (rdata && rdata.success) {
                this.data.published = false;

                // toggle buttons
                if(this.toolbarButtons.unpublish) {
                    this.toolbarButtons.unpublish.hide();
                }

                if (this.toolbarButtons.save) {
                    this.toolbarButtons.save.show();
                }

                if (this.toolbarButtons.publish && this.toolbarButtons.save) {
                    this.toolbarButtons.publish.hide();
                }

                pimcore.elementservice.setElementPublishedState({
                    elementType: "document",
                    id: this.id,
                    published: false
                });
            }
        }.bind(this));
    },

    unpublishClose: function () {
        this.willClose = true;
        this.unpublish(null);
    },

    reload: function () {
        this.tab.on("close", function () {
            var currentTabIndex = this.tab.ownerCt.items.indexOf(this.tab);
            window.setTimeout(function (id, type) {
                pimcore.helpers.openDocument(id, type, {tabIndex: currentTabIndex});
            }.bind(window, this.id, this.getType()), 500);
        }.bind(this));

        pimcore.helpers.closeDocument(this.id);
    },

    setType: function (type) {
        this.type = type;
    },

    getType: function () {
        return this.type;
    },

    linkTranslation: function () {

        var win = null;

        var checkLanguage = function (el) {

            Ext.Ajax.request({
                url: Routing.generate('pimcore_admin_document_document_translationchecklanguage'),
                params: {
                    path: el.getValue()
                },
                success: function (response) {
                    var data = Ext.decode(response.responseText);
                    if (data["success"]) {
                        win.getComponent("language").setValue(pimcore.available_languages[data["language"]] + " [" + data["language"] + "]");
                        win.getComponent("language").show();
                        win.getComponent("info").hide();
                    } else {
                        win.getComponent("language").setValue("").hide();
                        win.getComponent("info").show();
                    }
                }
            });
        };

        win = new Ext.Window({
            width: 600,
            bodyStyle: "padding:10px",
            items: [{
                xtype: "textfield",
                name: "translation",
                itemId: "translation",
                width: "100%",
                fieldCls: "input_drop_target",
                fieldLabel: t("translation"),
                enableKeyListeners: true,
                listeners: {
                    "render": function (el) {
                        new Ext.dd.DropZone(el.getEl(), {
                            reference: this,
                            ddGroup: "element",
                            getTargetFromEvent: function (e) {
                                return this.getEl();
                            }.bind(el),

                            onNodeOver: function (target, dd, e, data) {
                                if (data.records.length === 1 && data.records[0].data.elementType === "document") {
                                    return Ext.dd.DropZone.prototype.dropAllowed;
                                }
                            },

                            onNodeDrop: function (target, dd, e, data) {

                                if (!pimcore.helpers.dragAndDropValidateSingleItem(data)) {
                                    return false;
                                }

                                data = data.records[0].data;
                                if (data.elementType === "document") {
                                    this.setValue(data.path);
                                    return true;
                                }
                                return false;
                            }.bind(el)
                        });
                    },
                    "change": checkLanguage,
                    "keyup": checkLanguage
                }
            }, {
                xtype: "displayfield",
                name: "language",
                itemId: "language",
                value: "",
                hidden: true,
                fieldLabel: t("language")
            }, {
                xtype: "displayfield",
                name: "language",
                itemId: "info",
                fieldLabel: t("info"),
                value: t("target_document_needs_language")
            }],
            buttons: [{
                text: t("cancel"),
                iconCls: "pimcore_icon_cancel",
                handler: function () {
                    win.close();
                }
            }, {
                text: t("apply"),
                iconCls: "pimcore_icon_apply",
                handler: function () {
                    if (!win.getComponent("translation").getValue() || !win.getComponent("language").getValue()) {
                        Ext.MessageBox.alert(t("error"), t("target_document_invalid"));
                        return false;
                    }

                    Ext.Ajax.request({
                        url: Routing.generate('pimcore_admin_document_document_translationadd'),
                        method: 'POST',
                        params: {
                            sourceId: this.id,
                            targetPath: win.getComponent("translation").getValue()
                        },
                        success: function (response) {
                            this.reload();
                        }.bind(this)
                    });

                    win.close();
                }.bind(this)
            }]
        });

        win.show();
    },

    showDocumentOverview: function () {

        new pimcore.document.document_language_overview(this);
    },

    createTranslation: function (inheritance) {

        var languagestore = [];
        var websiteLanguages = pimcore.settings.websiteLanguages;
        var selectContent = "";
        for (var i = 0; i < websiteLanguages.length; i++) {
            if (this.data.properties["language"]["data"] != websiteLanguages[i]) {
                selectContent = pimcore.available_languages[websiteLanguages[i]] + " [" + websiteLanguages[i] + "]";
                languagestore.push([websiteLanguages[i], selectContent]);
            }
        }

        var pageForm = new Ext.form.FormPanel({
            border: false,
            defaults: {
                labelWidth: 170
            },
            items: [{
                xtype: "combo",
                name: "language",
                store: languagestore,
                editable: false,
                triggerAction: 'all',
                mode: "local",
                fieldLabel: t('language'),
                listeners: {
                    select: function (el) {
                        pageForm.getComponent("parent").disable();
                        Ext.Ajax.request({
                            url: Routing.generate('pimcore_admin_document_document_translationdetermineparent'),
                            params: {
                                language: el.getValue(),
                                id: this.id
                            },
                            success: function (response) {
                                var data = Ext.decode(response.responseText);
                                if (data["success"]) {
                                    pageForm.getComponent("parent").setValue(data["targetPath"]);
                                }
                                pageForm.getComponent("parent").enable();
                            }
                        });
                    }.bind(this)
                }
            }, {
                xtype: "textfield",
                name: "parent",
                itemId: "parent",
                width: "100%",
                fieldCls: "input_drop_target",
                fieldLabel: t("parent"),
                listeners: {
                    "render": function (el) {
                        new Ext.dd.DropZone(el.getEl(), {
                            reference: this,
                            ddGroup: "element",
                            getTargetFromEvent: function (e) {
                                return this.getEl();
                            }.bind(el),

                            onNodeOver: function (target, dd, e, data) {
                                if (data.records.length === 1 && data.records[0].data.elementType === "document") {
                                    return Ext.dd.DropZone.prototype.dropAllowed;
                                }
                            },

                            onNodeDrop: function (target, dd, e, data) {

                                if (!pimcore.helpers.dragAndDropValidateSingleItem(data)) {
                                    return false;
                                }

                                data = data.records[0].data;
                                if (data.elementType === "document") {
                                    this.setValue(data.path);
                                    return true;
                                }
                                return false;
                            }.bind(el)
                        });
                    }
                }
            }, {
                xtype: "textfield",
                itemId: "title",
                fieldLabel: t('title'),
                name: 'title',
                width: "100%",
                enableKeyEvents: true,
                listeners: {
                    keyup: function (el) {
                        pageForm.getComponent("name").setValue(el.getValue());
                        pageForm.getComponent("key").setValue(el.getValue());
                    }
                }
            }, {
                xtype: "textfield",
                itemId: "name",
                fieldLabel: t('navigation'),
                name: 'name',
                width: "100%"
            }, {
                xtype: "textfield",
                width: "100%",
                fieldLabel: t('key'),
                itemId: "key",
                name: 'key'
            }]
        });

        var win = new Ext.Window({
            width: 600,
            bodyStyle: "padding:10px",
            items: [pageForm],
            buttons: [{
                text: t("cancel"),
                iconCls: "pimcore_icon_cancel",
                handler: function () {
                    win.close();
                }
            }, {
                text: t("apply"),
                iconCls: "pimcore_icon_apply",
                handler: function () {

                    var params = pageForm.getForm().getFieldValues();
                    win.disable();

                    Ext.Ajax.request({
                        url: Routing.generate('pimcore_admin_element_getsubtype'),
                        params: {
                            id: pageForm.getComponent("parent").getValue(),
                            type: "document"
                        },
                        success: function (response) {
                            var res = Ext.decode(response.responseText);
                            if (res.success) {
                                if (params["key"].length >= 1) {
                                    params["parentId"] = res["id"];
                                    params["type"] = this.getType();
                                    params["translationsBaseDocument"] = this.id;
                                    if (inheritance) {
                                        params["inheritanceSource"] = this.id;
                                    }

                                    Ext.Ajax.request({
                                        url: Routing.generate(this.getAddRoute()),
                                        method: 'POST',
                                        params: params,
                                        success: function (response) {
                                            response = Ext.decode(response.responseText);
                                            if (response && response.success) {
                                                pimcore.helpers.openDocument(response.id, response.type);
                                            }
                                        }
                                    });
                                }
                            } else {
                                Ext.MessageBox.alert(t("error"), t("element_not_found"));
                            }

                            win.close();
                        }.bind(this)
                    });
                }.bind(this)
            }]
        });

        win.show();
    },

    getTranslationButtons: function (asMenuItem = false) {

        var translationsMenu = [];
        var unlinkTranslationsMenu = [];
        if (this.data["translations"]) {
            var me = this;
            Ext.iterate(this.data["translations"], function (language, documentId, myself) {
                translationsMenu.push({
                    text: pimcore.available_languages[language] + " [" + language + "]",
                    iconCls: "pimcore_icon_language_" + language.toLowerCase(),
                    handler: function () {
                        pimcore.helpers.openElement(documentId, "document");
                    }
                });
            });

            if (Object.keys(me.data["translations"]).length) {
                //add menu for All Translations
                translationsMenu.push({
                    text: t("all_translations"),
                    iconCls: "pimcore_icon_translations",
                    handler: function () {
                        Ext.iterate(me.data["translations"], function (language, documentId) {
                            pimcore.helpers.openElement(documentId, "document");
                        });
                    }
                });
            }
        }

        if (this.data["unlinkTranslations"]) {
            var me = this;
            Ext.iterate(this.data["unlinkTranslations"], function (language, documentId, myself) {
                unlinkTranslationsMenu.push({
                    text: pimcore.available_languages[language] + " [" + language + "]",
                    handler: function () {
                        Ext.Ajax.request({
                            url: Routing.generate('pimcore_admin_document_document_translationremove'),
                            method: 'DELETE',
                            params: {
                                sourceId: me.id,
                                targetId: documentId
                            },
                            success: function (response) {
                                me.reload();
                            }.bind(this)
                        });
                    }.bind(this),
                    iconCls: "pimcore_icon_language_" + language.toLowerCase()
                });
            });
        }

        return {
            ...(() => asMenuItem ? { text: t("translation") } : { tooltip: t("translation") })(),
            iconCls: "pimcore_material_icon_translation pimcore_material_icon",
            ...(() => asMenuItem ? {} : { scale: "medium" })(),
            menu: [{
                text: t("new_document"),
                hidden: !pimcore.helpers.documentTypeHasSpecificRole(this.getType(), "translatable"),
                iconCls: "pimcore_icon_page pimcore_icon_overlay_add",
                menu: [{
                    text: t("using_inheritance"),
                    hidden: !pimcore.helpers.documentTypeHasSpecificRole(this.getType(), "translatable_inheritance"),
                    handler: this.createTranslation.bind(this, true),
                    iconCls: "pimcore_icon_clone"
                }, {
                    text: "&gt; " + t("blank"),
                    handler: this.createTranslation.bind(this, false),
                    iconCls: "pimcore_icon_file_plain"
                }]
            }, {
                text: t("link_existing_document"),
                handler: this.linkTranslation.bind(this),
                iconCls: "pimcore_icon_page pimcore_icon_overlay_reading"
            }, {
                text: t("open_translation"),
                menu: translationsMenu,
                hidden: !translationsMenu.length,
                iconCls: "pimcore_icon_open"
            }, {
                text: t("unlink_existing_document"),
                menu: unlinkTranslationsMenu,
                hidden: !unlinkTranslationsMenu.length,
                iconCls: "pimcore_icon_delete"
            }, {
                text: t("document_language_overview"),
                handler: this.showDocumentOverview.bind(this),
                iconCls: "pimcore_icon_page"
            }]
        };
    },

    resetPath: function () {
        Ext.Ajax.request({
            url: Routing.generate('pimcore_admin_document_document_getdatabyid'),
            params: {id: this.id},
            success: function (response) {
                var rdata = Ext.decode(response.responseText);
                this.data.path = rdata.path;
                this.data.key = rdata.key;
            }.bind(this)
        });
    },

    getDataRoute: function() {
        return "pimcore_admin_document_" + this.type + '_getdatabyid';
    },

    getSaveRoute: function() {
        return "pimcore_admin_document_" + this.type + '_save';
    },

    getAddRoute: function() {
        return "pimcore_admin_document_document_add";
    }
});
