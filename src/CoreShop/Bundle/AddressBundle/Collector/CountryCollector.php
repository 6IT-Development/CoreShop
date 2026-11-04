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
use CoreShop\Component\Address\Model\CountryInterface;
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

    public function getCountry(): ?CountryInterface
    {
        return $this->data['country'] ?? null;
    }

    public function isCountryChangeSupported(): bool
    {
        // Read with a fallback: the getters also run on profiles unserialized from storage.
        return (bool) ($this->data['country_change_support'] ?? false);
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        if ($this->pimcoreContext->matchesPimcoreContext($request, PimcoreContextResolver::CONTEXT_ADMIN)) {
            $this->data['admin'] = true;

            return;
        }

        try {
            $this->data['country'] = $this->countryContext->getCountry();
            $this->data['country_name'] = $this->countryContext->getCountry()->getName();
        } catch (\Exception) {
            //If something went wrong, we don't have any country, which we can safely ignore
        }
    }

    public function reset(): void
    {
        // Restore the initial structure: kernel.reset (worker runtimes, Messenger, functional
        // tests) calls this between requests, and the getters rely on these keys.
        $this->data = [
            'country' => null,
            'country_change_support' => $this->countryChangeSupport,
        ];
    }

    public function getName(): string
    {
        return 'coreshop.country_collector';
    }
}
