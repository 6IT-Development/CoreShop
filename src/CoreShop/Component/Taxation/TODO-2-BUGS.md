# Taxation Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs that are limited to an edge case (1-cent rounding, unusual configuration, custom code paths) or that give wrong results only under a specific setup.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Prefixes and markers as in TODO-1; TaxationBundle items are referenced as TaxationBundle **C-/B-/I-nn**.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Calculator – rounding inconsistencies

### CB-01 — Net mode, two or more combined rates: `applyTaxes()` rounds the total, `getTaxesAmount()` rounds per rate → product page and cart differ by 1 cent for every fourth price — (reproduced) (new)
- **Where:** `Calculator/TaxRulesTaxCalculator.php:40-43` (one rounding of `price × (1 + Σr)`) vs `:91-110` (one rounding per rate, then summed).
- **Reproduced:** 10 % + 15 % combined, net 0.01 … 19.99: 500 of 1 999 prices give `applyTaxes(p) − p ≠ getTaxesAmount(p)` (e.g. net 0.02: `applyTaxes` adds 0.01, per-rate sum is 0.00).
- **Impact:** `TaxApplicator` (product page) uses `applyTaxes()`, `CartItemProcessor` uses `getTaxesAmount()`; the storefront shows one gross price and the cart charges another. Same class of problem as TODO-1 **CC-03**, but caused by rounding order, not float representation.
- **Fix:** define gross as `net + Σ rounded per-rate amounts` everywhere (TODO-1 **CC-03** fix). Document the rounding rule ("per rate, half away from zero") in the interface.

### CB-02 — Gross mode, single rate: `G − removeTaxes(G) ≠ getTaxesAmountFromGross(G)` whenever the net value is exactly half a cent — (reproduced) (new)
- **Where:** `Calculator/TaxRulesTaxCalculator.php:45-48` (`round(G / (1 + r))`) vs `:75-89` (`round(G − G / (1 + r))`). Both round a `.5` value up, so net + tax = G + 1.
- **Reproduced:** 20 % (FR, AT, UK, SK, BG …): 16 667 of 99 999 gross prices (every `G ≡ 3 mod 6`). Example gross 12.03: `removeTaxes()` → net 10.03, `getTaxesAmountFromGross()` → tax 2.01 (sum 12.04); the cart (`CartItemProcessor.php:81` `gross − tax`) stores net 10.02, the product page (`TaxApplicator::applyTax(..., false)` → `removeTaxes()`) shows 10.03.
- **Impact:** in gross-price stores the net price shown (B2B views, "excl. VAT" labels) differs from the net value stored on cart / order items by 1 cent for a sixth of all prices at 20 %.
- **Fix:** implement `removeTaxes(G)` as `G − getTaxesAmountFromGross(G)` (TODO-1 **CC-03**), so there is only one rounding.

### CB-03 — The same tax rate twice in one calculator is charged twice but recorded once; rates without an id collapse into key `""` — (reproduced in bundle scan) (TaxationBundle TODO-2 **B-01**)
- **Where:** `Calculator/TaxRulesTaxCalculator.php:79-86`, `:100-107` (result keyed by `$tax->getId()`, later entries overwrite earlier ones), `:50-68` (`getTotalRate()` counts every entry); `Collector/TaxCollector.php:66` (`addTaxToArray(int $taxId, …)`).
- **Problem:** two matching rules with the same rate → `applyTaxes(100.00)` = 120.00, but `getTaxesAmountAsArray()` = `{1: 10.00}`. Unsaved rates (`getId() === null`, tests, previews) all land on key `""`; `TaxCollector::addTaxToArray()` then throws `TypeError` under `strict_types` (string key `""` is not `int`).
- **Fix (component side):** accumulate (`$taxesAmounts[$id] = ($taxesAmounts[$id] ?? 0) + $amount`) or reject duplicates in the constructor (`\InvalidArgumentException`); reject rates without an id in the constructor (`Assert::notNull($tax->getId())`). Group-level validation stays in the bundle.

### CB-04 — Negative rates: `abs()` in net mode, raw value in gross mode — (reproduced in bundle scan) (TaxationBundle TODO-2 **B-02**)
- **Where:** `Calculator/TaxRulesTaxCalculator.php:56,63,102,105` (`abs($tax->getRate())`) vs `:81,84` (no `abs()`).
- **Problem:** rate −10: net mode adds +10 %, gross mode records tax −12.22 on 110.00.
- **Fix (component side):** treat the rate the same way in every method; preferably reject negative rates in the calculator constructor (and in `TaxRate::setRate()`, TODO-3 **CI-06**) instead of silently applying `abs()`. Validation constraint stays in the bundle.

---

## Collector

### CB-05 — `TaxCollector` treats array keys as tax-rate ids, but Pimcore field collections loaded from the database are keyed by position → amounts are booked on the wrong rate or dropped — (verified) (plausible: trigger) (new) (dependent)
- **Where:**
  - `Collector/TaxCollector.php:57-64`: `mergeTaxes()` passes the **key** of `$taxes1` as tax id (`addTaxToArray($id, $tax->getAmount(), $taxes2)`) and re-reads the rate with `find($id)`; it never uses the item's own `getTaxRate()`.
  - `Collector/TaxCollector.php:81-94`: `collectTaxes()` / `collectTaxesFromGross()` match an existing item in `$usedTaxes` by `array_key_exists($tax->getId(), $usedTaxes)`.
  - **(verified)** `vendor/six-it/pimcore-source/models/DataObject/Fieldcollection/Dao.php:131-137`: a loaded field collection is keyed by `$value->getIndex()` (0, 1, 2 …); `Fieldcollection::save()` (`Fieldcollection.php:120-138`) does not keep any other key.
  - **(dependent)** callers that pass field-collection items: `Component/Core/Order/Processor/CartTaxProcessor.php:55-58` (`$item->getTaxes()->getItems()` → `mergeTaxes()`), `Component/Core/Cart/Rule/Applier/CartRuleApplier.php:192-208` and `CartItem/Rule/Applier/CartItemRuleApplier.php:142-160` (`$taxItems->getItems()` as `$usedTaxes`).
- **Problem:** the result is only correct as long as the items were created in the **same request** by `CartItemProcessor` (`new Fieldcollection($taxes)` with id keys). That holds for the standard cart pipeline because `CartItemsProcessor` / `CartItemsImmutableProcessor` rebuild every item's taxes before `CartTaxProcessor` runs. Any other caller (a custom processor, a CLI recalculation of a stored order, an item skipped by a custom processor, a third-party shipping-tax strategy that returns a list) passes position keys:
  - key `0` → `find(0)` → `null` → the amount is silently dropped;
  - key `1` → `find(1)` → the amount is added to tax rate **#1**, whatever rate the item really had;
  - in `collectTaxes(…, $usedTaxes)` a new amount for rate #2 is added to the item at position 2.
- **Fix (component side):**
  - `mergeTaxes()`: iterate values, use `$tax->getTaxRate()` (fall back to the key only if no rate is set) and re-key the result by rate id.
  - `collectTaxes*()`: normalise `$usedTaxes` first (`foreach … $byId[$item->getTaxRate()?->getId()] = $item`), so callers do not need to know the key convention. Document the returned key (tax-rate id) in `TaxCollectorInterface`.

### CB-06 — A rate of 0 % produces no tax item at all — (plausible) (new)
- **Where:** `Collector/TaxCollector.php:73-75` (`if ($amount === 0) return;`).
- **Problem:** a 0 % rule (export, reverse charge, zero-rated goods) never yields a `CoreShopTaxItem`, so orders / invoices have no "0 % VAT" line and per-rate tax reports cannot list the zero-rated turnover. The same check also hides a legitimate 0-cent amount of a non-zero rate (price below 5 cents), which then reappears as a separate line once the quantity changes.
- **Fix:** decide explicitly: skip only when `$tax->getRate() == 0` is **not** wanted to be reported (configurable), otherwise create the item with amount 0. At minimum, base the decision on the rate, not on the rounded amount.

---

## Models

### CB-07 — Reading a tax-rate name has side effects and can throw without a current locale — (plausible) (dependent: TaxationBundle TODO-2 **B-03**)
- **Where:** `Model/TaxRate.php:59-72` (`__toString()` with hard-coded `'en'`, `getName()`, `setName()`), `Collector/TaxCollector.php:86` (`$tax->getName()`); **(dependent)** `Component/Resource/Model/TranslatableTrait.php` `getTranslation()`: throws `\RuntimeException('No locale has been set …')` when neither `$locale` nor `currentLocale` is set, and creates **and adds** an empty translation when neither the locale nor the fallback exists.
- **Problem:**
  - A storefront locale without a tax-rate translation attaches an empty `TaxRateTranslation` to a managed `TaxRate` during cart processing; the next `flush()` persists it (bundle **B-03**).
  - A `TaxRate` that was not loaded through Doctrine (`new TaxRate()` in tests, fixtures, imports; or a rate loaded in a CLI / Messenger context before the translatable listener set the locale) makes `TaxCollector` throw `RuntimeException` while collecting taxes.
  - `__toString()` uses `'en'`; in a shop without English it creates an empty `en` translation as a side effect of logging / admin display.
- **Fix (component side):** add a side-effect-free name accessor (`getName(?string $locale = null): ?string` that returns the translation, the fallback translation or `null`, never creating one); use it in `TaxCollector` and `__toString()` (fallback to `#id`).

### CB-08 — `TaxRule::setTaxRate()` rejects `null`, and the getters claim non-null return values — (dependent: TaxationBundle TODO-2 **B-04**)
- **Where:** `Model/TaxRuleInterface.php:38-50`, `Model/TaxRule.php:87-97`.
- **Problem:** the admin form submits `null` for an empty rate combo; the property accessor fails with a `TypeError`-based exception (500) before validation can report "please select a tax rate". `getTaxRuleGroup()` / `getTaxRate()` are documented as `@return TaxRuleGroupInterface` / `TaxRateInterface` but return `null` on a new rule (`TaxRule::__toString()` already guards for that).
- **Fix (component side):** `setTaxRate(?TaxRateInterface $taxRate): static`, `getTaxRate(): ?TaxRateInterface`, `getTaxRuleGroup(): ?TaxRuleGroupInterface`; validation (`NotBlank`, `Valid`) stays in the bundle.
