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

namespace CoreShop\Component\Core\Model;

/**
 * Carts that keep the changes the customer did not make (removed products, changed prices, lost discounts) until
 * the customer dismissed them, see CoreShop\Component\Core\Cart\Notice\CartNotices.
 *
 * Untyped on purpose: Pimcore generates the accessors of the coreShopSerializedData field without types.
 */
interface NoticeableCartInterface
{
    /**
     * @return list<array<string, mixed>>|null
     */
    public function getCartNotices();

    /**
     * @param list<array<string, mixed>>|null $cartNotices
     */
    public function setCartNotices($cartNotices);
}
