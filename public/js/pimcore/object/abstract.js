/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.object.abstract");
/**
 * @private
 */
pimcore.object.abstract = Class.create(pimcore.element.abstract, {

    selectInTree: function (type, button) {

        if(type != "variant" || this.data.general.showVariants) {
            try {
                pimcore.treenodelocator.showInTree(this.id, "object", button)
            } catch (e) {
                console.log(e);
            }
        }
    }
});