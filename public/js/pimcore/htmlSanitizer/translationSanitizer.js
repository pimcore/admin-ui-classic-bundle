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

pimcore.registerNS("pimcore.htmlSanitizer.translationSanitizer");

/**
 * @internal
 */
pimcore.htmlSanitizer.translationSanitizer = Class.create(pimcore.htmlSanitizer, {
    allowedTags: {
        span: [ 'class', 'style', 'id' ],
        p: [ 'class', 'style', 'id' ],
        strong: 'class',
        em: 'class',
        h1: [ 'class', 'id' ],
        h2: [ 'class', 'id' ],
        h3: [ 'class', 'id' ],
        h4: [ 'class', 'id' ],
        h5: [ 'class', 'id' ],
        h6: [ 'class', 'id' ],
        a: [ 'class', 'id', 'href', 'target', 'title', 'rel' ]
    }
});