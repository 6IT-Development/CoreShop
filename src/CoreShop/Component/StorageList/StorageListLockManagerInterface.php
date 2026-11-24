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

namespace CoreShop\Component\StorageList;

use CoreShop\Component\StorageList\Model\StorageListInterface;

/**
 * For a StorageListManagerInterface that guards against concurrent saves of one list (carts, see
 * CoreShop\Component\Order\Manager\CartLockManagerInterface): call lockStorageList() before the change, then change and
 * persist the list it returns. persist() releases the lock.
 */
interface StorageListLockManagerInterface
{
    /**
     * Blocks until the list is free. Returns the list to change: the given one, or a fresh copy of it. A list the
     * manager does not guard is returned as it is.
     */
    public function lockStorageList(StorageListInterface $storageList): StorageListInterface;
}
