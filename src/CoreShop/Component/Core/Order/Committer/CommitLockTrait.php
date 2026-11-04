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

namespace CoreShop\Component\Core\Order\Committer;

use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\OrderSaleStates;
use Pimcore\Db;
use Pimcore\Model\DataObject\Concrete;

/**
 * Two parallel requests (a double click, a retry) both see the cart in memory and would both commit it.
 * The commit therefore runs under a lock per cart, and the stored sale state is checked again inside it.
 */
trait CommitLockTrait
{
    /**
     * @param callable(): void $commit
     */
    private function commitLocked(OrderInterface $order, callable $commit): void
    {
        if (null === $this->lockFactory || !$order->getId()) {
            $commit();

            return;
        }

        $lock = $this->lockFactory->createLock(sprintf('coreshop_order_commit_%s', $order->getId()));
        $lock->acquire(true);

        try {
            if ($this->isStoredAsCommitted($order)) {
                return;
            }

            $commit();
        } finally {
            $lock->release();
        }
    }

    private function isStoredAsCommitted(OrderInterface $order): bool
    {
        if (!$order instanceof Concrete) {
            return false;
        }

        $saleState = Db::get()->fetchOne(
            sprintf('SELECT saleState FROM object_store_%s WHERE oo_id = ?', $order->getClassId()),
            [$order->getId()],
        );

        return is_string($saleState) && $saleState !== '' && $saleState !== OrderSaleStates::STATE_CART;
    }
}
