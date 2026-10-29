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

use CoreShop\Component\Store\Context\StoreContextInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Runs before Symfony's DefaultLogoutListener (priority 64), which only sets the
 * firewall's logout target when no response has been set yet.
 */
final class ShopUserLogoutHandler
{
    public function __construct(
        private RouterInterface $router,
        private string $routeName,
        private StoreContextInterface $storeContext,
    ) {
    }

    public function onLogoutSuccess(LogoutEvent $event): void
    {
        $request = $event->getRequest();

        if ($request->hasSession()) {
            $session = $request->getSession();

            // remove the carts of all stores, not only the current one, as stores can share the session
            foreach (array_keys($session->all()) as $key) {
                if (str_starts_with((string) $key, 'coreshop.cart.')) {
                    $session->remove($key);
                }
            }
        }

        $event->setResponse(new RedirectResponse($this->router->generate($this->routeName, ['_locale' => $request->getLocale()])));
    }
}
