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

use CoreShop\Bundle\CoreBundle\Product\PricingChangeTracker;
use CoreShop\Bundle\CoreBundle\Product\StoredProductComparator;
use CoreShop\Component\Core\Configuration\ConfigurationServiceInterface;
use CoreShop\Component\Order\Model\PurchasableInterface;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\Concrete;

/**
 * Every cart is recalculated on its next request after SYSTEM.PRICE_RULE.UPDATE moved (RequestCartRecalculation).
 * A product save moves it only when something the cart price depends on changed: the published state, the store
 * values (price and tax rule per store) or one of the configured fields
 * (core_shop_core.cart_recalculation.product_fields), compared with the stored product before the save, or the
 * Doctrine side of its pricing (specific and quantity price rules, unit prices) was written during the save
 * (PricingChangeTracker).
 */
final class ProductUpdateEventListener
{
    /** @var array<int, true> */
    private array $changedProductIds = [];

    /**
     * @param list<string> $priceFields
     */
    public function __construct(
        private ConfigurationServiceInterface $configurationService,
        private StoredProductComparator $storedProductComparator,
        private PricingChangeTracker $pricingChangeTracker,
        private array $priceFields,
    ) {
    }

    public function rememberPriceRelevantChange(DataObjectEvent $event): void
    {
        $object = $event->getObject();

        if (!$object instanceof PurchasableInterface || !$object instanceof Concrete || !$object->getId()) {
            return;
        }

        if (
            $this->storedProductComparator->hasPublishedChanged($object)
            || $this->storedProductComparator->haveStoreValuesChanged($object)
            || $this->storedProductComparator->haveFieldsChanged($object, $this->priceFields)
        ) {
            $this->changedProductIds[$object->getId()] = true;
        }
    }

    public function storeConfigurationThatProductChanged(DataObjectEvent $event): void
    {
        $object = $event->getObject();

        if (!$object instanceof PurchasableInterface) {
            return;
        }

        // always asked, so a pricing write is not left for a later, unrelated product save
        $pricingWritten = $this->pricingChangeTracker->consumeChange();
        if (!isset($this->changedProductIds[$object->getId()]) && !$pricingWritten) {
            return;
        }

        unset($this->changedProductIds[$object->getId()]);

        $this->configurationService->set('SYSTEM.PRICE_RULE.UPDATE', time());
    }
}
