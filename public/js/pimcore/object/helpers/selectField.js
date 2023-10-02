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

pimcore.registerNS('pimcore.object.helpers.selectField');

/**
 * @private
 */
pimcore.object.helpers.selectField = {
    OPTIONS_PROVIDER_TYPE_CONFIGURE: 'configure',
    OPTIONS_PROVIDER_TYPE_SELECT_OPTIONS: 'select_options',
    OPTIONS_PROVIDER_TYPE_CLASS: 'class',

    selectOptionsStore: null,

    /**
     * @param {Object} datax
     * @param {Ext.grid.Panel} valueGrid
     * @returns {[
     *     Ext.form.field.ComboBox,
     *     Ext.form.field.Text,
     *     Ext.form.field.Text,
     *     Ext.form.field.ComboBox
     *     ]}
     */
    getOptionsProviderFields: function (datax, valueGrid) {
        var selectOptionsSelector = Ext.create('Ext.form.field.ComboBox', {
            fieldLabel: t('selectoptions'),
            emptyText: '',
            value: datax.optionsProviderData,
            hidden: true,
            valueField: 'id',
            displayField: 'text',
            editable: false,
            forceSelection: true,
            queryMode: 'local',
            store: this.getSelectOptionsStore(),
            listeners: {
                change: function (comboBox, newValue) {
                    optionsProviderClass.setValue(pimcore.settings.select_options_provider_class);
                    optionsProviderData.setValue(newValue);
                }
            }
        });

        var optionsProviderClass = Ext.create('Ext.form.field.Text', {
            fieldLabel: t('options_provider_class'),
            width: 600,
            name: 'optionsProviderClass',
            hidden: true,
            value: datax.optionsProviderClass
        });

        var optionsProviderData = Ext.create('Ext.form.field.Text', {
            fieldLabel: t('options_provider_data'),
            width: 600,
            value: datax.optionsProviderData,
            hidden: true,
            name: 'optionsProviderData'
        });

        var toggleFields = function (optionsProviderType) {
            switch (optionsProviderType) {
                case this.OPTIONS_PROVIDER_TYPE_SELECT_OPTIONS:
                    optionsProviderClass.hide();
                    optionsProviderData.hide();
                    selectOptionsSelector.show();
                    valueGrid.hide();
                    break;
                case this.OPTIONS_PROVIDER_TYPE_CLASS:
                    optionsProviderClass.show();
                    optionsProviderData.show();
                    selectOptionsSelector.hide();
                    valueGrid.hide();
                    break;
                // Configure
                default:
                    optionsProviderClass.hide();
                    optionsProviderData.hide();
                    selectOptionsSelector.hide();
                    valueGrid.show();
            }
        }.bind(this)

        var typeValue = this.OPTIONS_PROVIDER_TYPE_CONFIGURE;
        if (datax.optionsProviderType) {
            typeValue = datax.optionsProviderType;
            // Legacy fallback in case no type is set and a class/service is configured
        } else if (datax.optionsProviderClass) {
            typeValue = this.OPTIONS_PROVIDER_TYPE_CLASS;
        }

        toggleFields(typeValue);

        var optionsProviderType = Ext.create('Ext.form.field.ComboBox', {
            name: 'optionsProviderType',
            fieldLabel: t('options_provider_type'),
            value: typeValue,
            valueField: 'value',
            displayField: 'label',
            editable: false,
            forceSelection: true,
            queryMode: 'local',
            store: Ext.create('Ext.data.Store', {
                fields: ['value', 'label'],
                data: [
                    {value: this.OPTIONS_PROVIDER_TYPE_CONFIGURE, label: t('options_provider_type_configure')},
                    {value: this.OPTIONS_PROVIDER_TYPE_SELECT_OPTIONS, label: t('options_provider_type_select_options')},
                    {value: this.OPTIONS_PROVIDER_TYPE_CLASS, label: t('options_provider_type_class')}
                ]
            }),
            listeners: {
                change: function (comboBox, newValue) {
                    toggleFields(newValue);
                }
            }
        });

        return [
            optionsProviderType,
            optionsProviderClass,
            optionsProviderData,
            selectOptionsSelector
        ];
    },

    /**
     * @returns {Ext.data.JsonStore}
     */
    getSelectOptionsStore: function () {
        if (this.selectOptionsStore === null) {
            this.selectOptionsStore = Ext.create('Ext.data.JsonStore', {
                fields: [
                    {name: 'id'},
                    {name: 'text'}
                ],
                autoLoad: true,
                proxy: {
                    type: 'ajax',
                    url: Routing.generate('pimcore_admin_dataobject_class_selectoptionstree'),
                    reader: {
                        type: 'json'
                    },
                    extraParams: {
                        grouped: 0
                    }
                },
            });
        }

        return this.selectOptionsStore;
    }
};