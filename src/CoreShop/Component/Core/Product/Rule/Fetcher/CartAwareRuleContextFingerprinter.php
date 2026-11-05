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

namespace CoreShop\Component\Core\Product\Rule\Fetcher;

use CoreShop\Component\Core\Model\OrderInterface;
use CoreShop\Component\Core\Model\OrderItemInterface;
use CoreShop\Component\Order\Model\PriceRuleItemInterface;
use CoreShop\Component\Product\Rule\Fetcher\RuleContextFingerprinterInterface;

/**
 * Adds the cart state that product price rule conditions read (item
 * quantities for "quantity", applied cart rules for
 * "not_combinable_with_cart_price_voucher_rule") to the context fingerprint,
 * so a cart that changes within one request does not get stale rules.
 */
final class CartAwareRuleContextFingerprinter implements RuleContextFingerprinterInterface
{
    public function __construct(
        private RuleContextFingerprinterInterface $inner,
    ) {
    }

    public function fingerprint(array $context): ?string
    {
        $fingerprint = $this->inner->fingerprint($context);

        if (null === $fingerprint || !($context['cart'] ?? null) instanceof OrderInterface) {
            return $fingerprint;
        }

        $cart = $context['cart'];
        $items = [];
        $priceRules = [];

        foreach ($cart->getItems() ?? [] as $item) {
            if (!$item instanceof OrderItemInterface) {
                continue;
            }

            $items[] = [
                $item->getProduct()?->getId(),
                $item->getQuantity(),
                $item->getDefaultUnitQuantity(),
                $item->getIsGiftItem(),
            ];
        }

        foreach ($cart->getPriceRuleItems()?->getItems() ?: [] as $priceRuleItem) {
            if (!$priceRuleItem instanceof PriceRuleItemInterface) {
                continue;
            }

            $priceRules[] = [$priceRuleItem->getCartPriceRule()?->getId(), $priceRuleItem->getVoucherCode()];
        }

        return hash('xxh128', $fingerprint . serialize([$items, $priceRules]));
    }
}
