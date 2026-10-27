# TaxationBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (15 files: `CoreShopTaxationBundle.php`, `Controller/*`, `CoreExtension/*`, `DependencyInjection/*`, `Doctrine/ORM/*`, `Form/Type/*`), `composer.json`, all of `Resources/config/**` (`services.yml`, `services/{form,pimcore,data_hub}.yml`, `pimcore/{config,admin,routing}.yml`, `doctrine/model/*.orm.xml`, `serializer/Model.*.yml`, `validation/*.yml`), `Resources/install/pimcore/fieldcollections/CoreShopTaxItem.json` and the admin JS in `Resources/public/pimcore/js/**` where it defines what the forms receive. The runtime logic the bundle wires lives in `Component/Taxation`, so every class of that component was read (`Calculator/*`, `Collector/*`, `Model/*`, `Repository/*`, `composer.json`). Call paths were also followed into `Component/Core` (`Taxation/TaxCalculatorFactory.php`, `Order/Processor/CartItemProcessor.php`), `CoreBundle` (`Doctrine/ORM/TaxRuleRepository.php`, `Form/Extension/TaxRuleTypeExtension.php`, `Resources/config/pimcore/coreshop/coreshop_taxation.yml`, `Resources/config/doctrine/model/{Carrier,ProductStoreValues}.orm.xml`, `Resources/public/pimcore/js/taxation/taxrulegroup/item.js`) and `ResourceBundle` (`Controller/{ResourceController,AdminController,EventDispatcher}.php`, `CoreExtension/Select.php`, `Form/Type/AbstractResourceType.php`, `EventListener/ORMTranslatableListener.php`, `Component/Resource/Model/TranslatableTrait.php`).

Reference for API checks: `vendor/` (`symfony/*` 7.4.21, `six-it/pimcore-source`), the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml` (+ its `…Deprecations.log`). The vendored copy `vendor/six-it/core-shop/src/CoreShop/{Bundle/TaxationBundle,Component/Taxation}` is identical to this repo.

"Critical" here means: a security issue, money charged wrongly / data written wrongly, or an error that takes down every request, a whole area (cart, checkout, admin section), or the install / container build once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code (PHP 8.4.27 with the project autoloader) or by reading the vendored library source / the compiled container.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers. Every class of the bundle and the component loads under PHP 8.4.27; every service resolves in the compiled container (`coreshop.admin_controller.tax_rate`, `coreshop.admin_controller.tax_rule_group` incl. the `listRulesAction()` argument locator, `TaxCollector`, the repositories / factories of all four resources and the `tax_item` Pimcore model); the container deprecation log has no entry for this bundle or `Component/Taxation`. No implicitly nullable parameters. Modernisation is in TODO-3 (**I-01** to **I-05**, **I-07**, **I-11**).

Already covered elsewhere and therefore not repeated here: `TaxCalculatorFactory` ignores the `active` flags of rates and groups and depends on row order (`Component/Core/TODO-2-BUGS.md` **B-11**), `CachedTaxCalculatorFactory` never resets (**B-12** there), `CartItemProcessor` looks every rate up again (`Component/Core/TODO-3-IMPROVEMENTS.md` **I-11**).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Wrong tax amounts

### C-01 — Gross-price stores with two or more "combine" tax rates record too much tax and too little net — (verified)
- **Where:**
  - `Component/Taxation/Calculator/TaxRulesTaxCalculator.php:75-89`: the `COMBINE_METHOD` branch (`:84`) computes each rate as `G − G / (1 + r_i)`, i.e. as if that rate were the **only** tax in the gross price.
  - Consumers: `Component/Core/Order/Processor/CartItemProcessor.php:71-76` (every amount of every cart item in a store with `useGrossPrice = true`), `Component/Taxation/Collector/TaxCollector.php:46-55` (`collectTaxesFromGross()`).
- **Reproduced:** rates 10 % + 15 % combined, net 100.00 → `applyTaxes()` = 125.00 (correct). From that gross:
  - `getTaxesAmountFromGrossAsArray(12500)` = `{1: 1136, 2: 1630}` → tax **27.66** instead of 25.00 (correct split `{1: 1000, 2: 1500}`).
  - `CartItemProcessor` then stores net subtotal / item price as 125.00 − 27.66 = **97.34** instead of 100.00.
  - `removeTaxes(12500)` correctly returns 10000, so the calculator contradicts itself.
- **Impact:** every gross-price shop that uses a rule group with more than one combined rate for a country (Canada GST + PST/QST, US state + county/city, any "base + local" setup) writes wrong net amounts and wrong per-rate tax lines into carts, orders, invoices and tax reports. The customer pays the right gross amount, but the shop declares too much tax and books too little revenue. Single-rate groups (most EU VAT setups) are not affected.
- **Fix:** derive the gross split from the net value instead of treating each rate on its own: `net = removeTaxes(G)`, then `getTaxesAmountAsArray(net)` and put the rounding difference `(G − net) − Σ` on the last (or largest) rate so that Σ tax lines = `G − net` exactly. This also fixes **C-02**. Add unit tests for both methods with 1, 2 and 3 rates and with cent-rounding edge cases (the component has no tests today, see TODO-3 **I-08**).

### C-02 — Gross-price stores with "one after another" rates swap the per-rate tax amounts — (verified)
- **Where:** `Component/Taxation/Calculator/TaxRulesTaxCalculator.php:80-82`.
- **Problem:** in compound mode the gross price is `N·(1+a)·(1+b)`; the last applied rate has to be removed first. The loop removes the rates in the order they were applied, so the first rate is computed on a base that still contains the second rate, and vice versa.
- **Reproduced:** 10 % then 15 %, net 100.00 → gross 126.50. Net mode (`getTaxesAmountAsArray(10000)`) gives `{1: 1000, 2: 1650}`; gross mode (`getTaxesAmountFromGrossAsArray(12650)`) gives `{1: 1150, 2: 1500}`. The sum (26.50) is correct, the split is not.
- **Impact:** the stored `CoreShopTaxItem` lines per rate (orders, invoices, tax reports per rate, e.g. Québec QST on top of GST) are wrong in every gross-price store that uses this behaviour. Net-price stores are correct, so the same cart gives different tax lines depending on the store type.
- **Fix:** same as **C-01** (compute from net), or iterate the rates in reverse order for the gross computation and return the result in the original order.

---

## Admin actions that silently change taxation

### C-03 — Deleting a tax rate removes it from every tax rule group without a warning; deleting a tax rule group makes carriers tax-free or fails with a raw 500 — (verified code path) (plausible: storefront impact) (dependent)
- **Where:**
  - `Resources/config/doctrine/model/TaxRule.orm.xml:24-26`: `taxRateId` has `on-delete="CASCADE"`.
  - `Controller/TaxRuleGroupController.php` / `coreshop.admin_controller.tax_rate` inherit `ResourceBundle/Controller/ResourceController.php:207-227` (`deleteAction()`), which removes the resource without any check. There is no `coreshop.tax_rate.pre_delete` / `coreshop.tax_rule_group.pre_delete` listener anywhere in the code base, and `ResourceBundle/Controller/EventDispatcher.php:44-52` has no way to stop a pre-event (a listener would have to throw).
  - **(dependent)** `CoreBundle/Resources/config/doctrine/model/Carrier.orm.xml:16-17`: `taxRuleGroupId` `on-delete="SET NULL"`. `CoreBundle/Resources/config/doctrine/model/ProductStoreValues.orm.xml:18-19`: `taxRuleId` has no `on-delete` (database default `RESTRICT`).
  - Pimcore fields of type `coreShopTaxRate` / `coreShopTaxRuleGroup` (`CoreExtension/*`, e.g. `CoreShopTaxItem.json:103` `taxRate`) only store the id; `ResourceBundle/CoreExtension/Select.php` returns `null` for an id that no longer exists.
- **Impact:**
  - Deleting a tax rate (the admin lists it like any other resource) deletes, at database level, every `coreshop_tax_rule` row that uses it, in every group. From the next price calculation on, every product and carrier using those groups is charged **without** that rate. Doctrine does not know about the cascade, so no event fires and nothing is logged. Open carts are recalculated with the lower tax.
  - Deleting a tax rule group that is used by a carrier sets the carrier's tax rule to `NULL`: shipping is charged without VAT from then on, again silently.
  - Deleting a tax rule group that is used by any product store value fails with `ForeignKeyConstraintViolationException`; the admin gets an unhandled 500 instead of "still in use".
  - Order tax items keep name / rate / amount, but their `taxRate` link becomes `null`.
- **Fix:**
  - Add pre-delete listeners for `tax_rate` and `tax_rule_group` that count references (tax rules; product store values; carriers; optionally tax items) and throw a translated, user-facing exception that the admin JS shows (the base controller has to turn it into `success: false, message`, see ResourceBundle).
  - Change `TaxRule.taxRate` to `on-delete="RESTRICT"` (+ migration) so the database cannot silently drop rules, and `Carrier.taxRule` to `RESTRICT` as well.
  - Point admins to "deactivate" instead of "delete" once the `active` flag is honoured (`Component/Core/TODO-2-BUGS.md` **B-11**).
