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

namespace CoreShop\Component\Product\Calculator;

use CoreShop\Component\Product\Model\ProductInterface;
use CoreShop\Component\Product\Rule\Fetcher\RuleContextFingerprinter;
use CoreShop\Component\Product\Rule\Fetcher\RuleContextFingerprinterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Caches the results of an inner calculator for the duration of the main request.
 *
 * Results are kept per product object and per context, so unsaved products, other
 * stores, currencies, customers or unit definitions never share a result.
 * Nothing is cached outside a main request (CLI, Messenger), and the cache is
 * cleared on kernel.reset.
 */
final class MemoryCachedProductPriceCalculator implements ProductPriceCalculatorInterface, ResetInterface
{
    /**
     * @var \WeakMap<ProductInterface, array<string, mixed>>
     */
    private \WeakMap $cache;

    private RuleContextFingerprinterInterface $contextFingerprinter;

    public function __construct(
        private ProductPriceCalculatorInterface $inner,
        private RequestStack $requestStack,
        ?RuleContextFingerprinterInterface $contextFingerprinter = null,
    ) {
        $this->contextFingerprinter = $contextFingerprinter ?? new RuleContextFingerprinter();
        $this->cache = new \WeakMap();
    }

    public function getPrice(ProductInterface $product, array $context, bool $withDiscount = false): int
    {
        return $this->remember(
            $product,
            $context,
            'price:' . ($withDiscount ? '1' : '0'),
            fn (): int => $this->inner->getPrice($product, $context, $withDiscount),
        );
    }

    public function getRetailPrice(ProductInterface $product, array $context): int
    {
        return $this->remember(
            $product,
            $context,
            'retail',
            fn (): int => $this->inner->getRetailPrice($product, $context),
        );
    }

    public function getDiscountPrice(ProductInterface $product, array $context): int
    {
        return $this->remember(
            $product,
            $context,
            'discountPrice',
            fn (): int => $this->inner->getDiscountPrice($product, $context),
        );
    }

    public function getDiscount(ProductInterface $product, array $context, int $price): int
    {
        return $this->remember(
            $product,
            $context,
            'discount:' . $price,
            fn (): int => $this->inner->getDiscount($product, $context, $price),
        );
    }

    public function getCustomAttributes(ProductInterface $product, array $context): array
    {
        return $this->remember(
            $product,
            $context,
            'customAttributes',
            fn (): array => $this->inner->getCustomAttributes($product, $context),
        );
    }

    public function reset(): void
    {
        $this->cache = new \WeakMap();
    }

    /**
     * @template T
     *
     * @param callable(): T $compute
     *
     * @return T
     */
    private function remember(ProductInterface $product, array $context, string $method, callable $compute): mixed
    {
        if (!$this->requestStack->getMainRequest() instanceof Request) {
            return $compute();
        }

        $fingerprint = $this->contextFingerprinter->fingerprint($context);

        if (null === $fingerprint) {
            return $compute();
        }

        $key = $method . '|' . $fingerprint;
        $entries = $this->cache[$product] ?? [];

        if (array_key_exists($key, $entries)) {
            return $entries[$key];
        }

        $result = $compute();

        $entries = $this->cache[$product] ?? [];
        $entries[$key] = $result;
        $this->cache[$product] = $entries;

        return $result;
    }
}
