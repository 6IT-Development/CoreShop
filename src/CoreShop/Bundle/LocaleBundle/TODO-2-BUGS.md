# LocaleBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this bundle (mostly in `Component/Locale`, `Component/Core` and `Component/Registry`), but they come from how the locale contexts are defined, wired or used. Fix them together with this bundle.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.
Items are roughly ordered by impact.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Locale resolution

### B-01 — (dependent) `PimcoreAdminLocaleContext` lower-cases locale codes (`de_AT` → `de_at`), so it returns codes that are not valid Pimcore languages
- **Where:** `Component/Core/Locale/Context/PimcoreAdminLocaleContext.php:68-72` (`strtolower($backendLanguage)` / `strtolower($first)`). It is registered with the highest priority (300) in `CoreBundle/Resources/config/services/locale.yml:5-12`, so it wins for every admin request.
- **Problem:**
  - Pimcore language codes are case-sensitive (`de_AT`, `en_GB`, `zh_Hans`). `Tool::isValidLanguage('de_at')` is `false`, and the Pimcore localized tables are named `object_localized_<classId>_de_AT`.
  - The CoreBundle reports build table names from this value: `CoreBundle/Report/CategoriesReport.php:104,135`, `ProductsReport.php:105,154`, `ManufacturerReport.php:111`. On a case-sensitive MySQL/MariaDB setup (the Linux default, `lower_case_table_names=0`), they fail with "table doesn't exist" for every admin whose resolved locale has a region part.
  - Doctrine translatable entities get `currentLocale = 'de_at'`, which never matches a stored translation. This feeds **C-01**.
  - `in_array($backendLanguage, $frontendLanguages)` (`:68`) is non-strict, and the comparison is done *before* the lower-casing, so the two halves of the method disagree on case.
  - The admin UI language is usually a plain language (`de`) while frontend languages are often regional (`de_AT`). There is no primary-language match, so a German admin gets the *first* frontend language (maybe `en_GB`) instead of `de_AT`.
- **Fix direction:** return the code exactly as configured in Pimcore (no `strtolower`). Use strict comparison. Add a primary-language fallback: first an exact match, then the first frontend language whose `\Locale::getPrimaryLanguage()` equals the admin language, then the default language (`Tool::getDefaultLanguage()`, not "first in list").

### B-02 — (dependent) `PimcoreLocaleContext` can throw a `TypeError` instead of `LocaleNotFoundException`
- **Where:** `Component/Locale/Context/PimcoreLocaleContext.php:44-46` returns `Tool::getDefaultLanguage()`, which is `?string` in Pimcore 11/12. The same applies to `Component/Resource/Translation/Provider/PimcoreTranslationLocaleProvider.php:32` (`getDefaultLocaleCode(): string`).
- **Problem:** when no valid language is configured (fresh install, broken `system_settings`, some CLI/test kernels), `getDefaultLanguage()` returns `null` and the `string` return type throws a `TypeError`. Every caller only catches `LocaleNotFoundException`: `CompositeLocaleContext.php:49`, `Component/Core/Context/ShopperContext.php:93`, `StoreBasedCartContext.php:64`, `TranslatableEntityLocaleAssigner.php:40`. So the composite does not fall through to lower-priority contexts, and the error ends up as a 500 on every request that touches the locale (cart, any translatable entity load).
- **Fix direction:** `$default = Tool::getDefaultLanguage(); if (null === $default) { throw new LocaleNotFoundException('No default Pimcore language configured'); }`. Do the same in `PimcoreTranslationLocaleProvider::getDefaultLocaleCode()`.

### B-03 — (dependent) `PimcoreLocaleContext` replaces an unknown request locale with the default language, so lower-priority contexts never run and close matches are lost
- **Where:** `Component/Locale/Context/PimcoreLocaleContext.php:44-46`.
- **Problem:**
  - For a request locale such as `de_CH` with configured languages `[en, de]`, the context returns `en` (the default), not `de`.
  - It never throws for an *invalid* locale, only for a *missing* one. Any context registered with a priority below 100 (custom contexts, e.g. "customer's preferred locale" or "store default locale") is never reached once Pimcore has any locale. That makes the composite effectively end at priority 100.
- **Fix direction:** try `\Locale::getPrimaryLanguage($pimcoreLocale)` (and `\Locale::canonicalize()`) against `Tool::getValidLanguages()` before giving up. When nothing matches, throw `LocaleNotFoundException::notAvailable($pimcoreLocale, Tool::getValidLanguages())` (the factory already exists and is unused), and let a final, low-priority "default language" context do the defaulting. That makes the fallback order explicit and pluggable.

### B-04 — (dependent) Interface-based autoconfiguration tags every `LocaleContextInterface` service, including decorators (infinite recursion) and explicitly tagged services (added twice)
- **Where:** `DependencyInjection/CoreShopLocaleExtension.php:39-45` → `Component/Registry/Autoconfiguration.php:33-40` (`registerForAutoconfiguration(LocaleContextInterface::class)->addTag('coreshop.context.locale')` when `autoconfigure_with_attributes` is `false`, the default). `Component/Registry/PrioritizedCompositeServicePass.php:46-54,65-70` then adds one `addContext()` call **per tag**.
- **Problem:**
  - An app that decorates `LocaleContextInterface` (e.g. to cache, or to force a locale for an API) with `autoconfigure: true` (the Symfony default in `config/services.yaml`) gets its decorator tagged as well. The decorator becomes a member of the composite it wraps, so `getLocaleCode()` recurses until the stack overflows.
  - An app service that is autoconfigured **and** carries an explicit `coreshop.context.locale` tag with a priority gets two tags: one with its priority and one without (priority 0). It is inserted into the queue twice. If it throws `LocaleNotFoundException`, it is called twice per resolution.
  - The pass does not skip the composite itself. If someone registers `CompositeLocaleContext` with autoconfigure on, it gets added to itself.
- **Fix direction:** fix it once in `PrioritizedCompositeServicePass` for all context bundles: skip the composite id, skip definitions with `getDecoratedService() !== null` (and classes that take the context interface as a constructor argument), and de-duplicate per service id (highest explicit priority wins). Document that decorators need `autoconfigure: false`. Same root cause as `Bundle/CurrencyBundle/TODO-2-BUGS.md` **B-18**.

## Configuration and DI

### B-05 — `autoconfigure_with_attributes` is a `scalarNode` but is passed to a `bool` parameter under `strict_types`
- **Where:** `DependencyInjection/Configuration.php:35` (`scalarNode(...)->defaultFalse()`) → `DependencyInjection/CoreShopLocaleExtension.php:44` → `Component/Registry/Autoconfiguration.php:31` (`bool $autoconfigureWithAttributes`). The value is also prepended by `CoreBundle/DependencyInjection/CoreShopCoreExtension.php:124-135` from `core_shop_core.autoconfigure_with_attributes` (also a `scalarNode`, `CoreBundle/DependencyInjection/Configuration.php:42`).
- **Problem:** `core_shop_locale: { autoconfigure_with_attributes: 'true' }`, `1`, or an env placeholder (`'%env(bool:CORESHOP_ATTRIBUTES)%'`, which is still a string placeholder at extension load time) makes the container build fail with a `TypeError` ("must be of type bool, string given"), because `CoreShopLocaleExtension.php` declares `strict_types=1`. A value such as `'no'` would be accepted by the tree and then crash.
- **Fix direction:** `->booleanNode('autoconfigure_with_attributes')->defaultFalse()->info('…')->end()` in this bundle and in `CoreBundle`. Env placeholders are not supported for this flag (it changes container structure), so document that and reject them with a clear message.

### B-06 — `LocaleChoiceType` with `multiple: true` returns a Doctrine `ArrayCollection` of strings
- **Where:** `Form/Type/LocaleChoiceType.php:31-36` adds `Symfony\Bridge\Doctrine\Form\DataTransformer\CollectionToArrayTransformer` as a model transformer whenever `multiple` is set.
- **Problem:** locales are plain strings, never a Doctrine association. `reverseTransform()` turns the submitted array into an `ArrayCollection`, so a model property typed `array` (e.g. a store's or rule's `locales`) receives a collection. With `by_reference`/property-access setters typed `array`, that is a `TypeError`; untyped setters store a serialized `ArrayCollection` object instead of an array. `transform()` also throws `TransformationFailedException` for any model value that is neither `null`, `array` nor `Collection` (e.g. a comma-separated string).
  The transformer also makes the bundle depend on `symfony/doctrine-bridge`, which is not declared (**B-10**).
- **Fix direction:** drop the transformer, so `multiple` returns `string[]` like any `ChoiceType`. If a collection-backed caller really exists, add the transformer in that caller's form type, not here.

### B-07 — (dependent) `FixedLocaleContext` is a stateful, public, shared service registered in production, with no way to reset or clear it
- **Where:** `Component/Locale/Context/FixedLocaleContext.php` (`private ?string $locale`, `setLocale(string)` only). Registered in `Resources/config/services.yml:17-19` with priority **200**, above `PimcoreLocaleContext` (100). Its only caller is `Behat/Context/Setup/LocaleContext.php:33`.
- **Problem:**
  - Once `setLocale()` is called, the locale overrides the request locale for the rest of the process lifetime. In long-running runtimes (FrankenPHP/RoadRunner workers, Messenger consumers, `kernel.reset` between Behat scenarios), it leaks into every following request or message.
  - There is no `reset()`/`clear()`, and `setLocale()` only accepts `string`, so a caller cannot undo it.
  - `if ($this->locale)` (`:32`) treats the valid string `'0'` as unset (cosmetic, but inconsistent with the `?string` type).
  - It is `public: true` (via `_defaults`), so any code can grab it from the container and change the shop-wide locale.
- **Fix direction:** implement `Symfony\Contracts\Service\ResetInterface` (`reset(): void { $this->locale = null; }`) and tag it `kernel.reset` (autoconfigure does this). Accept `?string` in `setLocale()`. Use `null !== $this->locale`. Consider registering it only in the test environment (`services_test.yml`) or giving it a priority that is documented as "explicit override". Tracked across contexts in `Component/Customer/TODO-2-BUGS.md` **B-04**.

## Consumers

### B-08 — (dependent) Locale codes from `LocaleContextInterface` are concatenated into SQL table names without validation
- **Where:** `CoreBundle/Report/CategoriesReport.php:71,104,135`, `ProductsReport.php:84,105,154`, `ManufacturerReport.php:111`.
- **Problem:** `LocaleContextInterface::getLocaleCode()` makes no promise that the code is a configured Pimcore language or even a safe identifier. The built-in contexts happen to return configured values (except **B-01**'s lower-cased variant). But any custom context registered through this bundle's tag, or `FixedLocaleContext` (**B-07**), can return arbitrary strings. These end up unquoted inside `FROM object_localized_…_<locale>`: a missing-table error at best, an SQL injection vector at worst.
- **Fix direction:** in the reports, validate with `Tool::isValidLanguage($locale)` (throw or fall back to the default language) and quote the identifier (`$connection->quoteIdentifier()`). Document on `LocaleContextInterface` that implementations must return a configured Pimcore language code (I-16).

### B-09 — (dependent) Customer registration fails with a 500 when no locale can be resolved
- **Where:** `FrontendBundle/Controller/RegisterController.php:61` (`$customer->setLocaleCode($this->container->get('coreshop.context.locale')->getLocaleCode())`).
- **Problem:** the call is not wrapped. When the composite throws `LocaleNotFoundException` (request without `_locale`, e.g. a registration form embedded on a non-localized route, or **B-02**), the customer form is valid but the request ends in an uncaught exception and the customer is not created. `ShopperContext::hasLocaleCode()` exists for exactly this check.
- **Fix direction:** use `ShopperContext` (`hasLocaleCode()` ? `getLocaleCode()` : `TranslationLocaleProviderInterface::getDefaultLocaleCode()`), or catch `LocaleNotFoundException` and fall back to the default language.

### B-10 — `composer.json` (bundle and component) does not declare what the code uses
- **Where:** `composer.json`, `Component/Locale/composer.json`.
- **Problem:**
  - Bundle: uses `symfony/http-kernel` (`Bundle`), `symfony/dependency-injection`, `symfony/config`, `symfony/form`, `symfony/options-resolver`, `symfony/doctrine-bridge` (`CollectionToArrayTransformer`, **B-06**) and `coreshop/registry` (`PrioritizedCompositeServicePass`, `Autoconfiguration`). None of them are required. `coreshop/registry` only arrives transitively through `coreshop/resource-bundle`.
  - Neither package has a `php` constraint (the monorepo root requires `~8.4.0`).
  - `require-dev` still pins PHPStan 1.x (`phpstan/phpstan ^1.10`, `phpstan-doctrine ^1.3.40`, `phpstan-webmozart-assert ^1.2.4`) while the root uses `^2.x`. Running PHPStan per package resolves a different major.
  - Component: uses `Pimcore\Tool` and `Pimcore\Localization\LocaleServiceInterface` through `six-it/pimcore-source` (fine), but has no `php` constraint either. The `"extra"` key is mis-indented (cosmetic).
  - Split packages installed standalone can therefore resolve to incompatible versions or miss classes at runtime.
- **Fix direction:** add `"php": "~8.4.0"`, the `symfony/*: ^7.4` packages above (or drop `doctrine-bridge` with **B-06**), `coreshop/registry: ^4.0`, and align `require-dev` with the root (`phpstan/phpstan ^2.1`, `phpstan-doctrine ^2.0`, `phpstan-webmozart-assert ^2.0`).

### B-11 — (dependent) `SEOBundle` configuration is a copy of this bundle's tree and still uses the root name `core_shop_locale`
- **Where:** `SEOBundle/DependencyInjection/Configuration.php:29` (`new TreeBuilder('core_shop_locale')`). The extension alias is `core_shop_seo`.
- **Problem:** validation errors for `core_shop_seo:` point to `core_shop_locale.autoconfigure_with_attributes`, and `config:dump-reference core_shop_seo` prints the wrong root key. Anyone debugging the locale config is sent to the wrong bundle.
- **Fix direction:** `new TreeBuilder('core_shop_seo')`. Belongs to SEOBundle, listed here because it was copied from this file. If I-02 moves both into `AbstractBundle::configure()`, the copy goes away.
