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

namespace CoreShop\Bundle\CoreBundle\Product;

use CoreShop\Component\Core\Model\ProductStoreValuesInterface;
use CoreShop\Component\Core\Model\ProductUnitDefinitionPriceInterface;
use CoreShop\Component\Product\Model\ProductSpecificPriceRuleInterface;
use CoreShop\Component\Product\Model\ProductUnitDefinitionInterface;
use CoreShop\Component\Product\Model\ProductUnitDefinitionsInterface;
use CoreShop\Component\ProductQuantityPriceRules\Model\ProductQuantityPriceRuleInterface;
use CoreShop\Component\ProductQuantityPriceRules\Model\QuantityRangeInterface;
use CoreShop\Component\Rule\Model\ActionInterface;
use CoreShop\Component\Rule\Model\ConditionInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;

/**
 * Notices when the Doctrine side of a product's pricing is written: the specific and quantity price rules (with their
 * ranges, actions and conditions), the store values, the unit definitions and the unit prices. The product editor
 * saves them with the product (their Pimcore field types flush the entity manager in the save), so
 * ProductUpdateEventListener asks after the product save whether one changed. Doctrine knows exactly what it writes,
 * a comparison with the stored rows does not have to be rebuilt.
 *
 * A change is kept until it is asked for: it may make one product save more move the recalculation flag (e.g. a cart
 * price rule action saved in the same request), it never loses one.
 */
final class PricingChangeTracker
{
    private const array PRICING_ENTITIES = [
        ProductSpecificPriceRuleInterface::class,
        ProductQuantityPriceRuleInterface::class,
        QuantityRangeInterface::class,
        ActionInterface::class,
        ConditionInterface::class,
        ProductStoreValuesInterface::class,
        ProductUnitDefinitionPriceInterface::class,
        ProductUnitDefinitionsInterface::class,
        ProductUnitDefinitionInterface::class,
    ];

    private bool $changed = false;

    public function onFlush(OnFlushEventArgs $event): void
    {
        if ($this->changed) {
            return;
        }

        $unitOfWork = $event->getObjectManager()->getUnitOfWork();

        $entities = [
            ...$unitOfWork->getScheduledEntityInsertions(),
            ...$unitOfWork->getScheduledEntityUpdates(),
            ...$unitOfWork->getScheduledEntityDeletions(),
        ];
        foreach ([...$unitOfWork->getScheduledCollectionUpdates(), ...$unitOfWork->getScheduledCollectionDeletions()] as $collection) {
            // e.g. an action removed from a rule: the join table row of the rule's collection
            $entities[] = $collection->getOwner();
        }

        foreach ($entities as $entity) {
            if (self::isPricing($entity)) {
                $this->changed = true;

                return;
            }
        }
    }

    /**
     * Whether pricing was written since the last call.
     */
    public function consumeChange(): bool
    {
        $changed = $this->changed;
        $this->changed = false;

        return $changed;
    }

    private static function isPricing(?object $entity): bool
    {
        return $entity !== null && array_any(self::PRICING_ENTITIES, static fn (string $type): bool => $entity instanceof $type);
    }
}
