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

namespace CoreShop\Component\Core\Order\Transformer;

use CoreShop\Component\Core\Model\OrderItemInterface;
use CoreShop\Component\Core\Model\OrderShipmentInterface;
use CoreShop\Component\Order\Model\OrderDocumentInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\Transformer\OrderDocumentTransformerInterface;

final class OrderToShipmentTransformer implements OrderDocumentTransformerInterface
{
    public function __construct(
        private OrderDocumentTransformerInterface $inner,
    ) {
    }

    public function transform(
        OrderInterface $order,
        OrderDocumentInterface $document,
        array $itemsToTransform,
    ): OrderDocumentInterface {
        if ($document instanceof OrderShipmentInterface && $order instanceof \CoreShop\Component\Core\Model\OrderInterface) {
            $document->setWeight($this->getShipmentWeight($order, $itemsToTransform));
        }

        return $this->inner->transform($order, $document, $itemsToTransform);
    }

    /**
     * A shipment may contain only some of the order items, or only a part of their quantity,
     * so its weight is the weight of the items it ships, not the weight of the whole order.
     */
    private function getShipmentWeight(\CoreShop\Component\Core\Model\OrderInterface $order, array $itemsToTransform): float
    {
        $weight = 0.0;

        foreach ($itemsToTransform as $itemToTransform) {
            foreach ($order->getItems() ?? [] as $orderItem) {
                if (
                    $orderItem instanceof OrderItemInterface &&
                    (int) $orderItem->getId() === (int) ($itemToTransform['orderItemId'] ?? 0)
                ) {
                    $weight += ($orderItem->getItemWeight() ?? 0.0) *
                        (float) ($itemToTransform['quantity'] ?? 0) *
                        (float) ($orderItem->getUnitDefinition()?->getConversionRate() ?? 1.0);

                    break;
                }
            }
        }

        return $weight;
    }
}
