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

namespace CoreShop\Bundle\OrderBundle\Expiration;

use CoreShop\Bundle\WorkflowBundle\History\HistoryLoggerInterface;
use CoreShop\Bundle\WorkflowBundle\Manager\StateMachineManagerInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\OrderTransitions;
use CoreShop\Component\Order\Repository\OrderRepositoryInterface;
use CoreShop\Component\Payment\Model\PaymentInterface;
use CoreShop\Component\Payment\Repository\PaymentRepositoryInterface;
use CoreShop\Component\StorageList\Expiration\StorageListExpirationInterface;
use Pimcore\Model\DataObject\Concrete;

final class OrderExpiration implements StorageListExpirationInterface
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private StateMachineManagerInterface $stateMachineManager,
        private HistoryLoggerInterface $historyLogger,
        private PaymentRepositoryInterface $paymentRepository,
    ) {
    }

    public function expire(int $days, array $params = []): void
    {
        if ($days <= 0) {
            return;
        }

        $orders = $this->orderRepository->findExpiredOrders($days);

        foreach ($orders as $order) {
            if ($this->hasActivePayment($order)) {
                continue;
            }

            $workflow = $this->stateMachineManager->get($order, OrderTransitions::IDENTIFIER);

            if (!$workflow->can($order, OrderTransitions::TRANSITION_CANCEL)) {
                continue;
            }

            $workflow->apply($order, OrderTransitions::TRANSITION_CANCEL);

            if ($order instanceof Concrete) {
                $this->historyLogger->log(
                    $order,
                    'Automatic Expiration Order Cancellation',
                );
            }
        }
    }

    /**
     * A payment that is being processed, authorized or completed means the customer is paying
     * (or has paid), so the order must not be expired.
     */
    private function hasActivePayment(OrderInterface $order): bool
    {
        foreach ($this->paymentRepository->findForPayable($order) as $payment) {
            if (in_array($payment->getState(), [
                PaymentInterface::STATE_PROCESSING,
                PaymentInterface::STATE_AUTHORIZED,
                PaymentInterface::STATE_COMPLETED,
            ], true)) {
                return true;
            }
        }

        return false;
    }
}
