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

pimcore.registerNS("pimcore.document.folder");
/**
 * @private
 */
pimcore.document.folder = Class.create(pimcore.document.document, {

    initialize: function(id, options) {

        this.options = options;
        this.id = intval(id);
        this.setType("folder");
        this.addLoadingPanel();

        const preOpenDocumentFolder = new CustomEvent(pimcore.events.preOpenDocument, {
            detail: {
                document: this,
                type: "folder"
            },
            cancelable: true
        });

        const isAllowed = document.dispatchEvent(preOpenDocumentFolder);
        if (!isAllowed) {
            this.removeLoadingPanel();
            return;
        }

        this.getData();
    },

    init: function () {

        var user = pimcore.globalmanager.get("user");

        if (this.isAllowed("properties")) {
            try {
                this.properties = new pimcore.document.properties(this, "document", true);
            } catch (e) {
                console.log(e);
            }
        }

        if (user.isAllowed("notes_events")) {
            this.notes = new pimcore.element.notes(this, "document");
        }

        this.dependencies = new pimcore.element.dependencies(this, "document");
        this.tagAssignment = new pimcore.element.tag.assignment(this, "document");
        this.workflows = new pimcore.element.workflows(this, "document");
    },

    getSaveData : function () {
        var parameters = {};

        parameters.id = this.id;

        if (this.isAllowed("properties")) {
            // properties
            try {
                parameters.properties = Ext.encode(this.properties.getValues());
            }
            catch (e) {
                //console.log(e);
            }
        }

        return parameters;
    },

    addTab: function () {

        var tabTitle = this.data.key;
        this.tabPanel = Ext.getCmp("pimcore_panel_tabs");
        var tabId = "document_" + this.id;

        const tabbarContainer = new Ext.Container({
            flex: 2
        });

        const tabPanel = this.getTabPanel();
        const toolbar = this.getLayoutToolbar();

        if (pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
            this.tab = new Ext.Panel({
                id: tabId,
                cls: "pimcore_panel_toolbar_horizontal_border_layout",
                title: htmlspecialchars(tabTitle),
                closable:true,
                hideMode: "offsets",
                layout: "border",
                items: [
                    {
                        xtype: 'panel',
                        width: "100%",
                        region: 'north',
                        layout: 'hbox',
                        items: [
                            toolbar,
                            tabbarContainer,
                        ]
                    },

                    tabPanel
                ],
                iconCls: this.getIconClass(),
                document: this
            });

            this.toolbarSubmenu.menu.addCls('pimcore_headbar_submenu_menu');

            pimcore.helpers.headbar.prepareTabPanel(tabPanel, tabbarContainer, this.tab);
        } else {
            this.tab = new Ext.Panel({
                id: tabId,
                title: htmlspecialchars(tabTitle),
                closable:true,
                layout: "border",
                items: [
                    toolbar,
                    tabPanel
                ],
                iconCls: this.getIconClass(),
                document: this
            });
        }

        this.tab.on("beforedestroy", function () {
            Ext.Ajax.request({
                url: Routing.generate('pimcore_admin_element_unlockelement'),
                method: 'PUT',
                params: {
                    id: this.data.id,
                    type: "document"
                }
            });
        }.bind(this));

        // remove this instance when the panel is closed
        this.tab.on("destroy", function () {
            pimcore.globalmanager.remove("document_" + this.id);
            pimcore.helpers.forgetOpenTab("document_" + this.id + "_folder");
        }.bind(this));

        this.tab.on("activate", function () {
            this.tab.updateLayout();
            pimcore.layout.refresh();
        }.bind(this));

        this.tab.on("afterrender", function (tabId) {
            this.tabPanel.setActiveItem(tabId);

            const postOpenDocumentFolder = new CustomEvent(pimcore.events.postOpenDocument, {
                detail: {
                    document: this,
                    type: "folder"
                }
            });

            document.dispatchEvent(postOpenDocumentFolder);
        }.bind(this, tabId));

        this.removeLoadingPanel();

        this.addToMainTabPanel();

        // recalculate the layout
        pimcore.layout.refresh();
    },

    getLayoutToolbar : function () {

        if (!this.toolbar) {

            this.toolbarButtons = {};

            this.toolbarButtons.publish = new Ext.Button({
                text: t('save'),
                iconCls: "pimcore_icon_save_white",
                cls: "pimcore_save_button",
                scale: "medium",
                handler: this.save.bind(this)
            });

            this.toolbarButtons.remove = new Ext.Button({
                tooltip: t('delete_folder'),
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

            if (this.isAllowed("publish")) {
                buttons.push(this.toolbarButtons.publish);
            }

            buttons.push("-");

            if (pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
                this.toolbarSubmenu = new Ext.Button({
                    ...pimcore.helpers.headbar.getSubmenuConfig()
                });

                buttons.push(this.toolbarSubmenu);
            }

            if(this.isAllowed("delete") && !this.data.locked) {
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

            if(this.isAllowed("rename") && !this.data.locked) {
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

            this.toolbar = new Ext.Toolbar({
                id: "document_toolbar_" + this.id,
                region: "north",
                border: false,
                ...(() => pimcore.helpers.checkIfNewHeadbarLayoutIsEnabled() ? { flex: 3 } : { })(),
                cls: "pimcore_main_toolbar",
                items: buttons,
                overflowHandler: 'scroller'
            });
        }

        return this.toolbar;
    },

    getTabPanel: function () {

        var items = [];
        var user = pimcore.globalmanager.get("user");

        if (this.isAllowed("properties")) {
            items.push(this.properties.getLayout());
        }

        items.push(this.dependencies.getLayout());

        if (user.isAllowed("notes_events")) {
            items.push(this.notes.getLayout());
        }

        var user = pimcore.globalmanager.get("user");
        if (user.isAllowed("tags_assignment")) {
            items.push(this.tagAssignment.getLayout());
        }

        if (user.isAllowed("workflow_details") && this.data.workflowManagement && this.data.workflowManagement.hasWorkflowManagement === true) {
            items.push(this.workflows.getLayout());
        }

        this.tabbar = pimcore.helpers.getTabBar({items: items});
        return this.tabbar;
    },

    getMetaInfo: function() {
        return {
            id: this.data.id,
            path: this.data.path + this.data.key,
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
        ], "folder");
    },

    rename: function () {
        if(this.isAllowed("rename") && !this.data.locked) {
            var options = {
                elementType: "document",
                elementSubType: this.getType(),
                id: this.id,
                default: this.data.key
            }
            pimcore.elementservice.editElementKey(options);
        }
    }
});
