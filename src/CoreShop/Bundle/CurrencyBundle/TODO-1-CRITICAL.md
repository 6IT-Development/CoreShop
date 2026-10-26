# CurrencyBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: all PHP in this bundle, `Resources/config/**` (services, form, twig, data_hub, pimcore config/admin/routing, doctrine, serializer, validation), `composer.json`, and the code it depends on or that builds on it:
`Component/Currency/**` (models, contexts, converter, display, repository interfaces), `Component/Core/Model/Currency{,Interface}.php`, `Component/Core/Context/Currency/*`, `Component/Core/Currency/CurrencyStorage.php`, `Component/Core/Order/Processor/CartCurrencyConversionProcessor.php`, `CoreBundle/Doctrine/ORM/CurrencyRepository.php`, `CoreBundle/Resources/config/{pimcore/coreshop/coreshop_currency.yml,services/context.yml,doctrine/model/{Currency,Country}.orm.xml,serializer/Model.Currency.yml}`, `FrontendBundle/Controller/CurrencyController.php`, `ResourceBundle/Controller/{ResourceController,ViewHandler}.php`, `ResourceBundle/CoreExtension/{Select,Multiselect}.php`, `Component/Registry/{Autoconfiguration,PrioritizedCompositeServicePass}.php`, and the Pimcore `ClassDefinition\Data` base class (`../pimcore-source`).

"Critical" here means: a security issue, data loss, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the bundle root unless noted otherwise.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers found. `php -l` (PHP 8.4.27) passes on every file in the bundle and the component. There are no implicit nullable parameters, no removed Symfony APIs, and the `MoneyCurrency` method signatures are compatible with the Pimcore `Data` parent. Deprecations and modernisation items are in TODO-2 (B-12) and TODO-3.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## C-01 — `ExchangeRateController::saveAction()` skips the permission check, so any backend user can create or change exchange rates (and with them, storefront prices)

- **Where:**
  - `Controller/ExchangeRateController.php:28-58`: overrides `ResourceController::saveAction()` but never calls `$this->isGrantedOr403()`. The parent's first line is that call (`ResourceBundle/Controller/ResourceController.php:100-102`).
  - `:30-34`: when `id` is missing or unknown, it creates a new entity (`$this->factory->createNew()`), so the action is also an unguarded "add". `addAction()` (`ResourceController.php:177-179`) is guarded.
  - Routed through `Resources/config/pimcore/routing.yml:12-15` (`coreshop_exchange_rate_save`); the resource is declared with `permission: exchange_rate` (`DependencyInjection/Configuration.php:92`), so the intent is clearly to guard it.
- **Problem:** any authenticated Pimcore user, including one with no CoreShop permissions, can POST to `coreshop_exchange_rate_save` and create or change any rate. `CartCurrencyConversionProcessor` turns these rates into every converted cart, order and payment total (`Component/Core/Order/Processor/CartCurrencyConversionProcessor.php:129-143`). A rate of `0.0000000001` makes every product in that currency nearly free. A rate of `0` crashes the checkout (see C-05).
- **Fix direction:** call `$this->isGrantedOr403()` first in the override. Better: drop the override and fix the base action. The override exists only to "create if missing" and to skip `manager->clear()`, so either extend the base with a factory fallback, or send new rows to `addAction()` from `exchangeRate/panel.js` (see I-14).

## C-02 — A rate for the reverse pair (B→A next to A→B) makes `findOneWithCurrencyPair()` throw `NonUniqueResultException`. That is a 500 on every cart recalculation in that currency

- **Where:**
  - `Doctrine/ORM/ExchangeRateRepository.php:28-42`: matches both directions (`orX(from=A AND to=B, from=B AND to=A)`) and calls `getOneOrNullResult()`.
  - `Resources/config/doctrine/model/ExchangeRate.orm.xml:7-9`: the unique constraint covers only `(fromCurrency, toCurrency)`, so `(EUR, USD)` and `(USD, EUR)` can both exist.
  - `Resources/config/validation/ExchangeRate.yml`: no `UniqueEntity` and no direction-agnostic check.
- **Problem:** an admin who adds "USD → EUR" while "EUR → USD" already exists (a natural mistake in the grid UI) creates two matching rows. From then on `CurrencyConverter::getExchangeRate()` (`Component/Currency/Converter/CurrencyConverter.php:72`) throws on every call for that pair. `CartCurrencyConversionProcessor` runs on every cart recalculation, so every storefront request with a cart in either currency fails until someone deletes a row by hand. The Twig filter `coreshop_convert_currency` (used in `FrontendBundle/Resources/views/Common/Macro/currency.html.twig:12,18`) breaks every price display in that currency too.
- **Fix direction:**
  - Enforce one row per unordered pair: normalise on save (for example always store `min(id)` as `fromCurrency` and invert the rate), or add a validator that also checks the reverse direction, plus a DB-level guard (a generated `LEAST/GREATEST` columns unique index, or a check in a pre-persist listener).
  - Make the query deterministic until then: `->setMaxResults(1)` with an explicit `orderBy` (prefer the exact direction), so a duplicate cannot crash the storefront.

## C-03 — `isoCode` is neither unique nor `NOT NULL`. Duplicate codes crash `getByCode()`, and an empty code crashes the converter with a `TypeError`

- **Where:**
  - `Resources/config/doctrine/model/Currency.orm.xml:7-9`: plain `<index>` on `isoCode`, not a unique constraint. `:15`: `isoCode` is `nullable="true"`.
  - `Doctrine/ORM/CurrencyRepository.php:43-51`: `getByCode()` uses `getOneOrNullResult()` without `setMaxResults(1)`.
  - `Resources/config/validation/Currency.yml:5-6`: only `NotBlank`. No `UniqueEntity`, no `Length(3)`, no `Currency` constraint.
  - Consumers: `Component/Currency/Converter/CurrencyConverter.php:68-69`, `FrontendBundle/Controller/CurrencyController.php:49` (`switchAction`), `CoreBundle/Fixtures/Data/Application/{CurrencyFixture,CountryFixture}.php`.
- **Problem:**
  - Two currencies with the same code (a second "EUR" created in the admin, or an import) make `getByCode()` throw `NonUniqueResultException`. `CurrencyConverter` calls it for every conversion where source and target differ, so every cart and price display in a non-base currency returns 500. The frontend currency switcher fails as well, and so does re-running the fixtures.
  - `isoCode` can be `NULL` (validation only applies in the admin form group `coreshop`; imports, fixtures and API writes skip it). `CartCurrencyConversionProcessor::convert()` passes `$cart->getCurrency()->getIsoCode()` to `CurrencyConverterInterface::convert(int, string, string)`. Under `strict_types` a `null` there is a `TypeError`.
- **Fix direction:** make `isoCode` `NOT NULL` with a unique constraint (migration: deduplicate first, then reassign the FKs from countries, stores, orders and money-currency columns). Add `UniqueEntity(fields: isoCode)`, `Length(exactly: 3)` and `Currency` to the validation. Add `setMaxResults(1)` to `getByCode()` as a safety net. Longer term, key the converter by currency entity or id, not by code (I-17).

## C-04 — **(dependent)** Deleting a currency cascades the delete to every country that uses it

- **Where:** `CoreBundle/Resources/config/doctrine/model/Currency.orm.xml:5-8`: `one-to-many countries` with `orphan-removal="true"` and `<cascade-all/>`. The owning side is `CoreBundle/Resources/config/doctrine/model/Country.orm.xml:5-6` (`currencyId`, `nullable="true"`).
  The trigger is this bundle's generic resource `delete` route (`Resources/config/pimcore/routing.yml:1-10`) → `ResourceController::deleteAction()` → `$manager->remove($currency)`.
- **Problem:**
  - `cascade-all` includes `remove`, so deleting "USD" in the admin also removes "United States", "Ecuador", "El Salvador", and every other country linked to USD. Depending on the other FKs (addresses, zones, store/country joins, tax rules) this either deletes those rows too or fails with a foreign-key exception after a partial flush. Either result is data loss or a broken admin.
  - `orphan-removal` also means `Currency::removeCountry($country)` (`Component/Core/Model/Currency.php`) **deletes** the country instead of just unlinking it, even though the method calls `$country->setCurrency(null)`.
  - The owning side is already nullable, so the safe behaviour (unlink the countries) is what the schema allows.
- **Fix direction:** remove `orphan-removal` and `cascade-all` (keep at most `persist`). On currency delete, either refuse when countries, stores or orders still reference it (validation or a pre-remove listener with a clear message), or null the `currencyId` explicitly. Same review for `ExchangeRate.orm.xml:24,27` `on-delete="CASCADE"`: that one is intended, but document it.

## C-05 — A zero (or missing) exchange rate causes `DivisionByZeroError` on every conversion in the reverse direction

- **Where:**
  - `Component/Currency/Converter/CurrencyConverter.php:51`: `return (int) round($value / $exchangeRate->getExchangeRate());`
  - `Resources/config/validation/ExchangeRate.yml:7-9`: `NotBlank` + `Type(float)`. `NotBlank` accepts `0.0` and negative numbers.
  - `Form/Type/ExchangeRateType.php:31-35`: no `constraints`, no `attr.min`.
- **Problem:** an admin saving `0` (or clearing the field to `0` in the ExtJS number editor) for "EUR → USD" makes every USD → EUR conversion throw `DivisionByZeroError`. Doctrine hydrates the `decimal` column as the string `"0.0000000000"`, and in PHP 8 `int / "0.0…"` throws. As in C-02, this means a 500 on every cart recalculation and price render in that direction. A negative rate does not throw, but it produces negative prices and payment totals.
- **Fix direction:** add `Positive` (or `GreaterThan(0)`) to the validation. Also guard in the converter: treat a non-positive rate as "no rate" and log it, or throw a domain exception that the cart processor can handle. Do not let a raw `DivisionByZeroError` escape. Combine with B-01 (what to do when no usable rate exists).
