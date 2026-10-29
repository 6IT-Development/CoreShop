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

namespace CoreShop\Bundle\StorageListBundle\Core\EventListener;

use CoreShop\Component\StorageList\Context\StorageListContextInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class SessionStoreStorageListLogoutSubscriber
{
    public function __construct(
        private StorageListContextInterface $context,
        private string $sessionKeyName,
    ) {
    }

    public function onLogoutSuccess(LogoutEvent $event): void
    {
        $request = $event->getRequest();

        if (!$request->hasSession()) {
            return;
        }

        if ($request->attributes->get('_stateless', false)) {
            return;
        }

        $session = $request->getSession();
        $prefix = $this->sessionKeyName . '.';

        // remove the lists of all stores, not only the current one, as stores can share the session
        foreach (array_keys($session->all()) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                $session->remove($key);
            }
        }
    }
}
