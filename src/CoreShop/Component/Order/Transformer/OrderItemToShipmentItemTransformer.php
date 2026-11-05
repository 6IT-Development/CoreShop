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
use CoreShop\Component\Order\Model\OrderDocumentItemInterface;
use CoreShop\Component\Order\Model\OrderItemInterface;
use CoreShop\Component\Order\Model\OrderShipmentItemInterface;
use CoreShop\Component\Pimcore\DataObject\VersionHelper;
use CoreShop\Component\Resource\Service\FolderCreationServiceInterface;
use Webmozart\Assert\Assert;

class OrderItemToShipmentItemTransformer implements OrderDocumentItemTransformerInterface
{
    public function __construct(
        protected FolderCreationServiceInterface $folderCreationService,
        protected TransformerEventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function transform(
        OrderDocumentInterface $orderDocument,
        OrderItemInterface $orderItem,
        OrderDocumentItemInterface $documentItem,
        float $quantity,
        array $options = [],
    ): OrderDocumentItemInterface {
        Assert::isInstanceOf($documentItem, OrderShipmentItemInterface::class);

        $this->eventDispatcher->dispatchPreEvent(
            'shipment_item',
            $documentItem,
            [
                'shipment' => $orderDocument,
                'order' => $orderItem->getOrder(),
                'order_item' => $orderItem,
                'options' => $options,
            ],
        );

        $itemFolder = $this->folderCreationService->createFolderForResource($documentItem, ['prefix' => $orderDocument->getFullPath()]);

        $documentItem->setKey($orderItem->getKey());
        $documentItem->setParent($itemFolder);
        $documentItem->setPublished(true);

        $documentItem->setOrderItem($orderItem);
        $documentItem->setQuantity($quantity);
        /*
         * Take the share of the order item total, it contains the item level discounts (cart item rules)
         * which the unit price does not
         */
        $documentItem->setTotal($this->getShareOfTotal($orderItem->getTotal(true), $orderItem, $quantity), true);
        $documentItem->setTotal($this->getShareOfTotal($orderItem->getTotal(false), $orderItem, $quantity), false);

        $documentItem->setConvertedTotal($this->getShareOfTotal($orderItem->getConvertedTotal(true), $orderItem, $quantity), true);
        $documentItem->setConvertedTotal($this->getShareOfTotal($orderItem->getConvertedTotal(false), $orderItem, $quantity), false);

        VersionHelper::useVersioning(function () use ($documentItem) {
            $documentItem->save();
        }, false);

        $this->eventDispatcher->dispatchPostEvent(
            'shipment_item',
            $documentItem,
            [
                'shipment' => $orderDocument,
                'order' => $orderItem->getOrder(),
                'order_item' => $orderItem,
                'options' => $options,
            ],
        );

        return $documentItem;
    }

    private function getShareOfTotal(int $total, OrderItemInterface $orderItem, float $quantity): int
    {
        $orderedQuantity = (float) $orderItem->getQuantity();

        if ($orderedQuantity <= 0) {
            return 0;
        }

        return (int) round($total * min($quantity, $orderedQuantity) / $orderedQuantity);
    }
}
