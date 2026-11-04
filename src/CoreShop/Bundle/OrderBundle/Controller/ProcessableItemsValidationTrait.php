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

use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\Processable\ProcessableInterface;

trait ProcessableItemsValidationTrait
{
    /**
     * Checks the items posted for a new invoice or shipment against what is still processable
     * for the order. Returns an error message, or null if the items are valid.
     */
    protected function validateProcessableItems(ProcessableInterface $processable, OrderInterface $order, mixed $items): ?string
    {
        if (!$processable->isProcessable($order)) {
            return 'The current order state does not allow to create this document';
        }

        if (!is_array($items) || count($items) === 0) {
            return 'No items given';
        }

        $processableItems = $processable->getProcessableItems($order);
        $requested = [];

        foreach ($items as $item) {
            $orderItemId = (int) ($item['orderItemId'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 0);

            if (!array_key_exists($orderItemId, $processableItems)) {
                return sprintf('Order item %s does not belong to this order or is already fully processed', $orderItemId);
            }

            if ($quantity <= 0) {
                return sprintf('Invalid quantity for order item %s', $orderItemId);
            }

            $requested[$orderItemId] = ($requested[$orderItemId] ?? 0) + $quantity;
        }

        foreach ($requested as $orderItemId => $quantity) {
            if ($quantity - (float) $processableItems[$orderItemId]['quantity'] > 1e-6) {
                return sprintf(
                    'Quantity %s for order item %s exceeds the remaining quantity %s',
                    $quantity,
                    $orderItemId,
                    $processableItems[$orderItemId]['quantity'],
                );
            }
        }

        return null;
    }
}
