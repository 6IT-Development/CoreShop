# Store Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope, path conventions and markers as in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Component items use the prefixes `CC-` (critical), `CB-` (bugs) and `CI-` (improvements); StoreBundle items are referenced as StoreBundle `C-`/`B-`/`I-`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Context / caching

### CB-01 — `CachedStoreContext` and `FixedStoreContext` are never reset, and a "not found" is never cached — (reproduced: miss) (plausible: worker impact) (dependent)
- **Where:** `Context/RequestBased/CachedStoreContext.php:27-46`, `Context/FixedStoreContext.php:21-39`; wiring `StoreBundle/Resources/config/services.yml:23-30` (no `kernel.reset` tag). Tracked from the bundle side as StoreBundle TODO-2 **B-01**.
- **Problem:**
  - The cached store lives as long as the service, not the request. In worker runtimes (FrankenPHP worker mode, RoadRunner), in functional tests with `disableReboot()`, and in Messenger workers that boot a request, the store resolved for the first request is returned for every later one → wrong store, currency, prices, taxes and theme for visitors of other sites.
  - `FixedStoreContext` (priority 2, above the request-based context) keeps a store once set for the rest of the process and has no way to clear it (`setStore()` does not accept `null`, there is no `reset()`).
  - Misses are not cached: when the inner context throws, `$initialized` stays `false`, so every `getStore()` re-runs all resolvers (document / site lookups, DB queries). Reproduced: 3 calls → 3 inner calls. The `elseif (!$this->cachedStore)` branch at `:41-43` is therefore dead code.
  - The value is cached on the first call, whenever it happens. A `kernel.request` listener that asks for the store before Pimcore has set the current site (or before routing set `_route`) fixes the default store for the rest of the request.
- **Fix:** implement `Symfony\Contracts\Service\ResetInterface` in both classes (`reset(): void` clears the state) and tag them `kernel.reset` (or rely on autoconfiguration). Cache per main request (`spl_object_id($request)` or a request attribute), cache the miss too (store the `StoreNotFoundException` or a sentinel), allow `FixedStoreContext::setStore(?StoreInterface)` / `clear()`.

### CB-02 — A request resolver that returns `null` stops the resolver chain — (dependent)
- **Where:** `Context/RequestBased/RequestResolverInterface.php:27-32`, `Context/RequestBased/CompositeRequestResolver.php:40-51`, `Context/RequestBased/StoreContext.php:44-68`. Tracked from the bundle side as StoreBundle TODO-2 **B-02**.
- **Problem:** the signature allows `?StoreInterface`, the docblock says `@return StoreInterface` + `@throws StoreNotFoundException`. The composite returns the first result that does not throw — including `null`. A custom resolver (tag / `#[AsRequestBasedResolverStoreContext]`) that returns `null` for "not mine" prevents all lower-priority resolvers from running, including `SiteBasedRequestResolver` (default store) → `StoreContext` turns `null` into `StoreNotFoundException` → no store (CC-02 impact).
- **Fix:** in the composite `continue` on `null`; make the contract explicit (preferably `: StoreInterface` + throw only, see CI-01) and fix the docblock.

## Request resolvers

### CB-03 — `PimcoreAdminSiteBasedRequestResolver` swallows its own "not found" and every other exception — (dependent)
- **Where:** `Context/RequestBased/PimcoreAdminSiteBasedRequestResolver.php:56-75`. Tracked from the bundle side as StoreBundle TODO-2 **B-03**.
- **Problem:**
  - When a site is found but no store resolves for it, the `throw new StoreNotFoundException()` at `:65` is caught by `catch (\Exception)` at `:70`; the loop then continues with the **parent** document instead of stopping. With nested sites and no default store, an inner site without a store then silently resolves the **outer** site's store.
  - `catch (\Exception)` also hides DB errors and `NonUniqueResultException` (CC-01), so admin preview shows a store while the storefront crashes.
  - `Site::getByRootId()` already returns `null` for "no site" (`vendor/six-it/pimcore-source/models/Site.php`), so the `try` is not needed.
- **Fix:** drop the `try/catch`; when a site is found, return its store or throw `StoreNotFoundException` directly; let unexpected exceptions bubble (or log them). See CI-06 for replacing the parent walk.

### CB-04 — The admin resolver is switched on by public query parameters on any storefront URL — (plausible)
- **Where:** `Context/RequestBased/PimcoreAdminSiteBasedRequestResolver.php:49-52` → `vendor/six-it/pimcore-source/lib/Http/RequestHelper.php:141-160` (`isFrontendRequestByAdmin()`).
- **Problem:**
  - `isFrontendRequestByAdmin()` only checks whether `pimcore_editmode`, `pimcore_preview`, `pimcore_admin`, `pimcore_object_preview` or `pimcore_version` is present in the query / body. It does not check an admin session. Any visitor can append `?pimcore_preview=1` to any storefront URL, and the store is then resolved from the **document tree** (`getNearestDocumentByPath($request->getPathInfo())` + walk to the site root) instead of from the current Pimcore site.
  - Effects: on the main domain, a URL below another site's root path (`/site-b/...?pimcore_preview=1`) resolves site B's store (prices, currency, cart session key) on the main domain; every such request costs extra document-by-path lookups (one query per path segment + one per ancestor). Low security impact (all stores are public), but the resolved store no longer matches the domain the visitor is on, and carts/currencies stored per store can be mixed.
  - Array values (`?pimcore_preview[]=1`) make `InputBag::get()` throw `BadRequestException` inside the store resolution → HTTP 400 on any page that needs the store.
- **Fix:** only take this branch for authenticated admin users (e.g. check `Pimcore\Tool\Authentication::authenticateSession($request)` or the `pimcore_admin` firewall user, or Pimcore's `EditmodeResolver::isEditmode()` / `DocumentResolver` that already validate the session), or require that the resolved document's site equals `Site::getCurrentSite()` when it is a site request. Read parameters with `$request->query->has()` to avoid the 400.

### CB-05 — `Document\Service::getNearestDocumentByPath()` caches without the path; the resolver can get a document for a different URL — (dependent) (plausible)
- **Where:** `Context/RequestBased/PimcoreAdminSiteBasedRequestResolver.php:50-51` → `vendor/six-it/pimcore-source/models/Document/Service.php:461-510` (`$cacheKey = $ignoreHardlinks . implode('-', $types);`, `@internal`).
- **Problem:** the per-instance cache key does not contain the path. `Document\Service` is a shared service, so the second call in the same container with another path (a sub-request, Pimcore's own fallback listener, or the next request in a worker process) returns the first document → in admin preview / editmode the store of the previously resolved document's site is used.
- **Fix:** do not rely on this `@internal` method: resolve the document with `Document::getByPath()` / Pimcore's `DocumentResolver` (request attribute `contentDocument`, which Pimcore sets for document routes), or report the cache-key bug upstream (six-it/pimcore-source) and include `$path` in the key.

## Models

### CB-06 — `StoresAwareTrait` declares a constructor: every using class with its own constructor must alias and call it, otherwise `$stores` is `null` — (reproduced) (plausible: trigger)
- **Where:** `Model/StoresAwareTrait.php:31-34`, users `Core/Model/Carrier.php:30-40`, `Core/Model/Country.php:29-39`, `Core/Model/PaymentProvider.php:29-37` (all three alias it as `storesAwareConstructor`). Same pattern in `Address/Model/CountriesAwareTrait.php`.
- **Problem:** a class (core or project entity) that uses the trait and defines its own `__construct()` without calling the aliased trait constructor gets `$stores === null`; `hasStore()`, `addStore()`, `removeStore()`, `hasStores()` then fail with `Error: Call to a member function contains() on null` (reproduced). A class that uses two such traits (`StoresAwareTrait` + `CountriesAwareTrait`) gets a fatal "trait method collision" unless both are aliased. The current users are correct, so this is a latent trap for extensions.
- **Fix:** remove the constructor from the trait; initialise lazily (`$this->stores ??= new ArrayCollection()` in a private `getStoresCollection()`) or require the using class to initialise it (document it). See CI-03 for typing.

### CB-07 — `StoreNotFoundException` cannot take a message and rejects `\Throwable` as previous — (reproduced)
- **Where:** `Context/StoreNotFoundException.php:21-27`.
- **Problem:** the constructor signature replaces `RuntimeException`'s `(string $message, int $code, ?\Throwable $previous)` with `(?\Exception $previousException)`. `new StoreNotFoundException('Store 5 not found')` → `TypeError` (looks like a normal exception constructor, so it is an easy mistake for extensions), and chaining an `\Error` (`TypeError`, `ValueError`) → `TypeError` (reproduced). Every "not found" has the same generic message, so logs do not say which resolver / site failed.
- **Fix:** `public function __construct(string $message = 'Store could not be found!', int $code = 0, ?\Throwable $previous = null)`; keep BC with a named constructor `StoreNotFoundException::fromPrevious(\Throwable $e)` and update `StoreContext.php:40`. Same change in `ThemeBundle/Service/ThemeNotResolvedException.php` (referenced by StoreBundle **I-15**).

### CB-08 — `Store` setters accept any type; values that violate the documented types reach the entity — (reproduced)
- **Where:** `Model/Store.php:68-137`, `Model/StoreInterface.php:29-67`; validation `StoreBundle/Resources/config/validation/Store.yml` (`isDefault: Type boolean`, `siteId: Type integer`).
- **Problem:** `setIsDefault('0')` stores the string `"0"` (reproduced: `getIsDefault()` returns `string(1) "0"`), `setSiteId('5')` a string, `setName(null)` `null`, `setId()` anything. Code that compares strictly (`=== true`, `=== $site->getId()`) then gives the wrong answer, the `Type` constraints reject the entity although the caller meant a valid value, and `name`/`template` `null` only fails in the DB (`NOT NULL`). Callers today (fixtures, Behat, forms) pass correct types, so the impact is on imports / APIs / extensions.
- **Fix:** native parameter and return types (`setIsDefault(bool $isDefault): void`, `setSiteId(?int $siteId)`, `setName(string $name)`, …), see CI-02. Coordinate with Core `StoreInterface` and the StoreBundle form/mapping (`siteId` nullable, StoreBundle **B-07**).

### CB-09 — `StoreAwareTrait` documents a non-null property and has no return type on `setStore()` while the Pimcore classes return `static` — (reproduced: typing pitfall)
- **Where:** `Model/StoreAwareTrait.php:23-37`, `Model/StoreAwareInterface.php:23-25`; users `Order/Model/Order.php:32`, `Wishlist/Model/Wishlist.php:27` (Pimcore models), `Core/Model/Configuration.php:29`, `Core/Model/ProductStoreValues.php:32` (Doctrine entities); generated `var/classes/DataObject/CoreShopOrder.php:152,531,554` (`protected $store;`, `getStore(): ?StoreInterface`, `setStore(?StoreInterface $store): static`).
- **Problem:**
  - `@var StoreInterface` on a property that is `null` until set; static analysis treats `getStore()` results as non-null in the trait.
  - For the Pimcore models (`Order`, `Wishlist`) the trait is dead code: the generated class overrides the property and both accessors (with lazy loading and dirty tracking). If someone "fixes" the trait by typing the property (`protected ?StoreInterface $store = null`), **every generated Pimcore class that redeclares `protected $store;` fails with a fatal error** ("Type of CoreShopOrder::$store must be ?StoreInterface (as in class Order)", reproduced with PHP 8.4.27). Adding `: void` to `setStore()` in the interface or trait is equally fatal for the generated `: static` setters.
  - The Doctrine users (`Configuration`, `ProductStoreValues`) return nothing from `setStore()`, the Pimcore users return `static` → callers cannot rely on fluent use.
- **Fix:** remove `StoreAwareTrait` from `Order` and `Wishlist` (the generated class provides everything). Then type the trait for the Doctrine entities only: `protected ?StoreInterface $store = null;`, `setStore(?StoreInterface $store): static`. Declare `setStore(?StoreInterface $store): static` in `StoreAwareInterface` (compatible with the generated setters). Regenerate the class definitions and run the container / class-load test after the change.
