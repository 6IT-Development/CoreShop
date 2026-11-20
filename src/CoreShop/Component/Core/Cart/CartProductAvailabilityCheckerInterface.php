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

namespace CoreShop\Component\Core\Cart;

use CoreShop\Component\Order\Model\PurchasableInterface;

/**
 * Decides whether a product may stay in the carts after it was saved. ProductAvailabilityEventListener asks it when
 * the published state or one of the fields in core_shop_core.cart_availability.product_fields changed, and removes
 * the product from every cart when it answers no. A project replaces it by aliasing this interface to its own.
 */
interface CartProductAvailabilityCheckerInterface
{
    public function isAvailable(PurchasableInterface $product): bool;
}
