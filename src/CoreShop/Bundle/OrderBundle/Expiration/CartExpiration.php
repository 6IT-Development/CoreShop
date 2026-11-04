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

namespace CoreShop\Bundle\OrderBundle\Expiration;

use CoreShop\Component\Order\Repository\OrderRepositoryInterface;
use CoreShop\Component\StorageList\Expiration\StorageListExpirationInterface;

final class CartExpiration implements StorageListExpirationInterface
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private OrderRepositoryInterface $cartRepository,
    ) {
    }

    public function expire(int $days, array $params = []): void
    {
        if ($days <= 0) {
            return;
        }

        // A partial config override can leave these null; fall back to the bundle defaults.
        $anonymous = (bool) ($params['anonymous'] ?? true);
        $customer = (bool) ($params['customer'] ?? false);

        $afterId = 0;

        do {
            $carts = $this->cartRepository->findExpiredCarts($days, $anonymous, $customer, $afterId, self::BATCH_SIZE);

            foreach ($carts as $cart) {
                $afterId = max($afterId, (int) $cart->getId());

                $cart->delete();
            }

            \Pimcore::collectGarbage();
        } while (count($carts) === self::BATCH_SIZE);
    }
}
