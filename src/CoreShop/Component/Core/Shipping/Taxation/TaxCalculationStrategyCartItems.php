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

namespace CoreShop\Component\Core\Shipping\Taxation;

use CoreShop\Component\Address\Model\AddressInterface;
use CoreShop\Component\Core\Model\ProductInterface;
use CoreShop\Component\Core\Model\StoreInterface;
use CoreShop\Component\Core\Taxation\TaxCalculatorFactoryInterface;
use CoreShop\Component\Order\Distributor\ProportionalIntegerDistributorInterface;
use CoreShop\Component\Shipping\Model\CarrierInterface;
use CoreShop\Component\Shipping\Model\ShippableInterface;
use CoreShop\Component\Shipping\Model\ShippableItemInterface;
use CoreShop\Component\Shipping\Taxation\TaxCalculationStrategyInterface;
use CoreShop\Component\Store\Model\StoreAwareInterface;
use CoreShop\Component\Taxation\Collector\TaxCollectorInterface;
use Webmozart\Assert\Assert;

class TaxCalculationStrategyCartItems implements TaxCalculationStrategyInterface
{
    public function __construct(
        private TaxCollectorInterface $taxCollector,
        private TaxCalculatorFactoryInterface $taxCalculationFactory,
        private ProportionalIntegerDistributorInterface $distributor,
    ) {
    }

    public function calculateShippingTax(
        ShippableInterface $shippable,
        CarrierInterface $carrier,
        AddressInterface $address,
        int $shippingAmount,
        array $context = [],
    ): array {
        /**
         * @var StoreAwareInterface $shippable
         * @var ShippableInterface $shippable
         */
        Assert::isInstanceOf($shippable, StoreAwareInterface::class);

        $store = $shippable->getStore();

        /**
         * @var StoreInterface $store
         */
        Assert::isInstanceOf($store, StoreInterface::class);

        [$totalAmount, $taxRules] = $this->collectCartItemsTaxRules($shippable, $store);

        if (!$totalAmount || !$taxRules) {
            return [];
        }

        // Free items (gifts, 100 % discounts) give a total of 0: split the shipping evenly between the tax rules
        if (array_sum($totalAmount) <= 0) {
            $totalAmount = array_fill(0, count($totalAmount), 1);
        }

        $distributedAmount = $this->distributor->distribute($totalAmount, $shippingAmount);

        return $this->collectTaxes($address, $taxRules, $distributedAmount, $store->getUseGrossPrice(), $context);
    }

    private function collectCartItemsTaxRules(ShippableInterface $cart, StoreInterface $store): array
    {
        $totalAmount = [];
        $taxRules = [];

        /**
         * @var ShippableItemInterface $item
         */
        foreach ($cart->getItems() as $item) {
            if ($item instanceof \CoreShop\Component\Core\Model\OrderItemInterface &&
                $item->getDigitalProduct() === true
            ) {
                continue;
            }

            $product = $item->getProduct();

            if (!$product instanceof ProductInterface) {
                continue;
            }

            $taxRule = $product->getStoreValuesOfType('taxRule', $store);

            if (!$taxRule) {
                continue;
            }

            if (!\array_key_exists($taxRule->getId(), $totalAmount)) {
                $totalAmount[$taxRule->getId()] = 0;
            }

            $totalAmount[$taxRule->getId()] += $item->getTotal(true);
            $taxRules[$taxRule->getId()] = $taxRule;
        }

        // Both lists are keyed by the tax rule id, so their values line up by position.
        return [\array_values($totalAmount), \array_values($taxRules)];
    }

    private function collectTaxes(
        AddressInterface $address,
        array $taxRuleGroup,
        array $distributedAmount,
        bool $useGrossValues,
        array $context = [],
    ): array {
        $taxes = [];

        foreach ($distributedAmount as $i => $amount) {
            $taxCalculator = $this->taxCalculationFactory->getTaxCalculatorForAddress(
                $taxRuleGroup[$i],
                $address,
                $context,
            );

            if ($useGrossValues) {
                $shippingTax = $this->taxCollector->collectTaxesFromGross($taxCalculator, $amount);
            } else {
                $shippingTax = $this->taxCollector->collectTaxes($taxCalculator, $amount);
            }
            /** @psalm-suppress InvalidArgument */
            $taxes = $this->taxCollector->mergeTaxes($shippingTax, $taxes);
        }

        return $taxes;
    }
}
