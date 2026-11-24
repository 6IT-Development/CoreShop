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

namespace CoreShop\Component\Order\Manager;

use CoreShop\Component\Order\Model\OrderInterface;

/**
 * For code that changes a stored cart: call lockCart() before the change, then change and persist the cart it
 * returns. The lock is the one CartManagerInterface::persistCart() takes, and persistCart() releases it. A cart that
 * was saved by another request since it was loaded is loaded again, and the cart contexts get the fresh copy
 * (StorageListReloadedEvent), so the change is made once, on the current state, and is not refused by persistCart()
 * (CartPersistConflictException).
 */
interface CartLockManagerInterface
{
    /**
     * Blocks until the cart is free. Returns the cart to change: the given one, or a fresh copy of it.
     */
    public function lockCart(OrderInterface $cart): OrderInterface;

    /**
     * For a change that is given up before persistCart(). Locks that are still held are also released after the
     * main request's response and on kernel.reset.
     */
    public function unlockCart(OrderInterface $cart): void;
}
