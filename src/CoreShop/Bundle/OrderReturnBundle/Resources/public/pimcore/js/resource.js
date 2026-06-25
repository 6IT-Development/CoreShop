pimcore.registerNS('coreshop.orderreturn.resource');
coreshop.orderreturn.resource = Class.create(coreshop.resource, {
    initialize: function () {
        coreshop.broker.fireEvent('resource.register', 'coreshop.orderreturn', this);
    },

    openResource: function (item) {
        if (item === 'order_returns') {
            this.openOrderReturns()
        }
    },
    openOrderReturns: function () {
        try {
            pimcore.globalmanager.get('coreshop_order_returns').activate();
        }
        catch (e) {
            pimcore.globalmanager.add(
                'coreshop_order_returns', new coreshop.orderreturn.orderreturn.list()
            );
        }
    },
});

coreshop.broker.addListener('pimcore.ready', function() {
    new coreshop.orderreturn.resource();
});
