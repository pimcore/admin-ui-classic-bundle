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

pimcore.registerNS('pimcore.object.selectoptions');

/**
 * @private
 */
pimcore.object.selectoptions = Class.create({
    initialize: function () {
        this.getTabPanel();
    },

    getTabPanel: function () {
        if (!this.panel) {
            this.panel = new Ext.Panel({
                id: 'pimcore_selectoptions',
                title: t('selectoptions'),
                iconCls: 'pimcore_icon_select',
                border: false,
                layout: 'border',
                closable:true,
                items: [this.getTree(), this.getEditPanel()]
            });

            var tabPanel = Ext.getCmp('pimcore_panel_tabs');
            tabPanel.add(this.panel);
            tabPanel.setActiveItem('pimcore_selectoptions');

            this.panel.on('destroy', function () {
                pimcore.globalmanager.remove('selectoptions');
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
                    url: Routing.generate('pimcore_admin_dataobject_class_selectoptionstree'),
                    reader: {
                        type: 'json'

                    },
                    extraParams: {
                        grouped: 1
                    }
                }
            });

            this.tree = Ext.create('Ext.tree.Panel', {
                id: 'pimcore_panel_selectoptions_tree',
                store: this.store,
                region: 'west',
                autoScroll:true,
                animate:false,
                containerScroll: true,
                width: 200,
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
                            text: t('add'),
                            iconCls: 'pimcore_icon_select pimcore_icon_overlay_add',
                            handler: this.addDefinition.bind(this),
                            disabled: !pimcore.settings['select-options-writeable']
                        }
                    ]
                }
            });

            this.tree.on('render', function () {
                this.getRootNode().expand();
            });
        }

        return this.tree;
    },

    getEditPanel: function () {
        if (!this.editPanel) {
            this.editPanel = Ext.create('Ext.tab.Panel', {
                region: "center",
                plugins:
                    [
                        Ext.create('Ext.ux.TabCloseMenu', {
                            showCloseAll: true,
                            showCloseOthers: true
                        }),
                        Ext.create('Ext.ux.TabReorderer', {})
                    ]
            });
        }

        return this.editPanel;
    },

    getTreeNodeListeners: function () {
        var treeNodeListeners = {
            'itemclick': this.onTreeNodeClick.bind(this),
            'itemcontextmenu': this.onTreeNodeContextmenu.bind(this),
            'beforeitemmove': this.onTreeNodeBeforeMove.bind(this)
        };
        return treeNodeListeners;
    },

    onTreeNodeClick: function (tree, record) {
        if (!record.isLeaf()) {
            return;
        }
        this.openSelectOptions(record.data.id);
    },

    openSelectOptions: function (id) {
        if (Ext.getCmp('pimcore_selectoptions_editor_panel_' + id)) {
            this.getEditPanel().setActiveTab(Ext.getCmp('pimcore_selectoptions_editor_panel_' + id));
            return;
        }

        Ext.Ajax.request({
            url: Routing.generate('pimcore_admin_dataobject_class_selectoptionsget'),
            params: {
                id: id
            },
            success: this.addDefinitionPanel.bind(this)
        });
    },

    addDefinitionPanel: function (response) {
        var data = Ext.decode(response.responseText);
        new pimcore.object.selectoptionsitems.definition(
            data,
            this,
            this.openSelectOptions.bind(this, data.id),
            'pimcore_selectoptions_editor_panel_'
        );
        pimcore.layout.refresh();
    },

    onTreeNodeContextmenu: function (tree, record, item, index, e, eOpts) {
        if (!record.isLeaf()) {
            return;
        }

        e.stopEvent();
        tree.select();

        let menu = new Ext.menu.Menu();
        if (pimcore.currentuser.admin || record.data.adminOnly !== true) {
            menu.add(new Ext.menu.Item({
                text: t('delete'),
                iconCls: 'pimcore_icon_select pimcore_icon_overlay_delete',
                handler: this.deleteDefinition.bind(this, tree, record)
            }));
        }

        menu.showAt(e.pageX, e.pageY);
    },

    onTreeNodeBeforeMove: function (node, oldParent, newParent, index, eOpts ) {
        return pimcore.helpers.treeDragDropValidate(node, oldParent, newParent);
    },

    addDefinition: function () {
        Ext.MessageBox.prompt(' ', t('enter_the_name_of_the_new_item'),
            this.addDefinitionComplete.bind(this), null, null, '');
    },

    addDefinitionComplete: function (button, value, object) {
        var isValidName = /^[A-Z][a-zA-Z0-9]*$/;

        if (
            button !== 'ok'
            || value.length < 3
            || !isValidName.test(value)
            || pimcore.object.helpers.reservedWords.isReservedWord(value)
        ) {
            if (button !== 'cancel') {
                Ext.Msg.alert(' ', t('failed_to_create_new_item_select_options'));
            }
            return;
        }

        Ext.Ajax.request({
            url: Routing.generate('pimcore_admin_dataobject_class_selectoptionsupdate'),
            method: 'POST',
            params: {
                id: value,
                task: 'add'
            },
            success: function (response) {
                this.tree.getStore().load();

                var data = Ext.decode(response.responseText);
                if (!data) {
                    return;
                }

                if (data.success) {
                    this.openSelectOptions(data.id);
                    pimcore.object.helpers.selectField.getSelectOptionsStore().reload();
                } else {
                    pimcore.helpers.showNotification(t('error'), data.message, 'error', response.responseText);
                }
            }.bind(this)
        });
    },

    activate: function () {
        Ext.getCmp('pimcore_panel_tabs').setActiveItem('pimcore_selectoptions');
    },

    deleteDefinition: function (tree, record) {
        Ext.Msg.confirm(t('delete'), sprintf(t('delete_message_advanced'), t('selectoptions'), record.data.text), function (btn) {
            if (btn === 'yes') {
                Ext.Ajax.request({
                    url: Routing.generate('pimcore_admin_dataobject_class_selectoptionsdelete'),
                    method: 'DELETE',
                    params: {
                        id: record.data.id
                    },
                    success: function (response) {
                        var data = Ext.decode(response.responseText);
                        if (data && data.success === false) {
                            pimcore.helpers.showNotification(t('error'), data.message, 'error', response.responseText);
                            return;
                        }

                        this.getEditPanel().removeAll();
                        record.remove();
                    }.bind(this)
                });
            }
        }.bind(this));
    }
});