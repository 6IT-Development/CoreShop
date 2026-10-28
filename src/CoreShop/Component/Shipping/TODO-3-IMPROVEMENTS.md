# Shipping Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-28 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are improvements to code quality, readability, logic, performance, PHP 8.4 modernisation and forward compatibility (Symfony 8, Doctrine ORM 3 / DBAL 4). None of them is a functional bug on its own.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have their root cause or part of the fix outside this component.

**BC note:** the interfaces of this component are implemented in `Component/Core` (`Carrier`, `Order` as `ShippableInterface`, `OrderItem` as `ShippableItemInterface`, nine condition checkers, three actions, two tax strategies, `TaxedCarrierPriceRuleCalculator`, `CheapestDefaultCarrierResolver`, `StoreBasedShippableCarriersDiscovery`) and in projects. Every signature change below must be made together with those implementers and listed in `UPGRADE.md`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 / Symfony 7.4 compatibility and forward compatibility

### I-01 — `composer.json` does not describe what the component needs
- **Where:** `composer.json:26-57`.
- **Fix:**
  - Add `"php": "~8.4.0"` (the root `composer.json:88` requires `~8.4.0`; the component has no PHP constraint).
  - Add `"doctrine/collections": "^2.2"`. `Model/Carrier.php` and `Model/CarrierInterface.php` use `ArrayCollection` / `Collection` directly; today the package only comes in through `coreshop/resource`.
  - `"six-it/pimcore-source"` is only needed for `Pimcore\Model\Asset` in `Model/Carrier{,Interface}.php` (logo). Keep it until **I-10** is done, then remove it.
  - `"laminas/laminas-stdlib"` is only used by `Validator/CompositeShippableCarrierValidator.php`. Remove it together with **I-09**.
  - `require-dev`: align with the root (`phpstan/phpstan ^2.1` and the matching `phpstan-doctrine` 2.x). Remove `phpstan-webmozart-assert`, because the component does not use `Webmozart\Assert`.
  - Fix the indentation of `"repositories"` and `"extra"` (`:45-57`).

### I-02 — Add native types to the interfaces and models — (dependent: Core implementers)
- **Where / Fix:**
  - `Model/CarrierInterface.php`: `getIdentifier(): ?string`, `setIdentifier(?string): void`, `getTitle(?string $language = null): ?string`, `getDescription(?string $language = null): ?string`, `getTrackingUrl(): ?string`, `getHideFromCheckout(): bool`, `setHideFromCheckout(bool): void`, `getTaxCalculationStrategy(): ?string`, `getShippingRules(): Collection` (with `@return Collection<int, ShippingRuleGroupInterface>` instead of `Collection|ShippingRuleGroupInterface[]`), `hasShippingRules(): bool`, `hasShippingRule(...): bool`, `add/removeShippingRule(...): void`. Remove the wrong `@param null $language` docblocks (`:42,49,54`).
  - `Model/CarrierTranslationInterface.php`: `?string` getters and setters (TODO-2 **B-09**).
  - `Model/ShippingRuleGroupInterface.php`: `getCarrier(): ?CarrierInterface`, `getPriority(): int`, `setPriority(int): void`, `getStopPropagation(): bool`, `setStopPropagation(bool): void`, `getShippingRule(): ?ShippingRuleInterface`, `setShippingRule(?ShippingRuleInterface): void` (the `null` case is in `ShippingBundle/TODO-1-CRITICAL.md` **C-03**).
  - `Model/CarrierAwareInterface.php` / `CarrierAwareTrait.php`: `setCarrier(?CarrierInterface $carrier): void`, property `protected ?CarrierInterface $carrier = null;`.
  - `Model/ShippableInterface.php`: `setWeight(?float): void`. Consider `getItems(): array` (never `null`, TODO-2 **B-06**).
  - `Model/ShippableItemInterface.php`: `getTotal(bool $withTax = true): int`, `getWidth()/getHeight()/getDepth()/getWeight(): ?float`. Ask whether `setTotal()` belongs in a read-only "shippable" contract at all; nothing in the shipping code calls it.
  - Typed properties in `Carrier`, `CarrierTranslation`, `ShippingRuleGroup`, `ShippingRule` (`?int $id = null`, `?string $identifier = null`, `bool $hideFromCheckout = false`, `Collection $shippingRules`, …). Doctrine ORM 2.20 supports typed properties. **Do not** use PHP 8.4 property hooks or asymmetric visibility (`private(set)`) on Doctrine-mapped properties until the project is on an ORM version that supports them (ORM 3.4+ with native lazy objects).
  - `Exception/NoShippingPriceFoundException.php:24`: `string $calculatorClass`, `?\Throwable $previous` instead of `?\Exception`.

### I-03 — PHP 8.3 / 8.4 language features
- **Fix:**
  - Constructor-promoted services → `private readonly` (`CarrierPriceCalculator`, `CarriersResolver`, `ShippingRuleCarrierValidator`, `CarrierShippingRuleChecker`, `CarrierShippingRulePriceCalculator`, `Processor/ShippingRuleActionProcessor`, `Rule/Action/ShippingRuleActionProcessor`, `ShippingRuleConditionChecker`). Most are `protected` today without a subclass in the repository.
  - Make the classes `final` where nothing extends them (`AmountConditionChecker`, `WeightConditionChecker`, `DimensionConditionChecker`, `ShippingRuleConditionChecker`, both percent actions, `CompositeShippableCarrierValidator`, `ShippingRuleCarrierValidator`). Keep the models and `AbstractConditionChecker` open. Check projects first (BC).
  - `#[\Override]` (PHP 8.3) on the interface implementations, so signature drift is caught when the interfaces get native types (**I-02**).
  - PHP 8.4 `array_any()` / `array_all()` for the "any item exceeds", "all validators accept" and "first valid group" loops (`DimensionConditionChecker`, `CompositeShippableCarrierValidator`, `CarrierShippingRuleChecker`). Use `array_find()` where the first match is needed.
  - Fix the broken empty-constructor formatting `public function __construct(\n        ) {` in `Model/Carrier.php:75-76`, `Exception/UnresolvedDefaultCarrierException.php:23-24`, `Validator/CompositeShippableCarrierValidator.php:30-31` (also in `Component/Core/Model/Carrier.php:39-40`, **dependent**). Run PHP-CS-Fixer.
  - Remove the `@psalm-suppress MissingConstructor` annotations once the properties are typed with defaults.

---

## Code quality and readability

### I-04 — Exceptions
- **Where:** `Exception/NoShippingPriceFoundException.php`, `Exception/UnresolvedDefaultCarrierException.php`.
- **Fix:**
  - `NoShippingPriceFoundException` has an untyped first argument and is never thrown (TODO-2 **B-10**). Either use it (recommended) or remove it together with the dead `catch` blocks.
  - Give both a common marker interface (`ShippingExceptionInterface`) and extend `\RuntimeException` (they describe runtime states, not programming errors).
  - `UnresolvedDefaultCarrierException`: optional context (shippable id, number of candidate carriers) for the log. Today `CartShippingProcessor` catches it silently.
  - `AbstractConditionChecker.php:30,34`: both `InvalidArgumentException`s have the same message, and it says `$subject` must be "an array". Give each case its own message (`expected CarrierInterface, got X` / `missing params "shippable", "address"`), and also check the types of `$params['shippable']` / `$params['address']`. Today a wrong type ends as a `TypeError` in the subclass. (Partly in `ShippingBundle/TODO-3-IMPROVEMENTS.md` **I-13**.)

### I-05 — Naming and structure
- **Fix:**
  - Two classes are called `ShippingRuleActionProcessor`: `Processor/ShippingRuleActionProcessor.php` (loops over a rule's actions) and `Rule/Action/ShippingRuleActionProcessor.php` (the `shippingRule` action). The interface of the first one lives in a third place, `Rule/Processor/ShippingRuleActionProcessorInterface.php`. Rename the action to `ShippingRuleReferenceActionProcessor` (or `ReuseShippingRuleActionProcessor`), and move the processor and its interface into the same namespace. Keep `class_alias` BC shims for one minor version.
  - `CarrierInterface::getShippingRules()` / `addShippingRule()` / … work with **groups** (`ShippingRuleGroupInterface`). Add `getShippingRuleGroups()` etc. and deprecate the old names.
  - Parameter order is inconsistent: `TaxCalculationStrategyInterface::calculateShippingTax(ShippableInterface, CarrierInterface, …)` vs. `(CarrierInterface, ShippableInterface, …)` everywhere else. Align it in the next major.
  - `TaxedShippingCalculatorInterface::getPrice(..., bool $withTax = true, array $context = [])`: a boolean flag before the context. `CartShippingProcessor` calls it twice with `true`/`false`. A method that returns both values (`getPrices(): ShippingPrice` with `gross`/`net`) would halve the work and remove the flag.
  - `Rule/Action/CarrierActionProcessorInterface.php` is an empty marker interface used only as the registry type. Document that, or let the two real interfaces stand alone and type the registry with a union check.

### I-06 — Simplify `CarrierShippingRuleChecker` — (dependent)
- **Where:** `Checker/CarrierShippingRuleChecker.php:40-63`.
- **Fix:**
  - The `count($shippingRules) === 0` early return (`:42-44`) is redundant, because the loop returns `null` anyway.
  - `$rule instanceof ShippingRuleInterface ? $rule : $rule->getShippingRule()` (`:47,58`) is evaluated twice and handles a case (a plain rule in the groups collection) that the types do not allow. Remove the branch and work with `ShippingRuleGroupInterface` only.
  - Pass `'carrier' => $carrier` instead of a positional `$carrier` (`ShippingBundle/TODO-2-BUGS.md` **B-11**). Skip groups without a rule (`ShippingBundle/TODO-1-CRITICAL.md` **C-03**) and with an inactive rule (TODO-2 **B-03**). Sort explicitly (TODO-2 **B-05**).
  - Document `stopPropagation` in the interface: today it means "if this group's rule is **not** valid, stop and make the carrier unavailable". A matching group always stops anyway ("first valid wins"). The name suggests the opposite, so a short docblock avoids misconfiguration.

### I-07 — Price calculation: one pass, one formula, no dead code
- **Where:** `Calculator/*`, `Processor/ShippingRuleActionProcessor.php`, `Rule/Action/{AdditionPercent,DiscountPercent}ActionProcessor.php`.
- **Fix:**
  - `Processor/ShippingRuleActionProcessor` loops over the actions twice and resolves each processor from the registry twice (`:46-58`, `:73-86`). Resolve once into a list of `[processor, configuration]` and reuse it for both phases.
  - Document the modification model in the interface: every modification is computed from the **base price** and they are summed (not compounded). `+10 %` and `−10 %` therefore give the base price, not 99 %.
  - The two percent actions are identical except for the sign. Use one `AbstractPercentActionProcessor` with the shared rounding helper from TODO-2 **B-01**, and clamp the sum (TODO-1 **C-02**).
  - `CompositePriceCalculator` is defined in `ShippingBundle/Resources/config/services.yml:43-45` with a single inner calculator and is never referenced anywhere else. It duplicates the loop of `CarrierPriceCalculator`. Deprecate and remove it.
  - `CarrierPriceCalculator.php:38-40`: the `@var CarrierPriceCalculatorInterface $calculator` docblock is placed before the `foreach` and has no effect. Use `@return array<string, CarrierPriceCalculatorInterface>` on the registry instead.
  - The calculator semantics ("highest priority wins, first non-throwing result returns") are in `ShippingBundle/TODO-2-BUGS.md` **B-03**.

### I-08 — Condition checkers: readability and a typed configuration reader
- **Where:** `Rule/Condition/*`.
- **Fix:**
  - Replace nested `if ($x > 0) { if (...) { return false; } }` blocks with single conditions (`Amount`, `Weight`, `Dimension`), for example `if ($maxWeight > 0 && $totalWeight > $maxWeight) { return false; }`.
  - `PostcodeConditionChecker`: `$deliveryAddress = $address;` (`:31`) is a useless alias. `return $configuration['exclusion'] ? false : true;` → `return !$configuration['exclusion'];`. Rewrite `checkPostCode()` as described in TODO-2 **B-02** and `ShippingBundle/TODO-2-BUGS.md` **B-04**.
  - Add a small value object or helper (`ConditionConfiguration::float('minAmount', 0.0)`, `::string('postcodes', '')`, `::bool('exclusion', false)`) and use it in every checker and action. This fixes TODO-2 **B-06** in one place.
  - `DimensionConditionChecker`: checks each axis separately (`height ≤ H`, `width ≤ W`, `depth ≤ D`), so a 60 × 10 cm parcel fails a "40 × 70" rule even though it fits when rotated. Offer an optional "allow rotation" mode (sort both triples and compare). Also offer a "sum of dimensions" / girth limit, which many carriers use.
  - Document the units: amounts in the store's base currency **minor units** (the admin JS multiplies by `decimal_factor`), weight in the product's weight unit, and whether `useTotal` includes cart-rule discounts.

### I-09 — Replace `Laminas\Stdlib\PriorityQueue` in `CompositeShippableCarrierValidator`
- **Where:** `Validator/CompositeShippableCarrierValidator.php`, `ShippingBundle/DependencyInjection/Compiler/CompositeShippableValidatorPass.php` **(dependent)**.
- **Fix:**
  - The class pulls in `laminas/laminas-stdlib` for one priority queue. Verified: iteration is non-destructive (it clones), equal priorities keep insertion order, and it works with 3.21.0 on PHP 8.4. So this is not a bug, only an extra dependency.
  - Symfony 7.4 option: inject `iterable $validators` with `#[AutowireIterator('coreshop.shipping.carrier.validator')]` (priority from the tag), and drop `addValidator()`, the compiler pass and Laminas. To stay framework-free, keep a constructor `iterable` argument and do the sorting in the bundle.
  - Make the class `final`, and use `array_all()` / an early `return false` in a `foreach`.

### I-10 — Models
- **Fix:**
  - `Model/Carrier{,Interface}.php` depends on `Pimcore\Model\Asset` for the logo. This is the only Pimcore dependency of the component. Move the logo to `Component/Core/Model/Carrier` (Core already depends on Pimcore), or type it as `?object` / an `AssetReferenceInterface` in the component. Then remove `six-it/pimcore-source` (**I-01**).
  - `Model/ShippingRuleGroup.php:30` uses `SetValuesTrait`, but nothing in the repository calls `setValues()` / `setValue()` on a rule group. Remove it. **(dependent)** `Component/Resource/Model/SetValuesTrait.php` `setValue()` also has a bug in its "compatibility" branch: it calls `$this->$method` with the **original** `o_` method name, which is exactly the one that does not exist.
  - `Carrier` uses `private` for its own fields but `protected` for `$shippingRules`; `CarrierTranslation` and `ShippingRule` use `protected $id`. Choose one style (private + typed accessors).
  - `Carrier::removeShippingRule()` sets the back-reference to `null`, and orphan removal then deletes the row. Document that removing a group deletes it (it is not detached), so that callers do not try to re-attach it.
  - **(dependent)** `ShippingBundle/Resources/config/doctrine/model/*.orm.xml`: `datetime` → `datetime_immutable` and `AUTO` → `IDENTITY` are tracked in `ShippingBundle/TODO-3-IMPROVEMENTS.md` **I-10**. If the model properties are typed as `\DateTimeInterface`, either mapping works.

---

## Logic / maintainability

### I-11 — `CheapestDefaultCarrierResolver` computes prices inside the sort comparator — (dependent: Core)
- **Where:** `Component/Core/Shipping/Resolver/CheapestDefaultCarrierResolver.php:52-57` (implements this component's `DefaultCarrierResolverInterface`).
- **Fix:**
  - The comparator calls `resolveCartContext()` and `getPrice()` twice **per comparison**: O(n log n) full rule evaluations instead of n.
  - It never returns `0` for equal prices, which breaks the comparator contract, so equal-price carriers are ordered randomly.
  - Fix: compute the context once, compute each carrier's price once into a map, then sort by `[price, priority/id]` with `<=>`. Skip carriers without a price (TODO-2 **B-10**). Also combine with the memoisation in `ShippingBundle/TODO-3-IMPROVEMENTS.md` **I-12**.
  - The `instanceof OrderInterface` check (`:44`) runs **after** `resolveCarriers()`, which therefore does unnecessary work. Move it to the top.

### I-12 — Documentation (`README.md` / `docs/`)
- **Fix:** the component README has no content about behaviour. Document:
  - "the first valid group (by priority) wins", and the meaning of `stopPropagation` (**I-06**);
  - that a carrier without groups is always available, and what it costs (TODO-2 **B-10**);
  - the modification model, rounding and the "never below 0" rule (TODO-1 **C-02**, TODO-2 **B-01**, **I-07**);
  - the postcode syntax (lists, ranges, prefixes, exclusion) (TODO-2 **B-02**);
  - the `$params` keys that condition checkers receive (`carrier`, `shippable`, `address`) and the `$context` keys that actions receive (`base_currency`, `store`, …, from `CartContextResolver`);
  - how to register custom conditions, actions, calculators, validators and tax strategies (tags, attributes, priorities).

### I-13 — Tests
- The component has no unit tests (no `Tests/` directory). Behat covers the main paths (`features/domain/shipping/**`), but not the edge cases found in this scan.
- **Add PHPUnit tests (framework-free, no container) for:**
  - `Carrier` translated setters with two locales (TODO-1 **C-01**), and reads that must not create translations (TODO-2 **B-08**).
  - `CarrierShippingRulePriceCalculator`: clamp at 0 (TODO-1 **C-02**), no rule → exception (TODO-2 **B-10**).
  - `ShippingRuleConditionChecker` / `Rule/Action/ShippingRuleActionProcessor`: self-reference and cycle (TODO-1 **C-03**), inactive referenced rule (TODO-2 **B-04**).
  - `Processor/ShippingRuleActionProcessor`: unknown action type (TODO-1 **C-04**).
  - Percent actions: the rounding table from TODO-2 **B-01**, run on PHP 8.4.
  - `PostcodeConditionChecker`: every case in TODO-2 **B-02** and `ShippingBundle/TODO-*` **C-01** / **B-04**.
  - `Amount` / `Weight` / `Dimension` checkers: missing keys, `null` items, limits of `0`.
  - `CarrierShippingRuleChecker`: priority ties, inactive rules, `stopPropagation` (TODO-2 **B-03**, **B-05**).
- Add a Behat scenario for "carrier whose only rule is inactive" once TODO-2 **B-03** is decided.
