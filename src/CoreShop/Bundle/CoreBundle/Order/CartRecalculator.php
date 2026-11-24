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

namespace CoreShop\Bundle\CoreBundle\Order;

use CoreShop\Component\Core\Cart\Notice\CartNotices;
use CoreShop\Component\Core\Model\NoticeableCartInterface;
use CoreShop\Component\Core\Model\OrderInterface;
use CoreShop\Component\Order\Manager\CartManagerInterface;
use CoreShop\Component\Order\Model\CartPriceRuleInterface;
use CoreShop\Component\Order\Model\OrderItemInterface;
use CoreShop\Component\Order\Model\PriceRuleItemInterface;
use CoreShop\Component\Order\OrderSaleStates;
use CoreShop\Component\Order\Processor\CartProcessorInterface;
use CoreShop\Component\Pimcore\DataObject\VersionHelper;
use Pimcore\Model\DataObject\Concrete;
use Symfony\Component\Lock\LockFactory;

/**
 * Recalculates a cart the customer did not change (prices or rules changed, products were removed): only the items
 * whose stored data changed are saved, and the changes the customer did not make are kept as cart notices.
 *
 * With core_shop_core.cart_recalculation.lock one request per cart recalculates, a parallel one (another tab, the
 * header widgets) waits for it and only processes its own copy in memory: the result is the same, it is saved already.
 */
final class CartRecalculator
{
    private const string LOCK_PREFIX = 'coreshop_cart_recalculation_';

    private const float LOCK_TTL = 30.0;

    public function __construct(
        private CartManagerInterface $cartManager,
        private CartProcessorInterface $cartProcessor,
        private ?LockFactory $lockFactory,
        private bool $lockEnabled,
    ) {
    }

    public function recalculate(OrderInterface $cart): void
    {
        if (!$this->lockEnabled || null === $this->lockFactory || !$cart->getId()) {
            $this->persist($cart);

            return;
        }

        $lock = $this->lockFactory->createLock(self::LOCK_PREFIX . $cart->getId(), self::LOCK_TTL);
        if ($lock->acquire()) {
            try {
                $this->persist($cart);
            } finally {
                $lock->release();
            }

            return;
        }

        $lock->acquire(true);
        try {
            $this->catchUp($cart);
        } finally {
            $lock->release();
        }
    }

    private function persist(OrderInterface $cart): void
    {
        $tracksNotices = $cart instanceof NoticeableCartInterface && $cart->getSaleState() === OrderSaleStates::STATE_CART;
        $pricesBefore = $tracksNotices ? $this->itemPrices($cart) : [];
        $rulesBefore = $tracksNotices ? $this->ruleNames($cart) : [];

        $this->cartManager->persistCart($cart, ['only_changed_items' => true]);

        if (!$tracksNotices) {
            return;
        }

        $storedNotices = $cart->getCartNotices();
        $notices = CartNotices::fromArray($storedNotices);
        $now = time();

        foreach ($this->itemPrices($cart) as $productId => [$name, $price]) {
            if (isset($pricesBefore[$productId]) && $pricesBefore[$productId][1] !== $price) {
                $notices->recordPriceChange($productId, $name, $pricesBefore[$productId][1], $price, $now);
            }
        }

        $rulesAfter = $this->ruleNames($cart);
        foreach (array_diff_key($rulesBefore, $rulesAfter) as $ruleId => $name) {
            $notices->recordRuleRemoval($ruleId, $name, $now);
        }
        foreach (array_keys($rulesAfter) as $ruleId) {
            $notices->forgetRuleRemoval($ruleId);
        }

        if ($notices->toArray() === $storedNotices) {
            return;
        }

        $cart->setCartNotices($notices->toArray());
        VersionHelper::useVersioning(static fn () => $cart->save(), false);
    }

    /**
     * The other request saved the recalculated cart: this copy only needs the same numbers and the notices it wrote.
     */
    private function catchUp(OrderInterface $cart): void
    {
        $this->cartProcessor->process($cart);

        if (!$cart instanceof NoticeableCartInterface || !$cart instanceof Concrete) {
            return;
        }

        $stored = $cart::getById($cart->getId(), ['force' => true]);
        if ($stored instanceof NoticeableCartInterface) {
            $cart->setCartNotices($stored->getCartNotices());
        }
    }

    /**
     * @return array<int, array{string, int}> name and gross unit price by product id, the first item of a product
     */
    private function itemPrices(OrderInterface $cart): array
    {
        $prices = [];
        foreach ($cart->getItems() ?? [] as $item) {
            $productId = $item instanceof OrderItemInterface ? $item->getProduct()?->getId() : null;
            if ($productId === null || isset($prices[$productId])) {
                continue;
            }

            $prices[(int) $productId] = [(string) $item->getName($cart->getLocaleCode()), $item->getItemPrice(true)];
        }

        return $prices;
    }

    /**
     * @return array<int, string> label (or name) by cart price rule id
     */
    private function ruleNames(OrderInterface $cart): array
    {
        $names = [];
        foreach ($cart->getPriceRuleItems()?->getItems() ?? [] as $priceRuleItem) {
            $rule = $priceRuleItem instanceof PriceRuleItemInterface ? $priceRuleItem->getCartPriceRule() : null;
            if (!$rule instanceof CartPriceRuleInterface || null === $rule->getId()) {
                continue;
            }

            $names[(int) $rule->getId()] = (string) ($rule->getLabel($cart->getLocaleCode()) ?: $rule->getName());
        }

        return $names;
    }
}
