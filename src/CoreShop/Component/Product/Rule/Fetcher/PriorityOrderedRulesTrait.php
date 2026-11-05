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
namespace CoreShop\Component\Product\Rule\Fetcher;

use CoreShop\Component\Product\Model\PriceRuleInterface;

/**
 * Valid rules are returned by ascending priority, then ascending id (unsaved rules last), so the
 * order in which rules apply and which rule's stop propagation wins never depends on the order
 * of a repository result or of a collection.
 */
trait PriorityOrderedRulesTrait
{
    /**
     * @param iterable<PriceRuleInterface> $rules
     *
     * @return list<PriceRuleInterface>
     */
    private function orderByPriority(iterable $rules): array
    {
        $rules = is_array($rules) ? array_values($rules) : iterator_to_array($rules, false);

        usort($rules, static fn (PriceRuleInterface $a, PriceRuleInterface $b): int => [(int) $a->getPriority(), null === $a->getId(), $a->getId()] <=> [(int) $b->getPriority(), null === $b->getId(), $b->getId()]);

        return $rules;
    }
}
