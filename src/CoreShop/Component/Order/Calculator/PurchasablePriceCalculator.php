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

namespace CoreShop\Component\Order\Calculator;

use CoreShop\Component\Order\Exception\NoPurchasableDiscountPriceFoundException;
use CoreShop\Component\Order\Exception\NoPurchasablePriceFoundException;
use CoreShop\Component\Order\Exception\NoPurchasableRetailPriceFoundException;
use CoreShop\Component\Order\Model\PurchasableInterface;

final class PurchasablePriceCalculator implements PurchasablePriceCalculatorInterface
{
    public function __construct(
        private PurchasableRetailPriceCalculatorInterface $purchasableRetailPriceCalculator,
        private PurchasableDiscountPriceCalculatorInterface $purchasableDiscountPriceCalculator,
        private PurchasableDiscountCalculatorInterface $purchasableDiscountCalculator,
    ) {
    }

    public function getPrice(PurchasableInterface $purchasable, array $context, bool $includingDiscounts = false): int
    {
        $price = null;

        try {
            $price = $this->purchasableRetailPriceCalculator->getRetailPrice($purchasable, $context);
        } catch (NoPurchasableRetailPriceFoundException) {
        }

        try {
            $discountPrice = $this->purchasableDiscountPriceCalculator->getDiscountPrice($purchasable, $context);

            if ($discountPrice > 0 && (null === $price || $discountPrice < $price)) {
                $price = $discountPrice;
            }
        } catch (NoPurchasableDiscountPriceFoundException) {
        }

        // Without a retail or discount price the purchasable must not be sold for 0
        if (null === $price) {
            throw new NoPurchasablePriceFoundException(__CLASS__);
        }

        if ($includingDiscounts) {
            $price = max(0, $price - $this->purchasableDiscountCalculator->getDiscount($purchasable, $context, $price));
        }

        return $price;
    }
}
