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

/**
 * @private
 */

Ext.setVersion("ext", "7.0.0.159");
Ext.setVersion("core", "7.0.0.159");

if(typeof window['t'] !== 'function') {
    // for compatibility reasons
    window.t = function(v) { return v; };
}


Ext.form.field.Date.prototype.startDay = 1;

Ext.override(Ext.dd.DragDropMgr, {
        startDrag: function (x, y) {

            // always hide tree-previews on drag start
            pimcore.helpers.treeNodeThumbnailPreviewHide();

            this.callParent(arguments);
        }
    }
);

/**
 * Undesired behaviour: submenu is hidden on clicking owner menu item
 * fix see https://www.sencha.com/forum/showthread.php?305492-Undesired-behaviour-submenu-is-hidden-on-clicking-owner-menu-item
 * @param e
 */
Ext.menu.Manager.checkActiveMenus = function(e) {
    var allMenus = this.visible,
        len = allMenus.length,
        i, menu,
        mousedownCmp = Ext.Component.fromElement(e.target);
    if (len) {
        // Clone here, we may modify this collection while the loop is active
        allMenus = allMenus.slice();
        for (i = 0; i < len; ++i) {
            menu = allMenus[i];
            // Hide the menu if:
            //      The menu does not own the clicked upon element AND
            //      The menu is not the child menu of a clicked upon MenuItem
            if (!(menu.owns(e) || (mousedownCmp && mousedownCmp.isMenuItem && mousedownCmp.menu === menu))) {
                menu.hide();
            }
        }
    }
};


Ext.define('pimcore.FieldSetTools', {
    extend: 'Ext.form.FieldSet',

    createLegendCt: function () {
        var me = this;
        var result = this.callSuper(arguments);

        if (me.config.tools && me.config.tools.length > 0) {
            for (var i = 0; i < me.config.tools.length; i++) {
                var tool = me.config.tools[i];
                this.createToolCmp(tool, result);
            }
        }
        return result;

    },


    createToolCmp: function(tool, result) {
        var me = this;
        var cls = me.baseCls + '-header-tool-default ' + me.baseCls + '-header-tool-right';
        if (tool['cls']) {
            cls = cls + ' ' + tool['cls'];
        }
        var cfg = {
            type: tool['type'],
            html: me.title,
            ui: me.ui,
            tooltip: tool.qtip,
            handler: tool.handler,
            hidden: tool.hidden,
            cls: cls,
            ariaRole: 'checkbox',
            ariaRenderAttributes: {
                'aria-checked': !me.collapsed
            }
        };

        if (tool['id']) {
            cfg['id'] = tool['id'];
        }

        var cmp = new Ext.panel.Tool(cfg);
        result.add(cmp);
        return result;
    },
});



Ext.define('pimcore.filters', {
    extend: 'Ext.grid.filters.Filters',
    alias: 'plugin.pimcore.gridfilters',
    menuFilterText: t('filter'),

    createColumnFilter: function(column) {
        this.callSuper(arguments);
        var type = column.filter.type;
        var theFilter = column.filter.filter;

        if (column.filter instanceof Ext.grid.filters.filter.TriFilter) {
            theFilter.lt.config.type = type;
            theFilter.gt.config.type = type;
            theFilter.eq.config.type = type;

            if (column.decimalPrecision) {
                column.filter.fields.lt.decimalPrecision = column.decimalPrecision;
                column.filter.fields.gt.decimalPrecision = column.decimalPrecision;
                column.filter.fields.eq.decimalPrecision = column.decimalPrecision;
            }
        } else {
            theFilter.config.type = type;
        }
    }
});

// See https://www.sencha.com/forum/showthread.php?288385
// Column renderer will give no metadata parameter after change a value of cell.
// It happens because column renderer method is invoked with null second parameter here
Ext.define('Ext.overrides.grid.View', {
        extend: 'Ext.grid.View',

        alias: 'widget.patchedgridview'
        ,

        handleUpdate: function(store, record, operation, changedFieldNames) {
            var me = this,
                rowTpl = me.rowTpl,
                oldItem, oldItemDom, oldDataRow,
                newItemDom,
                newAttrs, attLen, attName, attrIndex,
                overItemCls,
                focusedItemCls,
                selectedItemCls,
                columns,
                column,
                columnsToUpdate = [],
                len, i,
                hasVariableRowHeight = me.variableRowHeight,
                cellUpdateFlag,
                updateTypeFlags = 0,
                cell,
                fieldName,
                value,
                defaultRenderer,
                scope,
                ownerCt = me.ownerCt;


            if (me.viewReady) {
                oldItemDom = me.getNodeByRecord(record);

                if (oldItemDom) {
                    overItemCls = me.overItemCls;
                    focusedItemCls = me.focusedItemCls;
                    selectedItemCls = me.selectedItemCls;
                    columns = me.ownerCt.getVisibleColumnManager().getColumns();

                    if (!me.getRowFromItem(oldItemDom) || (updateTypeFlags & 1) || (oldItemDom.tBodies[0].childNodes.length > 1)) {
                        oldItem = Ext.fly(oldItemDom, '_internal');
                        newItemDom = me.createRowElement(record, me.dataSource.indexOf(record), columnsToUpdate);
                        if (oldItem.hasCls(overItemCls)) {
                            Ext.fly(newItemDom).addCls(overItemCls);
                        }
                        if (oldItem.hasCls(focusedItemCls)) {
                            Ext.fly(newItemDom).addCls(focusedItemCls);
                        }
                        if (oldItem.hasCls(selectedItemCls)) {
                            Ext.fly(newItemDom).addCls(selectedItemCls);
                        }

                        newAttrs = newItemDom.attributes;
                        attLen = newAttrs.length;
                        for (attrIndex = 0; attrIndex < attLen; attrIndex++) {
                            attName = newAttrs[attrIndex].name;
                            if (attName !== 'id') {
                                oldItemDom.setAttribute(attName, newAttrs[attrIndex].value);
                            }
                        }

                        if (columns.length && (oldDataRow = me.getRow(oldItemDom))) {
                            me.updateColumns(oldDataRow, Ext.fly(newItemDom).down(me.rowSelector, true), columnsToUpdate);
                        }

                        while (rowTpl) {
                            if (rowTpl.syncContent) {
                                if (rowTpl.syncContent(oldItemDom, newItemDom, changedFieldNames ? columnsToUpdate : null) === false) {
                                    break;
                                }
                            }
                            rowTpl = rowTpl.nextTpl;
                        }
                    }
                    else {
                        this.refresh();
                    }

                    if (hasVariableRowHeight) {
                        Ext.suspendLayouts();
                    }


                    me.fireEvent('itemupdate', record, me.store.indexOf(record), oldItemDom);

                    if (hasVariableRowHeight) {
                        me.refreshSize();

                        Ext.resumeLayouts(true);
                    }
                }
            }
        }
    }
);

Ext.define('pimcore.tree.Panel', {
    extend: 'Ext.tree.Panel'
});

Ext.define('pimcore.tree.View', {
    extend: 'Ext.tree.View',
    alias: 'widget.pimcoretreeview',
    listeners: {
        refresh: function() {
            this.updatePaging();
        },
        beforeitemupdate: function(record) {
            if(record.ptb) {
                record.ptb.destroy();
                delete record.ptb;
            }
        },

        itemupdate: function(record) {
            if (record.needsPaging && typeof record.ptb == "undefined" && typeof record.itemUpdated == "undefined") {
                record.itemUpdated = true;
                this.doUpdatePaging(record);
            }
        }
    },

    queue: {},

    renderRow: function(record, rowIdx, out) {
        var me = this;
        if (record.needsPaging) {
            me.queue[record.id] = record;
        }

        me.superclass.renderRow.call(this, record, rowIdx, out);

        // do not update paging again, if already done in "itemupdate" event
        if (record.needsPaging && typeof record.ptb == "undefined" && typeof record.itemUpdated == "undefined") {
            this.doUpdatePaging(record);
        }

        this.fireEvent("itemafterrender", record, rowIdx, out);
    },

    doUpdatePaging: function(node) {

        if (node.data.expanded && node.needsPaging) {

            node.ptb = ptb = Ext.create('pimcore.toolbar.Paging', {
                    node: node,
                    width: 260
                }
            );

            node.ptb.node = node;
            node.ptb.store = this.store;


            var tree = node.getOwnerTree();
            var view = tree.getView();
            var nodeEl = Ext.fly(view.getNodeByRecord(node));
            if (!nodeEl) {
                //console.log("Could not resolve node " + node.id);
                return;
            }
            nodeEl = nodeEl.getFirstChild();
            nodeEl = nodeEl.query(".x-tree-node-text");
            nodeEl = nodeEl[0];
            var el = nodeEl;

            //el.addCls('x-grid-header-inner');
            el = Ext.DomHelper.insertAfter(el, {
                tag: 'span',
                "class": "pimcore_pagingtoolbar_container"
            }, true);

            el.addListener("click", function(e) {
                e.stopPropagation();
            });


            el.addListener("mousedown", function(e) {
                e.stopPropagation();
            });

            ptb.render(el);
            tree.updateLayout();

            if (node.filter) {
                node.ptb.filterField.focus([node.filter.length, node.filter.length]);
            } else if (node.fromPaging) {
                node.ptb.numberItem.focus();
            }
        }

    },

    updatePaging: function() {
        var me = this;
        var queue = me.queue;

        var names = Object.getOwnPropertyNames(queue);

        for (i = 0; i < names.length; i++) {
            var node = queue[names[i]];
            this.doUpdatePaging(node);
        }

        me.queue = {}
    }
});

Ext.define('pimcore.data.PagingTreeStore', {

    extend: 'Ext.data.TreeStore',

    ptb: false,

    onProxyLoad: function(operation) {
        try {
            var me = this;
            var options = operation.initialConfig
            var node = options.node;
            var proxy = me.getProxy();
            var extraParams = proxy.getExtraParams();


            var response = operation.getResponse();
            var data = response.responseJson;

            node.fromPaging = data.fromPaging;
            node.filter = data.filter;
            node.inSearch = data.inSearch;
            node.overflow = data.overflow;

            proxy.setExtraParam("fromPaging", 0);

            var total = data.total;

            var text = node.data.text;
            if (typeof total == "undefined") {
                total = 0;
            }

            node.addListener("expand", function (node) {
                var tree = node.getOwnerTree();
                if (tree) {
                    var view = tree.getView();
                    view.updatePaging();
                }
            }.bind(this));

            //to hide or show the expanding icon depending if children are available or not
            node.addListener('remove', function (node, removedNode, isMove) {
                if (!node.hasChildNodes()) {
                    node.set('expandable', false);
                }
            });
            node.addListener('append', function (node) {
                node.set('expandable', true);
            });

            if (me.pageSize < total || node.inSearch) {
                node.needsPaging = true;
                node.pagingData = {
                    total: data.total,
                    offset: data.offset,
                    limit: data.limit
                }
            } else {
                node.needsPaging = false;
            }

            me.superclass.onProxyLoad.call(this, operation);
            var proxy = this.getProxy();
            proxy.setExtraParam("start", 0);
        } catch (e) {
            console.log(e);
        }
    }
});


Ext.define('pimcore.toolbar.Paging', {
    extend: 'Ext.toolbar.Toolbar',
    requires: [
        'Ext.toolbar.TextItem',
        'Ext.form.field.Number'
    ],

    displayInfo: false,

    prependButtons: false,

    displayMsg: t('Displaying {0} - {1} of {2}'),

    emptyMsg: t('no_data_to_display'),

    beforePageText: t('page'),

    afterPageText: '/ {0}',

    firstText: t('first_page'),

    prevText: t('previous_page'),

    nextText: t('next_page'),

    lastText: t('last_page'),

    refreshText: t('refresh'),

    width: 280,

    height: 20,

    border: false,

    emptyPageData: {
        total: 0,
        currentPage: 0,
        pageCount: 0,
        toRecord: 0,
        fromRecord: 0
    },

    doCancelSearch: function (node) {
        this.inSearch = 0;
        this.cancelFilterButton.hide();
        this.filterButton.show();
        this.filterField.setValue("");
        this.filterField.hide();

        var store = this.store;
        store.load({
                node: node,
                params: {
                    "inSearch": 0
                }
            }
        );


        this.first.show();
        this.prev.show();
        this.numberItem.show();
        this.spacer.show();
        this.afterItem.show();
        this.next.show();
        this.last.show();
    },

    getPagingItems: function () {
        var me = this,
            inputListeners = {
                scope: me,
                blur: me.onPagingBlur
            };

        var node = me.node;
        var pagingData = me.node.pagingData;

        var currPage = pagingData.offset / pagingData.limit + 1;

        this.inSearch = node.inSearch;
        var hidden = this.inSearch
        pimcore.isTreeFiltering = false;

        inputListeners[Ext.supports.SpecialKeyDownRepeat ? 'keydown' : 'keypress'] = me.onPagingKeyDown;

        this.filterField = new Ext.form.field.Text({
            name: 'filter',
            width: 160,
            border: true,
            cls: "pimcore_pagingtoolbar_container_filter",
            fieldStyle: "padding: 0 10px 0 10px;",
            height: 18,
            value: node.filter ? node.filter : "",
            enableKeyEvents: true,
            hidden: !hidden,
            listeners: {
                "keydown": function (node, inputField, event) {
                    if (event.keyCode == 13) {
                        var store = this.store;
                        var proxy = store.getProxy();
                        this.currentFilter = this.filterField.getValue();


                        try {
                            store.load({
                                    node: node,
                                    params: {
                                        "filter": this.filterField.getValue(),
                                        "inSearch": this.inSearch
                                    }
                                }
                            );
                        } catch (e) {

                        }


                    }
                }.bind(this, node)
            }

        })
        ;

        var result = [this.filterField];

        this.overflow = new Ext.button.Button(
            {
                tooltip: t("there_are_more_items"),
                overflowText: t("there_are_more_items"),
                iconCls: "pimcore_icon_warning",
                disabled: false,
                scope: me,
                border: false,
                hidden: !node.overflow
            });


        this.filterButton = new Ext.button.Button(
            {
                itemId: 'filterButton',
                tooltip: t("filter"),
                overflowText: t("filter"),
                iconCls: Ext.baseCSSPrefix + 'tbar-page-filter',
                margin: '-1 2 3 2',
                handler: function () {
                    this.inSearch = 1;
                    this.cancelFilterButton.show();
                    this.filterButton.hide();
                    this.filterField.setValue("");
                    this.filterField.show();

                    this.filterField.focus();

                    this.first.hide();
                    this.prev.hide();
                    this.numberItem.hide();
                    this.spacer.hide();
                    this.afterItem.hide();
                    this.next.hide();
                    this.last.hide();
                }.bind(this),
                scope: me,
                hidden: this.inSearch
            });

        this.cancelFilterButton = new Ext.button.Button(
            {
                itemId: 'cancelFlterButton',
                tooltip: t("clear"),
                overflowText: t("clear"),
                margin: '-1 2 3 2',
                iconCls: Ext.baseCSSPrefix + 'tbar-page-cancel-filter',
                handler: function () {
                    this.doCancelSearch(node);

                }.bind(this),
                scope: me,
                hidden: !this.inSearch
            });

        this.afterItem = Ext.create('Ext.form.NumberField', {

            cls: Ext.baseCSSPrefix + 'tbar-page-number',
            value: Math.ceil(pagingData.total / pagingData.limit),
            hideTrigger: true,
            heightLabel: true,
            height: 18,
            width: 38,
            disabled: true,
            margin: '-1 2 3 2',
            hidden: hidden
        });


        this.numberItem = new Ext.form.field.Number({
            xtype: 'numberfield',
            itemId: 'inputItem',
            name: 'inputItem',
            heightLabel: true,
            cls: Ext.baseCSSPrefix + 'tbar-page-number',
            allowDecimals: false,
            minValue: 1,
            maxValue: this.getMaxPageNum(),
            value: currPage,
            hideTrigger: true,
            enableKeyEvents: true,
            keyNavEnabled: false,
            selectOnFocus: true,
            submitValue: false,
            height: 18,
            width: 40,
            isFormField: false,
            margin: '-1 2 3 2',
            listeners: inputListeners,
            hidden: hidden
        });


        this.first = new Ext.button.Button(
            {
                itemId: 'first',
                tooltip: me.firstText,
                overflowText: me.firstText,
                iconCls: Ext.baseCSSPrefix + 'tbar-page-first',
                disabled: me.node.pagingData.offset == 0,
                handler: me.moveFirst,
                scope: me,
                border: false,
                hidden: hidden

            });


        this.prev = new Ext.button.Button({
            itemId: 'prev',
            tooltip: me.prevText,
            overflowText: me.prevText,
            iconCls: Ext.baseCSSPrefix + 'tbar-page-prev',
            disabled: me.node.pagingData.offset == 0,
            handler: me.movePrevious,
            scope: me,
            border: false,
            hidden: hidden
        });


        this.spacer = new Ext.toolbar.Spacer({
            xtype: "tbspacer",
            hidden: hidden
        });


        this.next = new Ext.button.Button({
            itemId: 'next',
            tooltip: me.nextText,
            overflowText: me.nextText,
            iconCls: Ext.baseCSSPrefix + 'tbar-page-next',
            disabled: (Math.ceil(me.node.pagingData.total / me.node.pagingData.limit) - 1) * me.node.pagingData.limit == me.node.pagingData.offset,
            handler: me.moveNext,
            scope: me,
            hidden: hidden
        });


        this.last = new Ext.button.Button({
            itemId: 'last',
            tooltip: me.lastText,
            overflowText: me.lastText,
            iconCls: Ext.baseCSSPrefix + 'tbar-page-last',
            disabled: (Math.ceil(me.node.pagingData.total / me.node.pagingData.limit) - 1) * me.node.pagingData.limit == me.node.pagingData.offset,
            handler: me.moveLast,
            scope: me,
            hidden: hidden
        });


        result.push(this.overflow);
        result.push(this.filterButton);
        result.push(this.cancelFilterButton);

        result.push(this.filterField);
        result.push(this.first);
        result.push(this.prev);
        result.push(this.numberItem);
        result.push(this.spacer);
        result.push(this.afterItem);
        result.push(this.next);
        result.push(this.last);


        return result;
    },

    getMaxPageNum: function() {
        var me = this;
        return Math.ceil(me.node.pagingData.total / me.node.pagingData.limit)
    },

    initComponent: function(config) {
        var me = this,
            userItems = me.items || me.buttons || [],
            pagingItems;

        pagingItems = me.getPagingItems();
        if (me.prependButtons) {
            me.items = userItems.concat(pagingItems);
        } else {
            me.items = pagingItems.concat(userItems);
        }
        delete me.buttons;
        if (me.displayInfo) {
            me.items.push('->');
            me.items.push({
                xtype: 'tbtext',
                itemId: 'displayItem'
            });
        }
        me.callParent();
    },


    getInputItem: function() {
        return this.child('#inputItem');
    },


    onPagingBlur: function(e) {
        var inputItem = this.getInputItem(),
            curPage;
        if (inputItem) {
            //curPage = this.getPageData().currentPage;
            //inputItem.setValue(curPage);
        }
    },

    onPagingKeyDown: function(field, e) {
        this.processKeyEvent(field, e);
    },

    readPageFromInput: function() {
        var inputItem = this.getInputItem(),
            pageNum = false,
            v;
        if (inputItem) {
            v = inputItem.getValue();
            pageNum = parseInt(v, 10);
        }
        return pageNum;
    },


    processKeyEvent: function(field, e) {
        var me = this,
            k = e.getKey(),
        //pageData = me.getPageData(),
            increment = e.shiftKey ? 10 : 1,
            pageNum;
        if (k == e.RETURN) {
            e.stopEvent();
            pageNum = me.readPageFromInput();
            if (pageNum !== false) {
                pageNum = Math.min(Math.max(1, pageNum), this.getMaxPageNum());
                this.moveToPage(pageNum);
            }


        } else if (k == e.HOME) {
            e.stopEvent();
            this.moveFirst();
        } else if (k == e.END) {
            e.stopEvent();
            this.moveLast();
        } else if (k == e.UP || k == e.PAGE_UP || k == e.DOWN || k == e.PAGE_DOWN) {
            e.stopEvent();
            pageNum = me.readPageFromInput();
            if (pageNum) {
                if (k == e.DOWN || k == e.PAGE_DOWN) {
                    increment *= -1;
                }
                pageNum += increment;
                if (pageNum >= 1 && pageNum <= this.getMaxPageNum()) {
                    this.moveToPage(pageNum);
                }
            }
        }
    },

    moveToPage: function(page) {
        var me = this;
        var node = me.node;
        var pagingData = node.pagingData;
        var store = node.getTreeStore();

        var proxy = store.getProxy();
        proxy.setExtraParam("start",  pagingData.limit * (page - 1));
        proxy.setExtraParam("fromPaging", 1);
        store.load({
            node: node
        });
    },

    moveFirst: function() {
        var me = this;
        var node = me.node;
        var pagingData = node.pagingData;
        var store = node.getTreeStore();
        var page = pagingData.offset / pagingData.total;

        var proxy = store.getProxy();
        proxy.setExtraParam("start", 0);
        store.load({
            node: node
        });
    },

    movePrevious: function() {
        var me = this;
        var node = me.node;
        var pagingData = node.pagingData;
        var store = node.getTreeStore();
        var page = pagingData.offset / pagingData.total;

        var proxy = store.getProxy();
        proxy.setExtraParam("start", pagingData.offset - pagingData.limit);
        store.load({
            node: node
        });
    },

    moveNext: function() {
        var me = this;
        var node = me.node;
        var pagingData = node.pagingData;
        var store = node.getTreeStore();
        var page = pagingData.offset / pagingData.total;

        var proxy = store.getProxy();
        proxy.setExtraParam("start", pagingData.offset + pagingData.limit);
        store.load({
            node: node
        });

    },

    moveLast: function() {
        var me = this;
        var node = me.node;
        var pagingData = node.pagingData;
        var store = node.getTreeStore();
        var offset = (Math.ceil(pagingData.total / pagingData.limit) - 1) * pagingData.limit;

        var proxy = store.getProxy();
        proxy.setExtraParam("start", offset);
        store.load({
            node: node
        });
    },

    doRefresh: function() {
        var me = this;
        var node = me.node;
        var pagingData = node.pagingData;
        var store = node.getTreeStore();
        var page = pagingData.offset / pagingData.total;

        var proxy = store.getProxy();
        proxy.setExtraParam("start", pagingData.offset);
        store.load({
            node: node
        });
    },

    onDestroy: function() {
        //this.bindStore(null);
        this.callParent();
    }
});

/**
 * Fixes ID validation to include more characters as we need the colon for nested editable names
 *
 * See:
 *
 * - http://www.sencha.com/forum/showthread.php?296173-validIdRe-throwing-Invalid-Element-quot-id-quot-for-valid-ids-containing-colons
 * - https://github.com/JarvusInnovations/sencha-hotfixes/blob/ext/5/0/1/1255/overrides/dom/Element/ValidId.js
 */
Ext.define('EXTJS-17231.ext.dom.Element.validIdRe', {
    override: 'Ext.dom.Element',

    validIdRe: /^[a-z][a-z0-9\-_:.]*$/i,

    getObservableId: function () {
        return (this.observableId = this.callParent().replace(/([.:])/g, "\\$1"));
    }
});

//Fix - Date picker does not align to component in scrollable container and breaks view layout randomly.
Ext.override(Ext.picker.Date, {
        afterComponentLayout: function (width, height, oldWidth, oldHeight) {
        var field = this.pickerField;
        this.callParent([
            width,
            height,
            oldWidth,
            oldHeight
        ]);
        // Bound list may change size, so realign on layout
        // **if the field is an Ext.form.field.Picker which has alignPicker!**
        if (field && field.alignPicker) {
            field.alignPicker();
        }
    }
});


/** workaround for [DataObject] Advanced Image Dropzone only works once #9115
 * Issue: on node drop the component gets destroyed. On mouse up it then tries to focus an already destroyed element.
 */
Ext.override(Ext.dom.Element, {
    focus: function (defer, dom) {

        var me = this;

        dom = dom || me.dom;

        if (Number(defer)) {
            Ext.defer(me.focus, defer, me, [null, dom]);
        } else {
            Ext.fireEvent('beforefocus', dom);
            if (dom) {
                dom.focus();
            }
        }

        return me;
    }
});

/**
 * A specialized {@link Ext.view.BoundListKeyNav} implementation for navigating in the quicksearch.
 * This is needed because in the default implementation the Crtl+A combination is disabled, but this is needed
 * for the purpose of the quicksearch
 */
Ext.define('Pimcore.view.BoundListKeyNav', {
    extend: 'Ext.view.BoundListKeyNav',

    alias: 'view.navigation.quicksearch.boundlist',

    initKeyNav: function(view) {
        var me = this,
            field = view.pickerField;

        // Add the regular KeyNav to the view.
        // Unless it's already been done (we may have to defer a call until the field is rendered.
        if (!me.keyNav) {
            me.callParent([view]);

            // Add ESC handling to the View's KeyMap to collapse the field
            me.keyNav.map.addBinding({
                key: Ext.event.Event.ESC,
                fn: me.onKeyEsc,
                scope: me
            });
        }

        // BoundLists must be able to function standalone with no bound field
        if (!field) {
            return;
        }

        if (!field.rendered) {
            field.on('render', Ext.Function.bind(me.initKeyNav, me, [view], 0), me, {single: true});
            return;
        }

        // BoundListKeyNav also listens for key events from the field to which it is bound.
        me.fieldKeyNav = new Ext.util.KeyNav({
            disabled: true,
            target: field.inputEl,
            forceKeyDown: true,
            up: me.onKeyUp,
            down: me.onKeyDown,
            right: me.onKeyRight,
            left: me.onKeyLeft,
            pageDown: me.onKeyPageDown,
            pageUp: me.onKeyPageUp,
            home: me.onKeyHome,
            end: me.onKeyEnd,
            tab: me.onKeyTab,
            space: me.onKeySpace,
            enter: me.onKeyEnter,
            // This object has to get its key processing in first.
            // Specifically, before any Editor's key hyandling.
            priority: 1001,
            scope: me
        });
    }
});

/**
 * Workaround to fix the rowEditing not fully showing the buttons (Update/Cancel) when there are 2 rows.
 *
 * See:
 * - https://forum.sencha.com/forum/showthread.php?305665-RowEditing-Buttons-not-visible&p=1317756&viewfull=1#post1317756
 */
Ext.define('Ext.overrides.grid.RowEditor', {
    override: 'Ext.grid.RowEditor',

    showTipBelowRow: true,

    syncButtonPosition: function (context) {
        var me = this,
            scrollDelta = me.getScrollDelta(),
            floatingButtons = me.getFloatingButtons(),
            scrollingView = me.scrollingView,
        // If this is negative, it means we're not scrolling so lets just ignore it
            scrollHeight = Math.max(0, me.scroller.getSize().y - me.scroller.getClientSize().y),
            overflow = scrollDelta - (scrollHeight - me.scroller.getPosition().y);
        floatingButtons.show();
        // If that's the last visible row, buttons should be at the top regardless of scrolling,
        // but not if there is just one row which is both first and last.
        if (overflow > 0 || (context.rowIdx > 1 && context.isLastRenderedRow())) {
            if (!me._buttonsOnTop) {
                floatingButtons.setButtonPosition('top');
                me._buttonsOnTop = true;
                me.layout.setAlign('bottom');
                me.updateLayout();
            }
            scrollDelta = 0;
        } else if (me._buttonsOnTop !== false) {
            floatingButtons.setButtonPosition('bottom');
            me._buttonsOnTop = false;
            me.layout.setAlign('top');
            me.updateLayout();
        } else // Ensure button Y position is synced with Editor height even if button
            // orientation doesn't change
        {
            floatingButtons.setButtonPosition(floatingButtons.position);
        }
        return scrollDelta;
    },
});

Ext.define('Ext.local.grid.filters.filter.TriFilter', {
    extend: 'Ext.grid.filters.filter.TriFilter',
    menuItems: [
        'lt',
        'gt',
        '-',
        'eq',
        'in'
    ],
    constructor: function(config) {
        var me = this,
            stateful = false,
            filter = {},
            filterGt, filterLt, filterEq, filterIn, value, operator;
        me.callParent([
            config
        ]);
        value = me.value;
        filterLt = me.getStoreFilter('lt');
        filterGt = me.getStoreFilter('gt');
        filterEq = me.getStoreFilter('eq');
        filterIn = me.getStoreFilter('in');

        if (filterLt || filterGt || filterEq || filterIn) {
            stateful = me.active = true;
            if (filterLt) {
                me.onStateRestore(filterLt);
            }
            if (filterGt) {
                me.onStateRestore(filterGt);
            }
            if (filterEq) {
                me.onStateRestore(filterEq);
            }
            if (filterIn) {
                me.onStateRestore(filterIn);
            }
        } else {
            if (me.grid.stateful && me.getGridStore().saveStatefulFilters) {
                value = undefined;
            }
            me.active = me.getActiveState(config, value);
        }
        filter.lt = filterLt || me.createFilter({
            operator: 'lt',
            value: (!stateful && value && value.lt) || null
        }, 'lt');
        filter.gt = filterGt || me.createFilter({
            operator: 'gt',
            value: (!stateful && value && value.gt) || null
        }, 'gt');
        filter.eq = filterEq || me.createFilter({
            operator: 'eq',
            value: (!stateful && value && value.eq) || null
        }, 'eq');
        filter.in = filterIn || me.createFilter({
            operator: 'in',
            type: 'numeric',
            value: (!stateful && value && value.in) || null
        }, 'in');
        me.filter = filter;
        if (me.active) {
            me.setColumnActive(true);
            if (!stateful) {
                for (operator in value) {
                    me.addStoreFilter(me.filter[operator]);
                }
            }
        }
    },
    setValue: function(value) {
        var me = this,
            filters = me.filter,
            add = [],
            remove = [],
            active = false,
            filterCollection = me.getGridStore().getFilters(),
            field, filter, v, i, len, rLen, aLen;
        if (me.preventFilterRemoval) {
            return;
        }
        me.preventFilterRemoval = true;
        if ('eq' in value) {
            v = filters.lt.getValue();
            if (v || v === 0) {
                remove.push(filters.lt);
            }
            v = filters.gt.getValue();
            if (v || v === 0) {
                remove.push(filters.gt);
            }
            v = filters.in.getValue();
            if (v || v === 0) {
                remove.push(filters.in);
            }
            v = value.eq;
            if (v || v === 0) {
                add.push(filters.eq);
                filters.eq.setValue(v);
            } else {
                remove.push(filters.eq);
            }
        } else {
            v = filters.eq.getValue();
            if (v || v === 0) {
                remove.push(filters.eq);
            }
            if ('lt' in value) {
                v = value.lt;
                if (v || v === 0) {
                    add.push(filters.lt);
                    filters.lt.setValue(v);
                } else {
                    remove.push(filters.lt);
                }
            }
            if ('gt' in value) {
                v = value.gt;
                if (v || v === 0) {
                    add.push(filters.gt);
                    filters.gt.setValue(v);
                } else {
                    remove.push(filters.gt);
                }
            }
            if ('in' in value) {
                v = value.in;
                if (typeof v === "object" && v[0][0] == '') {
                    remove.push(filters.in);
                } else if (v || v === 0) {
                    add.push(filters.in);
                    filters.in.setValue(v);
                } else {
                    remove.push(filters.in);
                }
            }
        }
        rLen = remove.length;
        aLen = add.length;
        active = !!(me.countActiveFilters() + aLen - rLen);
        if (rLen || aLen || active !== me.active) {
            filterCollection.beginUpdate();
            if (rLen) {
                for (i = 0; i < rLen; i++) {
                    filter = remove[i];
                    me.fields[filter.getOperator()].setValue(null);
                    filter.setValue(null);
                    me.removeStoreFilter(filter);
                }
            }
            if (aLen) {
                for (i = 0; i < aLen; i++) {
                    me.addStoreFilter(add[i]);
                }
            }
            me.setActive(active);
            filterCollection.endUpdate();
        }
        me.preventFilterRemoval = false;
    }
});

Ext.define('Ext.grid.filters.filter.Number', {
    extend: 'Ext.local.grid.filters.filter.TriFilter',
    alias: ['grid.filter.number', 'grid.filter.numeric'],

    uses: ['Ext.form.field.Number'],

    type: 'number',

    config: {
        fields: {
            gt: {
                iconCls: Ext.baseCSSPrefix + 'grid-filters-gt',
                margin: '0 0 3px 0'
            },
            lt: {
                iconCls: Ext.baseCSSPrefix + 'grid-filters-lt',
                margin: '0 0 3px 0'
            },
            eq: {
                iconCls: Ext.baseCSSPrefix + 'grid-filters-eq',
                margin: '0 0 3px 0'
            },
            in: {
                iconCls: Ext.baseCSSPrefix + 'grid-filters-find',
                margin: 0
            }
        }
    },

    itemDefaults: {
        enableKeyEvents: true,
        hideEmptyLabel: false,
        labelSeparator: '',
        labelWidth: 29,
        selectOnFocus: false
    },

    menuDefaults: {
        bodyPadding: 3,
        showSeparator: false
    },

    createMenu: function() {
        var me = this,
            listeners = {
                scope: me,
                keyup: me.onValueChange,
                spin: {
                    fn: me.onInputSpin,
                    buffer: 200
                },
                el: {
                    click: me.stopFn
                }
            },
            itemDefaults = me.getItemDefaults(),
            menuItems = me.menuItems,
            fields = me.getFields(),
            field, i, len, key, item, cfg;

        me.callParent();

        me.fields = {};

        for (i = 0, len = menuItems.length; i < len; i++) {
            key = menuItems[i];

            if (key !== '-' && key !== 'in') {
                itemDefaults.xtype = 'numberfield';
                field = fields[key];

                cfg = {
                    labelClsExtra: Ext.baseCSSPrefix + 'grid-filters-icon ' + field.iconCls,
                    emptyText: 'Enter Number...'
                };

                if (itemDefaults) {
                    Ext.merge(cfg, itemDefaults);
                }

                Ext.merge(cfg, field);
                cfg.emptyText = cfg.emptyText || me.emptyText;
                delete cfg.iconCls;

                me.fields[key] = item = me.menu.add(cfg);

                item.filter = me.filter[key];
                item.filterKey = key;
                item.on(listeners);
            } else if (key === 'in') {
                itemDefaults.xtype = 'textfield';
                field = fields.in;

                cfg = {
                    labelClsExtra: Ext.baseCSSPrefix + 'grid-filters-icon ' + field.iconCls,
                    emptyText: 'Enter Numbers...'
                };

                if (itemDefaults) {
                    Ext.merge(cfg, itemDefaults);
                }

                Ext.merge(cfg, field);
                cfg.emptyText = cfg.emptyText || me.emptyText;
                delete cfg.iconCls;

                me.fields[key] = item = me.menu.add(cfg);

                item.filter = me.filter[key];
                item.filterKey = key;
                item.on(listeners);
            }
            else {
                me.menu.add(key);
            }
        }
    },
    getValue: function(field) {
        var value = {};

        value[field.filterKey] = field.getValue();

        return value;
    },
    onInputSpin: function(field, direction) {
        var value = {};

        value[field.filterKey] = field.getValue();

        this.setValue(value);
    },
    stopFn: function(e) {
        e.stopPropagation();
    }
});
