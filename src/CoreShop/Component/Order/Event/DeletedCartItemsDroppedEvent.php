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

namespace CoreShop\Component\Order\Event;

use CoreShop\Component\Order\Model\OrderInterface;

/**
 * CartManager::persistCart() left out items that were deleted after this copy of the cart was loaded, by code that
 * changes a stored cart without moving its persist version (e.g. a product that can no longer be bought is removed
 * from every cart). That code may have stored other fields of the cart too, which this copy is about to overwrite:
 * listeners copy them over from the stored cart before it is saved.
 */
final class DeletedCartItemsDroppedEvent
{
    /**
     * @param list<int> $droppedItemIds
     */
    public function __construct(
        private OrderInterface $cart,
        private array $droppedItemIds,
    ) {
    }

    public function getCart(): OrderInterface
    {
        return $this->cart;
    }

    /**
     * @return list<int>
     */
    public function getDroppedItemIds(): array
    {
        return $this->droppedItemIds;
    }
}
