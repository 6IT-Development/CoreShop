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

namespace CoreShop\Component\Order;

use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\Repository\OrderInvoiceRepositoryInterface;
use CoreShop\Component\Order\Repository\OrderShipmentRepositoryInterface;

class OrderEditPossible implements OrderEditPossibleInterface
{
    public function __construct(
        private bool $orderEditEnabled,
        private ?OrderInvoiceRepositoryInterface $orderInvoiceRepository = null,
        private ?OrderShipmentRepositoryInterface $orderShipmentRepository = null,
    ) {
    }

    public function isOrderEditable(OrderInterface $order): bool
    {
        if (!$this->orderEditEnabled) {
            return false;
        }

        if ($order->getSaleState() === OrderSaleStates::STATE_QUOTE) {
            return !in_array($order->getQuoteState(), [QuoteStates::STATE_CANCELLED, QuoteStates::STATE_COMPLETE], true);
        }

        if ($order->getSaleState() === OrderSaleStates::STATE_ORDER) {
            if (in_array($order->getOrderState(), [OrderStates::STATE_CANCELLED, OrderStates::STATE_COMPLETE], true)) {
                return false;
            }

            /**
             * Once a payment has been authorized or (partially) paid, the amount is fixed. Changing the order
             * also means that the remaining amount has to be paid as well, that is currently not reflectable
             * Allowing that, also means that we have to find a way to do further Payments to the Order
             * Might come in the future
             */
            if (!in_array($order->getPaymentState(), [null, OrderPaymentStates::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, OrderPaymentStates::STATE_CANCELLED], true)) {
                return false;
            }

            /**
             * Invoice and shipment items reference the order items, so an order item must not be removed
             * or reduced below what has already been invoiced or shipped.
             */
            if (!in_array($order->getInvoiceState(), [null, OrderInvoiceStates::STATE_NEW, OrderInvoiceStates::STATE_READY], true)) {
                return false;
            }

            if (!in_array($order->getShippingState(), [null, OrderShipmentStates::STATE_NEW, OrderShipmentStates::STATE_READY], true)) {
                return false;
            }

            // An invoice or shipment that is not completed yet leaves the order state at "ready"
            if (null !== $this->orderInvoiceRepository && $this->orderInvoiceRepository->getDocumentsNotInState($order, InvoiceStates::STATE_CANCELLED)) {
                return false;
            }

            if (null !== $this->orderShipmentRepository && $this->orderShipmentRepository->getDocumentsNotInState($order, ShipmentStates::STATE_CANCELLED)) {
                return false;
            }
        }

        return true;
    }
}
