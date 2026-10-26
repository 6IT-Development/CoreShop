# Currency Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this component, but they come from how its contexts, converter, formatter or models are implemented, wired or used, and they should be fixed together with it.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.
Items are roughly ordered by impact.

Already tracked elsewhere, and **not repeated here**: CurrencyBundle **B-01** (missing rate returns the unconverted amount), **B-02** (converter cache key / misses / reset), **B-03** (`CachedCurrencyContext` leaks between requests), **B-05**/**B-06** (rate validation, `DECIMAL(15,10)`, float math), **B-08** (`new Money($value, null)`), **B-16** (display fraction always `2`), **B-18** (decorators tagged into the composite); FrontendBundle **B-17** (currency switch accepts any currency). Fix those together with the items here that mention them.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Contexts

### B-01 — (dependent) `StoreAwareCurrencyContext` breaks the `CurrencyContextInterface` contract: it lets `StoreNotFoundException` escape and can return `null`
- **Where:** `Component/Core/Context/Currency/StoreAwareCurrencyContext.php:37-53`; contract `Context/CurrencyContextInterface.php:25-28` (`@throws CurrencyNotFoundException`, return `CurrencyInterface`); `Component/Currency/Model/CurrencyAwareTrait.php:28-31` (`?CurrencyInterface`); `StoreBundle/Resources/config/doctrine/model/Store.orm.xml:24-25` (`currencyId nullable="true"`)
- **Problem:**
  - It decorates **the** `CurrencyContextInterface` service (`CoreBundle/Resources/config/services/context.yml:29-35`), so every consumer goes through it. `$this->storeContext->getStore()` (`:40`) is called outside the `try`, so without a resolvable store (CLI commands, Messenger workers, admin requests for a site without a store) callers get `StoreNotFoundException`, not `CurrencyNotFoundException`.
  - `ShopperContext::hasCurrency()` (`Component/Core/Context/ShopperContext.php:71-80`) only catches `CurrencyNotFoundException`, so it throws instead of returning `false`. `CoreContextProvider::provideContextForStorageList()` (`Component/StorageList/Core/Provider/CoreContextProvider.php:46-49`) does not catch at all.
  - `return $store->getCurrency();` (`:46`, `:51`) returns a nullable value from a method typed `CurrencyInterface`. A store saved without a currency (imports, fixtures, API: validation `NotBlank` only runs in the `coreshop` group) produces a `TypeError` on every frontend request instead of `CurrencyNotFoundException`.
- **Fix direction:** wrap the store lookup and convert `StoreNotFoundException` to `CurrencyNotFoundException` (with `previous`), as `StorageBasedCurrencyContext.php:38-42` already does. Throw `CurrencyNotFoundException` when the store has no currency. Add unit tests for "no store", "store without currency", "unavailable currency".

### B-02 — (dependent) `CountryAwareCurrencyContext` returns a nullable country currency from a non-nullable method, and is not registered anywhere
- **Where:** `Component/Core/Context/Currency/CountryAwareCurrencyContext.php:35-45`; `Component/Core/Model/Country.php:45-48` (returns `?CurrencyInterface`, `currencyId` nullable)
- **Problem:** for a country without a currency (allowed by the schema and the admin form), `getCurrency()` throws `TypeError` instead of `CurrencyNotFoundException`. `CompositeCurrencyContext` (`Context/CompositeCurrencyContext.php:50`) only catches `CurrencyNotFoundException`, so a project that tags this context gets a 500 for visitors from that country instead of falling through to the next context. The class is not wired in any CoreShop config (`grep` finds only its own file), so it is effectively dead code that ships with this bug.
- **Fix direction:** `$currency = $country->getCurrency() ?? throw new CurrencyNotFoundException(...)`. Then either register it (tagged, with a lower priority than the storage-based context) and document it, or delete it (see TODO-3 **I-14**).

### B-03 — (dependent) `CurrencyStorage` crashes on a crafted cookie, and "removing" the currency writes an empty cookie instead of deleting it
- **Where:** `Component/Core/Currency/CurrencyStorage.php:46-57`; `CoreBundle/Storage/CookieStorage.php` (`onKernelRequest`: `new ParameterBag($request->cookies->all())`, `get()`, `remove()` → `set($name, null)`, `onKernelResponse` → `new Cookie($name, (string) $value)`)
- **Problem:**
  - The cookie bag is a plain `ParameterBag`, not an `InputBag`, so a cookie like `_currency_1[]=2` comes back as an `array`. `$this->currencyRepository->find(['2'])` then throws a Doctrine missing-identifier exception (500) on every request of that client, because the context is resolved on every page.
  - `CurrencyStorage::set()` calls `$this->storage->remove()` (`:38`) when switching back to the base currency. `CookieStorage::remove()` stores `null`, which is sent as an **empty** cookie (`Set-Cookie: _currency_1=`), not an expired one. It works only because `get()` treats `''` as falsy. The cookie also has no `SameSite`/`Secure`/`HttpOnly` and no lifetime.
  - `get()` reads the storage twice (`:48-49`).
- **Fix direction:** read the value once, accept only `is_int`/`ctype_digit` strings, and treat anything else as "not set". In `CookieStorage`, use `$request->cookies` (`InputBag`) or validate scalars, and send `Cookie::create($name)->withExpires(1)` for removals, with `SameSite=Lax`, `Secure` (from the request) and `HttpOnly`. Track the cookie part in the CoreBundle TODO as well.

### B-04 — `FixedCurrencyContext` is part of the production composite and holds mutable, never-reset state
- **Where:** `Context/FixedCurrencyContext.php:25-39`; `CurrencyBundle/Resources/config/services.yml:27-29` (tagged `coreshop.context.currency`, public, shared)
- **Problem:** it is only meant for tests (Behat `Setup/CurrencyContext.php:37` uses it), but it is registered in every environment with the same priority (`0`) as `StorageBasedCurrencyContext`, so the composite's order between the two depends on service registration order. Any code that calls `setCurrency()` (a CLI command, a custom importer, a Messenger handler) pins the currency for the rest of the process, for **all** later requests in worker runtimes, because the service is not resettable. There is also no way to clear it (`setCurrency(null)` is not allowed).
- **Fix direction:** register it only for `test` (`#[When(env: 'test')]` or `when@test:` in YAML), or give it an explicit low priority and implement `ResetInterface` (`kernel.reset`) with a `clear()`/nullable setter.

### B-05 — **Verify:** `CachedCurrencyContext` keeps the first currency for the whole main request, even when the store changes during that request
- **Where:** `Context/CachedCurrencyContext.php:35-48`
- **Problem:** (beyond bundle **B-03**, which is about resets between requests) the cache key is only "a main request exists". The store context is itself a composite that includes `Component/Store/Context/FixedStoreContext.php`, so code can switch the store inside a request (Behat does; custom admin actions that render prices or mails for another store's order would too). After that, `StoreAwareCurrencyContext` (the inner service, which checks availability per store) is never asked again, and the first store's currency is returned. **Verify** which CoreShop or project code paths switch the store mid-request before deciding how far to go.
- **Fix direction:** key the cache by store id (read the current store from `StoreContextInterface`) or by `spl_object_id($request)`; at least skip caching when `getCurrentRequest() !== getMainRequest()`. Combine with bundle **B-03**/**I-18**.

## Conversion and formatting

### B-06 — (dependent) Converted cart amounts are rounded line by line, so converted totals do not add up (and the converted payment total can differ from the converted grand total)
- **Where:** `Component/Core/Order/Processor/CartCurrencyConversionProcessor.php:41-66`, `:100-126`; `Converter/CurrencyConverter.php:48,51` (`(int) round()` per call)
- **Problem:** every amount is converted on its own: item prices, item totals, adjustments, taxes, subtotal, total and payment total. With a rate like 1.0873, the sum of converted item totals plus converted adjustments can differ from the converted cart total by one or more minor units, and the converted tax lines can differ from the converted tax total. The storefront then shows a cart whose lines do not add up to the total, and invoices or ERP exports in the shopper currency fail reconciliation. `convertedPaymentTotal` is converted separately from `convertedTotal`, so the two can differ too (relevant for TODO-1 **C-01**).
- **Fix direction:** convert the leaf amounts, then derive the converted subtotals and totals by summing (the same way the base totals are derived), or convert the totals and distribute the rounding difference across the lines (largest-remainder). Make `convertedPaymentTotal` equal `convertedTotal` unless a payment-specific adjustment exists. Add a test with a rate that produces rounding differences.

### B-07 — (dependent) `CartCurrencyConversionProcessor` silently skips conversion or crashes when a currency is missing, and turns `null` into `0`
- **Where:** `Component/Core/Order/Processor/CartCurrencyConversionProcessor.php:129-144`
- **Problem:**
  - `$cart->getCurrency()->getIsoCode()` (`:142`) is called without a null check. A cart whose currency was never set (cart created outside the storefront, an API, a fixture) or was deleted (TODO-1 **C-02**) throws `Error: Call to a member function getIsoCode() on null` on every recalculation.
  - When the **base** currency is missing (`:135-137`), the unconverted value is returned and stored as "converted" without any log entry. This is the same class of silent failure as bundle **B-01**.
  - `convert(null)` returns `0` (`:131-133`), so "no payment total yet" is stored as a converted payment total of `0`.
- **Fix direction:** if either currency is missing, throw a domain exception (or skip the processor and log at `warning`), never write unconverted values into `converted*` fields. Keep `null` as `null` for nullable fields (`setConvertedPaymentTotal(?int)`).

### B-08 — (dependent) `MoneyFormatter` puts the minus sign in front of the formatted string, which is wrong for many locales, and does the division in `float`
- **Where:** `MoneyBundle/Formatter/MoneyFormatter.php:31-44`; contract `Formatter/MoneyFormatterInterface.php:26`
- **Problem:**
  - It formats `abs($amount / $factor)` and then prepends `'-'`. `NumberFormatter::CURRENCY` already knows where the sign goes. For `nl_NL` the correct output is `€ -12,50`, but this gives `-€ 12,50`. For `de_CH` ICU gives `EUR-12.50` but this gives `-EUR 12.50`, and for `ar`/`he` (CoreShop ships admin translations for both) the sign ends up outside the RTL/bidi marks, so it is shown in the wrong place (checked with PHP 8.4.27 intl). Values that round to zero (`-1` with `fraction: 0`) are shown as `-€0`.
  - `$amount / $factor` is a `float`. For amounts above 2^53 / factor, digits are lost before formatting.
  - The interface default `int $fraction = 2` ignores the currency (JPY, KWD) and the configured `money_decimal_precision`; callers such as `FormatMoneyExtension` (`MoneyBundle/Twig/FormatMoneyExtension.php:35`) inherit it.
- **Fix direction:** format the signed value directly (`$formatter->formatCurrency($amount / $factor, $code)`), or, to avoid `float`, build a `BcMath\Number` from the minor units and format its decimal string. Default the fraction to the currency's ISO digits (`Currencies::getFractionDigits()`), capped by the configured precision (together with bundle **B-16**). Cache `NumberFormatter` instances per `locale`+`fraction`.

### B-09 — (dependent) Twig uses concrete component classes instead of the interface aliases, so app overrides of the converter or the display-fraction provider do not reach templates
- **Where:** `CurrencyBundle/Resources/config/services/twig.yml:9` (`ConvertCurrencyExtension` gets `@CoreShop\Component\Currency\Converter\CurrencyConverter`), `:14` (`DisplayFractionExtension` gets `@CoreShop\Component\Currency\Display\DefaultDisplayFractionProvider`); aliases in `CurrencyBundle/Resources/config/services.yml:10,31`
- **Problem:** a project that replaces or decorates `CurrencyConverterInterface` (for example a live-rate converter) or `DisplayFractionProviderInterface` (for example per-currency fractions, which is how bundle **B-16** is meant to be solved) changes the cart processors, rule actions and calculators, but the `coreshop_convert_currency` filter and `coreshop_currency_display_fraction` function keep using the default classes. Prices in templates and in the cart are then calculated by different converters and can differ. `Behat/Service/ConfigurationBasedDisplayFractionProvider.php` exists for this, but is not wired anywhere, so the test suite never notices.
- **Fix direction:** inject the interfaces (`@CoreShop\Component\Currency\Converter\CurrencyConverterInterface`, `@…\DisplayFractionProviderInterface`), or autowire them (bundle **I-08**). Wire the Behat provider through the interface alias, or delete it.

## Models and contracts

### B-10 — `CurrencyAwareTrait` / `CurrencyAwareInterface` and their users disagree on nullability and return types
- **Where:** `Model/CurrencyAwareInterface.php:23-25`, `Model/CurrencyAwareTrait.php:23-36`; users `Component/Store/Model/Store.php:31`, `Component/Core/Model/Payment.php:55` (`setCurrency($currency)` untyped), `Component/Core/Model/QuantityRange{,Interface}.php` (`setCurrency(?CurrencyInterface $currency = null)`), `Component/Core/Model/Country{,Interface}.php:29-31`
- **Problem:** the property is documented as `CurrencyInterface` but is nullable in every mapping, the getter is `?CurrencyInterface`, and consumers treat it as non-null (`StoreAwareCurrencyContext`, `CartCurrencyConversionProcessor:39`, `ShopperContext.php:146`, `OrderCreationController.php:183-184`). `Country::getCurrency()` and `CountryInterface::getCurrency()` have no return type at all, so static analysis cannot catch B-01/B-02. `setCurrency()` has no return type in the interface, and every implementation picks its own.
- **Fix direction:** type the trait property `protected ?CurrencyInterface $currency = null;`, add `: void` to the setter in the interface and in all implementations (`Payment::setCurrency(?CurrencyInterface $currency): void`), and add `?CurrencyInterface` to `CountryInterface::getCurrency()`. Then fix the non-null assumptions reported by PHPStan level 6+ (B-01, B-07). This is the bug part of bundle **I-01**.

### B-11 — `ExchangeRate` uses `TimestampableTrait`, but `ExchangeRateInterface` does not extend `TimestampableInterface`
- **Where:** `Model/ExchangeRateInterface.php:23`, `Model/ExchangeRate.php:29-30,52-55`; compare `Model/CurrencyInterface.php:24`
- **Problem:** code typed against `ExchangeRateInterface` (repositories, serializer, the admin grid, Gedmo `timestampable` mapping) cannot call `getCreationDate()`/`getModificationDate()` without an `instanceof` check, and a custom `ExchangeRateInterface` implementation without those methods breaks the Gedmo timestampable mapping at flush time. `ExchangeRate` also does not extend `AbstractResource` (bundle **I-24**), so it lacks whatever `AbstractResource` adds for `Currency`.
- **Fix direction:** `interface ExchangeRateInterface extends ResourceInterface, TimestampableInterface`, and extend `AbstractResource` like `Currency` does.

### B-12 — `CurrencyRepositoryInterface::getByCode()` docblock contradicts its signature, and the repository contracts leak Doctrine ORM
- **Where:** `Repository/CurrencyRepositoryInterface.php:27,34-37`
- **Problem:** `@return CurrencyInterface` overrides the native `?CurrencyInterface` for PHPStan/Psalm, so the `null` checks in callers (`Converter/CurrencyConverter.php:71`, `FrontendBundle/Controller/CurrencyController.php:51`) are reported as redundant and may be "cleaned up" by a tool, which would bring back a `TypeError`. `createListQueryBuilder(): QueryBuilder` makes a component interface depend on `doctrine/orm`, which the component does not require (`composer.json:26-30`). A non-Doctrine implementation (tests, in-memory, API) cannot implement it.
- **Fix direction:** remove the wrong `@return` (or make it `@return CurrencyInterface|null`). Move `createListQueryBuilder()` to the bundle repository (or add `doctrine/orm` to the component's `require`; see TODO-3 **I-12**).

### B-13 — (dependent) `CurrenciesConditionChecker`s use loose `in_array()` and crash on a missing currency or configuration key
- **Where:** `Component/Core/Product/Rule/Condition/CurrenciesConditionChecker.php:38`, `Component/Core/Cart/Rule/Condition/CurrenciesConditionChecker.php:35`, `Component/Core/Payment/Rule/Condition/CurrenciesConditionChecker.php:37`, `Component/Core/Shipping/Rule/Condition/CurrenciesConditionChecker.php:39`
- **Problem:**
  - Cart, payment and shipping checkers call `->getCurrency()->getId()` without a null check (TODO-1 **C-02**, B-07: cart currency can be `null`), which is an `Error` during rule evaluation.
  - `$configuration['currencies']` is read without `??`; an old or hand-edited rule without the key emits an `Undefined array key` warning and then passes `null` to `in_array()` (`TypeError`).
  - Loose `in_array()` compares `int` ids with whatever the form stored (`"3"`, `3`, or `"3abc"`); it works today only because ids are stored as numeric strings.
- **Fix direction:** `$ids = array_map('intval', $configuration['currencies'] ?? []);`, return `false` when the subject has no currency, and use `in_array($currencyId, $ids, true)`.

### B-14 — (dependent, dev only) `CurrencyCollector` queries the database in its constructor and breaks after `reset()`
- **Where:** `CoreBundle/Collector/CurrencyCollector.php:32-47` (constructor calls `findActiveForStore($storeContext->getStore())`), `:55-58` (`getCurrencies(): array` reads `$this->data['currencies']`), `:80-83` (`reset()` empties `data`)
- **Problem:** the query runs when the profiler instantiates the collector, which is before routing and before the store is resolved for the current request, so the toolbar shows the currencies of whatever store the context guessed (or none: the `catch (\Exception)` hides it). After `reset()` (every request in worker mode, and between kernel requests in functional tests), `collect()` does not refill `currencies`, so `getCurrencies()` hits an undefined key and returns `null` from an `array` method: `TypeError` when the toolbar renders. Doctrine entities are also stored in `$data` and serialized into the profile.
- **Fix direction:** do all work in `collect()`, store scalars (`isoCode`, `name`, `id`) instead of entities, use `$this->data['currencies'] ?? []`, and catch `\Throwable`, not only `\Exception`.
