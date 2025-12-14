/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.object.tags.manyToManyAssetRelation");
/**
 * @private
 */
pimcore.object.tags.manyToManyAssetRelation = Class.create(pimcore.object.tags.manyToManyRelation, {

    type: "manyToManyAssetRelation",

    initialize: function ($super, data, fieldConfig) {
        $super(data, fieldConfig);

        let visibleFields = [];
        if (Ext.isString(fieldConfig.visibleFields)) {
            visibleFields = fieldConfig.visibleFields.split(",").map(function (field) {
                return field.trim();
            });
        } else if (Ext.isArray(fieldConfig.visibleFields)) {
            visibleFields = fieldConfig.visibleFields;
        }

        this.visibleFields = visibleFields.filter(function (field) {
            return field.length > 0;
        });
    },

    getVisibleColumns: function () {
        var visibleFields = this.visibleFields || [];

        if (visibleFields.length === 0) {
            return pimcore.object.tags.manyToManyRelation.prototype.getVisibleColumns.call(this);
        }

        var columns = [];

        for (var i = 0; i < visibleFields.length; i++) {
            var key = visibleFields[i];
            var layout = (this.fieldConfig.visibleFieldDefinitions || {})[key] || {fieldtype: "input", title: key, name: key};

            var field = {
                key: key,
                label: layout.title === "fullpath" ? t("reference") : layout.title,
                layout: layout
            };

            var tagClass = pimcore.object.tags[field.layout.fieldtype];
            if (!tagClass) {
                continue;
            }

            var fc = tagClass.prototype.getGridColumnConfig(field);

            fc.flex = 100;
            fc.hidden = false;
            fc.layout = field;
            fc.editor = null;
            fc.sortable = false;

            if (fc.layout.key === "fullpath") {
                fc.renderer = this.fullPathRenderCheck.bind(this);
            }

            fc.filter = {
                type: 'list',
                labelField: field.key,
                idField: field.key,
                store: this.getSortedStore(this.store, field.key)
            };

            var columnWidth = this.getColumnWidth(fc.dataIndex);
            if (columnWidth > 0) {
                fc.width = columnWidth;
                delete fc.flex;
            }

            if(typeof fc.listeners === "undefined") {
                fc.listeners = {};
            }
            fc.listeners.resize = function (columnKey, column, width) {
                localStorage.setItem(this.getColumnWidthLocalStorageKey(columnKey), width);
            }.bind(this, fc.dataIndex);

            columns.push(fc);
        }

        return columns;
    },

    requestNicePathData: function (targets) {
        if (!this.object) {
            return;
        }

        var context = this.getContext();
        var fields = this.visibleFields || [];
        var loadEditModeData = fields.length > 0;

        pimcore.helpers.requestNicePathData(
            {
                type: "object",
                id: this.object.id
            },
            targets,
            {
                idProperty: this.idProperty,
                pathProperty: this.pathProperty,
                loadEditModeData: loadEditModeData
            },
            this.fieldConfig,
            context,
            pimcore.helpers.requestNicePathDataGridDecorator.bind(this, this.component.getView()),
            pimcore.helpers.getNicePathHandlerStore.bind(this, this.store, {
                idProperty: this.idProperty,
                pathProperty: this.pathProperty,
                loadEditModeData: loadEditModeData,
                fields: fields
            }, this.component.getView())
        );
    }
});
