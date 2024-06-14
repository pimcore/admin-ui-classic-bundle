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

pimcore.registerNS("pimcore.iconlibrary.panel");

/**
 * @private
 */
pimcore.iconlibrary.panel = Class.create({

    initialize: function () {
        this.getTabPanel();
    },

    activate: function () {
        var tabPanel = Ext.getCmp("pimcore_panel_tabs");
        tabPanel.setActiveItem("pimcore_iconlibrary_panel");
    },

    getTabPanel: function () {
        if (!this.panel) {
            const iconLibraryTab = Ext.create('Ext.tab.Panel', {
                region: 'center',
                deferredRender: true,
                id: "pimcore_icon_library_tabs",
                hideMode: "display",
                cls: "tab_panel",
                height: "100%",
                items: [
                    {
                        title: t('color_icons'),
                        html: '<iframe src="' + Routing.generate('pimcore_admin_misc_iconlist', {type: 'color'}) + '" frameborder="0" style="width:100%; height:100%" ></iframe>',
                    },
                    {
                        title: t('white_icons'),
                        html: '<iframe src="' + Routing.generate('pimcore_admin_misc_iconlist', {type: 'white'}) + '" frameborder="0" style="width:100%; height:100%" ></iframe>',
                    },
                    {
                        title: t('twemoji'),
                        html: '<iframe src="' + Routing.generate('pimcore_admin_misc_iconlist', {type: 'twemoji'}) + '" frameborder="0" style="width:100%; height:100%" ></iframe>',
                    },
                    {
                        title: t('flags'),
                        html: '<iframe src="' + Routing.generate('pimcore_admin_misc_iconlist', {type: 'flags'}) + '" frameborder="0" style="width:100%; height:100%" ></iframe>',
                    }
                ]
            });

            this.panel = new Ext.Panel({
                id: "pimcore_iconlibrary_panel",
                title: t("icon_library"),
                iconCls: "pimcore_icon_icons",
                border: false,
                layout: 'border',
                closable: true,
                items: [
                    iconLibraryTab
                ],
            });

            const tabPanel = Ext.getCmp("pimcore_panel_tabs");
            tabPanel.add(this.panel);


            this.panel.on("destroy", function () {
                pimcore.globalmanager.remove("iconlibrary");
            }.bind(this));

            pimcore.layout.refresh();
        }

        return this.panel;
    },
});



