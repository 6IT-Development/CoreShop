# Product Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope, path conventions and markers: see [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md) (`Calculator/`, `Exception/`, `Repository/`, `Rule/` and dependent config; `Model/` not scanned yet). Component items use `CI-` numbers. **(BC)** = changes a public signature or behaviour; needs an upgrade note.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Design / logic

### CI-01 — Composite calculators: iterate from the highest priority and stop at the first result
- **Where:** `Calculator/CompositeRetailPriceCalculator.php:30-51`, `Calculator/CompositeDiscountPriceCalculator.php:30-51`.
- **Today:** every registered calculator runs (store price, unit price, global rules, specific rules), and the **last** success overwrites the others. Because the registry iterates in ascending priority, the result is "highest priority wins", but only by accident of the loop, and the work of all lower-priority calculators is wasted (each rule calculator evaluates all its rules).
- **Improve:** iterate in descending priority and `return` on the first success (`array_reverse($registry->all())`, or `PriorityMap::setDescOrder()` for these registries). The two classes are identical apart from the interface and the exception: extract one generic helper (`firstSuccessful(iterable $calculators, \Closure $call, string $notFoundException)`) or a small abstract base. Document the "highest priority wins" rule in the interface docblocks.

### CI-02 — `ProductPriceRuleCalculator`: fetch the valid rules once, one loop instead of four
- **Where:** `Rule/Calculator/ProductPriceRuleCalculator.php:49-191`.
- **Today:** four near-identical methods, each calling `getValidRules()` and resolving every action processor again. `ProductPriceCalculator::getPrice()` calls three of them, `TaxedProductPriceCalculator` and the cart item processor call them again — per price lookup the rule fetcher runs 4 × 2 times (global + specific calculator), each time through `findActive()` when the memory cache is bypassed (CLI, workers).
- **Improve:**
  - One private generator `actionsOf(ProductInterface $product, array $context, string $processorInterface): \Generator` that walks valid rules, honours `stopPropagation`, checks `has()` (TODO-1 **CC-03**) and yields `[$processor, $configuration]`. Each public method becomes 3–5 lines.
  - Remove the redundant `if (empty($rules)) return $discount;` (`:139-141`).
  - Replace the inline `@var` casts with a typed `@return list<PriceRuleInterface>` on `ValidRulesFetcherInterface::getValidRules()`.
  - Optional: compute a `ProductPriceResult` (retail, discount price, discount, attributes) in one pass and cache that per (product, context) instead of four separate calls.

### CI-03 — Caching: one correct, resettable mechanism instead of two broken ones
- **Where:** `Calculator/MemoryCachedProductPriceCalculator.php` (unused, TODO-2 **CB-01**), `Rule/Fetcher/MemoryCachedValidRuleFetcher.php` (TODO-1 **CC-01**, TODO-2 **CB-06**).
- **Improve:**
  - Delete `MemoryCachedProductPriceCalculator` (or deprecate it with `#[\Deprecated]`, PHP 8.4) — it is not wired, and caching whole prices duplicates what the rule-level cache should do.
  - Cache what is context-independent: the active rules (`findActive()` result) per request / until a rule is saved (Symfony Cache with a tag invalidated on rule save). Evaluate conditions per call — they are cheap compared to the queries.
  - If a per-product cache stays: `\WeakMap` + context fingerprint, `ResetInterface`, no `RequestStack` dependency in the component (whether caching is allowed is a bundle decision; remove `symfony/http-foundation` from the component, see **CI-10**). Same direction as ProductQuantityPriceRules TODO-3 **I-10** and CoreBundle TODO-3 **I-23**.

### CI-04 — Make the `$context` array a documented shape (or a value object)
- **Where:** every calculator and action signature (`array $context`); keys used in this scan: `store`, `base_currency`, `unitDefinition`, `cart`, `customer`, `country`, `currency`.
- **Improve:** define a PHPStan type alias (`@phpstan-type ProductPriceContext array{store: StoreInterface, base_currency: CurrencyInterface, unitDefinition?: ProductUnitDefinitionInterface, cart?: OrderInterface, …}`) in one place and reference it with `@phpstan-import-type`. Long term **(BC)**: a readonly `PriceContext` object with typed getters and a `fingerprint(): string` method (directly usable as cache key for TODO-1 **CC-01**). This also replaces the repeated `Assert::keyExists($context, 'base_currency'); Assert::isInstanceOf(…)` blocks in the three currency actions.

---

## PHP 8.4 / Symfony 7.4 idioms

### CI-05 — Exceptions: one base class, typed constructor, `\Throwable` previous **(BC)**
- **Where:** `Exception/*.php` (three copies of the same constructor).
- **Improve:**
  - `abstract class PriceNotFoundException extends \RuntimeException` (unchecked, like the ProductQuantityPriceRules one) with `public function __construct(public readonly string $calculatorClass, ?\Throwable $previous = null)`; the three concrete classes only set the message. Callers can then `catch (PriceNotFoundException)` once.
  - Use `self::class` / `static::class` instead of `__CLASS__` at the throw sites (`Composite*Calculator.php:48`, `ProductPriceRuleCalculator.php:84,124`, the action processors).
  - Either throw `NoPriceFoundException` from `ProductPriceCalculator::getPrice()` (TODO-2 **CB-04**) or remove it; today it is dead and has a name clash with `CoreShop\Component\ProductQuantityPriceRules\Exception\NoPriceFoundException` (both imported in Core).

### CI-06 — Money arithmetic in integers with an explicit rounding mode
- **Where:** `Rule/Action/DiscountPercentActionProcessor.php:30`; same pattern in `Component/Core/Cart/Rule/Action/DiscountPercentActionProcessor.php`, `Component/Core/CartItem/Rule/Action/DiscountPercentActionProcessor.php`, `Component/Shipping|Payment/Rule/Action/DiscountPercentActionProcessor.php` **(dependent)**.
- **Improve:** one small, tested helper (for example `CoreShop\Component\Currency\Math::percentOf(int $amount, string|int|float $percent, \RoundingMode $mode = \RoundingMode::HalfAwayFromZero): int`) based on integer basis points or `BcMath\Number` (PHP 8.4). Use the PHP 8.4 `\RoundingMode` enum instead of the implicit default. Fixes TODO-2 **CB-07** everywhere at once.

### CI-07 — Typed action configuration instead of raw arrays
- **Where:** `Rule/Action/{PriceActionProcessor,DiscountPriceActionProcessor,DiscountAmountActionProcessor,DiscountPercentActionProcessor}.php`.
- **Improve:**
  - `final readonly class AmountConfiguration { public function __construct(public int $amount, public int $currencyId) {} public static function fromArray(array $c): self }` (validates and casts, TODO-2 **CB-09**); `PercentConfiguration` likewise.
  - `PriceActionProcessor` and `DiscountPriceActionProcessor` are identical except for the exception class (`:37-68`): extract a shared protected method or trait (`resolveFixedPrice()`), and do the same for the currency lookup + conversion used by `DiscountAmountActionProcessor`.
  - Store the currency ISO code next to the id in new configurations, so a re-created currency matches again (Currency TODO-1 **C-02**) **(dependent: ProductBundle forms)**.
  - Type `$subject` as `ProductInterface` in the processor interfaces once generics allow it, or at least add `@param ProductInterface $subject`; today `DiscountPercentActionProcessor` asserts it, `DiscountAmountActionProcessor` does not check it at all.

### CI-08 — `TimeSpanConditionChecker`: Symfony Clock instead of `Carbon::now()`
- **Where:** `Rule/Condition/TimeSpanConditionChecker.php:28-48`.
- **Improve:** inject `Psr\Clock\ClockInterface` (Symfony 7.4 service `clock`, `symfony/clock` is already installed in `vendor/`), compare `$clock->now()->getTimestamp() * 1000` with the stored integers, drop both Carbon objects and the Carbon dependency. Share the "is this time span active / expired / future" logic with `Component/Order/Cart/Rule/Condition/TimeSpanConditionChecker.php` and `RuleBundle/Assessor/RuleAvailabilityAssessor.php` (RuleBundle TODO-3 **I-05**) **(dependent)**. Tests can then use `MockClock`. Optionally implement `ProductConditionCheckerInterface` and `ProductSpecificConditionCheckerInterface` so it is autoconfigured like a project condition (it is tagged manually today, `Bundle/ProductBundle/Resources/config/services.yml:79-82`).

### CI-09 — Interfaces: native types, `readonly`, `final`, `#[\Override]`, no facade inheritance **(BC for the interface part)**
- **Where:** all services in `Calculator/` and `Rule/`.
- **Improve:**
  - Constructor-promoted dependencies `private readonly` (all composites, `ProductPriceCalculator`, `ProductPriceRuleCalculator`, the fetchers, the action processors); `final` on `Composite*Calculator` and the action processors, which are `class` with `protected` properties today; `#[\Override]` on every implemented method.
  - `ProductPriceCalculatorInterface` extends all four strategy interfaces. That is why autoconfiguration tags a decorator as four strategies (ProductBundle TODO-1 **C-03**). Make the facade a separate interface (`ProductPriceCalculatorInterface` with its own five methods, not `extends`), and keep the strategy interfaces for the composites only.
  - Native `mixed $subject` (or `ProductInterface`) and `@return list<ProductAttribute>` in `Rule/Action/*Interface.php`; `@throws NoRetailPriceFoundException` / `@throws NoDiscountPriceFoundException` on the interfaces that rely on them.
  - The empty marker interfaces `ActionProcessorInterface`, `ProductActionProcessorInterface`, `ProductSpecificActionProcessorInterface`, `ProductConditionCheckerInterface`, `ProductSpecificConditionCheckerInterface` are only used for autoconfiguration (`Bundle/ProductBundle/DependencyInjection/CoreShopProductExtension.php`). The built-in actions implement the functional interfaces but not the markers, so a project action that implements only `ProductDiscountActionProcessorInterface` is silently not registered. Document in the docblocks that both are required, or let the functional interfaces extend the product markers.
  - Use PHP 8.4 `array_any()` / `array_find()` where loops search (`MemoryCached*` lookups, the rule loops after **CI-02**), and `??=` for the cache writes.

### CI-10 — `composer.json`: declare what the component uses, drop what it does not
- **Where:** `composer.json`.
- **Today:** the code uses `webmozart/assert` (`Calculator/CompositeProductCustomAttributesCalculator.php`, all currency actions), `nesbot/carbon` (`Rule/Condition/TimeSpanConditionChecker.php`) and `symfony/http-foundation` (`RequestStack` in both memory caches), none of which is required; a standalone install of `coreshop/product` fails on the first price calculation. `coreshop/address` is required but not used by any class in this scan (re-check against `Model/` before removing). No `php` constraint.
- **Improve:** add `"php": ">=8.4"`, `webmozart/assert`, `psr/clock` (after **CI-08**, instead of Carbon), `symfony/service-contracts` (for `ResetInterface`, after **CI-03**), and drop `symfony/http-foundation` once the caches no longer need `RequestStack`. Move `phpstan/*` to `^2` together with the rest of the repository.

### CI-11 — Repository interfaces: generics and real contracts
- **Where:** `Repository/*.php`.
- **Improve:** `@extends PimcoreRepositoryInterface<ProductInterface>` / `<CategoryInterface>`, `@return list<CategoryInterface>` instead of `CategoryInterface[]`, `@return list<ProductSpecificPriceRuleInterface>` with "ordered by priority ASC, id ASC" (TODO-2 **CB-12**). Give the empty `ProductRepositoryInterface`, `PriceRuleRepositoryInterface` and `ProductPriceRuleRepositoryInterface` a one-line docblock saying what they are for (marker for DI / for the resource system), so they are not removed as dead code.

---

## Readability / small clean-ups

### CI-12 — Tests for the price path (none exist in the component)
- **Where:** the component has no `Tests/` directory. Behat covers single conditions and actions (`features/domain/product/{price_rules,specific_price_rules}/*.feature`), not the edge cases found here.
- **Add (PHPUnit, no kernel needed — the classes take plain interfaces):**
  - `ProductPriceCalculator`: discount larger than price → 0 (TODO-1 **CC-02**); no retail price + discount price (TODO-2 **CB-04**).
  - `ProductPriceRuleCalculator`: unknown action type is skipped (TODO-1 **CC-03**); precedence and stop propagation (TODO-2 **CB-05**); custom attributes of two rules are both kept (TODO-2 **CB-03**).
  - `MemoryCachedValidRuleFetcher`: different contexts / unsaved products / `reset()` (TODO-1 **CC-01**, TODO-2 **CB-06**).
  - `DiscountPercentActionProcessor`: 17.5 / 35 / 70 % table from TODO-2 **CB-07**.
  - `TimeSpanConditionChecker` with `MockClock`: empty, missing, reversed, open-ended spans (TODO-2 **CB-08**).
  - Action processors with float / string / missing configuration (TODO-2 **CB-09**).
  - Behat: a global rule plus a specific rule with stop propagation; a `discountAmount` larger than the price.

### CI-13 — Small readability fixes
- `Calculator/Composite*Calculator.php`, `Rule/Calculator/ProductPriceRuleCalculator.php`: drop the temporary `$actionPrice = …; $price = $actionPrice;` pairs; replace `//Silently ignore the error` with a comment that says *why* (no price from this rule is a normal case).
- `Calculator/MemoryCachedProductPriceCalculator.php:34`: property `$cachedIsDiscountable` holds custom attributes → rename (if the class is kept, **CI-03**).
- `Rule/Fetcher/CompositeValidRuleFetcher.php:38-40`: `array_merge(...array_map(fn ($f) => $f->getValidRules($product, $context), $this->validRuleFetchers->all()))` instead of merging in a loop (quadratic copying); it uses a non-prioritised `ServiceRegistry`, so the order of global vs. specific rules in the merged list (used by `CoreBundle/Twig/ValidPriceRulesExtension`) is registration order — document it or sort by priority.
- `Rule/Condition/TimeSpanConditionChecker.php:35-45`: nested `if`s → two early returns (or the shared helper from **CI-08**).
- Name the parameter of `CompositeDiscountCalculator::__construct()` `$discountCalculators` (plural), likewise in the other composites — they hold registries, not a single calculator.
- `Rule/Action/NotDiscountableCustomAttributeActionProcessor.php`: move `'not_discountable'` to a public constant (`ProductAttribute::NOT_DISCOUNTABLE`) and use it in `Component/Core/Cart/Rule/Applier/CartRuleApplier.php:249` **(dependent)** — a typo on either side silently disables the feature today.
