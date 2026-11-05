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

namespace CoreShop\Component\Address\Context;

use CoreShop\Component\Address\Model\CountryInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds a country set explicitly (tests, CLI, workers acting for a country). It is reset between
 * requests (kernel.reset), so a country set once does not leak into later requests.
 */
final class FixedCountryContext implements CountryContextInterface, ResetInterface
{
    private ?CountryInterface $country = null;

    public function getCountry(): CountryInterface
    {
        if ($this->country instanceof CountryInterface) {
            return $this->country;
        }

        throw new CountryNotFoundException();
    }

    public function setCountry(CountryInterface $country): void
    {
        $this->country = $country;
    }

    public function clearCountry(): void
    {
        $this->country = null;
    }

    public function reset(): void
    {
        $this->clearCountry();
    }
}
