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

namespace CoreShop\Bundle\OrderBundle\Twig;

use CoreShop\Bundle\WorkflowBundle\StateManager\WorkflowStateInfoManagerInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\OrderPaymentStates;
use CoreShop\Component\Order\OrderShipmentStates;
use CoreShop\Component\Order\OrderStates;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class OrderStateExtension extends AbstractExtension
{
    public function __construct(
        private WorkflowStateInfoManagerInterface $workflowStateManager,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('coreshop_order_state', [$this, 'getOrderState']),
        ];
    }

    public function getOrderState(OrderInterface $order): array
    {
        $orderState = $this->workflowStateManager->getStateInfo('coreshop_order', $order->getOrderState(), true);
        $paymentState = $this->workflowStateManager->getStateInfo('coreshop_order_payment', $order->getPaymentState(), true);
        $shippingState = $this->workflowStateManager->getStateInfo('coreshop_order_shipment', $order->getShippingState(), true);
        $invoiceState = $this->workflowStateManager->getStateInfo('coreshop_order_invoice', $order->getInvoiceState(), true);

        // the calculated state tries to get the recent state for a customer.
        // while the order is in progress (new or confirmed), show the payment state until it is paid,
        // then the shipping state until it is shipped.
        // A paid order is always confirmed, so checking only "new" would never reach the shipping state.
        $calculatedState = $orderState['label'];

        if (in_array($orderState['state'], [OrderStates::STATE_NEW, OrderStates::STATE_CONFIRMED], true)) {
            if ($paymentState['state'] !== OrderPaymentStates::STATE_PAID) {
                $calculatedState = $paymentState['label'];
            } elseif ($shippingState['state'] !== OrderShipmentStates::STATE_SHIPPED) {
                $calculatedState = $shippingState['label'];
            }
        }

        return [
            'orderState' => $orderState['label'],
            'paymentState' => $paymentState['label'],
            'shippingState' => $shippingState['label'],
            'invoiceState' => $invoiceState['label'],
            'calculatedState' => $calculatedState,
        ];
    }
}
