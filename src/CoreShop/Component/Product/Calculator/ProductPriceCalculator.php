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

use CoreShop\Component\Product\Exception\NoDiscountPriceFoundException;
use CoreShop\Component\Product\Exception\NoPriceFoundException;
use CoreShop\Component\Product\Exception\NoRetailPriceFoundException;
use CoreShop\Component\Product\Model\ProductInterface;

final class ProductPriceCalculator implements ProductPriceCalculatorInterface
{
    public function __construct(
        private ProductRetailPriceCalculatorInterface $retailPriceCalculator,
        private ProductDiscountPriceCalculatorInterface $discountPriceCalculator,
        private ProductDiscountCalculatorInterface $discountCalculator,
        private ProductCustomAttributesCalculatorInterface $productCustomAttributeCalculator,
    ) {
    }

    public function getPrice(ProductInterface $product, array $context, bool $withDiscount = false): int
    {
        $retailPrice = $this->findRetailPrice($product, $context);
        $discountPrice = $this->findDiscountPrice($product, $context);

        if (null === $retailPrice && null === $discountPrice) {
            throw new NoPriceFoundException(__CLASS__);
        }

        // a discount price applies when it is lower than the retail price, or when there is no retail price;
        // a discount price of 0 is a valid price
        $price = null !== $discountPrice && (null === $retailPrice || $discountPrice < $retailPrice)
            ? $discountPrice
            : $retailPrice;

        if ($withDiscount) {
            $price = max(0, $price - $this->getDiscount($product, $context, $price));
        }

        return $price;
    }

    public function getRetailPrice(ProductInterface $product, array $context): int
    {
        return $this->retailPriceCalculator->getRetailPrice($product, $context);
    }

    public function getDiscountPrice(ProductInterface $product, array $context): int
    {
        return $this->discountPriceCalculator->getDiscountPrice($product, $context);
    }

    public function getDiscount(ProductInterface $product, array $context, int $price): int
    {
        return $this->discountCalculator->getDiscount($product, $context, $price);
    }

    public function getCustomAttributes(ProductInterface $product, array $context): array
    {
        return $this->productCustomAttributeCalculator->getCustomAttributes($product, $context);
    }

    private function findRetailPrice(ProductInterface $product, array $context): ?int
    {
        try {
            return $this->getRetailPrice($product, $context);
        } catch (NoRetailPriceFoundException) {
            return null;
        }
    }

    private function findDiscountPrice(ProductInterface $product, array $context): ?int
    {
        try {
            return $this->getDiscountPrice($product, $context);
        } catch (NoDiscountPriceFoundException) {
            return null;
        }
    }
}
