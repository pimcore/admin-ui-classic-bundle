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

pimcore.registerNS("pimcore.object.object");
/**
 * @private
 */
pimcore.object.object = Class.create(pimcore.object.abstract, {
    frontendLanguages: null,
    willClose: false,
    initialize: function (id, options) {
        this.id = intval(id);
        this.options = options;
        this.addLoadingPanel();

        const preOpenObject = new CustomEvent(pimcore.events.preOpenObject, {
            detail: {
                object: this,
                type: "object"
            },
            cancelable: true
        });

        const isAllowed = document.dispatchEvent(preOpenObject);
        if (!isAllowed) {
            this.removeLoadingPanel();
            return;
        }


        var user = pimcore.globalmanager.get("user");

        //TODO why do we create all this stuff and decide whether we want to display it or not ????????????????
        this.edit = new pimcore.object.edit(this);
        this.preview = new pimcore.object.preview(this);
        this.properties = new pimcore.element.properties(this, "object");
        this.versions = new pimcore.object.versions(this);
        this.scheduler = new pimcore.element.scheduler(this, "object");
        this.dependencies = new pimcore.element.dependencies(this, "object");
        this.workflows = new pimcore.element.workflows(this, "object");

        if (user.isAllowed("notes_events")) {
            this.notes = new pimcore.element.notes(this, "object");
        }

        if(pimcore.globalmanager.get('customReportsPanelImplementationFactory').hasImplementation()) {
            this.reports = pimcore.globalmanager.get('customReportsPanelImplementationFactory').getNewReportInstance("object_concrete");
        }
        this.variants = new pimcore.object.variantsTab(this);
        if(pimcore.globalmanager.get('applicationLoggerPanelImplementationFactory').hasImplementation()) {
            this.appLogger = pimcore.globalmanager.get('applicationLoggerPanelImplementationFactory').getNewLoggerInstance({localMode: true,
                searchParams: {
                    relatedobject: this.id
                }
            });
        }
        this.tagAssignment = new pimcore.element.tag.assignment(this, "object");
        this.frontendLanguages = pimcore.settings.websiteLanguages;

        this.getData();
    },

    getData: function () {
        var params = {id: this.id};
        if (this.options !== undefined && this.options.layoutId !== undefined) {
            params.layoutId = this.options.layoutId;
        }

        var options = this.options || {};

        Ext.Ajax.request({
            url: Routing.generate('pimcore_admin_dataobject_dataobject_get'),
            params: params,
            ignoreErrors: options.ignoreNotFoundError,
            success: this.getDataComplete.bind(this),
            failure: function () {
                this.forgetOpenTab();
            }.bind(this)
        });
    },

    getDataComplete: function (response) {
        try {
            this.data = Ext.decode(response.responseText);

            if (typeof this.data.editlock == "object") {
                pimcore.helpers.lockManager(this.id, "object", "object", this.data);
                throw "object is locked";
            }

            this.addTab();

            this.startChangeDetector();
            this.setupInheritanceDetector();

            //update published state in trees
            pimcore.elementservice.setElementPublishedState({
                elementType: "object",
                id: this.id,
                published: this.data.general.published
            });

        }
        catch (e) {
            console.log(e);

            this.forgetOpenTab();

            if (this.toolbar) {
                this.toolbar.destroy();
            }
            if (pimcore.globalmanager.get('global_language_' + this.id)) {
                pimcore.globalmanager.remove('global_language_' + this.id);
            }
            pimcore.helpers.closeObject(this.id);
        }
    },

    inheritedFields: {},
    setupInheritanceDetector: function () {
        this.tab.on("deactivate", this.stopInheritanceDetector.bind(this));
        this.tab.on("activate", this.startInheritanceDetector.bind(this));
        this.tab.on("destroy", this.stopInheritanceDetector.bind(this));
        this.startInheritanceDetector();
    },

    startInheritanceDetector: function () {
        if(this.data.metaData) {
            var dataKeys = Object.keys(this.data.metaData);
            for (var i = 0; i < dataKeys.length; i++) {
                if (this.data.metaData[dataKeys[i]].inherited == true) {
                    this.inheritedFields[dataKeys[i]] = true;
                }
            }
        }

        if (!this.inheritanceDetectorInterval) {
            this.inheritanceDetectorInterval = window.setInterval(this.checkForInheritance.bind(this), 1000);
        }
    },

    stopInheritanceDetector: function () {
        window.clearInterval(this.inheritanceDetectorInterval);
        this.inheritanceDetectorInterval = null;
    },

    checkForInheritance: function () {

        // do not run when tab is not active
        if(document.hidden) {
            return;
        }

        if (!this.edit.layout.rendered) {
            throw "edit not available";
        }


        var dataKeys = Object.keys(this.inheritedFields);
        var currentField;

        if (dataKeys.length == 0) {
            this.stopInheritanceDetector();
        }

        for (var i = 0; i < dataKeys.length; i++) {
            var field = dataKeys[i];
            if (this.data.metaData && this.data.metaData[field] && this.data.metaData[field].inherited == true) {
                if (this.edit.dataFields[field] && typeof this.edit.dataFields[field] == "object") {
                    currentField = this.edit.dataFields[field];

                    if (currentField.dataIsNotInherited()) {
                        currentField.unmarkInherited();
                        this.data.metaData[field].inherited = false;
                        delete this.inheritedFields[field];
                    }
                }
            }

        }
    },


    addTab: function () {

        if (this.data.general["iconCls"]) {
            iconClass = this.data.general["iconCls"];
        } else if (this.data.general["icon"]) {
            iconClass = pimcore.helpers.getClassForIcon(this.data.general["icon"]);
        }

        this.tabPanel = Ext.getCmp("pimcore_panel_tabs");
        var tabId = "object_" + this.id;
        this.tab = new Ext.Panel({
            id: tabId,
            title: htmlspecialchars(this.data.general.key),
            closable: true,
            layout: "border",
            items: [this.getLayoutToolbar(), this.getTabPanel()],
            object: this,
            cls: "pimcore_class_" + this.data.general.className,
            iconCls: iconClass
        });

        this.tab.on("activate", function () {
            this.tab.updateLayout();
            pimcore.layout.refresh();
        }.bind(this));

        this.tab.on("beforedestroy", function () {
            Ext.Ajax.request({
                url: Routing.generate('pimcore_admin_element_unlockelement'),
                method: 'PUT',
                params: {
                    id: this.id,
                    type: "object"
                }
            });
        }.bind(this));

        // remove this instance when the panel is closed
        this.tab.on("destroy", function () {
            this.forgetOpenTab();
        }.bind(this));

        this.tab.on("afterrender", function (tabId) {
            this.tabPanel.setActiveItem(tabId);
            const postOpenObject = new CustomEvent(pimcore.events.postOpenObject, {
                detail: {
                    object: this,
                    type: "object"
                }
            });

            document.dispatchEvent(postOpenObject);

            if(this.options && this.options['uiState']) {
                this.setUiState(this.tabbar, this.options['uiState']);
            }
        }.bind(this, tabId));

        this.removeLoadingPanel();

        this.addToMainTabPanel();

        if (this.getAddToHistory()) {
            pimcore.helpers.recordElement(this.id, "object", this.data.general.fullpath);
        }

        // recalculate the layout
        pimcore.layout.refresh();
    },

    forgetOpenTab: function () {
        pimcore.globalmanager.remove("object_" + this.id);
        if (pimcore.globalmanager.get('global_language_' + this.id)) {
            pimcore.globalmanager.remove('global_language_' + this.id);
        }
        pimcore.helpers.forgetOpenTab("object_" + this.id + "_object");
        pimcore.helpers.forgetOpenTab("object_" + this.id + "_variant");

    },

    getTabPanel: function () {

        var items = [];
        var user = pimcore.globalmanager.get("user");

        //try {
        items.push(this.edit.getLayout(this.data.layout));
        //} catch (e) {
        //    console.log(e);
        //}

        if (this.data.hasPreview) {
            try {
                items.push(this.preview.getLayout());
            } catch (e) {

            }
        }

        if (this.isAllowed("properties")) {
            try {
                items.push(this.properties.getLayout());
            } catch (e) {

            }
        }
        try {
            if (this.isAllowed("versions")) {
                items.push(this.versions.getLayout());
            }
        } catch (e) {

        }

        if (this.isAllowed("settings")) {
            try {
                items.push(this.scheduler.getLayout());
            } catch (e) {
                console.log(e);

            }
        }

        try {
            items.push(this.dependencies.getLayout());
        } catch (e) {

        }

        try {
            if(this.reports) {
                var reportLayout = this.reports.getLayout();
                if (reportLayout) {
                    items.push(reportLayout);
                }
            }
        } catch (e) {
            console.log(e);

        }

        if (user.isAllowed("notes_events")) {
            items.push(this.notes.getLayout());
        }

        if (user.isAllowed("tags_assignment")) {
            items.push(this.tagAssignment.getLayout());
        }

        if (user.isAllowed("workflow_details") && this.data.workflowManagement && this.data.workflowManagement.hasWorkflowManagement === true) {
            items.push(this.workflows.getLayout());
        }

        //
        if (this.data.childdata.data.classes.length > 0) {
            try {
                this.search = new pimcore.object.search(this.data.childdata, "children");
                this.search.title = t('children_grid');
                this.search.onlyDirectChildren = true;
                items.push(this.search.getLayout());
            } catch (e) {

            }
        }

        if (this.data.general.allowVariants) {
            try {
                items.push(this.variants.getLayout());
            } catch (e) {
                console.log(e);
            }
        }

        if (this.appLogger && user.isAllowed("application_logging") && this.data.general.showAppLoggerTab) {
            try {
                var appLoggerTab = this.appLogger.getTabPanel();
                items.push(appLoggerTab);
            } catch (e) {
                console.log(e);
            }
        }

        this.tabbar = pimcore.helpers.getTabBar({items: items});
        return this.tabbar;
    },

    getLayoutToolbar: function () {

        if (!this.toolbar) {

            var buttons = [];

            this.toolbarButtons = {};


            this.toolbarButtons.save = new Ext.SplitButton({
                text: t('save'),
                iconCls: "pimcore_icon_save_white",
                cls: "pimcore_save_button",
                scale: "medium",
                handler: this.save.bind(this, "version"),
                menu: [
                    {
                        text: t('save_close'),
                        iconCls: "pimcore_icon_save",
                        handler: this.saveClose.bind(this)
                    },
                    {
                        text: t('save_only_scheduled_tasks'),
                        iconCls: "pimcore_icon_save",
                        handler: this.save.bind(this, "scheduler", "scheduler"),
                        hidden: !this.isAllowed("settings") || this.data.general.published
                    }
                ]
            });


            this.toolbarButtons.publish = new Ext.SplitButton({
                text: t('save_and_publish'),
                iconCls: "pimcore_icon_save_white",
                cls: "pimcore_save_button",
                scale: "medium",
                handler: this.publish.bind(this),
                menu: [{
                    text: t('save_pubish_close'),
                    iconCls: "pimcore_icon_save",
                    handler: this.publishClose.bind(this)
                },
                    {
                        text: t('save_draft'),
                        iconCls: "pimcore_icon_save",
                        handler: this.save.bind(this, "version"),
                        hidden: !this.isAllowed("save") || !this.data.general.published
                    },
                    {
                        text: t('save_only_scheduled_tasks'),
                        iconCls: "pimcore_icon_save",
                        handler: this.save.bind(this, "scheduler", "scheduler"),
                        hidden: !this.isAllowed("settings") || !this.data.general.published
                    }
                ]
            });

            this.toolbarButtons.unpublish = new Ext.Button({
                text: t('unpublish'),
                iconCls: "pimcore_material_icon_unpublish pimcore_material_icon",
                scale: "medium",
                handler: this.unpublish.bind(this)
            });

            this.toolbarButtons.remove = new Ext.Button({
                tooltip: t("delete"),
                iconCls: "pimcore_material_icon_delete pimcore_material_icon",
                scale: "medium",
                handler: this.remove.bind(this)
            });

            this.toolbarButtons.rename = new Ext.Button({
                tooltip: t('rename'),
                iconCls: "pimcore_material_icon_rename pimcore_material_icon",
                scale: "medium",
                handler: this.rename.bind(this)
            });

            if (this.isAllowed("save")) {
                buttons.push(this.toolbarButtons.save);
            }
            if (this.isAllowed("publish")) {
                buttons.push(this.toolbarButtons.publish);
            }
            if (this.isAllowed("unpublish") && !this.data.general.locked) {
                buttons.push(this.toolbarButtons.unpublish);
            }

            buttons.push("-");

            if (this.isAllowed("delete") && !this.data.general.locked) {
                buttons.push(this.toolbarButtons.remove);
            }
            if (this.isAllowed("rename") && !this.data.general.locked) {
                buttons.push(this.toolbarButtons.rename);
            }

            var reloadConfig = {
                tooltip: t('reload'),
                iconCls: "pimcore_material_icon_reload pimcore_material_icon",
                scale: "medium",
                handler: this.reload.bind(this, {
                    layoutId: this.data.currentLayoutId
                })
            };

            if (this.data["validLayouts"] && this.data.validLayouts.length >= 1) {
                reloadConfig.xtype = "splitbutton";

                var menu = [];
                for (var i = 0; i < this.data.validLayouts.length; i++) {
                    var menuLabel = t(this.data.validLayouts[i].name);
                    if (this.data.currentLayoutId == this.data.validLayouts[i].id) {
                        menuLabel = "<b>" + menuLabel + "</b>";
                    }
                    menu.push({
                        text: menuLabel,
                        iconCls: "pimcore_icon_reload",
                        handler: this.reload.bind(this, {
                            layoutId: this.data.validLayouts[i].id
                        })
                    });
                }
                reloadConfig.menu = menu;
            } else {
                reloadConfig.xtype = "button";
            }

            buttons.push(reloadConfig);

            if (pimcore.elementservice.showLocateInTreeButton("object")) {
                if (this.data.general.type != "variant" || this.data.general.showVariants) {
                    buttons.push({
                        tooltip: t('show_in_tree'),
                        iconCls: "pimcore_material_icon_locate pimcore_material_icon",
                        scale: "medium",
                        handler: this.selectInTree.bind(this, this.data.general.type)
                    });
                }
            }

            buttons.push({
                xtype: "splitbutton",
                tooltip: t("show_metainfo"),
                iconCls: "pimcore_material_icon_info pimcore_material_icon",
                scale: "medium",
                handler: this.showMetaInfo.bind(this),
                menu: this.getMetaInfoMenuItems()
            });

            if (this.data.general.showFieldLookup) {
                buttons.push({
                    xtype: "button",
                    tooltip: t("fieldlookup"),
                    iconCls: "pimcore_material_fieldlookup pimcore_material_icon",
                    scale: "medium",
                    handler: function() {
                        var object = this.edit.object;
                        var config = {
                            classid: object.data.general.classId
                        }
                        var dialog = new pimcore.object.fieldlookup.filterdialog(config, null, object);
                        dialog.show();
                    }.bind(this)
                });
            }


            if (this.data.hasPreview) {
                buttons.push("-");
                buttons.push({
                    tooltip: t("open"),
                    iconCls: "pimcore_material_icon_preview pimcore_material_icon",
                    scale: "medium",
                    handler: function () {
                        var date = new Date();
                        var path = Routing.generate('pimcore_admin_dataobject_dataobject_preview', {id: this.data.general.id, time: date.getTime()});
                        this.saveToSession(function () {
                            window.open(path);
                        });
                    }.bind(this)
                });
            }

            if (pimcore.globalmanager.get("user").isAllowed('notifications_send')) {
                buttons.push({
                    tooltip: t('share_via_notifications'),
                    iconCls: "pimcore_icon_share",
                    scale: "medium",
                    handler: this.shareViaNotifications.bind(this)
                });
            }

            buttons.push("-");
            buttons.push({
                xtype: 'tbtext',
                text: t("id") + " " + this.data.general.id,
                scale: "medium"
            });

            buttons.push("-");
            buttons.push({
                xtype: 'tbtext',
                text: t(this.data.general.classTitle),
                scale: "medium"
            });

            this.draftVersionNotification = new Ext.Button({
                text: t('draft'),
                iconCls: "pimcore_icon_delete pimcore_material_icon",
                scale: "medium",
                hidden: true,
                handler: this.deleteDraft.bind(this)
            });

            buttons.push(this.draftVersionNotification);

            //workflow management
            pimcore.elementservice.integrateWorkflowManagement('object', this.id, this, buttons);

            if (this.data.draft && (this.data.draft.isAutoSave || this.isAllowed("save"))) {
                this.draftVersionNotification.show();
            }

            this.languageSwitcher = Ext.create('Ext.button.Split', {
                iconCls: "pimcore_icon_language_" + this.frontendLanguages[0].toLowerCase(),
                scale: "medium",
                menu: this.getLanguageMenuItems(),
                handler: function() {
                    if (pimcore.globalmanager.get('global_language_' + this.id)) {
                        this.toolbar.fireEvent(
                            pimcore.events.globalLanguageChanged,
                            pimcore.globalmanager.get('global_language_' + this.id)
                        );
                    }
                }.bind(this)
            });

            buttons.push(this.languageSwitcher);

            this.toolbar = new Ext.Toolbar({
                id: "object_toolbar_" + this.id,
                region: "north",
                border: false,
                cls: "pimcore_main_toolbar",
                items: buttons,
                overflowHandler: 'scroller'
            });

            if (!this.data.general.published) {
                this.toolbarButtons.unpublish.hide();
            } else if (this.isAllowed("publish")) {
                this.toolbarButtons.save.hide();
            }
        }
        this.edit.toolbar = this.toolbar;

        return this.toolbar;
    },

    activate: function () {
        var tabId = "object_" + this.id;
        var tabPanel = Ext.getCmp("pimcore_panel_tabs");
        tabPanel.setActiveItem(tabId);
    },

    getSaveData: function (only, omitMandatoryCheck) {
        var data = {};

        data.id = this.id;

        // get only scheduled tasks
        if (only == "scheduler") {
            try {
                data.scheduler = Ext.encode(this.scheduler.getValues());
                return data;
            }
            catch (e) {
                console.log("scheduler not available");
                return;
            }
        }

        // data
        try {
            data.data = Ext.encode(this.edit.getValues(omitMandatoryCheck));
        }
        catch (e1) {
            console.log(e1);
        }

        // properties
        try {
            data.properties = Ext.encode(this.properties.getValues());
        }
        catch (e2) {
            //console.log(e2);
        }

        try {
            data.general = Ext.apply({}, this.data.general);
            // object shouldn't be relocated, renamed, or anything else that is evil
            delete data.general["parentId"];
            delete data.general["type"];
            delete data.general["key"];
            delete data.general["locked"];
            delete data.general["classId"];
            delete data.general["modificationDate"];

            data.general = Ext.encode(data.general);
        }
        catch (e3) {
            console.log(e3);
        }

        // scheduler
        try {
            data.scheduler = Ext.encode(this.scheduler.getValues());
        }
        catch (e4) {
            //console.log(e4);
        }


        return data;
    },

    close: function() {
        pimcore.helpers.closeObject(this.id);
    },

    saveClose: function (only) {
        this.willClose = true;
        this.save(null, only);
    },

    publishClose: function () {
        this.willClose = true;
        this.publish(null)
    },

    publish: function (only, callback) {
        return this.save("publish", only, callback, function (rdata) {
            if (rdata && rdata.success) {
                //set the object as published only if in the response error doesn't exist
                this.data.general.published = true;
                // toggle buttons
                this.toolbarButtons.unpublish.show();
                this.toolbarButtons.save.hide();

                pimcore.elementservice.setElementPublishedState({
                    elementType: "object",
                    id: this.id,
                    published: true
                });
            }
        }.bind(this));
    },

    unpublish: function (only, callback) {
        this.save("unpublish", only, callback, function (rdata) {
            if (rdata && rdata.success) {
                this.data.general.published = false;

                // toggle buttons
                this.toolbarButtons.unpublish.hide();
                this.toolbarButtons.save.show();

                pimcore.elementservice.setElementPublishedState({
                    elementType: "object",
                    id: this.id,
                    published: false
                });
            }
        }.bind(this))
    },

    unpublishClose: function () {
        this.willClose = true;
        this.unpublish(null);
    },

    saveToSession: function (callback) {
        this.save("session", null, callback);
    },

    save: function (task, only, callback, successCallback) {

        var omitMandatoryCheck = false;

        // unpublish and save version is possible without checking mandatory fields
        if (task == "version" || task == "unpublish" || task == "autoSave") {
            omitMandatoryCheck = true;
        }

        if (this.tab.disabled || (this.tab.isMasked() && task != 'autoSave')) {
            return;
        }

        if(task != 'autoSave'){
            this.tab.mask();
        }

        var saveData = this.getSaveData(only, omitMandatoryCheck);

        if (saveData && saveData.data != false && saveData.data != "false") {

            const preSaveObject = new CustomEvent(pimcore.events.preSaveObject, {
                detail: {
                    object: this,
                    type: "object",
                    task: task
                },
                cancelable: true
            });

            const isAllowed = document.dispatchEvent(preSaveObject);
            if (!isAllowed) {
                this.tab.unmask();
                return false;
            }

            Ext.Ajax.request({
                url: Routing.generate('pimcore_admin_dataobject_dataobject_save', {task: task}),
                method: "PUT",
                params: saveData,
                success: function (response) {
                    if (task != "session") {
                        try {
                            var rdata = Ext.decode(response.responseText);
                            if (typeof successCallback == 'function') {
                                // the successCallback function retrieves response data information
                                successCallback(rdata);
                            }
                            if (rdata) {
                                if (rdata.success) {
                                    // check for version notification
                                    if (this.draftVersionNotification) {
                                        if (task == "publish" || task == "unpublish") {
                                            this.draftVersionNotification.hide();
                                        } else if (task === 'version' || task === 'autoSave') {
                                            this.draftVersionNotification.show();
                                        }
                                    }

                                    if (task != "autoSave") {
                                        pimcore.helpers.showNotification(t("success"), t("saved_successfully"), "success");
                                    }

                                    this.resetChanges(task);
                                    Ext.apply(this.data.general, rdata.general);

                                    if (rdata['draft']) {
                                        this.data['draft'] = rdata['draft'];
                                    }

                                    pimcore.helpers.updateTreeElementStyle('object', this.id, rdata.treeData);
                                    const postSaveObject = new CustomEvent(pimcore.events.postSaveObject, {
                                        detail: {
                                            object: this,
                                            task: task
                                        }
                                    });

                                    document.dispatchEvent(postSaveObject);
                                } else {
                                    pimcore.helpers.showPrettyError("error", t("saving_failed"), rdata.message);
                                }
                            }
                        } catch (e) {
                            pimcore.helpers.showNotification(t("error"), t("saving_failed"), "error");
                        }
                        // reload versions
                        if (task != "autoSave" && this.isAllowed("versions")) {
                            if (typeof this.versions.reload == "function") {
                                try {
                                    //TODO remove this as soon as it works
                                    this.versions.reload();
                                } catch (e) {
                                    console.log(e);
                                }
                            }
                        }
                    }

                    if (this.tab && (this.tab.getMaskTarget() || this.tab.el).getData()) {
                        this.tab.unmask();
                    }

                    if (typeof callback == "function") {
                        callback();
                    }

                    if (this.willClose) {
                        this.close();
                    }

                }.bind(this),
                failure: function (response) {
                    this.tab.unmask();
                }.bind(this)
            });

            return true;
        } else {
            this.tab.unmask();
        }
        return false;
    },

    remove: function () {
        var options = {
            "elementType": "object",
            "id": this.id
        };
        pimcore.elementservice.deleteElement(options);
    },

    isAllowed: function (key) {
        return this.data.userPermissions[key];
    },

    reload: function (params) {
        params = params || {};
        var uiState = null;

        // Reload layout when explicitly set to false
        if (params['layoutId'] === false) {
            params['layoutId'] = null;
        } else if (params['layoutId'] !== 0 && !params['layoutId']) {
            params['layoutId'] = this.data.currentLayoutId;
        }

        if(this.data.currentLayoutId == params['layoutId'] && !params['ignoreUiState']) {
            uiState = this.getUiState(this.tabbar);
        }

        this.tab.on("close", function () {
            var currentTabIndex = this.tab.ownerCt.items.indexOf(this.tab);
            var options = {
                layoutId: params['layoutId'],
                tabIndex: currentTabIndex,
                uiState: uiState
            };

            window.setTimeout(function (id) {
                pimcore.helpers.openObject(id, "object", options);
            }.bind(window, this.id), 500);
        }.bind(this));

        pimcore.helpers.closeObject(this.id);
    },

    getMetaInfo: function() {
        return {
            id: this.data.general.id,
            path: this.data.general.fullpath,
            parentid: this.data.general.parentId,
            classid: this.data.general.classId,
            "class": this.data.general.className,
            type: this.data.general.type,
            modificationdate: this.data.general.modificationDate,
            creationdate: this.data.general.creationDate,
            usermodification: this.data.general.userModification,
            usermodification_name: this.data.general.userModificationFullname,
            userowner: this.data.general.userOwner,
            userowner_name: this.data.general.userOwnerFullname,
            deeplink: pimcore.helpers.getDeeplink("object", this.data.general.id, this.data.general.type)
        };
    },

    showMetaInfo: function () {
        var metainfo = this.getMetaInfo();

        new pimcore.element.metainfo([
            {
                name: "id",
                value: metainfo.id
            },
            {
                name: "path",
                value: metainfo.path
            }, {
                name: "parentid",
                value: metainfo.parentid
            }, {
                name: "classid",
                value: metainfo.classid
            }, {
                name: "class",
                value: metainfo.class
            }, {
                name: "type",
                value: metainfo.type
            }, {
                name: "modificationdate",
                type: "date",
                value: metainfo.modificationdate
            }, {
                name: "creationdate",
                type: "date",
                value: metainfo.creationdate
            }, {
                name: "usermodification",
                type: "user",
                value: '<span data-uid="' + metainfo.usermodification + '">' + metainfo.usermodification_name + '</span>'
            }, {
                name: "userowner",
                type: "user",
                value: '<span data-uid="' + metainfo.userowner + '">' + metainfo.userowner_name + '</span>'
            }, {
                name: "deeplink",
                value: metainfo.deeplink
            }
        ], "object");
    },

    rename: function () {
        if (this.isAllowed("rename") && !this.data.general.locked) {
            var options = {
                elementType: "object",
                elementSubType: this.data.general.type,
                id: this.id,
                default: this.data.general.key
            };
            pimcore.elementservice.editElementKey(options);
        }
    },

    getUiState: function (extJsObject) {
        var visible = extJsObject.isVisible();
        if (extJsObject.hasOwnProperty('collapsed')) {
            visible = !extJsObject.collapsed;
        }
        var states = {visible: visible, children: []};

        if (extJsObject.hasOwnProperty('items')) {
            extJsObject.items.each(function (item, index) {
                if(!item.hasOwnProperty('excludeFromUiStateRestore')) {
                    states.children[index] = this.getUiState(item);
                }
            }.bind(this));
        }
        return states;
    },

    setUiState: function (extJsObject, savedState) {
        if (savedState.visible) {
            if (!extJsObject.hasOwnProperty('collapsed')) {
                extJsObject.setVisible(savedState.visible);
            } else {
                // without timeout the accordion panel's state gets confused and thus panels are not toggleable
                setTimeout(function () {
                    extJsObject.expand(false);
                }, 50);
            }
        }
        if (extJsObject.hasOwnProperty('items')) {
            extJsObject.items.each(function (item, index) {
                if(savedState.children[index]) {
                    this.setUiState(item, savedState.children[index]);
                }
            }.bind(this));
        }
    },

    shareViaNotifications: function () {
        if (pimcore.globalmanager.get("user").isAllowed('notifications_send')) {
            var elementData = {
                id:this.id,
                type:'object',
                published:this.data.general.published,
                path:this.data.general.fullpath
            };
            if (pimcore.globalmanager.get("new_notifications")) {
                pimcore.globalmanager.get("new_notifications").getWindow().destroy();
            }
            pimcore.globalmanager.add("new_notifications", new pimcore.notification.modal(elementData));        }
    }
});
