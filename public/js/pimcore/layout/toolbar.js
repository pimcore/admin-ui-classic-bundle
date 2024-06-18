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

pimcore.registerNS("pimcore.layout.toolbar");
/**
 * @private
 */
pimcore.layout.toolbar = Class.create({

     initialize: function() {
 
         var user = pimcore.globalmanager.get("user");
         this.toolbar = Ext.getCmp("pimcore_panel_toolbar");
 
         var perspectiveCfg = pimcore.globalmanager.get("perspective");

         var menu = {};

         if (perspectiveCfg.inToolbar("file")) {
             var fileItems = [];
 
             if (perspectiveCfg.inToolbar("file.perspectives")) {

                 if (pimcore.settings.availablePerspectives.length > 1) {

                     var items = [];
                     for (var i = 0; i < pimcore.settings.availablePerspectives.length; i++) {
                         var perspective = pimcore.settings.availablePerspectives[i];
                         var itemCfg = {
                             text: t(perspective.name),
                             disabled: perspective.active,
                             itemId: 'pimcore_menu_file_perspective_' + perspective.name.replace(/[^a-z0-9\-_]+/ig, '-'),
                             handler: this.openPerspective.bind(this, perspective.name)
                         };
 
                         if (perspective.icon) {
                             itemCfg.icon = perspective.icon;
                         } else if (perspective.iconCls) {
                             itemCfg.iconCls = perspective.iconCls;
                         }
 
                         items.push(itemCfg);
                     }
 
                     this.perspectivesMenu = {
                         text: t("perspectives"),
                         iconCls: "pimcore_nav_icon_perspective",
                         itemId: 'pimcore_menu_file_perspective',
                         hideOnClick: false,
                         menu: {
                             cls: "pimcore_navigation_flyout",
                             shadow: false,
                             items: items
                         }
                     };
                     fileItems.push(this.perspectivesMenu);
                 }
             }
 
 
             if (user.isAllowed("dashboards") && perspectiveCfg.inToolbar("file.dashboards")) {
                 this.dashboardMenu = {
                     text: t("dashboards"),
                     iconCls: "pimcore_nav_icon_dashboards",
                     itemId: 'pimcore_menu_file_dashboards',
                     hideOnClick: false,
                     menu: {
                         cls: "pimcore_navigation_flyout",
                         shadow: false,
                         items: [{
                             text: t("welcome"),
                             iconCls: "pimcore_nav_icon_dashboards",
                             itemId: 'pimcore_menu_file_dashboards_welcome',
                             handler: pimcore.helpers.openWelcomePage.bind(this)
                         }]
                     }
                 };
 
                 Ext.Ajax.request({
                     url: Routing.generate('pimcore_admin_portal_dashboardlist'),
                     success: function (response) {
                         var data = Ext.decode(response.responseText);
                         for (var i = 0; i < data.length; i++) {
                             this.dashboardMenu.menu.add({
                                 text: data[i],
                                 iconCls: "pimcore_nav_icon_dashboards",
                                 itemId: 'pimcore_menu_file_dashboards_custom_' + data[i],
                                 handler: function (key) {
                                     try {
                                         pimcore.globalmanager.get("layout_portal_" + key).activate();
                                     }
                                     catch (e) {
                                         pimcore.globalmanager.add("layout_portal_" + key, new pimcore.layout.portal(key));
                                     }
                                 }.bind(this, data[i])
                             });
                         }
 
                         this.dashboardMenu.menu.add(new Ext.menu.Separator({}));
                         this.dashboardMenu.menu.add({
                             text: t("add"),
                             iconCls: "pimcore_nav_icon_add",
                             itemId: 'pimcore_menu_file_dashboards_add',
                             handler: function () {
                                 var prompt = Ext.MessageBox.prompt(' ', t('enter_the_name_of_the_new_item'),
                                     function (button, value, object) {
                                         if (button == "ok") {
                                             Ext.Ajax.request({
                                                 url: Routing.generate('pimcore_admin_portal_createdashboard'),
                                                 method: 'POST',
                                                 params: {
                                                     key: value
                                                 },
                                                 success: function (response) {
                                                     var response = Ext.decode(response.responseText);
                                                     if (response.success) {
                                                         Ext.MessageBox.confirm(t("info"), t("reload_pimcore_changes"), function (buttonValue) {
                                                             if (buttonValue == "yes") {
                                                                 window.location.reload();
                                                             }
                                                         });
                                                         try {
                                                             pimcore.globalmanager.get("layout_portal_" + value).activate();
                                                         }
                                                         catch (e) {
                                                             pimcore.globalmanager.add("layout_portal_" + value, new pimcore.layout.portal(value));
                                                         }
                                                     } else {
                                                         Ext.Msg.show({
                                                             title: t("error"),
                                                             msg: t(response.message),
                                                             buttons: Ext.Msg.OK,
                                                             animEl: 'elId',
                                                             icon: Ext.MessageBox.ERROR
                                                         });
                                                     }
                                                 }
                                             });
                                         }
                                     }
                                 );
                                 prompt.textField.on('keyUp', function(el){
                                     el.setValue(el.getValue().replace(/\W/g, ''));
                                 }, this);
                             }.bind(this)
                         });
                     }.bind(this)
                 });
 
                 fileItems.push(this.dashboardMenu);
             }
 
 
             if (user.isAllowed("documents") && perspectiveCfg.inToolbar("file.openDocument")) {
                 fileItems.push({
                     text: t("open_document_by_id"),
                     iconCls: "pimcore_nav_icon_document pimcore_icon_overlay_go",
                     itemId: 'pimcore_menu_file_open_document_by_id',
                     handler: pimcore.helpers.openElementByIdDialog.bind(this, "document")
                 });
             }
 
             if (user.isAllowed("assets") && perspectiveCfg.inToolbar("file.openAsset")) {
                 fileItems.push({
                     text: t("open_asset_by_id"),
                     iconCls: "pimcore_nav_icon_asset pimcore_icon_overlay_go",
                     itemId: 'pimcore_menu_file_open_asset_by_id',
                     handler: pimcore.helpers.openElementByIdDialog.bind(this, "asset")
                 });
             }
 
             if (user.isAllowed("objects") && perspectiveCfg.inToolbar("file.openObject")) {
                 fileItems.push({
                     text: t("open_data_object"),
                     iconCls: "pimcore_nav_icon_object pimcore_icon_overlay_go",
                     itemId: 'pimcore_menu_file_open_data_object',
                     handler: pimcore.helpers.openElementByIdDialog.bind(this, "object")
                 });
             }
 
             if (perspectiveCfg.inToolbar("file.searchReplace") && (user.isAllowed("objects") || user.isAllowed("documents") || user.isAllowed("assets"))) {
                 fileItems.push({
                     text: t("search_replace_assignments"),
                     iconCls: "pimcore_nav_icon_search pimcore_icon_overlay_go",
                     itemId: 'pimcore_menu_file_search_replace_assigments',
                     handler: function () {
                         new pimcore.element.replace_assignments();
                     }
                 });
             }
 
             if (perspectiveCfg.inToolbar("file.schedule") && (user.isAllowed("objects") || user.isAllowed("documents") || user.isAllowed("assets"))) {
                 fileItems.push({
                     text: t('element_history'),
                     iconCls: "pimcore_nav_icon_history",
                     itemId: 'pimcore_menu_file_element_history',
                     cls: "pimcore_main_menu",
                     handler: this.showElementHistory.bind(this)
                 });
             }
 
             if (user.isAllowed("seemode") && perspectiveCfg.inToolbar("file.seemode")) {
                 fileItems.push({
                     text: t("seemode"),
                     iconCls: "pimcore_nav_icon_seemode",
                     itemId: 'pimcore_menu_file_seemode',
                     cls: "pimcore_main_menu",
                     handler: pimcore.helpers.openSeemode
                 });
             }
 
             if (perspectiveCfg.inToolbar("file.closeAll")) {
                 fileItems.push({
                     text: t("close_all_tabs"),
                     iconCls: "pimcore_nav_icon_close_all",
                     itemId: 'pimcore_menu_file_close_all_tabs',
                     handler: this.closeAllTabs
                 });
             }
 
             if (perspectiveCfg.inToolbar("file.help")) {
                 // link to docs as major.minor.x
                 var docsVersion = pimcore.settings.version.match(/^(\d+\.\d+)/);
                 if (docsVersion) {
                     docsVersion = docsVersion[0] + '.x';
                 } else {
                     docsVersion = 'latest';
                 }
 
                 fileItems.push({
                     text: t('help'),
                     iconCls: "pimcore_nav_icon_help",
                     itemId: 'pimcore_menu_file_help',
                     cls: "pimcore_main_menu",
                     hideOnClick: false,
                     menu: {
                         cls: "pimcore_navigation_flyout",
                         shadow: false,
                         items: [{
                             text: t("documentation"),
                             iconCls: "pimcore_nav_icon_documentation",
                             itemId: 'pimcore_menu_file_help_documentation',
                             handler: function () {
                                 window.open("https://pimcore.com/docs/" + docsVersion);
                             }
                         },
                             {
                                 text: t("report_bugs"),
                                 iconCls: "pimcore_nav_icon_github",
                                 itemId: 'pimcore_menu_file_help_report_bugs',
                                 handler: function () {
                                     window.open("https://github.com/pimcore/pimcore/issues");
                                 }
                             }
                         ]
                     }
                 });
             }
 
 
             if (perspectiveCfg.inToolbar("file.about")) {
                 fileItems.push({
                     text: t("about_pimcore") + " &reg;",
                     iconCls: "pimcore_nav_icon_pimcore",
                     itemId: 'pimcore_menu_file_about_pimcore',
                     handler: function () {
                         pimcore.helpers.showAbout();
                     }
                 });
             }

             menu.file = {
                 label: t('file'),
                 iconCls: 'pimcore_main_nav_icon_file',
                 items: fileItems,
                 shadow: false,
                 cls: "pimcore_navigation_flyout"
             };
         }
 
         if (perspectiveCfg.inToolbar("extras")) {
 
             var extrasItems = [];

             let translationItems = [];

             if (user.isAllowed("translations") && perspectiveCfg.inToolbar("extras.translations")) {
                 translationItems = [{
                     text: t("translations"),
                     iconCls: "pimcore_nav_icon_translations",
                     itemId: 'pimcore_menu_extras_translations_shared_translations',
                     handler: this.editTranslations.bind(this, 'messages'),
                     priority: 10
                 }];

                 extrasItems.push({
                     text: t("translations"),
                     iconCls: "pimcore_nav_icon_translations",
                     itemId: 'pimcore_menu_extras_translations',
                     hideOnClick: false,
                     menu: {
                         cls: "pimcore_navigation_flyout",
                         shadow: false,
                         items: translationItems
                     }
                 });
             }
 
             if (user.isAllowed("recyclebin") && perspectiveCfg.inToolbar("extras.recyclebin")) {
                 extrasItems.push({
                     text: t("recyclebin"),
                     iconCls: "pimcore_nav_icon_recyclebin",
                     itemId: 'pimcore_menu_extras_recyclebin',
                     handler: this.recyclebin
                 });
             }
 
             if (user.isAllowed("notes_events") && perspectiveCfg.inToolbar("extras.notesEvents")) {
                 extrasItems.push({
                     text: t('notes_events'),
                     iconCls: "pimcore_nav_icon_notes",
                     itemId: 'pimcore_menu_extras_notes',
                     handler: this.notes
                 });
             }

             if(user.isAllowed("gdpr_data_extractor")&& perspectiveCfg.inToolbar("extras.gdpr_data_extractor")) {
                 extrasItems.push({
                     text: t("gdpr_data_extractor"),
                     iconCls: "pimcore_nav_icon_gdpr",
                     itemId: 'pimcore_menu_extras_gdpr',
                     handler: function() {
                         new pimcore.settings.gdpr.gdprPanel();
                     }
                 });
             }
 
             if (extrasItems.length > 0) {
                 extrasItems.push("-");
             }
 
             if (user.isAllowed("emails") && perspectiveCfg.inToolbar("extras.emails")) {
                 extrasItems.push({
                     text: t("email"),
                     iconCls: "pimcore_nav_icon_email",
                     itemId: 'pimcore_menu_extras_email',
                     hideOnClick: false,
                     menu: {
                         cls: "pimcore_navigation_flyout",
                         shadow: false,
                         items: [{
                             text: t("email_logs"),
                             iconCls: "pimcore_nav_icon_email",
                             itemId: 'pimcore_menu_extras_email_logs',
                             handler: this.sentEmailsLog
                         }, {
                             text: t("email_blocklist"),
                             iconCls: "pimcore_nav_icon_email",
                             itemId: 'pimcore_menu_extras_email_blocklist',
                             handler: this.emailBlocklist
                         }, {
                             text: t("send_test_email"),
                             iconCls: "pimcore_nav_icon_email",
                             itemId: 'pimcore_menu_extras_mail_send_test_mail',
                             handler: this.sendTestEmail
                         }]
                     }
                 });
             }
 
             if (user.admin) {
                 if (perspectiveCfg.inToolbar("extras.maintenance")) {
                     extrasItems.push({
                         text: t("maintenance_mode"),
                         iconCls: "pimcore_nav_icon_maintenance",
                         itemId: 'pimcore_menu_extras_maintenance_mode',
                         handler: this.showMaintenance
                     });
                 }

                 var systemItems = [];

                 if (perspectiveCfg.inToolbar("extras.systemtools.requirements")) {
                     systemItems.push(
                         {
                             text: t("system_requirements_check"),
                             iconCls: "pimcore_nav_icon_systemrequirements",
                             itemId: 'pimcore_menu_extras_system_info_system_requirements_check',
                             handler: this.showSystemRequirementsCheck,
                             priority: 30
                         }
                     );
                 }

                 extrasItems.push({
                     text: t("system_infos_and_tools"),
                     iconCls: "pimcore_nav_icon_info",
                     hideOnClick: false,
                     itemId: 'pimcore_menu_extras_system_info',
                     menu: {
                         cls: "pimcore_navigation_flyout",
                         shadow: false,
                         items: systemItems
                     }
                 });
             }

             // adding menu even though extraItems can be empty
             // items can be added via event later
             menu.extras = {
                 label: t('tools'),
                 iconCls: 'pimcore_main_nav_icon_build',
                 items: extrasItems,
                 shadow: false,
                 cls: "pimcore_navigation_flyout"
             };
         }

         if (perspectiveCfg.inToolbar("marketing")) {
             // marketing menu
             var marketingItems = [];

             menu.marketing = {
                 label: t('marketing'),
                 iconCls: 'pimcore_main_nav_icon_marketing',
                 items: marketingItems,
                 shadow: false,
                 cls: "pimcore_navigation_flyout"
             };
         }
 
         if (perspectiveCfg.inToolbar("settings")) {
             // settings menu
             var settingsItems = [];
 
             if (user.isAllowed("document_types") && perspectiveCfg.inToolbar("settings.documentTypes")) {
                 settingsItems.push({
                     text: t("document_types"),
                     iconCls: "pimcore_nav_icon_doctypes",
                     itemId: 'pimcore_menu_settings_document_types',
                     handler: this.editDocumentTypes
                 });
             }
             if (user.isAllowed("predefined_properties") && perspectiveCfg.inToolbar("settings.predefinedProperties")) {
                 settingsItems.push({
                     text: t("predefined_properties"),
                     iconCls: "pimcore_nav_icon_properties",
                     itemId: 'pimcore_menu_settings_predefined_properties',
                     handler: this.editProperties
                 });
             }
 
             if (user.isAllowed("predefined_properties") && perspectiveCfg.inToolbar("settings.predefinedMetadata")) {
                 settingsItems.push({
                     text: t("predefined_asset_metadata"),
                     iconCls: "pimcore_nav_icon_metadata",
                     itemId: 'pimcore_menu_settings_predefined_asset_metadata',
                     handler: this.editPredefinedMetadata
                 });
             }
 
             if (user.isAllowed("system_settings") && perspectiveCfg.inToolbar("settings.system")) {
                 settingsItems.push({
                     text: t("system_settings"),
                     iconCls: "pimcore_nav_icon_system_settings",
                     itemId: 'pimcore_menu_settings_system_settings',
                     handler: this.systemSettings
                 });
             }

             if (user.isAllowed("system_appearance_settings") && perspectiveCfg.inToolbar("settings.appearance")) {
                 settingsItems.push({
                     text: t("appearance_and_branding"),
                     iconCls: "pimcore_nav_icon_frame",
                     itemId: 'pimcore_menu_settings_system_appearance',
                     handler: this.systemAppearanceSettings
                 });
             }
 
             if (user.isAllowed("website_settings") && perspectiveCfg.inToolbar("settings.website")) {
                 settingsItems.push({
                     text: t("website_settings"),
                     iconCls: "pimcore_nav_icon_website_settings",
                     itemId: 'pimcore_menu_settings_website_settings',
                     handler: this.websiteSettings
                 });
             }

             if (user.isAllowed("users") && perspectiveCfg.inToolbar("settings.users")) {
                 var userItems = [];
 
                 if (perspectiveCfg.inToolbar("settings.users.users")) {
                     userItems.push(
                         {
                             text: t("users"),
                             handler: this.editUsers,
                             iconCls: "pimcore_nav_icon_users",
                             itemId: 'pimcore_menu_settings_users_users',
                         }
                     );
                 }
 
                 if (perspectiveCfg.inToolbar("settings.users.roles")) {
                     userItems.push(
                         {
                             text: t("roles"),
                             handler: this.editRoles,
                             iconCls: "pimcore_nav_icon_roles",
                             itemId: 'pimcore_menu_settings_users_roles',
                         }
                     );
                 }
 
                 if (user.isAllowed("users")) {
                     userItems.push(
                         {
                             text: t("analyze_permissions"),
                             handler: function() {
                                 var checker = new pimcore.element.permissionchecker();
                                 checker.show();
                             }.bind(this),
                             iconCls: "pimcore_nav_icon_analyze_permissions",
                             itemId: 'pimcore_menu_settings_users_analyse_permissions',
                         }
                     );
                 }
 
                 if (userItems.length > 0) {
                     settingsItems.push({
                         text: t("users") + " / " + t("roles"),
                         iconCls: "pimcore_nav_icon_users",
                         itemId: 'pimcore_menu_settings_users',
                         hideOnClick: false,
                         menu: {
                             cls: "pimcore_navigation_flyout",
                             shadow: false,
                             items: userItems
                         }
                     });
                 }
             }
 
             if (user.isAllowed("thumbnails") && perspectiveCfg.inToolbar("settings.thumbnails")) {
                 settingsItems.push({
                     text: t("thumbnails"),
                     iconCls: "pimcore_nav_icon_thumbnails",
                     itemId: 'pimcore_menu_settings_thumbnails',
                     hideOnClick: false,
                     menu: {
                         cls: "pimcore_navigation_flyout",
                         shadow: false,
                         items: [{
                             text: t("image_thumbnails"),
                             iconCls: "pimcore_nav_icon_thumbnails",
                             itemId: 'pimcore_menu_settings_thumbnails_image',
                             handler: this.editThumbnails
                         }, {
                             text: t("video_thumbnails"),
                             iconCls: "pimcore_nav_icon_videothumbnails",
                             itemId: 'pimcore_menu_settings_thumbnails_video',
                             handler: this.editVideoThumbnails
                         }]
                     }
                 });
             }
 
             if (user.isAllowed("objects") && perspectiveCfg.inToolbar("settings.objects")) {
 
                 var objectMenu = {
                     text: t("data_objects"),
                     iconCls: "pimcore_nav_icon_object",
                     itemId: 'pimcore_menu_settings_data_objects',
                     hideOnClick: false,
                     menu: {
                         cls: "pimcore_navigation_flyout",
                         shadow: false,
                         items: []
                     }
                 };

                 if (perspectiveCfg.inToolbar("settings.objects.classes") && user.isAllowed("classes")) {
                     objectMenu.menu.items.push({
                         text: t("classes"),
                         iconCls: "pimcore_nav_icon_class",
                         itemId: 'pimcore_menu_settings_data_objects_classes',
                         handler: this.editClasses
                     });
                 }

                 if (perspectiveCfg.inToolbar("settings.objects.fieldcollections") && user.isAllowed("fieldcollections")) {
                     objectMenu.menu.items.push({
                         text: t("field_collections"),
                         iconCls: "pimcore_nav_icon_fieldcollection",
                         itemId: 'pimcore_menu_settings_data_objects_fieldcollections',
                         handler: this.editFieldcollections
                     });
                 }

                 if (perspectiveCfg.inToolbar("settings.objects.objectbricks") && user.isAllowed("objectbricks")) {
                     objectMenu.menu.items.push({
                         text: t("objectbricks"),
                         iconCls: "pimcore_nav_icon_objectbricks",
                         itemId: 'pimcore_menu_settings_data_objects_objectbricks',
                         handler: this.editObjectBricks
                     });
                 }

                 if (perspectiveCfg.inToolbar('settings.objects.selectoptions') && user.isAllowed('selectoptions')) {
                     objectMenu.menu.items.push({
                         text: t('selectoptions'),
                         iconCls: 'pimcore_nav_icon_selectoptions',
                         itemId: 'pimcore_menu_settings_data_objects_selectoptions',
                         handler: this.editSelectOptions
                     });
                 }

                 if (perspectiveCfg.inToolbar("settings.objects.quantityValue") && user.isAllowed("quantityValueUnits")) {
                     objectMenu.menu.items.push({
                         text: t("quantityValue_field"),
                         iconCls: "pimcore_nav_icon_quantityValue",
                         itemId: 'pimcore_menu_settings_data_objects_quantity_value',
                         cls: "pimcore_main_menu",
                         handler: function () {
                             try {
                                 pimcore.globalmanager.get("quantityValue_units").activate();
                             }
                             catch (e) {
                                 pimcore.globalmanager.add("quantityValue_units", new pimcore.object.quantityValue.unitsettings());
                             }
                         }
                     });
                 }

                 if (perspectiveCfg.inToolbar("settings.objects.classificationstore") && user.isAllowed("classificationstore")) {
                     objectMenu.menu.items.push({
                         text: t("classification_store"),
                         iconCls: "pimcore_nav_icon_classificationstore",
                         itemId: 'pimcore_menu_settings_data_objects_classificationstore',
                         handler: this.editClassificationStoreConfig
                     });
                 }

                 if (perspectiveCfg.inToolbar("settings.objects.bulkExport") && user.isAllowed("classes")) {
                     objectMenu.menu.items.push({
                         text: t("bulk_export"),
                         iconCls: "pimcore_nav_icon_export",
                         itemId: 'pimcore_menu_settings_data_objects_bulk_export',
                         handler: this.bulkExport
                     });
                 }

                 if (perspectiveCfg.inToolbar("settings.objects.bulkImport") && user.isAllowed("classes")) {
                     objectMenu.menu.items.push({
                         text: t("bulk_import"),
                         iconCls: "pimcore_nav_icon_import",
                         itemId: 'pimcore_menu_settings_data_objects_bulk_import',
                         handler: this.bulkImport.bind(this)
                     });
                 }


                 if (objectMenu.menu.items.length > 0) {
                     settingsItems.push(objectMenu);
                 }
             }

             if (perspectiveCfg.inToolbar("settings.cache") && (user.isAllowed("clear_cache") || user.isAllowed("clear_temp_files") || user.isAllowed("clear_fullpage_cache"))) {
 
                 var cacheItems = [];
                 var cacheSubItems = [];
 
                 if (user.isAllowed("clear_cache")) {
 
                     if (perspectiveCfg.inToolbar("settings.cache.clearAll")) {
                         cacheSubItems.push({
                             text: t("all_caches") + ' (Symfony + Data)',
                             iconCls: "pimcore_nav_icon_clear_cache",
                             itemId: 'pimcore_menu_settings_cache_all_caches',
                             handler: this.clearCache.bind(this, {'env[]': pimcore.settings['cached_environments']})
                         });
                     }
 
                     if (perspectiveCfg.inToolbar("settings.cache.clearData")) {
                         cacheSubItems.push({
                             text: t("data_cache"),
                             iconCls: "pimcore_nav_icon_clear_cache",
                             itemId: 'pimcore_menu_settings_cache_data_cache',
                             handler: this.clearCache.bind(this, {'only_pimcore_cache': true})
                         });
                     }
 
                     if (perspectiveCfg.inToolbar("settings.cache.clearSymfony")) {
 
                         pimcore.settings['cached_environments'].forEach(function(environment) {
                             cacheSubItems.push({
                                 text: 'Symfony ' + t('environment') + ": " + environment  + ' (' + t('deprecated') + ')',
                                 iconCls: "pimcore_nav_icon_clear_cache",
                                 itemId: 'pimcore_menu_settings_cache_symfony_' + environment,
                                 handler: this.clearCache.bind(this, {
                                     'only_symfony_cache': true,
                                     'env[]': environment
                                 })
                             });
                         }.bind(this));
 
                         cacheSubItems.push({
                             text: 'Symfony ' + t('environment') + ": " + t('all')  + ' (' + t('deprecated') + ')',
                             iconCls: "pimcore_nav_icon_clear_cache",
                             itemId: 'pimcore_menu_settings_cache_symfony',
                             handler: this.clearCache.bind(this, {'only_symfony_cache': true, 'env[]': pimcore.settings['cached_environments']})
                         });
                     }
 
                     cacheItems.push({
                         text: t("clear_cache"),
                         iconCls: "pimcore_nav_icon_clear_cache",
                         itemId: 'pimcore_menu_settings_cache_clear_cache',
                         hideOnClick: false,
                         menu: {
                             cls: "pimcore_navigation_flyout",
                             shadow: false,
                             items: cacheSubItems
                         }
                     });
                 }
 
                 if (perspectiveCfg.inToolbar("settings.cache.clearOutput")) {
                     if (user.isAllowed("clear_fullpage_cache")) {
                         cacheItems.push({
                             text: t("clear_full_page_cache"),
                             iconCls: "pimcore_nav_icon_clear_cache",
                             itemId: 'pimcore_menu_settings_cache_clear_full_page_cache',
                             handler: this.clearOutputCache
                         });
                     }
                 }
 
                 if (perspectiveCfg.inToolbar("settings.cache.clearTemp")) {
                     if (user.isAllowed("clear_temp_files")) {
                         cacheItems.push({
                             text: t("clear_temporary_files"),
                             iconCls: "pimcore_nav_icon_clear_cache",
                             itemId: 'pimcore_menu_settings_cache_clear_temporary_files',
                             handler: this.clearTemporaryFiles
                         });
                     }
                 }
 
                 if (perspectiveCfg.inToolbar("settings.cache.generatePreviews")) {
                     if (pimcore.settings.document_generatepreviews && (pimcore.settings.chromium || pimcore.settings.gotenberg)) {
                         cacheItems.push({
                             text: t("generate_page_previews"),
                             iconCls: "pimcore_nav_icon_page_previews",
                             itemId: 'pimcore_menu_settings_cache_generate_page_previews',
                             handler: this.generatePagePreviews
                         });
                     }
                 }
 
 
                 if (cacheItems.length > 0) {
                     var cacheMenu = {
                         text: t("cache"),
                         iconCls: "pimcore_nav_icon_clear_cache",
                         itemId: 'pimcore_menu_settings_cache',
                         hideOnClick: false,
                         menu: {
                             cls: "pimcore_navigation_flyout",
                             shadow: false,
                             items: cacheItems
                         }
                     };
 
                     settingsItems.push(cacheMenu);
                 }
             }
 
             // admin translations only for admins
             if (user.isAllowed('admin_translations')) {
                 if (perspectiveCfg.inToolbar("settings.adminTranslations")) {
                     settingsItems.push({
                         text: t("admin_translations"),
                         iconCls: "pimcore_nav_icon_translations",
                         itemId: 'pimcore_menu_settings_admin_translations',
                         handler: this.editTranslations.bind(this, 'admin')
                     });
                 }
             }
 
             // tags for elements
             if (user.isAllowed("tags_configuration") && perspectiveCfg.inToolbar("settings.tagConfiguration")) {
                 settingsItems.push({
                     text: t("element_tag_configuration"),
                     iconCls: "pimcore_nav_icon_element_tags",
                     itemId: 'pimcore_menu_settings_element_tag_configuration',
                     handler: this.showTagConfiguration
                 });
             }
 
             if (user.admin) {
                 settingsItems.push({
                     iconCls: "pimcore_nav_icon_icons",
                     itemId: 'pimcore_menu_settings_icon_library',
                     text: t('icon_library'),
                     handler: this.showIconLibrary.bind(this)
                 });
             }
 
             // help menu
            menu.settings = {
                label: t('settings'),
                iconCls: 'pimcore_main_nav_icon_settings',
                items: settingsItems,
                shadow: false,
                cls: "pimcore_navigation_flyout"
            };
         }

         // profile
         let profileItems = [
            {
                text: t("my_profile"),
                iconCls: 'pimcore_icon_profile',
                handler: () => {
                    pimcore.helpers.openProfile();
                }
            },

            {
                text: t('logout'),
                iconCls: 'pimcore_material_icon_logout',
                handler: () => {
                    document.getElementById('pimcore_logout_form').submit();
                }
            }
         ]

         // notifications
         if (user.isAllowed("notifications")) {
             var notificationItems = [{
                 text: t("notifications"),
                 iconCls: "pimcore_nav_icon_notifications",
                 itemId: 'pimcore_menu_notifications_notifications',
                 handler: this.showNotificationTab.bind(this)
             }];
 
             if(user.isAllowed('notifications_send')) {
                 notificationItems.push({
                     text: t("notifications_send"),
                     iconCls: "pimcore_nav_icon_notifications_sent",
                     itemId: 'pimcore_menu_notifications_notifications_send',
                     id: "notifications_new",
                     handler: this.showNotificationModal.bind(this)
                 });
             }
 
             notificationItems.push('-');
 
             // check for devmode
             if (pimcore.settings.devmode) {
                 notificationItems.push({
                     text: t("DEV MODE"),
                     iconCls: "pimcore_nav_icon_dev_mode",
                     itemId: 'pimcore_menu_notifications_dev_mode',
                 });
                 pimcore.notification.helper.incrementCount();
             }
 
             // check for debug
             if (pimcore.settings.debug) {
                 notificationItems.push({
                     text: t("debug_mode_on"),
                     iconCls: "pimcore_nav_icon_debug_mode",
                     itemId: 'pimcore_menu_notifications_debug_mode',
                 });
                 pimcore.notification.helper.incrementCount();
             }
 
             // check for maintenance
             if (!pimcore.settings.maintenance_active) {
                 notificationItems.push({
                     text: t("maintenance_not_active"),
                     iconCls: "pimcore_nav_icon_maintenance",
                     itemId: 'pimcore_menu_notifications_maintenance',
                     handler: function () {
                         window.open('https://pimcore.com/docs/platform/Pimcore/Getting_Started/Installation/Webserver_Installation#5-maintenance-cron-job');
                     }
                 });

                 pimcore.notification.helper.incrementCount();
             }
 
             //check for mail settings
             if (!pimcore.settings.mail) {
                 notificationItems.push({
                     text: t("mail_settings_incomplete"),
                     iconCls: "pimcore_nav_icon_email",
                     itemId: 'pimcore_menu_notifications_email',
                     handler: function () {
                         window.open('https://pimcore.com/docs/pimcore/current/Development_Documentation/Development_Tools_and_Details/Email_Framework');
                     }
                 });

                 pimcore.notification.helper.incrementCount();
             }

             profileItems = [
                ...notificationItems,
                '-',
                ...profileItems,
             ]
         }

         menu.notification = {
            items: profileItems,
            shadow: false,
            cls: "pimcore_navigation_flyout",
            exclude: true,
        };

         // Additional menu items can be added via this event
         const preMenuBuild = new CustomEvent(pimcore.events.preMenuBuild, {
             detail: {
                 menu: menu,
             }
         });

         document.dispatchEvent(preMenuBuild);

         // building the html markup for the main navigation
         pimcore.helpers.buildMainNavigationMarkup(menu);

         if(Object.keys(menu).length !== 0) {
             Object.keys(menu).filter(key => {
                 return (menu[key].items && menu[key].items.length > 0) || menu[key]['noSubmenus'];
             }).forEach(key => {
                 // Building all submenus
                 // menu[key].items can be empty
                 // menu items can be added via event after the inital setup
                 // if items are empty do not build submenus or main menu item
                 if(!menu[key]['noSubmenus']) {
                     pimcore.helpers.buildMenu(menu[key].items);
                 }

                 let menuItem = {
                     shadow: menu[key].shadow,
                     cls: "pimcore_navigation_flyout",
                 }

                 if(menu[key].listeners) {
                     menuItem.listeners = menu[key].listeners
                 }

                 if(menu[key].items) {
                     menuItem.items = menu[key].items;

                     if(!menu[key]['exclude'] && !menu[key]['noSubmenus']) {
                         menuItem.listeners =
                             {
                                 ...menuItem.listeners, ...{
                                     "show": function (e) {
                                         Ext.get('pimcore_menu_' + key).addCls('active');
                                     },
                                     "hide": function (e) {
                                         Ext.get('pimcore_menu_' + key).removeCls('active');
                                     }
                                 }
                             }
                     }
                 }

                 // Adding single main menu item
                 let menuKey = key + 'Menu';
                 this[menuKey] = Ext.create('pimcore.menu.menu', menuItem);

                 // if the main menu has its own handler use it
                 if(menu[key]['handler']) {
                     Ext.get("pimcore_menu_" + key).on("mousedown", menu[key]['handler']);
                 }

                 // only add the default show sub menu if there are items
                 if(menu[key]['items'] && !menu[key]['exclude'] && !menu[key]['handler']) {
                     // make sure the elements are clickable
                     Ext.get("pimcore_menu_" + key).on("mousedown", this.showSubMenu.bind(this[menuKey]));
                 }
             });
         }

         if (pimcore.settings.notifications_enabled && this.notificationMenu) {
             Ext.get('pimcore_notification').show();
             Ext.get("pimcore_notification").on("mousedown", this.showSubMenu.bind(this.notificationMenu));
             pimcore.notification.helper.updateFromServer();
         }
 
         Ext.each(Ext.query(".pimcore_menu_item"), function (el) {
             el = Ext.get(el);
 
             if (el) {
                 var menuVariable = el.id.replace(/pimcore_menu_/, "") + "Menu";
                 if (el.hasCls("pimcore_menu_needs_children")) {
                     if (!this[menuVariable]) {
                         el.setStyle("display", "none");
                     }
                 }
 
                 el.on("mouseenter", function () {
                     if (Ext.menu.MenuMgr.hideAll()) {
                         var offsets = el.getOffsetsTo(Ext.getBody());
                         offsets[0] = 60;
                         var menu = this[menuVariable];
                         if (menu) {
                             menu.showAt(offsets);
                         }
                     }
                 }.bind(this));
             } else {
                 console.error("no pimcore_menu_item");
             }
         }.bind(this));

         // Full menu can be checked here
         const postMenuBuild = new CustomEvent(pimcore.events.postMenuBuild, {
             detail: {
                 menu: menu,
             }
         });

         document.dispatchEvent(postMenuBuild);
 
         return;
     },
 
     showSubMenu: function (e) {
         if(this.hidden) {
             e.stopEvent();
             var el = Ext.get(e.currentTarget);
             var offsets = el.getOffsetsTo(Ext.getBody());
             offsets[0] = 60;
             this.showAt(offsets);
         } else {
             this.hide();
         }
     },
 
     closeAllTabs: function () {
         pimcore.helpers.closeAllTabs();
     },
 
     editDocumentTypes: function () {
 
         try {
             pimcore.globalmanager.get("document_types").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("document_types", new pimcore.settings.document.doctypes());
         }
     },
 
     editProperties: function () {
 
         try {
             pimcore.globalmanager.get("predefined_properties").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("predefined_properties", new pimcore.settings.properties.predefined());
         }
     },
 
 
     editPredefinedMetadata: function () {
 
         try {
             pimcore.globalmanager.get("predefined_metadata").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("predefined_metadata", new pimcore.settings.metadata.predefined());
         }
     },
 
     recyclebin: function () {
         try {
             pimcore.globalmanager.get("recyclebin").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("recyclebin", new pimcore.settings.recyclebin());
         }
     },
 
     editUsers: function () {
         pimcore.helpers.showUser();
     },
 
     editRoles: function () {
 
         try {
             pimcore.globalmanager.get("roles").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("roles", new pimcore.settings.user.role.panel());
         }
     },
 
     editThumbnails: function () {
         try {
             pimcore.globalmanager.get("thumbnails").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("thumbnails", new pimcore.settings.thumbnail.panel());
         }
     },
 
     editVideoThumbnails: function () {
         try {
             pimcore.globalmanager.get("videothumbnails").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("videothumbnails", new pimcore.settings.videothumbnail.panel());
         }
     },
 
     editTranslations: function (domain) {
         const preEditTranslations = new CustomEvent(pimcore.events.preEditTranslations, {
             detail: {
                 translation: this,
                 domain: domain ?? "website"
             },
             cancelable: true
         });
 
         const isAllowed = document.dispatchEvent(preEditTranslations);
         if (!isAllowed){
             return;
         }
 
         try {
             pimcore.globalmanager.get("translationdomainmanager").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("translationdomainmanager", new pimcore.settings.translation.domain(domain));
         }
     },
 
     openPerspective: function(name) {
         location.href = Routing.generate('pimcore_admin_index', {perspective: name});
     },
 
     generatePagePreviews: function ()  {
         Ext.Ajax.request({
             url: Routing.generate('pimcore_admin_document_page_generatepreviews'),
             success: function (res) {
                 var data = Ext.decode(res.responseText);
                 if(data && data.success) {
                     pimcore.helpers.showNotification(t("success"), t("success_generating_previews"), "success");
                 }
             },
             failure: function (message) {
                 pimcore.helpers.showNotification(t("error"), t("error_generating_previews"), "error", t(message));
             }
         });
     },
 
     sendTestEmail: function () {
         pimcore.helpers.sendTestEmail(pimcore.settings.mailDefaultAddress);
     },

     notes: function () {
         try {
             pimcore.globalmanager.get("notes").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("notes", new pimcore.element.notes());
         }
     },

     systemSettings: function () {
 
         try {
             pimcore.globalmanager.get("settings_system").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("settings_system", new pimcore.settings.system());
         }
     },

     systemAppearanceSettings: function () {
         try {
             pimcore.globalmanager.get("settings_system_appearance").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("settings_system_appearance", new pimcore.settings.appearance());
         }
     },
 
     websiteSettings: function () {
 
         try {
             pimcore.globalmanager.get("settings_website").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("settings_website", new pimcore.settings.website());
         }
     },
     editClassificationStoreConfig: function () {
         try {
             pimcore.globalmanager.get("classificationstore_config").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("classificationstore_config", new pimcore.object.classificationstore.storeTree());
         }
     },
 
     editClasses: function () {
         try {
             pimcore.globalmanager.get("classes").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("classes", new pimcore.object.klass());
         }
     },
 
     editFieldcollections: function () {
         try {
             pimcore.globalmanager.get("fieldcollections").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("fieldcollections", new pimcore.object.fieldcollection());
         }
     },
 
     editObjectBricks: function () {
         try {
             pimcore.globalmanager.get("objectbricks").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("objectbricks", new pimcore.object.objectbrick());
         }
     },

    editSelectOptions: function () {
        try {
            pimcore.globalmanager.get('selectoptions').activate();
        } catch (e) {
            pimcore.globalmanager.add('selectoptions', new pimcore.object.selectoptions());
        }
    },

     clearCache: function (params) {
         Ext.Msg.confirm(t('warning'), t('system_performance_stability_warning'), function(btn){
             if (btn == 'yes'){
                 Ext.Ajax.request({
                     url: Routing.generate('pimcore_admin_settings_clearcache'),
                     method: "DELETE",
                     params: params
                 });
             }
         });
     },
 
     clearOutputCache: function () {
         Ext.Ajax.request({
             url: Routing.generate('pimcore_admin_settings_clearoutputcache'),
             method: 'DELETE'
         });
     },
 
     clearTemporaryFiles: function () {
         Ext.Msg.confirm(t('warning'), t('system_performance_stability_warning'), function(btn){
             if (btn == 'yes'){
                 Ext.Ajax.request({
                     url: Routing.generate('pimcore_admin_settings_cleartemporaryfiles'),
                     method: "DELETE"
                 });
             }
         });
     },

     showMaintenance: function () {
         new pimcore.settings.maintenance();
     },

    showSystemRequirementsCheck: function () {
        pimcore.helpers.openGenericIframeWindow("systemrequirementscheck", Routing.generate('pimcore_admin_install_check'), "pimcore_icon_systemrequirements", "System-Requirements Check");
    },

     showElementHistory: function() {
         try {
             pimcore.globalmanager.get("element_history").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("element_history", new pimcore.element.history());
         }
     },
 
     sentEmailsLog: function () {
         try {
             pimcore.globalmanager.get("sent_emails").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("sent_emails", new pimcore.settings.email.log());
         }
     },
 
     emailBlocklist: function () {
         try {
             pimcore.globalmanager.get("email_blocklist").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("email_blocklist", new pimcore.settings.email.blocklist());
         }
     },
 
     showTagConfiguration: function() {
         try {
             pimcore.globalmanager.get("element_tag_configuration").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("element_tag_configuration", new pimcore.element.tag.configuration());
         }
     },
 
 
     bulkImport: function() {
 
         Ext.Msg.confirm(t('warning'), t('warning_bulk_import'), function(btn){
             if (btn == 'yes'){
                 this.doBulkImport();
             }
         }.bind(this));
     },
 
 
     doBulkImport: function() {
         var importer = new pimcore.object.bulkimport;
         importer.upload();
     },
 
     bulkExport: function() {
         var exporter = new pimcore.object.bulkexport();
         exporter.export();
     },
 
     showNotificationTab: function () {
         try {
             pimcore.globalmanager.get("notifications").activate();
         }
         catch (e) {
             pimcore.globalmanager.add("notifications", new pimcore.notification.panel());
         }
     },
 
     showNotificationModal: function () {
         if (pimcore.globalmanager.get("new_notifications")) {
             pimcore.globalmanager.get("new_notifications").getWindow().destroy();
         }
 
         pimcore.globalmanager.add("new_notifications", new pimcore.notification.modal());
     },

    showIconLibrary: function () {
        try {
            pimcore.globalmanager.get("iconlibrary").activate();
        }
        catch (e) {
            pimcore.globalmanager.add("iconlibrary", new pimcore.iconlibrary.panel());
            pimcore.globalmanager.get("iconlibrary").activate();
        }
    }
 });
 
