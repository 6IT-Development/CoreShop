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

use CoreShop\Component\Order\Manager\CartManagerInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\Model\OrderItemInterface;
use CoreShop\Component\Order\Processor\CartProcessorInterface;
use CoreShop\Component\Pimcore\DataObject\VersionHelper;
use CoreShop\Component\Resource\Service\FolderCreationServiceInterface;
use CoreShop\Component\StorageList\Model\StorageListInterface;
use CoreShop\Component\StorageList\StorageListManagerInterface;
use Pimcore\Db;
use Pimcore\Model\DataObject\Folder;
use Symfony\Component\Lock\LockFactory;
use Webmozart\Assert\Assert;

final class CartManager implements CartManagerInterface, StorageListManagerInterface
{
    public function __construct(
        private CartProcessorInterface $cartProcessor,
        private FolderCreationServiceInterface $folderCreationService,
        private ?LockFactory $lockFactory = null,
    ) {
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
        $cartsFolder = $this->folderCreationService->createFolderForResource($cart, [
            'suffix' => date('Y/m/d'),
            'path' => 'cart',
        ]);

        $params = [];
        if (func_num_args() === 2) {
            $params = func_get_arg(1) ?? [];
        }

        // Concurrent persists of the same cart (double-click, two tabs) are serialized, and
        // the 2N+2 saves of one persist are stored together or not at all.
        $lock = $cart->getId() && $this->lockFactory !== null
            ? $this->lockFactory->createLock(sprintf('coreshop_cart_persist_%s', $cart->getId()))
            : null;
        $lock?->acquire(true);

        $db = Db::get();
        $db->beginTransaction();

        try {
            $this->saveCart($cart, $cartsFolder, $params['enable_versioning'] ?? false);

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

    private function saveCart(OrderInterface $cart, Folder $cartsFolder, bool $enableVersioning): void
    {
        VersionHelper::useVersioning(function () use ($cart, $cartsFolder) {
            if (!$cart->getId()) {
                $tempItems = $cart->getItems();
                $cart->setItems([]);

                /**
                 * @psalm-suppress DocblockTypeContradiction
                 */
                if (!$cart->getParent()) {
                    $cart->setParent($cartsFolder);
                }

                $cart->save();
                $cart->setItems($tempItems);
            }

            $items = array_values($cart->getObjectVar('items') ?? []);

            /**
             * @var OrderItemInterface $item
             */
            foreach ($items as $index => $item) {
                $item->setParent(
                    $this->folderCreationService->createFolderForResource(
                        $item,
                        ['prefix' => $cart->getFullPath()],
                    ),
                );
                $item->setKey(uniqid(sprintf('%s.', $index + 1), true));
                $item->setPublished(true);
                $item->save();
            }

            /**
             * The CartProcessor might add new Items to the Cart (eg. Gift Products)
             * so we need to set the Parent and Key after the CartProcessor has been processed
             */
            $this->cartProcessor->process($cart);

            /**
             * @var OrderItemInterface $item
             */
            foreach ($cart->getItems() as $index => $item) {
                $item->setParent(
                    $this->folderCreationService->createFolderForResource(
                        $item,
                        ['prefix' => $cart->getFullPath()],
                    ),
                );
                $item->setKey(uniqid(sprintf('%s.', ((int) $index + 1)), true));
                $item->save();
            }

            $cart->save();
        }, $enableVersioning);
    }
}
