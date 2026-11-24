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

use CoreShop\Component\Core\Cart\Notice\CartNotices;
use CoreShop\Component\Core\Model\NoticeableCartInterface;
use CoreShop\Component\Core\Model\OrderInterface;
use CoreShop\Component\Order\Event\DeletedCartItemsDroppedEvent;
use Pimcore\Model\DataObject\Concrete;

/**
 * A copy of the cart loaded before ProductAvailabilityEventListener removed a product is being saved
 * (CartManager::persistCart(), which already left the deleted items out). That removal stored a notice and the
 * recalculation flag, which this copy would overwrite: they are copied over from the stored cart, so the customer
 * still learns about the removal (RequestCartAvailability on the next request).
 */
final class StaleCartChangesListener
{
    public function onDeletedCartItemsDropped(DeletedCartItemsDroppedEvent $event): void
    {
        $cart = $event->getCart();
        if (!$cart instanceof OrderInterface || !$cart instanceof Concrete || !$cart->getId()) {
            return;
        }

        $stored = $cart::getById($cart->getId(), ['force' => true]);
        if (!$stored instanceof OrderInterface) {
            return;
        }

        if ($stored->getNeedsRecalculation()) {
            $cart->setNeedsRecalculation(true);
        }

        if ($cart instanceof NoticeableCartInterface && $stored instanceof NoticeableCartInterface) {
            $notices = CartNotices::fromArray($cart->getCartNotices());
            $notices->mergeNewer(CartNotices::fromArray($stored->getCartNotices()));
            $cart->setCartNotices($notices->toArray());
        }
    }
}
