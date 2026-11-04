<?php

declare(strict_types=1);

/*
 * CoreShop
 *
 * This source file is available under two different licenses:
 *  - GNU General Public License version 3 (GPLv3)
 *  - CoreShop Commercial License (CCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) CoreShop GmbH (https://www.coreshop.com)
 * @license    https://www.coreshop.com/license     GPLv3 and CCL
 *
 */

namespace CoreShop\Bundle\OrderBundle\Controller;

use CoreShop\Component\Order\Model\OrderDocumentInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\OrderSaleStates;
use Pimcore\Model\Element\ElementInterface;

/**
 * Server-side permission checks for the order, quote and cart admin endpoints.
 */
trait SalePermissionTrait
{
    /**
     * Requires the `<saleState>_detail` permission and the Pimcore object ACL ($acl) on the sale.
     */
    protected function checkSalePermission(OrderInterface $sale, string $acl = 'view'): void
    {
        $saleState = $sale->getSaleState();

        if (!in_array($saleState, [OrderSaleStates::STATE_ORDER, OrderSaleStates::STATE_QUOTE, OrderSaleStates::STATE_CART], true)) {
            $saleState = OrderSaleStates::STATE_ORDER;
        }

        $this->checkPermission(sprintf('coreshop_permission_%s_detail', $saleState));

        if (!$sale instanceof ElementInterface || !$sale->isAllowed($acl, $this->getPimcoreUser())) {
            throw $this->createAccessDeniedHttpException();
        }
    }

    /**
     * Checks the permission on the order an invoice or shipment belongs to.
     */
    protected function checkDocumentPermission(OrderDocumentInterface $document, string $acl = 'view'): void
    {
        $order = $document->getOrder();

        if (!$order instanceof OrderInterface) {
            throw $this->createAccessDeniedHttpException();
        }

        $this->checkSalePermission($order, $acl);
    }

    /**
     * Requires any of the `*_detail` permissions, for actions that are not bound to one sale.
     */
    protected function checkAnySaleDetailPermission(): void
    {
        $this->checkPermissionsHasOneOf([
            'coreshop_permission_order_detail',
            'coreshop_permission_quote_detail',
            'coreshop_permission_cart_detail',
        ]);
    }

    /**
     * Requires the `<saleType>_create` permission.
     */
    protected function checkSaleCreatePermission(mixed $saleType): void
    {
        if (!in_array($saleType, [OrderSaleStates::STATE_ORDER, OrderSaleStates::STATE_QUOTE, OrderSaleStates::STATE_CART], true)) {
            throw $this->createAccessDeniedHttpException();
        }

        $this->checkPermission(sprintf('coreshop_permission_%s_create', $saleType));
    }

    /**
     * Requires any of the `*_create` permissions (backend order, quote and cart creation).
     */
    protected function checkAnySaleCreatePermission(): void
    {
        $this->checkPermissionsHasOneOf([
            'coreshop_permission_order_create',
            'coreshop_permission_quote_create',
            'coreshop_permission_cart_create',
        ]);
    }
}
