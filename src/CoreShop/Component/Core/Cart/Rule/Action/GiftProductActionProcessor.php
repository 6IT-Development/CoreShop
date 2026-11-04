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

namespace CoreShop\Component\Core\Cart\Rule\Action;

use CoreShop\Bundle\ResourceBundle\Pimcore\Repository\StackRepositoryInterface;
use CoreShop\Component\Core\Model\ProductInterface;
use CoreShop\Component\Order\Cart\Rule\Action\CartPriceRuleActionProcessorInterface;
use CoreShop\Component\Order\Factory\AdjustmentFactoryInterface;
use CoreShop\Component\Order\Factory\OrderItemFactoryInterface;
use CoreShop\Component\Order\Model\AdjustmentInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\Model\OrderItemInterface;
use CoreShop\Component\Order\Model\PriceRuleItemInterface;
use CoreShop\Component\Order\Model\PurchasableInterface;
use CoreShop\Component\Product\Model\ProductUnitDefinitionInterface;
use CoreShop\Component\Rule\Model\ActionInterface;

final class GiftProductActionProcessor implements CartPriceRuleActionProcessorInterface
{
    public function __construct(
        private StackRepositoryInterface $productRepository,
        private OrderItemFactoryInterface $cartItemFactory,
        private AdjustmentFactoryInterface $adjustmentFactory,
    ) {
    }

    public function applyRule(OrderInterface $cart, array $configuration, PriceRuleItemInterface $cartPriceRuleItem): bool
    {
        $key = $this->getKey($configuration['action']);
        $product = $this->productRepository->find($configuration['product']);

        if (!$product instanceof PurchasableInterface) {
            $this->removeGiftItems($cart, $this->findGiftItems($cart, $key));

            return false;
        }

        $item = null;
        $giftItems = $this->findGiftItems($cart, $key);

        foreach ($giftItems as $index => $giftItem) {
            if ($giftItem->getProduct()?->getId() === $product->getId()) {
                $item = $giftItem;
                unset($giftItems[$index]);

                break;
            }
        }

        $this->removeGiftItems($cart, $giftItems);

        if (null === $item) {
            $item = $this->cartItemFactory->createWithCart($cart, $product);
            $item->setIsGiftItem(true);
        }

        /**
         * @var \CoreShop\Component\Core\Model\OrderItemInterface $item
         */
        $item->setQuantity(1);

        if ($product instanceof ProductInterface) {
            $item->setDigitalProduct($product->getDigitalProduct());
        }

        if (
            $product instanceof ProductInterface &&
            $product->hasUnitDefinitions() &&
            $product->getUnitDefinitions()?->getDefaultUnitDefinition() instanceof ProductUnitDefinitionInterface
        ) {
            $item->setUnitDefinition($product->getUnitDefinitions()?->getDefaultUnitDefinition());
        }

        $item->setDefaultUnitQuantity(1);
        $item->removeAdjustments($key);

        $adjustment = $this->adjustmentFactory->createWithData(
            $key,
            $cartPriceRuleItem->getCartPriceRule()->getName(),
            0,
            0,
            true,
        );

        $item->addAdjustment($adjustment);

        return true;
    }

    public function unApplyRule(OrderInterface $cart, array $configuration, PriceRuleItemInterface $cartPriceRuleItem): bool
    {
        $key = $this->getKey($configuration['action']);

        $this->removeGiftItems($cart, $this->findGiftItems($cart, $key));

        return true;
    }

    /**
     * @return OrderItemInterface[]
     */
    private function findGiftItems(OrderInterface $cart, string $key): array
    {
        $cartItems = [];

        foreach ($cart->getItems() as $item) {
            if (!$item->getIsGiftItem()) {
                continue;
            }

            if (count($item->getAdjustments($key)) > 0) {
                $cartItems[] = $item;
            }
        }

        return $cartItems;
    }

    /**
     * @param OrderItemInterface[] $cartItems
     */
    private function removeGiftItems(OrderInterface $cart, array $cartItems): void
    {
        foreach ($cartItems as $cartItem) {
            $this->removeCartItem($cart, $cartItem);
        }
    }

    private function removeCartItem(OrderInterface $cart, OrderItemInterface $cartItem): void
    {
        $cart->removeItem($cartItem);

        if ($cartItem->getId() === null) {
            return;
        }

        if ($cartItem->getId() === 0) {
            return;
        }

        $cartItem->delete();
    }

    private function getKey(ActionInterface $action): string
    {
        return sprintf('%s_%s', AdjustmentInterface::CART_PRICE_RULE, $action->getId());
    }
}
