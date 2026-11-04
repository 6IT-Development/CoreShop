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

namespace CoreShop\Component\Core\CartItem\Rule\Action;

use CoreShop\Component\Core\CartItem\Rule\Applier\CartItemRuleApplierInterface;
use CoreShop\Component\Currency\Converter\CurrencyConverterInterface;
use CoreShop\Component\Currency\Model\CurrencyInterface;
use CoreShop\Component\Currency\Repository\CurrencyRepositoryInterface;
use CoreShop\Component\Order\CartItem\Rule\Action\CartItemPriceRuleActionProcessorInterface;
use CoreShop\Component\Order\Model\AdjustmentInterface;
use CoreShop\Component\Order\Model\OrderItemInterface;
use CoreShop\Component\Order\Model\PriceRuleItemInterface;
use Webmozart\Assert\Assert;

class DiscountAmountActionProcessor implements CartItemPriceRuleActionProcessorInterface
{
    public function __construct(
        protected CurrencyConverterInterface $moneyConverter,
        protected CurrencyRepositoryInterface $currencyRepository,
        protected CartItemRuleApplierInterface $cartItemRuleApplier,
    ) {
    }

    public function applyRule(
        OrderItemInterface $orderItem,
        array $configuration,
        PriceRuleItemInterface $cartPriceRuleItem,
    ): bool {
        $discount = $this->getDiscount($orderItem, $configuration);

        if ($discount <= 0) {
            return false;
        }

        $this->cartItemRuleApplier->applyDiscount($orderItem, $cartPriceRuleItem, $discount, (bool) ($configuration['gross'] ?? false));

        return true;
    }

    public function unApplyRule(
        OrderItemInterface $orderItem,
        array $configuration,
        PriceRuleItemInterface $cartPriceRuleItem,
    ): bool {
        return true;
    }

    protected function getDiscount(OrderItemInterface $orderItem, array $configuration): int
    {
        $applyOn = $configuration['applyOn'] ?? 'total';

        if ('total' === $applyOn) {
            $cartAmount = $orderItem->getTotal((bool) ($configuration['gross'] ?? false));
        } else {
            $cartAmount =
                $orderItem->getSubtotal((bool) ($configuration['gross'] ?? false)) +
                $orderItem->getAdjustmentsTotal(AdjustmentInterface::CART_PRICE_RULE, (bool) ($configuration['gross'] ?? false));
        }

        $currency = $this->currencyRepository->find($configuration['currency']);
        $amount = $configuration['amount'];

        Assert::isInstanceOf($currency, CurrencyInterface::class);

        // Cart amounts are in the store's base currency, so the rule amount has to be
        // converted before it can be compared with them.
        $amount = $this->moneyConverter->convert(
            $amount,
            $currency->getIsoCode(),
            $orderItem->getOrder()->getStore()->getCurrency()->getIsoCode(),
        );

        return $this->getApplicableAmount($cartAmount, $amount);
    }

    protected function getApplicableAmount(int $cartAmount, int $ruleAmount): int
    {
        return min($cartAmount, $ruleAmount);
    }
}
