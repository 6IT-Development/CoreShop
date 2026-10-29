# ResourceBundle – TODO 1: Critical errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: `Attribute/`, `Cache/`, `Command/`, `Controller/`, `CoreExtension/` (21 PHP files) and the config they depend on: `Resources/config/services.yml`, `Resources/config/services/{commands,controller,routing}.yml`, `Resources/config/pimcore/{config,routing,admin}.yml`. Call paths were followed into `DependencyInjection/{CoreShopResourceExtension,Configuration}.php`, `DependencyInjection/Driver/{AbstractDriver,Pimcore/PimcoreDriver}.php`, `Routing/ResourceLoader.php`, `EventListener/DeepCopySubscriber.php`, `Component/Resource/{Metadata/Metadata,Model/SetValuesTrait,Pimcore/ResourceDocumentTagFactory}.php`, the `Select`/`Multiselect` subclasses in other bundles, `OrderBundle/Controller/*`, and `Resources/public/pimcore/js/{helpers,resource}.js` + `coreExtension/data/coreShopRelation(s).js`.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4.21, six-it/pimcore-source 2.1.2, six-it/admin-ui-classic-bundle, doctrine/orm 2.20.13, doctrine/dbal 3.10.6, jms/serializer 3.32.9, PHP 8.4.27).
Markers: **(dependent)** = root cause or part of the fix is outside the scanned directories. **(reproduced)** = run with PHP 8.4.27 against the bundle code and the vendored libraries. **(plausible)** = follows from the code, but the trigger depends on data or configuration.

"Critical" here means: security hole, shop-wide or admin-wide breakage on a common path, data written wrongly on a common path, or a blocker for Symfony 7.4 / PHP 8.4.

**No Symfony 7.4 / PHP 8.4 blocker was found.** All 21 PHP files pass `php -l` on 8.4.27, and all declare `strict_types=1`. Deprecations that will break on Doctrine ORM 3 are tracked in TODO-2 **B-01** and **B-02**. Strict-types `TypeError`s on missing request parameters are in TODO-2 **B-03**.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security

### C-01 — `ResourceController::listAction()` has no permission check: any backend user can list every CoreShop resource
- **Where:** `Controller/ResourceController.php:80-89`. Every other action (`get`, `save`, `clone`, `add`, `delete`, `folderConfiguration`) calls `$this->isGrantedOr403()` first; `list` does not. Route: `Routing/ResourceLoader.php:31-35` registers `list` as `GET` for **every** resource that has an admin controller (default `admin_controller` is `ResourceController`, `DependencyInjection/Configuration.php:77`).
- **Problem:**
  - Resources protect themselves with a `permission` parameter (for example `PaymentBundle/DependencyInjection/Configuration.php:81` → `coreshop_permission_payment_provider`). `isGrantedOr403()` enforces it, but `list` skips it, so any logged-in backend user (also users without that permission) can call `GET /admin/coreshop/<app>/<resources>/list` for every resource.
  - What leaks is limited to the `List` serializer group plus every property that has **no** group (JMS puts those in `Default`, and `ViewHandler.php:38` always adds `Default`). For the core resources this is IDs, names, identifiers and active flags. A resource whose serializer config forgets `groups:` leaks all of those fields.
  - `$start` and `$limit` are read and ignored (`:82-83`). `findAll()` loads and serializes the whole table on every call. On large tables (for example voucher codes, if exposed through a resource controller) this is an expensive, unauthenticated-by-permission request.
- **Fix:**
  - Call `$this->isGrantedOr403();` as the first line of `listAction()`.
  - Implement `start`/`limit` (`findBy([], null, $limit, $start)` with an upper bound) or remove the variables (TODO-3 **I-08**).
  - Add a functional test: a user without the resource permission gets 403 on `list` (TODO-3 **I-24**).

> `ResourceSettingsController::getNicePathAction()` (path lookup without element permission checks) was checked as a security candidate. Pimcore's own `ElementController::getNicePathAction()` behaves the same way, so it is tracked as hardening in TODO-2 **B-04**, not as a critical item.

---

## Scan 2 (2026-10-29): `DataHub/`, `DeepCopy/`, `DependencyInjection/`, `Doctrine/`, `Event/`

Scope: 31 PHP files (about 2,900 lines). Config they depend on: `Resources/config/services/{data_hub,integrations/doctrine/orm}.yml`, `CoreShopResourceBundle::build()` (compiler pass order). Call paths were followed into `CoreBundle/CoreExtension/StoreValues.php` (the only `EntityMerger` user), `Pimcore/ObjectManager.php`, `EventListener/ORMMappedSuperClassSubscriber.php`, `Controller/{ResourceController,EventDispatcher}.php`, `Component/Resource/Metadata/Metadata.php`, and the vendored Doctrine ORM 2.20.13 / DoctrineBundle / DeepCopy. DI findings were checked against the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml`.

**No critical item and no Symfony 7.4 / PHP 8.4 blocker was found in this scan.** All 31 files pass `php -l` on 8.4.27 and declare `strict_types=1`. The extensions extend `Symfony\Component\DependencyInjection\Extension\Extension` (through `AbstractPimcoreExtension`), not the `HttpKernel` class that Symfony 7.1 deprecated. The Doctrine ORM 3 blockers found here are tracked in TODO-2 **B-21**. The new bugs are TODO-2 **B-19** to **B-26**, the new improvements TODO-3 **I-25** to **I-37**.
