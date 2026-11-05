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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Caches the valid rules per product object and rule context for the current
 * request. Outside a request (CLI, Messenger) nothing is cached, and the cache
 * is cleared between requests through ResetInterface.
 */
final class MemoryCachedValidRuleFetcher implements ValidRulesFetcherInterface, InvalidatableRulesFetcherInterface, ResetInterface
{
    /**
     * @var \WeakMap<ProductInterface, array<string, array>>
     */
    private \WeakMap $checkedProducts;

    private RuleContextFingerprinterInterface $contextFingerprinter;

    public function __construct(
        private ValidRulesFetcherInterface $validRuleFetcher,
        private RequestStack $requestStack,
        ?RuleContextFingerprinterInterface $contextFingerprinter = null,
    ) {
        $this->contextFingerprinter = $contextFingerprinter ?? new RuleContextFingerprinter();
        $this->checkedProducts = new \WeakMap();
    }

    public function getValidRules(ProductInterface $product, array $context): array
    {
        if (!$this->requestStack->getMainRequest() instanceof Request) {
            return $this->validRuleFetcher->getValidRules($product, $context);
        }

        $fingerprint = $this->contextFingerprinter->fingerprint($context);

        if (null === $fingerprint) {
            return $this->validRuleFetcher->getValidRules($product, $context);
        }

        if (isset($this->checkedProducts[$product][$fingerprint])) {
            return $this->checkedProducts[$product][$fingerprint];
        }

        $rules = $this->validRuleFetcher->getValidRules($product, $context);

        $cached = $this->checkedProducts[$product] ?? [];
        $cached[$fingerprint] = $rules;
        $this->checkedProducts[$product] = $cached;

        return $rules;
    }

    public function invalidate(ProductInterface $product): void
    {
        unset($this->checkedProducts[$product]);

        $id = $product->getId();

        if (null === $id) {
            return;
        }

        $stale = [];

        foreach ($this->checkedProducts as $cachedProduct => $rules) {
            if ($cachedProduct->getId() === $id) {
                $stale[] = $cachedProduct;
            }
        }

        foreach ($stale as $cachedProduct) {
            unset($this->checkedProducts[$cachedProduct]);
        }
    }

    public function reset(): void
    {
        $this->checkedProducts = new \WeakMap();
    }
}
