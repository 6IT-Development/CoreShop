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

/**
 * @deprecated since CoreShop 4.1, it registers a retail price calculator. Use {@see AsProductRetailPriceCalculator} instead.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsProductPriceCalculator
{
    public function __construct(
        private ?string $type = null,
    ) {
    }

    public function getType(): ?string
    {
        return $this->type;
    }
}
