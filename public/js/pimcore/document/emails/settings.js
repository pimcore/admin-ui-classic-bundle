/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.document.emails.settings");
/**
 * @private
 */
pimcore.document.emails.settings = Class.create(pimcore.document.settings_abstract, {

    getLayout: function () {

        if (this.layout == null) {

            this.layout = Ext.create('Ext.form.Panel', {

                title: t('settings'),
                bodyStyle:'padding:0 10px 0 10px;',
                border: false,
                autoScroll: true,
                iconCls: "pimcore_material_icon_settings pimcore_material_icon",
                items: [
                    {
                        xtype:'fieldset',
                        title: t('email_settings'),
                        collapsible: true,
                        autoHeight:true,
                        labelWidth: 200,
                        defaultType: 'textfield',
                        defaults: {width: 700},
                        items :[
                            {
                                fieldLabel: t('email_subject'),
                                name: 'subject',
                                value: this.document.data.subject
                            },
                            {
                                fieldLabel: t('email_from'),
                                name: 'from',
                                value: this.document.data.from
                            },
                            {
                                fieldLabel: t('email_reply_to'),
                                name: 'replyTo',
                                value: this.document.data.replyTo
                            },
                            {
                                fieldLabel: t('email_to'),
                                name: 'to',
                                value: this.document.data.to
                            },
                            {
                                fieldLabel: t('email_cc'),
                                name: 'cc',
                                value: this.document.data.cc
                            },
                            {
                                fieldLabel: t('email_bcc'),
                                name: 'bcc',
                                value: this.document.data.bcc
                            },
                            {
                                xtype: "displayfield",
                                value: t("email_settings_receiver_description"),
                                style: "font-size: 10px;"
                            }
                        ]
                    },
                    this.getControllerViewFields(),
                    this.getPathAndKeyFields()
                ]
            });
        }

        return this.layout;
    }

});
