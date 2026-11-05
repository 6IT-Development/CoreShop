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

namespace CoreShop\Component\Order\Distributor;

use Webmozart\Assert\Assert;

/**
 * @internal
 */
final class DistributorAmount
{
    /**
     * The distributors split amounts in the smallest currency unit, so a fractional or
     * out-of-range amount would silently lose (or wrap) money.
     */
    public static function toInt(float $amount): int
    {
        Assert::true(is_finite($amount) && floor($amount) === $amount, sprintf('The amount "%s" must be a whole number.', $amount));
        Assert::true(abs($amount) < (float) \PHP_INT_MAX, sprintf('The amount "%s" is too large.', $amount));

        return (int) $amount;
    }
}
