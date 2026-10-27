# MoneyBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (`CoreShopMoneyBundle.php`, `CoreExtension/Money.php`, `DataHub/{MutationType,QueryType}/Money.php`, `DependencyInjection/{Configuration,CoreShopMoneyExtension}.php`, `EventListener/IndexActionSettingsSubscriber.php`, `Formatter/MoneyFormatter.php`, `Form/Transformer/MoneyToIntegerTransformer.php`, `Form/Type/MoneyType.php`, `Twig/FormatMoneyExtension.php`), `Resources/config/{services.yml,services/form.yml,services/data_hub.yml,pimcore/config.yml,pimcore/admin.yml}`, `composer.json`, `README.md`, and the admin JS of the data type (`Resources/public/pimcore/js/coreExtension/{data,tags}/coreShopMoney.js`) where it defines what the PHP side receives. Dependent code and config:
`Component/Currency/Formatter/MoneyFormatterInterface.php`, `CurrencyBundle/DependencyInjection/{Configuration,CoreShopCurrencyExtension}.php` (they define `coreshop.currency.decimal_factor` / `decimal_precision`), `CurrencyBundle/Controller/CurrencyController.php`, `PimcoreBundle/DependencyInjection/Extension/AbstractPimcoreExtension.php`, `PimcoreBundle/Resources/public/pimcore/js/helpers.js` (`coreshop.util.format.number`), the `MoneyType` form consumers in `CoreBundle/Form/**` and `OrderBundle/Form/Type/Rule/**`, the `MoneyFormatterInterface` consumers (`CoreBundle/Report/*Report.php`, `CoreBundle/Order/OrderMailProcessor.php`, `CoreBundle/Pimcore/GridColumnConfig/Operator/StorePrice.php`, `OrderBundle/Pimcore/GridColumnConfig/Operator/PriceFormatter.php`, `Behat/Context/Domain/CurrencyContext.php`), the `coreshop_format_money` Twig users (`OrderBundle/Resources/views/**`, `FrontendBundle/Resources/views/Common/Macro/currency.html.twig`), and the class definitions that use `coreShopMoney` (`CoreBundle`, `ProductBundle`, `OrderBundle`, `TaxationBundle` `Resources/install/pimcore/**`).
Pimcore behaviour was checked against the sources this project installs: `../pimcore-source` (`six-it/pimcore-source`) and `../admin-ui-classic-bundle` (`six-it/admin-ui-classic-bundle`). `pimcore/data-hub` is not installed locally, so the DataHub items are based on the code in this bundle only.

"Critical" here means: a security issue, money charged or stored wrongly, data loss or data written wrongly, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers. All 10 PHP files pass `php -l` on PHP 8.4.27. There are no implicit nullable parameters and no dynamic properties (all properties of `CoreExtension/Money.php` are declared). No removed Symfony API is used: the extension extends `Symfony\Component\DependencyInjection\Extension\Extension` through `AbstractPimcoreExtension`. The soft issues are: the Pimcore 11.2 deprecation `getAsIntegerCast()` (removed in Pimcore 12, **B-09**), the missing native return type on `getSubscribedEvents()` (Symfony's `DebugClassLoader` reports it, **B-12**), and the packaging gaps in `composer.json` (no `php` constraint, undeclared `ext-intl` / Twig / admin-ui / webmozart dependencies, PHPStan 1.x, **B-13**). Missing PHP 8.4 / Symfony 7.4 idioms are in TODO-3.

**Overlap with other TODOs:**
- `Component/Currency/TODO-2-BUGS.md` **B-08** already covers three `MoneyFormatter` problems: the minus sign is placed before the formatted string, the division is done in `float`, and the fixed `$fraction = 2` default. They are **not repeated** here. This bundle only adds the rounding-mode problem (**B-05**). Fix them together.
- `CurrencyBundle/TODO-2-BUGS.md` **B-13** (validate `money_decimal_factor` / `money_decimal_precision`) is where the parameters this bundle reads are defined. This bundle's own fallback defaults are **I-10**.
- `CurrencyBundle/TODO-2-BUGS.md` **B-11** (min/max compared in the wrong unit), **B-12** (`getAsIntegerCast()`) and **B-14** (GraphQL `Int` is 32-bit) describe the same defects in `MoneyCurrency`. The `Money` versions here are **B-02**, **B-09** and **B-06**. `CurrencyBundle/TODO-3-IMPROVEMENTS.md` **I-12** (wrap the `\Pimcore::getContainer()` lookups) applies to `Money` too (**I-04**).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## C-01 — A non-nullable `coreShopMoney` field (the default) never inherits from its parent object, and the first save of a variant writes `0` over the inherited value

- **Where:**
  - `CoreExtension/Money.php:65`: `$nullable = false` is the default, and the class editor (`coreShopMoney.js` data type) leaves it unchecked.
  - `:549-560` `getDataFromResource()`: a `NULL` column (the variant has no own value) becomes `0`.
  - `:203-219` `preGetData()`: `null` or a non-`int` becomes `0`.
  - `:638-641` `isEmpty()`: only `null` and `''` count as empty, so `0` is a real value.
  - Generated getters: `:241-249` (class) and `:337-345` (object brick). They call `preGetData()` first and only then ask `isEmpty($data)`, so the parent lookup never runs.
  - Shipped example: `CoreBundle/Resources/install/pimcore/classes/CoreShopProductBundle/CoreShopProduct.json` has `"allowInherit": true`, `"allowVariants": true` and `wholesalePrice` as a `coreShopMoney` field with `nullable: false`. Any project money field on a product (or another inheritable class) behaves the same way.
- **Problem:**
  1. **Frontend / API:** for a variant that has no own value, `$variant->getWholesalePrice()` returns `0` instead of the parent's value. `DataObject::doGetInheritedValues()` is on, but `isEmpty(0)` is `false`, so `getValueFromParent()` is never called.
  2. **Admin:** `admin-ui-classic-bundle/src/Controller/Admin/DataObject/DataObjectController.php:726` decides inheritance with `$fielddefinition->isEmpty($fieldData)`. The variant shows `0.00` in an editable field instead of the greyed-out parent value.
  3. **Data written wrongly:** `pimcore-source/models/DataObject/Concrete/Dao.php:245` saves every field as `getDataForResource($this->model->$getter())`, with inherited values turned off. The first save of the variant, even for an unrelated field, writes `0` into the store table. From then on the variant has its own value of `0`, and changing the parent never reaches it again.
  4. **Query table / listings:** `Concrete/Dao.php:294` (`$isEmpty = $fd->isEmpty($fieldValue)`) and `Concrete/Dao/InheritanceHelper.php:514,530` use the same check. The parent's value is not copied into the children's query-table rows, so listing filters and sorting on the field (`wholesalePrice > …`) work on `0` for every variant.
- **Impact:** money values are silently wrong for every variant in the frontend, API and listings. They are then written to the database as `0`, which is data loss: the inheritance link is gone and has to be repaired by hand per variant.
- **Fix direction:**
  - Keep `null` as `null` internally: `getDataFromResource()` and `preGetData()` return `null` for a missing value. The `0` fallback for non-nullable fields belongs at the end of the generated getter, *after* the inheritance block: `return $data ?? 0;` when `!$this->nullable`. This is how Pimcore's own `Numeric` type handles it.
  - Make `Money` implement `Data\PreGetDataInterface` (also fixes **B-03**) and use the generated code path that checks `instanceof PreGetDataInterface`, as `pimcore-source/models/DataObject/ClassDefinition/Data.php:510` does.
  - Ship a repair command or migration: for every class with `allowInherit` and a `coreShopMoney` field, set the variant's store column back to `NULL` where the value is `0` and the parent's value is not `0`. Then rebuild the query table (`InheritanceHelper`). Let the admin confirm before it runs, because a real `0` cannot be told apart from the corrupted one.
  - Add a test: a parent with a value and a variant without one, then `getX()`, save the variant, reload, `getX()` again (**I-14**).
