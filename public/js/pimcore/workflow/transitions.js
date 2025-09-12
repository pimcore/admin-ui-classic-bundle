/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.workflow.transitions.x");

/**
 * @private
 */
pimcore.workflow.transitions.perform = function (ctype, cid, elementEditor, workflow, transition) {


    Ext.Ajax.request({
        url : transition.isGlobalAction ? Routing.generate('pimcore_admin_workflow_submitglobal') : Routing.generate('pimcore_admin_workflow_submitworkflowtransition'),
        method: 'post',
        params: {
            ctype: ctype,
            cid: cid,
            workflowName: workflow,
            transition: transition.name
        },
        success: function(response) {
            var data = Ext.decode(response.responseText);

            if (data.success) {

                pimcore.helpers.showNotification(t("workflow_transition_applied_successfully"), t(transition.label), "success");

                elementEditor.reload({layoutId: transition.objectLayout});

            } else {
                Ext.MessageBox.alert(t(data.message), data.reasons.map(function(reason){ return t(reason); }).join('<br>'));
            }


        },
    });
};
