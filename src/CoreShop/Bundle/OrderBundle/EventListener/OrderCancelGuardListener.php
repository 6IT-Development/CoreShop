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

namespace CoreShop\Bundle\OrderBundle\EventListener;

use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\OrderInvoiceStates;
use CoreShop\Component\Order\OrderPaymentStates;
use CoreShop\Component\Order\OrderShipmentStates;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * Cancelling an order cascades to its payment, shipment and invoice states, but those
 * can only be cancelled before anything was paid, shipped or invoiced. Block the cancel
 * instead of leaving e.g. a partially paid payment on a cancelled order: refund the
 * payment first, a fully refunded order can be cancelled.
 */
final class OrderCancelGuardListener
{
    private const PAYMENT_STATES_BLOCKING_CANCEL = [
        OrderPaymentStates::STATE_PARTIALLY_PAID,
        OrderPaymentStates::STATE_PAID,
        OrderPaymentStates::STATE_PARTIALLY_REFUNDED,
    ];

    private const SHIPMENT_STATES_BLOCKING_CANCEL = [
        OrderShipmentStates::STATE_PARTIALLY_SHIPPED,
        OrderShipmentStates::STATE_SHIPPED,
    ];

    private const INVOICE_STATES_BLOCKING_CANCEL = [
        OrderInvoiceStates::STATE_PARTIALLY_INVOICED,
        OrderInvoiceStates::STATE_INVOICED,
    ];

    public function guardCancel(GuardEvent $event): void
    {
        $order = $event->getSubject();

        if (!$order instanceof OrderInterface) {
            return;
        }

        if (in_array($order->getPaymentState(), self::PAYMENT_STATES_BLOCKING_CANCEL, true)) {
            $event->addTransitionBlocker(new TransitionBlocker('The order cannot be cancelled while it is (partially) paid.', 'coreshop_order_cancel_paid'));
        }

        if (in_array($order->getShippingState(), self::SHIPMENT_STATES_BLOCKING_CANCEL, true)) {
            $event->addTransitionBlocker(new TransitionBlocker('The order cannot be cancelled while it is (partially) shipped.', 'coreshop_order_cancel_shipped'));
        }

        if (in_array($order->getInvoiceState(), self::INVOICE_STATES_BLOCKING_CANCEL, true)) {
            $event->addTransitionBlocker(new TransitionBlocker('The order cannot be cancelled while it is (partially) invoiced.', 'coreshop_order_cancel_invoiced'));
        }
    }
}
