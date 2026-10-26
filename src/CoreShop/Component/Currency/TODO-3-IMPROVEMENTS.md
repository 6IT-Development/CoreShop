# Currency Component – TODO 3: Improvements (code quality, readability, logic, PHP 8.4 / Symfony 7.4 idioms)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
None of these are defects on their own. They make the component cleaner, safer, easier to test, or more idiomatic for the target stack.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.
Where an item extends a CurrencyBundle TODO-3 item, the bundle item number is given. Do them together. Bundle items that already fully cover component files (**I-04** empty constructors, **I-05** `CurrencyNotFoundException` typing, **I-07** `BcMath\Number`, **I-16** rate direction, **I-17** entity-based converter, **I-18** `CachedCurrencyContext` flow, **I-19** `Money` value object) are not repeated.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 language features

### I-01 — Native types on every model, interface and trait, with asymmetric visibility where it fits (extends bundle I-01)
- **Where:** `Model/Currency{,Interface}.php`, `Model/ExchangeRate{,Interface}.php`, `Model/CurrencyAware{Interface,Trait}.php`, `Model/Money.php`
- Bundle I-01 lists the property and accessor types. Component-specific additions:
  - `Money`: `public private(set) int $value` and `public private(set) CurrencyInterface $currency` (or `final readonly class`, bundle I-19). Today the public properties **and** setters are two ways to mutate the same state.
  - `Currency::$id` / `ExchangeRate::$id`: `public protected(set) ?int $id = null;`. `ExchangeRate::$id` is documented as `@var mixed` (`ExchangeRate.php:32-35`) but returned as `?int`.
  - `Currency::setId()` (`Currency.php:66-71`) is not in the interface and is not called anywhere in `src/`. Remove it, or make the ID `protected(set)` and drop it.
  - Make every setter return `void` (today `setId()` and `setSymbol()` return `$this`, the others return nothing).
- This is a BC break for implementors of the interfaces. Plan it with B-10 for a minor/major and note it in `UPGRADE`.

### I-02 — `#[\Override]`, `final` and `readonly` (extends bundle I-02 / I-03)
- `#[\Override]` on every implemented method in `Context/*`, `Converter/CurrencyConverter.php`, `Display/DefaultDisplayFractionProvider.php`, `Model/*`.
- `Display/DefaultDisplayFractionProvider.php:21` is the only non-`final` service class in the component, and nothing extends it. Make it `final` (decorate through the interface instead).
- Promoted dependencies `private readonly` in `CachedCurrencyContext`, `CurrencyConverter`, and the Core contexts (`StoreAwareCurrencyContext`, `StorageBasedCurrencyContext`, `CountryAwareCurrencyContext`, `CurrencyStorage`).

### I-03 — Use the PHP 8.4 array helpers and `??=` for the small loops
- `Component/Core/Context/Currency/StoreAwareCurrencyContext.php:55-60` and `Component/Core/Currency/CurrencyStorage.php:73-78` build an array of ISO codes and call `in_array()`. Use `array_any($currencies, static fn (CurrencyInterface $c): bool => $c->getId() === $currency->getId())`: one pass, no temporary array, and comparison by id instead of by the (not yet unique, bundle C-03) ISO code.
- `CompositeCurrencyContext::getCurrency()` can keep its loop, but `CachedCurrencyContext` and the converter cache should use `??=` (bundle I-18, B-02).
- PHP 8.4 allows `new Foo()->bar()` without wrapping parentheses. Use it where a temporary object is created and used immediately (for example in the formatter refactor, B-08).

### I-04 — Mark the APIs that are being replaced with PHP 8.4's `#[\Deprecated]`
- When the entity-based `convertBetween()` lands (bundle I-17), put `#[\Deprecated(message: 'use convertBetween()', since: 'coreshop/currency 4.x')]` on the code-based `convert()`. Do the same for `CurrencyRepositoryInterface::createListQueryBuilder()` (B-12) and `findActive()` (bundle B-04) when they move to the bundle.
- The unused named constructors `CurrencyNotFoundException::notFound()`, `disabled()`, `notAvailable()` (`Context/CurrencyNotFoundException.php:30-56`, no callers in `src/`) are either the start of a better error model (use them in `StorageBasedCurrencyContext`, `StoreAwareCurrencyContext` and `CurrencyStorage` to say *why* no currency was found), or should be deprecated and removed.

## Symfony 7.4 idioms and DI

### I-05 — Replace `Laminas\Stdlib\PriorityQueue` in `CompositeCurrencyContext` with a Symfony tagged iterator (extends bundle I-25)
- **Where:** `Context/CompositeCurrencyContext.php:22-41`; `CurrencyBundle/DependencyInjection/Compiler/CompositeCurrencyContextPass.php`; `composer.json:29`
- Accept `iterable $contexts` in the constructor and wire it with `!tagged_iterator coreshop.context.currency` (or `#[AutowireIterator('coreshop.context.currency')]`). Symfony sorts by the tag's `priority` attribute, which is what the pass does by hand today.
- Keep `addContext()` (deprecated) for third-party passes, appending to an internal list that is merged in priority order.
- This removes the only use of `laminas/laminas-stdlib` in the component, and with it a dependency whose PHP 8.4 support has to be checked separately.
- Make the "nothing found" error more useful: collect the class names of the contexts that failed and add them to the message, instead of keeping only the last exception (`:45-57`).

### I-06 — Make every stateful service resettable, and replace the magic decoration priorities with attributes
- **Where:** `Context/CachedCurrencyContext.php`, `Context/FixedCurrencyContext.php`, `Converter/CurrencyConverter.php`; `CurrencyBundle/Resources/config/services.yml:20-25` (`decoration_priority: 255`), `CoreBundle/Resources/config/services/context.yml:29-35` (`decoration_priority: 256`)
- Implement `Symfony\Contracts\Service\ResetInterface` on all three (bundle B-02, B-03, TODO-2 B-04). With autoconfiguration, Symfony 7.4 tags them `kernel.reset` automatically.
- The decorator order (`StoreAware` at 256 wraps the composite first, `Cached` at 255 wraps that) is only visible by comparing two numbers in two bundles. Use `#[AsDecorator(decorates: CurrencyContextInterface::class, priority: …)]` on both classes, with class constants for the priorities (for example `CachedCurrencyContext::DECORATION_PRIORITY`), and a comment saying "must wrap `StoreAwareCurrencyContext`".

## Domain logic and readability

### I-07 — Extract "is this currency available in this store?" into one service
- **Where:** duplicated in `Component/Core/Context/Currency/StoreAwareCurrencyContext.php:55-68` and `Component/Core/Currency/CurrencyStorage.php:64-86`; also `FrontendBundle/Controller/CurrencyController.php:37` (widget) and the missing check in `switchAction` (FrontendBundle B-17)
- Create `StoreCurrencyProvider` (or `AvailableCurrenciesProviderInterface`) with `getAvailableCurrencies(StoreInterface): array`, `isAvailable(CurrencyInterface, StoreInterface): bool` and `getBaseCurrency(StoreInterface): CurrencyInterface` (throwing `CurrencyNotFoundException`, B-01). Memoise per store id and reset with `ResetInterface`.
- Today `findActiveForStore()` runs on every uncached `getCurrency()` call (every call outside a main request: CLI, Messenger, indexing) and again on every currency switch.

### I-08 — Carry amounts as `Money` (amount + currency) through the converter, formatter and payment APIs
- **Where:** `Converter/CurrencyConverterInterface.php:23`, `Formatter/MoneyFormatterInterface.php:26`, `Model/Money.php`; consumers `Component/Order/Payment/OrderPaymentProvider.php`, `CartCurrencyConversionProcessor.php`, `MoneyBundle/Formatter/MoneyFormatter.php`
- TODO-1 **C-01** exists because an `int` amount and a currency travel separately and nothing checks they belong together. Add `convertMoney(Money $money, CurrencyInterface $to): Money` and `formatMoney(Money $money, string $locale): string`, and let new code (and the payment provider) use them. Keep the `int`/`string` methods as thin wrappers for BC.
- `MoneyFormatterInterface::format()` has five positional parameters, two of them optional ints with different meanings (`$fraction`, `$factor`). With a `Money` argument, `$factor` comes from configuration and `$fraction` from the display-fraction provider (B-08, bundle B-16), and named arguments are no longer needed to call it safely.

### I-09 — Give `DisplayFractionProviderInterface` a typed contract
- **Where:** `Display/DisplayFractionProviderInterface.php:23`, `Display/DefaultDisplayFractionProvider.php:23-26`; caller `CurrencyBundle/Twig/DisplayFractionExtension.php:39-42`
- `array $context` is undocumented: implementors have to read the Twig templates to learn which keys exist. At least add `@param array{currency?: CurrencyInterface, store?: StoreInterface, locale?: string} $context`. Better: `getDisplayFraction(?CurrencyInterface $currency = null, array $context = []): int`.
- Same for `MoneyFormatterInterface::format()`: document that `$amount` is in minor units, that `$factor` defaults to `coreshop.currency.decimal_factor`, and when `\InvalidArgumentException` is thrown (only when `NumberFormatter::formatCurrency()` returns `false`).

### I-10 — Remove the redundant re-wrapping and nested `if`s in the Core contexts
- `Component/Core/Context/Currency/StorageBasedCurrencyContext.php:44-48` catches `CurrencyNotFoundException` only to throw a new, message-less `CurrencyNotFoundException` that wraps it. Let it propagate (or add a message: "No currency stored for store #%d").
- `Component/Core/Currency/CurrencyStorage.php:64-71`: `isBaseCurrency()` only works for the Core `StoreInterface`, and silently returns `false` otherwise. Type the method against the Core interface, or move the check into the provider from I-07.
- `CurrencyStorage::get()` → `$id = $this->storage->get($key); if (is_numeric($id) && ($currency = $this->currencyRepository->find((int) $id)) instanceof CurrencyInterface) { return $currency; }` (see B-03).

### I-11 — `numericIsoCode` should be a three-character string, not an `int`
- **Where:** `Model/CurrencyInterface.php:48-56`, `Model/Currency.php:46-49,93-104`; mapping `CurrencyBundle/Resources/config/doctrine/model/Currency.orm.xml` (`integer`)
- ISO 4217 numeric codes are three digits **with** leading zeros (`008` ALL, `036` AUD, `044` BSD). Stored as `int`, they come back as `8`, `36`, `44`, and every export, payment gateway field or EDI message that needs the ISO form has to pad them again. Use `string` with `Length(exactly: 3)` + `Regex('/^\d{3}$/')`, or keep `int` and add `getNumericIsoCodeFormatted(): string` (`sprintf('%03d', …)`). Coordinate with bundle B-07 (validation) and I-24 (mapping).

### I-12 — Clean up `composer.json` (extends bundle I-25)
- **Where:** `composer.json:26-56`
- `six-it/pimcore-source: ^2.0` is required, but nothing in this component uses Pimcore. Remove it (the bundles that need Pimcore already require it).
- Add what the code uses: `symfony/http-foundation` (`CachedCurrencyContext`), `doctrine/orm` (`CurrencyRepositoryInterface::createListQueryBuilder()`, unless it moves out, B-12), `symfony/service-contracts` (for `ResetInterface`, I-06), `"php": "^8.4"`.
- Drop `laminas/laminas-stdlib` after I-05.
- `require-dev`: PHPStan 2.x (`phpstan/phpstan: ^2.1`, `phpstan-doctrine` and `phpstan-webmozart-assert` 2.x) to parse PHP 8.4 asymmetric visibility (I-01).
- `minimum-stability: dev` with a path repository is fine inside the monorepo, but `branch-alias` `4.1-dev` should match the version the monorepo is on. Check before the next split release.

### I-13 — Document the currency model in `README.md`
- **Where:** `README.md` (only boilerplate today)
- Describe: the currency context chain (composite → `StoreAware` → `Cached`, which contexts are tagged and with what priority); "base" vs "converted" amounts and which one each field and API uses (the root of TODO-1 C-01); the exchange-rate direction (bundle I-16); what happens when a rate is missing (bundle B-01, once decided); how to plug in a custom `CurrencyContextInterface` without being swallowed into the composite (bundle B-18).

### I-14 — Remove or wire dead code
- `Component/Core/Context/Currency/CountryAwareCurrencyContext.php`: not registered (TODO-2 B-02). Either tag it with a documented priority, or delete it.
- `Behat/Service/ConfigurationBasedDisplayFractionProvider.php`: not wired (TODO-2 B-09).
- `Model/Currency.php:66-71` `setId()` and the unused `CurrencyNotFoundException` factories (I-01, I-04).

### I-15 — Use `EXTRA_LAZY` for `Currency::$countries`
- **Where:** `CoreBundle/Resources/config/doctrine/model/Currency.orm.xml:5`; `Component/Core/Model/Currency.php:40-69`
- `hasCountries()`, `hasCountry()` and `addCountry()` load the whole collection (every country that uses the currency) just to check membership or emptiness. `fetch="EXTRA_LAZY"` turns `contains()`, `count()` and `isEmpty()` into single queries. Combine with the cascade fix in bundle C-04.

## Tests

### I-16 — Add tests for the parts this scan found untested (extends bundle I-26)
- There are no tests for the component (no `Tests/` folder, nothing under `tests/` references it). In addition to the converter and composite tests listed in bundle I-26:
  - `StoreAwareCurrencyContext`: no store, store without currency, unavailable currency, available currency (TODO-2 B-01).
  - `CurrencyStorage`: base currency removes the key, unavailable currency removes the key, crafted/array cookie value, deleted currency id (TODO-2 B-03).
  - `CartCurrencyConversionProcessor`: rounding consistency between lines and totals (TODO-2 B-06), missing cart currency (TODO-2 B-07).
  - Payment creation in a non-base currency (TODO-1 C-01) and rule evaluation with a deleted currency (TODO-1 C-02).
  - `MoneyFormatter`: negative amounts in `nl_NL`, `de_CH`, `ar` (TODO-2 B-08).
