/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.element.gridexport.abstract");
/**
 * @private
 */
pimcore.element.gridexport.abstract = Class.create({
    name: t('export'),
    text: t('export'),
    warningText: t('asset_export_warning'),

    getExportSettingsContainer: function () {
        return null;
    },
    getObjectSettingsContainer: function () {
        return null;
    },
});

pimcore.globalmanager.add("pimcore.asset.gridexport", []);
pimcore.globalmanager.add("pimcore.object.gridexport", []);
