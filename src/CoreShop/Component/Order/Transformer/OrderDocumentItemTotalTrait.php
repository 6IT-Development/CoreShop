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

namespace CoreShop\Component\Order\Transformer;

use CoreShop\Component\Order\Model\OrderDocumentInterface;
use CoreShop\Component\Order\Model\OrderInvoiceItemInterface;
use CoreShop\Component\Order\Model\OrderItemInterface;
use CoreShop\Component\Order\Model\OrderShipmentItemInterface;
use CoreShop\Component\Order\Repository\OrderDocumentRepositoryInterface;

trait OrderDocumentItemTotalTrait
{
    /**
     * Sets the document item totals to its quantity share of the order item totals, which contain the item level
     * discounts. The share is taken from the quantity documented so far (including this item) and the amounts
     * the other documents already took are subtracted, so all documents of an order item add up to its total.
     */
    private function setDocumentItemTotals(
        OrderInvoiceItemInterface|OrderShipmentItemInterface $documentItem,
        OrderDocumentInterface $orderDocument,
        OrderItemInterface $orderItem,
        float $quantity,
        ?OrderDocumentRepositoryInterface $documentRepository,
        string $stateCancelled,
    ): void {
        $orderedQuantity = (float) $orderItem->getQuantity();
        $processed = [
            'quantity' => 0.0,
            'total_gross' => 0,
            'total_net' => 0,
            'converted_total_gross' => 0,
            'converted_total_net' => 0,
        ];

        if (null !== $documentRepository && null !== $orderItem->getOrder()) {
            foreach ($documentRepository->getDocumentsNotInState($orderItem->getOrder(), $stateCancelled) as $document) {
                if ($document->getId() === $orderDocument->getId()) {
                    continue;
                }

                foreach ($document->getItems() ?? [] as $processedItem) {
                    if (!$processedItem instanceof OrderInvoiceItemInterface && !$processedItem instanceof OrderShipmentItemInterface) {
                        continue;
                    }

                    if ($processedItem->getOrderItem()?->getId() !== $orderItem->getId()) {
                        continue;
                    }

                    $processed['quantity'] += (float) $processedItem->getQuantity();
                    $processed['total_gross'] += $processedItem->getTotal(true);
                    $processed['total_net'] += $processedItem->getTotal(false);
                    $processed['converted_total_gross'] += $processedItem->getConvertedTotal(true);
                    $processed['converted_total_net'] += $processedItem->getConvertedTotal(false);
                }
            }
        }

        $share = $orderedQuantity > 0 ? min($processed['quantity'] + $quantity, $orderedQuantity) / $orderedQuantity : 0.0;

        $documentItem->setTotal((int) round($orderItem->getTotal(true) * $share) - $processed['total_gross'], true);
        $documentItem->setTotal((int) round($orderItem->getTotal(false) * $share) - $processed['total_net'], false);

        $documentItem->setConvertedTotal((int) round($orderItem->getConvertedTotal(true) * $share) - $processed['converted_total_gross'], true);
        $documentItem->setConvertedTotal((int) round($orderItem->getConvertedTotal(false) * $share) - $processed['converted_total_net'], false);
    }
}
