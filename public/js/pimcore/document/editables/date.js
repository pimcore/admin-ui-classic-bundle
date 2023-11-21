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

pimcore.registerNS("pimcore.document.editables.date");
/**
 * @private
 */
pimcore.document.editables.date = Class.create(pimcore.document.editable, {

    initialize: function($super, id, name, config, data, inherited) {
        $super(id, name, config, data, inherited);

        this.config.name = id + "_editable";
        this.data = null;
        if(data) {
            this.data = new Date(intval(data) * 1000);
        }
    },

    render: function () {
        this.setupWrapper();
        
        if (this.config.format && this.config.format.includes('%')) {
            console.warn('Deprecated: Date format contains % symbols which is used for strftime, please the use parameters according Ext.Date formatting syntax instead.');

            // replace any % prefixed parts from strftime format
            this.config.format = this.config.format.replace(/%([a-zA-Z])/g, '$1');
        }
        
        if(this.data) {
            this.config.value = this.data;
        }

        this.element = new Ext.form.DateField(this.config);
        if (this.config["reload"]) {
            this.element.on("change", this.reloadDocument);
        }

        this.element.render(this.id);
    },

    getValue: function () {
        if(this.element) {
            return this.element.getValue();
        } else if (this.data) {
            return Ext.Date.format(this.data, "Y-m-d\\TH:i:s");
        }
    },

    getType: function () {
        return "date";
    }
});
