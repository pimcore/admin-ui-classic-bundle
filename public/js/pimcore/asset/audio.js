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

pimcore.registerNS("pimcore.asset.audio");
/**
 * @private
 */
pimcore.asset.audio = Class.create(pimcore.asset.asset, {

    initialize: function(id, options) {

        this.options = options;
        this.id = intval(id);
        this.setType("audio");
        this.addLoadingPanel();

        const preOpenAssetAudio = new CustomEvent(pimcore.events.preOpenAsset, {
            detail: {
                object: this,
                type: "audio"
            },
            cancelable: true
        });

        const isAllowed = document.dispatchEvent(preOpenAssetAudio);
        if (!isAllowed) {
            this.removeLoadingPanel();
            return;
        }

        var user = pimcore.globalmanager.get("user");

        this.properties = new pimcore.element.properties(this, "asset");
        this.versions = new pimcore.asset.versions(this);
        this.scheduler = new pimcore.element.scheduler(this, "asset");
        this.dependencies = new pimcore.element.dependencies(this, "asset");

        if (user.isAllowed("notes_events")) {
            this.notes = new pimcore.element.notes(this, "asset");
        }

        this.tagAssignment = new pimcore.element.tag.assignment(this, "asset");
        this.metadata = new pimcore.asset.metadata.editor(this);
        this.workflows = new pimcore.element.workflows(this, "asset");

        this.getData();
    },

    getTabPanel: function () {

        var items = [];
        var user = pimcore.globalmanager.get("user");

        items.push(this.getEditPanel());

        if (this.isAllowed("publish")) {
            items.push(this.metadata.getLayout());
        }
        if (this.isAllowed("properties")) {
            items.push(this.properties.getLayout());
        }

        if (this.isAllowed("versions")) {
            items.push(this.versions.getLayout());
        }
        if (this.isAllowed("settings")) {
            items.push(this.scheduler.getLayout());
        }

        items.push(this.dependencies.getLayout());

        if (user.isAllowed("notes_events")) {
            items.push(this.notes.getLayout());
        }

        if (user.isAllowed("tags_assignment")) {
            items.push(this.tagAssignment.getLayout());
        }

        if (user.isAllowed("workflow_details") && this.data.workflowManagement && this.data.workflowManagement.hasWorkflowManagement === true) {
            items.push(this.workflows.getLayout());
        }

        this.tabbar = pimcore.helpers.getTabBar({items: items});
        return this.tabbar;
    },

    getEditPanel: function () {
        if (!this.editPanel) {
            let html = t('preview_not_available');

            if (document.createElement('audio').canPlayType(this.data.mimetype)) {
                html = '<audio controls><source src="' + this.data.path + this.data.filename + '" type="' + this.data.mimetype + '"></audio>';
            }

            this.editPanel = new Ext.Panel({
                title: t("preview"),
                html: html,
                bodyCls: "pimcore_panel_body_centered",
                iconCls: "pimcore_material_icon_devices pimcore_material_icon"
            });
        }

        return this.editPanel;
    }
});

