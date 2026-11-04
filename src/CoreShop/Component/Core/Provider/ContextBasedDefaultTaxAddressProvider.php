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

namespace CoreShop\Component\Core\Provider;

use CoreShop\Component\Address\Context\CountryNotFoundException;
use CoreShop\Component\Address\Model\AddressInterface;
use CoreShop\Component\Address\Model\CountryInterface;
use CoreShop\Component\Core\Model\StoreInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Resource\Factory\PimcoreFactoryInterface;

class ContextBasedDefaultTaxAddressProvider implements DefaultTaxAddressProviderInterface
{
    public function __construct(
        private PimcoreFactoryInterface $addressFactory,
        private ?AddressProviderInterface $cartAddressProvider = null,
    ) {
    }

    public function getAddress(array $context = []): ?AddressInterface
    {
        // Same address as the cart processors use: the shipping address, then the cart fallback address
        if (array_key_exists('cart', $context) && $context['cart'] instanceof OrderInterface) {
            $address = $context['cart']->getShippingAddress() ?: $this->cartAddressProvider?->getAddress($context['cart']);
            if ($address instanceof AddressInterface) {
                return $address;
            }
        }

        if (!array_key_exists('country', $context) && !array_key_exists('store', $context)) {
            throw new CountryNotFoundException();
        }

        $country = $context['country'] ?? null;

        if (!$country instanceof CountryInterface && ($context['store'] ?? null) instanceof StoreInterface) {
            $country = $context['store']->getBaseCountry();
        }

        // Without a country, only the tax rules that apply to every country are used
        $address = $this->addressFactory->createNew();
        $address->setCountry($country instanceof CountryInterface ? $country : null);

        return $address;
    }
}
