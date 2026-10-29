# ThemeBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: all PHP in this bundle (17 files: `CoreShopThemeBundle.php`, `Attribute/`, `Context/`, `DependencyInjection/**`, `EventListener/`, `Service/`), `composer.json`, and all of `Resources/config/**` (`services.yml`, `services/profiler.yml`, `pimcore/config.yml`) plus `Resources/views/Collector/**`. Call paths were followed into `Component/Registry` (`PrioritizedCompositeServicePass`, `Autoconfiguration`), `StoreBundle` (`Theme/StoreThemeResolver.php`, `Resources/config/services/theme.yml`), `Component/Store` (`Model/Store.php`, `Context/RequestBased/PimcoreAreaBrickRenderResolver.php`) and the callers of `ThemeHelperInterface` (`CoreBundle/Order/OrderMailProcessor.php`, `OrderBundle/Renderer/{OrderDocumentPdfRenderer,PimcoreOrderDocumentPdfRenderer}.php`, `Component/Core/Notification/Rule/Action/Order/StoreMailActionProcessor.php`).

Reference for API checks: `vendor/` (`symfony/*` 7.4.21, `sylius/theme-bundle` 2.5.0, `six-it/pimcore-source`, `six-it/admin-ui-classic-bundle`) and the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml` (+ its `…Deprecations.log`). This bundle is byte-identical to the vendored `six-it/core-shop` copy (`diff -rq` is empty).

"Critical" here means: a security issue, data loss or data written wrongly, or an error that takes down every request, a whole area, or the install or upgrade path once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by reading the vendored library source or the compiled container.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers. `php -l` (PHP 8.4.27) passes on every PHP file, every service definition of this bundle resolves in the compiled container, and there are no implicitly nullable parameters. The container deprecation log has **two** entries for this bundle (missing `void` / `array` return types); they are listed in TODO-3 **I-02**.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Wrong output / data written wrongly

### C-01 — The temporarily selected theme is never reliably restored and is never reset, so it leaks into later mails, PDFs and pages — (verified code path, plausible impact)
- **Where:**
  - `Service/ThemeHelper.php:32-48` (`useTheme()`):
    - `:41` runs `$function()` without `try/finally`. If the closure throws (mail transport error, PDF renderer error, Twig error), the switched theme is **never** put back.
    - `:43-45` restores only `if ($backupTheme)`. When there was no theme before (the normal case: in a frontend request `ThemeContext` resolves the theme through the resolvers and `SettableThemeContext` is empty; in CLI / messenger it is always empty), the theme set at `:38` **stays set** after the call.
    - Restoring to "no theme" is not possible with the current API: `vendor/sylius/theme-bundle/src/Context/SettableThemeContext.php` has `setTheme(ThemeInterface $theme)` (not nullable) and no reset method (verified).
  - `Context/ThemeContext.php:45-47`: a non-null `SettableThemeContext` theme **always wins** over the resolvers, so a leaked theme overrides store/site/document resolution for everything rendered afterwards.
  - `Sylius\Bundle\ThemeBundle\Context\SettableThemeContext` is not tagged `kernel.reset` in the compiled container (verified, service at `App_KernelDevDebugContainer.xml:19478`), so the leaked theme survives between messenger messages and between requests in long-running runtimes (FrankenPHP worker, RoadRunner, Swoole).
  - The same leak is produced by `EventListener/DocumentRenderListener.php:62` (see TODO-2 **B-01**).
- **Impact:**
  - Callers: `CoreBundle/Order/OrderMailProcessor.php:118`, `OrderBundle/Renderer/OrderDocumentPdfRenderer.php:55`, `OrderBundle/Renderer/PimcoreOrderDocumentPdfRenderer.php:36`, `Component/Core/Notification/Rule/Action/Order/StoreMailActionProcessor.php:57`.
  - Messenger worker / CLI (multi-store): message 1 sends the order mail of store A through `useTheme('A')` → theme A stays in `SettableThemeContext`. Message 2 renders a mail, invoice or document without `useTheme()` (plain notification mail action, Pimcore mail, custom code) for store B → it is rendered with **store A's templates** (branding, footer, legal/imprint text, terms) and sent to store B's customer.
  - Frontend request: if `useTheme()` throws inside a caller that catches the exception (e.g. mail failures logged and ignored), the rest of the response is rendered with the other store's theme.
- **Fix:**
  - Wrap the call in `try { return $function(); } finally { restore }`.
  - Make "restore to no theme" possible: replace the use of Sylius' `SettableThemeContext` with an own settable context (e.g. `CoreShop\Bundle\ThemeBundle\Context\SettableThemeContext` implementing `ThemeContextInterface` and `Symfony\Contracts\Service\ResetInterface`, with `setTheme(?ThemeInterface)` and `reset()`), wire it in `services.yml` for `ThemeHelper`, `ThemeContext` and `DocumentRenderListener`, and tag it `kernel.reset` (`method: reset`).
  - Alternatively, if Sylius' class must stay: keep a stack inside `ThemeHelper` and only override the theme for the duration of the call through a decorating context that `ThemeContext` asks first (the override must be cleared in `finally`).
  - Add a unit test: theme before = null, closure throws → theme after = null; theme before = X → theme after = X.

---

## Whole area down

### C-02 — `PimcoreDocumentPropertyResolver` throws a `TypeError` (500) when the inheritable `theme` property is not a string — (verified code path; only when `core_shop_theme.default_resolvers.pimcore_document_property: true`)
- **Where:**
  - `Service/PimcoreDocumentPropertyResolver.php:74-75` and `:86-87` return `$document->getProperty('theme')` from a method declared `: string`, in a file with `declare(strict_types=1)`.
  - `vendor/six-it/pimcore-source/models/Element/AbstractElement.php:329`: `getProperty()` returns `mixed`. For property types `document`, `asset`, `object` it returns an element object, for `bool` it returns `true` (verified).
  - `:77` catches only `\Exception`; a `TypeError` is an `\Error`, so it is **not** turned into `ThemeNotResolvedException`.
  - `Service/CompositeThemeResolver.php:43,58` also catch only `ThemeNotResolvedException`, and `Context/ThemeContext.php:57` likewise, so the `TypeError` reaches Twig.
- **Impact:**
  - Pimcore properties are inheritable. One editor setting `theme` with the wrong property type (e.g. a document link or a checkbox) on a site root makes **every frontend page of that tree** fail with 500: `ThemeContext::getTheme()` is called for every template lookup and every `asset()` call (`vendor/sylius/theme-bundle/src/Asset/Package/PathPackage.php:53`).
  - The same happens in `DocumentRenderListener::preRender()` (`EventListener/DocumentRenderListener.php:50`) → every mail/PDF rendered from a document of that tree fails.
  - Priority 15 is below `StoreThemeResolver` (20, `StoreBundle/Resources/config/services/theme.yml:9`), so for page requests this only triggers when the store has no usable template or the store cannot be resolved; for document rendering (`resolveThemeForDocument()`) it triggers always (store resolver is not a document resolver).
- **Fix:**
  - Read the property once, accept it only when `\is_string($theme) && '' !== $theme`, otherwise throw `ThemeNotResolvedException` (optionally log a warning with the document path).
  - Share that check between `resolveTheme()` and `resolveThemeForDocument()` (let `resolveTheme()` call `resolveThemeForDocument()` once it has the document, as `PimcoreSiteThemeResolver` does).
  - In `ThemeContext::getTheme()` do **not** catch `\Throwable` broadly; fix the resolver instead so real errors stay visible.
