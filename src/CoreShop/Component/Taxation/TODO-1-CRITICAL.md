# Taxation Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: all 16 PHP files in `src/CoreShop/Component/Taxation` (`Calculator/{TaxCalculatorInterface,TaxRulesTaxCalculator}.php`, `Collector/{TaxCollectorInterface,TaxCollector}.php`, `Model/{TaxItem,TaxRate,TaxRateTranslation,TaxRule,TaxRuleGroup}{,Interface}.php`, `Repository/{TaxRateRepositoryInterface,TaxRuleRepositoryInterface}.php`), its `composer.json` and `README.md`. Dependent config and callers that were followed: `Bundle/TaxationBundle/Resources/config/{services.yml,doctrine/model/*.orm.xml,validation/*.yml}`, `Bundle/TaxationBundle/Resources/install/pimcore/fieldcollections/CoreShopTaxItem.json`, `Component/Core/{Taxation/TaxCalculatorFactory.php,Taxation/TaxApplicator.php,Product/TaxedProductPriceCalculator.php,Order/Processor/{CartItemProcessor,CartItemsProcessor,CartItemsImmutableProcessor,CartTaxProcessor}.php,Cart/Rule/Applier/CartRuleApplier.php,CartItem/Rule/Applier/CartItemRuleApplier.php,Shipping/Taxation/{TaxCalculationStrategyTaxRule,TaxCalculationStrategyCartItems}.php}`, `Bundle/CoreBundle/Resources/config/services/{cart-processor,shipping-taxation,rules}.yml`, `Component/Resource/Model/{TranslatableTrait,ToggleableTrait}.php`, and `vendor/six-it/pimcore-source/models/DataObject/{Fieldcollection.php,Fieldcollection/Dao.php}`.

The bundle-side wiring has already been scanned in `src/CoreShop/Bundle/TaxationBundle` (its TODO files use `C-`, `B-`, `I-` numbers). Most of the calculator / collector root causes listed there live in this component; they are listed here again with the **component-side** part of the fix and a cross-reference, so either list can be worked from. When an item is fixed, close it in both files. Items that are new in this scan are marked **(new)**.

"Critical" here means: money charged or displayed wrongly, tax data written wrongly (orders, invoices, tax reports), or an error that takes down a whole area (cart, checkout) once it is triggered, or a blocker for Symfony 7.4 / PHP 8.4.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4, six-it/pimcore-source 2.x, PHP 8.4.27). Component items use the prefixes `CC-` (critical), `CB-` (bugs) and `CI-` (improvements).
Markers: **(dependent)** = root cause or part of the fix is outside this component; **(reproduced)** = run with PHP 8.4.27 against this component's classes; **(verified)** = confirmed by reading the vendored library source; **(plausible)** = follows from the code, but the trigger depends on data, configuration or deployment order.

**No Symfony 7.4 / PHP 8.4 language blocker was found.** All 16 PHP files pass `php -l` on 8.4.27 and load with the project autoloader; no implicitly nullable parameters, no dynamic properties, no removed PHP functions. The component has no direct Symfony code (validation, forms, DI live in TaxationBundle). Modernisation (native types, enum, readonly / asymmetric visibility, `RoundingMode`) is in TODO-3.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Calculator – wrong tax amounts

### CC-01 — Gross mode, two or more "combine" rates: each rate is removed as if it were the only tax → too much tax, too little net — (reproduced) (TaxationBundle TODO-1 **C-01**)
- **Where:** `Calculator/TaxRulesTaxCalculator.php:75-89`, branch `:83-85`:
  ```php
  $taxesAmounts[$tax->getId()] = (int) round($price - ($price / (1 + ($tax->getRate() / 100))));
  ```
  Consumers: `Collector/TaxCollector.php:46-55` (`collectTaxesFromGross()`), `Component/Core/Order/Processor/CartItemProcessor.php:71-76` (every gross-price cart item), `Component/Core/Shipping/Taxation/*` (shipping tax), `Component/Core/Cart*/Rule/Applier/*` (discount tax).
- **Reproduced:** 10 % + 15 % combined, gross 125.00 → `{1: 1136, 2: 1630}` = 27.66 tax, correct is `{1: 1000, 2: 1500}`. `removeTaxes(12500)` returns 10000, so the calculator contradicts itself; the cart then stores net 97.34 instead of 100.00.
- **Fix (component side):** compute the gross split from the net value: `net = removeTaxes(G)`, per-rate amounts = net breakdown (`getTaxesAmountAsArray(net)`), then put the remainder `(G − net) − Σ` on the last (or largest) rate so that Σ tax lines = `G − net` exactly. Implement once in a private "breakdown" method that all four amount methods use (see TODO-3 **CI-01**). Pin with unit tests (TODO-3 **CI-11**).

### CC-02 — Gross mode, "one after another" rates: rates are removed in application order → per-rate amounts swapped — (reproduced) (TaxationBundle TODO-1 **C-02**)
- **Where:** `Calculator/TaxRulesTaxCalculator.php:80-82`.
- **Reproduced:** 10 % then 15 %, net 100.00 → gross 126.50. Net mode gives `{1: 1000, 2: 1650}`, gross mode gives `{1: 1150, 2: 1500}`; sum correct, split wrong.
- **Fix (component side):** same as **CC-01** (derive from net). If the loop is kept, iterate `array_reverse($this->taxRates, true)` for gross and return the result in the original order.

### CC-03 — `applyTaxes()` / `removeTaxes()` build the factor `1 + rate / 100` in binary floating point: gross prices are 1 cent too low for 5.5 %, 13 %, 17 %, 25.5 % … and differ from the cart — (reproduced) (new) (dependent)
- **Where:** `Calculator/TaxRulesTaxCalculator.php:40-48`:
  ```php
  return (int) round($price * (1 + ($this->getTotalRate() / 100)));
  ```
  Consumers: `Component/Core/Taxation/TaxApplicator.php:43,47` → `Component/Core/Product/TaxedProductPriceCalculator.php:44,57,70,83` (every product price shown in the storefront), `Bundle/CoreBundle/Twig/ProductQuantityPriceRuleRangesPriceExtension.php:64`, carrier price display. The cart instead uses `getTaxesAmount()` (`CartItemProcessor.php:99-104`), which computes `price * (rate / 100)` and is not affected for single rates.
- **Reproduced** (net prices 0.01 … 2 999.99, compared with exact decimal half-up rounding):

  | rate | wrong `applyTaxes()` results | example |
  |---|---|---|
  | 25.5 % (FI standard VAT since 2024-09) | 858 | net 1.00 → 1.25, correct 1.26 |
  | 13 % (AT/GR reduced) | 2 178 | net 0.50 → 0.56, correct 0.57 |
  | 17 % (LU standard) | 385 | net 8.50 → 9.94, correct 9.95 |
  | 5.5 % (FR reduced) | 144 | net 19.00 → 20.04, correct 20.05 |
  | 2.5 % | 4 595 | net 0.60 → 0.61, correct 0.62 |

  `1.255`, `1.13`, `1.17`, `1.055` have no exact binary representation, so `100 * 1.255 = 125.49999…` rounds down. In every listed case `applyTaxes(p) − p !== getTaxesAmount(p)`.
- **Impact:** in a **net-price store** the product page, listings and price-range tables show a gross price that is 1 cent lower than the gross price the cart / order charges for the same product. That is a price-indication mismatch visible to the customer (and a compliance issue in the EU). The same float factor is used by `removeTaxes()` (gross → net display) and copied into `Component/Core/Cart/Rule/Applier/CartRuleApplier.php:128,131` and `CartItem/Rule/Applier/CartItemRuleApplier.php:109,112` **(dependent)**.
- **Fix (component side):**
  - Never multiply by `1 + rate/100`. Compute the tax first and add it: `applyTaxes(p) = p + getTaxesAmount(p)`; `removeTaxes(G) = G − getTaxesAmountFromGross(G)`. Then display and cart are consistent by construction (also fixes TODO-2 **CB-01**, **CB-02**).
  - Calculate in integers or decimals instead of floats (rate in basis points / `BcMath\Number`, explicit `RoundingMode`), see TODO-3 **CI-01**.
  - Dependent: replace the copied `1 + getTotalRate() / 100` formulas in the two rule appliers with calculator calls (Core).
