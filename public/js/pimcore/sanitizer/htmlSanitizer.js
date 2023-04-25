pimcore.registerNS("pimcore.sanitizer.htmlSanitizer");

pimcore.sanitizer.htmlSanitizer = Class.create(pimcore.sanitizer, {
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