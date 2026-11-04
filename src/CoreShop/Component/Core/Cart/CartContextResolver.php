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

use CoreShop\Component\Address\Model\CountryInterface;
use CoreShop\Component\Core\Model\StoreInterface;
use CoreShop\Component\Core\Provider\AddressProviderInterface;
use CoreShop\Component\Order\Cart\CartContextResolverInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use Webmozart\Assert\Assert;

final class CartContextResolver implements CartContextResolverInterface
{
    public function __construct(
        private CartContextResolverInterface $inner,
        private ?AddressProviderInterface $addressProvider = null,
    ) {
    }

    public function resolveCartContext(OrderInterface $cart): array
    {
        $context = $this->inner->resolveCartContext($cart);

        $store = $cart->getStore();

        /**
         * @var StoreInterface $store
         */
        Assert::isInstanceOf($store, StoreInterface::class);

        $context['store'] = $store;

        if (!($context['country'] ?? null) instanceof CountryInterface) {
            $context['country'] = $this->resolveCountry($cart, $store);
        }

        return $context;
    }

    private function resolveCountry(OrderInterface $cart, StoreInterface $store): ?CountryInterface
    {
        foreach ([$cart->getShippingAddress(), $cart->getInvoiceAddress()] as $address) {
            if ($address?->getCountry() instanceof CountryInterface) {
                return $address->getCountry();
            }
        }

        return $this->addressProvider?->getAddress($cart)?->getCountry() ?? $store->getBaseCountry();
    }
}
