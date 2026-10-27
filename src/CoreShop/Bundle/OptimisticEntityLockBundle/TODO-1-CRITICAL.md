# OptimisticEntityLockBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (`CoreShopOptimisticEntityLockBundle.php`, `DependencyInjection/CoreShopOptimisticEntityLockExtension.php`, `EventListener/LockListener.php`, `Exception/OptimisticLockException.php`, `Manager/EntityLockManager{,Interface}.php`, `Model/OptimisticLockedInterface.php`), `composer.json`, `Resources/config/services.yml`, `README.md`. Also checked: the code and config that depend on it:
`ResourceBundle/CoreShopResourceBundle.php:70` (registers this bundle in every CoreShop install), `Behat/Model/Lock/OptimisticLock.php`, `Behat/Context/Setup/OptimisticEntityLockContext.php`, `Behat/Resources/config/services/contexts/setup.yml:343-345`, `Behat/Resources/config/suites/domain/optimistic_entity_lock.yml`, `features/domain/optimistic_entity_lock/lock.feature` (repo root), `docs/03_Bundles/OptimisticEntityLock_Bundle.md` (repo root), `.gitsplit.yml:32`.

Reference for API checks: `../pimcore2teszt10211524/vendor/` (`six-it/pimcore-source` `models/DataObject/{AbstractObject,Concrete}.php`, `lib/Extension/Bundle/{AbstractPimcoreBundle,PimcoreBundleInterface}.php`, `bundles/CoreBundle/src/Command/Bundle/ListCommand.php`; `six-it/admin-ui-classic-bundle` `src/Controller/Admin/DataObject/DataObjectController.php`; `doctrine/dbal` 3.10.6; `composer/InstalledVersions.php` + `installed.php`). The vendored `six-it/core-shop` copy of this bundle is identical to the working tree.

"Critical" here means: a security issue, money charged or stored wrongly, data loss or data written wrongly, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** live outside this bundle, but they come from how it is used, and they should be fixed together with it.

**Symfony 7.4 / PHP 8.4 compatibility:** no blockers. `php -l` (PHP 8.4.27) passes on all 7 PHP files. There are no implicit nullable parameters, no dynamic properties and no removed functions. `Symfony\Component\DependencyInjection\Extension\Extension` (not the deprecated HttpKernel one), `EventSubscriberInterface`, `Connection::fetchOne()` / `createQueryBuilder()` (DBAL 3.10) and `Concrete::getById(int|string, array)` all exist with the signatures used. The real Symfony 7.4 / PHP 8.4 risk is a `TypeError` from a `string` return type (TODO-2 **B-01**).

**How the bundle is used:** no CoreShop model implements `OptimisticLockedInterface`; only the Behat test model does. The listener therefore only affects project classes that opt in, as described in `docs/03_Bundles/OptimisticEntityLock_Bundle.md`. But the bundle is always loaded (`ResourceBundle/CoreShopResourceBundle.php:70`), and the three items below hit any project that follows the documentation.

**Overlap with other TODOs:** none. FrontendBundle TODO-2 **B-22** describes the same `getComposerVersion()` pattern as TODO-2 **B-01** here; fix all bundle classes together.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Save path

### C-01 — Enabling the lock on a class with existing objects makes every save of those objects fail with a `TypeError`
- **Where:** `EventListener/LockListener.php:84-103,105-130`. Docs: `docs/03_Bundles/OptimisticEntityLock_Bundle.md` ("Implementing the Interface", "Adding the Field").
- **Problem:**
  - The documented setup is: implement the interface, add an `optimisticLockVersion` field. Existing objects then have `NULL` in that column and `getOptimisticLockVersion()` returns `null`. Only objects created **afterwards** get `1` from `preAddLock()`.
  - On the next save, `ensureVersionMatch()` reads the column: `fetchOne()` returns `null`, and `(int)` makes it `0` (`:119`). `0 === null` is false (`:121`), so the method calls `OptimisticLockException::lockFailedVersionMismatch($object, null, 0)` (`:125-129`). The second parameter is `int $expectedLockVersion` (`Exception/OptimisticLockException.php:42`), and the file uses `strict_types=1`, so PHP throws `TypeError: … Argument #2 ($expectedLockVersion) must be of type int, null given`.
  - The error is thrown from `PRE_UPDATE`. Every save of every pre-existing object of the class fails: admin save, imports, workflows, CoreShop services that save the object. For a class like orders or products this takes down the whole area until someone back-fills the column by SQL. Nothing in the docs mentions that step.
  - The code is also inconsistent with itself: the increment at `:102` and the manager (`Manager/EntityLockManager.php:55`) treat `null` as `1`, the check treats it as a mismatch.
  - Related: a missing row (`fetchOne()` → `false` → `0`) ends in the same branch, and only fails "nicely" if the object's version happens to be non-null.
- **Fix:**
  - Define one rule for "never versioned" and apply it everywhere. Recommended: if the stored value is `NULL`, accept the save and start at `1` (the first save "adopts" the object). Compare with `$current = $row === false || $row === null ? null : (int) $row;`.
  - Never pass `null` to `lockFailedVersionMismatch()`: type the factory `?int` or normalise before the call.
  - Document the migration (`UPDATE object_store_<id> SET optimisticLockVersion = 1 WHERE optimisticLockVersion IS NULL`), or ship a console command for it.
  - Add a Behat scenario: an object saved before the interface/field existed can still be saved (TODO-3 **I-12**).

### C-02 — Saving a draft (or Pimcore autosave) bumps the version only in the draft; after that, the object can no longer be published from the admin
- **Where:** `EventListener/LockListener.php:38-45,84-103`. **(dependent)** `six-it/pimcore-source/models/DataObject/Concrete.php:235-273` (`saveVersion()` dispatches `PRE_UPDATE` and `POST_UPDATE` with `saveVersionOnly: true`), `six-it/admin-ui-classic-bundle/src/Controller/Admin/DataObject/DataObjectController.php:1281,1333,1374-1378` (`saveAction` loads the **latest version** of the object and calls `saveVersion()` for the tasks `version` and `autoSave`), `:1979-1992` (`getLatestVersion()`).
- **Problem:**
  - `checkLock()` does not look at the `saveVersionOnly` argument. On "Save draft" or autosave it checks the DB value (`N`, OK), then sets the object to `N+1` (`:102`). The object is serialised into the draft version with `N+1`, but `object_store_<id>` is not written, so the DB still holds `N`.
  - The next admin request (another autosave, "Save & Publish") loads the object **from that draft** (`getLatestVersion()`), so it carries `N+1`. `ensureVersionMatch()` compares `N` (DB) with `N+1` and throws `OptimisticLockException`.
  - Result: for any lock-enabled class, after the first autosave or draft save an editor can no longer publish, and also cannot autosave again. The only way out is deleting the draft version. Pimcore's admin autosave runs automatically while an object is open, so in practice editing lock-enabled objects in the admin is broken.
  - The same happens in code: `$object->saveVersion(); $object->save();` on one instance always throws.
- **Fix:** in `checkLock()`, return early when `$event->hasArgument('saveVersionOnly') && $event->getArgument('saveVersionOnly')`: no check and no increment (the check belongs to the moment the store table is written). Add a Behat scenario "save version only, then save" (TODO-3 **I-12**). Then decide how a draft that was created at `N` should behave when the object meanwhile became `N+1` (a real conflict); see TODO-2 **B-02**.

### C-03 — The check and the write are not atomic, so two concurrent saves can still overwrite each other (lost update)
- **Where:** `EventListener/LockListener.php:84-130`. **(dependent)** `six-it/pimcore-source/models/DataObject/AbstractObject.php:494-560` (`PRE_UPDATE` is dispatched at `:504`, **before** `beginTransaction()` at `:523`).
- **Problem:**
  - Optimistic locking only works if "compare version" and "write version + 1" happen in one atomic step (`UPDATE … WHERE version = :expected`, then check the affected row count), or with the row locked between them.
  - Here, `ensureVersionMatch()` runs a plain `SELECT` outside any transaction. Then Pimcore opens its own transaction and writes the object. Two processes that loaded the object at version `N` can both pass the `SELECT` (both see `N`), and both write `N+1`. The second one silently overwrites the first one's changes. That is exactly the lost update the bundle promises to prevent (`docs/03_Bundles/OptimisticEntityLock_Bundle.md`, "Functionality").
  - The window is the time between `PRE_UPDATE` and the end of Pimcore's `update()`: all other `PRE_UPDATE` listeners, `correctPath()`, the child-path update and the whole `update()` with relations, localized fields and so on. Under parallel workers (Messenger, imports, payment callbacks that save orders) this is not a rare event.
- **Fix:** turn the check into a compare-and-swap in the listener:
  - In `checkLock()`, run `UPDATE object_store_<id> SET optimisticLockVersion = :next WHERE oo_id = :id AND (optimisticLockVersion = :expected OR (optimisticLockVersion IS NULL AND :expected IS NULL))` (see C-01 for `NULL`). If the affected row count is not `1`, throw `OptimisticLockException`. Then set the object to `:next`, so Pimcore writes the same value again.
  - Subscribe to `POST_UPDATE_FAILURE` and set the column back to `:expected` (and the object, see TODO-2 **B-03**) when Pimcore's save fails after the swap.
  - The alternative, `SELECT … FOR UPDATE`, does not work from `PRE_UPDATE`, because there is no open transaction there yet; it would need Pimcore's transaction to start earlier.
  - Add a test with two DBAL connections (TODO-3 **I-12**).
