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

namespace CoreShop\Component\Order\Exception;

use CoreShop\Component\StorageList\Exception\StorageListPersistConflictException;

/**
 * The cart was saved by another request after this copy was loaded (its persist version is older than the stored
 * one). Saving it would undo that save, so nothing was saved. Load the cart again, under
 * CartLockManagerInterface::lockCart(), and apply the change to the fresh copy.
 */
final class CartPersistConflictException extends StorageListPersistConflictException
{
    public function __construct(
        int $cartId,
        private int $loadedVersion,
        private int $storedVersion,
    ) {
        parent::__construct($cartId, sprintf(
            'Cart %d was saved by another request (version %d) after this copy was loaded (version %d).',
            $cartId,
            $storedVersion,
            $loadedVersion,
        ));
    }

    public function getCartId(): int
    {
        return $this->getStorageListId();
    }

    public function getLoadedVersion(): int
    {
        return $this->loadedVersion;
    }

    public function getStoredVersion(): int
    {
        return $this->storedVersion;
    }
}
