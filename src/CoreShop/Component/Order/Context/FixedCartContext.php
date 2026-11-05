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

namespace CoreShop\Component\Order\Context;

use CoreShop\Component\Order\Model\OrderInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds a cart set explicitly (admin order creation, CLI, workers acting on a cart). It is asked before the
 * session and customer contexts, so it is request scoped: it is reset between requests (kernel.reset), and a
 * cart set once does not leak into later requests or messages of the same process.
 */
final class FixedCartContext implements CartContextInterface, ResetInterface
{
    private ?OrderInterface $cart = null;

    public function getCart(): OrderInterface
    {
        if ($this->cart instanceof OrderInterface) {
            return $this->cart;
        }

        throw new CartNotFoundException();
    }

    public function getStorageList(): OrderInterface
    {
        return $this->getCart();
    }

    public function setCart(?OrderInterface $cart): void
    {
        $this->cart = $cart;
    }

    public function clearCart(): void
    {
        $this->cart = null;
    }

    public function reset(): void
    {
        $this->clearCart();
    }
}
