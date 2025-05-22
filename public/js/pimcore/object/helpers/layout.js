/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/



pimcore.registerNS("pimcore.object.helpers.layout");
/**
 * @private
 */
pimcore.object.helpers.layout = {

    /**
     * specify which children a layout can have
     * @param source
    */
    getRawAllowedTypes : function () {
        return {
            accordion: ["panel", "region", "tabpanel", "text", "iframe"],
            fieldset: ["data", "text", "iframe"],
            fieldcontainer: ["data", "text", "iframe"],
            panel: ["data", "region", "tabpanel", "button", "accordion", "fieldset", "fieldcontainer", "panel", "text", "html", "iframe"],
            region: ["panel", "accordion", "tabpanel", "text", "localizedfields", "iframe"],
            tabpanel: ["panel", "region", "accordion", "text", "localizedfields", "iframe", "tabpabel"],
            button: [],
            text: [],
            root: ["panel", "region", "tabpanel", "accordion", "text", "iframe", "button", "fieldcontainer", "fieldset"],
            localizedfields: ["data", "panel", "tabpanel", "accordion", "fieldset", "fieldcontainer", "text", "region", "button", "iframe"],
            block: ["data", "panel", "tabpanel", "accordion", "fieldset", "fieldcontainer", "text", "region", "button", "iframe"]
        };
    },

    getAllowedTypes : function (source) {
        const allowedTypes = this.getRawAllowedTypes();

        const prepareClassLayoutContextMenu = new CustomEvent(pimcore.events.prepareClassLayoutContextMenu, {
            detail: {
                allowedTypes: allowedTypes,
                source: source
            }
        });

        document.dispatchEvent(prepareClassLayoutContextMenu);

        return allowedTypes;
    }
};
