# StoreBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (24 files: `CoreShopStoreBundle.php`, `Attribute/*`, `Collector/*`, `Context/Debug/*`, `Controller/*`, `CoreExtension/*`, `DependencyInjection/**`, `Doctrine/ORM/*`, `Form/Type/*`, `Helper/*`, `Theme/*`), `composer.json`, all of `Resources/config/**` (`services.yml`, `services/{form,profiler,theme,pimcore,debug,data_hub}.yml`, `pimcore/{config,admin,routing}.yml`, `doctrine/model/Store.orm.xml`, `serializer/Model.Store.yml`, `validation/Store.yml`) and `Resources/views/Collector/store.html.twig`. Almost all runtime logic the bundle wires lives in `Component/Store`, so every class of that component was read (`Context/**`, `Model/*`, `Repository/*`). Call paths were also followed into `Component/Registry` (`Autoconfiguration`, `PrioritizedCompositeServicePass`), `ResourceBundle` (`ResourceController`, `CoreExtension/{Select,Multiselect}`, `Doctrine/ORM/EntityRepository`), `CoreBundle` (`Form/Extension/StoreTypeExtension.php`, `Fixtures/Data/Application/StoreFixture.php`), `ThemeBundle` (`ThemeNotResolvedException`) and the admin JS in `Resources/public/pimcore/js/**` where it defines what the forms receive.

Reference for API checks: `vendor/` (`symfony/*` 7.4.x, `six-it/pimcore-source`, `six-it/admin-ui-classic-bundle`, `laminas/laminas-stdlib`), the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml` (+ its `…Deprecations.log`). The vendored copy `vendor/six-it/core-shop/src/CoreShop/{Bundle/StoreBundle,Component/Store}` is identical to this repo.

"Critical" here means: a security issue, money charged wrongly / data written wrongly, or an error that takes down every request, a whole area (cart, checkout, admin section), or the install / container build once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code (PHP 8.4.27) or by reading the vendored library source / the compiled container.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers. `php -l` passes and every class of the bundle loads under PHP 8.4.27 with the project autoloader; every service resolves in the compiled container (`CompositeStoreContext` gets `CachedStoreContext`/1, `FixedStoreContext`/2, `DebugStoreContext`/256; `CompositeRequestResolver` gets the three resolvers with 220/200/100); the container deprecation log has no entry for this bundle or `Component/Store`. The Pimcore admin route names the resolvers depend on (`pimcore_admin_document_page_save`, `pimcore_admin_document_page_areabrick-render-index-editmode`) still exist in `six-it/admin-ui-classic-bundle` (`src/Controller/Admin/Document/PageController.php:50,110,342`). No implicitly nullable parameters. Runtime-compatibility issues (worker mode, `kernel.reset`) are in TODO-2 (**B-01**), modernisation in TODO-3 (**I-01** to **I-07**).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Whole storefront down

### C-01 — Two stores on the same Pimcore site (or two default stores) make every request of that site fail with `NonUniqueResultException` — (verified code path) (dependent)
- **Where:**
  - `Doctrine/ORM/StoreRepository.php:33-50`: `findOneBySite()` and `findStandard()` use `getOneOrNullResult()`, which throws `Doctrine\ORM\NonUniqueResultException` when more than one row matches.
  - `Resources/config/validation/Store.yml:11-12`: `siteId` only has `Type: integer`; there is no uniqueness rule. `Resources/config/doctrine/model/Store.orm.xml:14-15`: no unique constraint / index on `siteId` or `isDefault`.
  - `Resources/public/pimcore/js/item.js:72-85`: the admin offers every site in a combo, so assigning a second store to the same site is one click.
  - `Component/Store/Context/SiteBasedResolver.php:35,42` and `Component/Store/Context/RequestBased/SiteBasedRequestResolver.php:36` do not catch it; `Component/Store/Context/CompositeStoreContext.php:48` and `CompositeRequestResolver.php:45` only catch `StoreNotFoundException`.
- **Impact:** from the moment the second store is saved, every storefront request on that site that asks for the store (cart, prices, currency, theme, product listing, checkout) throws a 500. `PimcoreAdminSiteBasedRequestResolver.php:70` swallows the exception with `catch (\Exception)`, so the admin preview / editmode keeps working and hides the problem. The same happens for two rows with `isDefault = 1` (can only be created by fixtures, imports or SQL today, see **C-02**) on every request outside a Pimcore site.
- **Fix:**
  - Validation: a `Callback` / custom constraint (or `UniqueEntity(fields: ['siteId'])` once "no site" is `null`, see below) so a site can only be assigned to one store; the same for `isDefault = true`.
  - Mapping: make `siteId` nullable with `null` = "no site" (today `0` is used and must stay non-unique) and add a unique index on `siteId`; migration to convert `0` → `null`.
  - Repository: add `->setMaxResults(1)` and a deterministic `->orderBy('o.id')` to both queries as a safety net so a bad row degrades instead of taking the site down.

### C-02 — The default store cannot be managed in the admin, and deleting it leaves every non-site request without a store — (verified code path) (plausible: storefront impact) (dependent)
- **Where:**
  - `Form/Type/StoreType.php:31-36` (+ `CoreBundle/Form/Extension/StoreTypeExtension.php:31-37`): no `isDefault` field. `Resources/public/pimcore/js/item.js:65-95` (and `CoreBundle/Resources/public/pimcore/js/store/item.js`) has no field for it either. The only writer is `CoreBundle/Fixtures/Data/Application/StoreFixture.php:46` (install fixture).
  - `Controller/StoreController.php` inherits `ResourceController::deleteAction()` (`ResourceBundle/Controller/ResourceController.php:207-227`), which removes any store without a check; there is no `coreshop.store.pre_delete` listener anywhere in the code base.
  - `Component/Store/Context/SiteBasedResolver.php:42-48` returns `null` when `findStandard()` finds nothing → `SiteBasedRequestResolver` throws `StoreNotFoundException` → `CompositeStoreContext::getStore()` throws. No bundle catches `StoreNotFoundException` outside the store/theme resolvers.
- **Impact:**
  - Deleting the default store in the admin (it is listed like any other store) makes every request outside a Pimcore site (the main domain of a single-site shop = the whole shop) fail wherever the store is needed (cart, price calculation, currency, checkout). There is no way to repair it in the admin, because no store can be marked as default; it needs SQL.
  - A shop that was installed without the fixture, or whose default store was deleted, cannot designate a new default at all.
  - Deleting any store that is still referenced by orders, carts, quotes, price rules, etc. is also not guarded (the Pimcore objects store a plain id; Doctrine join tables depend on their own `on-delete`); see `CoreBundle` TODOs for the referencing side.
- **Fix:**
  - Add `isDefault` (`CheckboxType`) to `StoreType` and to the admin JS; on save, clear the flag on all other stores in the same transaction (pre-save listener or repository method), and reject unsetting it on the last default store.
  - Add a `coreshop.store.pre_delete` listener that refuses to delete the default store (and, ideally, a store still referenced by orders / carts) with a translatable error message that `ResourceController` returns to the admin.
  - Consider letting `SiteBasedResolver` fall back to "the only store" when exactly one exists and no default is flagged.
