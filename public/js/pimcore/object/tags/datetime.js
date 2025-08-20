/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.object.tags.datetime");
/**
 * @private
 */
pimcore.object.tags.datetime = Class.create(pimcore.object.tags.abstract, {

    type:"datetime",

    initialize:function (data, fieldConfig) {
        this.data = data;
        this.fieldConfig = fieldConfig;
    },

    applyDefaultValue: function() {
        if ((typeof this.data === "undefined" || this.data === null) && this.fieldConfig.defaultValue) {
            this.defaultValue = this.fieldConfig.defaultValue;
        } else if ((typeof this.data === "undefined" || this.data === null) && this.fieldConfig.useCurrentDate) {
            this.defaultValue = (new Date().getTime()) / 1000;
        }

        if (this.defaultValue) {
            this.data = this.defaultValue;
        }
    },

    getGridColumnConfig:function (field) {
        return {
            text: t(field.label),
            width:150,
            sortable:true,
            dataIndex:field.key,
            getEditor:this.getWindowCellEditor.bind(this, field),
            renderer:function (key, fieldConfig, value, metaData, record) {
                        this.applyPermissionStyle(key, value, metaData, record);

                        if (record.data.inheritedFields && record.data.inheritedFields[key] && record.data.inheritedFields[key].inherited == true) {
                            metaData.tdCls += " grid_value_inherited";
                        }

                        if (value) {
                            let date;
                            if (typeof value === "string" && value.match(/-/)) {
                                date = new Date(value);
                            } else {
                                let timestamp = intval(value) * 1000;
                                date = new Date(timestamp);

                                if (!this.isRespectTimezone(fieldConfig)) {
                                    date = dateToServerTimezone(date);
                                }
                            }
                            return Ext.Date.format(date, pimcore.globalmanager.get('localeDateTime').getShortDateTimeFormat());
                        }
                        return "";
                    }.bind(this, field.key, field.layout)};
    },

    getGridColumnFilter:function (field) {
        return {type:'date', dataIndex:field.key, dateFormat: field.layout.respectTimezone !== false ? "c" : "Y-m-d"};
    },

    getLayoutEdit:function () {

        var date = {
            width:130,
        };

        var time = {
            format:"H:i",
            emptyText:"",
            width:90
        };

        if (this.data) {
            var tmpDate = new Date(intval(this.data) * 1000);

            if (!this.isRespectTimezone()) {
                tmpDate = dateToServerTimezone(tmpDate);
            }

            date.value = tmpDate;
            time.value = tmpDate;
        }

        this.datefield = Ext.create('Ext.form.field.Date', date);
        this.timefield = Ext.create('Ext.form.field.Time', time);

        var componentCfg = {
            layout: 'hbox',
            fieldLabel:t(this.fieldConfig.title),
            combineErrors:false,
            items:[this.datefield, this.timefield],
            componentCls: this.getWrapperClassNames(),
            isDirty: function() {
                return this.datefield.isDirty() || this.timefield.isDirty()
            }.bind(this)
        };

        if (this.fieldConfig.labelWidth) {
            componentCfg.labelWidth = this.fieldConfig.labelWidth;
        }

        if (this.fieldConfig.labelAlign) {
            componentCfg.labelAlign = this.fieldConfig.labelAlign;
        }

        this.component = Ext.create('Ext.form.FieldContainer', componentCfg);

        return this.component;
    },

    getLayoutShow:function () {

        this.component = this.getLayoutEdit();

        this.component.addCls('x-form-readonly');
        this.datefield.setReadOnly(true);
        this.timefield.setReadOnly(true);

        return this.component;
    },

    getValue:function () {

        if (this.datefield.getValue()) {
            var value = this.datefield.getValue();
            var dateString = Ext.Date.format(value, pimcore.globalmanager.get('localeDateTime').getShortDateFormat());

            if (this.timefield.getValue()) {
                var timeValue = this.timefield.getValue();
                timeValue = Ext.Date.format(timeValue, "H:i");
                dateString += " " +  timeValue;
            }
            else {
                dateString += " 00:00";
            }

            value = Ext.Date.parseDate(dateString, pimcore.globalmanager.get('localeDateTime').getShortDateTimeFormat());

            if (value && this.fieldConfig.columnType === "datetime" && !this.isRespectTimezone()) {
                return dateString;
            }

            if (value && typeof value.getTime == "function") {
                return value.getTime();
            }

            return value;
        }
        return false;
    },

    getName:function () {
        return this.fieldConfig.name;
    },

    isDirty:function () {
        var dirty = false;

        if(this.defaultValue) {
            return true;
        }

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
    },

    getCellEditValue: function () {
        if (this.fieldConfig.columnType === "datetime" && !this.isRespectTimezone()) {
            return this.getValue();
        }
        return this.getValue() / 1000;
    },

    isRespectTimezone: function(fieldConfig) {
       fieldConfig = fieldConfig || this.fieldConfig;

       return fieldConfig && fieldConfig.respectTimezone !== false;
    }
});
