# Address Component – TODO 3: Improvements (code quality, readability, logic, PHP 8.4 idioms)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
None of these are defects on their own. They make the component cleaner, safer, or more idiomatic for the target stack.
Paths are relative to the component root unless noted otherwise. Bundle items (`C-`, `B-`, `I-`) are in `src/CoreShop/Bundle/AddressBundle/TODO-*.md`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

### CI-01 — Native types for models and interfaces (PHP 8.4)
- **Where:** `Model/*.php`, `Model/*Interface.php`, `Repository/*.php`, `Context/*.php`
- All properties are untyped (`protected $id;`, `protected $name;` …) and most accessors are docblock-typed only (`getIsoCode()`, `getName()`, `getZone()`, `getAddressFormat()`, `getSalutations()`, `setX($x)`). Add native property, parameter and return types (`?string`, `?ZoneInterface`, `array`, `static` for fluent setters). This is a BC break for implementors, so schedule it for a major version, or add the types to the classes first and to the interfaces in the next major version.
- `AddressInterface::getPhoneNumber()` has no return type, while every other getter has one. Use `?string`.
- `AddressIdentifierRepositoryInterface::findByName($name)` → `string $name`. Its docblock says `@return AddressIdentifierInterface` on a nullable method; the same applies to `CountryRepositoryInterface::findByCode()`.
- `CompositeCountryContext::addContext(…, $priority = 0)` → `int $priority = 0` (`CompositeRequestResolver::addResolver()` is already typed).
- The setter styles are inconsistent: `CountryTranslation::setName()` and `StateTranslation::setName()` return nothing, while `Country::setName()` and the others return `$this`. Pick one convention.
- `CountryNotFoundException::__construct(?\Exception …)` → `?\Throwable $previous = null`.
- Consider PHP 8.4 asymmetric visibility (`public private(set) ?int $id`) for ids.

### CI-02 — Simplify the context classes
- **Where:** `Context/**`
- `RequestBased/CountryContext` uses `\UnexpectedValueException` only to convert it into `CountryNotFoundException` a few lines later. Throw `CountryNotFoundException` directly; `assertCountryWasFound()` then becomes a one-liner (and covers CB-02).
- `CompositeCountryContext` and `CompositeRequestResolver` use `Laminas\Stdlib\PriorityQueue`. Symfony's `!tagged_iterator { tag: …, default_priority_method: … }` with `#[AutowireIterator]` already sorts by priority. That would remove both compiler passes in the bundle and the `laminas/laminas-stdlib` dependency. Note: this is a cross-bundle change, because `PrioritizedCompositeServicePass` lives in the Registry component.
- Malformed `__construct(\n        ) {` formatting (same as the bundle's I-14) in `CompositeCountryContext.php:33`, `CompositeRequestResolver.php:35`, `Model/Country.php:70`, `Model/State.php:54`, `Model/Zone.php:50`, `Model/CountriesAwareTrait.php:31`.
- Make `CountryContext`, `CompositeRequestResolver` and the formatter `final` with `readonly` promoted properties. `AddressFormatter` is the only non-final class here. Make it final, or document it as an extension point.

### CI-03 — `CountriesAwareTrait` should not declare a constructor
- **Where:** `Model/CountriesAwareTrait.php:31-34` (used by `Component/Core/Model/Store.php`)
- A trait constructor is silently overridden as soon as the using class declares its own constructor, which leaves `$countries` `null`. Use a private `initializeCountriesCollection()` (the pattern `TranslatableTrait` already uses), or lazy-initialise in the getter. `hasCountries()`, `hasCountry()` and `getCountries()` also lack native return types.

### CI-04 — Do not leak Doctrine ORM through the component repository interface
- **Where:** `Repository/CountryRepositoryInterface.php:27` (`createListQueryBuilder(): QueryBuilder`)
- The component interface returns an ORM `QueryBuilder`, which ties the storage-agnostic component to `doctrine/orm`. The bundle's `CountryRepository::createListQueryBuilder()` only returns `createQueryBuilder('o')`. Move the method to a bundle-level interface, or drop it if nothing uses it (verify the callers first).

### CI-05 — `AddressFormatter` performance and escaping
- **Where:** `Formatter/AddressFormatter.php`
- `createTemplate()` compiles on every call. Cache the compiled template per country and format hash. This matters on the checkout step, which formats every customer address.
- Escaping currently relies on the app-level `name` autoescape strategy, which treats `__string_template__*` as html. Escape explicitly instead (see CB-05). Sandboxing is CC-02.
- Add unit tests: null country (CC-01), plain-text vs HTML output (CB-05), and salutation translation key.
