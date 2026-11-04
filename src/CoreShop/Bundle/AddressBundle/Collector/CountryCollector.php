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

namespace CoreShop\Bundle\AddressBundle\Collector;

use CoreShop\Component\Address\Context\CountryContextInterface;
use Pimcore\Http\Request\Resolver\PimcoreContextResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

final class CountryCollector extends DataCollector
{
    private bool $countryChangeSupport;

    public function __construct(
        private CountryContextInterface $countryContext,
        private PimcoreContextResolver $pimcoreContext,
        bool $countryChangeSupport = false,
    ) {
        $this->countryChangeSupport = $countryChangeSupport;
        $this->reset();
    }

    public function hasCountry(): bool
    {
        return null !== ($this->data['country_name'] ?? null) || null !== ($this->data['country_iso_code'] ?? null);
    }

    public function getCountryId(): ?int
    {
        return $this->data['country_id'] ?? null;
    }

    public function getCountryIsoCode(): ?string
    {
        return $this->data['country_iso_code'] ?? null;
    }

    public function getCountryName(): ?string
    {
        return $this->data['country_name'] ?? null;
    }

    public function isCountryChangeSupported(): bool
    {
        // Read with a fallback: the getters also run on profiles unserialized from storage.
        return (bool) ($this->data['country_change_support'] ?? false);
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        if ($this->pimcoreContext->matchesPimcoreContext($request, PimcoreContextResolver::CONTEXT_ADMIN)) {
            return;
        }

        try {
            $country = $this->countryContext->getCountry();
        } catch (\Exception) {
            //If something went wrong, we don't have any country, which we can safely ignore
            return;
        }

        // Only scalars: the profile is serialized, and a Doctrine entity (proxy class, lazy
        // translations) cannot be restored reliably when a stored profile is loaded later.
        $this->data['country_id'] = $country->getId();
        $this->data['country_iso_code'] = null !== $country->getIsoCode() ? (string) $country->getIsoCode() : null;
        $this->data['country_name'] = null !== $country->getName() ? (string) $country->getName() : null;
    }

    public function reset(): void
    {
        // Restore the initial structure: kernel.reset (worker runtimes, Messenger, functional
        // tests) calls this between requests, and the getters rely on these keys.
        $this->data = [
            'country_id' => null,
            'country_iso_code' => null,
            'country_name' => null,
            'country_change_support' => $this->countryChangeSupport,
        ];
    }

    public function getName(): string
    {
        return 'coreshop.country_collector';
    }
}
