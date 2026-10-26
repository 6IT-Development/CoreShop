# LocaleBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Code quality, readability, logic and modernization items. None of them is a functional bug on its own. Items marked **(dependent)** live outside this bundle.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Architecture / Symfony 7.4

### I-01 — Replace the custom priority-composite machinery with Symfony's tagged iterator
- **Where:** `DependencyInjection/Compiler/CompositeLocaleContextPass.php`, `CoreShopLocaleBundle.php:27-32`, `Component/Locale/Context/CompositeLocaleContext.php` (`addContext()` + Laminas `PriorityQueue`), `Component/Registry/PrioritizedCompositeServicePass.php`.
- **Idea:** `CompositeLocaleContext` takes `iterable $localeContexts` in its constructor and the service is configured with `!tagged_iterator { tag: coreshop.context.locale, default_priority_method: … }` (or `#[AutowireIterator('coreshop.context.locale')]`). Symfony sorts by the tag's `priority` attribute at compile time and builds the list lazily. That removes the compiler pass, the method-call injection, the runtime `PriorityQueue`, and the `laminas/laminas-stdlib` dependency of `Component/Locale`. It also lets the composite exclude itself by id (helps **B-04**).
- **BC:** keep `addContext()` on the composite (append to the internal list, re-sort) for one minor, marked `@deprecated`.

### I-02 — Merge `Bundle`, `Extension` and `Configuration` into one `AbstractBundle`
- **Where:** `CoreShopLocaleBundle.php`, `DependencyInjection/CoreShopLocaleExtension.php`, `DependencyInjection/Configuration.php`.
- **Idea:** since Symfony 6.1, `AbstractBundle::configure(DefinitionConfigurator)`, `loadExtension(array, ContainerConfigurator, ContainerBuilder)` and `build()` cover all three files. The extension currently extends `AbstractModelExtension` (resource/Pimcore registration helpers) but registers no models, so that base class only adds coupling. If CoreShop wants to keep the `CoreShop<Name>Extension` pattern for consistency, at least extend `Symfony\Component\DependencyInjection\Extension\Extension` directly.
- **Note:** check the alias stays `core_shop_locale` (`AbstractBundle` derives `core_shop_locale` from `CoreShopLocaleBundle`, so it matches), because `CoreShopCoreExtension::prepend()` looks it up by that name.

### I-03 — Use native attributes for context registration instead of `AsLocaleContext` + `Autoconfiguration`
- **Where:** `Attribute/AsLocaleContext.php`, `DependencyInjection/CoreShopLocaleExtension.php:39-45`, `Component/Registry/Autoconfiguration.php` (reflection over public `get*` methods to build tag attributes).
- **Idea:** put `#[AutoconfigureTag('coreshop.context.locale')]` on `LocaleContextInterface` (Symfony ≥ 5.3) and let implementations set their priority with `#[AsTaggedItem(priority: 150)]` or a static `getDefaultPriority()`. Then `autoconfigure_with_attributes` becomes unnecessary for this bundle. If `AsLocaleContext` stays for BC, have it extend `Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag` so Symfony handles it natively without the reflection-based `getProperties()` helper.

### I-04 — Make services private by default and document the `lazy: true` reason
- **Where:** `Resources/config/services.yml:2-3` (`_defaults: public: true`), `:9-11`.
- **Idea:**
  - Only the `LocaleContextInterface` alias needs to be public, and only for legacy `$container->get()` callers. `FixedLocaleContext` and `PimcoreLocaleContext` should be private. Behat can get them through its own service config or the test container.
  - The comment "Quite Important to keep that, otherwise we get some pretty weird issues." does not say *what* breaks. Find out and write it down (most likely a circular dependency: `LocaleServiceInterface` → translator → … → locale context during container boot or early request). If the cause is gone, drop `lazy`.
  - Use `_defaults: { autowire: true, autoconfigure: false }` and drop the explicit `arguments` for `PimcoreLocaleContext`.

### I-05 — Move `FixedLocaleContext` to the test environment, or document it as a public override API
- **Where:** `Resources/config/services.yml:17-19`, `Behat/Resources/config/services/contexts/setup.yml:236`.
- **Idea:** decide what it is. If it is a test helper, register it in a `services_test.yml` (like `CoreBundle` does) so production has one context less to iterate. If it is meant as a public API (e.g. "render this e-mail in the customer's locale"), add a scoped helper such as `withLocale(string $locale, callable $fn)` that restores the previous value in `finally`, and document it. Depends on **B-07**.

### I-06 — Make locale resolution observable and cheaper
- **Where:** `Component/Locale/Context/CompositeLocaleContext.php:42-57`, `ResourceBundle/EventListener/ORMTranslatableListener.php:69-78` (`postLoad`).
- **Idea:**
  - `postLoad` resolves the locale for **every** translatable entity load. Each resolution walks admin context (request stack, token storage, `Tool::getValidLanguages()`) → fixed → Pimcore. A grid of 500 countries or states means 500 resolutions. Memoize per request in the composite (key it on the current request object, reset with `ResetInterface`), or resolve once in the listener per flush/request.
  - The final `LocaleNotFoundException` only chains the *last* failure. Collect the messages of all contexts ("admin: Not in Admin Mode; fixed: …; pimcore: …") so a failed resolution can be diagnosed from one log line.
  - Throw a clear message when no context is registered at all.

## PHP 8.4 idioms / readability

### I-07 — `AsLocaleContext`: readonly / asymmetric visibility instead of a getter-only private property
- **Where:** `Attribute/AsLocaleContext.php:22-33`.
- **Idea:** `final readonly class AsLocaleContext { public function __construct(public ?int $priority = null) {} }`, or PHP 8.4 `public private(set) ?int $priority`. Keep `getPriority()` while `Autoconfiguration::getProperties()` still reads tag attributes from getters (I-03 removes that need).

### I-08 — Clean up the empty constructors
- **Where:** `DependencyInjection/Compiler/CompositeLocaleContextPass.php:29-37` (`public function __construct(\n        ) {`), `Component/Locale/Context/CompositeLocaleContext.php:32-35` (same formatting, plus the property could be initialised inline).
- **Idea:** write `public function __construct()` on one line (or drop the constructor in the composite by initialising the property inline). Run PHP-CS-Fixer / ECS on both packages. The odd formatting is a leftover of removed constructor parameters.

### I-09 — Type the constant and move the tag name to the contract
- **Where:** `DependencyInjection/Compiler/CompositeLocaleContextPass.php:27` (`public const LOCALE_CONTEXT_SERVICE_TAG = 'coreshop.context.locale';`). The literal is repeated in `Resources/config/services.yml:15,19` and `CoreBundle/Resources/config/services/locale.yml:12`.
- **Idea:** PHP 8.3 typed class constant (`public const string LOCALE_CONTEXT_SERVICE_TAG`). Define it on `LocaleContextInterface` (or a small `LocaleContextTags` class in the component) so non-bundle code does not import a compiler pass to get a tag name. If I-01 is done, the pass disappears and the constant needs a new home anyway.

### I-10 — `readonly` promoted properties in the contexts
- **Where:** `Component/Locale/Context/PimcoreLocaleContext.php:26-29`, `Component/Core/Locale/Context/PimcoreAdminLocaleContext.php:32-38`, `Component/Resource/Translation/TranslatableEntityLocaleAssigner.php:28-31`, `Component/Core/Translation/TranslatableEntityPimcoreLocaleAssigner.php:29-32`.
- **Idea:** `private readonly …` for all injected dependencies. Add `#[\Override]` (PHP 8.3) on `getLocaleCode()` implementations, so a renamed interface method fails loudly. Note: `PimcoreLocaleContext` must stay non-final while it is `lazy: true` (I-04).

### I-11 — Type `LocaleNotFoundException` properly
- **Where:** `Component/Locale/Context/LocaleNotFoundException.php:23-48`.
- **Idea:**
  - `public function __construct(?string $message = null, ?\Throwable $previous = null)`. The untyped `$message` and the `\Exception`-only `$previousException` reject `\Error` (e.g. the `TypeError` from **B-02**) as a previous exception.
  - Type the factories: `notFound(string $localeCode)`, `notAvailable(string $localeCode, array $availableLocalesCodes)` with `@param list<string>`. Both are currently unused. Use `notAvailable()` in **B-03**, or delete them.
  - Consider extending a common `CoreShop\Component\…\ContextNotFoundException` shared by the currency/store/country contexts, so callers like `StoreBasedCartContext.php:60-66` can catch one type instead of three identical `catch` blocks.

### I-12 — `PimcoreAdminLocaleContext::getLocaleCode()` readability
- **Where:** `Component/Core/Locale/Context/PimcoreAdminLocaleContext.php:40-75`.
- **Idea:** remove the dead `$localizedTableLanguage = null` initialisation, replace `empty()` with explicit checks (`[] === $frontendLanguages`, `'' !== $backendLanguage`), replace `reset()` with `$frontendLanguages[array_key_first($frontendLanguages)]` or the default language (see **B-01**), and return directly from each branch. The comment "this should never happen" should become a log entry or a more specific exception message.

### I-13 — `LocaleChoiceType` simplifications and better labels
- **Where:** `Form/Type/LocaleChoiceType.php:38-60`.
- **Idea:**
  - `usort($locales, static fn (string $a, string $b): int => $a <=> $b)` is just `sort($locales)`. The `Options $options` parameter is unused.
  - Build the choices as `array_combine($labels, $codes)`. Then the identity `choice_value` and `choice_label` callbacks can go. The identity `choice_value` is already the default for string choices.
  - Show human-readable labels: `Symfony\Component\Intl\Locales::getName($code, $displayLocale)` (`symfony/intl` is already required by the root), with the code as a suffix (`Deutsch (Österreich) — de_AT`). Sort by label, not by code.
  - Wrap the choices in `ChoiceList::lazy($this, fn () => …)` so the list is built once per request and cached by the form factory.
  - Optional `allowed_locales`/`display_locale` options for callers that need a subset (e.g. per store).
  - Override `getBlockPrefix()` explicitly (`coreshop_locale_choice`) to avoid clashes with other `locale_choice` types and to keep theming stable.

### I-14 — `FixedLocaleContext` and `LocaleAwareInterface` types
- **Where:** `Component/Locale/Context/FixedLocaleContext.php`, `Component/Locale/Model/LocaleAwareInterface.php:25`.
- **Idea:** `LocaleAwareInterface::setLocaleCode(?string $localeCode)` has no return type. Add `: void` (or `: static`) in the next major (BC break for implementors, so add a `@return void` now). For `FixedLocaleContext`, see **B-07** (`?string`, `reset()`).

## Docs / tests / tooling

### I-15 — Add tests
- **Where:** there are no unit tests for this bundle or `Component/Locale`. Behat only touches `FixedLocaleContext`.
- **Idea:**
  - Unit: `CompositeLocaleContext` (priority order, fall-through on `LocaleNotFoundException`, all fail → exception, no contexts), `PimcoreLocaleContext` (valid, invalid → primary-language fallback, missing → exception, no default → **B-02**), `PimcoreAdminLocaleContext` (case preserved, primary-language match, **B-01**), `FixedLocaleContext::reset()`.
  - DI: compiler-pass test with a decorator and a double-tagged service (**B-04**), config test for `autoconfigure_with_attributes` (**B-05**).
  - Form: `LocaleChoiceType` single/multiple round-trip (**B-06**) with `TypeTestCase`.
  - Integration: loading a translatable entity in an untranslated locale and flushing must not create a translation row (**C-01**).

### I-16 — Document the contract and the built-in priorities
- **Where:** `README.md` (only boilerplate), `Component/Locale/Context/LocaleContextInterface.php` (no docblock).
- **Idea:** document on the interface that `getLocaleCode()` returns a configured Pimcore language code or throws `LocaleNotFoundException`, and never another exception type (**B-02**, **B-08**). In the README, list the built-in contexts and priorities (admin 300 from CoreBundle, fixed 200, Pimcore 100), how to add one (tag / attribute / `autoconfigure_with_attributes`), and the decorator caveat (**B-04**).

### I-17 — PHPStan level and baseline
- **Where:** `composer.json` `require-dev` (see **B-10**).
- **Idea:** once PHPStan 2 is aligned, run the bundle and the component at max level. The `@psalm-var` annotations in `CompositeLocaleContext.php:25-30` and `PimcoreLocaleContext.php:33-38` are Psalm-only. Replace them with `@var` generics PHPStan understands (`PriorityQueue<LocaleContextInterface, int>`, or a typed `list<LocaleContextInterface>` after I-01). The inline `@var string|null $pimcoreLocale` is redundant with the `?string` return type of `findLocale()`.

### I-18 — Consider caching the valid-language list
- **Where:** `Component/Locale/Context/PimcoreLocaleContext.php:44`, `Component/Core/Locale/Context/PimcoreAdminLocaleContext.php:61`, `Form/Type/LocaleChoiceType.php:43`, `Component/Resource/Translation/Provider/PimcoreTranslationLocaleProvider.php:27`.
- **Idea:** four places ask `Pimcore\Tool` for the language list independently. Route them all through `TranslationLocaleProviderInterface` (inject it instead of calling the static `Tool`). That makes the contexts unit-testable without a booted Pimcore and gives one place to add per-store language lists later.

### I-19 — (dependent) Remove the duplicate locale assigner
- **Where:** `Component/Resource/Translation/TranslatableEntityLocaleAssigner.php` and `Component/Core/Translation/TranslatableEntityPimcoreLocaleAssigner.php` are line-for-line identical except for the fallback locale (**C-01**). Only the first is wired.
- **Idea:** keep one (with the correct fallback), alias the other to it with `@deprecated`, and remove it in the next major.
