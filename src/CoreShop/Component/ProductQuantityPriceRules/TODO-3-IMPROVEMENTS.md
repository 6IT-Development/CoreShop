# ProductQuantityPriceRules Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, PHP 8.4 modernisation and forward-compatibility items. None of them is a functional bug on its own. Where one hides a latent bug, it says so.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have part of the fix outside this component. Items marked **(BC)** change a public signature or interface and belong in the next major version, or need a deprecation layer.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Package

### I-01 — `composer.json` and `README.md` do not match what the component uses
- **Where:** `composer.json`, `README.md`.
- **Problem / Fix:**
  - Used directly but not declared:
    - `doctrine/collections` (`Calculator/VolumeCalculator.php:27`, `Model/*`);
    - `symfony/http-foundation` (`Rule/Fetcher/MemoryCachedValidRuleFetcher.php:22-23`).

    A standalone `coreshop/product-quantity-price-rules` install only gets these transitively. Declare them, or move the request-aware decorator to the bundle (see **I-10**).
  - Declared but unused: `six-it/pimcore-source ^2.0`. No file in the component references Pimcore. Drop it, or, if it stays for the version constraint, match the root `^2.1`.
  - There is no `php` constraint. Add `"php": "^8.4"` (root: `~8.4.0`).
  - `require-dev`: `phpstan/phpstan ^1.10`, `phpstan-doctrine ^1.3.40`, `phpstan-webmozart-assert ^1.2.4` are PHPStan 1.x, which is EOL and does not parse PHP 8.4 syntax (property hooks, asymmetric visibility). Align with the root (`^2.x`). `phpstan-webmozart-assert` is unused, because the component has no `Assert` calls.
  - `branch-alias` `dev-master: 4.1-dev` does not match the split branch. The `repositories` entry is indented differently from the other keys.
  - `README.md` is boilerplate: "It is build", `coreshop.org` vs `coreshop.com` links, heading underlines of the wrong length. Add a short overview of the concepts (rule → ranges → pricing behaviour, calculator, detector, context keys; see **I-08**) and link `https://docs.coreshop.com`.

## Models

### I-02 — (BC) Type the models and their interfaces natively, and fix wrong docblocks
- **Where:** `Model/ProductQuantityPriceRule{,Interface}.php`, `Model/QuantityRange{,Interface}.php`, `Model/QuantityRangePriceAwareInterface.php`.
- **Problem / Fix:**
  - All properties are untyped. Use `protected ?int $id = null`, `protected ?int $product = null`, `protected ?string $name = null`, `protected ?string $calculationBehaviour = null`, `protected int $priority = 0`, `protected Collection $conditions`, `protected Collection $ranges` (`Collection<int, QuantityRangeInterface>`), `protected ?float $rangeStartingFrom = null`, `protected ?string $pricingBehaviour = null`, `protected float $percentage = 0.0` (today `= 0`, an int, so `getPercentage()` returns `int` until it is set), `protected bool $highlighted = false`, `protected ?ProductQuantityPriceRuleInterface $rule = null`.
  - Most parameters and returns are untyped: `getName()`, `setName($name)`, `getCalculationBehaviour()`, `setCalculationBehaviour($x)`, `getRanges()`, `hasRanges()`, `hasRange()`, `addRange()`, `removeRange()`, `getPriority()`, `setPriority($p)`, `getProduct()`, `setProduct($p)`, `getRangeStartingFrom()`, `getPricingBehaviour()`, `getPercentage()`, `getHighlighted()`, `isHighlighted()`, `getRule()`, `setRule($rule)`, and the `RuleInterface` methods `getConditions()`, `getActions()`, `hasActions()`, `hasAction()`, `addAction()`, `removeAction()`. Add native types together with `RuleInterface` **(dependent: Component/Rule)**.
  - Wrong or loose docblocks:
    - `getProduct()` says `@return int`, but the property is `int|null`; `@param int $product` on `setProduct()`.
    - `QuantityRangeInterface::getRule()` says `ProductQuantityPriceRuleInterface`, but it is nullable (TODO-2 **B-05**).
    - `getRanges()` says `Collection|QuantityRangeInterface[]`; use `Collection<int, QuantityRangeInterface>`.
    - `ProductQuantityPriceRule::$conditions` / `$ranges` say `ArrayCollection|…[]`, but Doctrine puts a `PersistentCollection` there.
  - Inconsistent setter returns: `setProduct()` and `setPriority()` return `$this`, while every other setter returns nothing, and the interface declares neither. Pick `: void` (or `: static`) and use it everywhere.
  - `QuantityRange::setId()` exists, but is not in `QuantityRangeInterface`. Only reflection code needs it (bundle **I-11**). Remove it, or add it to the interface.
  - `getHighlighted()` and `isHighlighted()` duplicate each other, and `isHighlighted()` compares `=== true` on a bool. Keep `isHighlighted(): bool` and deprecate the getter (it is used by JMS / ExtJS, so check the serializer config first) **(dependent)**.
  - `ProductQuantityPriceRule::__construct(\n        ) {` is an empty multi-line parameter list. Write `__construct()`.
  - `$inherit` is dead and the action methods throw a generic `\Exception`: tracked as bundle **I-08**, not repeated. The `@psalm-suppress MissingConstructor` on `QuantityRange` is unnecessary once the properties have defaults.
  - Consider PHP 8.4 asymmetric visibility for `id` (`public private(set) ?int $id`) only if Doctrine and JMS hydration are tested with it. Otherwise keep `protected`.

### I-03 — Give the models `__clone()` and a readable `__toString()`
- **Where:** `Model/ProductQuantityPriceRule.php:223-226`, `Model/QuantityRange.php`.
- **Problem / Fix:**
  - `clone $rule` today shares the `conditions` and `ranges` collections with the original (reproduced: adding a range to the clone adds it to the original) and keeps `id` and `product`. `ProductQuantityPriceRulesBundle/CoreExtension/ProductQuantityPriceRules.php:150-196` works around this with reflection. A `__clone()` that resets `id` / `product` and deep-clones conditions and ranges, re-attaching them with `addRange()`, belongs here. The bundle side is tracked as bundle **I-11**.
  - `__toString()` returns `" ()"` for a new, unnamed rule. Use `sprintf('%s (%s)', $this->name ?? '-', $this->id ?? 'new')`.

## Services

### I-04 — PHP 8.4 idioms in the services
- **Where:** `Calculator/VolumeCalculator.php`, `Detector/QuantityReferenceDetector.php`, `Fetcher/QuantityPriceFetcher.php`, `Fetcher/QuantityRuleFetcher.php`, `Rule/Action/Percentage{Decrease,Increase}Action.php`, `Rule/Fetcher/{MemoryCached,ValidProductQuantityPrice}Rule{,s}Fetcher.php`.
- **Fix:**
  - Constructor-promoted dependencies are never reassigned. Make them `readonly`, or make the class `final readonly` where nothing extends it: `QuantityReferenceDetector`, `QuantityPriceFetcher`, `QuantityRuleFetcher`, `ValidProductQuantityPriceRuleFetcher`, and both percentage actions. `VolumeCalculator` is extended or decorated by Core (`UnitVolumeCalculator` injects the concrete class), so make its property `protected readonly` rather than the class `final` (see **I-05**). `MemoryCachedValidRuleFetcher` keeps mutable state, so only its two dependencies become `readonly`.
  - Add `#[\Override]` to every method that implements an interface method.
  - `QuantityRuleFetcher.php:50`: the closure does not use `$this`. Make it `static fn`, and use `<=>` (see TODO-2 **B-02**).
  - `VolumeCalculator.php:46`: `__CLASS__` → `self::class` (or `static::class`, so a subclass name is reported).
  - Remove the inline `/** @var … $service */` casts after `ServiceRegistryInterface::get()` (`VolumeCalculator.php:65-68`, `QuantityPriceFetcher.php:41-44`, `:55-58`). Use typed registries or `Assert::isInstanceOf()`, which PHPStan understands.

### I-05 — (BC) Put `calculateRangePrice()` on an interface and stop Core from depending on the concrete `VolumeCalculator`
- **Where:** `Calculator/VolumeCalculator.php:61-71` (public, but not in `CalculatorInterface`), `:73-90` (`protected locate()`). **(dependent)** `Component/Core/ProductQuantityPriceRules/Calculator/UnitVolumeCalculator.php:36` (injects `VolumeCalculator`, calls `calculateRangePrice()`, and copies `locate()`).
- **Problem / Fix:**
  - The decorator needs a method that is not part of any interface, so the decoration is tied to the concrete class, and the `locate()` logic exists twice (TODO-1 **C-01**).
  - Split the work into a `QuantityRangeLocatorInterface` (`locate(Collection $ranges, float $quantity, array $context): ?QuantityRangeInterface`) and a `RangePriceCalculatorInterface` (`calculateRangePrice()`). Let Core provide a unit-aware locator instead of a calculator decorator.
  - Rename `$cheapestRangePrice` (`VolumeCalculator.php:79`, Core `:95`). The loop picks the *highest matching start*, not the cheapest price, and the name misleads anyone reading the code.

### I-06 — (BC) `QuantityReferenceDetector` depends on concrete fetchers
- **Where:** `Detector/QuantityReferenceDetector.php:29-33`.
- **Fix:** take `QuantityRuleFetcherInterface` and `QuantityPriceFetcherInterface`, so projects can decorate them. The service wiring (`@…\QuantityReferenceDetector` instead of the interface alias in the bundle and CoreBundle YAML) is tracked as bundle **I-16** and not repeated.

### I-07 — Exceptions: typed constructors, better messages, a common marker
- **Where:** `Exception/NoPriceFoundException.php`, `Exception/NoRuleFoundException.php`.
- **Fix:**
  - Typed parameters: `string $calculatorClass` and `?string $message = null` (today untyped, and `$message ?: …` also replaces `"0"`). The `?\Throwable` fix is TODO-2 **B-07**.
  - The messages give no subject or quantity, so a log line cannot be traced to a product. Add optional `?int $subjectId` / `?float $quantity`, or named constructors (`NoPriceFoundException::forQuantity(...)`).
  - Add a marker interface (`ProductQuantityPriceRulesExceptionInterface`), so callers can `catch` one type instead of listing both (`CartItemsProcessor.php:96`).
  - Make both classes `final`, unless projects are known to extend them.

### I-08 — Document the interfaces: generics, `@throws` and the `$context` shape
- **Where:** `Calculator/CalculatorInterface.php`, `Detector/QuantityReferenceDetectorInterface.php`, `Fetcher/Quantity{Price,Rule}FetcherInterface.php`, `Rule/Fetcher/ValidRulesFetcherInterface.php`, `Rule/Action/ProductQuantityPriceRuleActionInterface.php`, `Repository/ProductQuantityPriceRuleRepositoryInterface.php`, `Model/QuantityRangePriceAwareInterface.php`.
- **Fix:**
  - `ValidRulesFetcherInterface::getValidRules()` documents `RuleInterface[]`. It returns quantity rules, so use `list<ProductQuantityPriceRuleInterface>`. `QuantityRuleFetcherInterface:29` says `array|ProductQuantityPriceRuleInterface[]`; use `list<…>`. Do the same for `findForProduct()`, `getQuantityPriceRules()` and `setQuantityPriceRules(array …)`.
  - `QuantityRuleFetcherInterface::fetch()` throws `NoRuleFoundException`, and `QuantityPriceFetcherInterface::*` throw `NoPriceFoundException`, but neither documents it (the detector interface does). Add `@throws`.
  - `array $context` is untyped everywhere. Document the keys the code reads: `base_currency` (Core actions), `unitDefinition`, `cartItem`, `store`, `currency`, `country`, `customer`. Use `@param array<string, mixed> $context`, or introduce a small value object in the next major.
  - `ProductQuantityPriceRuleActionInterface::calculate(… int $realItemPrice …)` and `CalculatorInterface::*(… int $originalPrice …)` use different names for the same value. Pick one, and state that it is in the context currency's minor units.

### I-09 — Behaviour types as constants or an enum; explicit rounding
- **Where:** `Rule/Action/Percentage{Decrease,Increase}Action.php:28`. **(dependent)** type strings `volume`, `percentage_decrease`, `percentage_increase` in `ProductQuantityPriceRulesBundle/Resources/config/services/{calculator,actions}.yml`.
- **Fix:**
  - Use PHP 8.4 `round($value, 0, \RoundingMode::HalfAwayFromZero)` (or the mode the shop's money rounding uses elsewhere), so the rounding mode is explicit and matches the cart.
  - Share the percentage arithmetic in one small helper (`PercentageCalculator::apply(int $price, float $percent): int`). Today the two actions repeat the expression, and they treat the bounds differently (TODO-2 **B-04**).
  - Expose the built-in type ids as typed class constants (`public const string TYPE = 'percentage_decrease';`, PHP 8.3) or a backed enum. The YAML tags, the attributes and the tests can then refer to them instead of repeating string literals.

### I-10 — `MemoryCachedValidRuleFetcher` belongs in the bundle, or should not depend on `RequestStack`
- **Where:** `Rule/Fetcher/MemoryCachedValidRuleFetcher.php`.
- **Fix:**
  - It is the only class in the component that needs HttpFoundation, and it uses `RequestStack` only as an "am I in a web request" switch (`:37`). Move it to `ProductQuantityPriceRulesBundle` (it is already wired there), or make it independent of the request and implement `ResetInterface` (bundle **B-12**). The component then needs no Symfony runtime dependency (**I-01**).
  - Also fix the asymmetric behaviour: it writes the cache when there is no main request, but never reads it (bundle **B-12**). The `\WeakMap` keying is TODO-2 **B-03**.

### I-11 — Float comparison of quantities and range limits
- **Where:** `Calculator/VolumeCalculator.php:82`. **(dependent)** `Component/Core/ProductQuantityPriceRules/Calculator/UnitVolumeCalculator.php:115`.
- **Fix:** `rangeStartingFrom > $quantity` compares floats. A quantity built from arithmetic (unit conversion, `0.1 + 0.2`) can fall just below a limit such as `0.3` and pick the lower tier. Compare with a precision-aware helper (round both sides to the unit precision), or switch the column to `decimal` (bundle **I-08**) and compare as strings with `bccomp()`.

### I-12 — `Events` and the condition-checker marker interface
- **Where:** `Events.php`, `Rule/Condition/QuantityRuleConditionCheckerInterface.php`.
- **Fix:**
  - `Events::RULES_DATA_FROM_EDITMODE_VALIDATION`: make it a typed constant (`public const string`, PHP 8.3), and add a docblock that names the event class it carries (`ProductQuantityPriceRulesBundle\Event\ProductQuantityPriceRuleValidationEvent`). The docblock currently says "before quantity price rule data passes form process", but the event is dispatched *after* the form submit (`CoreExtension/ProductQuantityPriceRules.php:326`) **(dependent)**.
  - `QuantityRuleConditionCheckerInterface` is empty, and only the bundle's autoconfiguration uses it (`CoreShopProductQuantityPriceRulesExtension.php:67`). Add a docblock saying that it is a marker for autoconfiguration.

## Tests

### I-13 — The component has no tests
- **Where:** no `Tests/` or `spec/` folder in the component, and no PHPUnit or PHPSpec coverage anywhere in the repository for `CoreShop\Component\ProductQuantityPriceRules\*`. Only Behat covers it, end to end.
- **Fix:** add unit tests for:
  - `VolumeCalculator` (sorted, unsorted and mixed-unit ranges, quantity below the first range; TODO-1 **C-01**);
  - unknown behaviour types (TODO-1 **C-02**);
  - both percentage actions (bounds, rounding; TODO-2 **B-04**);
  - `QuantityRuleFetcher` sort and tie-break (TODO-2 **B-02**);
  - `QuantityReferenceDetector` fallback across rules (TODO-2 **B-01**);
  - `MemoryCachedValidRuleFetcher` (null id, request and no-request; TODO-2 **B-03**);
  - `addRange()` / `removeRange()` association consistency (TODO-2 **B-06**).

  The scratch reproduction used for this scan needed only the component, `coreshop/registry`, `coreshop/resource` and `coreshop/rule`, so these tests can run without a kernel.

---

## Already tracked elsewhere (not repeated)
- `Bundle/ProductQuantityPriceRulesBundle/TODO-3-IMPROVEMENTS.md` **I-04** (PHP 8.4 typing across the bundle; the component-model sub-bullet is detailed here in **I-02**), **I-08** (Doctrine mapping: `decimal` columns, `rule_id` not null / cascade, dead `$inherit`, action methods throwing `\Exception`), **I-11** (`createDataCopy()` reflection → `__clone()`), **I-16** (wire consumers to `QuantityReferenceDetectorInterface`).
- `Component/Core/TODO-3-IMPROVEMENTS.md` **I-23** (quantity-price `Amount*` / `Fixed` actions: check `base_currency`, share a base class), and the Core `ProductQuantityPriceRule` / `QuantityRange` model cleanups (section on model typing).
