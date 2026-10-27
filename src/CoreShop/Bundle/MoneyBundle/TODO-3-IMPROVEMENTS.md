# MoneyBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Code quality, readability, logic and modernization items. None of them is a functional bug on its own. Items marked **(dependent)** live outside this bundle.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Data type (`CoreExtension/Money.php`)

### I-01 — Remove the duplicated code generators (about 330 of 670 lines)
- **Where:** `CoreExtension/Money.php:221-547`: `getGetterCode`, `getSetterCode`, `getGetterCodeObjectbrick`, `getSetterCodeObjectbrick`, `getGetterCodeFieldcollection`, `getSetterCodeFieldcollection`, `getGetterCodeLocalizedfields`, `getSetterCodeLocalizedfields`.
- **Idea:** they are copies of Pimcore 10 core generators. The only real customisation is the `int`/`?int` type. Since Pimcore 11, `Data` generates typed getters and setters from `TypeDeclarationSupportInterface` (`getParameterTypeDeclaration()`, `getReturnTypeDeclaration()`, which `Money` already implements at `:72-80`). Implement `Data\TypeDeclarationSupportInterface` and `Data\PreGetDataInterface`, delete all eight overrides, and let Pimcore generate the code. That fixes **B-10** and **B-11** automatically and keeps the generated code in line with future Pimcore changes. If some override must stay, extract one private `buildDocBlock()` / `buildSetterBody()` helper instead of eight near-copies.
- **Check first:** rebuild all shipped classes (`CoreShopProduct`, `CoreShopOrder*`, the field collections) and diff the generated files.

### I-02 — Remove dead code in the generators and properties
- **Where:**
  - `:291-299`, `:370-378`, `:442-450`, `:524-532`: `$this instanceof DataObject\ClassDefinition\Data\EncryptedField` is always `false` for `Money`.
  - `:234`, `:309`, `:331`, `:388`, `:411`, `:460`: `method_exists($this, 'preGetData')` is always `true` and `method_exists($this, 'preSetData')` is always `false`, so half of each branch never runs.
  - `:251-253`, `:347-349`, `:419-421`, `:487-489`: generated `EncryptedField` checks in every getter.
  - `:46-50`: `public $phpdocType`. It is not read by Pimcore 11 any more, because `getPhpdocInputType()` / `getPhpdocReturnType()` replace it.
- **Idea:** delete them, or let them disappear with **I-01**.

### I-03 — One conversion helper for minor ↔ major units
- **Where:** `CoreExtension/Money.php:567-587` (`getDataForEditmode`, `getDataFromEditmode`), `:662-669` (`toNumeric`), `Form/Transformer/MoneyToIntegerTransformer.php:30-42`, `Formatter/MoneyFormatter.php:36`, plus `CurrencyBundle/CoreExtension/MoneyCurrency.php` and `OrderBundle/Controller/OrderPaymentController.php:69`.
- **Idea:** a small `final readonly class MoneyConverter` (or static methods on a `MinorUnits` helper) with `toMinor(string|int|float $major): int` and `toMajor(int $minor): string`. It uses `BcMath\Number` (PHP 8.4 object API) or string arithmetic, uses half-up rounding, and throws on overflow. All call sites then round the same way (**B-01**, **B-05**, **B-07** share this root cause), and the `float` issues disappear in one place. `toNumeric()` (`:662-669`, which picks `int` vs `float` by looking for a `.`) is no longer needed.

### I-04 — Wrap the `\Pimcore::getContainer()` parameter lookups and type them
- **Where:** `CoreExtension/Money.php:643-657` (`getDecimalFactor()`, `getDecimalPrecision()`: untyped, phpdoc `@return int`, no cast; a `string` parameter from an env placeholder would be returned as-is).
- **Idea:** `protected function getDecimalFactor(): int { return (int) \Pimcore::getContainer()->getParameter('coreshop.currency.decimal_factor'); }`, or one shared static accessor used by both money data types. The value never changes per request, so cache it in a `static` property. See `CurrencyBundle/TODO-3-IMPROVEMENTS.md` **I-12**.

### I-05 — Type the public properties and accessors of the data type
- **Where:** `CoreExtension/Money.php:35-65`, `:95-168`.
- **Idea:**
  - `public int|string|null $width = null;`, `public ?int $defaultValue = null;`, `public ?float $minValue = null;`, `public ?float $maxValue = null;`, `public bool $nullable = false;`.
  - Native types on the accessors: `getWidth(): int|string|null`, `setWidth(int|string|null $width): static`, `getDefaultValue(): int|float|null`, `setMaxValue(?float $maxValue): void`, `getMinValue(): ?float`, and so on.
  - `getDefaultValue()` (`:115-122`) returns `0` when none is set, so a nullable field with no default can never start as `null` in code that reads the default. It should return `null` and leave the fallback to the caller.
  - Class definitions are exported with `var_export` / `__set_state`, so check the serialised class definition (`var/classes/definition_*.php`) still loads after the property types change.

### I-06 — Implement dirty detection
- **Where:** `CoreExtension/Money.php` (no `supportsDirtyDetection()` / `isEqual()`, so the parent default `false` is used: `pimcore-source/models/DataObject/ClassDefinition/Data.php:1290`).
- **Idea:** implement `Data\EqualComparisonInterface` (`isEqual(mixed $a, mixed $b): bool` → `$a === $b` after normalising `null`) and return `true` from `supportsDirtyDetection()`. Then setters mark only real changes, versions are not created for no-op saves, and the localized setter no longer marks every write dirty (`:538`, `$isEqual = false`).

### I-07 — Default values work only in the admin
- **Where:** `CoreExtension/Money.php:115-136`; JS `…/tags/coreShopMoney.js:136-146`, `:296-302`.
- **Idea:** the default is applied by the ExtJS tag only. Objects created in PHP, imports or the API never get it. Use Pimcore's `Data\Extension\DefaultValueTrait` / `DefaultValueGeneratorInterface` support (as `Numeric` does) so the default is applied in `preGetData` / on create for every channel. The JS `isDirty()` returning `true` whenever a default exists (`:300-302`) can then go: today it marks every object with a defaulted money field as changed as soon as it is opened.

## Formatter / form / Twig

### I-08 — Use attributes and private services instead of YAML tags and `public: true`
- **Where:** `Resources/config/services.yml:4-25`, `Resources/config/services/form.yml:1-9`, `Resources/config/services/data_hub.yml`.
- **Idea:**
  - Remove `_defaults: public: true`. Only the `MoneyFormatterInterface` alias may need to stay public, for legacy `$container->get()` callers. Form types, Twig extensions and event subscribers never need to be public.
  - `#[AsEventListener(event: AdminEvents::INDEX_ACTION_SETTINGS)]` on `IndexActionSettingsSubscriber::onIndexActionSettings()`, and `#[Autowire(param: 'coreshop.currency.decimal_factor')]` on constructor arguments. Then use `_defaults: { autowire: true, autoconfigure: true }` with a `resource:` import, as other modernised bundles do.
  - Twig: since Twig 3.21 / Symfony 7.3, `#[AsTwigFilter('coreshop_format_money')]` on a method replaces the `AbstractExtension` + `getFilters()` boilerplate. At least use a first-class callable: `new TwigFilter('coreshop_format_money', $this->moneyFormatter->format(...))`.
  - `MoneyType` only needs the `form.type` tag through autoconfiguration.

### I-09 — Merge `Bundle`, `Extension` and `Configuration` into one `AbstractBundle`
- **Where:** `CoreShopMoneyBundle.php`, `DependencyInjection/CoreShopMoneyExtension.php`, `DependencyInjection/Configuration.php`.
- **Idea:** `AbstractBundle::configure()` + `loadExtension()` (Symfony ≥ 6.1) replaces three classes for this small bundle. The derived alias is still `core_shop_money`, which `Resources/config/pimcore/admin.yml` uses. Also:
  - `->prototype('scalar')` → `->scalarPrototype()` (`Configuration.php:46,50,54,58`), and extract the four identical `arrayNode` blocks into a loop over `['js', 'css', 'editmode_js', 'editmode_css']`.
  - The `/** @var ArrayNodeDefinition $rootNode */` (`Configuration.php:30`) is not needed with PHPStan 2 + `symfony/config` generics.
- **Dependencies:** the bundle uses `AbstractPimcoreExtension` (PimcoreBundle) and the admin JS helper `coreshop.util.format.number` (PimcoreBundle `helpers.js`), but `CoreShopMoneyBundle` does not declare that it needs `CoreShopPimcoreBundle`. It only works because the bundles that register MoneyBundle (`CoreBundle`, `OrderBundle`, `ProductBundle`, `ShippingBundle`, `TaxationBundle`) also register PimcoreBundle. Implement `Pimcore\HttpKernel\Bundle\DependentBundleInterface::registerDependentBundles()` and register `CoreShopPimcoreBundle` there.

### I-10 — Remove the duplicated parameter defaults from the extension
- **Where:** `DependencyInjection/CoreShopMoneyExtension.php:35-41`; real definition `CurrencyBundle/DependencyInjection/Configuration.php:46-47` + `CoreShopCurrencyExtension.php:52-53`.
- **Idea:** the `hasParameter()` checks look like "do not override the user's value", but extensions are loaded in bundle priority order (MoneyBundle 3600 before CurrencyBundle 2700). So this code always runs first and is then overwritten. The defaults (`100`, `2`) are a second copy that can silently drift from CurrencyBundle's. Either:
  - move the two settings into this bundle's `Configuration` (it is the "money" bundle) and let CurrencyBundle read them, with BC aliases; or
  - make MoneyBundle depend on CurrencyBundle (**I-09**) and drop the fallback.
  In both cases, add the validation from `CurrencyBundle/TODO-2-BUGS.md` **B-13** (`min(1)`, `factor === 10 ** precision`).

### I-11 — `MoneyFormatter`: cache `NumberFormatter` instances and clean up the assertion
- **Where:** `Formatter/MoneyFormatter.php:31-44`.
- **Idea:**
  - A new `\NumberFormatter` (ICU locale load) is built per call. Reports and grid operators call it thousands of times per request (`CoreBundle/Report/ProductsReport.php:172-174` formats three values per row). Cache per `"$locale|$fraction"` in a private array (`ResetInterface` to clear it between worker requests).
  - `sprintf(… gettype($amount) …)` always prints `integer` because the parameter is typed `int`. Replace it with the locale and fraction, which are what actually makes formatting fail. Prefer `throw new \InvalidArgumentException(…)` (the documented interface contract) over `Assert::notSame` with a `false` comparison.
  - Validate `$factor > 0` (a `0` gives `DivisionByZeroError`).
  - `private readonly int $decimalFactor` (also in `MoneyToIntegerTransformer`, `IndexActionSettingsSubscriber`, `FormatMoneyExtension`), and make the non-final classes (`MoneyToIntegerTransformer`, `IndexActionSettingsSubscriber`) `final` where nobody extends them.

### I-12 — `MoneyType` options
- **Where:** `Form/Type/MoneyType.php:41-46`.
- **Idea:** besides `scale` (**B-07**), add an optional `factor` option so a form can override the global factor (as `MoneyFormatter::format()` already allows). `protected int $decimalFactor` → `private readonly int`. `getBlockPrefix()` `coreshop_money` is fine, but it is worth a comment that it is chosen so it does not collide with Symfony's own `money` block.

## Admin JS (dependent)

### I-13 — Clean up the ExtJS tag
- **Where:** `Resources/public/pimcore/js/coreExtension/tags/coreShopMoney.js`.
- **Idea:**
  - `:219` and `:256`: `input.width += input.labelWidth` gives `NaN` when no label width is configured (and in `getLayoutShow()` also when no width is set). Use Pimcore's `this.sumWidths()` as `numeric.js` does.
  - `:285-294`: `isInvalidMandatory()` is no longer called anywhere in `admin-ui-classic-bundle` (grep finds no caller), and it returns the value instead of a boolean. Delete it.
  - `:229`: `getLayoutShow()` does not set `decimalPrecision`, so read-only views round to the ExtJS default of 2 decimals even when `decimal_precision` is 4.
  - `:179`: `console.log(e)` in the renderer. Remove it or use `console.error`.
  - The data type advertises `classificationstore: true` and `block: true` (`…/data/coreShopMoney.js:27-28`), but the PHP side was never tested in either container. Either test both (store/load/grid) or remove the flags.

## Docs / tests / tooling

### I-14 — Add tests
- **Where:** there are no tests for this bundle (`tests/` has no MoneyBundle reference, Behat only uses the formatter through `CurrencyContext`).
- **Idea:**
  - Unit: `MoneyToIntegerTransformer` (round trip, `1.005`, overflow, non-numeric → `TransformationFailedException`), `MoneyFormatter` (half-up, negative values per locale, JPY/KWD, factor ≠ 100), `Money::getDataFromEditmode()` / `getDataForEditmode()` / `checkValidity()` (min/max units, drafts, overflow).
  - Integration (Pimcore kernel): inheritance of a non-nullable money field across parent/variant, including save and reload (**C-01**); a localized non-nullable field without a value (**B-03**); grid round trip (**B-04**).
  - Form: `MoneyType` with `TypeTestCase` and a `de` locale (comma decimal).

### I-15 — Document the unit contract
- **Where:** `README.md` (boilerplate only), `Component/Currency/Formatter/MoneyFormatterInterface.php`, `CoreExtension/Money.php`.
- **Idea:** state in one place that money is stored as `int` minor units (`value × money_decimal_factor`). Say which APIs take or return major units (admin edit mode, `MoneyType` view data, CSV after **B-08**) and which take or return minor units (getters/setters, `MoneyFormatter::format()`, DataHub). Mention the two config keys (`core_shop_currency.money_decimal_factor` / `money_decimal_precision`), that they must agree, and that changing them after go-live needs a data migration.

### I-16 — PHPStan / Psalm leftovers
- **Where:** `CoreExtension/Money.php:26-28` (`@psalm-suppress InvalidReturnType, InvalidReturnStatement, MissingConstructor`), `:205-207` (inline `@var Concrete $object` on an untyped parameter), the DataHub classes (untyped parameters and return types: `DataHub/MutationType/Money.php:26`, `DataHub/QueryType/Money.php:27,41`).
- **Idea:** the project runs PHPStan, not Psalm. Remove the Psalm suppressions and fix what PHPStan 2 reports at the project level instead. Add native parameter and return types to the DataHub generators where the parent `pimcore/data-hub` signatures allow it (check against the data-hub version you install: it is not in this checkout). `preGetData(mixed $container, array $params = []): mixed` comes with **B-03**.
