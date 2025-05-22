/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.iconlibrary");

/**
 * @private
 */
pimcore.iconlibrary = {

    panel: Class.create({

        initialize: function () {
            this.getTabPanel();
        },

        activate: function () {
            const tabPanel = Ext.getCmp("pimcore_panel_tabs");
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
                });

                pimcore.layout.refresh();
            }

            return this.panel;
        }
    }),

    // needed for compatibility reasons when the icon library is loaded as iframe panel
    replaceCurrentTabWithIconLibrary: function () {
        var tabpanel = Ext.getCmp("pimcore_panel_tabs");
        var activeTab = tabpanel.getActiveTab();

        if (activeTab) {
            activeTab.close();
        }

        pimcore.globalmanager.get("layout_toolbar").showIconLibrary()
    },

    createIconSelectionWidget: function (value,  classId, fieldName = 'icon', width = 396, labelWidth = 200) {
        const iconCss = ' left center no-repeat; text-indent: 20px';

        const iconTypes = Ext.create('Ext.data.Store', {
            fields: ['text', 'value'],
            data: [
                { "text": t('color_icons'), "value": 'color' },
                { "text": t('white_icons'), "value": 'white' },
                { "text": t('twemoji') + ' (1/3)', "value": 'twemoji-1' },
                { "text": t('twemoji') + ' (2/3)', "value": 'twemoji-2' },
                { "text": t('twemoji') + ' (3/3)', "value": 'twemoji-3' },
                { "text": t('twemoji_variants') + ' (1/3)', "value": 'twemoji_variants-1' },
                { "text": t('twemoji_variants') + ' (2/3)', "value": 'twemoji_variants-2' },
                { "text": t('twemoji_variants') + ' (3/3)', "value": 'twemoji_variants-3' },
            ]
        });

        const iconTypeBox = Ext.create('Ext.form.ComboBox', {
            store: iconTypes,
            width: 180,
            displayField: 'text',
            valueField: 'value',
            emptyText: t('type'),
            listeners: {
                select: function (classId, elem) {
                    iconStore.proxy.extraParams = {
                        'type' : elem.value,
                        classId: classId,
                    };
                    iconStore.load();
                }.bind(this, classId)
            }
        });

        const iconStore = new Ext.data.ArrayStore({
            proxy: {
                url: Routing.generate('pimcore_admin_dataobject_class_geticons'),
                type: 'ajax',
                reader: {
                    type: 'json'
                },
                extraParams: {
                    classId: classId,
                    type: ''
                }
            },
            fields: ["text", "value"]
        });

        const iconField = new Ext.form.field.Text({
            name: fieldName,
            width: width,
            renderer: Ext.util.Format.htmlEncode,
            value: value,
            listeners: {
                "afterrender": function (el) {
                    el.inputEl.applyStyles("background:url(" + el.getValue() + ")" + iconCss);
                }.bind(this)
            }
        });

        return Ext.create('Ext.form.FieldContainer', {
            layout: 'vbox',
            items: [
                {
                    xtype: "fieldcontainer",
                    layout: "hbox",
                    fieldLabel: t("icon"),
                    labelWidth: labelWidth,
                    items: [
                        iconField
                    ]
                },
                {
                    xtype: "fieldcontainer",
                    layout: "hbox",
                    fieldLabel: t("icon_tools"),
                    labelWidth: labelWidth,
                    items: [
                        iconTypeBox,
                        {
                            xtype: "combobox",
                            store: iconStore,
                            width: 75,
                            valueField: 'value',
                            displayField: 'text',
                            emptyText: t('select_type_first'),
                            listeners: {
                                select: function (iconField, iconCss, ele, rec, idx) {
                                    const newValue = rec.data.value;
                                    iconField.setValue(newValue);
                                    iconField.inputEl.applyStyles("background:url(" + newValue + ")" + iconCss);
                                    return newValue;
                                }.bind(this, iconField, iconCss)
                            }
                        },
                        {
                            iconCls: "pimcore_icon_refresh",
                            xtype: "button",
                            tooltip: t("refresh"),
                            handler: function (iconField, iconCss) {
                                iconField.inputEl.applyStyles("background:url(" + iconField.getValue() + ")" + iconCss);
                            }.bind(this, iconField, iconCss)
                        },
                        {
                            xtype: "button",
                            iconCls: "pimcore_icon_icons",
                            text: t('icon_library'),
                            handler: function () {
                                pimcore.globalmanager.get("layout_toolbar").showIconLibrary();
                            }
                        }
                    ]
                }
            ]
        });
    }
};






