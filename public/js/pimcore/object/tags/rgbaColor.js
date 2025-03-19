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

pimcore.registerNS("pimcore.object.tags.rgbaColor");
/**
 * @private
 */
pimcore.object.tags.rgbaColor = Class.create(pimcore.object.tags.abstract, {

    type: "rgbaColor",

    initialize: function (data, fieldConfig) {
        this.data = null;

        if (data) {
            this.data = data;
        }

        this.fieldConfig = fieldConfig;
    },

    getGridColumnConfig: function (field) {
        return {
            text: t(field.label),
            width: 120,
            dataIndex: field.key,
            sortable: true,
            getEditor: this.getWindowCellEditor.bind(this, field),
            renderer: function (key, value, metaData, record) {
                this.applyPermissionStyle(key, value, metaData, record);

                if (record.data.inheritedFields?.[key]?.inherited) {
                    metaData.tdCls += " grid_value_inherited";
                }

                if (value) {
                    return '<div style="float: left;"><div style="float: left; margin-right: 5px; background-image: ' + ' url(/bundles/pimcoreadmin/img/ext/colorpicker/checkerboard.png);">'
                        + '<div style="background-color: ' + value + '; width:15px; height:15px;"></div></div>' + value + '</div>';
                }

            }.bind(this, field.key)
        };
    },

    getCellEditValue: function () {
        return this.getValue();
    },

    getGridColumnEditor: function (field) {
        if (field.layout.noteditable) {
            return null;
        }

        const editorConfig = this.initEditorConfig(field);

        return new Ext.form.TextField(editorConfig);
    },

    getGridColumnFilter: function (field) {
        return {type: 'string', dataIndex: field.key};
    },

    getLayoutEdit: function () {
        const labelWidth = this.fieldConfig.labelWidth ? this.fieldConfig.labelWidth : 100;
        let width = this.fieldConfig.width ? this.fieldConfig.width : 400;
        if (!this.fieldConfig.labelAlign || 'left' === this.fieldConfig.labelAlign) {
            width = this.sumWidths(width, labelWidth);
        }

        this.selector = new Ext.ux.colorpick.Selector({
            showPreviousColor: true,
            hidden: true,
            bind: {
                value: '{color}',
                visible: '{full}'
            }
        });

        const colorConfig = {
            flex: 1,
            format: '#hex8',
            isNull: !this.data,
            hidden: true,
            bind: '{color}'
        };

        if (this.data) {
            colorConfig["value"] = this.data;
        }

        this.colorField = Ext.create(
            'pimcore.colorpick.Field',
            colorConfig
        );

        const compositeCfg = {
            viewModel: {
                data: {
                    color: this.data ? this.data : 'FFFFFFFF'
                }
            },
            fieldLabel: this.fieldConfig.title,
            labelWidth: labelWidth,
            layout: 'hbox',
            width: width,
            items: [
                this.colorField,
                this.selector,
                {
                    xtype: 'button',
                    iconCls: 'pimcore_icon_delete',
                    style: 'margin-left: 5px',
                    handler: this.empty.bind(this),
                }
            ],
            componentCls: this.getWrapperClassNames(),
            border: false,
            style: {
                padding: 0
            }
        };

        if (this.fieldConfig.labelAlign) {
            compositeCfg.labelAlign = this.fieldConfig.labelAlign;
        }

        this.colorField.setVisible(true);
        this.component = Ext.create('Ext.form.FieldContainer', compositeCfg);

        return this.component;
    },

    empty: function () {
        this.colorField.setIsNull(true);
        this.component.getViewModel().set('color', "FFFFFFFF");
    },

    getLayoutShow: function () {
        this.component = this.getLayoutEdit();
        this.component.disable();

        return this.component;
    },

    getValue: function () {
        const viewModel = this.component.getViewModel();
        const isNull = this.colorField.getIsNull();
        if (isNull) {
            return null;
        }

        return viewModel.get("color");
    },

    getName: function () {
        return this.fieldConfig.name;
    },

    isDirty: function () {
        return this.getValue() != this.data;
    }
});
