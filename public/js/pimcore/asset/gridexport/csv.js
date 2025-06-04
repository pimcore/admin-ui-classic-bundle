/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.asset.gridexport.csv");
/**
 * @private
 */
pimcore.asset.gridexport.csv = Class.create(pimcore.element.gridexport.abstract, {
    name: "csv",
    text: t("export_csv"),
    warningText: t('asset_export_warning'),

    getExportSettingsContainer: function () {
        return new Ext.form.FieldSet({
            title: t('csv_settings'),
            items: [
                new Ext.form.TextField({
                    fieldLabel: t('delimiter'),
                    name: 'delimiter',
                    maxLength: 1,
                    labelWidth: 200,
                    value: ';',
                    allowBlank: false
                }),
                new Ext.form.ComboBox({
                    fieldLabel: t('header'),
                    name: 'header',
                    store: [
                        ['name', t('system_key')],
                        ['title', t('label')],
                        ['no_header', t('no_header')]
                    ],
                    labelWidth: 200,
                    value: 'title',
                    forceSelection: true,
                })
            ]
        });
    },

    getDownloadUrl: function(fileHandle) {
         return Routing.generate('pimcore_admin_asset_assethelper_downloadcsvfile', {fileHandle: fileHandle});
    }
});

pimcore.globalmanager.get("pimcore.asset.gridexport").push(new pimcore.asset.gridexport.csv());
