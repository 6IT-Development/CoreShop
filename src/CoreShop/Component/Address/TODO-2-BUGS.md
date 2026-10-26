# Address Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Bugs are incorrect behaviour, latent crashes on uncommon paths, and wrong packaging.
Paths are relative to the component root unless noted otherwise. Bundle items (`C-`, `B-`, `I-`) are in `src/CoreShop/Bundle/AddressBundle/TODO-*.md`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Context

### CB-01 — Stateful country contexts are never reset (long-running runtimes)
- **Where:** `Context/RequestBased/CachedCountryContext.php`, `Context/FixedCountryContext.php`; wired in `AddressBundle/Resources/config/services.yml:22-24` (`FixedCountryContext`, public and mutable) and `:29-33` (`CachedCountryContext` decorating `CompositeRequestResolver`)
- **Problem:** `CachedCountryContext` caches the first resolved country **for the lifetime of the service** and ignores the `$request` argument. Sub-requests and every later request in worker mode or Messenger workers get the stale country, which then drives wrong tax, shipping and price rules. `FixedCountryContext` also leaks between requests once `setCountry()` has been called. Neither implements `ResetInterface` or carries a `kernel.reset` tag.
- **Fix:** implement `Symfony\Contracts\Service\ResetInterface` and tag the services with `kernel.reset` (bundle side), or cache per request (`\WeakMap<Request, CountryInterface>`).

### CB-02 — A resolver returning `null` (allowed by its interface) causes a `TypeError` instead of a fallback
- **Where:** `Context/RequestBased/RequestResolverInterface.php` (`?CountryInterface`) vs `CachedCountryContext::findCountry(): CountryInterface` and `CompositeRequestResolver::findCountry(): CountryInterface`. Both are wired in `AddressBundle/Resources/config/services.yml:26-33`.
- **Problem:** A custom resolver (registered via the `coreshop.context.country.request_based.resolver` tag or `#[AsRequestBasedResolverCountryContext]`) that returns `null` triggers a `TypeError`. `CompositeCountryContext` catches only `CountryNotFoundException`, so the request ends in a 500 instead of falling back to the next context.
- **Fix:** convert `null` to `CountryNotFoundException` in the composite, or narrow the interface to a non-null return and document that resolvers must throw. See CI-02.

## Models

### CB-03 — `Country::$states` is never initialised; `getStates()` is not part of the interface
- **Where:** `Model/Country.php:58, 70-73, 112-123`; `Model/CountryInterface.php`
- **Problems:**
  1. The constructor initialises only the translations. On a **new** (non-hydrated) `Country`, `getStates(): Collection|array` returns `null` and throws a `TypeError`. Fixtures, factories and unit tests hit this before the first flush.
  2. `setStates(Collection|array)` lets callers replace the Doctrine collection with a plain array, which breaks change tracking for the inverse side.
  3. `getStates()` is missing from `CountryInterface`. Code that only has the interface (for example the bundle's B-02 "state belongs to country" check) cannot use it without a `method_exists` or instanceof cast.
- **Fix:** initialise `$this->states = new ArrayCollection()` in the constructor. Type it as `Collection` only. Add `getStates()`, `hasState()`, `addState()` and `removeState()` to the interface, and keep both sides in sync (`State::setCountry()`).

### CB-04 — `AddressesAwareTrait` treats all unsaved addresses as the same address
- **Where:** `Model/AddressesAwareTrait.php:28-43, 59-68`
- **Problems:**
  1. `hasAddress()` compares by `getId()`. Unsaved addresses all have `null` ids, so `null === null` is true: `addAddress()` silently drops the second unsaved address, and `removeAddress()` removes **every** unsaved address. The current callers (`OrderBundle/Controller/CustomerCreationController.php:94`, `AddressCreationController.php:70`, `Component/Core/Customer/CustomerTransformHelper.php:146`) save the address first, so this is latent. Any new or third-party caller that does not save first is affected.
  2. `removeAddress()` keeps the original keys from `array_filter`, so the holder receives a non-list array (for example `[0 => …, 2 => …]`).
- **Fix:** compare by identity (`===`) when either id is `null`, otherwise by id. Wrap the result with `array_values(array_filter(...))`.

## Formatter

### CB-05 — `AddressFormatter` plain-text output is HTML-escaped; the non-Pimcore fallback sees no data
- **Where:** `Formatter/AddressFormatter.php:35-39, 49-53`
- **Problems:**
  1. With Symfony's default `twig.autoescape: name`, a string template (`__string_template__…`, no file extension) is escaped as **html**. `formatAddress($address, asHtml: false)`, used in `OrderBundle/Controller/OrderController.php:377` for plain-text and JSON values, still returns `Müller &amp; Söhne`.
  2. `get_object_vars($address)` is called from outside the object, so it returns only **public** properties. For any non-Pimcore `AddressInterface` implementation (protected properties), the template receives no variables and renders an empty address.
  3. The salutation is translated with the **raw** value (`coreshop.form.customer.salutation.` + `getSalutation()`), while the bundle's `SalutationChoiceType` uses a normalised key. Both are described in the bundle's B-06, and both must share one helper.
- **Fix:** wrap the format in `{% autoescape asHtml ? 'html' : false %}` (or render with an explicit strategy). Build the variables from the interface getters (`firstname`, `lastname`, …) instead of from object internals.

## Packaging

### CB-06 — The component `composer.json` does not describe its real requirements
- **Where:** `composer.json`
- **Problems:**
  1. No `"php": "^8.4"` constraint.
  2. Undeclared dependencies: `symfony/http-foundation` (`Request`, `RequestStack`), `symfony/translation-contracts`, `twig/twig`, `doctrine/collections`, and `doctrine/orm` (`QueryBuilder` in `Repository/CountryRepositoryInterface.php`).
  3. `phpstan/phpstan ^1.10` (and its plugins at `^1.x`) conflicts with the root `^2.1`. `six-it/pimcore-source ^2.0` vs root `^2.1`. The `branch-alias` is `4.1-dev`; verify it.
- **Fix:** declare the dependencies with `^7.4` (Symfony) constraints and align them with the root `composer.json`. See CI-04 for removing the ORM dependency from the interface.
