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

/**
 * @private
 */
Ext.define('pimcore.element.helpers.gridCellEditor', {
    extend: 'Ext.grid.CellEditor',

    constructor: function(config) {
        this.callParent(arguments);
    },

    initComponent: function() {
        this.callParent();
    },

    startEdit: function(el, value, /* private: false means don't focus*/
                        doFocus) {


        Ext.WindowManager.each(function(window, idx, length) {
            window.destroy();

        });

        value = Ext.clone(value);

        const fieldInfo = Ext.clone(this.config.fieldInfo);
        const fieldType = this.config.elementType;

        //make sure that no relation data is loaded async
        fieldInfo.layout.optimizedAdminLoading = false;

        if (!fieldInfo?.layout) {
            return;
        }

        if (fieldInfo.layout.noteditable) {
            pimcore.helpers.showNotification(t('warning'), t('this_element_cannot_be_edited'), 'warning');
            return;
        }

        this.context = this.editingPlugin.context;

        const tagType = fieldInfo.layout.fieldtype;

        // translate title
        if(typeof fieldInfo.layout.title != "undefined") {
            fieldInfo.layout.title = t(fieldInfo.layout.title);
        }

        let tag;
        if (fieldType == "assetmetadata") {
            tag = new pimcore.asset.metadata.tags[tagType](value, fieldInfo.layout);
        } else {
            tag = new pimcore[fieldType].tags[tagType](value, fieldInfo.layout);
        }

        if(fieldType == 'object') {
            const object = Ext.clone(this.context.record);
            tag.setObject(object);
            tag.updateContext({
                objectId: object.id
            });
        }

        tag.updateContext({
            cellEditing: true,
            fieldname: fieldInfo.key
        });

        if (typeof tag["finishSetup"] !== "undefined") {
            tag.finishSetup();
        }

        const formPanel = Ext.create('Ext.form.Panel', {
            xtype: "form",
            border: false,
            items: [tag.getLayoutEdit()],
            bodyStyle: "padding: 10px;"
        });
        this.editWin = new Ext.Window({
            modal: false,
            title: t("edit") + " " + fieldInfo.layout.title,
            items: [formPanel],
            bodyStyle: "background: #fff;",
            width: 700,
            maxHeight: 600,
            autoScroll: true,
            preventRefocus: true,      // nasty hack because this is an internal property
                                       // for html grid cell values with hrefs this prevents that the cell
                                       // gets refocused which would then trigger another editor window
                                       // upon close of this instance
            listeners:{
                close:function(){
                    this.cancelEdit(false);
                }.bind(this)
            },
            buttons: [
                {
                    text: t("cancel"),
                    iconCls: 'pimcore_icon_cancel',
                    handler: function() {
                        this.cancelEdit(false);
                        this.editWin.close();
                    }.bind(this)
                },
                {
                    text: t("save"),
                    iconCls: 'pimcore_icon_save',
                    handler: function() {
                        if (typeof tag.isDirty === 'function' && tag.isDirty() === false) {
                            this.editWin.close();
                            return;
                        }
                        const newValue = tag.getCellEditValue();
                        this.setValue(newValue);
                        this.completeEdit(false);
                        this.editWin.close();
                    }.bind(this)
                }
            ]
        });
        this.editWin.show();
        this.editWin.updateLayout();


    },

    getValue: function() {
        return this.value;
    },

    setValue: function(value) {
        this.value = value;
    },

    completeEdit: function(remainVisible) {
        const me = this,
            fieldInfo = me.config.fieldInfo,
            startValue = me.startValue;
        let value;

        if (fieldInfo.layout.noteditable) {
            return;
        }

        value = me.getValue();

        if (me.fireEvent('beforecomplete', me, value, startValue) !== false) {
            // Grab the value again, may have changed in beforecomplete
            value = me.getValue();
            if (me.updateEl && me.boundEl) {
                me.boundEl.setHtml(value);
            }
            me.onEditComplete(remainVisible);
            me.fireEvent('complete', me, value, startValue);
        }
    },

    destroy: function() {
        if (this.editWin) {
            this.editWin.destroy();
        }
        this.callParent(arguments);
    }
});
