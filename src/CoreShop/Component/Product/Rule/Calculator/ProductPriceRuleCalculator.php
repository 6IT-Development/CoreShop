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

namespace CoreShop\Component\Product\Rule\Calculator;

use CoreShop\Component\Product\Calculator\ProductCustomAttributesCalculatorInterface;
use CoreShop\Component\Product\Calculator\ProductDiscountCalculatorInterface;
use CoreShop\Component\Product\Calculator\ProductDiscountPriceCalculatorInterface;
use CoreShop\Component\Product\Calculator\ProductRetailPriceCalculatorInterface;
use CoreShop\Component\Product\Exception\InvalidActionConfigurationException;
use CoreShop\Component\Product\Exception\NoDiscountPriceFoundException;
use CoreShop\Component\Product\Exception\NoRetailPriceFoundException;
use CoreShop\Component\Product\Model\PriceRuleInterface;
use CoreShop\Component\Product\Model\ProductInterface;
use CoreShop\Component\Product\Rule\Action\ProductCustomAttributesActionProcessorInterface;
use CoreShop\Component\Product\Rule\Action\ProductDiscountActionProcessorInterface;
use CoreShop\Component\Product\Rule\Action\ProductDiscountPriceActionProcessorInterface;
use CoreShop\Component\Product\Rule\Action\ProductPriceActionProcessorInterface;
use CoreShop\Component\Product\Rule\Fetcher\ValidRulesFetcherInterface;
use CoreShop\Component\Registry\ServiceRegistryInterface;
use CoreShop\Component\Rule\Model\ActionInterface;
use Psr\Log\LoggerInterface;

final class ProductPriceRuleCalculator implements
    ProductDiscountCalculatorInterface,
    ProductRetailPriceCalculatorInterface,
    ProductDiscountPriceCalculatorInterface,
    ProductCustomAttributesCalculatorInterface
{
    public function __construct(
        private ValidRulesFetcherInterface $validRulesFetcher,
        private ServiceRegistryInterface $actionServiceRegistry,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function getRetailPrice(ProductInterface $product, array $context): int
    {
        $price = null;

        $this->processRules($product, $context, static function (ActionInterface $action, object $processor, ?int $rulePrice) use ($product, $context): ?int {
            if (!$processor instanceof ProductPriceActionProcessorInterface) {
                return $rulePrice;
            }

            try {
                return $processor->getPrice($product, $context, $action->getConfiguration());
            } catch (NoRetailPriceFoundException) {
                return $rulePrice;
            }
        }, static function (?int $rulePrice) use (&$price): void {
            $price = $rulePrice ?? $price;
        });

        if (null === $price) {
            throw new NoRetailPriceFoundException(__CLASS__);
        }

        return $price;
    }

    public function getDiscountPrice(ProductInterface $product, array $context): int
    {
        $price = null;

        $this->processRules($product, $context, static function (ActionInterface $action, object $processor, ?int $rulePrice) use ($product, $context): ?int {
            if (!$processor instanceof ProductDiscountPriceActionProcessorInterface) {
                return $rulePrice;
            }

            try {
                return $processor->getDiscountPrice($product, $context, $action->getConfiguration());
            } catch (NoDiscountPriceFoundException) {
                return $rulePrice;
            }
        }, static function (?int $rulePrice) use (&$price): void {
            $price = $rulePrice ?? $price;
        });

        if (null === $price) {
            throw new NoDiscountPriceFoundException(__CLASS__);
        }

        return $price;
    }

    public function getDiscount(ProductInterface $product, array $context, int $price): int
    {
        $discount = 0;

        $this->processRules($product, $context, static function (ActionInterface $action, object $processor, ?int $ruleDiscount) use ($product, $context, $price): ?int {
            if (!$processor instanceof ProductDiscountActionProcessorInterface) {
                return $ruleDiscount;
            }

            return ($ruleDiscount ?? 0) + $processor->getDiscount($product, $price, $context, $action->getConfiguration());
        }, static function (?int $ruleDiscount) use (&$discount): void {
            $discount += $ruleDiscount ?? 0;
        });

        // Discounts of all actions add up on the same price (10 % + 20 % = 30 %); never discount more than the price
        return min(max(0, $price), $discount);
    }

    public function getCustomAttributes(ProductInterface $product, array $context): array
    {
        $customAttributes = [];

        $this->processRules($product, $context, static function (ActionInterface $action, object $processor, ?array $ruleAttributes) use ($product, $context): ?array {
            if (!$processor instanceof ProductCustomAttributesActionProcessorInterface) {
                return $ruleAttributes;
            }

            return ($ruleAttributes ?? []) + $processor->getCustomAttributes($product, $context, $action->getConfiguration());
        }, static function (?array $ruleAttributes) use (&$customAttributes): void {
            $customAttributes += $ruleAttributes ?? [];
        });

        return $customAttributes;
    }

    /**
     * Runs $applyAction over the actions of every valid rule and hands the rule's result to $commitRule.
     *
     * A rule is applied completely or not at all: if one of its actions has a type without a registered
     * processor, or a processor rejects its configuration, the rule is skipped and logged, so one broken
     * rule cannot take down every price calculation. A skipped rule still stops propagation when it is
     * configured to, so lower-priority rules do not suddenly apply in its place.
     *
     * @param callable(ActionInterface, object, mixed): mixed $applyAction
     * @param callable(mixed): void                          $commitRule
     */
    private function processRules(ProductInterface $product, array $context, callable $applyAction, callable $commitRule): void
    {
        /**
         * @var PriceRuleInterface[] $rules
         */
        $rules = $this->validRulesFetcher->getValidRules($product, $context);

        foreach ($rules as $rule) {
            try {
                $ruleResult = null;

                /**
                 * @var ActionInterface $action
                 */
                foreach ($rule->getActions() as $action) {
                    $ruleResult = $applyAction($action, $this->getProcessor($action), $ruleResult);
                }

                $commitRule($ruleResult);
            } catch (InvalidActionConfigurationException $exception) {
                $this->logger?->critical('Product price rule "{rule}" was skipped: {message}', [
                    'rule' => $rule->getId(),
                    'action' => $action->getId(),
                    'type' => $action->getType(),
                    'product' => $product->getId(),
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);
            }

            if ($rule->getStopPropagation()) {
                break;
            }
        }
    }

    private function getProcessor(ActionInterface $action): object
    {
        $type = (string) $action->getType();

        if (!$this->actionServiceRegistry->has($type)) {
            throw new InvalidActionConfigurationException(sprintf('Action type "%s" has no registered processor.', $type));
        }

        return $this->actionServiceRegistry->get($type);
    }
}
