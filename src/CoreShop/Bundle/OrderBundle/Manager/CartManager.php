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

namespace CoreShop\Bundle\OrderBundle\Manager;

use CoreShop\Component\Order\Event\DeletedCartItemsDroppedEvent;
use CoreShop\Component\Order\Exception\CartPersistConflictException;
use CoreShop\Component\Order\Manager\CartLockManagerInterface;
use CoreShop\Component\Order\Manager\CartManagerInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\Model\OrderItemInterface;
use CoreShop\Component\Order\Processor\CartProcessorInterface;
use CoreShop\Component\Pimcore\DataObject\VersionHelper;
use CoreShop\Component\Resource\Service\FolderCreationServiceInterface;
use CoreShop\Component\StorageList\Event\StorageListReloadedEvent;
use CoreShop\Component\StorageList\Model\StorageListInterface;
use CoreShop\Component\StorageList\StorageListManagerInterface;
use CoreShop\Component\Pimcore\DataObject\ObjectFingerprint;
use Doctrine\DBAL\ArrayParameterType;
use Pimcore\Db;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Folder;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;
use Webmozart\Assert\Assert;

/**
 * Concurrent saves of one cart:
 * - persistCart() saves under a lock per cart and in one transaction.
 * - Every persistCart() moves the cart's persist version (field persistVersion). A copy that is older than the stored
 *   cart is refused (CartPersistConflictException) instead of undoing the newer save. Writers that call lockCart()
 *   before they change the cart always work on the current copy and are never refused.
 * - Code that changes a stored cart without persistCart() and without moving the version (removing a product that
 *   can no longer be bought) only deletes items: a copy that still holds them leaves them out, and listeners of
 *   DeletedCartItemsDroppedEvent copy the other stored changes over.
 *
 * A class without the persistVersion field (migration Version20261124120000 not run yet) is not checked: saves of
 * concurrent copies then overwrite each other, as before.
 */
final class CartManager implements CartManagerInterface, CartLockManagerInterface, StorageListManagerInterface, ResetInterface
{
    /**
     * Followed by the cart id. Code that changes a stored cart outside persistCart() takes it as well (e.g.
     * ProductAvailabilityEventListener).
     */
    public const PERSIST_LOCK_PREFIX = 'coreshop_cart_persist_';

    public const VERSION_FIELD = 'persistVersion';

    /** @var array<int, LockInterface> taken by lockCart(), by cart id */
    private array $heldLocks = [];

    public function __construct(
        private CartProcessorInterface $cartProcessor,
        private FolderCreationServiceInterface $folderCreationService,
        private ?LockFactory $lockFactory = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function lockCart(OrderInterface $cart): OrderInterface
    {
        $cartId = (int) $cart->getId();
        if ($cartId === 0) {
            return $cart;
        }

        if ($this->lockFactory !== null && !isset($this->heldLocks[$cartId])) {
            $lock = $this->lockFactory->createLock(self::PERSIST_LOCK_PREFIX . $cartId);
            $lock->acquire(true);
            $this->heldLocks[$cartId] = $lock;
        }

        if (!$cart instanceof Concrete || $this->storedVersion($cart) <= $this->loadedVersion($cart)) {
            return $cart;
        }

        $freshCart = $cart::getById($cartId, ['force' => true]);
        if (!$freshCart instanceof OrderInterface) {
            return $cart;
        }

        $this->eventDispatcher?->dispatch(new StorageListReloadedEvent($cart, $freshCart));

        return $freshCart;
    }

    public function unlockCart(OrderInterface $cart): void
    {
        $cartId = (int) $cart->getId();

        ($this->heldLocks[$cartId] ?? null)?->release();
        unset($this->heldLocks[$cartId]);
    }

    /**
     * A writer that called lockCart() and gave up without persistCart() must not keep the customer's next request
     * waiting: released after the main request's response.
     */
    public function releaseHeldLocks(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->reset();
        }
    }

    public function reset(): void
    {
        foreach ($this->heldLocks as $lock) {
            $lock->release();
        }

        $this->heldLocks = [];
    }

    public function persist(StorageListInterface $storageList): void
    {
        /**
         * @var OrderInterface $storageList
         */
        Assert::isInstanceOf($storageList, OrderInterface::class);

        $this->persistCart($storageList);
    }

    public function persistCart(OrderInterface $cart/*, array $params = []*/): void
    {
        $params = [];
        if (func_num_args() === 2) {
            $params = func_get_arg(1) ?? [];
        }

        // Concurrent persists of the same cart (double-click, two tabs) are serialized, and
        // the 2N+2 saves of one persist are stored together or not at all.
        // A lock taken by lockCart() is used and ends here.
        $cartId = (int) $cart->getId();
        $lock = $this->heldLocks[$cartId] ?? null;
        unset($this->heldLocks[$cartId]);

        if ($lock === null && $cartId !== 0 && $this->lockFactory !== null) {
            $lock = $this->lockFactory->createLock(self::PERSIST_LOCK_PREFIX . $cartId);
            $lock->acquire(true);
        }

        $db = Db::get();
        $db->beginTransaction();

        try {
            if ($cartId !== 0) {
                $this->assertNotStale($cart);
                $this->dropDeletedItems($cart);
            }

            $this->saveCart($cart, $params);

            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }

            throw $exception;
        } finally {
            $lock?->release();
        }
    }

    private function assertNotStale(OrderInterface $cart): void
    {
        if (!$cart instanceof Concrete) {
            return;
        }

        $storedVersion = $this->storedVersion($cart);
        $loadedVersion = $this->loadedVersion($cart);

        if ($storedVersion > $loadedVersion) {
            throw new CartPersistConflictException((int) $cart->getId(), $loadedVersion, $storedVersion);
        }
    }

    private static function hasVersion(Concrete $cart): bool
    {
        return $cart->getClass()->getFieldDefinition(self::VERSION_FIELD) !== null
            && method_exists($cart, 'getPersistVersion')
            && method_exists($cart, 'setPersistVersion');
    }

    /**
     * The field is read and written by name: its accessors are generated with the class, and a project that did
     * not run the migration yet does not have them.
     */
    private function loadedVersion(Concrete $cart): int
    {
        return self::hasVersion($cart) ? (int) $cart->getPersistVersion() : 0;
    }

    private function storedVersion(Concrete $cart): int
    {
        if (!self::hasVersion($cart)) {
            return 0;
        }

        return (int) Db::get()->fetchOne(
            sprintf('SELECT %s FROM object_store_%s WHERE oo_id = ?', Db::get()->quoteIdentifier(self::VERSION_FIELD), $cart->getClassId()),
            [$cart->getId()],
        );
    }

    /**
     * Items deleted since the cart was loaded (e.g. by ProductAvailabilityEventListener while this request ran) are
     * left out: saving them again would bring the removed product back. Such a deletion does not move the persist
     * version, so assertNotStale() lets this copy through; DeletedCartItemsDroppedEvent lets listeners copy the other
     * stored changes over. Checked under the lock and in the transaction, so a deletion that finished before is seen.
     * The raw item list is filtered: getItems() and removeItem() hide the new, not yet saved items.
     */
    private function dropDeletedItems(OrderInterface $cart): void
    {
        $items = array_values($cart->getObjectVar('items') ?? []);

        $ids = [];
        foreach ($items as $item) {
            if ($item instanceof OrderItemInterface && $item->getId()) {
                $ids[] = (int) $item->getId();
            }
        }

        if ($ids === []) {
            return;
        }

        $existing = array_flip(array_map('intval', Db::get()->fetchFirstColumn(
            'SELECT id FROM objects WHERE id IN (?)',
            [$ids],
            [ArrayParameterType::INTEGER],
        )));

        if (count($existing) === count($ids)) {
            return;
        }

        $cart->setItems(array_values(array_filter(
            $items,
            static fn (mixed $item): bool => !$item instanceof OrderItemInterface || !$item->getId() || isset($existing[(int) $item->getId()]),
        )));

        $this->eventDispatcher?->dispatch(new DeletedCartItemsDroppedEvent($cart, array_values(array_diff($ids, array_keys($existing)))));
    }

    private function saveCart(OrderInterface $cart, array $params): void
    {
        VersionHelper::useVersioning(function () use ($cart, $params) {
            if (!$cart->getId()) {
                $tempItems = $cart->getItems();
                $cart->setItems([]);

                /**
                 * @psalm-suppress DocblockTypeContradiction
                 */
                if (!$cart->getParent()) {
                    $cart->setParent($this->folderCreationService->createFolderForResource($cart, [
                        'suffix' => date('Y/m/d'),
                        'path' => 'cart',
                    ]));
                }

                $cart->save();
                $cart->setItems($tempItems);
            }

            $itemsFolder = null;

            /**
             * New items are saved before the CartProcessor runs: getItems() hides unsaved, unpublished items, so
             * the processors would not see them. The saved ones are already visible and are saved once, below.
             *
             * @var OrderItemInterface $item
             */
            foreach (array_values($cart->getObjectVar('items') ?? []) as $index => $item) {
                if ($item->getId() && $item->getPublished()) {
                    continue;
                }

                $itemsFolder ??= $this->createItemsFolder($cart, $item);
                $this->placeItem($item, $itemsFolder, $index);
                $item->setPublished(true);
                $item->save();
            }

            $fingerprints = ($params['only_changed_items'] ?? false) ? $this->fingerprintItems($cart) : [];

            /**
             * The CartProcessor might add new Items to the Cart (eg. Gift Products)
             * so we need to set the Parent and Key after the CartProcessor has been processed
             */
            $this->cartProcessor->process($cart);

            /**
             * @var OrderItemInterface $item
             */
            foreach ($cart->getItems() as $index => $item) {
                $itemsFolder ??= $this->createItemsFolder($cart, $item);
                $this->placeItem($item, $itemsFolder, (int) $index);

                $fingerprint = $fingerprints[spl_object_id($item)] ?? null;
                if ($fingerprint !== null && $item instanceof Concrete && $fingerprint === ObjectFingerprint::of($item)) {
                    continue;
                }

                $item->save();
            }

            if ($cart instanceof Concrete && self::hasVersion($cart)) {
                $cart->setPersistVersion($this->loadedVersion($cart) + 1);
            }

            $cart->save();
        }, $params['enable_versioning'] ?? false);
    }

    /**
     * Only for a cart that was loaded and not changed since (the recalculation of a cart): the fingerprint is taken
     * from the object in memory, a change made before persistCart() would look like the saved state.
     *
     * @return array<int, string> fingerprints of the saved items, by spl_object_id()
     */
    private function fingerprintItems(OrderInterface $cart): array
    {
        $fingerprints = [];
        foreach ($cart->getItems() as $item) {
            if ($item instanceof Concrete && $item->getId()) {
                $fingerprints[spl_object_id($item)] = ObjectFingerprint::of($item);
            }
        }

        return $fingerprints;
    }

    private function createItemsFolder(OrderInterface $cart, OrderItemInterface $item): Folder
    {
        return $this->folderCreationService->createFolderForResource($item, ['prefix' => $cart->getFullPath()]);
    }

    /**
     * An item keeps its key once it has one: a new key renamed the object on every save (Pimcore then checks the
     * new path and rewrites it) and made an unchanged item look changed. Only an item that is not in the items
     * folder of its cart (yet) is moved there.
     */
    private function placeItem(OrderItemInterface $item, Folder $itemsFolder, int $index): void
    {
        if ($item->getParentId() !== $itemsFolder->getId()) {
            $item->setParent($itemsFolder);
        }

        if (!$item->getKey()) {
            $item->setKey(uniqid(sprintf('%s.', $index + 1), true));
        }
    }
}
