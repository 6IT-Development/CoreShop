# ThemeBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope and references: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md).
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by reading the vendored library source or the compiled container.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Theme resolution

### B-01 — `DocumentRenderListener` sets the theme for a rendered document and never restores it — (plausible)
- **Where:** `EventListener/DocumentRenderListener.php:38-43` subscribes only to `DocumentEvents::RENDERER_PRE_RENDER`; `:62` calls `SettableThemeContext::setTheme()`. There is no `RENDERER_POST_RENDER` handler (the event exists: `vendor/six-it/pimcore-source/lib/Event/DocumentEvents.php:163`, verified).
- **Impact:**
  - `Context/ThemeContext.php:45-47` gives the settable theme priority, so after any `Document\Renderer::render()` call (Pimcore mail, web-to-print, static page generator, custom code) **the rest of the request/process** uses the theme of that document.
  - Example (with `default_resolvers.pimcore_site` or `pimcore_document_property` enabled): a contact form controller of store B sends a Pimcore email document that resolves to theme A, then renders its response template → the page is rendered with theme A.
  - Same root cause as TODO-1 **C-01** for long-running processes.
- **Fix:** remember the previous theme in `preRender()` (stack, because renders can nest) and restore it in a `RENDERER_POST_RENDER` handler; restoring to "no theme" needs the resettable settable context from **C-01**. Make sure the stack is also popped when rendering throws (there is no post-render event in that case → reset on `kernel.reset` / `kernel.terminate`, or move the switch into a decorator of `DocumentRendererInterface` with `try/finally`).

### B-02 — Resolver priorities make the Pimcore site / document-property resolvers useless for page requests, and page vs. mail/PDF rendering pick different themes for the same document — (verified wiring, logical)
- **Where:**
  - `Resources/config/services.yml:24` (`pimcore_site`, priority 10) and `:33` (`pimcore_document_property`, priority 15).
  - `StoreBundle/Resources/config/services/theme.yml:9` (`coreshop_store`, priority 20). Compiled: the composite only gets `StoreThemeResolver` with priority 20 by default (`App_KernelDevDebugContainer.xml:14986-14991`, verified).
  - `StoreBundle/Theme/StoreThemeResolver.php:37` returns `$store->getTemplate()`, which is `NotBlank`-validated and defaults to `'standard'` (`Component/Store/Model/Store.php:46`, `StoreBundle/Resources/config/validation/Store.yml:7-8`), so it practically always returns a name.
  - `Service/CompositeThemeResolver.php:51-64`: `resolveThemeForDocument()` skips `StoreThemeResolver` (it does not implement `DocumentThemeResolverInterface`).
- **Impact:**
  - `resolveTheme()` (page requests): the store theme always wins; enabling the document-property resolver ("override the theme per document") has no effect while a store is resolvable.
  - `resolveThemeForDocument()` (mail/PDF/document rendering through `DocumentRenderListener`): the store resolver is skipped, so the document property / site wins. The same document gets **theme X on the website and theme Y in its mail/PDF rendering**.
- **Fix:** decide the intended precedence (most specific first is the usual expectation: document property > site > store) and make both entry points follow it. Options: raise the default priorities of the two Pimcore resolvers above 20 (or make them configurable, TODO-3 **I-11**), and add a document-aware store resolver (resolve the store from the document's site via `SiteBasedResolverInterface`, like `Component/Store/Context/RequestBased/PimcoreAreaBrickRenderResolver.php:41-45`). Document the order in the docs / `UPGRADE.md` (behaviour change).

### B-03 — A resolved theme name that does not exist yields "no theme" instead of trying the next resolver or falling back
- **Where:**
  - `Context/ThemeContext.php:56`: `findOneByName()` returns `?ThemeInterface` (`vendor/sylius/theme-bundle/src/Repository/ThemeRepositoryInterface.php:25`, verified); `null` is returned as is.
  - `Service/CompositeThemeResolver.php:38-49` returns the first name it gets, without checking that the theme exists.
  - `EventListener/DocumentRenderListener.php:56-60` and `Service/ThemeHelper.php:35-39` silently ignore an unknown name too.
- **Impact:** a typo in a store's `template`, a site root key that is not a theme (`PimcoreSiteThemeResolver`) or the default `'standard'` without an installed `standard` theme → no theme is active and the next resolver (lower priority) is never asked. In `ThemeHelper` the closure runs with whatever theme happens to be set (see **C-01**). No log entry anywhere.
- **Fix:** let the composite (or `ThemeContext`) skip names that `ThemeRepositoryInterface::findOneByName()` does not know and continue with the next resolver; log a warning (once per name) when a name is resolved but missing. In `ThemeHelper::useTheme()` log or throw when the requested theme does not exist.

### B-04 — `PimcoreSiteThemeResolver` dies with `Error` when the site has no root document — (verified API)
- **Where:** `Service/PimcoreSiteThemeResolver.php:66` calls `$site->getRootDocument()->getKey()`. `vendor/six-it/pimcore-source/models/Site.php:210`: `getRootDocument(): ?Document\Page` (verified).
- **Impact:** "Call to a member function getKey() on null" is not a `ThemeNotResolvedException`, so it bypasses the composite (`Service/CompositeThemeResolver.php:43`) and `ThemeContext` (`Context/ThemeContext.php:57`) → every page and every document render of that site fails (only with `default_resolvers.pimcore_site: true`; needs an inconsistent site record, e.g. after a root document was removed by a migration/import).
- **Fix:** `$key = $site?->getRootDocument()?->getKey(); if (null === $key || '' === $key) { throw new ThemeNotResolvedException(); }`.

### B-05 — `ThemeContext` and the Pimcore resolvers only look at the main request — (plausible)
- **Where:** `Context/ThemeContext.php:43`, `Service/PimcoreSiteThemeResolver.php:36`, `Service/PimcoreDocumentPropertyResolver.php:40` use `RequestStack::getMainRequest()`.
- **Impact:**
  - ESI / hinclude fragments that a reverse proxy fetches from `/_fragment` are main requests of their own: `DocumentResolver::getDocument()` returns `null`, `PimcoreDocumentPropertyResolver` then looks up the nearest document of `/_fragment` (→ `/` or the site root) and may pick a different theme than the page that embeds the fragment.
  - Sub-requests rendered for another document (e.g. a Pimcore `renderlet`/snippet of another site) are themed with the outer document, which is acceptable but undocumented.
- **Fix:** for fragment requests, resolve the document from the request attributes Pimcore sets on fragment rendering (`_document` / `contentDocument`) before falling back to the path; document the main-request behaviour.

### B-06 — `PimcoreDocumentPropertyResolver` can return the document of an earlier request in long-running processes — (dependent, verified API, plausible impact)
- **Where:**
  - `Service/PimcoreDocumentPropertyResolver.php:71` calls `Document\Service::getNearestDocumentByPath($basePath . $request->getPathInfo())`.
  - `vendor/six-it/pimcore-source/models/Document/Service.php:467-471,488`: the result is cached in `$this->nearestPathCache[$cacheKey]` with `$cacheKey = $ignoreHardlinks . implode('-', $types)` — **the path is not part of the key** (verified). `Pimcore\Model\Document\Service` is a shared service without `kernel.reset` (`App_KernelDevDebugContainer.xml:39282`, verified).
- **Impact:** in PHP-FPM each request starts fresh, so only a second lookup with a different path in the same request is wrong. In worker runtimes (FrankenPHP worker mode, RoadRunner) the first request's nearest document is returned for all following requests → its `theme` property is used for every page that has no Pimcore document (CoreShop product/category routes, custom Symfony routes).
- **Fix:** do not use `getNearestDocumentByPath()` here; walk the path up with `Document::getByPath()` (runtime-cached) or use a per-request local lookup. Report the cache-key bug to `six-it/pimcore-source` (key must include `$path`, service must implement `ResetInterface`).

---

## Configuration

### B-07 — `autoconfigure_with_attributes` is a `scalarNode`, but it is passed to a `bool` parameter under `strict_types`
- **Where:** `DependencyInjection/Configuration.php:35` (`scalarNode(...)->defaultFalse()`); `DependencyInjection/CoreShopThemeExtension.php:54` passes it to `Autoconfiguration::registerForAutoConfiguration(..., bool $autoconfigureWithAttributes)` (`Component/Registry/Autoconfiguration.php:30`).
- **Impact:** `autoconfigure_with_attributes: 'true'`, `1`, `~` or an `%env(...)%` value is accepted by the config tree and then fails container compilation with a `TypeError`, instead of a clear config error (or being cast).
- **Fix:** `->booleanNode('autoconfigure_with_attributes')->defaultFalse()->end()` (same pattern in the other CoreShop bundles' `Configuration` classes).

### B-08 — `#[AsThemeResolver(priority: …)]` is ignored unless `autoconfigure_with_attributes` is enabled — (dependent)
- **Where:** `DependencyInjection/CoreShopThemeExtension.php:49-55` → `Component/Registry/Autoconfiguration.php:31-37`: in the default (non-attribute) mode only `registerForAutoconfiguration(ThemeResolverInterface)->addTag($tag)` is done, without priority; the attribute is never read. In attribute mode, autoconfigured classes **without** the attribute are not tagged at all.
- **Impact:** a project resolver with `#[AsThemeResolver(priority: 30)]` silently gets priority 0 (lowest, behind store/site/property) with the default configuration.
- **Fix:** in `Autoconfiguration` always register the attribute callback as well (attribute wins for the priority), or document clearly that the attribute does nothing unless the flag is on. Fix in `Component/Registry` affects all bundles.
