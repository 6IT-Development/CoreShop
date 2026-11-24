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

use CoreShop\Component\Order\Manager\CartLockManagerInterface;
use CoreShop\Component\Order\Manager\CartManagerInterface;
use CoreShop\Component\Order\Model\OrderInterface;

/**
 * Concurrent saves of a cart or order from the admin (see CartManager): lock before the change, so it is applied to
 * the current copy, and answer a refused stale save (CartPersistConflictException) with the usual JSON error.
 */
trait CartConflictTrait
{
    /**
     * Call it after the permission checks and before the form or the modifier changes the cart. persistCart()
     * releases the lock, or the end of the request.
     *
     * @template T of OrderInterface
     *
     * @param T $cart
     *
     * @return T the given cart, or a fresh copy of the same class when another request saved it meanwhile
     */
    protected function lockCartForChange(CartManagerInterface $cartManager, OrderInterface $cart): OrderInterface
    {
        if (!$cartManager instanceof CartLockManagerInterface) {
            return $cart;
        }

        /** @var T $lockedCart */
        $lockedCart = $cartManager->lockCart($cart);

        return $lockedCart;
    }

    /**
     * @return array{success: false, message: string}
     */
    protected function cartConflictResponseData(): array
    {
        return [
            'success' => false,
            'message' => 'The order was saved by another request in the meantime and was not changed. Reload it and try again.',
        ];
    }
}
