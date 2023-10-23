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

pimcore.registerNS('pimcore.helpers.headbar-submenu');

const headbarSubmenuConfigBuilder = function(extConfig = {}) {
    let _config = {};

    let _groups = {};

    ((extConfig) => {
        _config = createInitialMenu(extConfig);
    })(extConfig)

    function createInitialMenu(extConfig = {}) {
        return {
            cls: 'x-btn-default-toolbar-medium',
            iconCls: 'pimcore_icon_more_white',
            menu: [],
            ...extConfig
        }
    }

    function getGroup(groupName) {
        if (!_groups[groupName]) {
            _groups[groupName] = [];
        }

        return _groups[groupName];
    }

    this.addItemToGroup = (groupName, extConfig =  {}) => {
        getGroup(groupName).push({
            ...extConfig
        });
    }

    function getSeparator() {
        return {
            xtype: 'menuseparator'
        }
    }

    this.getConfig = (...groupNames) => {
        let iteration = 0;

        for (const groupName of groupNames) {
            if (!_groups[groupName]) {
                continue;
            }

            _config.menu.push(..._groups[groupName]);

            if (iteration++ !== groupNames.length - 1) {
                _config.menu.push(getSeparator());
            }
        }

        return _config;
    }

    return this;
}

pimcore.helpers.headbarSubmenu = (() => {
    let _submenu = undefined;

    this.getConfigBuilder =  () => {
        return new headbarSubmenuConfigBuilder();
    }

    this.getSubmenuConfig = (extConfig = {}) => {
        return {
            cls: 'x-btn-default-toolbar-medium',
            iconCls: 'pimcore_icon_more_white',
            menu: [],
            ...extConfig
        }
    }

    return this;
})();
