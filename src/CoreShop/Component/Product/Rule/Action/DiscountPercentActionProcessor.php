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

namespace CoreShop\Component\Product\Rule\Action;

use CoreShop\Component\Product\Model\ProductInterface;
use Webmozart\Assert\Assert;

class DiscountPercentActionProcessor implements ProductDiscountActionProcessorInterface
{
    use ActionConfigurationTrait;

    public function getDiscount($subject, int $price, array $context, array $configuration): int
    {
        Assert::isInstanceOf($subject, ProductInterface::class);

        return self::percentOf($price, $this->getFloatConfiguration($configuration, 'percent'));
    }

    /**
     * $percent % of $price, rounded half away from zero.
     *
     * Computed in integers: the percent is taken with four decimals (1/10 000 %), so 17.5 %, 35 % or 70 %
     * give the exact decimal result instead of the 1-cent-too-low result of a float product
     * (0.35 * 90 = 31.4999…).
     */
    public static function percentOf(int $price, float $percent): int
    {
        $scaledPercent = (int) round($percent * 10_000);

        if (0 === $scaledPercent || 0 === $price) {
            return 0;
        }

        // out of the safe integer range: fall back to float rounding
        if (abs($price) > intdiv(PHP_INT_MAX - 1_000_000, 2 * abs($scaledPercent))) {
            return (int) round($price * $scaledPercent / 1_000_000);
        }

        $product = $price * $scaledPercent;
        $rounded = intdiv(abs($product) * 2 + 1_000_000, 2_000_000);

        return $product < 0 ? -$rounded : $rounded;
    }
}
