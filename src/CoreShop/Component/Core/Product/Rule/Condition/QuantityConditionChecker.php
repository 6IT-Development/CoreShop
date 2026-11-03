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

namespace CoreShop\Component\Core\Product\Rule\Condition;

use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Core\Model\OrderItemInterface;
use CoreShop\Component\Product\Model\ProductInterface;
use CoreShop\Component\Resource\Model\ResourceInterface;
use CoreShop\Component\Rule\Condition\ConditionCheckerInterface;
use CoreShop\Component\Rule\Model\RuleInterface;

final class QuantityConditionChecker implements ConditionCheckerInterface
{
    public function isValid(
        ResourceInterface $subject,
        RuleInterface $rule,
        array $configuration,
        array $params = [],
    ): bool {
        if (!array_key_exists('cart', $params) || !$params['cart'] instanceof OrderInterface) {
            return false;
        }

        $cart = $params['cart'];
        $found = false;
        $quantity = 0.0;

        // Sum all lines of the product (each line can have another unit), in the product's default unit
        foreach ($cart->getItems() as $item) {
            if (!$item instanceof OrderItemInterface || $item->getIsGiftItem()) {
                continue;
            }

            if (!$item->getProduct() instanceof ProductInterface || $item->getProduct()->getId() !== $subject->getId()) {
                continue;
            }

            $found = true;
            $quantity += (float) ($item->getDefaultUnitQuantity() ?? $item->getQuantity());
        }

        if (!$found) {
            return false;
        }

        return $quantity >= $configuration['minQuantity'] && $quantity <= $configuration['maxQuantity'];
    }
}
