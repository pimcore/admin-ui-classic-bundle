/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.document.snippets.settings");
/**
 * @private
 */
pimcore.document.snippets.settings = Class.create(pimcore.document.settings_abstract, {

    getLayout: function () {

        if (this.layout == null) {

            this.layout = new Ext.FormPanel({
                title: t('settings'),
                border: false,
                autoScroll: true,
                bodyStyle:'padding:0 10px 0 10px;',
                iconCls: "pimcore_material_icon_settings pimcore_material_icon",
                items: [
                    this.getControllerViewFields(),
                    this.getPathAndKeyFields(),
                    this.getContentMainFields()
                ]
            });
        }

        return this.layout;
    }

});
