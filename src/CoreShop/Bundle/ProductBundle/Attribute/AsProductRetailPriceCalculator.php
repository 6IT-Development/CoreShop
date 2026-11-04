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

namespace CoreShop\Bundle\ProductBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsProductRetailPriceCalculator
{
    public function __construct(
        private ?string $type = null,
        private ?int $priority = null,
    ) {
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * Registry priority; null means the default (1000). The composite calculators run in
     * priority order, and the retail / discount-price composites let the last result win.
     */
    public function getPriority(): ?int
    {
        return $this->priority;
    }
}
