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

pimcore.registerNS("pimcore.object.tags.input");
/**
 * @private
 */
pimcore.object.tags.input = Class.create(pimcore.object.tags.abstract, {

    type: "input",

    initialize: function (data, fieldConfig) {


        this.data = null;

        if (data !== null && typeof data !== "undefined") {
            this.data = data;
        }
        this.fieldConfig = fieldConfig;
    },

    applyDefaultValue: function() {
        this.defaultValue = null;
        if ((typeof this.data === "undefined" || this.data === null) &&
            this.fieldConfig.defaultValue &&
            (this.context.type === "classificationstore" || this.context.containerType === "fieldcollection")
        ) {
            this.data = this.fieldConfig.defaultValue;
            this.defaultValue = this.fieldConfig.defaultValue;
        }
    },

    getGridColumnEditor: function(field) {
        if (field.layout.noteditable) {
            return null;
        }

        const editorConfig = this.initEditorConfig(field);

        return new Ext.form.TextField(editorConfig);
    },

    getGridColumnFilter: function(field) {
        return {type: 'string', dataIndex: field.key};
    },

    getLayoutEdit: function () {

        var input = {
            fieldLabel: this.fieldConfig.title,
            name: this.fieldConfig.name,
            labelWidth: 100,
            labelAlign: "left"
        };

        if (!this.fieldConfig.showCharCount) {
            input.componentCls = this.getWrapperClassNames();
        }

        if (this.data) {
            input.value = this.data;
        }

        if (this.fieldConfig.width) {
            input.width = this.fieldConfig.width;
        } else {
            input.width = 250;
        }

        if (this.fieldConfig.labelWidth) {
            input.labelWidth = this.fieldConfig.labelWidth;
        }

        if (this.fieldConfig.labelAlign) {
            input.labelAlign = this.fieldConfig.labelAlign;
        }

        if (!this.fieldConfig.labelAlign || 'left' === this.fieldConfig.labelAlign) {
            input.width = this.sumWidths(input.width, input.labelWidth);
        }

        if(this.fieldConfig.columnLength) {
            input.maxLength = this.fieldConfig.columnLength;
            input.enforceMaxLength = true;
        }

        if (this.fieldConfig["regex"]) {
            let regexFlags = implode('', this.fieldConfig["regexFlags"] ?? []);
            input.regex = new RegExp(this.fieldConfig.regex, regexFlags);
        }

        this.component = new Ext.form.TextField(input);

        if(this.fieldConfig.showCharCount) {
            var charCount = Ext.create("Ext.Panel", {
                bodyStyle: '',
                margin: '0 0 0 0',
                bodyCls: 'char_count',
                width: input.width,
                height: 17
            });

            this.component.setStyle("margin-bottom", "0");
            this.component.addListener("change", function(charCount) {
                this.updateCharCount(this.component, charCount);
            }.bind(this, charCount));

            //init word count
            this.updateCharCount(this.component, charCount);

            return Ext.create("Ext.Panel", {
                cls: "object_field object_field_type_" + this.type,
                style: "margin-bottom: 10px",
                layout: {
                    type: 'vbox',
                    align: 'left'
                },
                items: [
                    this.component,
                    charCount
                ]
            });

        } else {
            return this.component;
        }

    },

    updateCharCount: function(textField, charCount) {
        charCount.setHtml(textField.getValue().length + "/" + this.fieldConfig.columnLength);
    },


    getLayoutShow: function () {
        var layout = this.getLayoutEdit();
        this.component.setReadOnly(true);
        return layout;
    },

    getValue: function () {
        return this.component.getValue();
    },

    getName: function () {
        return this.fieldConfig.name;
    }
});
