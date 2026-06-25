pimcore.registerNS('coreshop.orderreturn.orderreturn.list');
coreshop.orderreturn.orderreturn.list = Class.create(coreshop.resource.list, {
    supportsCreate: true,
    type: 'order',

    generateUrl: function() {
        return Routing.generate('coreshop_admin_order_get_folder_configuration', {'saleType': this.type});
    },

    setupContextMenuPlugin: function () {
        this.contextMenuPlugin = new coreshop.pimcore.plugin.grid(
            'coreshop_order_returns',
            function (id) {
                this.open(id);
            }.bind(this),
            [coreshop.class_map.coreshop.order],
            this.getGridPaginator()
        );
    },

    open: function (id, callback) {
        console.log('Opening order with ID:', id);
        coreshop.order.helper.openOrder(id, callback);
    }
});
