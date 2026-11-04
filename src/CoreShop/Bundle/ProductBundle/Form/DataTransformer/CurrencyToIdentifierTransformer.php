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
namespace CoreShop\Bundle\ProductBundle\Form\DataTransformer;

use CoreShop\Component\Currency\Model\CurrencyInterface;
use CoreShop\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Form\DataTransformerInterface;

/**
 * Model transformer for a CurrencyChoiceType field whose stored value is the currency ID
 * (the price rule action configurations store the ID, the choice field works with entities).
 *
 * @internal
 *
 * @implements DataTransformerInterface<int|CurrencyInterface|null, CurrencyInterface|null>
 */
final class CurrencyToIdentifierTransformer implements DataTransformerInterface
{
    public function __construct(
        private readonly ?RepositoryInterface $currencyRepository,
    ) {
    }

    public function transform(mixed $value): ?CurrencyInterface
    {
        if ($value instanceof CurrencyInterface) {
            return $value;
        }

        if ($this->currencyRepository === null || !is_numeric($value)) {
            return null;
        }

        $currency = $this->currencyRepository->find((int) $value);

        return $currency instanceof CurrencyInterface ? $currency : null;
    }

    public function reverseTransform(mixed $value): ?int
    {
        if ($value instanceof CurrencyInterface) {
            return $value->getId();
        }

        return null;
    }
}
