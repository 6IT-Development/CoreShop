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

pimcore.registerNS('coreshop.shippingrule.conditions.guest');
coreshop.shippingrule.conditions.guest = Class.create(coreshop.rules.conditions.abstract, {
    type: 'guest',

    getForm: function () {
        // rules saved before this option existed have no value and keep matching guests
        var guest = this.data && typeof this.data.guest !== 'undefined' ? !!this.data.guest : true;

        this.form = new Ext.form.Panel({
            items: [{
                fieldLabel: t('coreshop_condition_guest_is_guest'),
                xtype: 'checkbox',
                name: 'guest',
                checked: guest
            }]
        });

        return this.form;
    },

    getValues: function () {
        return {
            guest: this.form.getForm().findField('guest').getValue()
        };
    }
});
