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
 */
Ext.define('pimcore.object.helpers.ImageGalleryPanel', {
    extend: 'Ext.panel.Panel',

    requires: [
        'pimcore.object.helpers.ImageGalleryDropZone'
    ],

    cls: 'x-portal',
    // bodyCls: 'x-portal-body',

    manageHeight: true,

    initComponent : function() {
        // Implement a Container beforeLayout call from the layout to this Container
        this.layout = {
            type : 'column'
        };
        this.callParent();
    },

    // private
    initEvents : function(){
        this.callParent();
        if (!this.proxyConfig.noteditable) {
            this.dd = Ext.create('pimcore.object.helpers.ImageGalleryDropZone', this, {}, this.proxyConfig);
        }
    },

    // private
    beforeDestroy : function() {
        if (this.dd) {
            this.dd.unreg();
        }
        this.callParent();
    }
});
