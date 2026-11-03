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

namespace CoreShop\Bundle\CoreBundle\Validator\Constraints;

use CoreShop\Bundle\OrderBundle\DTO\AddToCartInterface;
use CoreShop\Component\Core\Model\OrderItemInterface;
use CoreShop\Component\Order\Calculator\PurchasablePriceCalculatorInterface;
use CoreShop\Component\Order\Cart\CartContextResolverInterface;
use CoreShop\Component\Order\Exception\NoPurchasablePriceFoundException;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\Model\PurchasableInterface;
use CoreShop\Component\ProductQuantityPriceRules\Detector\QuantityReferenceDetectorInterface;
use CoreShop\Component\ProductQuantityPriceRules\Exception\NoPriceFoundException;
use CoreShop\Component\ProductQuantityPriceRules\Exception\NoRuleFoundException;
use CoreShop\Component\ProductQuantityPriceRules\Model\QuantityRangePriceAwareInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Webmozart\Assert\Assert;

/**
 * PurchasableCalculator uses 0 when no price is found, so without this check
 * a product without a price (for example without store values) could be bought for free.
 */
final class CartItemPriceValidator extends ConstraintValidator
{
    public function __construct(
        private PurchasablePriceCalculatorInterface $purchasablePriceCalculator,
        private QuantityReferenceDetectorInterface $quantityReferenceDetector,
        private CartContextResolverInterface $cartContextResolver,
    ) {
    }

    public function validate($value, Constraint $constraint): void
    {
        Assert::isInstanceOf($constraint, CartItemPrice::class);

        if ($value instanceof AddToCartInterface) {
            $cart = $value->getCart();
            $items = [$value->getCartItem()];
        } else {
            Assert::isInstanceOf($value, OrderInterface::class);

            $cart = $value;
            $items = $cart->getItems();
        }

        $context = $this->cartContextResolver->resolveCartContext($cart);

        foreach ($items as $item) {
            if (!$item instanceof OrderItemInterface || $item->isImmutable() || $item->getIsGiftItem() || $item->getCustomItemPrice()) {
                continue;
            }

            $product = $item->getProduct();

            if (!$product instanceof PurchasableInterface || $this->hasPrice($item, $product, $context)) {
                continue;
            }

            $this->context->addViolation(
                $constraint->message,
                ['%product%' => $product->getName() ?? (string) $product->getId()],
            );
        }
    }

    private function hasPrice(OrderItemInterface $item, PurchasableInterface $product, array $context): bool
    {
        if ($item->hasUnitDefinition()) {
            $context['unitDefinition'] = $item->getUnitDefinition();
        }

        $context['cartItem'] = $item;

        try {
            $this->purchasablePriceCalculator->getPrice($product, $context, true);

            return true;
        } catch (NoPurchasablePriceFoundException) {
        }

        // a product can be priced by fixed quantity ranges only
        if ($product instanceof QuantityRangePriceAwareInterface) {
            try {
                $this->quantityReferenceDetector->detectQuantityPrice($product, (float) $item->getQuantity(), 0, $context);

                return true;
            } catch (NoRuleFoundException|NoPriceFoundException) {
            }
        }

        return false;
    }
}
