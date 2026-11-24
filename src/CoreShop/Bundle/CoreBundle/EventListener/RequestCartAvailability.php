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

use CoreShop\Bundle\CoreBundle\Order\CartRecalculator;
use CoreShop\Component\Core\Context\ShopperContextInterface;
use CoreShop\Component\Core\Model\OrderInterface;
use Pimcore\Http\RequestHelper;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Recalculates a cart that lost products (ProductAvailabilityEventListener) on its next request. The customer is
 * told by the cart notices, which stay until dismissed: no flash message, no redirect (a redirect turned a POST into
 * a GET and answered an XHR with a redirect, a flash message was gone on the next page).
 */
final class RequestCartAvailability
{
    public function __construct(
        private CartRecalculator $cartRecalculator,
        private ShopperContextInterface $shopperContext,
        private RequestHelper $pimcoreRequestHelper,
    ) {
    }

    public function checkCartAvailability(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->pimcoreRequestHelper->isFrontendRequest($event->getRequest())) {
            return;
        }

        // the profiler and other internal routes
        if (str_starts_with((string) $event->getRequest()->attributes->get('_route'), '_')) {
            return;
        }

        if ($event->getRequest()->attributes->getBoolean(RequestCartRecalculation::SKIP_ATTRIBUTE)) {
            return;
        }

        if (!$this->shopperContext->hasStore()) {
            return;
        }

        $cart = $this->shopperContext->getCart();

        if (!$cart instanceof OrderInterface || !$cart->getId() || !$cart->getNeedsRecalculation()) {
            return;
        }

        $cart->setNeedsRecalculation(false);
        $this->cartRecalculator->recalculate($cart);
    }
}
