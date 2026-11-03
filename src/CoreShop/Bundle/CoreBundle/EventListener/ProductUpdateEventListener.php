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

use CoreShop\Component\Core\Configuration\ConfigurationServiceInterface;
use CoreShop\Component\Order\Model\PurchasableInterface;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\Element\DirtyIndicatorInterface;

final class ProductUpdateEventListener
{
    private const STOCK_FIELDS = ['onHand', 'onHold'];

    public function __construct(
        private ConfigurationServiceInterface $configurationService,
    ) {
    }

    public function storeConfigurationThatProductChanged(DataObjectEvent $event): void
    {
        $object = $event->getObject();

        if (!$object instanceof PurchasableInterface) {
            return;
        }

        if ($this->isStockOnlyUpdate($object)) {
            return;
        }

        $this->configurationService->set('SYSTEM.PRICE_RULE.UPDATE', time());
    }

    /**
     * Inventory updates run for every order and cannot change a price. Fields that do not
     * report changes are not in the dirty map, so a save without dirty fields still counts.
     */
    private function isStockOnlyUpdate(object $object): bool
    {
        if (!$object instanceof DirtyIndicatorInterface) {
            return false;
        }

        $dirtyFields = $object->getDirtyFields();

        return $dirtyFields !== [] && array_diff($dirtyFields, self::STOCK_FIELDS) === [];
    }
}
