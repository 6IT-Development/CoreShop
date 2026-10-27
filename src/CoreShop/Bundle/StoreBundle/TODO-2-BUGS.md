# StoreBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs that give wrong results, wrong UI behaviour or avoidable 500s in a limited area, but do not take down a whole area on their own.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code (PHP 8.4.27) or by reading the vendored library source / the compiled container.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Store resolution

### B-01 — `CachedStoreContext` and `FixedStoreContext` are never reset: wrong store in long-running processes — (verified code path) (plausible: impact) (dependent)
- **Where:** `Component/Store/Context/RequestBased/CachedStoreContext.php:27-46`, `Component/Store/Context/FixedStoreContext.php:25-39`, wired in `Resources/config/services.yml:23-30` without a `kernel.reset` tag. Compiled container: neither service has `kernel.reset`.
- **Problem:**
  - The cached store lives as long as the service instance, not as long as the request. In worker runtimes (FrankenPHP worker mode, RoadRunner, Swoole via `symfony/runtime`), in functional tests that keep the kernel between requests (`KernelBrowser::disableReboot()`), and in any process that handles several main requests, the store resolved for the **first** request is returned for all following ones. On a multi-site shop this means wrong store → wrong currency, prices, taxes, theme and country list for every later visitor of another site. `services_resetter` only resets services tagged `kernel.reset`. Same pattern as CurrencyBundle TODO-2 (cached currency context).
  - `FixedStoreContext` (priority 2, above the request-based context) keeps a store set by one process step for the rest of the process.
  - The result is cached on the first call, whenever that is. A `kernel.request` listener that runs before Pimcore has set `Site::$currentSite` (or before routing set `_route`, which the admin resolvers need) caches the default store for the whole request. (plausible)
  - Misses are not cached: `elseif (!$this->cachedStore)` at `:41` is dead code (when `initialized` is `true`, `cachedStore` is always set, because an exception leaves `initialized` `false`). On admin / CLI requests with no store, every `getStore()` call re-runs all resolvers (Document and Site lookups, DB queries).
- **Fix:** implement `Symfony\Contracts\Service\ResetInterface` in both classes (`reset(): void` clears the state) and tag them `kernel.reset` (`method: reset`). Cache per main request (`spl_object_id($request)` or a request attribute such as `_coreshop_store`) instead of per service. Cache the "not found" result as well.

### B-02 — A request resolver that returns `null` stops the resolver chain — (verified code path) (dependent)
- **Where:** `Component/Store/Context/RequestBased/RequestResolverInterface.php:27-32` (`?StoreInterface` return type, docblock says `@return StoreInterface` + `@throws`), `CompositeRequestResolver.php:40-51`, `StoreContext.php:44-68`.
- **Problem:** the interface allows `null`, but `CompositeRequestResolver::findStore()` returns the first result that does not throw, including `null`. A custom resolver (registered via `#[AsRequestBasedResolverStoreContext]` or the tag) that returns `null` for "not mine" therefore prevents lower-priority resolvers from running — including `SiteBasedRequestResolver` (priority 100), which provides the default store. `StoreContext` then turns `null` into `StoreNotFoundException`, so the shop has no store on those requests (TODO-1 **C-02** impact).
- **Fix:** in the composite, `continue` when a resolver returns `null`. Decide the contract: either make the return type `StoreInterface` (throw only) or document `null` as "not responsible". Update the docblock accordingly.

### B-03 — `PimcoreAdminSiteBasedRequestResolver` swallows its own "not found" and every other exception — (verified code path) (dependent)
- **Where:** `Component/Store/Context/RequestBased/PimcoreAdminSiteBasedRequestResolver.php:57-75`.
- **Problem:**
  - When a site is found but `resolveSiteWithDefaultForStore()` returns `null`, the `throw new StoreNotFoundException()` at `:65` is caught by `catch (\Exception)` at `:70`, and the loop continues with the parent document instead of stopping. Harmless today, but it is not what the code says.
  - `catch (\Exception)` also hides DB errors and `NonUniqueResultException` (TODO-1 **C-01**), so the admin preview shows a store while the storefront crashes, which makes the problem hard to find.
  - `Site::getByRootId()` already returns `null` on "not found" (`vendor/six-it/pimcore-source/models/Site.php:94-104`), so the `try` is not needed for that case.
- **Fix:** remove the `try/catch`; `return` the resolved store or throw directly when a site is found; let unexpected exceptions bubble (or log them).

### B-04 — Interface autoconfiguration tags decorators of the store context / resolver into their own composite — (plausible) (dependent)
- **Where:** `DependencyInjection/CoreShopStoreExtension.php:62-76`, `Component/Registry/Autoconfiguration.php:33-40`, `Component/Registry/PrioritizedCompositeServicePass.php:46-54`.
- **Problem:** in the default mode (`autoconfigure_with_attributes: false`) **every** autoconfigured service that implements `StoreContextInterface` or `RequestResolverInterface` gets the composite tag. A project service that decorates `CoreShop\Component\Store\Context\StoreContextInterface` (a common way to add caching or logging) is added via `addContext()` to the composite it decorates → circular reference at container build, or endless recursion at runtime if the decorated service is reached through a different id. The same applies to wrappers like `CachedStoreContext` if a project copies that pattern with `autoconfigure: true`.
- **Fix:** document it; skip services that have `decorates` (or the composite / cached classes) in the compiler pass; long term prefer attribute-only autoconfiguration.

## Configuration / container

### B-05 — `autoconfigure_with_attributes` is a scalar node passed to a `bool` parameter under `strict_types` — (verified code path)
- **Where:** `DependencyInjection/Configuration.php:40`, `DependencyInjection/CoreShopStoreExtension.php:67,75` (file has `declare(strict_types=1)`), `Component/Registry/Autoconfiguration.php:31` (`bool $autoconfigureWithAttributes`).
- **Problem:** `scalarNode` accepts `1`, `0`, `'true'`, `'yes'`, or an env placeholder such as `'%env(bool:STORE_ATTR)%'` (a string at load time). Any of these reaches `Autoconfiguration::registerForAutoConfiguration()` as `int`/`string`, which is a `TypeError` under strict types → the container cannot be built.
- **Fix:** `->booleanNode('autoconfigure_with_attributes')->defaultFalse()->end()` (same change in the other bundles that copy this node).

### B-06 — The debug store switcher cannot be configured, overrides the fixed store and cannot be switched off again — (verified code path)
- **Where:** `DependencyInjection/CoreShopStoreExtension.php:56-60`, `DependencyInjection/Configuration.php` (no `debug` key), `Resources/config/services/debug.yml:11-17`, `Context/Debug/DebugStoreProvider.php:25-38`, `Context/Debug/DebugStorePersister.php:32-46`, `Resources/views/Collector/store.html.twig:21`.
- **Problem:**
  - The extension reads `$configs['debug']`, but `Configuration` defines no `debug` node, so `core_shop_store: { debug: false }` is rejected ("Unrecognized option") and the switcher is always on when `kernel.debug` is `true`. On a staging / preview system running with `APP_DEBUG=1`, any visitor can switch to any store (including stores of other sites, with their prices and currencies) via `?_store_id=<id>`.
  - `DebugStoreContext` has priority 256, above `FixedStoreContext` (2). Once the cookie is set, it overrides a store set programmatically (Behat `Behat/Context/Setup/StoreContext.php` uses `FixedStoreContext`), and because the cookie path is `/` it also applies to `/admin` requests.
  - The cookie can never be cleared from the UI: an empty `?_store_id=` is ignored by `DebugStoreProvider`, the persister re-sends the cookie on every response, and it is written even when the id does not exist. The toolbar has no "reset" link.
- **Fix:** add `->booleanNode('debug')->defaultValue('%kernel.debug%')` (or `->defaultNull()` + fallback) to the configuration; give `DebugStoreContext` a priority below `FixedStoreContext` (or check the fixed context first); only persist ids that resolve to a store; support `?_store_id=` / `_store_id=0` to delete the cookie (`$response->headers->clearCookie('_store_id')`); add a reset link in the toolbar.

## Admin

### B-07 — Store form: empty `siteId` causes a DB exception; a site can never be unassigned — (verified code path) (plausible: ExtJS combo behaviour)
- **Where:** `Form/Type/StoreType.php:34` (`IntegerType`, no `empty_data`), `Resources/config/validation/Store.yml:11-12` (`Type: integer` passes `null`), `Resources/config/doctrine/model/Store.orm.xml:15` (`siteId` `NOT NULL`), `Controller/StoreController.php:28-46`, `Resources/public/pimcore/js/item.js:72-85,102-106`.
- **Problem:**
  - `listSitesAction()` only returns real `Site` records; there is no entry for "no site / main domain" (`0`). The combo is `forceSelection: true, editable: false`, so after a site has been chosen it cannot be reset to `0`.
  - If the combo has no value (new store whose `0` is not in the list, or the site was deleted), `getFieldValues()` sends `null` → `setSiteId(null)` → validation passes → `NotNullConstraintViolationException` on flush → generic 500 in the admin instead of a form error.
- **Fix:** return a "— none —" entry with `id: 0` (or `null` after TODO-1 **C-01**) from `listSitesAction()`; `empty_data: '0'` (or nullable column); add `NotNull` (if the column stays non-nullable) to the validation.

### B-08 — `name` / `template` have no length validation → DB exception for long values — (verified code path)
- **Where:** `Resources/config/doctrine/model/Store.orm.xml:12-13` (no type → `string`, length 255, `NOT NULL`), `Resources/config/validation/Store.yml:3-8`.
- **Problem:** values longer than 255 characters pass validation and fail on flush (MySQL strict mode) with a 500.
- **Fix:** `Length(max: 255)` on both; see TODO-3 **I-12**.

### B-09 — `listSitesAction` has no permission check — (verified code path)
- **Where:** `Controller/StoreController.php:28`; compare `ResourceBundle/Controller/ResourceController.php:93,102,139,179,209` (`isGrantedOr403()` in every other write/read action).
- **Problem:** every logged-in admin user can call `coreshop_store_listSites`, regardless of the `coreshop_permission_store` permission. Low impact (site ids, root ids and main domains), but inconsistent with the rest of the controller.
- **Fix:** call `$this->isGrantedOr403()` first.

### B-10 — `StoreOptionProvider` requires the concrete `StoreRepository` class — (verified code path)
- **Where:** `CoreExtension/StoreOptionProvider.php:21,27-29,35`, `Resources/config/services.yml:64-66`, `DependencyInjection/Configuration.php:74` (`classes.repository` is configurable).
- **Problem:** a project that configures its own repository class that implements `StoreRepositoryInterface` but does not extend `CoreShop\Bundle\StoreBundle\Doctrine\ORM\StoreRepository` gets a `TypeError` when the option provider is instantiated, i.e. in the object editor / grid of every class that has a `coreShopStore` or `coreShopStoreMultiselect` field.
- **Fix:** type against `StoreRepositoryInterface` and use `findAll()` (or a sorted finder, TODO-3 **I-10**).

## Profiler

### B-11 — `StoreCollector` reads data keys that may not exist — (verified code path)
- **Where:** `Collector/StoreCollector.php:37-39,44,52,57,62-82`, `Resources/views/Collector/store.html.twig:4-21`.
- **Problem:**
  - The constructor only sets `store`; `reset()` sets `[]`; the admin branch only sets `admin`; the `catch` leaves whatever was there. `getStore()`, `getStores()` and `isStoreChangeSupported()` index `$this->data` without defaults. After `reset()` (between requests in worker mode / in functional tests with `kernel.reset`), an admin request or a failed collection leads to "Undefined array key" (an `ErrorException` with the debug error handler) and `getStores(): array` returning `null` (`TypeError`) → the toolbar / profiler panel breaks.
  - `catch (\Exception)` does not catch `\Error` (e.g. a `TypeError` from a misbehaving context), so the collector can still break the profiler response.
- **Fix:** initialise all keys in one place (`reset()` → `['store' => null, 'stores' => [], 'store_change_support' => false, 'admin' => false]`, call it from the constructor), use `?? default` in the getters, catch `\Throwable`.
