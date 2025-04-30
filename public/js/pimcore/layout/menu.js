/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

/**
 * @private
 * Adding a priority sorting function for menus
 */
Ext.define('pimcore.menu.menu', {
    extend: 'Ext.menu.Menu',

    initComponent: function() {

        let me = this;
        let items = me.items;

        if(items) {
            me.items = Ext.Array.sort(items, pimcore.helpers.priorityCompare);
        }

        me.callParent();
    }
});

