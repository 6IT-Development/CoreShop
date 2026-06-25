pimcore.registerNS('coreshop.orderreturn.events');

coreshop.orderreturn.events = {
    addMenu: function (menu, broker) {
        var orderMenu = menu.down('#coreshop_menu_orders');
        if (orderMenu) {
            orderMenu.add({
                text: t('coreshop_order_returns'),
                iconCls: 'coreshop_icon_order_return',
                handler: function () {
                    coreshop.broker.fireEvent('resource.open', 'order_returns');
                }
            });
        }
    }
};

coreshop.broker.addListener('coreshop.menu.coreshop', coreshop.orderreturn.events.addMenu);
