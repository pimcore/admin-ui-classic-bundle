/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @category   Pimcore
 * @package    Object
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PCL
 */


pimcore.registerNS("pimcore.object.gridcolumn.Abstract");
/**
 * @private
 */
pimcore.object.gridcolumn.Abstract = Class.create({
    type: null,
    class: null,
    objectClassId: null,
    allowedTypes: null,
    allowedParents: null,
    maxChildCount: null,

    initialize: function(classId) {
        this.objectClassId = classId;
    },

    getDefaultText: function () {
        return t(this.type + "_" + this.defaultText, t('operator') + " " + this.defaultText);
    },

    getConfigTreeNode: function(configAttributes) {
        return {};
    },


    getCopyNode: function(source) {
        var copy = new Ext.tree.TreeNode({
            text: source.data.text,
            isTarget: true,
            leaf: true,
            configAttributes: {
                label: null,
                type: this.type,
                class: this.class
            }
        });
        return copy;
    },


    getConfigDialog: function(node, params) {
    },

    commitData: function() {
        this.window.close();
    }
});