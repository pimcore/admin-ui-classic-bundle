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

pimcore.registerNS("pimcore.sanitizer");

pimcore.sanitizer = Class.create({
    allowedTags: {},

    constructor: function () {
        if(this.constructor.name === "pimcore.sanitizer"){
            throw new Error("pimcore.sanitizer is a static class and can not be instantiated.");
        }
    },

    sanitize: function (string) {
        const parser = new DOMParser();
        const testDom = parser.parseFromString(string, "text/html");

        const htmlNodes = testDom.body.childNodes;

        htmlNodes.forEach((node) => {
            if (node.nodeName !== '#text') {
                this.sanitizeNode(node);
            }
        });

        return testDom.body.innerHTML;
    },

    sanitizeNode: function (node) {
        if(node.hasChildNodes()){
            node.childNodes.forEach((childNode) => {
                if (childNode.nodeName !== '#text') {
                    this.sanitizeNode(childNode);
                }
            });
        }

        if (this.allowedTags[node.nodeName.toLowerCase()]) {
            const allowedAttributes = this.allowedTags[node.nodeName.toLowerCase()];
            const attributes = node.attributes;

            for (let i = 0; i < attributes.length; i++) {
                const attribute = attributes[i];

                if (allowedAttributes === true) {
                    continue;
                }

                if (allowedAttributes.indexOf(attribute.name) === -1) {
                    node.removeAttribute(attribute.name);
                }
            }
        }

        if (!this.allowedTags[node.nodeName.toLowerCase()]) {
            node.remove();
        }
    }
});