# Taxation Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items. None of them is a functional bug on its own.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Prefixes and markers as in TODO-1; TaxationBundle items are referenced as TaxationBundle **C-/B-/I-nn**.

**Already fine for Symfony 7.4 / PHP 8.4:** every file has `declare(strict_types=1)`, no implicitly nullable parameters, `TaxCollector` already uses constructor property promotion, the models implement `\Stringable` explicitly, Doctrine collections are initialised in the constructors.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Calculator

### CI-01 — One exact arithmetic and one rounding rule for all calculator methods (PHP 8.4: `RoundingMode`, `BcMath\Number`)
- **Where:** `Calculator/TaxRulesTaxCalculator.php:40-110`.
- **Problem:** six public methods implement four different formulas (total factor, per-rate net, per-rate gross, compound loops), all on `float`, each with its own rounding. TODO-1 **CC-01**, **CC-02**, **CC-03** and TODO-2 **CB-01**, **CB-02**, **CB-04** are all consequences of that.
- **Fix:**
  - Implement one private `breakdownFromNet(int $net): array<int, int>` (per-rate amounts, compound-aware) and one `breakdownFromGross(int $gross): array<int, int>` (= `breakdownFromNet(net)` + remainder on the last rate); derive `applyTaxes`, `removeTaxes`, `getTaxesAmount*`, `getTaxesAmountFromGross*` from them.
  - Exact arithmetic, options:
    - rate as integer basis points (`8.875 %` → `88750` with 4 decimals) and `intdiv()`-based half-up rounding — no extension needed;
    - PHP 8.4 `BcMath\Number` (immutable, operator overloading, `round(…, RoundingMode::HalfAwayFromZero)`); note that `ext-bcmath` is **not** loaded in the PHP 8.4.27 CLI of the test project — add it to `composer.json` `require` if chosen.
  - Use the PHP 8.4 `RoundingMode` enum explicitly (`round($x, 0, RoundingMode::HalfAwayFromZero)`) instead of the implicit default, so the rounding rule is visible in code and identical for negative (discount) amounts.
  - Goes together with storing `rate` as decimal (TaxationBundle **I-04**, **B-05**).

### CI-02 — Immutable calculator: readonly / asymmetric visibility, typed constructor, total rate computed once (TaxationBundle **I-07**)
- **Where:** `Calculator/TaxRulesTaxCalculator.php:25-38`, `:50-68`.
- **Problem:** `public array $taxRates` and `public int $computationMethod` can be changed after construction, although calculators are cached and shared (`Component/Core/Taxation/CachedTaxCalculatorFactory.php`). The constructor parameter `$computationMethod` is untyped and cast with `(int)` (`'2'` is silently accepted, reproduced). `getTotalRate()` re-loops all rates on every `applyTaxes()` / `removeTaxes()` call and starts from `int 0`.
- **Fix:**
  - `public function __construct(array $taxRates = [], int $computationMethod = self::COMBINE_METHOD)`; keep public read access for BC with PHP 8.4 asymmetric visibility: `public private(set) array $taxRates;` / `public private(set) int $computationMethod;` (or `private readonly` + getters in the next major).
  - Validate the input once (every element `TaxRateInterface` with an id, known method; TODO-2 **CB-03**) with `Webmozart\Assert`.
  - Compute the total rate once in the constructor (starting from `0.0`, not `int 0`) and store it in a private field; `getTotalRate()` just returns it.
  - Mark the class `final` or document it as an extension point (no subclass exists in the code base).

### CI-03 — Behaviour constants: backed enum and correct semantics of `DISABLE_METHOD` (TaxationBundle **I-05**)
- **Where:** `Calculator/TaxCalculatorInterface.php:25-40`; `Calculator/TaxRulesTaxCalculator.php:53,80,101` (only `ONE_AFTER_ANOTHER_METHOD` is checked); **(dependent)** `Component/Core/Taxation/TaxCalculatorFactory.php:46-64`.
- **Problem:** `DISABLE_METHOD` ("only use this tax") is a rule-collection behaviour of the factory, not a computation method; a calculator built with `DISABLE_METHOD` and several rates silently combines them (reproduced: 10 % + 5 % with `DISABLE_METHOD` → total 15 %). Only the **first** rule's behaviour decides the method for the whole calculator; that is not documented.
- **Fix:** `enum TaxRuleBehavior: int { case ThisTaxOnly = 0; case Combine = 1; case OneAfterAnother = 2; }` in `Model/`; a separate `enum TaxComputationMethod { case Combine; case OneAfterAnother; }` for the calculator; keep the interface constants as `#[\Deprecated]`-documented aliases (PHP 8.4 `#[\Deprecated]` works on class constants) until the next major. Document the factory semantics in the interface.

### CI-04 — `TaxCalculatorInterface` documentation: copy-pasted docblocks, no array shapes
- **Where:** `Calculator/TaxCalculatorInterface.php:42-86`.
- **Fix:**
  - Four methods share the text "Return the tax amount associated to each taxes of the TaxCalculator", with stray `@return int $taxes_amount` / `@return int[] $taxes_amount` tags and empty doc lines (`:54-55`, `:62-63`). Describe each precisely: input is net or gross (in minor units), output is total or per rate.
  - `@return array<int, int>` (key = tax-rate id, value = amount in minor units) for both `*AsArray()` methods, `@return list<TaxRateInterface>` / `array<int, TaxRateInterface>` for `getTaxRates()`.
  - Document the rounding contract (CI-01) and the invariants: `applyTaxes(p) − p === getTaxesAmount(p)`, `G − removeTaxes(G) === getTaxesAmountFromGross(G)`.
  - `getTaxesAmountFromGross()` (`TaxRulesTaxCalculator.php:72`) returns `array_sum()` without the `(int)` cast that `getTaxesAmount()` (`:93`) has; make both identical.
  - `:80-85` and `:101-106`: both branches compute the same expression; after CI-01 the duplication disappears.

---

## Collector

### CI-05 — `TaxCollector`: no repository round trips, readonly dependencies, no in-place mutation (TaxationBundle **I-09**)
- **Where:** `Collector/TaxCollector.php:29-95`, `Collector/TaxCollectorInterface.php`.
- **Fix:**
  - `:71` calls `find()` **before** the `$amount === 0` early return (`:73`); swap them. Better: take the rates from `$taxCalculator->getTaxRates()` (already loaded entities, keyed by id) instead of one `find()` per rate and call; `mergeTaxes()` uses `$item->getTaxRate()` (TODO-2 **CB-05**).
  - Constructor: `private readonly TaxRateRepositoryInterface $taxRateRepository` (or drop it once unused), `private readonly FactoryInterface $taxItemFactory`; mark the class `final`.
  - Replace the `@var` docblocks (`:68-70`, `:82-84`) with `Assert::isInstanceOf()` / `instanceof`.
  - `addTaxToArray(…, array &$usedTaxes)` mutates both the array and the `TaxItem` objects it contains (`:93` `setAmount()` on an item that belongs to the caller's field collection). Return a new array and clone items that are changed, or document clearly that the passed items are modified in place.
  - `mergeTaxes(array $taxes1, array $taxes2)`: rename to `$source` / `$target`; add `@param array<int, TaxItemInterface>`.
  - The three public methods duplicate the same loop; `collectTaxes*()` can delegate to one private `collect(array $amounts, array $usedTaxes)`.

---

## Models and repositories

### CI-06 — Native types on all model interfaces and classes (TaxationBundle **I-04**)
- **Where:** `Model/*.php`.
- **Fix (one BC-breaking step, interfaces and implementations together; note in `UPGRADE`):**
  - `TaxRateInterface`: `getName(?string $language = null): ?string`, `setName(string $name, ?string $language = null): void`, `getRate(): float`, `setRate(float $rate): void` (`:30-45`, docblock-only today).
  - `TaxRate`: `protected ?int $id = null;`, `protected float $rate = 0.0;` (`:47` default is `int 0`, documented as float).
  - `TaxRateTranslationInterface` / `TaxRateTranslation`: `getName(): ?string`, `setName(?string $name): void`, typed properties (`$id` is documented as `mixed`, `:31-34`).
  - `TaxRuleGroupInterface` / `TaxRuleGroup`: `getName(): ?string`, `setName(?string): void`, `getTaxRules(): Collection` with `@return Collection<int, TaxRuleInterface>` (instead of `Collection|TaxRuleInterface[]`), `hasTaxRules(): bool`, `addTaxRule(): void`, `removeTaxRule(): void`, `hasTaxRule(): bool`; typed properties.
  - `TaxRuleInterface` / `TaxRule`: `getBehavior(): ?int` (or the enum, CI-03), `setBehavior(…): static`; nullable getters (TODO-2 **CB-08**). `TaxRule` setters `return $this` without declaring it while every other setter returns nothing — pick `: static` or `: void` consistently.
  - `TaxItemInterface`: setters have no return type (`:29,33,37,41`); add `: void` / `: static` matching the generated Pimcore field-collection class (`CoreShopTaxItem`), and `setTaxRate(?TaxRateInterface $taxRate)` to allow clearing.
  - Constructors are formatted as `public function __construct(\n        ) {` (`TaxRate.php:49-52`, `TaxRuleGroup.php:50-53`); use `public function __construct()`.

### CI-07 — Model readability and small logic cleanups
- **Where:** `Model/TaxRate.php`, `Model/TaxRuleGroup.php`, `Model/TaxRule.php`, `Model/TaxItem.php`.
- **Fix:**
  - `TaxRate::getTranslation()` (`:84-90`) only narrows the return type via a `@var` assignment; with PHP 8.4 a covariant return type plus `\assert($translation instanceof TaxRateTranslationInterface)` is enough. Same for the `@psalm-suppress MissingConstructor` on `TaxRuleGroup`, `TaxRule`, `TaxRateTranslation`: remove it after the properties get defaults (CI-06).
  - `__toString()` implementations: `TaxRate` uses `'en'` (TODO-2 **CB-07**), `TaxRule` (`:51-56`) calls `getTaxRate()` twice; use `$this->taxRate?->getName() ?? 'none'`.
  - `TaxRuleGroup::addTaxRule()` / `removeTaxRule()` (`:85-100`) keep both sides in sync — good — but `TaxRule::setTaxRuleGroup()` does not call back; document which side owns the relation (Doctrine owning side is `TaxRule.taxRuleGroup`).
  - `TaxItem::getId()` (`Model/TaxItem.php:25-28`) always returns `null`; `TaxItemInterface` only extends `ResourceInterface` for that. Add a docblock explaining that tax items are Pimcore field-collection entries without an id (or drop `ResourceInterface` in the next major).

### CI-08 — Repository interfaces: typed parameters, generics, redundant method (TaxationBundle **I-12**)
- **Where:** `Repository/TaxRateRepositoryInterface.php:26-32`, `Repository/TaxRuleRepositoryInterface.php:27-30`.
- **Fix:** `findByName(string $name, string $locale): array` (docblock-only types today); `@return list<TaxRateInterface>` / `list<TaxRuleInterface>`; `@extends RepositoryInterface<TaxRateInterface>` if the Resource component adds templates. `findByGroup()` duplicates `$group->getTaxRules()`; deprecate it or document why a query is needed (ordering, `active` filter — Core TODO-2 **B-11**).

---

## Packaging, docs and tests

### CI-09 — `composer.json` does not describe what the code needs (TaxationBundle **I-01**)
- **Where:** `composer.json:26-34`, `:42-57`.
- **Fix:**
  - Add `"php": "^8.4"`.
  - Add `doctrine/collections` (`Model/TaxRuleGroup.php:24-25`) and `webmozart/assert` once CI-02 / CI-05 use it; `ext-bcmath` only if CI-01 chooses `BcMath\Number`.
  - `six-it/pimcore-source` is needed only indirectly (`TaxItem` → `AbstractPimcoreFieldcollection` from `coreshop/resource`); keep it, but it is already provided through `coreshop/resource`.
  - `require-dev` pins PHPStan 1.x (`^1.10`), which is EOL and does not understand PHP 8.4 syntax (property hooks, asymmetric visibility); bump to PHPStan 2 with the rest of the monorepo.
  - `minimum-stability: dev` and the `path` repository have no effect for consumers of a library; keep them in the monorepo root only. Fix the indentation of `"extra"` (`:48`, 3 spaces).

### CI-10 — README is boilerplate only
- **Where:** `README.md`.
- **Fix:** document what the component is for and its contracts: calculator net/gross semantics, rounding rule (CI-01), per-rate array keyed by tax-rate id, behaviour semantics (CI-03), and that `TaxItem` is a Pimcore field collection. Use `https://` for the coreshop.org links and fix "It is build from" → "It is built from".

### CI-11 — No unit tests for calculator and collector (TaxationBundle **I-08**)
- **Where:** the component has no `Tests/` directory; the cases below were reproduced manually for this scan.
- **Fix:** add PHPUnit tests (pure PHP, no kernel needed):
  - Calculator: net / gross × combine / one-after-another × 0, 1, 2, 3 rates; the reproduced cases from TODO-1 **CC-01** (10 % + 15 % gross 125.00), **CC-02** (126.50), **CC-03** (25.5 % on 1.00, 13 % on 0.50, 17 % on 8.50, 5.5 % on 19.00), TODO-2 **CB-01** (10 % + 15 % on 0.02), **CB-02** (20 % on gross 12.03), **CB-03** (duplicate rate), **CB-04** (negative rate); negative input amounts (discounts); property-based invariants from CI-04 over a price range.
  - Collector: merge by rate (not by key, TODO-2 **CB-05**), position-keyed input, zero amounts / 0 % rate (**CB-06**), unknown rate id.
