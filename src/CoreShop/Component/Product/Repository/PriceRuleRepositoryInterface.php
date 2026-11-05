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

namespace CoreShop\Component\Product\Repository;

use CoreShop\Component\Product\Model\PriceRuleInterface;
use CoreShop\Component\Rule\Repository\RuleRepositoryInterface;

interface PriceRuleRepositoryInterface extends RuleRepositoryInterface
{
    /**
     * Returns the active rules, ordered by priority ascending (lower = more important), then by
     * id ascending. The valid-rule fetchers sort the rules again (`PriorityOrderedRulesTrait`),
     * so precedence does not depend on this order, but other callers may rely on it.
     *
     * @return list<PriceRuleInterface>
     */
    public function findActive(): array;
}
