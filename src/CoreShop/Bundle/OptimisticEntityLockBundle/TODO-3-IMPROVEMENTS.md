# OptimisticEntityLockBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Code quality, readability, logic and modernization items. None of them is a functional bug on its own. Items marked **(dependent)** live outside this bundle.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Design

### I-01 — Put the version logic in one service and let the listener and the manager share it
- **Where:** `EventListener/LockListener.php:105-130`, `Manager/EntityLockManager.php:29-60`.
- **Idea:** today there are two different implementations of "what is the current version": the listener runs a one-column SQL query, the manager loads the whole object with `getById(['force' => true])` and caches it. They also treat `null` differently (TODO-1 **C-01**). Add one small class (e.g. `VersionStore` with `fetchCurrent(Concrete&OptimisticLockedInterface $o): ?int` and `compareAndSwap(…, ?int $expected, int $next): bool`, see TODO-1 **C-03**). The listener and the manager both use it, and it becomes the single place to change the storage (table name, column, connection).

### I-02 — Name the manager API after what it does
- **Where:** `Manager/EntityLockManagerInterface.php:23-26`, `Manager/EntityLockManager.php:29,65`.
- **Idea:** `lock()` does not lock anything; it asserts that the caller's version is still current. A later `save()` can still fail. Rename (with a deprecated `lock()` alias for BC) to `assertVersion(Concrete&OptimisticLockedInterface $object, int $expectedVersion): void` and document `@throws OptimisticLockException`. The intersection type (PHP 8.1+) removes the runtime `instanceof` and both inline `@var` blocks in the listener. `updateLock()` is `@internal` but public and not on the interface, which is why the listener depends on the `final` concrete class (**I-04**); with **I-01** it is not needed any more.

### I-03 — `EntityLockManager`: drop the object cache
- **Where:** `Manager/EntityLockManager.php:27,39-53`.
- **Idea:** `$lockedVersions` is named as if it held versions, but it holds whole objects, keyed by id. A fresh one-column read is cheaper than `getById(['force' => true])` (which loads all fields and relations) and fixes TODO-2 **B-04**. If a per-request cache is really wanted, store `int` versions and implement `ResetInterface` (`kernel.reset` tag).

### I-04 — `LockListener`: depend on the interface, remove duplicate guards
- **Where:** `EventListener/LockListener.php`.
- **Idea:**
  - `protected EntityLockManager $lockManager` → `private readonly EntityLockManagerInterface` (after **I-02**), so a decorated manager is actually used. `protected Connection` → `private readonly`. Make the class `final`.
  - The two-step guard `instanceof OptimisticLockedInterface` + `instanceof Concrete` is repeated in three methods (`:51-57,70-76,88-94`) and once more inside `ensureVersionMatch()` (`:107-109`, unreachable). Use one `private function supports(DataObjectEvent $e): (Concrete&OptimisticLockedInterface)|null`.
  - The inline `@var Concrete $object / @var OptimisticLockedInterface $object` blocks (`:59-62,78-80,98-101`) then go away.
  - `$this->connection->fetchOne($queryBuilder->getSQL(), $queryBuilder->getParameters())` (`:119`) drops the parameter types; use `$queryBuilder->executeQuery()->fetchOne()`.
  - Quote the table name (`$this->connection->quoteIdentifier('object_store_' . $object->getClassId())`).
  - `#[AsEventListener]` attributes instead of `EventSubscriberInterface` + YAML tag, if the bundle moves to autoconfiguration (**I-08**).

### I-05 — `OptimisticLockException`: tidy up and carry the versions
- **Where:** `Exception/OptimisticLockException.php`.
- **Idea:**
  - Make it `final`, extend `\RuntimeException` (it is not something callers can avoid in advance).
  - `private ?\Pimcore\Model\DataObject\Concrete $entity` (`:27`) uses the FQCN although `Concrete` is imported (`:21`). Use `private readonly ?Concrete $entity`.
  - Add `public readonly ?int $expectedVersion` / `?int $actualVersion`, so the admin or an API can show "someone else saved this object (version 5, you had 4)". Use `sprintf()` for the message.
  - `lockFailed()` (`:37-40`) has no caller; remove it. `notVersioned(…)` passes an explicit `null` default (`:52`).
  - The short name collides with `Doctrine\ORM\OptimisticLockException`, which is also thrown in CoreShop code paths. Consider `DataObjectVersionConflictException` (keep the old class as a deprecated parent for BC).

### I-06 — `OptimisticLockedInterface`: return type and contract docs
- **Where:** `Model/OptimisticLockedInterface.php:21-26`.
- **Idea:** `setOptimisticLockVersion(?int $optimisticLockVersion): static`. The Pimcore-generated setters already return `static`, so this is compatible. Document: `null` = never versioned (C-01), the field must be a top-level integer numeric field (TODO-2 **B-06**, **B-07**), and only the listener should write it. **(dependent)** `Behat/Model/Lock/OptimisticLock.php:33` then needs `: static` too.

### I-07 — Bundle class cleanup
- **Where:** `CoreShopOptimisticEntityLockBundle.php`.
- **Idea:**
  - `getJsPaths()`, `getCssPaths()`, `getEditmodeJsPaths()`, `getEditmodeCssPaths()` (`:62-80`) are dead code: in Pimcore 11 the admin only reads them from bundles that implement `PimcoreBundleAdminClassicInterface`, and the bundle has no assets. Remove them.
  - `class_exists('\\CoreShop\\Bundle\\CoreBundle\\Application\\Version')` (`:38-39`) → import the class and use `class_exists(Version::class)`.
  - The `getComposerVersion()` logic is copy-pasted in several bundle classes (TODO-2 **B-01**). Move it into one helper, or implement `ComposerPackageBundleInterface` like `AbstractResourceBundle`.
  - Make the class `final`.
  - Option: extend Symfony's `AbstractBundle` and register the two services in `loadExtension()`. Then `DependencyInjection/CoreShopOptimisticEntityLockExtension.php` and `services.yml` can go.

### I-08 — `services.yml`: autowire, and make the listener use the interface alias
- **Where:** `Resources/config/services.yml:3-11`.
- **Idea:** `_defaults: { autowire: true, autoconfigure: true, public: false }`, then the explicit argument lists can go. `'@doctrine.dbal.default_connection'` → autowire `Doctrine\DBAL\Connection`. The listener should receive `EntityLockManagerInterface` (**I-04**), so decorating the alias affects the listener too. Mark the manager `public: true` only if it is meant to be fetched from the container (the Behat context injects it, which does not need public).

### I-09 — Optional: real admin conflict detection
- **Where:** new code; see TODO-2 **B-02**.
- **Idea:** listen to `Pimcore\Bundle\AdminBundle\Event\AdminEvents::OBJECT_GET_PRE_SEND_DATA` and add the current version to the editor payload; a small admin JS plugin posts it back with each save; a listener on `AdminEvents::OBJECT_SAVE_PRE_…` (or on the request in `PRE_UPDATE`) compares it and fails with a readable message ("This object was changed by X at Y. Reload before saving."). Without this, the docs should say clearly that the admin is not protected.

## Documentation / packaging / tests

### I-10 — Rewrite the documentation to match the behaviour
- **Where:** `docs/03_Bundles/OptimisticEntityLock_Bundle.md` (repo root), `README.md`.
- **Idea:**
  - The install section asks users to add the bundle to the kernel, but CoreShop already registers it (`ResourceBundle/CoreShopResourceBundle.php:70`). Pimcore's `BundleCollection::add()` deduplicates by identifier, so adding it again is harmless, but the step is unnecessary and confusing. The docs also show the old `AppKernel.php` path.
  - Describe the field exactly (numeric, integer, top-level, not inherited, hidden/read-only in the layout).
  - Describe the back-fill for existing objects (TODO-1 **C-01**), the draft behaviour (TODO-1 **C-02**), what the manager does (**I-02**), and that admin edits are not covered (TODO-2 **B-02**) until **I-09** exists.
  - `composer require coreshop/optimistic-entity-lock-bundle:^4.0` does not apply to this fork (the package is replaced by `six-it/core-shop`).

### I-11 — `composer.json` tooling and metadata
- **Where:** `composer.json:29-50`.
- **Idea:** PHPStan `^1.10` → `^2.1` (1.x does not fully understand PHP 8.4 syntax). `repositories: [{type: path, url: ../../*/*}]` and `"minimum-stability": "dev"` belong to the monorepo, not to a split package (`.gitsplit.yml:32` still splits it to `github.com/coreshop/OptimisticEntityLockBundle`; check whether the fork wants that). `branch-alias dev-master: 4.1-dev` is upstream versioning. Runtime deps and package type are TODO-2 **B-09**.

### I-12 — Extend the tests
- **Where:** `features/domain/optimistic_entity_lock/lock.feature` (repo root, 2 scenarios), `Behat/Context/Setup/OptimisticEntityLockContext.php`. No PHPUnit tests.
- **Idea, by priority:**
  1. Behat: object saved before the field existed (`NULL` version) can be saved (TODO-1 **C-01**).
  2. Behat: `saveVersion()` then `save()` on the same instance, and publish-after-draft (TODO-1 **C-02**).
  3. Integration test with two DBAL connections: two saves from version `N`, exactly one succeeds (TODO-1 **C-03**).
  4. Failed save, fix, save again on the same instance (TODO-2 **B-03**).
  5. PHPUnit for the manager: string / `null` versions, unsaved object, worker reset (TODO-2 **B-04**, **B-05**, **B-08**).
  6. `getVersion()` returns a string with replaced packages (TODO-2 **B-01**).
  The existing Behat context also passes `getValueForFieldName('optimisticLockVersion')` (mixed) into `lock()`; once the parameter is typed (TODO-2 **B-05**), cast it there.
