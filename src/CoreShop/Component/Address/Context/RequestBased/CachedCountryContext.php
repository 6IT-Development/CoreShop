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

namespace CoreShop\Component\Address\Context\RequestBased;

use CoreShop\Component\Address\Model\CountryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Caches the resolved country per request: sub-requests and later requests in a
 * long-running process (worker mode, Messenger) resolve their own country.
 */
final class CachedCountryContext implements RequestResolverInterface, ResetInterface
{
    /**
     * @var \WeakMap<Request, CountryInterface>
     */
    private \WeakMap $countries;

    public function __construct(
        private RequestResolverInterface $inner,
    ) {
        $this->countries = new \WeakMap();
    }

    public function findCountry(Request $request): CountryInterface
    {
        return $this->countries[$request] ??= $this->inner->findCountry($request);
    }

    public function reset(): void
    {
        $this->countries = new \WeakMap();
    }
}
