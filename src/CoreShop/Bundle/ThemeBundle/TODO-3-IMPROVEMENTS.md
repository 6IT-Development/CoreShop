# ThemeBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are improvements to code quality, readability, logic, performance, PHP 8.4 modernisation and forward compatibility (Symfony 8, `sylius/theme-bundle` 3). None of them is a functional bug on its own.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle.

**BC note:** `ThemeResolverInterface`, `DocumentThemeResolverInterface` and `ThemeHelperInterface` are implemented/used in `StoreBundle/Theme/StoreThemeResolver.php`, `CoreBundle/Order/OrderMailProcessor.php`, `OrderBundle/Renderer/{OrderDocumentPdfRenderer,PimcoreOrderDocumentPdfRenderer}.php`, `Component/Core/Notification/Rule/Action/Order/StoreMailActionProcessor.php`, `Behat/Context/Domain/ThemeContext.php` and in projects (custom resolvers). `CompositeThemeResolver::register()` is called by the compiler pass. Every signature or service-id change below must be made together with those users and listed in `UPGRADE.md`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 / Symfony 7.4 compatibility and forward compatibility

### I-01 — `composer.json` does not describe what the bundle needs
- **Where:** `composer.json:26-37`.
- **Fix:**
  - Add `"php": "~8.4.0"` (root `composer.json:88`).
  - Add the Symfony packages used directly, all `^7.4`: `symfony/dependency-injection`, `symfony/config`, `symfony/http-foundation`, `symfony/event-dispatcher` (or `-contracts`), `symfony/http-kernel` (`Extension`, bundle). Add `symfony/service-contracts` once **C-01** (`ResetInterface`) is done.
  - `"coreshop/pimcore-bundle"` is not used by any class of this bundle (only `Pimcore\…` from `six-it/pimcore-source`); remove it or document why it is needed.
  - `"laminas/laminas-stdlib"` is only used for `PriorityQueue` in `Service/CompositeThemeResolver.php:21`; remove it together with **I-04**.
  - `"sylius/theme-bundle": "^2.2"`: raise to the version that is actually tested with Symfony 7.4 (`^2.5`, installed 2.5.0).
  - `require-dev`: align with the root (`phpstan/phpstan ^2.1`, `phpstan-doctrine ^2.0`); drop `phpstan-doctrine` / `phpstan-webmozart-assert`, the bundle uses neither Doctrine nor `Webmozart\Assert`.
  - Fix the indentation of `"repositories"` (`:43-48`).

### I-02 — Add the native return types Symfony 8 will require — (verified: 2 entries in the container deprecation log)
- **Where / Fix:**
  - `DependencyInjection/Compiler/RemoveThemeAwareTranslatorPass.php:27`: `process(ContainerBuilder $container): void` (logged: "might add `void` as a native return type").
  - `EventListener/DocumentRenderListener.php:38`: `getSubscribedEvents(): array` (logged: "might add `array`"); `:45` `preRender(DocumentEvent $event): void`.
  - `Service/ThemeHelperInterface.php:23` / `Service/ThemeHelper.php:32`: `useTheme(...): mixed`.
  - Add `#[\Override]` on all interface implementations (`resolveTheme()`, `resolveThemeForDocument()`, `getTheme()`, `process()`, `load()`, `getConfigTreeBuilder()`, `build()`, bundle getters) so signature drift is caught by PHP.

### I-03 — PHP 8.4 modernisation
- **Where / Fix:**
  - Promoted constructor properties → `private readonly`: `Context/ThemeContext.php:33-37`, `EventListener/DocumentRenderListener.php:32-34`, `Service/PimcoreDocumentPropertyResolver.php:30-33`, `Service/PimcoreSiteThemeResolver.php:29-30`, `Service/ThemeHelper.php:27-28`, `Attribute/AsThemeResolver.php:25` (or `final readonly class` where all properties are readonly).
  - Typed class constant: `DependencyInjection/Compiler/CompositeThemeResolverPass.php:27` → `public const string THEME_RESOLVER_TAG`.
  - `final` for classes that are not meant to be extended: `EventListener/DocumentRenderListener.php:29`, `DependencyInjection/CoreShopThemeExtension.php:32`, `Service/ThemeNotResolvedException.php:21` (check projects first, BC).
  - `CoreShopThemeBundle.php:57-58`: `class_exists('\\CoreShop\\…\\Version')` → `class_exists(Version::class)` with a `use` (no autoload side effect difference, but readable and refactor-safe).

### I-04 — Replace the custom composite registration (Laminas `PriorityQueue` + compiler pass) with a tagged iterator
- **Where:** `Service/CompositeThemeResolver.php:26-36`, `DependencyInjection/Compiler/CompositeThemeResolverPass.php`, `CoreShopThemeBundle.php:41`.
- **Fix:** inject `#[AutowireIterator(CompositeThemeResolverPass::THEME_RESOLVER_TAG)] iterable $themeResolvers` (or `!tagged_iterator { tag: coreshop.theme.resolver }` in YAML). Symfony already sorts by the tag's `priority` attribute (higher first, same as now). Keep `register()` for one major as deprecated (BC), drop `laminas/laminas-stdlib`. Exclude the composite itself from the iterator (`exclude: [CompositeThemeResolver]`) so an autoconfigured composite can never register into itself.
  - Accept resolvers that implement **only** `DocumentThemeResolverInterface` (today `register(ThemeResolverInterface …)` at `:33` makes a document-only resolver impossible).

### I-05 — Use the Sylius config instead of removing the translator by hand; modernise `pimcore/config.yml`
- **Where:**
  - `DependencyInjection/Compiler/RemoveThemeAwareTranslatorPass.php`: removes `ThemeAwareTranslator` only. The rest of Sylius' translation integration (`vendor/sylius/theme-bundle/src/Resources/config/services/integrations/translations.php`, e.g. `ThemeTranslatorResourceProvider`, `App_KernelDevDebugContainer.xml:20661`) is still registered and unused (verified).
  - `Resources/config/pimcore/config.yml:9`: `%kernel.project_dir%/app/Resources/themes` is the Symfony 3 directory layout.
- **Fix:**
  - Set `sylius_theme: { translations: { enabled: false } }` (`vendor/sylius/theme-bundle/src/DependencyInjection/Configuration.php:44`, `SyliusThemeExtension.php:54`), then delete `RemoveThemeAwareTranslatorPass` and its registration (`CoreShopThemeBundle.php:42`).
  - Move the `sylius_theme` defaults into `CoreShopThemeExtension` via `PrependExtensionInterface::prepend()` so they are explicit and can be overridden in a predictable order; drop the `app/Resources/themes` directory (or keep it behind a deprecation note in `UPGRADE.md`).

---

## Dead code and dead configuration

### I-06 — Remove the unused registry service and the bogus tag
- **Where:** `Resources/config/services.yml:5-11` (`coreshop.theme.resolver.registry`, `PrioritizedServiceRegistry`) — nothing registers into it and nothing injects it (only the composite is filled by the pass). `:16-17` tags `CompositeThemeResolver` with a tag literally named `@coreshop.theme.resolver.registry` (compiled as `<tag name="@coreshop.theme.resolver.registry"/>`, `App_KernelDevDebugContainer.xml:14987`, verified) — a service reference written as a tag; it does nothing.
- **Fix:** delete both (check projects for `coreshop.theme.resolver.registry` first; if used, deprecate the id).

### I-07 — `services/profiler.yml` and the collector template reference classes that do not exist
- **Where:** `Resources/config/services/profiler.yml:5-8` (`CoreShop\Bundle\ThemeBundle\Collector\ThemeCollector`, `CoreShop\Bundle\ThemeBundle\Service\ActiveThemeInterface` — neither exists); the file is never loaded (`DependencyInjection/CoreShopThemeExtension.php:39` only loads `services.yml`). `Resources/views/Collector/theme.html.twig` and `Collector/Icon/theme.svg` are only referenced from that file.
- **Fix:** delete the three files. Sylius already registers its own `ThemeCollector` (`App_KernelDevDebugContainer.xml:8189`). If a CoreShop panel is wanted (e.g. "which resolver won"), implement it properly and load `profiler.yml` only when `kernel.debug` and `WebProfilerBundle` are present.

---

## Readability, structure, logic

### I-08 — Deduplicate "find the current document" and the brick-render route check
- **Where:** the route name `'pimcore_admin_document_page_areabrick-render-index-editmode'` is hard-coded in `Context/ThemeContext.php:49`, `Service/PimcoreSiteThemeResolver.php:42`, `Service/PimcoreDocumentPropertyResolver.php:48` and `Component/Store/Context/RequestBased/PimcoreAreaBrickRenderResolver.php:37`. The block "brick render → `Document::getById(documentId)` else `DocumentResolver::getDocument()`" is copied in `PimcoreSiteThemeResolver.php:42-53` and `PimcoreDocumentPropertyResolver.php:48-59`.
- **Fix:** one small service (e.g. `Service/RequestDocumentResolver::resolve(Request): ?Document`) plus a constant for the route name, used by all four places (the Store component one is **(dependent)**). Then `resolveTheme()` in both resolvers becomes "get document → `resolveThemeForDocument()`".

### I-09 — Exception handling in the resolvers
- **Where:**
  - `Service/ThemeNotResolvedException.php:23-26`: the constructor takes only `?\Exception $previousException`; no custom message, no `\Throwable` previous.
  - `Service/PimcoreDocumentPropertyResolver.php:39-81`: the whole body is in `try { … } catch (\Exception $ex)`, so the `ThemeNotResolvedException` thrown at `:43` is caught and wrapped in another `ThemeNotResolvedException`, and real failures (DB down, Pimcore errors) are silently turned into "no theme".
- **Fix:** constructor `(string $message = 'Theme could not be resolved', ?\Throwable $previous = null)` plus named constructors if useful (`::forDocument(Document $d)`); narrow the `try` to the Pimcore calls that are expected to fail, or drop it and let real errors surface (log at least).

### I-10 — Make `ThemeHelperInterface` type-safe and explicit
- **Where:** `Service/ThemeHelperInterface.php:23`, `Service/ThemeHelper.php:32-48`.
- **Fix:** `@template T` / `@param \Closure(): T $function` / `@return T`, native `: mixed`; accept `callable` instead of `\Closure` (callers pass closures, BC-safe). Together with **C-01** (restore in `finally`) and **B-03** (unknown theme: log or throw).

### I-11 — Configuration tree: types, docs and options
- **Where:** `DependencyInjection/Configuration.php:33-44`.
- **Fix:** `booleanNode` for `autoconfigure_with_attributes` (**B-07**); add `->info()` to every node (what each default resolver does, which priority it has); make the priorities of `pimcore_site` / `pimcore_document_property` configurable (needed for **B-02**) and the property name (`'theme'`, hard-coded at `Service/PimcoreDocumentPropertyResolver.php:74,86`).

### I-12 — Small readability fixes
- **Where / Fix:**
  - Empty constructors with odd formatting `public function __construct(\n        ) {`: `Service/CompositeThemeResolver.php:28-29`, `DependencyInjection/Compiler/CompositeThemeResolverPass.php:29-30`. Initialise the property inline / format normally.
  - `EventListener/DocumentRenderListener.php:52`: `if (!$themeName)` on a `string` → `if ('' === $themeName)` (`'0'` is a valid name); `:64` comment `//Ignore` → explain why it is ignored.
  - `Service/ThemeResolverInterface.php:23-25`: docblock "Resolve Current Theme" → document the contract ("returns a theme name, throws `ThemeNotResolvedException` if this resolver cannot decide").
  - `Service/PimcoreSiteThemeResolver.php:66`: assignment inside the condition (`$site && $theme = …`) → separate statements; document that the **root document key** is used as the theme name.
  - Naming: `PimcoreDocumentPropertyResolver` vs. `PimcoreSiteThemeResolver` → `PimcoreDocumentPropertyThemeResolver` (with a deprecated alias, BC).
  - `Context/ThemeContext.php:45-58` calls `$this->settableThemeContext->getTheme()` up to three times; read it once.

### I-13 — `services.yml`: everything is public and wired by hand
- **Where:** `Resources/config/services.yml:2-3` (`_defaults: public: true`), explicit argument lists at `:20-22`, `:27-31`, `:36-39`, `:42-47`, `:50-53`.
- **Fix:** `_defaults: { autowire: true, autoconfigure: false, public: false }`; keep `public: true` only for ids projects fetch from the container (`ThemeHelperInterface`, `ThemeResolverInterface` — check, BC); drop the explicit argument lists once all constructor types are unambiguous. Consider `config/` at the bundle root + `AbstractBundle` in the next major (Symfony best practice), which also lets **I-05** move into `prependExtension()`.

---

## Performance

### I-14 — Memoise the resolved theme per request
- **Where:** `Context/ThemeContext.php:41-60`. Sylius calls `ThemeContextInterface::getTheme()` for every template lookup and every `asset()` call (`vendor/sylius/theme-bundle/src/Asset/Package/PathPackage.php:53`, verified). Each call runs the whole resolver chain again: store context, `Document::getById()`, `DocumentResolver`, `getNearestDocumentByPath()` (several `Document::getByPath()` lookups when the path has no document).
- **Fix:** cache the result per main request (`\WeakMap<Request, ?ThemeInterface>` or a request attribute such as `_coreshop_theme`), invalidate it when the settable theme changes, and implement `ResetInterface` (`kernel.reset`) so it does not leak between requests/messages (see **C-01**).

---

## Tests

### I-15 — The bundle has no tests
- **Fix:** unit tests for `CompositeThemeResolver` (order, skip on `ThemeNotResolvedException`, document-only resolvers), `ThemeHelper` (restore on exception / to null — **C-01**), `ThemeContext` (admin context, brick rendering, settable precedence, unknown theme — **B-03**), `PimcoreDocumentPropertyResolver` with non-string property values (**C-02**), and a container test that compiles the bundle with both default resolvers on and off.
