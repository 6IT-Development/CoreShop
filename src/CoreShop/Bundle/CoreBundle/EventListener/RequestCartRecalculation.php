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
use CoreShop\Component\Core\Configuration\ConfigurationServiceInterface;
use CoreShop\Component\Core\Context\ShopperContextInterface;
use CoreShop\Component\Core\Model\OrderInterface;
use Doctrine\DBAL\Exception\DriverException;
use Pimcore\Http\RequestHelper;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final class RequestCartRecalculation
{
    /**
     * A request with this attribute set to true leaves the cart as it is, e.g. a page that fetches the cart widget
     * after it loaded: that request recalculates, the page does not have to wait for it.
     */
    public const SKIP_ATTRIBUTE = '_coreshop_skip_cart_recalculation';

    /**
     * MariaDB: "Record has changed since last read", the row was updated after this transaction's snapshot.
     */
    private const RECORD_CHANGED_SINCE_LAST_READ = 1020;

    public function __construct(
        private CartRecalculator $cartRecalculator,
        private ShopperContextInterface $shopperContext,
        private ConfigurationServiceInterface $configurationService,
        private RequestHelper $pimcoreRequestHelper,
    ) {
    }

    public function checkPriceRuleState(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->pimcoreRequestHelper->isFrontendRequest($event->getRequest())) {
            return;
        }

        if ($event->getRequest()->attributes->get('_route') === '_wdt') {
            return;
        }

        if ($event->getRequest()->attributes->getBoolean(self::SKIP_ATTRIBUTE)) {
            return;
        }

        if (!$this->shopperContext->hasStore()) {
            return;
        }

        $cart = $this->shopperContext->getCart();

        if ($cart instanceof OrderInterface && $cart->getId()) {
            /**
             * @var int|null $updateTime
             */
            $updateTime = $this->configurationService->get('SYSTEM.PRICE_RULE.UPDATE');

            if (null !== $updateTime && $updateTime > $cart->getModificationDate()) {
                try {
                    $this->cartRecalculator->recalculate($cart);
                } catch (DriverException $exception) {
                    if ($exception->getCode() !== self::RECORD_CHANGED_SINCE_LAST_READ) {
                        throw $exception;
                    }

                    // A parallel request of the same customer is saving (and so recalculating) this cart.
                }
            }
        }
    }
}
