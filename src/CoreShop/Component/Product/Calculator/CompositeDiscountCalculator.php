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

namespace CoreShop\Component\Product\Calculator;

use CoreShop\Component\Product\Model\ProductInterface;
use CoreShop\Component\Registry\PrioritizedServiceRegistryInterface;

class CompositeDiscountCalculator implements ProductDiscountCalculatorInterface
{
    public function __construct(
        protected PrioritizedServiceRegistryInterface $discountCalculator,
    ) {
    }

    public function getDiscount(ProductInterface $product, array $context, int $price): int
    {
        $discount = 0;

        /**
         * @var ProductDiscountCalculatorInterface $calculator
         */
        foreach ($this->discountCalculator->all() as $calculator) {
            $discount += $calculator->getDiscount($product, $context, $price);
        }

        // Discounts of all calculators add up on the same price; never discount more than the price
        return min(max(0, $price), $discount);
    }
}
