# MoneyBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this bundle (in `CurrencyBundle`, `PimcoreBundle` or the admin JS), but they come from how the money type, formatter or form type is defined or used. Fix them together with this bundle.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.
Items are roughly ordered by impact. Problems already tracked in `Component/Currency/TODO-2-BUGS.md` **B-08** (minus sign placement, `float` division, fixed `$fraction = 2` in `MoneyFormatter`) are not repeated.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Data type (`CoreExtension/Money.php`)

### B-01 — A large amount entered in the admin overflows `int` silently and is stored as a negative number
- **Where:** `CoreExtension/Money.php:576-587` (`getDataFromEditmode()`: `(int) round(round((float) $data, $precision) * $factor, 0)`), `:607-609` (`$data >= \PHP_INT_MAX`).
- **Problem:**
  - The `(int)` cast of a `float` outside the `int` range is not checked. On PHP 8.4, `(int) round(round(1e17, 2) * 100)` gives `-8446744073709551616`. The admin types a positive amount, and a negative amount is saved without any error.
  - The `>= \PHP_INT_MAX` check in `checkValidity()` cannot catch it: by then the value is already a wrapped `int`, and an `int` can be at most equal to `PHP_INT_MAX`. With `money_decimal_factor: 10000`, the limit is about 9.2 × 10^14 major units.
  - Precision is already lost much earlier: above 2^53 / factor (about 9 × 10^13 at factor 100), the `float` round trip changes the last digits.
- **Fix direction:** parse the edit-mode value as a decimal string (`BcMath\Number` on PHP 8.4, or `bcmul()`), and throw a `ValidationException` (or return `null` plus a validation error) when the result is above `PHP_INT_MAX` / below `PHP_INT_MIN`. Remove the dead `PHP_INT_MAX` comparison. Set `maxValue` on the ExtJS field to the safe limit as well.

### B-02 — `minValue` / `maxValue` are entered in major units but checked against minor units, and range checks are skipped for drafts
- **Where:** `CoreExtension/Money.php:594-619` (`checkValidity()`); JS `Resources/public/pimcore/js/coreExtension/tags/coreShopMoney.js:221-227` (sets `minValue`/`maxValue` on the decimal input, i.e. in major units); class editor `…/data/coreShopMoney.js:84-94`.
- **Problem:**
  - The setter receives minor units (`int`), so `checkValidity()` compares `1234` (= 12.34) with `maxValue: 100`. A limit of 100.00 rejects everything above 1.00, and `minValue: 5` accepts 0.05. The ExtJS field and the server disagree by `decimal_factor`. That is the same defect as `CurrencyBundle/TODO-2-BUGS.md` **B-11** for `MoneyCurrency`.
  - `$this->getMaxValue() > 0` (`:615`) silently ignores `maxValue: 0` and negative maxima, while `minValue` has no such guard.
  - The whole range block runs only when `!$omitMandatoryCheck` (`:604`). Pimcore passes `true` for unpublished saves and auto-save, so out-of-range values are stored without any check. Pimcore's own `Numeric` type checks the range regardless of the mandatory flag.
  - `'invalid numeric data [' . $data . ']'` (`:601`) turns an array or object into an `Array to string conversion` warning, or into an `Error` for an object without `__toString()`, instead of a `ValidationException`.
- **Fix direction:** compare in one unit (`$data / $this->getDecimalFactor()` against the limits, or store the limits multiplied once). Use `null !== $this->getMaxValue()` without the `> 0` guard. Run the range checks whenever data is not empty. Use `get_debug_type($data)` in the message. Fix together with CurrencyBundle **B-11**.

### B-03 — A non-nullable money field inside localized fields returns `null`, so the generated `: int` getter throws a `TypeError`
- **Where:** `CoreExtension/Money.php:472-497` (`getGetterCodeLocalizedfields()`, return type `int` when `!$this->nullable`); `:203-219` (`preGetData()` is a plain method, not `Data\PreGetDataInterface`); `pimcore-source/models/DataObject/Localizedfield.php:486` (`getLocalizedValue()` only calls `preGetData()` for `instanceof PreGetDataInterface`).
- **Problem:** the comment at `:491` ("preGetData … is already managed directly by the localized fields") is wrong for this type. `Localizedfield::getLocalizedValue()` never calls `Money::preGetData()`, so a language without a value returns `null`. With `nullable: false` the generated getter is `…: int` and throws `TypeError: Return value must be of type int, null returned`. That breaks every page and export that reads the field in a language that has not been filled in yet. The JS data type advertises `localizedfield: true` (`…/data/coreShopMoney.js:26`). Object bricks and field collections call `preGetData()` explicitly in the generated code, so they are not affected.
- **Fix direction:** implement `Data\PreGetDataInterface` (signature `preGetData(mixed $container, array $params = []): mixed`). Then the localized and generic paths both apply the non-null fallback. Together with **C-01**, apply the `?? 0` fallback after inheritance in the generated code. Alternatively, emit `return $data ?? 0;` for non-nullable fields in `getGetterCodeLocalizedfields()`.

### B-04 — (dependent) Admin grid: the inline editor is never created, and the grid editor and batch edit would disagree on units
- **Where:** `Resources/public/pimcore/js/coreExtension/tags/coreShopMoney.js:148-168` (`getGridColumnEditor()` returns an editor only when `field.type === "numeric"`, which is never the case for `coreShopMoney`); PHP has no `getDataForGrid()` / `getDataFromGridEditor()`; `admin-ui-classic-bundle/src/Controller/Admin/DataObject/DataObjectActionsTrait.php:257-261` (inline save uses `getDataFromGridEditor()` if present, otherwise the raw value); `DataObjectHelperController.php:1540` (batch edit uses `getDataFromEditmode()`, i.e. major units × factor); `pimcore-source/models/DataObject/Service.php:403-440` (grid data uses `getDataForGrid()` if present, otherwise the raw stored value).
- **Problem:**
  - The type check was copied from Pimcore's `numeric.js`, so money columns can never be edited inline. Users fall back to batch edit.
  - If the check is simply fixed, the grid editor gets the raw minor units (`1234`) while the cell renderer shows `12.34` (`coreshop.util.format.number` divides by the factor). On save, the raw number is written back unchanged. Typing `15` then stores 0.15, while batch edit, which goes through `getDataFromEditmode()`, stores `15` as 15.00. The same input gives two results that differ by the factor.
- **Fix direction:** add `getDataForGrid()` (minor → major, as `getDataForEditmode()` does) and `getDataFromGridEditor()` (major → minor, as `getDataFromEditmode()` does) to `Money`. Then fix the JS condition (`field.type === "coreShopMoney"`) and set `decimalPrecision` on the grid editor. The renderer must then stop dividing (`Ext.util.Format.number` / `Intl.NumberFormat` on the already converted value).

## Formatter / form / Twig

### B-05 — (dependent) `MoneyFormatter` rounds half-to-even (ICU default), so displayed amounts can differ by one cent from the stored and charged amount
- **Where:** `Formatter/MoneyFormatter.php:33-36` (no `ROUNDING_MODE` set); callers that pass a factor above `10^fraction`, e.g. `FrontendBundle/Resources/views/Common/Macro/currency.html.twig:6` (`fraction`, `factor`) or any project with `money_decimal_factor: 10000` and the default `fraction: 2`.
- **Problem:** `NumberFormatter` uses `ROUND_HALFEVEN` by default. Checked on PHP 8.4.27: `formatCurrency(11250 / 10000, 'EUR')` with 2 fraction digits gives `€1.12`, while CoreShop's calculators use `round()` (half up), which gives `1.13`. Invoices, order mails (`CoreBundle/Order/OrderMailProcessor.php:60`) and reports can show a total that is one cent off from the amount actually charged or stored. With the default factor 100 and fraction 2 there is nothing left to round, so default installs only hit this with a smaller `fraction` argument.
- **Fix direction:** `$formatter->setAttribute(\NumberFormatter::ROUNDING_MODE, \NumberFormatter::ROUND_HALFUP)`. Better: round in integer minor units with the same helper the calculators use, and give the formatter an already rounded value. Fix together with `Component/Currency/TODO-2-BUGS.md` **B-08**.

### B-06 — DataHub maps money to GraphQL `Int` (32-bit signed), so larger amounts fail the whole query or mutation
- **Where:** `DataHub/QueryType/Money.php:41-44`, `DataHub/MutationType/Money.php:31-34` (`Type::int()`); column is `bigint(20)` (`CoreExtension/Money.php:180-188`).
- **Problem:** graphql-php rejects integers outside ±2 147 483 647 ("Int cannot represent non 32-bit signed integer value"). At factor 100 that is 21 474 836.47. At factor 10000 it is only 214 748.36, a normal order total for B2B. A query that hits one such value fails as a whole. A mutation with a larger value is rejected, so it cannot be set over the API. Same as `CurrencyBundle/TODO-2-BUGS.md` **B-14** for `MoneyCurrency`.
- **Fix direction:** expose the minor units as `String` (or a custom `BigInt` scalar), or expose a `Float`/`String` decimal in major units, and document which one. Accept the same type in the mutation and convert with the factor. Coordinate with CurrencyBundle B-14 so both money types use the same representation.

### B-07 — `MoneyToIntegerTransformer` rounds differently from the admin and can return an overflowed or negative `int`
- **Where:** `Form/Transformer/MoneyToIntegerTransformer.php:30-42`; consumers: all `MoneyType` fields (`CoreBundle/Form/Type/{Payment,Shipping}/Rule/Action/*`, `CoreBundle/Form/Type/Product/ProductStoreValuesType.php:40`, `CoreBundle/Form/Extension/ProductQuantityRangeTypeExtension.php:38-41`, `OrderBundle/Form/Type/Rule/{Action,Condition}/*`).
- **Problem:**
  - `reverseTransform()` is `(int) round($value * $factor)`, but `Money::getDataFromEditmode()` is `(int) round(round($value, $precision) * $factor)`. For `1.005`, the form gives `100` and the data type gives `101` (checked on PHP 8.4.27). A price-rule amount and a product field can therefore store different values for the same input.
  - No range check: an input above `PHP_INT_MAX / factor` wraps to a negative `int`, the same as **B-01**. `INF`/`NAN` become `0`.
  - Neither method throws `TransformationFailedException`, which the Symfony transformer contract requires. A non-numeric value (e.g. a `string` model value from legacy JSON rule configuration) raises a `TypeError` (500) instead of a form error.
  - `MoneyType` does not set `scale`, so the user can type more decimals than `decimal_precision`, and they are silently rounded away.
- **Fix direction:** use one shared conversion helper (decimal-string based, see **I-03**) for the form, the data type and the controller (`OrderBundle/Controller/OrderPaymentController.php:69` repeats the same `(int) round(… * factor)`). Throw `TransformationFailedException` for non-numeric, non-finite or out-of-range values. Set `'scale' => '%coreshop.currency.decimal_precision%'` by default in `MoneyType::configureOptions()`.

### B-08 — CSV export and version preview show raw minor units
- **Where:** `CoreExtension/Money.php:589-592` (`getVersionPreview()` returns `(string) $data`), `:621-626` (`getForCsvExport()`).
- **Problem:** the version-compare view shows `1234` for 12.34, so editors cannot see what changed. Grid CSV/XLSX export (`pimcore-source/models/DataObject/Service.php:416`) writes `1234`, while the same column in the grid shows `12.34`. Re-importing an export (DataImporter, spreadsheets) is then off by the factor.
- **Fix direction:** return the major-unit value in both, formatted with `decimal_precision` and a `.` decimal separator (`number_format($data / $factor, $precision, '.', '')`), or use the same helper as `getDataForEditmode()`. Note the change in the upgrade notes, because existing export consumers may rely on minor units.

## Compatibility / config / packaging

### B-09 — `setWidth()` uses `Data::getAsIntegerCast()`, which is deprecated since Pimcore 11.2 and removed in Pimcore 12
- **Where:** `CoreExtension/Money.php:105-110`; parent `../pimcore-source/models/DataObject/ClassDefinition/Data.php:1004-1011` (`trigger_deprecation(… '11.2' …)`).
- **Problem:** every class-definition load (each object save, class rebuild, `pimcore:deployment:classes-rebuild`) logs a deprecation. Upgrading to Pimcore 12 makes this a fatal `Error` (undefined method) during class rebuild. Pimcore also stores widths as `"100%"` or `"350px"` strings, and the cast drops them.
- **Fix direction:** `$this->width = is_numeric($width) ? (int) $width : $width;` and type the property `int|string|null`, as Pimcore's own data types do. Fix together with `CurrencyBundle/TODO-2-BUGS.md` **B-12**.

### B-10 — `getSetterCode()` writes a wrong `@return` type for class-level fields, and `Fieldcollection` is spelled with the wrong case
- **Where:** `CoreExtension/Money.php:263-278`.
- **Problem:** for a `ClassDefinition`, the generated phpdoc says `@return \Pimcore\Model\DataObject\FieldCollection\Data\<ClassName>` instead of `\Pimcore\Model\DataObject\<ClassName>`. PHPStan/IDE type inference on fluent setter chains (`$product->setWholesalePrice(…)->setFoo(…)`) is wrong for every generated class. The namespace is `Fieldcollection`, not `FieldCollection` (same at `:437`, `:507`). PHP does not care about the case, but static analysis reports it. The `switch ($class::class)` also falls through to `mixed` for any subclass.
- **Fix direction:** generate a native `: static` return type, as Pimcore core does (`pimcore-source/models/DataObject/ClassDefinition/Data.php:555`), and drop the hand-written `@return`. Use `instanceof` instead of comparing `::class`.

### B-11 — Generated setters have no `: static` return type, and the localized setter has an untyped `$language`
- **Where:** `CoreExtension/Money.php:288`, `:367`, `:439`, `:519` (`public function setX (?int $x) {` / `…, $language = null) {`).
- **Problem:** Pimcore 11 generates `: static` for all core setters. Generated classes that mix core and CoreShop fields have inconsistent signatures. A project that overrides the setter in a subclass (`setWholesalePrice(?int $v): static`) and then rebuilds classes gets a covariance mismatch only for money fields. `$language` should be `?string` (the getter already uses `?string $language = null`, `:479`).
- **Fix direction:** emit `): static {` and `?string $language = null` in all four setter generators. This is done naturally once the generators share one helper (**I-01**).

### B-12 — `IndexActionSettingsSubscriber::getSubscribedEvents()` has no `array` return type
- **Where:** `EventListener/IndexActionSettingsSubscriber.php:33`.
- **Problem:** Symfony's `DebugClassLoader` reports `Method "EventSubscriberInterface::getSubscribedEvents()" might add "array" as a native return type declaration in the future` on every container build in `dev`/`test`. It also makes the deprecation log noisy for the Symfony 8 upgrade check.
- **Fix direction:** `public static function getSubscribedEvents(): array`, or switch to `#[AsEventListener]` (**I-08**).

### B-13 — `composer.json` does not declare what the bundle actually uses
- **Where:** `composer.json:26-36`.
- **Problem:**
  - No `"php"` constraint (the root requires `~8.4.0`).
  - Undeclared: `ext-intl` (`\NumberFormatter` in `Formatter/MoneyFormatter.php:33`); `twig/twig` (`Twig/FormatMoneyExtension.php`); `six-it/admin-ui-classic-bundle` (`Pimcore\Bundle\AdminBundle\Event\*` in `EventListener/IndexActionSettingsSubscriber.php:21-22`, and the ExtJS data type); `webmozart/assert` (`MoneyFormatter.php:22`); `symfony/event-dispatcher`, `symfony/options-resolver`, `symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel` (all used directly).
  - `pimcore/data-hub` is optional (`CoreShopMoneyExtension.php:45`) but is not listed under `suggest`.
  - `six-it/pimcore-source: ^2.0`, but the root requires `^2.1`.
  - `require-dev` is PHPStan 1.x (`^1.10`), while the root uses PHPStan `^2.1`. The two cannot be installed together in a split-package checkout.
- **Fix direction:** add `"php": "~8.4.0"`, `ext-intl`, `twig/twig: ^3.21`, `six-it/admin-ui-classic-bundle: ^2.0`, `webmozart/assert`, the Symfony components at `^7.4`, `suggest: {"pimcore/data-hub": …}`, align `six-it/pimcore-source` to `^2.1`, and bump `phpstan/*` to `^2`.

### B-14 — (dependent) Two sources for the same decimal settings in the admin
- **Where:** `EventListener/IndexActionSettingsSubscriber.php:40-48` (`pimcore.settings.coreshop_money.*`, used by `…/tags/coreShopMoney.js:229`); `CurrencyBundle/Controller/CurrencyController.php:27-35` + `CurrencyBundle/Resources/public/pimcore/js/resource.js:31-32` (`pimcore.globalmanager` `coreshop.currency.decimal_*`, used by `PimcoreBundle/Resources/public/pimcore/js/helpers.js:44-77` and the grid renderer of this type, `…/tags/coreShopMoney.js:182`).
- **Problem:** the edit field uses `pimcore.settings`, and the grid renderer uses the global manager, which is filled by an asynchronous request. When a grid renders before `resource.js` has loaded the settings, `decimal_factor` is `undefined`, and `coreshop.util.format.number` shows `NaN` for every money cell. MoneyBundle can also be installed without CurrencyBundle (it only requires the currency *component*), and then the global manager is never filled at all.
- **Fix direction:** use one source. `pimcore.settings.coreshop_money` is available synchronously at admin start, so make `helpers.js` read it (fall back to the global manager for BC), and let `CurrencyController` return the same values from the same parameters.

### B-15 — `getDataForEditmode()` divides non-numeric data and `getDataFromEditmode()` silently turns locale-formatted input into `0`
- **Where:** `CoreExtension/Money.php:567-587`.
- **Problem:**
  - `getDataFromResource()` (`:549-560`) passes non-numeric, non-null values through unchanged. `getDataForEditmode()` then evaluates `round($data / $factor, …)`, which on PHP 8 throws `TypeError: Unsupported operand types: string / int` for a legacy or corrupted value (e.g. `''` written by an import into a `NULL`-able column). The object can no longer be opened in the admin.
  - `getDataFromEditmode('1,5')` (comma decimal from a batch-edit text field or an API client in a German locale) is not numeric, so it is returned as is. `getDataForResource()` then stores `0` without any validation error.
- **Fix direction:** in `getDataForEditmode()`, return `null`/`0` for non-numeric data (and log it). In `getDataFromEditmode()`, normalise a single `,` decimal separator or throw a `ValidationException` for non-numeric input instead of storing `0`.
