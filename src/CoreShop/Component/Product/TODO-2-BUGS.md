# Product Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope, path conventions and markers: see [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md) (first scan: `Calculator/`, `Exception/`, `Repository/`, `Rule/` and dependent config, **CB-01**–**CB-12**; second scan: `Model/`, **CB-13**+). Component items use `CB-` numbers. **(latent)** = the defect is real, but the stock configuration does not reach it today.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Calculators

### CB-01 — `MemoryCachedProductPriceCalculator` returns wrong prices and crashes: colliding cache keys, `getDiscount()` ignores the price, `getCustomAttributes()` checks the wrong array — (reproduced) (latent: not wired)
- **Where:** `Calculator/MemoryCachedProductPriceCalculator.php`. The class is not registered anywhere in this repository (no service definition, no decorator), but it is a public `final` class meant to be wired by projects as a decorator of `ProductPriceCalculatorInterface`.
- **Problems (all reproduced with a stub inner calculator and a request on the stack):**
  1. `:48` key `sprintf('%s%s', $product->getId(), (string) $withDiscount)` → `(string) true === '1'`, `(string) false === ''`. Product 12 *with* discount and product 121 *without* discount share key `"121"`: the second call returned 1201 instead of 12100.
  2. `:89-93` `getDiscount()` caches by product id only and ignores `$price`: `getDiscount(…, 1000) = 100`, then `getDiscount(…, 5000) = 100` (inner: 500). Callers pass different base prices (retail vs. discount price, per unit definition).
  3. `:102-106` `getCustomAttributes()` checks `isset($this->cachedDiscount[…])` but writes and reads `$cachedIsDiscountable`. After a `getDiscount()` call for the same product it returns an unset key → `Warning: Undefined array key 12` and `TypeError: … getCustomAttributes(): Return value must be of type array, null returned`. Without a prior `getDiscount()` it never caches at all.
  4. Every method ignores `$context` (store, currency, customer, unit definition): `getRetailPrice()` for store A, then store B → both 1000 (inner B: 2000). Same defect as TODO-1 **CC-01**.
  5. Not `ResetInterface`; never cleared (worker processes, see TODO-1 **CC-01**). `getCurrentRequest()` here vs. `getMainRequest()` in `MemoryCachedValidRuleFetcher`.
  6. Default `$withDiscount = false` (`:42`) while the interface says `true` (see **CB-02**).
- **Fix:** either delete the class (it is unused, see TODO-3 **CI-03**) or rewrite it: key by `\WeakMap` per product + context fingerprint (+ `$price` for `getDiscount()`), one private `remember()` helper instead of five copies, `ResetInterface`. Wiring it with autoconfiguration also triggers ProductBundle TODO-1 **C-03** (recursion).

### CB-02 — `ProductPriceCalculatorInterface::getPrice()` defaults `$withDiscount` to `true`, both implementations default to `false` — (reproduced)
- **Where:** `Calculator/ProductPriceCalculatorInterface.php:29` (`bool $withDiscount = true`) vs. `Calculator/ProductPriceCalculator.php:35` and `Calculator/MemoryCachedProductPriceCalculator.php:42` (`= false`). Reflection confirms `true` / `false` / `false`.
- **Problem:** PHP lets an implementation change a default value, and the implementation's default wins at runtime. Code written against the interface (`$calc->getPrice($product, $context)`) expects the discounted price and gets the undiscounted one; static analysis and IDEs show the interface default. The Order counterpart (`PurchasablePriceCalculatorInterface`, `$includingDiscounts = false`) uses `false`.
- **Fix:** make the interface say `false` (matches runtime behaviour and Order), or remove the default from both and make all callers explicit. Note it as a BC change in the upgrade notes.

### CB-03 — Custom attributes are merged with `+=` (array union by index): attributes of every action and every calculator after the first are dropped — (reproduced) (Order TODO-2 **B-05** is the same pattern one layer up)
- **Where:** `Rule/Calculator/ProductPriceRuleCalculator.php:182` and `Calculator/CompositeProductCustomAttributesCalculator.php:45` (`$customAttributes += $attributes;`). Processors return lists (`Rule/Action/NotDiscountableCustomAttributeActionProcessor.php:27-29` returns `[0 => ProductAttribute]`).
- **Reproduced:** rule A with `notDiscountableCustomAttributes`, rule B with a project action that returns `[new ProductAttribute('gift_wrap', true)]` → result `not_discountable` only. Same with two calculators in the composite.
- **Impact:** in the reverse order (project action first), `not_discountable` is dropped, and `Component/Core/Cart/Rule/Applier/CartRuleApplier.php:249` then applies cart-rule / voucher discounts to an item that a price rule marked as not discountable. Latent in a stock install (one attribute action type), real as soon as a project adds one.
- **Fix:** `array_push($customAttributes, ...$attributes)` (or `[...$a, ...$b]`), or key the result by `getAttributeKey()` with a documented override order (higher priority wins). Apply the same change in `Component/Order/Calculator/CompositePurchasableCustomAttributesCalculator.php:45` **(dependent)**.

### CB-04 — "No price found" silently becomes `0`; `NoPriceFoundException` is never thrown; a discount price is ignored when there is no retail price, and a discount price of `0` is always ignored — (verified) (dependent: Core TODO-2 **B-05**, Order TODO-2 **B-04**)
- **Where:** `Calculator/ProductPriceCalculator.php:53-71` (both getters `catch` and `return 0`), `:40-44` (`if ($discountPrice > 0 && $discountPrice < $price)`); `Exception/NoPriceFoundException.php` (no `throw` anywhere in the repository); `Component/Core/Order/Calculator/PurchasableProductPriceCalculator.php` catches `NoPriceFoundException` — dead `catch`.
- **Problems:**
  - A product without store values for the current store and without a `price` rule gets price 0. The Purchasable layer cannot tell "free" from "unpriced" because the exception it waits for is never raised here; Core then adds and sells the item at 0 (Core **B-05**).
  - Retail price missing (0) + a valid `discountPrice` rule → `$discountPrice < 0` is false → price stays 0, the discount price is ignored.
  - A deliberate discount price of 0 ("free sample" rule) is ignored because of `> 0`, while a price of 0 from a `price` action is accepted — inconsistent.
- **Fix:** make `getPrice()` throw `NoPriceFoundException` when neither a retail nor a discount price was found (keep `getRetailPrice()` / `getDiscountPrice()` throwing their own exceptions instead of returning 0, or add nullable `find*()` variants). Use "has a discount price" (`null` check) instead of `> 0`. Then let Core reject unpriced items (Core **B-05**). Changing the return-0 behaviour is a BC break: document it.

---

## Rule evaluation

### CB-05 — Rule precedence is inconsistent: for `price` / `discountPrice` the **last** processed rule (lowest priority) wins, while `stopPropagation` lets the **first** one win; `stopPropagation` on a product-specific rule does not stop global rules; rules with the same priority run in undefined order — (verified)
- **Where:** `Rule/Calculator/ProductPriceRuleCalculator.php:58-81` and `:99-121` (`$price = $actionPrice;` overwrites on every match, `break` only on `getStopPropagation()`); rule order from `Bundle/ProductBundle/Doctrine/ORM/PriceRuleRepository.php:28-31` and `ProductSpecificPriceRuleRepository.php:28-32` (`ORDER BY priority ASC`, no tie-breaker) **(dependent)**; `Calculator/CompositeRetailPriceCalculator.php:39-45` / `CompositeDiscountPriceCalculator.php:39-45` (registry iterates ascending priority, last success wins: specific rules 30 > global rules 20 > unit price 11 > store price 10).
- **Problems:**
  - `features/domain/product/price_rules/stop_propagation.feature` defines lower `priority` = processed first = more important (a rule with stop propagation at priority 5 beats priority 10). For `price` and `discountPrice` actions without stop propagation the opposite happens: rule priority 0 sets 9.00, rule priority 10 sets 12.00 → 12.00 wins. Admins who sort rules by priority get the less important rule's price.
  - Two `discountPrice` rules: the later one wins even if it is higher than the earlier one; the composite only compares with the retail price, not between rules.
  - `stopPropagation` only breaks the loop inside one `ProductPriceRuleCalculator` instance. A product-specific rule with "stop propagation" still lets every matching **global** price rule add its discount (`CompositeDiscountCalculator` sums both calculators), which is what admins usually want to prevent with that flag on a specific rule.
  - Equal priorities (the default is 0 for every new rule, `Model/AbstractPriceRule.php:48`) → order is whatever the database returns; which price wins and which rule's stop propagation applies can change after an unrelated update.
- **Fix:** define one rule: "first valid rule (by priority, then id) that yields a price wins" — iterate and `return` on the first `price` / `discountPrice` hit, or keep the minimum for `discountPrice`. Add `->addOrderBy('o.id', 'ASC')` in both repositories **(dependent)**. Decide whether a specific rule's stop propagation suppresses global rules (pass a flag through the context, or let `CompositeDiscountCalculator` stop after a calculator reports "stop"). Add Behat scenarios for each rule.

### CB-06 — `MemoryCachedValidRuleFetcher`: all unsaved products share one cache slot, the cache is written (never read) outside requests and grows without limit, and it is not dropped when a product's rules change — (reproduced)
- **Where:** `Rule/Fetcher/MemoryCachedValidRuleFetcher.php:37-47`.
- **Problems:**
  - Key `$product->getId()`: every product with id `null` (new object, variant being generated, temporary object for a price preview) uses key `""`. Reproduced: two different unsaved products returned the same cached rule list.
  - `:45` writes the cache unconditionally, `:37` reads it only with a main request. In CLI / Messenger (index rebuild, feed export, `coreshop:*` commands over the catalogue) every product adds an entry that is never read or released. Reproduced: 20 000 products → 20 000 entries kept. Each entry also keeps the rule entities (and their collections) alive, so `EntityManager::clear()` does not free them.
  - The product-specific fetcher reads `$product->getSpecificPriceRules()`. If the admin saves the product's rules and the same request renders or recalculates a price (admin preview, order edit), the old list is served.
  - Same pattern as ProductQuantityPriceRules TODO-2 **B-03**.
- **Fix:** together with TODO-1 **CC-01**: `\WeakMap` keyed by the product object, do not cache when `getId() === null`, do not write when not reading (or always read, keyed by context), `ResetInterface`. Clear the entry in the specific-price-rules data type on save **(dependent: ProductBundle `CoreExtension/ProductSpecificPriceRules.php`)**.

### CB-07 — `DiscountPercentActionProcessor` rounds a float product: 17.5 %, 35 %, 70 % … give a discount 1 cent too low — (reproduced)
- **Where:** `Rule/Action/DiscountPercentActionProcessor.php:30`: `(int) round(($configuration['percent'] / 100) * $price)`.
- **Reproduced** (prices 0.01 … 3 000.00, compared with exact decimal half-up rounding):

  | percent | wrong results | example |
  |---|---|---|
  | 17.5 % | 1 170 | 1.80 → 0.31, correct 0.32 |
  | 35 % | 2 340 | 0.90 → 0.31, correct 0.32 |
  | 70 % | 4 681 | 0.45 → 0.31, correct 0.32 |

  `0.35 * 90 = 31.499999…` because `0.35` has no exact binary representation. 5, 7, 10, 15, 19, 20, 25, 30, 33 and 12.5 % happened to be exact in this range.
- **Impact:** the shown "‑35 %" price differs by 1 cent from what a customer or an accounting system computes; repeated per unit and per line.
- **Fix:** compute in integers: percent in basis points (`(int) round($percent * 100)`), then `intdiv($price * $bp * 2 + 10000, 20000)` for half-up, or `round($price * $percent / 100, 0, RoundingMode::HalfAwayFromZero)` on a `BcMath\Number` (PHP 8.4). Share the helper with the cart / shipping / payment percent actions (TODO-3 **CI-06**).

### CB-08 — `TimeSpanConditionChecker` throws `TypeError` for empty-string dates, warns for missing keys, and treats a time span without dates as "always valid" — (reproduced) (ProductBundle TODO-2 **B-33**, RuleBundle TODO-2 **B-01**)
- **Where:** `Rule/Condition/TimeSpanConditionChecker.php:30-45`.
- **Reproduced:**
  - `['dateFrom' => '', 'dateTo' => '']` → `TypeError: Unsupported operand types: string / int` at `:30`. Empty strings come from API-created, imported or migrated rules; the error escapes the price calculation like TODO-1 **CC-03**.
  - `['dateFrom' => '1700000000000', 'dateTo' => '']` → same `TypeError` at `:31`.
  - `[]` → four `Undefined array key` warnings (exceptions under the debug `ErrorHandler`), then `true`.
  - `['dateFrom' => null, 'dateTo' => null]` → `true`: an empty time-span condition silently makes the rule permanent. The form allows saving it (ProductBundle **B-33**).
- **Fix:** read with `$configuration['dateFrom'] ?? null`, accept only `is_numeric()` values (cast with `(int)`), treat "neither date set" as an invalid configuration (return `false` and log) instead of "always". Remove the Carbon objects; compare milliseconds directly or use an injected clock (TODO-3 **CI-08**).

### CB-09 — Action processors do not normalise their configuration: `discountPrice` and `discountAmount` throw `TypeError` for float or string values, missing keys give warnings — (reproduced)
- **Where:** `Rule/Action/DiscountPriceActionProcessor.php:58,67`, `Rule/Action/DiscountAmountActionProcessor.php:43,52` (raw `$configuration['price']` / `['amount']` passed to `convert(int $value, …)` under `strict_types`); `Rule/Action/PriceActionProcessor.php:58` casts with `(int)`, the other two do not. `$configuration['currency']`, `['percent']` are read without `??` in all four processors.
- **Reproduced:** `price` action with `1250.0` or `'1250'` → 1250 (cast). `discountPrice` with the same values → `TypeError: …convert(): Argument #1 ($value) must be of type int, float given` / `string given`; `discountAmount` with `1250.0` → same `TypeError`.
- **When:** the admin form stores ints (CoreShop `MoneyType`), but rule configurations also come from the REST / admin API, data importers, fixtures, Pimcore version restores and CoreShop 2/3 migrations. One such rule triggers TODO-1 **CC-03** for every product.
- **Fix:** one small normaliser per action (or a readonly configuration value object, TODO-3 **CI-07**) that validates `is_numeric`, casts to `int`, and throws the processor's "not applicable" exception (or returns 0 discount + log) for invalid data.

### CB-10 — Unit-definition handling differs between actions: `price` / `discountPrice` are skipped for non-default units, but `discountAmount` / `discountPercent` apply the same amount per unit; products without a default unit definition never get a rule price for any unit — (verified)
- **Where:** `Rule/Action/PriceActionProcessor.php:43-49` and `Rule/Action/DiscountPriceActionProcessor.php:43-49` (`throw` when `$context['unitDefinition']` is not the default unit); `Rule/Action/DiscountAmountActionProcessor.php:34-53` and `DiscountPercentActionProcessor.php:26-31` (no unit check).
- **Problems:**
  - A rule "sale price 9.00" (discountPrice) does not show any sale on the "box of 12" unit, while a rule "1.00 off" (discountAmount) on the same product takes 1.00 off the box of 12 as well as off the single piece — the box gets 1.00 off 12 pieces.
  - `$subject->getUnitDefinitions()?->getDefaultUnitDefinition()?->getId()` is `null` when the product has unit definitions but no default; `$context['unitDefinition']->getId() !== null` is then always true → `price` / `discountPrice` actions never apply to that product in a unit context.
- **Fix:** decide per action how units are handled and document it: either convert fixed prices / amounts with the unit's `conversionRate` (price × rate, amount × rate), or skip non-default units consistently for all fixed-amount actions. Treat "no default unit" as "the context unit is the base unit" rather than "never matches".

---

## Exceptions and contracts

### CB-11 — The three exceptions reject an `\Error` as previous exception, and `$calculatorClass` is untyped — (reproduced)
- **Where:** `Exception/{NoPriceFoundException,NoRetailPriceFoundException,NoDiscountPriceFoundException}.php:23-28`.
- **Reproduced:** `new NoRetailPriceFoundException('X', new \TypeError('boom'))` → `TypeError: …__construct(): Argument #2 ($previousException) must be of type ?Exception, TypeError given`. Wrapping a `TypeError` from a processor (TODO-2 **CB-09**) — the obvious fix for TODO-1 **CC-03** — therefore fails.
- **Fix:** `?\Throwable $previous = null`, `string $calculatorClass`. Same fix as ProductQuantityPriceRules TODO-2 **B-07**. Restructure as in TODO-3 **CI-05**.

### CB-12 — Repository contracts do not say what the calculators rely on: `findActive()` ordering, nullable returns — (verified) (latent)
- **Where:**
  - `Repository/PriceRuleRepositoryInterface.php` / `ProductPriceRuleRepositoryInterface.php` inherit `findActive(): array` from `Component/Rule/Repository/RuleRepositoryInterface`. `Rule/Fetcher/ValidProductPriceRuleFetcher.php:36` and the stop-propagation logic depend on "ordered by priority", which only `Bundle/ProductBundle/Doctrine/ORM/PriceRuleRepository.php` implements; the generic `RuleBundle/Doctrine/ORM/RuleRepository::findActive()` has no `ORDER BY`. A project repository that extends the generic one silently changes which rule wins (**CB-05**).
  - `Repository/ProductUnitDefinitionsRepositoryInterface.php:27-30`: docblock `@return ProductUnitDefinitionsInterface` contradicts the nullable return type.
  - (Correction after the `Model/` scan: unit names *are* unique — `Bundle/ProductBundle/Resources/config/doctrine/model/ProductUnit.orm.xml` maps `name` with `unique="true"` — so `ProductUnitRepositoryInterface::findByName()` is fine. A duplicate name fails on the index instead of in the form, ProductBundle TODO-2 **B-29**.)
- **Fix:** re-declare `findActive()` in `PriceRuleRepositoryInterface` with `@return list<PriceRuleInterface>` and "ordered by priority ASC, id ASC" in the contract; fix the docblock.

---

## Models (second scan)

### CB-13 — `ProductUnitDefinitions`: the "additional" methods compare a unit with a unit *definition*, so their guard never fires: an additional definition for the default unit overwrites the default's conversion rate, and the default can be removed from the collection while it stays the default — (reproduced) (plausible: trigger)
- **Where:** `Model/ProductUnitDefinitions.php:137-148` and `:150-161`:
  ```php
  $productUnit = $unitDefinition->getUnit();
  $defaultDefinition = $this->getDefaultUnitDefinition();
  $defaultDefinitionUnit = $defaultDefinition;          // should be ->getUnit()
  if ($productUnit === $defaultDefinitionUnit) { return; }
  ```
  `addUnitDefinition()` (`:87-101`) then merges by unit **name** into the existing definition.
- **Reproduced:**
  - Default `piece` (rate 1.0), then `addAdditionalUnitDefinition(piece, rate 12, precision 2)` → the default definition now has rate `12.0`, precision `2`.
  - `removeAdditionalUnitDefinition($default)` → the default is removed from `$unitDefinitions` (mapped with `orphan-removal="true"`, so it is **deleted** on flush) while `getDefaultUnitDefinition()` still returns it. On flush the FK `default_unit_definition` is set to `NULL` (`on-delete="SET NULL"`), the product loses its default unit.
- **Impact:** `conversionRate` is what `Component/Core/Order/Modifier/CartItemQuantityModifier.php:61` multiplies into `defaultUnitQuantity`, which drives stock movements, quantity price ranges and shipping weight. A default rate of 12 means one piece is booked as twelve. The admin editor prevents choosing the default unit as additional unit (`Bundle/ProductBundle/Resources/public/pimcore/js/product/units/builder.js:250-290` removes used units from the other combos), but nothing validates the posted data (ProductBundle TODO-2 **B-29**), and `ProductUnitDefinitionsType::onSubmit()` (`Form/Type/Unit/ProductUnitDefinitionsType.php:59-64`) calls `addAdditionalUnitDefinition()` for any posted unit that already exists — the REST / admin API, imports and project code reach it directly.
- **Fix:** compare units, by identity or id: `$unitDefinition->getUnit()?->getId() === $this->defaultUnitDefinition?->getUnit()?->getId()` (or `===` on the unit objects); in `removeUnitDefinition()` refuse (or clear `$defaultUnitDefinition` explicitly) when the default is removed; unit tests for both.

### CB-14 — `ProductUnitDefinitions` matches definitions by unit name and ids, and mutates its arguments: changing the default unit leaves two definitions with rate 1.0, the passed object is silently dropped, unsaved units are never "additional", a unit-less default throws `TypeError` — (reproduced)
- **Where:** `Model/ProductUnitDefinitions.php:80-85` (`setDefaultUnitDefinition()`), `:87-101` (`addUnitDefinition()`), `:120-135` (`getUnitDefinition(string $identifier)` by name), `:163-186` (`getAdditionalUnitDefinitions()` by unit id).
- **Reproduced:**
  - Default `piece`, additional `box` (rate 12), then `setDefaultUnitDefinition(box)`: result `piece` rate 1.0, `box` rate 1.0. The old default keeps 1.0 (should become 1/12), the 12 of `box` is overwritten. Quantities in either unit are now converted 1:1. (Through the admin editor the old default is removed instead, see ProductBundle TODO-2 **B-14**; through the API, fixtures or code the rates stay wrong.)
  - `setDefaultUnitDefinition()` also calls `setConversionRate(1.0)` on the **argument** before deciding whether it is used.
  - `addAdditionalUnitDefinition($b2)` for a unit that already has a definition `$b1`: `$b2` is not added, `$b2->getProductUnitDefinitions()` stays `null`, and `$b1` silently takes over `$b2`'s rate. Code that goes on to use `$b2` (e.g. `ProductUnitDefinitionPrice::setUnitDefinition($b2)`, a quantity range) references an object Doctrine does not know → "A new entity was found through the relationship …" on flush. `ProductUnitDefinitionsType::onSubmit()` works around exactly this by swapping in the existing object.
  - Two **unsaved** units (id `null`): `getAdditionalUnitDefinitions()->count() === 0` (expected 1), because `null !== null` is false at `:181`. New products with new units show no additional units until everything is flushed.
  - Default definition without a unit → `getUnitDefinition(null)` → `TypeError: … Argument #1 ($identifier) must be of type string, null given` (ProductBundle TODO-2 **B-28**).
- **Fix:** key the lookup by the unit object or its id with a name fallback only for unsaved units; when the default unit changes, recompute the other rates relative to the new base (`rate_old / rate_newDefault`) or reject the change while dependent data (unit prices, quantity ranges, cart items) exists; never mutate the argument before it is accepted; return the effective definition from `addUnitDefinition()` so callers can use it; `Assert::notNull($definition->getUnit())` with a clear message.

### CB-15 — `AbstractPriceRule::setLabel()` writes into the **fallback** translation when the requested locale does not exist yet: setting a German label overwrites the English one — (reproduced) (Order `CartPriceRule::setLabel()` has the same code)
- **Where:** `Model/AbstractPriceRule.php:95-98`: `$this->getTranslation($language)->setLabel($label);` — `getTranslation()` defaults to `$useFallbackTranslation = true` and returns the fallback-locale translation when `$language` has none (`Component/Resource/Model/TranslatableTrait.php:57-79`). `ProductUnit::set*Label()` (`Model/ProductUnit.php:74-107`) correctly passes `false`.
- **Reproduced:** fallback locale `en`; `setLabel('Summer sale', 'en')`, then `setLabel('Sommerschlussverkauf', 'de')` → `en = 'Sommerschlussverkauf'`, `de` = same, only one translation (`en`) exists.
- **Impact:** the admin form writes the `translations` collection directly, so the editor is not affected. Every programmatic setter call is: imports, fixtures, Behat setup, migrations from CoreShop 2/3, project code. The storefront then shows the wrong-language rule label.
- **Fix:** `$this->getTranslation($language, false)->setLabel($label);` and return `$this`. Same change in `Component/Order/Model/CartPriceRule.php:140-143` **(dependent)**.

### CB-16 — `getDescription(): string` throws `TypeError` on every rule without a description, and `ProductSpecificPriceRule` has no description column at all — (reproduced) (latent)
- **Where:** `Model/AbstractPriceRule.php:43`, `:66-69`; `Model/ProductPriceRuleInterface.php:23`. Mapping: `ProductPriceRule.orm.xml` `description` is `nullable="true"`; `ProductSpecificPriceRule.orm.xml` does not map it.
- **Reproduced:** `(new ProductPriceRule())->getDescription()` and `(new ProductSpecificPriceRule())->getDescription()` → `TypeError: Return value must be of type string, null returned`.
- **Impact:** no form, serializer group or service reads the description today, so nothing breaks in a stock install; any project code, Twig template or API that does breaks on the first rule without a description (all of them unless set via code).
- **Fix:** `?string` return type and property (`protected ?string $description = null`); move description from `AbstractPriceRule` to `ProductPriceRule` (where it is mapped and declared in the interface), or map it for specific rules too.

### CB-17 — The specific price rule's `inherit` flag is stored and editable but never evaluated — (verified)
- **Where:** `Model/ProductSpecificPriceRule.php:36-63`, `Model/ProductSpecificPriceRuleInterface.php:23-31`; written by `Bundle/ProductBundle/Form/Type/ProductSpecificPriceRuleType.php:37` and the "Inherit" checkbox in `Bundle/ProductBundle/Resources/public/pimcore/js/specificprice/object/item.js:113-116`, serialized (`Model.ProductSpecificPriceRule.yml`). No code calls `getInherit()` (repository-wide search); `Rule/Fetcher/ValidProductSpecificPriceRuleFetcher.php` only reads `$product->getSpecificPriceRules()`.
- **Problem:** an admin who ticks "Inherit" on a parent product's rule expects variants to get that rule. What actually happens depends only on Pimcore field inheritance of the whole field: a variant with **no** own rules inherits **all** parent rules (ticked or not); a variant with one own rule inherits **none**. The checkbox has no effect either way.
- **Fix:** implement it in `ValidProductSpecificPriceRuleFetcher`: own rules plus the parent chain's rules with `getInherit() === true` (walk `getParent()` while it is a `ProductInterface`), with variant rules taking precedence by priority. Or remove the field and the checkbox (DB migration) and document that variants inherit the whole field.

### CB-18 — `Category::getChildCategories()` / `hasChildCategories()` return every child object — folders, variants and objects of other classes — not only categories — (verified)
- **Where:** `Model/Category.php:26-34` (`$this->getChildren()`); `vendor/six-it/pimcore-source/models/DataObject/AbstractObject.php:327-352` (default `$objectTypes = [object, variant, folder]`, any class); `CategoryInterface.php:42` returns a plain `Listing`.
- **Problem:** `hasChildCategories()` drives the dropdown in `Bundle/FrontendBundle/Resources/views/Category/_menu.html.twig:10`. A category that only contains a folder (e.g. for images or landing objects) or objects of another class gets a dropdown with no categories in it; code iterating `getChildCategories()` receives non-category objects. `getTotalCount()` is one extra `COUNT` query per category per menu rendering.
- **Fix:** use a `CategoryInterface` listing: `$this->getChildren([AbstractObject::OBJECT_TYPE_OBJECT])` filtered by class (`$listing->setCondition('parentId = ? AND classId = ?', …)`), or delegate to `CategoryRepositoryInterface::findChildCategories()` (which the bundle implements via `parentCategory`, ProductBundle TODO-2 **B-24**). Return `list<CategoryInterface>` from the interface **(BC)**.

### CB-19 — `Category::getHierarchy()` walks the Pimcore tree parent, not `parentCategory`, and includes unpublished parents — (verified)
- **Where:** `Model/Category.php:41-59` (`$category->getParent()` while `instanceof self`); consumers `Component/Core/Index/Extensions/{ProductClassExtension,OpenSearchProductClassExtension}.php:67` (parent category ids written to the product index).
- **Problem:** the rest of CoreShop uses the `parentCategory` relation (`CategoryInterface::getParentCategory()`, maintained by `Bundle/ProductBundle/EventListener/CategoryPersistEventListener.php`, see ProductBundle TODO-2 **B-23**). The two disagree as soon as the tree contains folders between categories (hierarchy stops at the folder) or a category is moved without saving. `getParent()` also returns unpublished categories, which then appear in the index's category filter for the product.
- **Fix:** walk `getParentCategory()` (with a cycle guard) and skip unpublished parents, or document that the Pimcore tree is authoritative and use the same in `findChildCategories()`.
