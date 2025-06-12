/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS('pimcore.object.helpers.reservedWords');

pimcore.object.helpers.reservedWords = {
    // https://www.php.net/manual/en/reserved.keywords.php
    phpReservedKeywords: [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue',
        'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach',
        'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach',
        'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof',
        'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print', 'private', 'protected', 'public',
        'readonly', 'require', 'require_once', 'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use',
        'var', 'while', 'xor', 'yield', 'yield_from'
    ],

    // https://www.php.net/manual/en/reserved.classes.php
    phpReservedClasses: [
        'self', 'static', 'parent'
    ],

    // https://www.php.net/manual/en/reserved.other-reserved-words.php
    phpOtherReservedWords: [
        'int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never',
        'enum', 'resource', 'numeric'
    ],

    pimcore: [
        // Pimcore
        'data', 'folder', 'permissions', 'dao', 'concrete', 'items'
    ],

    isReservedWord: function (word) {
        return in_arrayi(word, this.getAllReservedWords());
    },

    getAllReservedWords: function () {
        return this.phpReservedKeywords.concat(
            this.phpReservedClasses,
            this.phpOtherReservedWords,
            this.pimcore
        );
    }
};