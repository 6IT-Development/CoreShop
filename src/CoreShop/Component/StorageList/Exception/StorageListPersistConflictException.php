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

namespace CoreShop\Component\StorageList\Exception;

/**
 * The storage list was saved by another request after this copy was loaded, and saving it would undo that save:
 * nothing was saved. Load it again under StorageListLockManagerInterface::lockStorageList() and apply the change to
 * the fresh copy. Thrown by managers that check this (carts: CartPersistConflictException).
 */
class StorageListPersistConflictException extends \RuntimeException
{
    public function __construct(
        private int $storageListId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getStorageListId(): int
    {
        return $this->storageListId;
    }
}
