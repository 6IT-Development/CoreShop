/*
 * CoreShop.
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) CoreShop GmbH (https://www.coreshop.com)
 * @license    https://www.coreshop.com/license     GPLv3 and CCL
 *
 */
pimcore.registerNS('coreshop.orderreturn.resource');
coreshop.orderreturn.resource = Class.create(coreshop.resource, {
    initialize: function () {

        coreshop.broker.fireEvent('resource.register', 'coreshop.orderreturn.resource', this);
    },

    openResource: function (item) {
        if (item === 'order_returns') {
            coreshop.helpers.openResource('order_return', 'coreshop_order_return', 'coreshop_icon_order_return', t('coreshop_order_returns'));
        }
    },
});

coreshop.broker.addListener('pimcore.ready', function() {
    new coreshop.orderreturn.resource();
});
