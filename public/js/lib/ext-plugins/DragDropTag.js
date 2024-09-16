/**
 * @author Adam Borowski
 * @see https://fiddle.sencha.com/#fiddle/i65
 */
Ext.define('cas.helper.plugin.DragDropTag', {
    extend: 'Ext.plugin.Abstract',
    alias: 'plugin.dragdroptag',
    requires: [],
    statics: {},
    init: function (cmp) {
        cmp.on('render', this.afterRender, this, { single: true });
    },
    afterRender: function () {
        let me = this.getCmp();
        me.boundList = me.getPicker();
        me.dragGroup = me.dropGroup = 'MultiselectDD-' + Ext.id();
        me.dragZone = Ext.create('Ext.dd.DragZone', me.itemList, {
            ddGroup: me.dragGroup,
            dragText: me.dragText,
            getDragData: function (e) {

                let sourceEl = e.getTarget(me.tagItemSelector, 10);

                if (sourceEl) {
                    let d = sourceEl.cloneNode(true);
                    d.id = Ext.id();
                    return {
                        ddel: d,
                        sourceEl: sourceEl,
                        repairXY: Ext.fly(sourceEl).getXY(),
                        sourceStore: me.store,
                        draggedRecord: me.getRecordByListItemNode(sourceEl)
                    }
                }
            },
            getRepairXY: function () {
                return this.dragData.repairXY;
            }
        });
        me.dropZone = Ext.create('Ext.dd.DropZone', me.itemList, {
            ddGroup: me.dropGroup,
            getTargetFromEvent: function (e) {
                let allItems = me.itemList.query(me.tagItemSelector, false);
                let mouseY = e.getY();
                let mouseX = e.getX();
                let itemsOnLine = [];
                let bestDistance = Infinity, bestIsAfter, bestItem;
                for (let i = 0; i < allItems.length; i++) {
                    let item = allItems[i];
                    let t = item.getY(), l = item.getX();
                    let b = item.getBottom(), r = item.getRight();
                    let middle = (l + r) / 2;
                    if (mouseY > t && mouseY < b) {
                        // cursor currently is at this item
                        let distance;
                        if (mouseX <= middle) {
                            // cursor is left of the element
                            distance = l - mouseX;
                            if (distance < bestDistance) {
                                bestDistance = distance;
                                bestIsAfter = false;
                                bestItem = item;
                            }
                        } else {
                            // cursor is right of the element
                            distance = mouseX - r;
                            if (distance < bestDistance) {
                                bestDistance = distance;
                                bestIsAfter = true;
                                bestItem = item;
                            }
                        }
                    }
                }
                if (bestItem)
                    return { element: bestItem, after: bestIsAfter };
            },
            onNodeEnter: function (target, dd, e, data) {
                Ext.fly(target.element).addCls('a-tagfield-highlight ' + (target.after ? 'after' : 'before'));
            },
            onNodeOut: function (target, dd, e, data) {
                Ext.fly(target.element).removeCls('a-tagfield-highlight after before');
            },
            onNodeOver: function (target, dd, e, data) {
                return Ext.dd.DropZone.prototype.dropAllowed;
            },
            onNodeDrop: function (target, dd, e, data) {
                let sourceIndex = Ext.fly(data.sourceEl).getAttribute('data-selectionindex');
                let targetIndex = parseInt(target.element.getAttribute('data-selectionindex'));
                let value = Ext.Array.clone(me.getValue());
                cas.helper.Array.moveItem(value, sourceIndex, targetIndex, target.after);
                me.setValue(null);
                me.setValue(value);
                return true;
            }
        });
    }
});
Ext.define('cas.helper.Array', {
    singleton: true,
    /**
     *
     * @param array
     * @param from index
     * @param to index
     * @param [after]
     */
    moveItem: function (array, from, to, after) {
        if (after === true) {
            if (from > to) to++;
        } else if (after === false) {
            if (from < to) to--;
        }
        array.splice(to, 0, array.splice(from, 1)[0]);
    }
});