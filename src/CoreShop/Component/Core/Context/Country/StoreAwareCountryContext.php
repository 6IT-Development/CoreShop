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

namespace CoreShop\Component\Core\Context\Country;

use CoreShop\Component\Address\Context\CountryContextInterface;
use CoreShop\Component\Address\Context\CountryNotFoundException;
use CoreShop\Component\Core\Model\CountryInterface;
use CoreShop\Component\Core\Model\StoreInterface;
use CoreShop\Component\Store\Context\StoreContextInterface;
use CoreShop\Component\Store\Context\StoreNotFoundException;

final class StoreAwareCountryContext implements CountryContextInterface
{
    public function __construct(
        private CountryContextInterface $countryContext,
        private StoreContextInterface $storeContext,
    ) {
    }

    public function getCountry(): \CoreShop\Component\Address\Model\CountryInterface
    {
        try {
            $store = $this->storeContext->getStore();
        } catch (StoreNotFoundException $exception) {
            throw new CountryNotFoundException($exception);
        }

        if (!$store instanceof StoreInterface) {
            throw new CountryNotFoundException();
        }

        try {
            $country = $this->countryContext->getCountry();

            if (!$country instanceof CountryInterface || !$this->isCountryAvailable($country, $store)) {
                return $this->getBaseCountry($store);
            }

            return $country;
        } catch (CountryNotFoundException $exception) {
            return $this->getBaseCountry($store, $exception);
        }
    }

    private function getBaseCountry(StoreInterface $store, ?\Exception $previousException = null): \CoreShop\Component\Address\Model\CountryInterface
    {
        $country = $store->getBaseCountry();

        if (!$country instanceof \CoreShop\Component\Address\Model\CountryInterface) {
            throw new CountryNotFoundException($previousException);
        }

        return $country;
    }

    private function isCountryAvailable(CountryInterface $country, StoreInterface $store): bool
    {
        return in_array($country->getIsoCode(), array_map(static function (CountryInterface $country) {
            return $country->getIsoCode();
        }, $store->getCountries()->toArray()), true);
    }
}
