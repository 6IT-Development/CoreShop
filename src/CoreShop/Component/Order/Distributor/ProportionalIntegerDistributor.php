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

final class ProportionalIntegerDistributor implements ProportionalIntegerDistributorInterface
{
    public function distribute(array $integers, int $amount): array
    {
        Assert::allInteger($integers);

        if (!$integers) {
            return [];
        }

        $integers = array_values($integers);
        $total = array_sum($integers);

        // Nothing to weight by: distribute evenly instead of dividing by zero
        if (0 === $total) {
            $integers = array_fill(0, count($integers), 1);
            $total = count($integers);
        }

        // Work on a positive amount and total, so the shares keep the sign of the amount
        $sign = $amount < 0 ? -1 : 1;
        $amount = abs($amount);

        if ($total < 0) {
            $integers = array_map(static fn (int $weight): int => -$weight, $integers);
            $total = -$total;
        }

        // Largest remainder method: floor every exact share, then hand the remaining units
        // to the entries with the largest fractional parts. A zero weight has no fractional
        // part, so it never gets one.
        $distributedAmounts = [];
        $remainders = [];

        foreach ($integers as $i => $weight) {
            $share = $weight * $amount;
            $quotient = intdiv($share, $total);
            $remainder = $share % $total;

            if ($remainder < 0) {
                --$quotient;
                $remainder += $total;
            }

            $distributedAmounts[$i] = $quotient;
            $remainders[$i] = $remainder;
        }

        $missingAmount = $amount - array_sum($distributedAmounts);

        // arsort() is stable, so equal remainders go to the earlier entries first
        arsort($remainders);

        foreach (array_slice(array_keys($remainders), 0, $missingAmount) as $i) {
            ++$distributedAmounts[$i];
        }

        return array_map(static fn (int $share): int => $share * $sign, $distributedAmounts);
    }
}
