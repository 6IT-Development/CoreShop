# Store Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: all 20 PHP files in `src/CoreShop/Component/Store` (`Context/**`, `Model/*`, `Repository/*`) and its `composer.json`. Dependent config and callers that were followed: `Bundle/StoreBundle/Resources/config/{services.yml,services/debug.yml,doctrine/model/Store.orm.xml,serializer/Model.Store.yml,validation/Store.yml}`, `Bundle/StoreBundle/Doctrine/ORM/StoreRepository.php`, `Bundle/StoreBundle/DependencyInjection/Compiler/{CompositeStoreContextPass,CompositeRequestResolverPass}.php`, the users of the model traits (`Component/Core/Model/{Store,Carrier,Country,PaymentProvider,Configuration,ProductStoreValues}.php`, `Component/Order/Model/Order.php`, `Component/Wishlist/Model/Wishlist.php`, generated `var/classes/DataObject/CoreShopOrder.php`), `Behat/Context/Setup/StoreContext.php`, and in `vendor/`: `six-it/pimcore-source` (`lib/Http/RequestHelper.php`, `lib/Tool/Frontend.php`, `models/Document/Service.php`, `models/Site.php`), `six-it/admin-ui-classic-bundle` (`Controller/Admin/Document/PageController.php`, `public/js/pimcore/document/editables/areablock.js`), `laminas/laminas-stdlib` 3.21 (`PriorityQueue`).
Bundle-side wiring lives in `src/CoreShop/Bundle/StoreBundle` (see that bundle's own TODO files: `C-`, `B-`, `I-` numbers). Several items below are the component half of an item that is already tracked there; they are listed here so the component can be worked on on its own, and they point to the bundle item instead of repeating it.

"Critical" here means: silent data corruption, a hang or an uncaught error / HTTP 500 on a reachable path, a security issue, or a blocker for Symfony 7.4 / PHP 8.4.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4, six-it/pimcore-source 2.x, laminas-stdlib 3.21.0, PHP 8.4.27). Component items use the prefixes `CC-` (critical), `CB-` (bugs) and `CI-` (improvements).
Markers: **(dependent)** = root cause or part of the fix is outside this component; **(reproduced)** = run with PHP 8.4.27 against the vendored libraries; **(plausible)** = follows from the code, but the trigger depends on data or configuration.

**No Symfony 7.4 / PHP 8.4 blocker was found.** All 20 PHP files pass `php -l` on 8.4.27 and load with the project autoloader. No deprecated Symfony or PHP API is used (no implicitly nullable parameters — `StoreContext::assertStoreWasFound(?StoreInterface $store = null)` is explicit —, no dynamic properties, `RequestStack::getMainRequest()` instead of the removed `getMasterRequest()`). The two Pimcore admin route names the resolvers match on still exist (`vendor/six-it/admin-ui-classic-bundle/src/Controller/Admin/Document/PageController.php:110,342`), and the request parameters they read (`id` in the POST body of `…_page_save`, `documentId` in the POST body of `…_areabrick-render-index-editmode`, `areablock.js:854-865`) match what the admin sends.
**No new critical defect that lies only in this component was found.** The two items below are store-resolution failures whose trigger is in the bundle (data / admin), but whose crash path runs through this component.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Store resolution

### CC-01 — A duplicate site assignment or a second default store lets `NonUniqueResultException` escape the whole context chain → HTTP 500 on every request of that site — (dependent) (plausible)
- **Where:** `Repository/StoreRepositoryInterface.php:29,31` (contract), `Context/SiteBasedResolver.php:35,42`, `Context/RequestBased/SiteBasedRequestResolver.php:36`, `Context/RequestBased/CompositeRequestResolver.php:45`, `Context/CompositeStoreContext.php:48`, `Context/RequestBased/PimcoreAdminSiteBasedRequestResolver.php:70`. Implementation: `StoreBundle/Doctrine/ORM/StoreRepository.php:33-50` (`getOneOrNullResult()`). Tracked from the bundle side as StoreBundle TODO-1 **C-01** (root cause: no uniqueness on `siteId` / `isDefault` in mapping and validation).
- **Problem:**
  - `findOneBySite()` / `findStandard()` promise "one or null", but nothing in the component or the mapping guarantees it. The Doctrine implementation throws `Doctrine\ORM\NonUniqueResultException` when two rows match.
  - Every layer in this component only catches `StoreNotFoundException`, so the Doctrine exception passes through `SiteBasedResolver` → `SiteBasedRequestResolver` → `CompositeRequestResolver` → `StoreContext` → `CachedStoreContext` (not cached, so it is re-thrown on every call) → `CompositeStoreContext`. All callers in `Component/Core` and `Component/StorageList` (`ShopperContext::hasStore()`, `StoreBasedCartContext`, `StorageBasedCurrencyContext`, `ConfigurationService`, …) also only catch `StoreNotFoundException` → 500 for cart, prices, currency, theme, checkout on that site.
  - `PimcoreAdminSiteBasedRequestResolver` catches `\Exception` (CB-03), so admin editmode / preview keeps working and hides the fault from the person who caused it.
- **Fix:**
  - Component side: document in `StoreRepositoryInterface` that both finders must return a deterministic single result and must never throw for duplicates (the bundle implementation then adds `->orderBy('o.id')->setMaxResults(1)`, see C-01).
  - Optionally let `SiteBasedResolver` log and degrade instead of failing (`try { … } catch (NonUniqueResultException)` is not possible without a Doctrine dependency in the component — keep the guarantee in the repository contract instead).
  - Bundle side (C-01): uniqueness validation + unique index, `siteId` nullable with `null` = "no site".

### CC-02 — No default store → every request outside a Pimcore site has no store; the component has no fallback — (dependent) (plausible)
- **Where:** `Context/SiteBasedResolver.php:31-48`, `Context/RequestBased/SiteBasedRequestResolver.php:36-42`, `Context/RequestBased/PimcoreAreaBrickRenderResolver.php:46-52`. Tracked from the bundle side as StoreBundle TODO-1 **C-02** (default store cannot be set in the admin and can be deleted).
- **Problem:**
  - `resolveSiteWithDefaultForStore()` returns `null` when the request is not a site request (or the site has no store) **and** `findStandard()` finds nothing. `SiteBasedRequestResolver` is the last resolver in the chain (priority 100), so the composite throws `StoreNotFoundException` and so does `CompositeStoreContext`.
  - Most Core callers do not catch it (e.g. `ShopperContext::getStore()`, the price calculators) → 500 on the whole storefront of a single-site shop. The state cannot be repaired in the admin (no `isDefault` field), only with SQL.
- **Fix:**
  - Component side: let `SiteBasedResolver` fall back to "the only store" when exactly one store exists and none is flagged as default (needs a `count()` / `findAll()` on the repository — `RepositoryInterface` already offers `findAll()`), and log a warning when it has to use the fallback.
  - Bundle side (C-02): `isDefault` in the form / admin JS, keep exactly one default on save, pre-delete guard.
