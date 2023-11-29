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

pimcore.registerNS("pimcore.asset.gridexport.xlsx");
/**
 * @private
 */
pimcore.asset.gridexport.xlsx = Class.create(pimcore.element.gridexport.abstract, {
    name: "xlsx",
    text: t("export_xlsx"),
    warningText: t('asset_export_warning'),

    getDownloadUrl: function(fileHandle) {
         return Routing.generate('pimcore_admin_asset_assethelper_downloadxlsxfile', {fileHandle: fileHandle});
    },

    getExportSettingsContainer: function () {
        return new Ext.form.FieldSet({
            title: t('export_xlsx'),
            items: [
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
    }
});

pimcore.globalmanager.get("pimcore.asset.gridexport").push(new pimcore.asset.gridexport.xlsx());
