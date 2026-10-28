# Shipping Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-28 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs: wrong results or avoidable errors in a limited area, or under a non-default configuration. None of them takes down the shop on its own.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. **(dependent)**, **(reproduced)** and **(plausible)** have the same meaning as in TODO-1.

Items already tracked in `ShippingBundle/TODO-*.md` are **not repeated here** (see the overlap list in TODO-1). The items below are new, or they add component-side detail that the bundle TODO does not have.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## PHP 8.4 behaviour change

### B-01 — PHP 8.4 `round()` change: the `additionPercent` / `discountPercent` actions are now off by one cent in some cases — (reproduced)
- **Where:** `Rule/Action/AdditionPercentActionProcessor.php:29` (`(int) round($price * ($configuration['percent'] / 100))`), `Rule/Action/DiscountPercentActionProcessor.php:29` (same formula, negated).
- **Problem:**
  - PHP 8.4 removed the internal "pre-rounding" of `round()`. Values that are mathematically `x.5` but are stored as `x.4999…` in floating point are now rounded **down**. `$percent / 100` is almost never exact in binary, so the product often lands just below `.5`.
  - Reproduced with the component's formula:

    | price (cents) | percent | PHP 8.3 | PHP 8.4 | exact (half up) |
    |---|---|---|---|---|
    | 100 | 14.5 | 15 | **14** | 15 |
    | 2500 | 8.7 | 218 | **217** | 218 |
    | 1000 | 2.05 | 21 | **20** | 21 |
    | 100 | 100.5 | 101 | **100** | 101 |

  - Brute force over price 1–5000 cents × percent 0.01–100.00 (1429 steps): PHP 8.3 has **0** mismatches against exact decimal half-up rounding, PHP 8.4 has **447**. Multiplying first (`$price * $percent / 100`) still gives 150 mismatches.
  - The effect is one cent per order, and it is silent. It does matter for: price consistency between orders created before and after the PHP upgrade, Behat or price expectations in project tests, and ERP or invoice reconciliation that recomputes the shipping fee.
- **Fix:**
  - Use integer math in basis points, which had **0** mismatches in the same brute force: `$bp = (int) round((float) $configuration['percent'] * 100); return intdiv($price * $bp + 5000, 10000);`. Negate the result for the discount. For negative prices, use symmetric rounding.
  - Alternative: `BcMath\Number` + `RoundingMode::HalfAwayFromZero` (both new in PHP 8.4). This needs `ext-bcmath`, which is **not** installed in the reference environment, so the integer version is preferred.
  - Put the formula in one shared helper and use it for both actions (TODO-3 **I-07**). Check `Component/Core` and the Payment component for the same `round($x * ($p / 100))` pattern.
  - Unit tests with the four rows above.

---

## Rule evaluation

### B-02 — Postcode ranges: equal bounds, reversed bounds, spaces around the dash and letters **after** the digits never match — (reproduced) (dependent)
- **Where:** `Rule/Condition/PostcodeConditionChecker.php:48-88` (`checkPostCode()`): `:54` (`strpos($postcode, '-') > 0`), `:61-62` (non-digit "text" part), `:68` (`if ($fromNumber < $toNumber)`), `:72` (`$fromText . $i`). These cases are **not** covered by `ShippingBundle/TODO-2-BUGS.md` **B-04** (whitespace inside the list, leading zeros, prefix overlap, range expansion). Fix them together.
- **Reproduced** (calling `checkPostCode()` through reflection):
  - `'1000-1000'` vs `1000` → **no match**. `$fromNumber < $toNumber` is false, so the literal string `"1000-1000"` is compared. A single-value "range" is common in generated lists.
  - `'1999-1000'` (reversed) vs `1500` → **no match**, with no error. The rule silently does nothing.
  - `'1000 - 2000'` vs `1500` → **no match**. The text part is `' '`, so the expanded values are `' 1000'` … `' 2000'`, while the delivery postcode is space-stripped (`:51`).
  - `'1000AB-1010AB'` vs `1005AB` (Dutch format) → **no match**. The letters are always put **before** the number (`$fromText . $i` → `AB1005`).
  - `'A1-A3'` vs `A10` → **match** (prefix overlap, already in bundle B-04).
- **Fix:** parse each entry into prefix, number and suffix with one regex (`/^(\D*)(\d+)(\D*)$/` after trimming each side of the dash). Require equal prefix and suffix, normalise the bounds with `min()`/`max()`, accept `from === to`, and compare numerically without expanding. Reject entries that cannot be parsed on save (bundle). Unit tests for every case above.

### B-03 — A carrier whose only shipping rule is **inactive** disappears from checkout; an inactive rule in a group with `stopPropagation` hides the carrier even when a later group matches — (verified code path)
- **Where:** `Checker/CarrierShippingRuleChecker.php:46-60`, `Validator/ShippingRuleCarrierValidator.php:35-39`. Root behaviour: `Component/Rule/Condition/RuleConditionsValidationProcessor.php:41-43` (`if (!$rule->getActive()) return false;`).
- **Problem:**
  - `ShippingRuleCarrierValidator` returns `true` only when a carrier has **no** rule groups, or when one of its groups' rules is valid. An inactive rule counts as "not valid". So a carrier with a single, deactivated rule has groups but no valid rule and is removed from the carrier list. Deactivating a "surcharge for islands" rule therefore removes the carrier instead of removing the surcharge.
  - When the group of an inactive rule has `stopPropagation = true`, the checker returns `null` at `:53-55`, so the following groups are not evaluated at all.
  - `features/domain/shipping/carrier_with_no_shipping_rules_valid.feature` covers "no rules → valid", but not "only inactive rules".
  - Same behaviour and same decision as `Component/Payment/TODO-2-BUGS.md` **B-01**. Decide once for both.
- **Fix:** skip groups whose rule is inactive (`continue`, **before** the `stopPropagation` check), and treat "all groups inactive" like "no groups" in `ShippingRuleCarrierValidator`. If the current behaviour is intended, document it in the admin and in `docs/` and add a Behat scenario for it.

### B-04 — The `shippingRule` **action** ignores the referenced rule's `active` flag and conditions, and applies its percent modifications to the outer rule's price — (verified code path)
- **Where:** `Rule/Action/ShippingRuleActionProcessor.php:43-49` (`getPrice()`), `:60-66` (`getModification()`). Compare with the **condition** of the same type, `Rule/Condition/ShippingRuleConditionChecker.php:39-45`, which does evaluate the referenced rule and so respects `active`.
- **Problem:**
  - Rule A has the action "shipping rule = B". A always adds B's price actions, even when B is deactivated or B's conditions (for example, "only for zone EU") do not match. An admin who deactivates B to stop a fee still sees the fee on every order that matches A.
  - `getModification()` passes A's **total base price** into B's modifications. Example: B = price 10 + 10 %, A = shippingRule(B) + price 5. The result is 15 + 1.5 (10 % of A's 15), not 10 + 1 + 5. This is not documented, and the result depends on what else A contains.
  - The condition and the action with the same type name behave differently. Same as `Component/Payment/TODO-2-BUGS.md` **B-02**.
- **Fix:** decide on the contract and document it in the admin label. Recommended: apply B only when `$rule->getActive()` and B's conditions are valid (inject `RuleValidationProcessorInterface`, as the condition checker does), and compute B's modifications on B's own price (call `CarrierShippingRulePriceCalculator`'s logic for B and return the full result from `getPrice()` only). Combine with the cycle guard from TODO-1 **C-03**.

### B-05 — Rule groups with the same priority are evaluated in database order, so the winning rule is not deterministic; groups that are added in memory are not sorted at all — (plausible) (dependent)
- **Where:** `Checker/CarrierShippingRuleChecker.php:40-60` (first valid group wins), `Model/Carrier.php:167-174` (`addShippingRule()` appends), `ShippingBundle/Resources/config/doctrine/model/Carrier.orm.xml:28-30` (`order-by priority ASC`, no second key).
- **Problem:**
  - Two groups with the same priority (the admin gives every new row `priority: 100`, `ShippingBundle/Resources/public/pimcore/js/carrier/item.js:255`) are returned in whatever order the database returns them. After an `OPTIMIZE TABLE`, a replica switch or a MySQL/MariaDB upgrade, the order can change, and with it the price of a carrier.
  - The `order-by` applies only when Doctrine loads the collection. Groups added with `addShippingRule()` during the same request (admin save + preview, fixtures, Behat setup, imports) stay in insertion order, so the result differs from the result after a reload.
- **Fix:** add `id ASC` as the second `order-by-field` (bundle mapping). In `CarrierShippingRuleChecker`, sort explicitly with `usort()` by `[priority, id]` instead of relying on the collection order (or use a `Criteria` with `orderBy`). Optionally reject duplicate priorities per carrier on save.

### B-06 — Rule configurations are read without defaults: a missing key raises a warning, `''` raises a `TypeError`, and a `null` postcode list takes down carrier resolution — (reproduced)
- **Where:** `Rule/Condition/AmountConditionChecker.php:29-30`, `WeightConditionChecker.php:29-30`, `DimensionConditionChecker.php:29-33`, `PostcodeConditionChecker.php:29,36,41`, `ShippingRuleConditionChecker.php:38`, `Rule/Action/{AdditionPercent,DiscountPercent}ActionProcessor.php:29`, `Rule/Action/ShippingRuleActionProcessor.php:43,60`.
- **Reproduced:**
  - `AmountConditionChecker` with `[]` → `Warning: Undefined array key "minAmount"` / `"maxAmount"`. In debug mode, Symfony's error handler turns this into an `ErrorException`, which is a 500. In production the warning is logged, and the code then computes with `null`.
  - `AdditionPercentActionProcessor` with `['percent' => '']` → `TypeError: Unsupported operand types: string / int`.
  - `PostcodeConditionChecker` with `['postcodes' => null]` → `TypeError: explode(): Argument #2 ($string) must be of type string, null given` (the file uses `strict_types`). The condition runs for every carrier during carrier resolution, so one such rule breaks the carrier list of every cart.
  - `DimensionConditionChecker` with a shippable whose `getItems()` is `null` (the interface allows it, `Model/ShippableInterface.php:26`) → `Warning: foreach() argument must be of type array|object, null given`, and then returns `true`.
- **Trigger:** configurations are JSON in the database. They can come from older versions, imports, the API, a manual SQL fix or a form that has no `NotBlank` (postcodes, bundle TODO-1 **C-01**).
- **Fix:** read every key with a default and an explicit cast (`(float) ($configuration['minAmount'] ?? 0)`, `(string) ($configuration['postcodes'] ?? '')`, `(bool) ($configuration['exclusion'] ?? false)`), or use `Assert::keyExists()` with a message that names the condition type and rule. Use `$shippable->getItems() ?? []`. See TODO-3 **I-08** for a small typed configuration reader.

### B-07 — `DimensionConditionChecker` also checks digital (non-shipped) items, and an item without dimensions always passes — (plausible) (dependent)
- **Where:** `Rule/Condition/DimensionConditionChecker.php:33-51`. Compare with `Component/Core/Order/Processor/CartShippingProcessor.php:77` (`hasShippableItems()`) and `Component/Core/Shipping/Taxation/TaxCalculationStrategyCartItems.php:82-86`, which both skip digital products.
- **Problem:**
  - A digital product that has dimensions (for example, a variant that inherits them from a physical parent) can make a "max. 60 cm" rule invalid, although it is never shipped.
  - `null` dimensions compare as `0`, so a product without data always passes a max-size rule. This is probably the right default, but it should be a documented decision.
- **Fix:** add `isShippable(): bool` (or similar) to `ShippableItemInterface` and skip items that are not shipped, in this checker and in custom checkers. Document the `null` behaviour. The weight condition has the same question (`CartShippingProcessor` adds the weight of digital items).

---

## Models

### B-08 — `Carrier::getTitle()` / `getDescription()` are reads that **create** an empty translation for a missing locale, and they throw when no locale is set — (reproduced) (dependent)
- **Where:** `Model/Carrier.php:97-110,189-195`. Root behaviour: `Component/Resource/Model/TranslatableTrait.php:58-93` (`createTranslation()` + `addTranslation()` when neither the locale nor the fallback exists, and `RuntimeException` when `$locale` and `currentLocale` are `null`).
- **Problem:**
  - Reproduced: carrier with current locale `de` and no translations → `getTitle('fr')` → `count(getTranslations()) === 1`. A read adds an empty `fr` translation to the collection. When the carrier is flushed later in the same unit of work (for example, an admin save, or an order process that touches the carrier), that empty row can be persisted **(plausible)**.
  - `getTitle()` without a locale on a carrier loaded outside a request (console command, Messenger worker, a carrier created with `new` in tests) throws `RuntimeException: No locale has been set and current locale is undefined.`. Carrier titles are used in order mails and PDFs, which often run in workers.
- **Fix:** for reads, use a non-mutating lookup: `$this->translations->get($locale) ?? $this->translations->get($this->fallbackLocale)`, and return `null` / `''` when neither exists. Fixing `TranslatableTrait` centrally helps every translatable model **(dependent: Resource component)**.

### B-09 — `CarrierTranslation::__toString()` returns the **description**, and throws a `TypeError` when the description is `NULL` — (reproduced)
- **Where:** `Model/CarrierTranslation.php:46-49` (`return $this->getDescription();`), `:44` (default `''`, but untyped). Mapping: `ShippingBundle/Resources/config/doctrine/model/CarrierTranslation.orm.xml:13` (`description` `nullable="true"`).
- **Problem:**
  - The database allows `NULL`, and the untyped setter accepts `null` (an empty admin textarea, imports). Reproduced: `setDescription(null); (string) $translation` → `TypeError: CarrierTranslation::__toString(): Return value must be of type string, null returned`.
  - A string cast of a translation (Twig `{{ translation }}`, a choice label, logging) is expected to show the **title**, not a long description.
- **Fix:** `return $this->title ?? '';`. Type the properties as `?string` and the getters as `?string` (TODO-3 **I-02**).

---

## Price calculation

### B-10 — `NoShippingPriceFoundException` is never thrown anywhere, so a carrier without rules or without a matching rule is priced **0** and is preferred as the default carrier — (verified code path) (dependent)
- **Where:** `Exception/NoShippingPriceFoundException.php` (no `throw` in the whole repository, `grep -rn "new NoShippingPriceFoundException"` → 0 hits), `Calculator/CarrierPriceCalculator.php:44` and `CompositePriceCalculator.php:40` (the `catch` blocks are dead code), `Calculator/CarrierShippingRulePriceCalculator.php:64` (`return 0;`), `Validator/ShippingRuleCarrierValidator.php:35-37` (a carrier with no groups is valid). **(dependent)** `Component/Core/Shipping/Resolver/CheapestDefaultCarrierResolver.php:52-57`.
- **Problem:**
  - "A carrier without rules is valid" is intended (`features/domain/shipping/carrier_with_no_shipping_rules_valid.feature`). But its price is then `0`, and `CheapestDefaultCarrierResolver` selects the cheapest carrier as the default. A newly created carrier that is already assigned to a store, but has no rules yet, therefore becomes the **default carrier with free shipping** for every cart.
  - The "last calculator wins" / "0 instead of no price" part is already in `ShippingBundle/TODO-2-BUGS.md` **B-03**. This item adds the missing exception path and the default-carrier effect.
- **Fix:** throw `NoShippingPriceFoundException` from `CarrierShippingRulePriceCalculator` when the carrier has groups but no matching rule. Decide explicitly (and document) whether a carrier without any rules is free or "price on request". If it is not free, let `CarrierPriceCalculator` throw when no calculator returned a price, and let `CheapestDefaultCarrierResolver` skip carriers without a price.

### B-11 — The base `CarriersResolver` ignores `hideFromCheckout` and returns carriers in an undefined order — (verified code path) (dependent)
- **Where:** `Resolver/CarriersResolver.php:40` (`$this->carrierRepository->findAll()`). The Core decorator `Component/Core/Shipping/Discover/StoreBasedShippableCarriersDiscovery.php:39-55` applies `hideFromCheckout` and the store, but only when the shippable is a Core `OrderInterface`; everything else falls through to the base resolver.
- **Problem:** in a setup without CoreBundle, or for any `ShippableInterface` that is not a Core order (a custom quote, a shipping estimate object, a project's "shippable product" for a price preview), carriers marked "hide from checkout" are offered, and the list order changes between requests.
- **Fix:** filter `getHideFromCheckout()` in the base resolver (or add a `findForCheckout()` repository method) and order by a stable key (`identifier` or `id`). Document that decorators must keep these guarantees.
