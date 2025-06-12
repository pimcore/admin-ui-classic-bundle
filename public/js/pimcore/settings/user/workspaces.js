/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/


pimcore.registerNS("pimcore.settings.user.workspaces");
/**
 * @private
 */
pimcore.settings.user.workspaces = Class.create({

    initialize: function (userPanel) {
        this.userPanel = userPanel;
        this.data = this.userPanel.data;
    },

    getPanel: function () {


        this.asset = new pimcore.settings.user.workspace.asset(this);
        this.document = new pimcore.settings.user.workspace.document(this);
        this.object = new pimcore.settings.user.workspace.object(this);

        this.panel = new Ext.Panel({
            title: t("workspaces"),
            bodyStyle: "padding:10px;",
            autoScroll: true,
            items: [this.document.getPanel(), this.asset.getPanel(), this.object.getPanel()]
        });

        return this.panel;
    },

    disable: function () {
        this.panel.disable();
    },

    enable: function () {
        this.panel.enable();
    },

    getValues: function () {
        return {
            asset: this.asset.getValues(),
            object: this.object.getValues(),
            document: this.document.getValues()
        };
    }

});