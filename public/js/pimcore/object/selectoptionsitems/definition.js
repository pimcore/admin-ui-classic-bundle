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

pimcore.registerNS('pimcore.object.selectoptionsitems.definition');

/**
 * @private
 */
pimcore.object.selectoptionsitems.definition = Class.create({
    parentPanel: null,
    data: {
        id: null,
        enumName: null,
        group: null,
        adminOnly: false,
        useTraits: '',
        implementsInterfaces: '',
        selectOptions: []
    },
    editorPrefix: null,
    reopen: null,

    panel: null,
    formPanel: null,
    groupField: null,
    adminOnlyField: null,
    useTraitsField: null,
    implementsInterfacesField: null,
    optionsEditorGrid: null,
    selectionModel: null,

    readOnly: false,

    initialize: function (data, parentPanel, reopen, editorPrefix) {
        this.parentPanel = parentPanel;
        this.data = data;
        this.editorPrefix = editorPrefix;
        this.reopen = reopen;

        // Only allow admin users to edit the select options when enabled
        this.readOnly = data.adminOnly && !pimcore.currentuser.admin;

        this.addLayout();
    },

    getId: function () {
        return this.data.id;
    },

    addLayout: function () {
        this.panel = new Ext.Panel({
            border: false,
            layout: 'border',
            closable: true,
            autoScroll: true,
            title: 'ID: ' + this.data.id,
            id: this.editorPrefix + this.getId(),
            items: [
                this.createEditPanel()
            ],
            buttons: this.createPanelButtons()
        });

        this.parentPanel.getEditPanel().add(this.panel);

        this.editpanel.add(this.getFormPanel());
        // this.setCurrentNode('root');
        this.parentPanel.getEditPanel().setActiveTab(this.panel);

        pimcore.layout.refresh();
    },

    createEditPanel: function () {
        this.editpanel = Ext.create('Ext.Panel', {
            region: 'center',
            bodyStyle: 'padding: 10px;',
            autoScroll: true
        });
        return this.editpanel;
    },

    createPanelButtons: function () {
        let buttons = [
            {
                text: t('reload_definition'),
                handler: this.reload.bind(this),
                iconCls: 'pimcore_icon_reload',
            },
        ];

        if (!this.readOnly) {
            buttons.push({
                text: t('save'),
                iconCls: 'pimcore_icon_apply',
                handler: this.save.bind(this),
                disabled: !this.data.isWriteable,
            });
        }

        return buttons;
    },

    getFormPanel: function () {
        this.formPanel = new Ext.form.FormPanel({
            title: '<b>' + t('general_settings') + '</b>',
            bodyStyle: 'padding: 10px; border-top: 1px solid #606060 !important;',
            autoScroll: true,
            defaults: {
                labelWidth: 200
            },
            items: [
                this.createPhpEnumNameField(),
                this.createUseTraitsField(),
                this.createImplementsInterfacesField(),
                this.createGroupField(),
                this.createAdminOnlyField(),
                this.createOptionsEditorGrid(),
                {
                    xtype: 'displayfield',
                    fieldLabel: '<b>' + t('used_by_class') + '</b>',
                    labelSeparator: ''
                },
                this.createUsagesGrid()
            ]
        });

        this.formPanel.on('afterrender', function () {
            this.usagesStore.reload()
        }.bind(this));

        return this.formPanel;
    },

    /**
     * @returns {Ext.form.field.Text}
     */
    createPhpEnumNameField: function () {
        return Ext.create('Ext.form.field.Text', {
            width: 600,
            name: 'phpEnumName',
            fieldLabel: t('PHP Enum Name'),
            disabled: true,
            renderer: Ext.util.Format.htmlEncode,
            value: this.data.enumName
        });
    },

    /**
     * @returns {Ext.form.field.Text}
     */
    createUseTraitsField: function () {
        this.useTraitsField = Ext.create('Ext.form.field.Text', {
            width: 600,
            name: 'useTraits',
            fieldLabel: t('use_traits'),
            value: this.data.useTraits,
            disabled: this.readOnly,
        });
        return this.useTraitsField;
    },

    /**
     * @returns {Ext.form.field.Text}
     */
    createImplementsInterfacesField: function () {
        this.implementsInterfacesField = Ext.create('Ext.form.field.Text', {
            width: 600,
            name: 'implementsInterfaces',
            fieldLabel: t('implements_interfaces'),
            value: this.data.implementsInterfaces,
            disabled: this.readOnly,
        });
        return this.implementsInterfacesField;
    },

    /**
     * @returns {Ext.form.field.Text}
     */
    createGroupField: function () {
        this.groupField = Ext.create('Ext.form.field.Text', {
            width: 600,
            name: 'group',
            fieldLabel: t('group'),
            value: this.data.group,
            disabled: this.readOnly,
        });
        return this.groupField;
    },

    /**
     * @returns {Ext.form.field.Text}
     */
    createAdminOnlyField: function () {
        this.adminOnlyField = Ext.create('Ext.form.field.Checkbox', {
            name: 'adminOnly',
            fieldLabel: t('admin_only'),
            value: this.data.adminOnly,
            checked: this.data.adminOnly,
            disabled: this.readOnly,
        });
        return this.adminOnlyField;
    },

    /**
     * @returns {Ext.grid.Panel}
     */
    createOptionsEditorGrid: function () {
        let valueStore = new Ext.data.Store({
            fields: [
                'label',
                {name: 'value', allowBlank: false},
                'name'
            ],
            proxy: {
                type: 'memory'
            },
            data: this.data.selectOptions,
        });

        let toolbarItems = [
            {
                xtype: 'tbtext',
                text: t('selection_options'),
            },
        ];
        let plugins = [];

        if (!this.readOnly) {
            toolbarItems.push(
                '-',
                {
                    xtype: 'button',
                    iconCls: 'pimcore_icon_add',
                    handler: function () {
                        var u = {
                            label: '',
                            value: ''
                        };

                        let selection = this.selectionModel.getSelection();
                        var idx;
                        if (selection.length > 0) {
                            let selectedRow = selection[0];
                            idx = valueStore.indexOf(selectedRow) + 1;
                        } else {
                            idx = valueStore.getCount();
                        }
                        valueStore.insert(idx, u);
                        this.selectionModel.select(idx);
                    }.bind(this),
                },
            );

            plugins.push(
                Ext.create('Ext.grid.plugin.CellEditing', {
                    clicksToEdit: 1,
                    listeners: {
                        edit: function (editor, e) {
                            if (!e.record.get('value')) {
                                e.record.set('value', e.record.get('label'));
                            }
                        },
                        beforeedit: function (editor, e) {
                            if (e.field === 'value') {
                                return !!e.value;
                            }
                            return true;
                        },
                        validateedit: function (editor, e) {
                            if (e.field !== 'value') {
                                return true;
                            }

                            // Iterate to all store data
                            for (var i = 0; i < valueStore.data.length; i++) {
                                var existingRecord = valueStore.getAt(i);
                                if (i != e.rowIdx && existingRecord.get('value') === e.value) {
                                    return false;
                                }
                            }
                            return true;
                        },
                    },
                })
            );
        }

        // Modified copy of the select field implementation
        this.optionsEditorGrid = Ext.create('Ext.grid.Panel', {
            viewConfig: {
                plugins: [
                    {
                        ptype: 'gridviewdragdrop',
                        dragroup: 'selectoptionsselect'
                    }
                ]
            },
            tbar: toolbarItems,
            style: 'margin-top: 10px',
            store: valueStore,
            selModel: Ext.create('Ext.selection.RowModel', {}),
            clicksToEdit: 1,
            columnLines: true,
            columns: this.createOptionsEditorGridColumns(),
            autoHeight: true,
            plugins: plugins,
        });

        this.selectionModel = this.optionsEditorGrid.getSelectionModel();
        return this.optionsEditorGrid;
    },

    createOptionsEditorGridColumns: function () {
        let columns = [
            {
                text: t('display_name'),
                sortable: true,
                dataIndex: 'label',
                editor: new Ext.form.TextField({}),
                renderer: function (value) {
                    return replace_html_event_attributes(strip_tags(value, 'div,span,b,strong,em,i,small,sup,sub'));
                },
                flex: 1
            },
            {
                text: t('value'),
                sortable: true,
                dataIndex: 'value',
                editor: new Ext.form.TextField({
                    allowBlank: false
                }),
                flex: 1
            },
            {
                text: t('name'),
                sortable: true,
                dataIndex: 'name',
                editor: {
                    xtype: 'textfield',
                    enableKeyEvents: true,
                    listeners: {
                        keyup: function (field) {
                            var value = field.getValue();
                            if (typeof value === 'string') {
                                // Only allow alphanumeric and underscore characters
                                field.setValue(
                                    value.replace(/[^A-Za-z0-9_]/g, '')
                                );
                            }
                        }
                    }
                },
                flex: 1
            },
        ];

        if (!this.readOnly) {
            columns.push(
                {
                    xtype: 'actioncolumn',
                    menuText: t('up'),
                    width: 40,
                    items: [
                        {
                            tooltip: t('up'),
                            icon: '/bundles/pimcoreadmin/img/flat-color-icons/up.svg',
                            handler: function (grid, rowIndex) {
                                if (rowIndex > 0) {
                                    var rec = grid.getStore().getAt(rowIndex);
                                    grid.getStore().removeAt(rowIndex);
                                    grid.getStore().insert(--rowIndex, [rec]);
                                    this.selectionModel.select(rowIndex);
                                }
                            }.bind(this)
                        }
                    ]
                },
                {
                    xtype: 'actioncolumn',
                    menuText: t('down'),
                    width: 40,
                    items: [
                        {
                            tooltip: t('down'),
                            icon: '/bundles/pimcoreadmin/img/flat-color-icons/down.svg',
                            handler: function (grid, rowIndex) {
                                if (rowIndex < (grid.getStore().getCount() - 1)) {
                                    var rec = grid.getStore().getAt(rowIndex);
                                    grid.getStore().removeAt(rowIndex);
                                    grid.getStore().insert(++rowIndex, [rec]);
                                    this.selectionModel.select(rowIndex);
                                }
                            }.bind(this)
                        }
                    ]
                },
                {
                    xtype: 'actioncolumn',
                    menuText: t('remove'),
                    width: 40,
                    items: [
                        {
                            tooltip: t('remove'),
                            icon: '/bundles/pimcoreadmin/img/flat-color-icons/delete.svg',
                            handler: function (grid, rowIndex) {
                                grid.getStore().removeAt(rowIndex);
                            }.bind(this)
                        }
                    ]
                }
            );
        }

        return columns;
    },

    /**
     * @returns {Ext.grid.GridPanel}
     */
    createUsagesGrid: function () {
        return Ext.create('Ext.grid.GridPanel', {
            frame: false,
            autoScroll: true,
            store: this.createUsagesStore(),
            columnLines: true,
            stripeRows: true,
            plugins: ['gridfilters'],
            width: 600,
            columns: [
                {text: t('class'), sortable: true, dataIndex: 'class', filter: 'string', flex: 1},
                {text: t('field'), sortable: true, dataIndex: 'field', filter: 'string', flex: 1}
            ],
            viewConfig: {
                forceFit: true
            }
        });
    },

    /**
     * @returns {Ext.data.ArrayStore}
     */
    createUsagesStore: function () {
        this.usagesStore = Ext.create('Ext.data.ArrayStore', {
            proxy: {
                url: Routing.generate('pimcore_admin_dataobject_class_getselectoptionsusages'),
                type: 'ajax',
                reader: {
                    type: 'json'
                },
                extraParams: {
                    id: this.data.id
                }
            },
            fields: ['class', 'field']
        });
        return this.usagesStore;
    },

    save: function (showSuccess = true) {
        let reloadTree = false;
        let newGroup = this.groupField.getValue();
        if (newGroup !== this.data.group) {
            this.data.group = newGroup;
            reloadTree = true;
        }

        // Reload if 'admin only' is enabled and user is not admin
        let reload = this.adminOnlyField.getValue() === true && this.data.adminOnly !== true && !pimcore.currentuser.admin;
        if (reload) {
            reloadTree = true;
        }

        let formData = this.getFormData();

        Ext.Ajax.request({
            url: Routing.generate('pimcore_admin_dataobject_class_selectoptionsupdate'),
            method: 'PUT',
            params: formData,
            success: showSuccess ? this.saveOnComplete.bind(this, reloadTree, reload) : null
        });
    },

    getFormData: function () {
        // Collect select options
        var selectOptions = [];
        var valueStore = this.optionsEditorGrid.getStore();
        valueStore.commitChanges();
        valueStore.each(function (record) {
            selectOptions.push({
                label: record.get('label'),
                value: record.get('value'),
                name: record.get('name')
            });
        });

        return {
            id: this.data.id,
            group: this.groupField.getValue(),
            adminOnly: this.adminOnlyField.getValue(),
            useTraits: this.useTraitsField.getValue(),
            implementsInterfaces: this.implementsInterfacesField.getValue(),
            selectOptions: Ext.encode(selectOptions)
        };
    },

    saveOnComplete: function (reloadTree, reload, response) {
        var rdata = Ext.decode(response.responseText);
        if (rdata && rdata.success) {
            if (reloadTree) {
                this.reloadTree();
            }
            if (reload) {
                this.reload();
            }
            pimcore.helpers.showNotification(t('success'), t('saved_successfully'), 'success');
            return;
        }

        if (rdata && rdata.message) {
            pimcore.helpers.showNotification(t('error'), rdata.message, 'error');
        } else {
            throw 'save was not successful, see log files in /var/log';
        }
    },

    reloadTree: function () {
        this.parentPanel.tree.getStore().load();
    },

    reload: function() {
        this.parentPanel.getEditPanel().remove(this.panel);
        this.reopen();
    }
});