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

pimcore.registerNS("pimcore.asset.text");
/**
 * @private
 */
pimcore.asset.text = Class.create(pimcore.asset.asset, {

    initialize: function(id, options) {

        this.options = options;
        this.id = intval(id);
        this.setType("text");
        this.addLoadingPanel();

        const preOpenAssetText = new CustomEvent(pimcore.events.preOpenAsset, {
            detail: {
                object: this,
                type: "text"
            },
            cancelable: true
        });

        const isAllowed = document.dispatchEvent(preOpenAssetText);
        if (!isAllowed) {
            this.removeLoadingPanel();
            return;
        }

        var user = pimcore.globalmanager.get("user");

        this.properties = new pimcore.element.properties(this, "asset");
        this.versions = new pimcore.asset.versions(this);
        this.scheduler = new pimcore.element.scheduler(this, "asset");
        this.dependencies = new pimcore.element.dependencies(this, "asset");

        if (user.isAllowed("notes_events")) {
            this.notes = new pimcore.element.notes(this, "asset");
        }

        this.tagAssignment = new pimcore.element.tag.assignment(this, "asset");
        this.metadata = new pimcore.asset.metadata.editor(this);
        this.workflows = new pimcore.element.workflows(this, "asset");

        this.getData();
    },

    getTabPanel: function () {
        var items = [];
        var user = pimcore.globalmanager.get("user");

        items.push(this.getEditPanel());

        if (this.isAllowed("publish")) {
            items.push(this.metadata.getLayout());
        }
        if (this.isAllowed("properties")) {
            items.push(this.properties.getLayout());
        }
        if (this.isAllowed("versions")) {
            items.push(this.versions.getLayout());
        }
        if (this.isAllowed("settings")) {
            items.push(this.scheduler.getLayout());
        }

        items.push(this.dependencies.getLayout());

        if (user.isAllowed("notes_events")) {
            items.push(this.notes.getLayout());
        }

        if (user.isAllowed("tags_assignment")) {
            items.push(this.tagAssignment.getLayout());
        }

        if (user.isAllowed("workflow_details") && this.data.workflowManagement && this.data.workflowManagement.hasWorkflowManagement === true) {
            items.push(this.workflows.getLayout());
        }

        this.tabbar = pimcore.helpers.getTabBar({items: items});
        return this.tabbar;
    },

    getEditPanel: function () {

        if (!this.editPanel) {
            if(this.data.data !== false) {
                let editorId = "asset_editor_" + this.id;

                this.editPanel = new Ext.Panel({
                    title: t("edit"),
                    iconCls: "pimcore_icon_edit",
                    bodyStyle: "padding: 10px;",
                    layout: 'fit',
                    items: [{
                        xtype: 'component',
                        html: '<div id="' + editorId + '" style="height:100%;width:100%"></div>',
                        listeners: {
                            afterrender: function (cmp) {
                                var me = this;
                                var editor = ace.edit(editorId);
                                editor.setTheme('ace/theme/chrome');

                                //set editor file mode
                                let modelist = ace.require('ace/ext/modelist');
                                let mode = modelist.getModeForPath(this.data.url).mode;
                                editor.getSession().setMode(mode);

                                //set data
                                if (this.data.data) {
                                    editor.setValue(this.data.data);
                                    editor.clearSelection();
                                }

                                editor.setOptions({
                                    showLineNumbers: true,
                                    showPrintMargin: false,
                                    fontFamily: 'Courier New, Courier, monospace;'
                                });

                                editor.on("change", function(obj) {
                                    me.detectedChange();
                                });

                                this.editor = editor;
                            }.bind(this)
                        }
                    }]
                });


                this.editPanel.on("resize", function (el, width, height, rWidth, rHeight) {
                    this.editor.resize();
                }.bind(this));

                this.editPanel.on("destroy", function (el) {
                    if (this.editor) {
                        this.editor.destroy();
                    }
                }.bind(this));
            } else {
                this.editPanel = new Ext.Panel({
                    title: t("preview"),
                    html: t("preview_not_available"),
                    bodyCls: "pimcore_panel_body_centered",
                    iconCls: "pimcore_material_icon_devices pimcore_material_icon"
                });
            }
        }

        return this.editPanel;
    },
    
    
    getSaveData : function ($super, only) {
        var parameters = $super(only);
        
        if(!Ext.isString(only) && this.data.data !== false) {
            parameters.data = this.editor.getValue();
        }
        
        return parameters;
    }
});

