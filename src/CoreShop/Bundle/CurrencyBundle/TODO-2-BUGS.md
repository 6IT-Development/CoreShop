# CurrencyBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this bundle, but they come from how this bundle's models, repositories, services or config are used, and they should be fixed together with it.
Paths are relative to the bundle root unless noted otherwise. Items are roughly ordered by impact.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Conversion and contexts

### B-01 — A missing exchange rate silently returns the unconverted amount: prices are shown, and charged, in the wrong currency
- **Where:** `Component/Currency/Converter/CurrencyConverter.php:41-45` (`if (null === $exchangeRate) { return $value; }`), and `:67-75` (also `null` when a currency code is not found)
- **Problem:** if a currency is available in a store (one of the store's countries uses it) but no rate exists, then `100.00 EUR` is shown and stored as `100.00 USD`. `CartCurrencyConversionProcessor` writes these values into the converted totals, including `setConvertedPaymentTotal()`, so the payment provider can be asked for the wrong amount. Nothing is logged, so the misconfiguration stays invisible.
- **Fix direction:** decide the contract explicitly. Either throw a dedicated `ExchangeRateNotFoundException` (and let `StoreAwareCurrencyContext` / `CurrencyStorage` treat currencies without a rate as unavailable), or fall back to the base currency **and** switch the cart currency. At minimum, log a warning. Document the behaviour in `CurrencyConverterInterface`.

### B-02 — `CurrencyConverter` cache: the reverse key is a copy of the forward key, misses are never cached, and the cache never resets
- **Where:** `Component/Currency/Converter/CurrencyConverter.php:27`, `:54-76`
- **Problem:**
  - `:62`: `$toFromIndex = $this->createIndex($fromCode, $toCode);` uses the same arguments as `:56`, so the "reverse" lookup is dead code, and EUR→USD and USD→EUR are cached separately. The obvious intent was `createIndex($toCode, $fromCode)`.
  - `isset()` is false for a cached `null`, so a missing rate (B-01) runs three queries (`getByCode` ×2 + pair lookup) on **every** conversion. A cart recalculation calls `convert()` about 20 + 14×items times.
  - The array lives for the whole service lifetime. In Messenger workers, `symfony/runtime` worker mode, or long CLI imports, rate changes are never seen, and the cached entities stay attached to an `EntityManager` that may already have been cleared or closed. That leads to `EntityNotFound` or stale lazy proxies.
- **Fix direction:** use `array_key_exists()` (or a sentinel) so misses are cached. Use a canonical key, for example `min(code)-max(code)`. Implement `Symfony\Contracts\Service\ResetInterface` and tag the service `kernel.reset` (`method: reset`). Cache the rate value (`string`/`float`) and the direction, not the entity.

### B-03 — `CachedCurrencyContext` is not resettable: the currency leaks between requests in long-running processes
- **Where:** `Component/Currency/Context/CachedCurrencyContext.php:35-48`; `Resources/config/services.yml:20-25`
- **Problem:**
  - Once a main request exists, the first resolved currency is kept for the rest of the service's life. Under a worker runtime (FrankenPHP worker mode, RoadRunner, Swoole via `symfony/runtime`) or in Messenger handlers that push a request, visitor B gets visitor A's currency. Prices, cart currency and payment amounts are then wrong.
  - Outside a request (`:47`) it still assigns `$this->currency` on every call. If a request is pushed later (for example a sub-request in a CLI renderer), that stale value is returned without re-resolving.
  - The service is not tagged `kernel.reset`, so `services_resetter` never clears it.
- **Fix direction:** implement `ResetInterface` (`reset(): void { $this->currency = null; }`) and tag it `kernel.reset`. Optionally key the cache by `spl_object_id($request)` so sub-requests and new main requests re-resolve. See I-18 for the simplified control flow.

### B-04 — Base `CurrencyRepository::findActive()` joins an association that does not exist on the base model, and returns duplicate rows
- **Where:** `Doctrine/ORM/CurrencyRepository.php:33-41`
- **Problem:**
  - `innerJoin('o.countries', 'c')`: `countries` is mapped only by CoreBundle (`CoreBundle/Resources/config/doctrine/model/Currency.orm.xml`). With this bundle alone (its own `Currency` model, which is the composer-declared standalone setup), the call throws `[Semantical Error] Class ... has no association named countries`.
  - Even with CoreBundle, there is no `DISTINCT` or `GROUP BY`. A currency used by five active countries is returned five times (the ORM collapses identical root entities only when it fetch-joins). `CoreBundle\Doctrine\ORM\CurrencyRepository::findActiveForStore()` adds `->distinct()`, which confirms the intent.
- **Fix direction:** move `findActive()` to the CoreBundle repository (where `countries` exists) and give the base an implementation that does not depend on Address, for example "all currencies" or a field-based flag. Or keep it and add `->distinct()` plus an `ORDER BY o.name`.

### B-05 — An exchange rate from a currency to itself is accepted; the validation `Type(float)` conflicts with the `decimal` mapping
- **Where:** `Resources/config/validation/ExchangeRate.yml:1-9`; `Form/Type/ExchangeRateType.php:28-45`; `Resources/config/doctrine/model/ExchangeRate.orm.xml:15`
- **Problem:**
  - `fromCurrency === toCurrency` is accepted. The converter returns early for equal codes, so the row is dead data, and it still takes the unique slot and confuses the grid.
  - Doctrine hydrates `decimal` as a `string`. Any validation of a loaded entity outside the form (API, import, `validator->validate($rate)`) fails `Type(float)` for every existing row. It only passes in the admin because `NumberType` casts the submitted value to `float`.
- **Fix direction:** add a class-level constraint (`Expression: "this.getFromCurrency() !== this.getToCurrency()"`, or a custom constraint, together with the C-02 reverse-pair check). Replace `Type(float)` with `Type(numeric)` + `Positive` (see C-05), or type the model property as `string` and validate it with a `Regex`/`DivisibleBy`-style check.

### B-06 — `exchangeRate` is `DECIMAL(15,10)`, so rates of 100 000 or more cannot be stored; conversion goes through `float`, which loses precision on large amounts
- **Where:** `Resources/config/doctrine/model/ExchangeRate.orm.xml:15`; `Form/Type/ExchangeRateType.php:31-35` (`scale: 10`); `Component/Currency/Converter/CurrencyConverter.php:48,51`
- **Problem:**
  - Precision 15 with scale 10 leaves 5 integer digits, so the maximum is `99999.9999999999`. Real pairs reach or exceed it: EUR→IRR, EUR→LBP (around 100 000), and most crypto pairs (for example BTC→anything). MySQL strict mode then raises `Out of range value`, which becomes a 500 in the admin. Non-strict mode silently clamps the rate.
  - The reverse direction divides by a rate that may be as small as `0.0000000001` (scale 10), which is only 1 significant digit.
  - `$value * $rate` happens in `float`. Order totals are `bigint` in minor units, and above 2^53 minor units (unusual, but possible with factor 10 000 and weak currencies) the result loses precision before `round()`.
- **Fix direction:** widen the column to `DECIMAL(24,12)` or similar (migration), and match the form `scale`. Do the arithmetic with `BcMath\Number` (PHP 8.4) or `bcmul`/`bcdiv` on the decimal string (see I-07).

### B-07 — Currency validation does not match the DB schema, so invalid data gives a SQL error (500) instead of a form error
- **Where:** `Resources/config/validation/Currency.yml:1-12`; `Resources/config/doctrine/model/Currency.orm.xml:15-18`
- **Problem:**
  - `isoCode` is `length="3"`, but there is no `Length(max: 3)`. Entering "EURO" raises `Data too long for column 'isoCode'` in strict mode, so a 500 instead of a validation message (non-strict mode truncates silently to "EUR", which then collides as in C-03).
  - `numericIsoCode` is `integer`, with no `Range(0, 999)`. `name` is a 255-character `string` with no `Length`.
  - `numericIsoCode` and `symbol` are `NotBlank` in validation but `nullable="true"` in the mapping. `CurrencyInterface` is documented as returning `string`/`int`. Rows created by fixtures and imports skip the form validation, so consumers (the Twig symbol filter, the frontend widget) still get `null`.
- **Fix direction:** align the two sides. Either make the columns `NOT NULL` (migration with defaults), or relax `NotBlank` and type the getters as nullable. Add `Length` / `Range` / `Currency` constraints. Also see C-03 for `isoCode` uniqueness.

## Pimcore data type `MoneyCurrency`

### B-08 — A deleted (or unknown) currency id makes `preGetData()` and `unmarshalForCache()` build `new Money($value, null)`, which throws a `TypeError` when the object loads
- **Where:** `CoreExtension/MoneyCurrency.php:137-153` (`preGetData`), `:324-331` (`unmarshalForCache`), `:338-341` (`getCurrencyById` returns `?CurrencyInterface`); `Component/Currency/Model/Money.php` (constructor requires `CurrencyInterface`)
- **Problem:**
  - After a currency is deleted (FK-less `int` column, see I-20), `getDataFromResource()` handles `null` correctly (`:179`). But an object already in the Pimcore cache goes through `unmarshalForCache()`, which does not. Neither does `preGetData()`, on every getter call when the object var still holds a `Money`. The result is `TypeError: Money::__construct(): Argument #2 ($currency) must be of type CurrencyInterface, null given`. The object (for example a product) can no longer be loaded on the storefront or opened in the admin until someone clears the cache by hand.
  - `preGetData()` also calls `getCurrencyById()` (a repository `find()`) on **every** getter call. That is a cheap identity-map hit, but it allocates a new `Money` each time, so `$obj->getPrice() !== $obj->getPrice()`.
- **Fix direction:** return `null` (or keep the stale `Money` and log it) when the currency cannot be resolved, in both methods. In `preGetData`, reuse the existing instance when its currency is already managed (`$em->contains()`).

### B-09 — `getDataFromEditmode()` throws a `TypeError` when the value field is empty, and reads an undefined `currency` key
- **Where:** `CoreExtension/MoneyCurrency.php:209-220`, `:359-362` (`toNumeric`)
- **Problem:**
  - The ExtJS tag sends `{value: '', currency: 3}` when the amount is cleared. `toNumeric('')` evaluates `'' * 100`, and on PHP 8.4 that throws `TypeError: Unsupported operand types: string * int` (checked with PHP 8.4.27). Saving the object in the admin fails with a 500.
  - `$data['currency']` is read without `??`, so a payload without the key emits an `Undefined array key` warning, and `find(null)` follows.
  - Non-numeric input ("12,50" in locales with a decimal comma) has the same problem.
- **Fix direction:** return `null` when `value` is `''`/`null`. Validate with `is_numeric()` (as `MoneyBundle\CoreExtension\Money::checkValidity()` does), normalise decimal commas, and use `$data['currency'] ?? null`.

### B-10 — `isEmpty()` calls `strlen()` on a value that may be `int`/`float`/`null`, which is a `TypeError` under `strict_types`
- **Where:** `CoreExtension/MoneyCurrency.php:291-310` (`:301`)
- **Problem:** for array input (edit-mode data, imports, `checkValidity()` before `getDataFromEditmode()`), `$data['value']` is often numeric (`12.5`) or missing. With `declare(strict_types=1)`, `strlen(12.5)` is a `TypeError`. A missing key is a warning followed by `strlen(null)` (PHP 8.1+ deprecation, TypeError under strict types).
- **Fix direction:** `$value = $data['value'] ?? null; if ($value === null || $value === '') { return true; }`, and `empty($data['currency'] ?? null)`.

### B-11 — `checkValidity()` compares `minValue`/`maxValue` (entered in major units) with the stored value in minor units, and calls `getValue()` on arrays
- **Where:** `CoreExtension/MoneyCurrency.php:243-272`; `Resources/public/pimcore/js/coreExtension/tags/coreShopMoneyCurrency.js:67-72` (applies `minValue`/`maxValue` to the decimal input, i.e. in major units)
- **Problem:**
  - `maxValue: 1000` (meaning 1 000.00) rejects anything above 10.00, because `100000 > 1000`. `minValue: 5` accepts 0.05. The client-side and server-side limits disagree by `decimal_factor`.
  - The `minValue` default is `0` and the check is `(string) $this->getMinValue() !== ''`, so negative amounts are always rejected, even when the admin left "min" empty. The JS sends `null` (skipped), but the PHP default is `0` (not skipped).
  - `isEmpty()` returns `false` for valid arrays, so `checkValidity()` then calls `$data->getValue()` on an `array`, which is a fatal `Error`.
  - `$data->getValue() >= \PHP_INT_MAX` can never trigger for an `int`. It is dead code copied from `Numeric`.
- **Fix direction:** compare in the same unit (`$value / $this->getDecimalFactor()`, or scale the limits). Default `minValue`/`maxValue` to `null`. Accept only `Money` here, and throw a `ValidationException` for other types. Drop the `PHP_INT_MAX` check.

### B-12 — `setWidth()` uses `Data::getAsIntegerCast()`, which is deprecated since Pimcore 11.2 and removed in Pimcore 12; `getForCsvExport()` can return `false`
- **Where:** `CoreExtension/MoneyCurrency.php:98-103` (`:100`); `:274-279`; parent `../pimcore-source/models/DataObject/ClassDefinition/Data.php:1004-1011` (`trigger_deprecation(... '11.2' ...)`)
- **Problem:**
  - Every class-definition load (each object save or class rebuild) logs a deprecation, and the code breaks on the Pimcore 12 upgrade. `$width` is typed `?int`, while Pimcore stores layout widths as `string|int|null` (for example `"100%"` from newer UIs), so the cast can drop the value.
  - `json_encode()` returns `string|false`. On failure (for example invalid UTF-8 in a future field), the `string` return type turns it into a `TypeError` during CSV export.
- **Fix direction:** `$this->width = is_numeric($width) ? (int) $width : null;` (or widen the property to `int|string|null` if Pimcore allows it). Use `json_encode(..., \JSON_THROW_ON_ERROR)`.

## Configuration, DataHub and Twig

### B-13 — `money_decimal_factor` / `money_decimal_precision` are not validated: factor `0` divides by zero, and the two values can disagree
- **Where:** `DependencyInjection/Configuration.php:46-48`; `DependencyInjection/CoreShopCurrencyExtension.php:52-53`; consumers `CoreExtension/MoneyCurrency.php:197,351-354,359-362`, `Controller/CurrencyController.php:27-35` (sent to the admin JS)
- **Problem:**
  - `integerNode` without `min(1)`, so `money_decimal_factor: 0` gives `DivisionByZeroError` in `getDataForEditmode()` (every object edit), and negative values corrupt amounts.
  - Nothing enforces `factor === 10 ** precision`. `factor: 100, precision: 4` makes the ExtJS field accept 4 decimals, but the server rounds to 2.
  - `autoconfigure_with_attributes` is a `scalarNode` (`:48`) passed to `Autoconfiguration::registerForAutoConfiguration(..., bool $autoconfigureWithAttributes)`. A YAML string such as `"true"` or `"yes"` raises a `TypeError` during container compilation (`strict_types`).
- **Fix direction:** `->integerNode(...)->min(1)`, plus a `->validate()->ifTrue(fn ($v) => $v['money_decimal_factor'] !== 10 ** $v['money_decimal_precision'])->thenInvalid(...)` on the root node (or derive one value from the other), and `->booleanNode('autoconfigure_with_attributes')->defaultFalse()`.

### B-14 — DataHub `MoneyCurrency` creates a new `ObjectType` with a fixed name on every call, and maps the amount to GraphQL `Int` (32-bit)
- **Where:** `DataHub/QueryType/MoneyCurrency.php:61-76` (`:64`, `:68`)
- **Problem:**
  - Each MoneyCurrency field (per class) calls `getFieldType()` and gets a **new** `ObjectType` named `CoreShop__Component__Currency__Model__Money`. graphql-php requires type names to be unique per instance, and fails schema building with `Schema must contain unique named types but contains multiple types named "…Money"`. With two MoneyCurrency fields in an exposed class, or the same field in two exposed classes, the whole DataHub endpoint for that configuration stops working. The shipped `CoreShopProduct` class has only one field, so a default install is not affected.
  - `Type::int()` is a signed 32-bit integer. Amounts at or above 2 147 483 648 minor units (21 474 836.48 at factor 100, or 214 748.36 at factor 10 000) make the query fail with `Int cannot represent non 32-bit signed integer value`.
- **Fix direction:** memoise the type (`private ?ObjectType $type = null;` in this service, or register it once through `Service::getTypeDefinition()` / the `DoctrineProvider` type registry like `DoctrineProvider.php:264`). Expose `value` as `Type::string()` or a custom `BigInt` scalar, and optionally add a `formatted`/`decimal` field.

### B-15 — Twig `coreshop_currency_symbol` ignores its `$locale` argument and throws on unknown codes
- **Where:** `Twig/CurrencyExtension.php:34-37`
- **Problem:**
  - `Currencies::getSymbol($code)` is called without `$locale`, so the documented second argument does nothing (`'CAD'|coreshop_currency_symbol('en_CA')` should return `$` but returns `CA$`).
  - A custom or invalid code (for example a store-specific "PTS" loyalty currency, or a typo) throws `Symfony\Component\Intl\Exception\MissingResourceException`, so the whole page fails to render. A `null` isoCode (C-03 / B-07) is a `TypeError`.
- **Fix direction:** `return Currencies::exists($code) ? Currencies::getSymbol($code, $locale) : $code;`. Optionally, fall back to the entity's own `symbol` field (which the admin maintains but nothing uses here).

### B-16 — `DefaultDisplayFractionProvider` always returns `2`, ignoring both `money_decimal_precision` and the currency
- **Where:** `Component/Currency/Display/DefaultDisplayFractionProvider.php:23-26`; `Resources/config/services.yml:31-32`; `Twig/DisplayFractionExtension.php:39-42`; used by `FrontendBundle/Resources/views/Common/Macro/currency.html.twig:3`
- **Problem:** JPY/KRW/HUF prices are rendered with `.00`, and BHD/KWD (3 decimals) are rounded for display. A project that sets `money_decimal_precision: 4` still shows 2 decimals. The `$context` passed in (store, currency) is never used.
- **Fix direction:** inject `%coreshop.currency.decimal_precision%` as the default. If `$context['currency']` is set, use `Symfony\Component\Intl\Currencies::getFractionDigits($isoCode)`, capped at the configured precision.

### B-17 — `ExchangeRateType` sets `empty_data: false` on the currency choice fields
- **Where:** `Form/Type/ExchangeRateType.php:36-43`
- **Problem:** `false` is not a valid choice value or a currency. For an empty submission, `ChoiceType` gets `false` as view data instead of `''`/`null`. That leads to a transformation failure ("This value is not valid.") instead of the `NotBlank` message from `ExchangeRate.yml`, or to `null` being written into `setFromCurrency(CurrencyInterface $currency)`, which PropertyAccess rejects with an exception. **Verify** which of the two happens on Symfony 7.4. Either way the result is not the intended `NotBlank` violation.
- **Fix direction:** remove `empty_data`, and make the setters accept `?CurrencyInterface` (or use `'empty_data' => null` with nullable setters) so that `NotBlank` reports the error cleanly.

### B-18 — **(dependent)** Interface-based autoconfiguration tags every `CurrencyContextInterface` service, including decorators, which then recurse into the composite
- **Where:** `DependencyInjection/CoreShopCurrencyExtension.php:55-61`; `Component/Registry/Autoconfiguration.php` (`registerForAutoconfiguration($interface)->addTag($tag)`); `Component/Registry/PrioritizedCompositeServicePass.php`
- **Problem:** with the default `autoconfigure_with_attributes: false`, any autoconfigured app service implementing `CurrencyContextInterface` is tagged `coreshop.context.currency` and added to `CompositeCurrencyContext`. That includes a decorator the app writes for `CurrencyContextInterface` (the pattern CoreShop itself uses in `StoreAwareCurrencyContext`). The decorator then becomes both the outer service and a member of the composite it wraps, so `getCurrency()` recurses until the stack overflows ("Maximum function nesting level" or a segfault).
- **Fix direction:** in the compiler pass, skip definitions that have `getDecoratedService() !== null`, or skip classes that take a `CurrencyContextInterface` constructor argument. Also document that decorators need `autoconfigure: false`. Consider making `autoconfigure_with_attributes: true` the default in the next major. The same pattern exists in the other `*ContextPass` bundles.
