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

pimcore.registerNS('coreshop.order.order.detail.blocks.returns');
coreshop.order.order.detail.blocks.returns = Class.create(coreshop.order.order.detail.abstractBlock, {
    saleInfo: null,

    initBlock: function () {
        var me = this;

        me.layout = Ext.create('Ext.panel.Panel', {
            title: t('coreshop_order_returns'),
            margin: '0 0 20 0',
            border: true,
            flex: 6,
            iconCls: 'coreshop_icon_order_comments'
        });
    },

    loadList: function () {
        var me = this;

        me.layout.removeAll();
        me.layout.setLoading(t('loading'));

        Ext.Ajax.request({
            url: Routing.generate('coreshop_admin_order_return_list'),
            params: {
                id: me.sale.id
            },
            success: function (response) {
                const res = Ext.decode(response.responseText);
                me.layout.setLoading(false);

                if (res.success) {
                    if (res.returns.length === 0) {
                        me.layout.add({
                            'xtype': 'panel',
                            'html': '<span class="coreshop-order-return-nothing-found">' + t('coreshop_order_return_nothing_found') + '</span>'
                        })
                    } else {
                        Ext.each(res.returns, function (ret) {
                            me.addReturnToList(ret);
                        });
                    }
                } else {
                    Ext.Msg.alert(t('error'), res.message);
                }

            }
        });
    },

    addReturnToList: function (ret) {
        var me = this;

        var date = new Date(ret.creationDate * 1000);
        var dateString = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();

        var item = Ext.create('Ext.Component', {
            html: '<div class="coreshop-order-return-box" style="border: 1px solid #666; padding: 10px; margin-bottom: 10px; border-radius: 3px; cursor: pointer; background-color: #f9f9f9;">' +
                '<div style="font-weight: bold; margin-bottom: 5px;">' + t('coreshop_order_return_id') + ': ' + ret.id + '</div>' +
                '<div>' + t('coreshop_order_return_date') + ': ' + dateString + '</div>' +
                '</div>',
            listeners: {
                render: function (c) {
                    c.getEl().on('click', function () {
                        me.open(ret.id);
                    });
                }
            }
        });

        me.layout.add(item);
    },

    open: function (id, callback) {
        try {
            pimcore.helpers.openObject(id, 'object');
        } catch (e) {
            console.error(e);
            pimcore.helpers.showNotification(t('error'), t('problem_opening_new_target'), 'error');
        } finally {
            if (typeof callback === 'function') {
                callback();
            }
        }
    },

    getPriority: function () {
        return 20;
    },

    getPosition: function () {
        return 'right';
    },

    getPanel: function () {
        return this.layout;
    },

    updateSale: function () {
        var me = this;

        me.loadList();
    }
});
