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

pimcore.registerNS("pimcore.object.tags.booleanSelect");
/**
 * @private
 */
pimcore.object.tags.booleanSelect = Class.create(pimcore.object.tags.abstract, {

    type: "booleanSelect",

    initialize: function (data, fieldConfig) {
        this.data = data;
        this.fieldConfig = fieldConfig;

    },

    getGridColumnConfig:function (field) {
        var renderer = function (key, value, metaData, record) {
            this.applyPermissionStyle(key, value, metaData, record);

            if (record.data.inheritedFields && record.data.inheritedFields[key] && record.data.inheritedFields[key].inherited == true) {
                try {
                    metaData.tdCls += " grid_value_inherited";
                } catch (e) {
                    console.log(e);
                }
            }

            if (field.layout.options !== undefined) {
                for (var i = 0; i < field.layout.options.length; i++) {
                    if (field.layout.options[i]["value"] == value) {
                        return field.layout.options[i]["key"];
                    }
                }
            }

            return Ext.util.Format.htmlEncode(value);

        }.bind(this, field.key);

        return {
            text: t(field.label), sortable: true, dataIndex: field.key, renderer: renderer,
            editor: this.getGridColumnEditor(field)
        };

    },

    getCellEditor: function (field, record) {
        if (field.layout.noteditable) {
            return null;
        }

        const key = field.key;
        const value = record.data[key];
        const options = record.data[key +  "%options"];

        const store = new Ext.data.Store({
            autoDestroy: true,
            fields: ['key',"value"],
            data: options
        });

        let editorConfig = this.initEditorConfig(field);

        editorConfig = Object.assign(editorConfig, {
            store: store,
            triggerAction: "all",
            editable: false,
            mode: "local",
            valueField: 'value',
            displayField: 'key',
            value: value
        });

        return new Ext.form.ComboBox(editorConfig);
    },

    getGridColumnEditor: function(field) {
        if (field.layout.noteditable) {
            return null;
        }

        const store = new Ext.data.JsonStore({
            autoDestroy: true,
            proxy: {
                type: 'memory',
                reader: {
                    type: 'json',
                    rootProperty: 'options'

                }
            },
            fields: ['key',"value"],
            data: field.layout
        });

        let editorConfig = this.initEditorConfig(field);

        editorConfig = Object.assign(editorConfig, {
            store: store,
            triggerAction: 'all',
            editable: false,
            mode: 'local',
            valueField: 'value',
            displayField: 'key'
        });

        return new Ext.form.ComboBox(editorConfig);
    },

    getGridColumnFilter: function(field) {
        if (field.layout.dynamicOptions) {
            return {type: 'string', dataIndex: field.key};
        } else {
            var store = Ext.create('Ext.data.JsonStore', {
                fields: ['key', "value"],
                data: field.layout.options
            });

            return {
                type: 'list',
                dataIndex: field.key,
                labelField: "key",
                idField: "value",
                options: store
            };
        }
    },

    getLayoutEdit: function () {
        // generate store
        var store = [];
        var validValues = [];

        if (this.fieldConfig.options) {
            for (var i = 0; i < this.fieldConfig.options.length; i++) {
                var value = this.fieldConfig.options[i].value;
                store.push([value, t(this.fieldConfig.options[i].key)]);
                validValues.push(value);
            }
        }

        var options = {
            name: this.fieldConfig.name,
            triggerAction: "all",
            editable: true,
            typeAhead: true,
            forceSelection: true,
            selectOnFocus: true,
            fieldLabel: this.fieldConfig.title,
            store: store,
            componentCls: this.getWrapperClassNames(),
            width: 250,
            labelWidth: 100
        };

        if (this.fieldConfig.labelWidth) {
            options.labelWidth = this.fieldConfig.labelWidth;
        }

        if (this.fieldConfig.labelAlign) {
            options.labelAlign = this.fieldConfig.labelAlign;
        }

        if (this.fieldConfig.width) {
            options.width = this.fieldConfig.width;
        }

        if (!this.fieldConfig.labelAlign || 'left' === this.fieldConfig.labelAlign) {
            options.width = this.sumWidths(options.width, options.labelWidth);
        }

        if (typeof this.data == "string" || typeof this.data == "number") {
            if (in_array(this.data, validValues)) {
                options.value = this.data;
            } else {
                options.value = "";
            }
        } else {
            options.value = "";
        }

        this.component = new Ext.form.ComboBox(options);

        return this.component;
    },


    getLayoutShow: function () {

        this.component = this.getLayoutEdit();
        this.component.disable();

        return this.component;
    },

    getValue:function () {
        if (this.isRendered()) {
            return this.component.getValue();
        }
        return this.data;
    },


    getName: function () {
        return this.fieldConfig.name;
    },

    isDirty:function () {
        var dirty = false;

        if (this.component && typeof this.component.isDirty == "function") {
            if (this.component.rendered) {
                dirty = this.component.isDirty();

                // once a field is dirty it should be always dirty (not an ExtJS behavior)
                if (this.component["__pimcore_dirty"]) {
                    dirty = true;
                }
                if (dirty) {
                    this.component["__pimcore_dirty"] = true;
                }

                return dirty;
            }
        }

        return false;
    }

});
