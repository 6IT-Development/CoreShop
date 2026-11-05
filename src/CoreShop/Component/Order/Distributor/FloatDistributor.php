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

final class FloatDistributor implements FloatDistributorInterface
{
    public function distribute(float $floatAmount, float $numberOfTargets): array
    {
        Assert::true((1 <= $numberOfTargets), 'Number of targets must be bigger than 0.');

        $sign = $floatAmount < 0 ? -1 : 1;
        $amount = DistributorAmount::toInt(abs($floatAmount));

        $intTargetAmount = (int) floor($numberOfTargets);
        $floatTargetAmount = $numberOfTargets - $intTargetAmount;

        // The fractional target gets its share rounded down, the whole targets share the rest
        $floatAmountOfAmount = (int) floor($amount / $numberOfTargets * $floatTargetAmount);
        $amount -= $floatAmountOfAmount;

        $low = intdiv($amount, $intTargetAmount);
        $high = $low + 1;

        $remainder = $amount % $intTargetAmount;
        $result = [];

        for ($i = 0; $i < $remainder; ++$i) {
            $result[] = $high * $sign;
        }

        for ($i = $remainder; $i < $intTargetAmount; ++$i) {
            $result[] = $low * $sign;
        }

        if ($floatTargetAmount > 0) {
            $result[] = $floatAmountOfAmount * $sign;
        }

        return $result;
    }
}
