# CurrencyBundle – TODO 3: Improvements (code quality, readability, logic, PHP 8.4 / Symfony 7.4 idioms)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
None of these are defects on their own. They make the bundle cleaner, safer, easier to test, or more idiomatic for the target stack.
Paths are relative to the bundle root unless noted otherwise.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 language features

### I-01 — Native types on the models and their interfaces (`Component/Currency/Model`)
- **Where:** `Currency.php`, `CurrencyInterface.php`, `ExchangeRate.php`, `ExchangeRateInterface.php`, `CurrencyAwareInterface.php`, `CurrencyAwareTrait.php`; also `Component/Core/Model/Currency{,Interface}.php`
- The properties are untyped (`protected $id; $name; $isoCode; …`), and the accessors rely on docblocks (`@return string`, `@param float`). Use `protected ?string $isoCode = null;`, `getIsoCode(): ?string`, `setIsoCode(string $isoCode): void`, and so on. Choose nullability together with B-07.
- The setters return values inconsistently: `Currency::setId()` and `setSymbol()` return `$this`, the other setters return nothing, and `CurrencyAwareTrait::setCurrency()` has no return type. Pick `void` (or `static`) everywhere.
- `ExchangeRate::$exchangeRate` should be `?string` (Doctrine `decimal`), or a value object, instead of the documented `float` (see B-05 and B-06).
- Optionally use asymmetric visibility for identifiers: `public protected(set) ?int $id = null;`. Doctrine hydrates through reflection.
- Drop the `@psalm-suppress MissingConstructor` once the properties have defaults.
- This is a BC break for implementors. Plan it for a minor/major version and note it in UPGRADE.

### I-02 — Add `#[\Override]` everywhere a parent or interface method is implemented
- **Where:** `CoreShopCurrencyBundle.php` (`getSupportedDrivers`, `build`, `getModelNamespace`), `DependencyInjection/*` (`getConfigTreeBuilder`, `load`), `Form/Type/*` (`buildForm`, `configureOptions`, `getParent`, `getBlockPrefix`), `Doctrine/ORM/*`, `Controller/ExchangeRateController.php` (`saveAction`), `CoreExtension/*` (all `Data` overrides), `DataHub/QueryType/MoneyCurrency.php`, `Twig/*` (`getFilters`, `getFunctions`), and `Component/Currency/**` (contexts, converter, display provider)
- PHP 8.3+ then reports signature drift at compile time. That matters most for `MoneyCurrency`, which overrides about 25 methods of a Pimcore class that changes between minors.

### I-03 — Constructor promotion and `readonly` everywhere
- `DataHub/QueryType/MoneyCurrency.php:31-44`: promote `DoctrineProvider $doctrineProvider` and `string $currencyClass` (`private readonly`).
- `Attribute/AsCurrencyContext.php:24-32`: `public readonly ?int $priority = null` is enough. Keep `getPriority()` because `Autoconfiguration::getProperties()` reads the `get*` methods, or change that helper to read public properties.
- Mark promoted dependencies `readonly` in `Form/Type/CurrencyChoiceType.php`, `Twig/ConvertCurrencyExtension.php`, `Twig/DisplayFractionExtension.php`, `Component/Currency/Converter/CurrencyConverter.php`, `Component/Currency/Context/CachedCurrencyContext.php` (`$inner`, `$requestStack`).

### I-04 — Remove the odd empty-constructor formatting left over from an automated refactor
- **Where:** `DependencyInjection/Compiler/CompositeCurrencyContextPass.php:29-30`, `Component/Currency/Context/CompositeCurrencyContext.php:33-34`, `Component/Currency/Model/ExchangeRate.php:52-53`, `Component/Core/Model/Currency.php`
- `public function __construct(\n        ) {` should be `public function __construct()`. Run php-cs-fixer (`method_argument_space`, `no_spaces_inside_parenthesis`) over the bundle.

### I-05 — Type `CurrencyNotFoundException` properly
- **Where:** `Component/Currency/Context/CurrencyNotFoundException.php:23-56`
- `$message = null` → `?string $message = null`, and `?\Exception $previousException` → `?\Throwable $previous`. As it stands, a `TypeError`/`Error` from an inner context cannot be chained.
- Type the named constructors: `notFound(string $currencyCode)`, `disabled(string …)`, `notAvailable(string …, array …)`. Replace `$message ?: '…'` with `$message ?? '…'`.

### I-06 — Clean up `CoreExtension/MoneyCurrency.php`
- Type the accessors: `getWidth(): ?int`, `setWidth(mixed $width): static`, `setMinValue(?float): void`, `getMinValue(): ?float`, and the same for max. Remove the docblocks that only repeat types.
- Use the imported `Money` instead of `\CoreShop\Component\Currency\Model\Money` (`:157,180,194,215`).
- Remove `getEntityManager()` (`:346-349`): it is unused.
- `toNumeric(): float|int` always returns `int`, so declare `: int`. `getDecimalFactor()` needs `: int`.
- Extract `$this->getName() . '__value'` / `'__currency'` into two private helpers. The concatenation appears 6 times, and `getDataFromResource()` computes `$currencyIndex` and then builds the same key again (`:174-177`).
- `getQueryColumnType()` and `getColumnType()` return identical arrays. Make one delegate to the other.
- `getDataForEditmode()`: return `round($value / $factor, $precision)` (as `MoneyBundle\CoreExtension\Money` does) so the UI does not receive `12.300000000000001`.

### I-07 — Use `BcMath\Number` (PHP 8.4) for exchange-rate arithmetic
- **Where:** `Component/Currency/Converter/CurrencyConverter.php:48,51`
- PHP 8.4 ships the object API `BcMath\Number` with operator overloading. Use `(new Number((string) $value) * new Number($rate->getExchangeRate()))->round(0, RoundingMode::HalfEven)`, and the same for division. That gives exact decimal math on the `DECIMAL` string Doctrine already returns, with no `float` round-trip. `RoundingMode` is also new in PHP 8.4.
- Pair it with B-06 (wider column). Make the rounding mode configurable (currently fixed to PHP's default half-up via `round()`, while the form uses `ROUND_HALFEVEN`).

## Symfony 7.4 idioms and DI

### I-08 — Make services private and autowired; stop declaring everything `public: true`
- **Where:** `Resources/config/services.yml:6-8`, `services/form.yml:5-7`, `services/pimcore.yml:1-3`
- Form types, the converter and the contexts do not need to be public. Only ids fetched through `\Pimcore::getContainer()->get()` must stay public (`coreshop.repository.currency` comes from ResourceBundle). Making the rest private allows dead-service removal and catches typos at compile time.
- Use `_defaults: { autowire: true, autoconfigure: true }`. The explicit `arguments:` blocks (`form.yml:9-27`, `services.yml:11-14`, `twig.yml`) then mostly disappear. Keep explicit arguments only where a parameter is injected.
- `services/data_hub.yml`: the three definitions differ only in class and tag id. Use a YAML anchor, or register them in the extension in a loop.

### I-09 — Use Twig attribute-based extensions (`#[AsTwigFilter]`, `#[AsTwigFunction]`)
- **Where:** `Twig/*.php`, `Resources/config/services/twig.yml`
- Twig 3.21 and TwigBundle 7.3+ support attribute-based extensions with lazy loading. Each class becomes a plain service with an attributed method, and `getFilters()` and the `twig.extension` tags go away. The runtime is only instantiated when the filter is used. Before switching, check the root `conflict` (`twig/twig: >=3.9.0 <3.14.0`) and the installed Twig version.
- Alternatively, split into `AbstractExtension` plus `RuntimeExtensionInterface` (`[ConvertCurrencyRuntime::class, 'convertAmount']`), so that `CurrencyConverter` and the repositories are not built for every template render.

### I-10 — Clean up the config tree and the extension
- **Where:** `DependencyInjection/Configuration.php`, `DependencyInjection/CoreShopCurrencyExtension.php`
- `:134-137`: `scalarNode('permissions')->defaultValue([...])` is an array default on a scalar node, which only works because it can never be overwritten. Use `arrayNode('permissions')->scalarPrototype()->end()->defaultValue([...])`.
- `:120,124,128,132`: `->prototype('scalar')` → `->scalarPrototype()`.
- Add `->info()` to `money_decimal_factor`, `money_decimal_precision` and `autoconfigure_with_attributes` (see B-13 for the validation part).
- `CoreShopCurrencyExtension.php:40-42`: `array_key_exists('pimcore_admin', $configs)` is always `true`, because the node has `addDefaultsIfNotSet()`. Drop the condition.
- `:44-48`: check `class_exists(PimcoreDataHubBundle::class)` together with `kernel.bundles`, or use `ContainerBuilder::willBeAvailable()`.
- `:35`: rename `$configs` to `$config` after processing. The variable holds the processed config, not the raw config list.

### I-11 — Base `CurrencyChoiceType` on `EntityType` (or at least make it sortable and lazy)
- **Where:** `Form/Type/CurrencyChoiceType.php:43-55`
- `EntityType` with `class: '%coreshop.model.currency.class%'` handles `choice_value`, multiple and collection transforms (`CollectionToArrayTransformer`), and query builders out of the box. It also allows `query_builder: fn (CurrencyRepository $r) => $r->createListQueryBuilder()->orderBy('o.name')`.
- If it stays a `ChoiceType`: use `static fn (): array => …` (the `Options $options` argument is unused), order the result by name, and use `ChoiceList::lazy()` so the query only runs when the field is rendered.

### I-12 — Wrap the `\Pimcore::getContainer()` service-locator calls in the data types
- **Where:** `CoreExtension/Currency.php:36-44`, `CoreExtension/MoneyCurrency.php:338-354`
- Pimcore instantiates data types itself, so DI is not available. Still, put the lookups in one small static accessor (or a `DataTypeServices` locator service with typed getters), so that `coreshop.repository.currency`, `coreshop.model.currency.class` and `coreshop.currency.decimal_factor` are referenced in one place, and tests can swap them.
- Add return types: `getRepository(): CurrencyRepositoryInterface`, `getCurrencyById(int|string $id): ?CurrencyInterface`.

### I-13 — Small DI details
- `CompositeCurrencyContextPass.php:27`: `final public const string CURRENCY_CONTEXT_SERVICE_TAG = …` (typed class constants, PHP 8.3).
- `CoreShopCurrencyBundle.php:44`: `'CoreShop\Component\Currency\Model'` → build it from `Currency::class`, or declare it as a typed constant.
- `services.yml:16-18`: `CompositeCurrencyContext` is `public: false`, while everything else in the file is public. Making everything private (I-08) removes the special case.

## Controllers and security

### I-14 — Remove the copy of `saveAction()` in `ExchangeRateController`
- **Where:** `Controller/ExchangeRateController.php:28-58` vs `ResourceBundle/Controller/ResourceController.php:100-135`
- The override exists only to create the entity when it is missing. It drifted from the base: no permission check (C-01), no `manager->clear()` and re-fetch, and an inconsistent `getId()` check. Options:
  - In `exchangeRate/panel.js`, call `coreshop_exchange_rate_add` for phantom records and `save` for existing ones. Then delete the controller and point `admin_controller` back to `ResourceController`.
  - Or add a protected `findOrCreate()` hook to `ResourceController` and override only that.

### I-15 — Guard `getConfigAction()` and the list endpoints
- **Where:** `Controller/CurrencyController.php:27-35`; `ResourceBundle/Controller/ResourceController.php:80-89` (`listAction` has no `isGrantedOr403()`, shared with all resources)
- The data is low-sensitivity (decimal settings, currency and rate lists), but every other action is permission-checked. Add `isGrantedOr403()`, or a dedicated admin firewall rule, for consistency. Coordinate the `listAction` change across bundles.
- Return a `JsonResponse` type (`: JsonResponse`) and drop the unused `Request $request` argument.

## Domain logic and readability

### I-16 — Make the exchange-rate direction explicit
- **Where:** `Component/Currency/Converter/CurrencyConverter.php:47-51`, `Doctrine/ORM/ExchangeRateRepository.php`, `Resources/public/pimcore/js/exchangeRate/panel.js`
- The semantics ("1 `from` = `rate` × `to`", with the reverse derived by division) are implicit. Document them in `ExchangeRateInterface` and in the grid header, show the inverse rate as a read-only column, and add a `ExchangeRate::rateFor(CurrencyInterface $from): string` method so the converter does not need the `getIsoCode() === $fromCurrencyCode` comparison. Together with C-02 this gives "one row per pair, either direction".

### I-17 — Let the converter accept currency entities (or ids), not only ISO codes
- **Where:** `Component/Currency/Converter/CurrencyConverterInterface.php`, `CurrencyConverter.php:54-76`, `Twig/ConvertCurrencyExtension.php:39-46`, `CartCurrencyConversionProcessor.php:129-143` (dependent)
- Every caller already holds `CurrencyInterface` objects and converts them to codes, which the converter then resolves back with two `getByCode()` queries. Add `convertBetween(int $value, CurrencyInterface $from, CurrencyInterface $to): int`, and keep the code-based method as a thin wrapper. That removes the dependency on `isoCode` uniqueness (C-03) from the hot path.
- `ConvertCurrencyExtension::convertAmount()` turns `null` into `0`. Consider returning `?int`, so templates can tell "no price" apart from "free".

### I-18 — Simplify `CachedCurrencyContext`
- **Where:** `Component/Currency/Context/CachedCurrencyContext.php:35-48`
- The nested `if`s can become `if (null === $this->requestStack->getMainRequest()) { return $this->inner->getCurrency(); } return $this->currency ??= $this->inner->getCurrency();`. Add `reset()` from B-03.

### I-19 — Make `Money` a proper value object
- **Where:** `Component/Currency/Model/Money.php`
- It has `public` mutable properties **and** getters/setters, so two ways to change state. Make it `final readonly class Money` with `with*()` methods. This is safe with Pimcore's object cache because `MoneyCurrency::marshalForCache()` stores scalars. Check external callers of `setValue()`/`setCurrency()` first.
- `__toString()` returns `"1999EUR"` (minor units, no separator). Either document it as a debug format, or use `sprintf('%d %s', …)`. `getVersionPreview()` shows this string to editors (`CoreExtension/MoneyCurrency.php:238-241`), so format it with the decimal factor there.
- `Currency::__toString()` (`Component/Currency/Model/Currency.php:56-59`) returns `"Euro (3)"`. The ISO code (`"Euro (EUR)"`) is more useful in logs and selects.

### I-20 — Storage of the `MoneyCurrency` columns
- **Where:** `CoreExtension/MoneyCurrency.php:69-83`
- `currency => 'int'` has no index and no FK. Queries such as "all products priced in USD" do a full scan, and deleted currencies leave dangling ids (B-08). Pimcore data types cannot easily declare FKs. Consider an index through `getQueryColumnType()` + `Helper::...`, or a cleanup listener on currency delete.
- Use `'int(11) unsigned'` (matching the `coreshop_currency.id` type) for consistency.

### I-21 — **Verify:** `CurrencyMultiselect` returns raw ids, not currency objects
- **Where:** `CoreExtension/CurrencyMultiselect.php`; `ResourceBundle/CoreExtension/Multiselect.php`
- It extends Pimcore's plain `Multiselect`, so the generated getter returns `string[]` ids, with no parameter or return type declaration. Unlike `Currency` (a `Select`), consumers have to resolve the ids themselves. Consider a relation-style implementation returning `CurrencyInterface[]`, or at least `getReturnTypeDeclaration(): ?string { return '?array'; }` plus a phpdoc `@return string[]|null`.

### I-22 — **(dependent)** `findActiveForStore()` readability
- **Where:** `CoreBundle/Doctrine/ORM/CurrencyRepository.php:27-39`
- `leftJoin('o.countries')` followed by `innerJoin('c.stores')` and `WHERE c.active = true` is effectively an inner join. Write `innerJoin` for both, and filter by `s = :store` (entity parameter) instead of `s.id = :storeId`.

## Metadata and config files

### I-23 — Remove dead serializer metadata, and keep the property types consistent
- `Resources/config/serializer/Model.Currency.yml:26-29`: `countries` is exposed on the base `Currency`, which has no such property (it lives on the Core subclass). JMS ignores it silently. Move it to `CoreBundle/Resources/config/serializer/Model.Currency.yml`.
- **(dependent)** `CoreBundle/Resources/config/serializer/Model.Currency.yml:5-8`: exposes `stores`, which does not exist on `Core\Model\Currency`. Remove it, or replace it with `countries`.
- `Model.ExchangeRate.yml:5-8`: `id` has no `groups`. It is only serialised because `ViewHandler` always adds `Default` (`ResourceBundle/Controller/ViewHandler.php:38`). Add `groups: [List, Detailed]`, as in `Model.Currency.yml`, so it does not depend on that implicit behaviour.
- `Model.ExchangeRate.yml:11`: `type: float` loses the `DECIMAL` precision on the way to the admin. Use `string`. The ExtJS numberfield parses it.

### I-24 — Model and mapping consistency
- `Component/Currency/Model/ExchangeRate.php:52-55` sets `creationDate` in the constructor, while Gedmo `timestampable on="create"` already does it (`ExchangeRate.orm.xml:16-18`), and `Currency` relies only on Gedmo. Pick one approach.
- `ExchangeRate` implements `ResourceInterface` directly with `SetValuesTrait`, while `Currency` extends `AbstractResource`. Align them.
- Index names: `isoCode` and `currencies` are generic. Use `coreshop_currency_iso_code_uniq` and `coreshop_exchange_rate_pair_uniq` to avoid clashes in schema diffs (rename together with C-02/C-03).

## Packaging and tooling

### I-25 — Declare what the bundle actually uses in `composer.json`
- **Where:** `composer.json:26-36`; `Component/Currency/composer.json`
- Used but not required: `doctrine/orm` (repositories, QueryBuilder in the interface), `symfony/form`, `symfony/options-resolver`, `symfony/validator`, `symfony/http-foundation`, `symfony/dependency-injection`, `symfony/config`, `twig/twig`, `jms/serializer-bundle` (metadata), `coreshop/registry` (pass and `Autoconfiguration`). `pimcore/data-hub` (or the six-it equivalent) should go under `suggest`.
- The component requires `laminas/laminas-stdlib: ^3.6`. Raise it to a release that declares PHP 8.4 support, or replace `PriorityQueue` with `\SplPriorityQueue` or a simple sorted array (insertion-stable sorting is what `CompositeCurrencyContext` needs).
- `require-dev`: `phpstan/phpstan: ^1.10` → `^2.1` (PHP 8.4 syntax, such as asymmetric visibility and property hooks, needs PHPStan 2.x), together with the matching `phpstan-doctrine` and `phpstan-webmozart-assert` 2.x versions.
- Add `"php": "^8.4"` (the root pins `~8.4.0`).

### I-26 — Add tests for the conversion path
- There are no unit tests for `CurrencyConverter`, `CompositeCurrencyContext`, `CachedCurrencyContext` or `MoneyCurrency`, in this bundle or in the component. Minimum set:
  - converter: same currency, forward rate, reverse rate, missing rate, zero rate, cache hit and miss;
  - composite context: priority order, all contexts failing, previous-exception chaining;
  - `MoneyCurrency`: resource and editmode round-trip, empty value, deleted currency, cache marshal and unmarshal.
- These pin down C-02, C-05, B-01, B-02 and B-08 through B-10 before the fixes land.
