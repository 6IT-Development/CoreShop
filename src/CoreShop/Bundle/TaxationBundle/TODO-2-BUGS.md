# TaxationBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs that are limited to an edge case, to a single admin screen, or that give wrong results only under a specific configuration.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code (PHP 8.4.27) or by reading the vendored library source.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Calculator and collector

### B-01 — The same tax rate twice in one calculator is charged twice but recorded once — (verified)
- **Where:** `Component/Taxation/Calculator/TaxRulesTaxCalculator.php:79-86`, `:100-107` (result arrays keyed by `$tax->getId()`); `:50-68` (`getTotalRate()` counts every entry).
- **Problem:** if two matching rules of a group point to the same rate (easy to configure: one rule for "all countries" plus one for a specific country, both with "Standard 10 %"; `CoreBundle/Doctrine/ORM/TaxRuleRepository.php:30-43` returns both), `applyTaxes(100.00)` = 120.00 but `getTaxesAmountAsArray()` = `{1: 10.00}`: the second entry overwrites the first. The cart total and the tax lines no longer add up (20.00 vs 10.00); in gross mode the net value is computed from the wrong sum.
- **Fix:** accumulate (`$taxesAmounts[$id] = ($taxesAmounts[$id] ?? 0) + …`) or refuse duplicates: add a group-level validation (callback) that forbids two rules with the same rate and the same country/state scope, and dedupe in `TaxCalculatorFactory`. Decide which behaviour is intended and document it.
  - Related: rates without an id (unsaved entities in tests / previews) all collapse into key `""`, and `TaxCollector::addTaxToArray(int $taxId, …)` then throws a `TypeError` under `strict_types` (plausible).

### B-02 — Negative rates are accepted and treated inconsistently: added as positive in net mode, removed as negative in gross mode — (verified)
- **Where:**
  - `Resources/config/validation/TaxRate.yml:3-5`: only `NotBlank` + `Type: float`, no range.
  - `Component/Taxation/Calculator/TaxRulesTaxCalculator.php:56,63,102,105` use `abs($tax->getRate())`; `:81,84` (gross) do not.
- **Reproduced:** rate −10: `applyTaxes(10000)` = 11000, `getTaxesAmountAsArray(10000)` = `{1: 1000}`, but `getTaxesAmountFromGrossAsArray(11000)` = `{1: -1222}`. A typo in the admin therefore gives +10 % tax in net stores and a **negative** tax line in gross stores.
- **Fix:** add `PositiveOrZero` (and a sensible upper bound, e.g. `LessThanOrEqual: 100`) to `rate` in group `coreshop`; remove the silent `abs()` calls (with validation in place they only hide bad data) or apply them in every method.

### B-03 — Reading a tax rate name in a locale that has no translation adds an empty translation that is persisted on the next flush — (plausible) (dependent)
- **Where:** `Component/Taxation/Collector/TaxCollector.php:86` (`$tax->getName()` = current locale), `Component/Taxation/Model/TaxRate.php:59-67` (`__toString()` uses `getName('en')`); **(dependent)** `Component/Resource/Model/TranslatableTrait.php:85-90` (creates **and adds** a new translation when neither the locale nor the fallback exists), `ResourceBundle/EventListener/ORMTranslatableListener.php:104` (`cascade: persist`).
- **Problem:** a storefront locale without a tax-rate translation (a newly added website language) makes cart processing attach an empty `TaxRateTranslation` to a managed `TaxRate`; any later `flush()` in the same request inserts it. The tax item gets `name = null`. Afterwards the admin cannot save that tax rate until the empty language tab is filled, because `validation/TaxRateTranslation.yml:3-4` requires `NotBlank` for every translation.
- **Fix:** read names with `getTranslation($locale, true)` without side effects (a read path in `TranslatableTrait` that does not call `addTranslation()`), fall back to the default/fallback locale name for the tax item, and let `__toString()` use the fallback instead of a hard-coded `'en'`.

---

## Admin (forms, controller, JS)

### B-04 — Saving a tax rule group with a rule row that has no tax rate selected throws a 500 instead of a validation message — (verified code path)
- **Where:**
  - `Resources/config/validation/TaxRuleGroup.yml:1-4`: no `Valid` on `taxRules`, so the constraints in `validation/TaxRule.yml` (`taxRate: NotBlank`, `behavior: NotBlank/Type`) are never evaluated through the group form (Symfony only validates embedded objects through `Valid`).
  - `Component/Taxation/Model/TaxRule.php:92` / `TaxRuleInterface.php:50`: `setTaxRate(TaxRateInterface $taxRate)` is not nullable. An empty `taxRate` submits `null`, and `vendor/symfony/form/Extension/Core/DataAccessor/PropertyPathAccessor.php:76-80` lets the `InvalidTypeException` of the property accessor through → unhandled exception while the form is submitted.
  - `Resources/public/pimcore/js/taxrulegroup/item.js:180-186` (and `CoreBundle/Resources/public/pimcore/js/taxation/taxrulegroup/item.js:213-218`): "Add" creates the row with `tax: null` (the column / form field is `taxRate`), so a new row that the admin does not edit has no `taxRate` at all and `clearMissing` sets it to `null`.
- **Impact:** clicking "Add" and then "Save" (or clearing the rate combo) ends with an error response instead of "please select a tax rate"; the whole group cannot be saved until the row is removed.
- **Fix:** add `taxRules: - Valid: ~` to `TaxRuleGroup.yml`; make `setTaxRate()` accept `?TaxRateInterface` (the `NotBlank` then reports it) or give the field `'constraints' => [new NotNull()]` with `empty_data`; use `taxRate: null` in both JS files and make the combo `allowBlank: false`.

### B-05 — The admin rounds tax rates to two decimals, so rates like 8.875 % cannot be entered — (verified code path)
- **Where:** `Resources/public/pimcore/js/tax/item.js:84-91` (`numberfield` with `decimalPrecision: 2`); `Form/Type/TaxRateType.php:35` (`NumberType` without `scale`); `Resources/config/doctrine/model/TaxRate.orm.xml:13` (`float`).
- **Problem:** ExtJS rounds the field value to 2 decimals before it is submitted. Many US sales-tax and some local rates have three or four decimals (8.875 %, 7.375 %, 9.975 % QST). The stored rate silently becomes 8.88 %, so every price is taxed wrongly by up to 0.005 percentage points.
- **Fix:** `decimalPrecision: 4` in the JS, `'scale' => 4` on the `NumberType`, and a `decimal(…, 4)` column (TODO-3 **I-04**).

### B-06 — `listRulesAction` has no permission check and returns a 500 for a missing id — (verified code path) (dependent)
- **Where:** `Controller/TaxRuleGroupController.php:29-38`; route `Resources/config/pimcore/routing.yml:10-15`.
- **Problem:**
  - Every other action of `ResourceController` (except `listAction`, see below) calls `$this->isGrantedOr403()`. `listRulesAction` does not, so any Pimcore backend user without the `coreshop_permission_tax_rule_group` permission can read all tax rules.
  - `getParameterFromRequest($request, 'id')` returns `null` when `id` is missing, and `findOr404(int|string $id)` then throws a `TypeError` (500) instead of a 404.
  - **(dependent)** `ResourceBundle/Controller/ResourceController.php:80-89` (`listAction`) has no permission check either, for every CoreShop resource.
- **Fix:** call `$this->isGrantedOr403()` first; read the id with `$request->attributes->getInt('id')` / `query->getInt()` and 404 on `0`. The route is not used by any JS (the group detail already serializes its rules); consider removing it instead (TODO-3 **I-06**).

### B-07 — The `coreshop_tax_rates` admin store declares a `rate` field that the list endpoint never sends — (verified code path)
- **Where:** `Resources/public/pimcore/js/resource.js:16-20` (fields `id`, `name`, `rate`); `ResourceBundle/Controller/ResourceController.php:88` serializes with group `List`; `Resources/config/serializer/Model.TaxRate.yml:18-21` exposes `rate` only in `Detailed`.
- **Problem:** `record.get('rate')` is always `undefined` in every combo / grid that uses this store (tax rule grid, `coreShopTaxRate` object field, document editable), so the rate cannot be shown next to the name and custom JS relying on it breaks.
- **Fix:** add `List` to the `rate` groups (and to `active`, so inactive rates can be marked in combos).

### B-08 — DataHub: only `coreShopTaxRuleGroup` gets a GraphQL query type, `coreShopTaxRate` has none — (plausible)
- **Where:** `Resources/config/services/data_hub.yml:1-9`; data types registered in `Resources/config/pimcore/config.yml:9-10`; compare `AddressBundle` / `CurrencyBundle` / `StoreBundle` `services/data_hub.yml`, which register one generator per data type.
- **Problem:** classes or field collections with a `coreShopTaxRate` field (the bundle's own `CoreShopTaxItem.taxRate`, i.e. order / cart item taxes) cannot expose that field in a DataHub GraphQL schema; depending on the DataHub version the field is skipped or the schema build fails with "no type generator".
- **Fix:** add a `coreshop.datahub.graphql.querytypegenerator.resource.tax_rate` service with `%coreshop.model.tax_rate.class%` and id `typegenerator_dataobjectquerydatatype_coreShopTaxRate`.
