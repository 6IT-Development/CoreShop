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

use CoreShop\Component\Product\Model\ProductInterface;

/**
 * A rules fetcher that keeps results and can be told that a product's rules changed.
 */
interface InvalidatableRulesFetcherInterface
{
    /**
     * Forgets the cached rules of the product: of this object and of every other loaded instance with the same id.
     */
    public function invalidate(ProductInterface $product): void;
}
