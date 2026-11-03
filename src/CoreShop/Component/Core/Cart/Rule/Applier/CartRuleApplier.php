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

namespace CoreShop\Component\Core\Cart\Rule\Applier;

use CoreShop\Component\Core\Product\ProductTaxCalculatorFactoryInterface;
use CoreShop\Component\Core\Provider\AddressProviderInterface;
use CoreShop\Component\Order\Cart\CartContextResolverInterface;
use CoreShop\Component\Order\Distributor\ProportionalIntegerDistributor;
use CoreShop\Component\Order\Factory\AdjustmentFactoryInterface;
use CoreShop\Component\Order\Model\AdjustmentInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\Model\PriceRuleItemInterface;
use CoreShop\Component\Taxation\Calculator\TaxCalculatorInterface;
use CoreShop\Component\Taxation\Collector\TaxCollectorInterface;
use Pimcore\Model\DataObject\Fieldcollection;

class CartRuleApplier implements CartRuleApplierInterface
{
    public function __construct(
        private ProportionalIntegerDistributor $distributor,
        private ProductTaxCalculatorFactoryInterface $taxCalculatorFactory,
        private TaxCollectorInterface $taxCollector,
        private AddressProviderInterface $defaultAddressProvider,
        private AdjustmentFactoryInterface $adjustmentFactory,
        private CartContextResolverInterface $cartContextResolver,
    ) {
    }

    public function applyDiscount(
        OrderInterface $cart,
        PriceRuleItemInterface $cartPriceRuleItem,
        int $discount,
        bool $withTax = false,
        bool $includeNonDiscountableItems = false,
    ): void {
        $this->apply($cart, $cartPriceRuleItem, $discount, $withTax, false, $includeNonDiscountableItems);
    }

    public function applySurcharge(
        OrderInterface $cart,
        PriceRuleItemInterface $cartPriceRuleItem,
        int $discount,
        bool $withTax = false,
        bool $includeNonDiscountableItems = false,
    ): void {
        $this->apply($cart, $cartPriceRuleItem, $discount, $withTax, true, $includeNonDiscountableItems);
    }

    protected function apply(
        OrderInterface $cart,
        PriceRuleItemInterface $cartPriceRuleItem,
        int $discount,
        bool $withTax = false,
        bool $positive = false,
        bool $includeNonDiscountableItems = false,
    ): void {
        $context = $this->cartContextResolver->resolveCartContext($cart);
        $totalAmount = [];
        $totalDiscountPossible = 0;

        $discountableItems = $includeNonDiscountableItems ? $cart->getItems() : $this->getDiscountableItems($cart);

        if (count($discountableItems) === 0) {
            return;
        }

        foreach ($discountableItems as $item) {
            $totalAmount[] = $item->getTotal($withTax);
            /*
             * Cart rules that were applied before are only stored as neutral adjustments
             * on the items, so the item total does not include them yet.
             */
            $totalDiscountPossible += $item->getTotal($withTax) +
                $item->getNeutralAdjustmentsTotal(AdjustmentInterface::CART_PRICE_RULE, $withTax);
        }

        //Don't apply less than the cart is worth
        if (!$positive) {
            $discount = max(0, min($discount, $totalDiscountPossible));
        }

        if (0 === $discount) {
            return;
        }

        /*
         * Split the discount once, by the amount it is given in (gross or net). The other
         * amount and the taxes of each share come from the item's own tax calculator, so
         * net, gross and taxes of each item, and of the whole rule, always match.
         */
        $distributedAmount = $this->distributor->distribute($totalAmount, $discount);

        $totalDiscountNet = 0;
        $totalDiscountGross = 0;
        $i = 0;

        foreach ($discountableItems as $item) {
            $applicableAmount = $distributedAmount[$i++];

            if (0 === $applicableAmount) {
                continue;
            }

            $taxCalculator = $this->taxCalculatorFactory->getTaxCalculator(
                $item->getProduct(),
                $cart->getShippingAddress() ?: $this->defaultAddressProvider->getAddress($cart),
                $context,
            );

            $amountNet = $applicableAmount;
            $amountGross = $applicableAmount;

            if ($taxCalculator instanceof TaxCalculatorInterface) {
                $taxItems = $item->getTaxes() ?? new Fieldcollection();

                if ($withTax) {
                    $amountNet = $amountGross - $taxCalculator->getTaxesAmountFromGross($amountGross);

                    /** @psalm-suppress InvalidArgument */
                    $taxItems->setItems(
                        $this->taxCollector->collectTaxesFromGross(
                            $taxCalculator,
                            ($positive ? $amountGross : -1 * $amountGross),
                            $taxItems->getItems(),
                        ),
                    );
                } else {
                    $amountGross = $amountNet + $taxCalculator->getTaxesAmount($amountNet);

                    /** @psalm-suppress InvalidArgument */
                    $taxItems->setItems(
                        $this->taxCollector->collectTaxes(
                            $taxCalculator,
                            ($positive ? $amountNet : -1 * $amountNet),
                            $taxItems->getItems(),
                        ),
                    );
                }
            }

            $totalDiscountNet += $amountNet;
            $totalDiscountGross += $amountGross;

            /*
             * https://github.com/coreshop/CoreShop/issues/2572
             *
             * Since we are applying the discount to the cart,
             * we add the adjustment to the cart-item as neutral.
             *
             * we need this adjustment so we know how much to refund or credit.
             */
            $item->addAdjustment($this->adjustmentFactory->createWithData(
                AdjustmentInterface::CART_PRICE_RULE,
                $cartPriceRuleItem->getCartPriceRule()->getName(),
                $positive ? $amountGross : (-1 * $amountGross),
                $positive ? $amountNet : (-1 * $amountNet),
                true,
            ));
        }

        // A rule can have several actions, each of them adds its own discount to the same price rule item
        $cartPriceRuleItem->setDiscount($cartPriceRuleItem->getDiscount(false) + ($positive ? $totalDiscountNet : (-1 * $totalDiscountNet)), false);
        $cartPriceRuleItem->setDiscount($cartPriceRuleItem->getDiscount(true) + ($positive ? $totalDiscountGross : (-1 * $totalDiscountGross)), true);

        $cart->addAdjustment(
            $this->adjustmentFactory->createWithData(
                AdjustmentInterface::CART_PRICE_RULE,
                $cartPriceRuleItem->getCartPriceRule()->getName(),
                $cartPriceRuleItem->getDiscount(true),
                $cartPriceRuleItem->getDiscount(false),
            ),
        );
    }

    protected function getDiscountableItems(OrderInterface $order)
    {
        $discountableItems = [];
        foreach ($order->getItems() as $item) {
            if ($item->getTotal() <= 0) {
                continue;
            }

            if (null === $item->findAttribute('not_discountable')) {
                $discountableItems[] = $item;
            }
        }

        return $discountableItems;
    }
}
