# OptimisticEntityLockBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this bundle, but they come from how it is used, and they should be fixed together with it.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items are roughly ordered by impact. Items marked **(plausible)** follow from the code, but were not reproduced against a running shop.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Bundle class

### B-01 — `getVersion()` / `getComposerVersion()` throw a `TypeError` in this installation
- **Where:** `CoreShopOptimisticEntityLockBundle.php:36-60`.
- **Problem:**
  - The fork's root package is `six-it/core-shop`, not `coreshop/core-shop`, so `InstalledVersions::isInstalled('coreshop/core-shop')` is `false` (`:50`).
  - `coreshop/optimistic-entity-lock-bundle` is only **replaced** by the root package (`vendor/composer/installed.php:220-225`, no `pretty_version`). `isInstalled()` is `true`, `getPrettyVersion()` returns `null` (`InstalledVersions.php:207-209`), and the `: string` return type throws `TypeError: …::getComposerVersion(): Return value must be of type string, null returned`.
  - `getVersion()` calls it in both branches, so it always throws. It is used by `bin/console pimcore:bundle:list` (`six-it/pimcore-source/bundles/CoreBundle/src/Command/Bundle/ListCommand.php:79`), which then fails for **all** bundles.
- **Reproduced:** with the project autoloader, `(new CoreShopOptimisticEntityLockBundle())->getVersion()` throws the `TypeError` above.
- **Fix:** `?? ''` after each `getPrettyVersion()`, and check `six-it/core-shop` as the first package name. Same issue in other bundle classes (FrontendBundle TODO-2 **B-22**, `PimcoreBundle/CoreShopPimcoreBundle.php:58-59`, `MenuBundle/CoreShopMenuBundle.php:57-58`). Better: one shared helper (TODO-3 **I-07**).

## Lock semantics

### B-02 — Concurrent edits in the Pimcore admin are not detected, contrary to the documentation
- **Where:** `EventListener/LockListener.php:84-130`, `docs/03_Bundles/OptimisticEntityLock_Bundle.md` ("This bundle is crucial for preventing editing conflicts when multiple users attempt to modify the same entity simultaneously"). **(dependent)** `six-it/admin-ui-classic-bundle/src/Controller/Admin/DataObject/DataObjectController.php:1272-1310`.
- **Problem:**
  - On each admin save, `saveAction` loads the object **fresh** from the database (or from the latest draft) and applies only the fields the editor changed. The `optimisticLockVersion` the editor saw when opening the object is not part of the request (unless the field is in the layout and was modified).
  - So when users A and B open an object at version `N`, A saves (`N+1`), and then B saves: B's request loads `N+1`, the check passes, and B's changed fields overwrite A's. Exactly the editing conflict the docs promise to prevent goes undetected.
  - If the field **is** in the layout and editable, users can type any number and either force a conflict or bypass the check.
  - The lock only catches stale **in-process** object instances (the Behat feature tests that case).
- **Fix:** either correct the documentation (the bundle protects code paths that hold an object across a longer time, not the admin), or add real admin support: send the version to the editor with `AdminEvents::OBJECT_GET_PRE_SEND_DATA`, post it back with a small admin JS plugin, and compare it in the listener (TODO-3 **I-09**). Document that the field should be hidden / read-only in the layout.

### B-03 — A failed save leaves the in-memory version incremented; saving the same instance again always fails
- **Where:** `EventListener/LockListener.php:38-45` (no `POST_UPDATE_FAILURE` subscription), `:102`.
- **Problem:** `checkLock()` sets `N+1` on the object before Pimcore writes anything. If the save fails afterwards (validation in another listener, a DB error after Pimcore's 5 retries, a mandatory-field `ValidationException`), the DB keeps `N` and the object keeps `N+1`. Fixing the problem and calling `save()` again on the same instance throws `OptimisticLockException` (DB `N` ≠ object `N+1`). In the admin this shows up as "fix the validation error, save again → lock error".
- **Fix:** subscribe to `POST_UPDATE_FAILURE` and restore the previous version on the object (store it in the event arguments in `checkLock()`, e.g. `$event->setArgument('optimisticLockPreviousVersion', $current)`). With the compare-and-swap from TODO-1 **C-03**, also revert the column there.

### B-04 — `EntityLockManager` compares against a per-process cache that is never refreshed and that can hold the caller's own instance
- **Where:** `Manager/EntityLockManager.php:27,39-47,65-68`, `EventListener/LockListener.php:66-82`.
- **Problem:**
  - `lock()` loads the "actual" object once per id and caches it in `$lockedVersions` for the life of the PHP process. After that, the database is never asked again. In a Messenger worker, a long CLI import or with FrankenPHP/RoadRunner workers, another process can change the version and `lock()` still compares against the old cached value: wrong passes and wrong failures.
  - `updateLock()` (called after every save of a locked object, `LockListener.php:81`) stores **the saved instance itself**. When the caller then calls `lock($sameObject, $sameObject->getOptimisticLockVersion())`, it compares the object with itself and always passes, even if another process saved meanwhile.
  - The cache keeps full Pimcore objects and grows with every locked object touched (memory leak in workers). The service is not tagged `kernel.reset`.
- **Fix:** store only the integer version (or nothing): read the current value with the same one-column query the listener uses (TODO-3 **I-03**). If a cache is kept, implement `Symfony\Contracts\Service\ResetInterface` and tag the service `kernel.reset`.

### B-05 — `lock()` with a non-int version (string, float) throws a `TypeError` instead of `OptimisticLockException`
- **Where:** `Manager/EntityLockManager.php:29,57-58`, `Manager/EntityLockManagerInterface.php:25`, `Exception/OptimisticLockException.php:42`.
- **Problem:** `$lockVersion` is untyped. The typical caller passes the version that came back from a form or request (`'3'`). `$entityVersionLock !== $lockVersion` is then always `true` (int vs string), and `lockFailedVersionMismatch(…, int $expectedLockVersion, …)` throws `TypeError` (strict types) instead of the lock exception that callers catch. A numeric field that is not configured as integer returns a `float` and ends the same way.
- **Fix:** type the parameter `?int` in interface and implementation. Callers that have strings must cast (or validate) first.

### B-06 — A numeric field without "integer" makes every save of a lock-enabled object fail (plausible)
- **Where:** `EventListener/LockListener.php:119-121`, `docs/03_Bundles/OptimisticEntityLock_Bundle.md` ("Add the field `optimisticLockVersion`"; no type given).
- **Problem:** the listener compares `(int) $dbValue === $object->getOptimisticLockVersion()`. A Pimcore numeric field without the `integer` option has a `float` getter and a `DOUBLE`/`DECIMAL` column. `1 === 1.0` is `false`, so every save throws, starting with the first update after creation. The Behat feature uses "numeric integer field" (`features/domain/optimistic_entity_lock/lock.feature:7`), but the docs do not say so. **(plausible)** — not run against a real class with a float field.
- **Fix:** document "numeric field, integer, not localized, not inherited". Also check the field definition once (e.g. in a class-definition save listener or a compiler-time / install-time check) and fail with a clear message (**B-07**).

### B-07 — A class that implements the interface without the field (or with the field in localized fields / a field collection) fails every save with an SQL error
- **Where:** `EventListener/LockListener.php:111-119`.
- **Problem:** the query selects `optimisticLockVersion` from `object_store_<classId>`. If the field is missing, or it lives in localized fields / a brick / a field collection (other tables), every save throws `Doctrine\DBAL\Exception\InvalidFieldNameException: Unknown column 'optimisticLockVersion'`. A class can implement the interface through a parent class (`Behat/Model/Lock/OptimisticLock.php` is used as `parentClass`), so this is easy to get wrong for a second class with the same parent.
- **Fix:** validate on class-definition save (`DataObjectClassDefinitionEvents::PRE_UPDATE`) that a class whose parent implements `OptimisticLockedInterface` has a top-level integer field `optimisticLockVersion`, and throw a clear error. In the listener, convert the DBAL error into an `OptimisticLockException::notVersioned()` with the class name.

### B-08 — `lock()` silently does nothing for `null`, and throws a `TypeError` for unsaved objects
- **Where:** `Manager/EntityLockManager.php:35-40`.
- **Problem:**
  - `lock($object, null)` returns without any check (`:35-37`). A caller that passes `$object->getOptimisticLockVersion()` for a never-versioned object (TODO-1 **C-01**) believes it holds a lock, but nothing was verified.
  - For an unsaved object `getId()` is `null`, and `Concrete::getById(null, …)` throws `TypeError` (the parameter is `int|string`) instead of a lock exception.
  - The `null === $entityVersion` branch (`:51-53`) can never be true; the value was just set from a non-null object (`:46`).
- **Fix:** make the semantics explicit (`assertVersion(Concrete&OptimisticLockedInterface $object, ?int $expected)`), compare `null` like C-01 defines it, and throw `OptimisticLockException::notVersioned()` (or a dedicated `notPersisted()`) when the object has no id. Remove the dead branch.

## Packaging

### B-09 — `composer.json` declares the wrong package type and none of the packages the bundle uses
- **Where:** `composer.json:3,26-28`.
- **Problem:**
  - `"type": "library"`: all other CoreShop bundles use `"pimcore-bundle"`, which Pimcore's bundle discovery and installers rely on.
  - The bundle uses `doctrine/dbal` (listener), `symfony/event-dispatcher`, `symfony/dependency-injection`, `symfony/config`, `symfony/http-kernel` (bundle base), and `composer-runtime-api` (`Composer\InstalledVersions`), but only requires `six-it/pimcore-source`. There is no `php` constraint (root `composer.json:88` requires `~8.4.0`). Installed standalone or with `--prefer-lowest`, it can resolve versions that do not support Symfony 7.4 / PHP 8.4.
- **Fix:** `"type": "pimcore-bundle"`, `"php": ">=8.4"`, `"doctrine/dbal": "^3.8 || ^4.0"`, `"symfony/{config,dependency-injection,event-dispatcher,http-kernel}": "^7.4"`, `"composer-runtime-api": "^2.2"`. The dev-tooling part is TODO-3 **I-11**.
