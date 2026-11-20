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

namespace CoreShop\Bundle\CoreBundle\EventListener;

use CoreShop\Bundle\CoreBundle\Product\StoredProductComparator;
use CoreShop\Component\Core\Cart\CartProductAvailabilityCheckerInterface;
use CoreShop\Component\Core\Cart\Notice\CartNotices;
use CoreShop\Component\Core\Model\NoticeableCartInterface;
use CoreShop\Component\Core\Model\OrderInterface;
use CoreShop\Component\Core\Model\OrderItemInterface;
use CoreShop\Component\Order\Model\PurchasableInterface;
use CoreShop\Component\Order\OrderSaleStates;
use CoreShop\Component\Order\Repository\OrderItemRepositoryInterface;
use CoreShop\Component\Order\Repository\OrderRepositoryInterface;
use CoreShop\Component\Pimcore\DataObject\VersionHelper;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\Concrete;

/**
 * Removes a product from every cart once it can no longer be bought (CartProductAvailabilityCheckerInterface): after
 * its published state or one of the fields in core_shop_core.cart_availability.product_fields changed, or when it is
 * deleted. Each cart keeps a "removed" notice for its customer and is recalculated on its next request
 * (RequestCartAvailability).
 */
final class ProductAvailabilityEventListener
{
    /** @var array<int, true> */
    private array $productIdsToCheck = [];

    /**
     * @param list<string> $availabilityFields
     */
    public function __construct(
        private OrderItemRepositoryInterface $cartItemRepository,
        private OrderRepositoryInterface $orderRepository,
        private StoredProductComparator $storedProductComparator,
        private CartProductAvailabilityCheckerInterface $availabilityChecker,
        private array $availabilityFields,
    ) {
    }

    public function preUpdateListener(DataObjectEvent $event): void
    {
        $object = $event->getObject();

        if ($event->hasArgument('isRecycleBinRestore') && $event->getArgument('isRecycleBinRestore')) {
            return;
        }

        if (!$object instanceof PurchasableInterface || !$object instanceof Concrete) {
            return;
        }

        if (isset($this->productIdsToCheck[$object->getId()])) {
            return;
        }

        if (
            $this->storedProductComparator->hasPublishedChanged($object)
            || $this->storedProductComparator->haveFieldsChanged($object, $this->availabilityFields)
        ) {
            $this->productIdsToCheck[$object->getId()] = true;
        }
    }

    public function postUpdateListener(DataObjectEvent $event): void
    {
        $object = $event->getObject();

        if (!$object instanceof PurchasableInterface || !isset($this->productIdsToCheck[$object->getId()])) {
            return;
        }

        unset($this->productIdsToCheck[$object->getId()]);

        if ($this->availabilityChecker->isAvailable($object)) {
            return;
        }

        $this->removeFromCarts($object);
    }

    public function postDeleteListener(DataObjectEvent $event): void
    {
        $object = $event->getObject();

        if (!$object instanceof PurchasableInterface) {
            return;
        }

        $this->removeFromCarts($object);
    }

    private function removeFromCarts(PurchasableInterface $product): void
    {
        $itemsByCart = [];
        foreach ($this->findCartItems((int) $product->getId()) as $cartItem) {
            $cart = $cartItem->getOrder();
            if ($cart instanceof OrderInterface && $cart->getSaleState() === OrderSaleStates::STATE_CART) {
                $itemsByCart[$cart->getId()][] = $cartItem;
            }
        }

        $now = time();
        foreach ($itemsByCart as $cartItems) {
            $cart = $cartItems[0]->getOrder();
            $notices = $cart instanceof NoticeableCartInterface ? CartNotices::fromArray($cart->getCartNotices()) : null;

            foreach ($cartItems as $cartItem) {
                $notices?->recordRemoval(
                    (int) $product->getId(),
                    (string) $cartItem->getName($cart->getLocaleCode()),
                    $cartItem->getItemPrice(true),
                    $now,
                );
                $cart->removeItem($cartItem);
                $cartItem->delete();
            }

            VersionHelper::useVersioning(
                static function () use ($cart, $notices): void {
                    if ($notices !== null) {
                        $cart->setCartNotices($notices->toArray());
                    }
                    $cart->setNeedsRecalculation(true);
                    $cart->save();
                },
                false,
            );
        }
    }

    /**
     * Only the items of carts: the items of the placed orders, usually most of them, are not loaded.
     *
     * @return list<OrderItemInterface>
     */
    private function findCartItems(int $productId): array
    {
        $listing = $this->cartItemRepository->getList();
        $listing->setCondition(
            sprintf(
                'product__id = ? AND order__id IN (SELECT oo_id FROM object_query_%s WHERE saleState = ?)',
                $this->orderRepository->getClassId(),
            ),
            [$productId, OrderSaleStates::STATE_CART],
        );

        return array_values(array_filter(
            $listing->getObjects(),
            static fn (mixed $item): bool => $item instanceof OrderItemInterface,
        ));
    }
}
