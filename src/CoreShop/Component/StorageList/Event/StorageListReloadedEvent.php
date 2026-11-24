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

namespace CoreShop\Component\StorageList\Event;

use CoreShop\Component\StorageList\Model\StorageListInterface;

/**
 * A storage list was loaded again because another request saved it after this copy was loaded (e.g. a cart, by
 * CartLockManagerInterface::lockCart()). The contexts that keep the list for the request replace the stale copy with
 * the fresh one, so the rest of the request works with it.
 */
final class StorageListReloadedEvent
{
    public function __construct(
        private StorageListInterface $staleList,
        private StorageListInterface $freshList,
    ) {
    }

    public function getStaleList(): StorageListInterface
    {
        return $this->staleList;
    }

    public function getFreshList(): StorageListInterface
    {
        return $this->freshList;
    }
}
