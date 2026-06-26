pimcore.registerNS('coreshop.orderreturn.orderreturn.list');
coreshop.orderreturn.orderreturn.list = Class.create(coreshop.resource.list, {
    supportsCreate: false,
    type: 'order_return',

    generateUrl: function() {
        return Routing.generate('coreshop_admin_order_get_folder_configuration', {'saleType': this.type});
    },

    setupContextMenuPlugin: function () {
        this.contextMenuPlugin = new coreshop.pimcore.plugin.grid(
            'coreshop_order_return',
            function (id) {
                this.open(id);
            }.bind(this),
            [coreshop.class_map.coreshop.order_return],
            this.getGridPaginator()
        );
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
    }
});
