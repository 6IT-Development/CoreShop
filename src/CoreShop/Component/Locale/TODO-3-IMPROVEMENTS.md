# Locale Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Code quality, readability, logic and modernization items. None of them is a functional bug on its own. Items marked **(dependent)** live outside this component.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.
Items already covered by the LocaleBundle TODOs are not repeated (see the overlap list in TODO-1). The ones below add to them or correct them.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Design

### I-01 — Split `PimcoreLocaleContext` into a request context and a default-language context, and drop `LocaleServiceInterface` / static `Tool`
- **Where:** `Context/PimcoreLocaleContext.php`, `Bundle/LocaleBundle/Resources/config/services.yml:9-15`.
- **Idea:**
  - `RequestLocaleContext` (priority 100): reads `_locale` from `RequestStack::getCurrentRequest()` (fixes TODO-2 **B-04**). It validates against the configured languages, tries `\Locale::getPrimaryLanguage()` (LocaleBundle **B-03**), and otherwise throws.
  - `DefaultLocaleContext` (lowest priority, e.g. `-1000`): returns `TranslationLocaleProviderInterface::getDefaultLocaleCode()` and throws when nothing is configured (TODO-2 **B-02**).
  - Then the fallback order is explicit and project contexts can sit in between. Injecting `RequestStack` + `TranslationLocaleProviderInterface` instead of `LocaleServiceInterface` + static `Pimcore\Tool` makes both unit-testable without a booted Pimcore (LocaleBundle **I-18**). It may also remove the reason for `lazy: true` (LocaleBundle **I-04**).
- **BC:** keep `PimcoreLocaleContext` as a `@deprecated` class that delegates to the two new contexts for one minor.

### I-02 — Encode the contract in types and docblocks
- **Where:** `Context/LocaleContextInterface.php:23`, `Model/LocaleAwareInterface.php:23-25`. This adds to LocaleBundle **I-16** (prose docs).
- **Idea:**
  - `/** @return non-empty-string  @throws LocaleNotFoundException */` on `LocaleContextInterface::getLocaleCode()`. PHPStan then flags implementations that can return `''` (TODO-2 **B-02**), and callers see that they must handle the exception.
  - On `LocaleAwareInterface`, document what `null` means and who supplies the fallback (TODO-2 **B-05**).
  - When the setter gets a return type (LocaleBundle **I-14**), use `: static`, not `: void`. The generated Pimcore setters (`CoreShopOrder::setLocaleCode(?string $localeCode): static`, same for `CoreShopCustomer`) already return `static`. `: void` would not be compatible with them, but `: static` is.

## Readability / PHP 8.4

### I-03 — `CompositeLocaleContext`: fix the property docblock and the formatting (correction to LocaleBundle I-08)
- **Where:** `Context/CompositeLocaleContext.php:25-35,46-56`.
- **Idea:**
  - `@var PriorityQueue|LocaleContextInterface[]` says "a queue **or** an array". It is always a queue. Use `@var PriorityQueue<LocaleContextInterface, int>` (laminas-stdlib 3.21 ships these generics) and drop the `@psalm-var` line.
  - `public function __construct(\n        ) {` → `public function __construct()` on one line.
  - LocaleBundle **I-08** suggests initialising the property inline. That is **not valid PHP**: `new` in initializers (PHP 8.1+) is allowed in parameter defaults, attribute arguments, static variables and global constants, but **not** in property defaults (also not in PHP 8.4). Keep the constructor, or switch to the tagged iterator (LocaleBundle **I-01**), which removes the issue.
  - The `continue;` in the `catch` block (`:52`) is redundant (it is the last statement of the loop body).

### I-04 — `PimcoreLocaleContext`: remove the wrong docblock and the dead branch
- **Where:** `Context/PimcoreLocaleContext.php:33-42`.
- **Idea:** the inline `@var string|null` / `@psalm-var string|null` contradicts `findLocale(): string`. It hides the dead `null` check from static analysis (that is why TODO-2 **B-01** went unnoticed). Remove both annotations. Then PHPStan reports the unreachable branch, and it gets replaced with the `''` check from TODO-2 **B-01** / **B-02**. If **I-01** is done, this class goes away.

### I-05 — `LocaleNotFoundException`: use the factories, or delete them
- **Where:** `Context/LocaleNotFoundException.php:30-48`. The typing is covered by LocaleBundle **I-11**.
- **Idea:** `notFound()` and `notAvailable()` have no caller in the repository. `notAvailable()` is the right message for the request context in **I-01** ("Locale "xx" is not available! The available ones are …"). Use it there. If **I-01** is not done, remove both, so the class does not suggest an API nobody uses.

### I-06 — (dependent) `CartCollector`: catch `\Throwable`, keep the data shape after `reset()`, and do not invent `'en'`
- **Where:** `OrderBundle/Collector/CartCollector.php:36-77`.
- **Idea:**
  - `catch (\Exception)` (`:69`) lets `\Error`s from a custom locale/cart context (`TypeError`, `ValueError`) escape from the profiler's `collect()`. In dev, that breaks the whole response. Use `\Throwable`. This is profiler code only, so it is safe.
  - `reset()` (`:74-77`) sets `$this->data = []`. After that, `getLocale(): string`, `getCart()` and `getAdmin(): bool` read missing keys (warning + `TypeError`). Reset to the same default array as the constructor.
  - The default `'locale' => 'en'` shows a locale nobody resolved. Use `null` (and `?string`), so the toolbar shows "unknown".

## Packaging / tests

### I-07 — `composer.json`: raise the `laminas/laminas-stdlib` floor, or drop the dependency
- **Where:** `composer.json:28` (`"laminas/laminas-stdlib": "^3.6"`). The root `composer.json:98` has the same constraint. Installed: 3.21.0 (declares `~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0`).
- **Idea:** `^3.6` allows releases that do not support PHP 8.4. Composer will refuse them on 8.4, so this is not a runtime bug, but `--prefer-lowest` CI runs and `--ignore-platform-reqs` installs can pick them. Raise the floor to the installed minor (`^3.21`), or remove the dependency completely with the tagged-iterator refactoring (LocaleBundle **I-01**). The other `composer.json` gaps (no `php` constraint, PHPStan 1.x, indentation) are LocaleBundle **B-10**. `"minimum-stability": "dev"` in a library package has no effect for consumers and can go too.

### I-08 — Add the tests that would have caught TODO-2 B-01 to B-04
- **Where:** no unit tests exist for this component. This adds to LocaleBundle **I-15**.
- **Idea:**
  - `PimcoreLocaleContext` (or the **I-01** replacements) with a real `Symfony\Component\HttpFoundation\Request`:
    - request without `_locale`: must not return Symfony's `default_locale` (**B-01**);
    - `findLocale()` returns `''`: throws (**B-01**/**B-02**);
    - empty Pimcore language list: throws (**B-02**);
    - sub-request with another `_locale` (**B-04**).
  - `CompositeLocaleContext`:
    - priority order;
    - fall-through on `LocaleNotFoundException`;
    - equal priorities keep insertion order (this is a Laminas `PriorityQueue` behaviour that the code relies on without saying so);
    - iterating twice gives the same result (the queue must not be consumed).
  - `StoreBasedCartContext`: the cart locale does not change on a non-localized request (**B-03**).
