/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.user");

pimcore.user = Class.create({

    initialize: function(object) {
        Object.assign(this, object);
    },

    isAllowed: function (type) {

        // @TODO: Should be removed when refactoring is finished
        if(this.admin) {
            return true;
        }

        if (typeof this.permissions == "object") {
            if(in_array(type,this.permissions)) {
                return true;
            }
        }
        return false;
    }
});
