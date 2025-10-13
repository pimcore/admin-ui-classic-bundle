/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.object.objectbrick");
/**
 * @private
 */
pimcore.object.objectbrick = Class.create(pimcore.object.fieldcollection, {

    forbiddenNames: [
        "abstract", "class", "data", "folder", "list", "permissions", "resource", "dao", "concrete", "items",
        "object", "interface", "default"
    ],

    getTabPanel: function () {

        if (!this.panel) {
            this.panel = new Ext.Panel({
                id: "pimcore_objectbricks",
                title: t("objectbricks"),
                iconCls: "pimcore_icon_objectbricks",
                border: false,
                layout: "border",
                closable:true,
                items: [this.getTree(), this.getEditPanel()]
            });

            var tabPanel = Ext.getCmp("pimcore_panel_tabs");
            tabPanel.add(this.panel);
            tabPanel.setActiveItem("pimcore_objectbricks");


            this.panel.on("destroy", function () {
                pimcore.globalmanager.remove("objectbricks");
            }.bind(this));

            pimcore.layout.refresh();
        }

        return this.panel;
    },

    getTree: function () {
        if (!this.tree) {
            this.store = Ext.create('Ext.data.TreeStore', {
                autoLoad: false,
                autoSync: true,
                proxy: {
                    type: 'ajax',
                    url: Routing.generate('pimcore_admin_dataobject_class_objectbricktree'),
                    reader: {
                        type: 'json'

                    },
                    extraParams: {
                        grouped: 1
                    }
                }
            });

            let metadataTreeHelper = new pimcore.object.helpers.classTree(true);
            let filterField = new Ext.form.field.Text(
              {
                width: 130,
                hideLabel: true,
                enableKeyEvents: true
              }
            );

            let filterButton = new Ext.button.Button({
              iconCls: "pimcore_icon_search"
            });

            this.tree = Ext.create('Ext.tree.Panel', {
                id: "pimcore_panel_objectbricks_tree",
                store: this.store,
                region: "west",
                autoScroll:true,
                animate:false,
                containerScroll: true,
                width: 300,
                split: true,
                root: {
                    id: '0'
                },
                listeners: this.getTreeNodeListeners(),
                rootVisible: false,
                tbar: {
                    cls: 'pimcore_toolbar_border_bottom',
                    items: [
                        {
                            text: t("add"),
                            iconCls: "pimcore_icon_objectbricks pimcore_icon_overlay_add",
                            handler: this.addField.bind(this),
                            disabled: !pimcore.settings['class-definition-writeable']
                        },
                        '-',
                        filterField,
                        filterButton
                    ]
                }
            });

            this.tree.on("render", function () {
                this.getRootNode().expand();
            });

            filterField.on("keyup", metadataTreeHelper.updateFilter.bind(this, this.tree, filterField));
            filterButton.on("click", metadataTreeHelper.updateFilter.bind(this, this.tree, filterField));
        }

        return this.tree;
    },

    getTreeNodeListeners: function () {
        var treeNodeListeners = {
            'itemclick': this.onTreeNodeClick.bind(this),
            "itemcontextmenu": this.onTreeNodeContextmenu.bind(this),
            "beforeitemmove": this.onTreeNodeBeforeMove.bind(this)
        };
        return treeNodeListeners;
    },

    onTreeNodeClick: function (tree, record, item, index, e, eOpts ) {
        if (!record.isLeaf()) {
            return;
        }
        this.openBrick(record.data.id);
    },

    openBrick: function (id) {
        if(Ext.getCmp("pimcore_objectbrick_editor_panel_" + id)) {
            this.getEditPanel().setActiveTab(Ext.getCmp("pimcore_objectbrick_editor_panel_" + id));
            return;
        }

        Ext.Ajax.request({
            url: Routing.generate('pimcore_admin_dataobject_class_objectbrickget'),
            params: {
                id: id
            },
            success: this.addFieldPanel.bind(this)
        });
    },

    addFieldPanel: function (response) {

        var data = Ext.decode(response.responseText);
        var fieldPanel = new pimcore.object.objectbricks.field(data, this, this.openBrick.bind(this, data.key), "pimcore_objectbrick_editor_panel_");
        pimcore.layout.refresh();

    },

    onTreeNodeContextmenu: function (tree, record, item, index, e, eOpts) {
        if (!record.isLeaf()) {
            return;
        }

        e.stopEvent();
        tree.select();

        var menu = new Ext.menu.Menu();
        menu.add(new Ext.menu.Item({
            text: t('delete'),
            iconCls: "pimcore_icon_objectbricks pimcore_icon_overlay_delete",
            handler: this.deleteField.bind(this, tree, record),
            disabled: !pimcore.settings['class-definition-writeable']
        }));

        menu.showAt(e.pageX, e.pageY);
    },

    onTreeNodeBeforeMove: function (node, oldParent, newParent, index, eOpts ) {
        return pimcore.helpers.treeDragDropValidate(node, oldParent, newParent);
    },

    addField: function () {
        Ext.MessageBox.prompt(' ', t('enter_the_name_of_the_new_object_brick'),
                                                    this.addFieldComplete.bind(this), null, null, "");
    },

    addFieldComplete: function (button, value, object) {
        const isValidName = /^[a-zA-Z]\w*$/;

        if (button == "ok" && value.length > 2 && isValidName.test(value) && !in_arrayi(value, this.forbiddenNames)) {
            Ext.Ajax.request({
                url: Routing.generate('pimcore_admin_dataobject_class_objectbrickupdate'),
                method: 'POST',
                params: {
                    key: value,
                    task: 'add'
                },
                success: function (response) {
                    this.tree.getStore().load();

                    var data = Ext.decode(response.responseText);
                    if (data && data.success) {
                        this.openBrick(data.id);
                    } else if (data && data.message) {
                        Ext.Msg.alert(t('error'), data.message);
                    } else {
                        Ext.Msg.alert(t('error'), t('failed_to_create_new_item'));
                    }
                }.bind(this)
            });
        }
        else if (button == "cancel") {
            return;
        }
        else {
            Ext.Msg.alert(t('error'), t('failed_to_create_new_item'));
        }
    },

    activate: function () {
        Ext.getCmp("pimcore_panel_tabs").setActiveItem("pimcore_objectbricks");
    },

    deleteField: function (tree, record) {

        Ext.Msg.confirm(t('delete'), sprintf(t('delete_message_advanced'), t('objectbrick'), record.data.text), function(btn) {
            if (btn == 'yes') {
                Ext.Ajax.request({
                    url: Routing.generate('pimcore_admin_dataobject_class_objectbrickdelete'),
                    method: 'DELETE',
                    params: {
                        id: record.data.id
                    }
                });

                this.getEditPanel().removeAll();
                record.remove();
            }
        }.bind(this));
    }


});
