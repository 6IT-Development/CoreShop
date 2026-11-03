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

namespace CoreShop\Bundle\MoneyBundle\Form\Transformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

class MoneyToIntegerTransformer implements DataTransformerInterface
{
    public function __construct(
        private int $decimalFactor,
    ) {
    }

    public function transform(mixed $value): ?float
    {
        if (null === $value) {
            return null;
        }

        return $value / $this->decimalFactor;
    }

    public function reverseTransform(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }

        $amount = round($value * $this->decimalFactor);

        // (float) PHP_INT_MAX rounds up to 2^63, the first value that no longer fits into an int
        if (!is_finite($amount) || abs($amount) >= (float) \PHP_INT_MAX) {
            throw new TransformationFailedException('The amount is out of range.');
        }

        return (int) $amount;
    }
}
