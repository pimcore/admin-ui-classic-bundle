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

pimcore.registerNS("pimcore.document.page_snippet");

/**
 * @private
 */
pimcore.document.page_snippet = Class.create(pimcore.document.document, {

    addTab: function () {

        var tabTitle = this.data.key;
        if (tabTitle.length < 1) {
            tabTitle = "home";
        }

        this.tabPanel = Ext.getCmp("pimcore_panel_tabs");
        var tabId = "document_" + this.id;

        const tabbarContainer = new Ext.Container({
            flex: 2
        });

        const backgroundContainer = new Ext.Container({
            cls: 'pimcore_headbar_background',
            width: '100%',
            height: 49,
        });

        const tabPanel = this.getTabPanel();
        const toolbar = this.getLayoutToolbar();

        if (pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
            this.tab = new Ext.Panel({
                id: tabId,
                cls: "pimcore_panel_toolbar_horizontal",
                title: htmlspecialchars(tabTitle),
                closable:true,
                hideMode: "offsets",
                layout: "absolute",
                items: [
                    toolbar,
                    tabPanel,
                    backgroundContainer,
                    tabbarContainer
                ],
                iconCls: this.getIconClass(),
                document: this
            });

            this.toolbarSubmenu.menu.addCls('pimcore_headbar_submenu_menu');

            tabPanel.items.each((item) => {
                const title = item.getTitle();

                if (title) {
                    item.tab.setTooltip(item.getTitle());
                    item.setTitle('');
                }
            });

            tabbarContainer.add(tabPanel.getTabBar());
            tabPanel.y = 46;
        } else {
            this.tab = new Ext.Panel({
                id: tabId,
                title: htmlspecialchars(tabTitle),
                closable:true,
                hideMode: "offsets",
                layout: "border",
                items: [
                    toolbar,
                    tabPanel
                ],
                iconCls: this.getIconClass(),
                document: this
            });
        }

        // remove this instance when the panel is closed
        this.tab.on("beforedestroy", function () {
            Ext.Ajax.request({
                url: Routing.generate('pimcore_admin_element_unlockelement'),
                method: 'PUT',
                params: {
                    id: this.data.id,
                    type: "document"
                }
            });

            this.cleanUpOnDestroy();
        }.bind(this));

        if (pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
            this.tab.on("boxready", function () {
                this.calcLayoutPositions(tabPanel, backgroundContainer, tabbarContainer, toolbar);
            }.bind(this));

            this.tab.on('resize', function () {
                this.calcLayoutPositions(tabPanel, backgroundContainer, tabbarContainer, toolbar);
            }.bind(this))
        }

        this.tab.on("destroy", function () {
            pimcore.globalmanager.remove("document_" + this.id);
            pimcore.helpers.forgetOpenTab("document_" + this.id + "_" + this.data.type);
        }.bind(this));


        this.tab.on("activate", function () {
            this.tab.updateLayout();
            pimcore.layout.refresh();
        }.bind(this));

        this.tab.on("afterrender", function (tabId) {
            this.tabPanel.setActiveItem(tabId);

            const postOpenDocument = new CustomEvent(pimcore.events.postOpenDocument, {
                detail: {
                    document: this,
                    type: this.data.type
                }
            });

            document.dispatchEvent(postOpenDocument);

        }.bind(this, tabId));

        this.removeLoadingPanel();

        this.addToMainTabPanel();

        // recalculate the layout
        pimcore.layout.refresh();
    },

    calcLayoutPositions: function(tabPanel, backgroundContainer, tabbarContainer, toolbar) {
        const headbarWidth = backgroundContainer.getWidth();
        const toolbarWidth = Math.round(headbarWidth * (3 / 5));
        const tabbarWidth = Math.round(headbarWidth * (2 / 5));

        toolbar.setPosition(0, 0);
        toolbar.setMaxWidth(toolbarWidth);
        toolbar.setWidth(toolbarWidth);
        tabbarContainer.setPosition(toolbarWidth, 0);
        tabbarContainer.setWidth(tabbarWidth);

        const tabbarItems = tabPanel.getTabBar().items.items;
        const firstTab = tabbarItems[0].getEl().dom;
        const lastTab = tabbarItems[tabbarItems.length - 1].getEl().dom;
        const firstBoundingRect = firstTab.getBoundingClientRect();
        const lastBoundingRect = lastTab.getBoundingClientRect();
        const firstAndLastTabDistance = lastBoundingRect.x + lastBoundingRect.width - firstBoundingRect.x;

        if (firstAndLastTabDistance > tabbarWidth) {
            tabPanel.getTabBar().setLayout({
                pack: 'start'
            })
        } else {
            tabPanel.getTabBar().setLayout({
                pack: 'end'
            })
        }

        tabPanel.setHeight(this.tab.getHeight() - 46);
        this.tab.updateLayout();
    },

    cleanUpOnDestroy: function () {
        if (this.edit) {
            if (typeof this.edit.onClose == "function") {
                this.edit.onClose();
            }
        }
        if (this.preview) {
            if (typeof this.preview.onClose == "function") {
                this.preview.onClose();
            }
        }
        if (this.settings) {
            if (typeof this.settings.onClose == "function") {
                this.settings.onClose();
            }
        }
        if (this.properties) {
            if (typeof this.properties.onClose == "function") {
                this.properties.onClose();
            }
        }
        this.removeFromSession();
    },

    getLayoutToolbar : function () {

        if (!this.toolbar) {
            this.toolbarButtons = {};

            this.toolbarButtons.save = new Ext.SplitButton({
                text: t('save'),
                iconCls: "pimcore_icon_save_white",
                cls: "pimcore_save_button",
                scale: "medium",
                handler: this.save.bind(this, 'version'),
                menu: [
                    {
                        text: t('save_close'),
                        iconCls: "pimcore_icon_save",
                        handler: this.saveClose.bind(this)
                    },
                    {
                        text: t('save_only_scheduled_tasks'),
                        iconCls: "pimcore_icon_save",
                        handler: this.save.bind(this, "scheduler","scheduler"),
                        hidden: !this.isAllowed("settings") || this.data.published
                    }
                ]
            });

            if (this.isAllowed("publish")) {
                this.toolbarButtons.save.menu.add(
                    {
                        text: t('save_and_publish'),
                        iconCls: "pimcore_icon_save",
                        cls: "pimcore_save_button",
                        scale: "medium",
                        handler: this.publish.bind(this)
                    }                    
                );

                this.toolbarButtons.save.menu.add(
                    {
                        text: t('save_pubish_close'),
                        iconCls: "pimcore_icon_save",
                        handler: this.publishClose.bind(this)
                    }
                )
            }

            this.toolbarButtons.publish = new Ext.SplitButton({
                text: t('save_and_publish'),
                iconCls: "pimcore_icon_save_white",
                cls: "pimcore_save_button",
                scale: "medium",
                handler: this.publish.bind(this),
                menu: [
                    {
                        text: t('save_pubish_close'),
                        iconCls: "pimcore_icon_save",
                        handler: this.publishClose.bind(this)
                    },{
                        text: t('save_draft'),
                        iconCls: "pimcore_icon_save",
                        handler: this.save.bind(this, 'version'),
                        hidden: !this.isAllowed("save") || !this.data.published
                    },
                    {
                        text: t('save_only_scheduled_tasks'),
                        iconCls: "pimcore_icon_save",
                        handler: this.save.bind(this, "scheduler","scheduler"),
                        hidden: !this.isAllowed("settings") || !this.data.published
                    }
                ]
            });

            this.toolbarButtons.unpublish = new Ext.Button({
                text: t('unpublish'),
                iconCls: "pimcore_material_icon_unpublish pimcore_material_icon",
                scale: "medium",
                handler: this.unpublish.bind(this)
            });

            if (pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
                this.toolbarButtons.unpublish = Ext.create('Ext.menu.Item', {
                    text: t('unpublish'),
                    iconCls: "pimcore_material_icon_unpublish pimcore_material_icon",
                    scale: "medium",
                    handler: this.unpublish.bind(this)
                })
            }

            this.toolbarButtons.remove = new Ext.Button({
                tooltip: t('delete'),
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

            var buttons = [];

            if (pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
                this.toolbarSubmenu = new Ext.Button({
                    ...pimcore.helpers.headbar.getSubmenuConfig()
                });
            }

            if (this.isAllowed("save")) {
                buttons.push(this.toolbarButtons.save);
            }

            if (this.isAllowed("publish")) {
                buttons.push(this.toolbarButtons.publish);
            }

            if (this.isAllowed("unpublish") && !this.data.locked) {
                if (pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
                    this.toolbarSubmenu.menu.add(
                        this.toolbarButtons.unpublish
                    )
                } else {
                    buttons.push(this.toolbarButtons.unpublish);
                }
            }

            buttons.push("-");

            if (pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
                buttons.push(this.toolbarSubmenu);
            }

            if(this.isAllowed("delete") && !this.data.locked && this.data.id != 1) {
                if (pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
                    this.toolbarSubmenu.menu.add({
                        text: t('delete'),
                        iconCls: "pimcore_material_icon_delete pimcore_material_icon",
                        scale: "medium",
                        handler: this.remove.bind(this)
                    });
                } else {
                    buttons.push(this.toolbarButtons.remove);
                }
            }

            if(this.isAllowed("rename") && !this.data.locked && this.data.id != 1) {
                if (pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
                    this.toolbarSubmenu.menu.add({
                        text: t('rename'),
                        iconCls: "pimcore_material_icon_rename pimcore_material_icon",
                        scale: "medium",
                        handler: this.rename.bind(this)
                    });
                } else {
                    buttons.push(this.toolbarButtons.rename);
                }
            }

            buttons.push({
                tooltip: t('reload'),
                iconCls: "pimcore_material_icon_reload pimcore_material_icon",
                scale: "medium",
                handler: this.reload.bind(this)
            });

            if (pimcore.elementservice.showLocateInTreeButton("document")) {
                buttons.push({
                    tooltip: t('show_in_tree'),
                    iconCls: "pimcore_material_icon_locate pimcore_material_icon",
                    scale: "medium",
                    handler: this.selectInTree.bind(this)
                });
            }

            buttons.push({
                xtype: "splitbutton",
                tooltip: t("show_metainfo"),
                iconCls: "pimcore_material_icon_info pimcore_material_icon",
                scale: "medium",
                handler: this.showMetaInfo.bind(this),
                menu: this.getMetaInfoMenuItems()
            });

            if (pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
                this.toolbarSubmenu.menu.add(this.getTranslationButtons(true));
            } else {
                buttons.push(this.getTranslationButtons());
            }

            if(this.data["url"]) {
                buttons.push("-");
                const openInNewWindowConfig = {
                    ...(
                        () => pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled() ?
                            { text: t("open_in_new_window") } :
                            { tooltip: t("open_in_new_window") }
                    )(),
                    iconCls: "pimcore_material_icon_open_window pimcore_material_icon",
                    scale: "medium",
                    handler: function () {
                        window.open(this.data.url);
                    }.bind(this)
                }

                if (pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
                    this.toolbarSubmenu.menu.add(openInNewWindowConfig);
                } else {
                    buttons.push(openInNewWindowConfig);
                }

                const openPreviewInNewWindowConfig = {
                    ...(
                        () => pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled() ?
                            { text: t("open_preview_in_new_window") } :
                            { tooltip: t("open_preview_in_new_window") }
                    )(),
                    iconCls: "pimcore_material_icon_preview pimcore_material_icon",
                    scale: "medium",
                    handler: function () {
                        var date = new Date();
                        var link = this.data.path + this.data.key;
                        var linkParams = [];

                        linkParams.push("pimcore_preview=true");
                        linkParams.push("_dc=" + date.getTime());

                        // add target group parameter if available
                        if(this["edit"] && this.edit.areaToolBar) {
                            if(this.edit.areaToolBar.targetGroup && this.edit.areaToolBar.targetGroup.getValue()) {
                                linkParams.push("_ptg=" + this.edit.areaToolBar.targetGroup.getValue());
                            }
                        }

                        if(linkParams.length) {
                            link += "?" + linkParams.join("&");
                        }

                        if(this.isDirty()) {
                            this.saveToSession(function () {
                                window.open(link);
                            });
                        } else {
                            window.open(link);
                        }
                    }.bind(this)
                };

                if (pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
                    this.toolbarSubmenu.menu.add(openPreviewInNewWindowConfig);
                } else {
                    buttons.push(openPreviewInNewWindowConfig);
                }
            }

            if (pimcore.globalmanager.get("user").isAllowed('notifications_send')) {
                const shareViaNotificationsConfig = {
                    ...(
                        () => pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled() ?
                            { text: t("share_via_notifications") } :
                            { tooltip: t("share_via_notifications") }
                    )(),
                    iconCls: "pimcore_icon_share",
                    scale: "medium",
                    handler: this.shareViaNotifications.bind(this)
                };

                if (pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
                    this.toolbarSubmenu.menu.add(shareViaNotificationsConfig);
                } else {
                    buttons.push(shareViaNotificationsConfig);
                }
            }

            //workflow management
            pimcore.elementservice.integrateWorkflowManagement('document', this.data.id, this, buttons);


            this.draftVersionNotification = new Ext.Button({
                text: t('draft'),
                cls: "pimcore_draft_button",
                iconCls: "pimcore_icon_delete pimcore_material_icon",
                scale: "medium",
                hidden: true,
                handler: this.deleteDraft.bind(this)
            });

            buttons.push(this.draftVersionNotification);

            if (this.data.draft && (this.data.draft.isAutoSave || this.isAllowed("save"))) {
                this.draftVersionNotification.show();
            }

            this.toolbar = new Ext.Toolbar({
                id: "document_toolbar_" + this.id,
                region: "north",
                border: false,
                ...(() => pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled() ? { flex: 3 } : { })(),
                cls: "pimcore_main_toolbar",
                items: buttons,
                overflowHandler: 'scroller'
            });

            if (this.isAllowed('publish') && this.isAllowed('save')) {
                if (this.data.published) {
                    this.toolbarButtons.save.hide();
                    this.toolbarButtons.publish.show();
                } else {
                    this.toolbarButtons.publish.hide();
                    this.toolbarButtons.save.show();
                }
            }

            if (!this.data.published) {
                this.toolbarButtons.unpublish.hide();
            } else if (this.isAllowed("publish")) {
                this.toolbarButtons.save.hide();
            }
        }

        return this.toolbar;
    },

    saveToSession: function (onComplete) {

        if (typeof onComplete != "function") {
            onComplete = function () {
            };
        }

        Ext.Ajax.request({
            url: Routing.generate(this.getSaveToSessionRoute()),
            method: "post",
            params: this.getSaveData(),
            success: onComplete
        });
    },

    removeFromSession: function () {
        Ext.Ajax.request({
            url: Routing.generate(this.getRemoveFromSessionRoute()),
            method: 'DELETE',
            params: {id: this.data.id}
        });
    },

    reloadEditmode: function () {

        this.saveToSession(function () {
            if (this.edit && this.edit.layout.rendered) {
                this.edit.reload(true);
            }

            if (this.preview && this.preview.layout.rendered) {
                this.preview.loadCurrentPreview();
            }

        }.bind(this));
    },

    getMetaInfo: function() {
        return {
            id: this.data.id,
            path: this.data.path + this.data.key,
            public_url: this.data.url,
            parentid: this.data.parentId,
            type: this.data.type,
            modificationdate: this.data.modificationDate,
            creationdate: this.data.creationDate,
            usermodification: this.data.userModification,
            usermodification_name: this.data.userModificationFullname,
            userowner: this.data.userOwner,
            userowner_name: this.data.userOwnerFullname,
            deeplink: pimcore.helpers.getDeeplink("document", this.data.id, this.data.type)
        };
    },

    showMetaInfo: function() {
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
                name: "public_url",
                value: metainfo.public_url
            }, {
                name: "parentid",
                value: metainfo.parentid
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
        ], "document");
    },

    rename: function () {
        if(this.isAllowed("rename") && !this.data.locked && this.data.id != 1) {
            var options = {
                elementType: "document",
                elementSubType: this.getType(),
                id: this.id,
                default: this.data.key
            };
            pimcore.elementservice.editElementKey(options);
        }
    },

    shareViaNotifications: function () {
        if (pimcore.globalmanager.get("user").isAllowed('notifications_send')) {
            var elementData = {
                id:this.data.id,
                type:'document',
                published:this.data.published,
                path:this.data.path + this.data.key
            };
            if (pimcore.globalmanager.get("new_notifications")) {
                pimcore.globalmanager.get("new_notifications").getWindow().destroy();
            }
            pimcore.globalmanager.add("new_notifications", new pimcore.notification.modal(elementData));        }
    },

    publish: function($super, only, callback) {
        /* It is needed to have extra validateRequiredEditables check here
         * so as to stop propagating Admin UI changes in case of required content = true */
        if (this.validateRequiredEditables()) {
            return false;
        }

        $super(only, callback);
    },

    save: function ($super, task, only, callback, successCallback) {
        if (task !== "publish" && task !== "autoSave") {
            this.validateRequiredEditables(true);
        }

        $super(task, only, callback, successCallback);
    },

    validateRequiredEditables: function (dismissAlert) {
        //validate required editables against missing values
        try {
            /* No validation in case of changing system settings as template can be changed
             * if template is changed, then document editables be validated on server side
             */
            var settingsForm = Ext.getCmp("pimcore_document_settings_" + this.id);
            if(settingsForm.dirty) {
                this.data.missingRequiredEditable = null;
                return;
            }

            var emptyRequiredEditables = this.edit.getEmptyRequiredEditables();
            if (emptyRequiredEditables.length > 0) {
                if (!dismissAlert) {
                    Ext.MessageBox.show({
                        title: t("error"),
                        width: 500,
                        msg: t("complete_required_fields")
                            + '<br /><br /><textarea style="width:100%; min-height:100px; resize:none" readonly="readonly">'
                            + emptyRequiredEditables.join(", ") + "</textarea>",
                        buttons: Ext.Msg.OK
                    });
                }

                this.data.missingRequiredEditable = true;

                return true;
            }

            if(this.data.missingRequiredEditable == true) {
                this.data.missingRequiredEditable = false;
            }
        } catch(e) {
        }
    },

    getSaveToSessionRoute: function() {
        return "pimcore_admin_document_" + this.type + '_savetosession';
    },

    getRemoveFromSessionRoute: function() {
        return "pimcore_admin_document_" + this.type + '_removefromsession';
    },

});
