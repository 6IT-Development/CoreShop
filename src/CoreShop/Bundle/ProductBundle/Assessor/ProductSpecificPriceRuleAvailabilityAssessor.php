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

namespace CoreShop\Bundle\ProductBundle\Assessor;

use CoreShop\Bundle\ProductBundle\Doctrine\ORM\ProductSpecificPriceRuleRepository;
use CoreShop\Bundle\RuleBundle\Assessor\RuleAvailabilityAssessor;
use CoreShop\Component\Product\Repository\ProductSpecificPriceRuleRepositoryInterface;
use CoreShop\Component\Rule\Condition\Assessor\RuleAvailabilityAssessorInterface;
use CoreShop\Component\Rule\Model\RuleInterface;

/**
 * Availability check for product-specific price rules.
 *
 * A catalog can have one or more specific rules per product, so loading all active rules (as the
 * generic assessor does) can exhaust the worker's memory. Only a timespan condition can make a rule
 * unavailable, so only active rules that have one are loaded.
 */
final class ProductSpecificPriceRuleAvailabilityAssessor implements RuleAvailabilityAssessorInterface
{
    private RuleAvailabilityAssessor $inner;

    public function __construct(
        private ProductSpecificPriceRuleRepositoryInterface $repository,
    ) {
        $this->inner = new RuleAvailabilityAssessor($repository);
    }

    public function getRules(): array
    {
        // A replaced repository without the filtered query keeps the old behaviour.
        if (!$this->repository instanceof ProductSpecificPriceRuleRepository) {
            return $this->repository->findActive();
        }

        return $this->repository->findActiveWithConditionOfType('timespan');
    }

    public function isValid(RuleInterface $rule): bool
    {
        return $this->inner->isValid($rule);
    }
}
