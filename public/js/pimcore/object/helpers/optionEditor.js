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
 * NOTE: This helper-methods are added to the classes pimcore.object.edit, pimcore.object.fieldcollection,
 * pimcore.object.tags.localizedfields
 */

pimcore.registerNS("pimcore.object.helpers.optionEditor");
/**
 * @private
 */
pimcore.object.helpers.optionEditor = Class.create({

    initialize: function (
        store,
        columnNames = [
            'key',
            'value',
        ]
    ) {
        this.store = store;
        this.columnNames = columnNames;
    },

    edit: function() {
        let displayField = {
            xtype: "displayfield",
            region: "north",
            hideLabel: true,
            value: t('csv_seperated_options_info')
        };

        let data = [];
        this.store.each(function (record) {
            // Map column value to row data
            let row = [];
            this.iterateColumnNames(function (columnName) {
                row.push(record.get(columnName));
            });
            data.push(row);
        }.bind(this));

        data = Ext.util.CSV.encode(data);

        this.textarea = new Ext.form.TextArea({
            region: "center",
            value: data
        });

        this.configPanel = new Ext.Panel({
            layout: "border",
            padding: 20,
            items: [displayField, this.textarea]
        });

        this.window = new Ext.Window({
            width: 800,
            height: 500,
            title: t('csv_seperated_options'),
            iconCls: "pimcore_icon_edit",
            layout: "fit",
            closeAction:'close',
            plain: true,
            maximized: false,
            modal: true,
            buttons: [
                {
                    text: t('apply'),
                    iconCls: "pimcore_icon_save",
                    handler: function(){
                        this.store.removeAll();
                        let content = this.textarea.getValue();
                        if (content.length > 0) {
                            let csvData = Ext.util.CSV.decode(content);

                            for (let i = 0;i < csvData.length;i++) {
                                let row = csvData[i];

                                // Set value with key if empty
                                if (!row[1]) {
                                    row[1] = row[0];
                                }

                                // Map row data to column value
                                let record = {};
                                this.iterateColumnNames(function (columnName, index) {
                                    record[columnName] = row[index];
                                });

                                this.store.add(record);
                            }
                        }

                        this.window.hide();
                        this.window.destroy();
                    }.bind(this)
                },
                {
                    text: t('cancel'),
                    iconCls: "pimcore_icon_empty",
                    handler: function(){
                        this.window.hide();
                        this.window.destroy();
                    }.bind(this)
                }
            ]
        });

        this.window.add(this.configPanel);
        this.window.show();
    },

    iterateColumnNames: function (callable) {
        let columnName;
        for (let i = 0; i < this.columnNames.length; i++) {
            columnName = this.columnNames[i];
            callable(columnName, i);
        }
    }
});
