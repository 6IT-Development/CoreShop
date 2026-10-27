# SequenceBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (4 files: `CoreShopSequenceBundle.php`, `DependencyInjection/{Configuration,CoreShopSequenceExtension}.php`, `Doctrine/ORM/SequenceRepository.php`), `composer.json`, `README.md` and all of `Resources/config/**` (`services.yml`, `doctrine/model/Sequence.orm.xml`). The bundle only wires things up, so the whole `Component/Sequence` (model, factory, generator, repository interface, `composer.json`) was scanned as well. Call paths were followed into `ResourceBundle` (`AbstractResourceBundle`, `AbstractModelExtension::registerResources()`, `Driver/AbstractDriver`, `Doctrine/ORM/EntityRepository`, `ORMMappedSuperClassSubscriber`, `Routing/ResourceLoader`), `Component/Resource` (`FactoryInterface`), `OrderBundle` (`services/order.yml`, `order_invoice.yml`, `order_shipment.yml`), `CoreBundle` (`services/order.yml` prefix/suffix decorators, `CoreShopCoreBundle`), `Component/Order` (`SequenceNumberGenerator`, `OrderToInvoiceTransformer`, `OrderToShipmentTransformer`), `Component/Core` (`OrderCommitter`, `QuoteCommitter`, `SaleNumberGenerator`) and the docs (`docs/03_Bundles/Sequence_Bundle.md`).

Reference for API checks: `vendor/` (`doctrine/orm` 2.20.13, `doctrine/dbal` 3.10.6, `doctrine/doctrine-bundle` 2.19.1, `symfony/*` 7.4.21, `six-it/pimcore-source`), the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml` (+ its `…Deprecations.log`). This bundle and `Component/Sequence` are byte-identical to the vendored `six-it/core-shop` copies (`diff -r` is empty).

"Critical" here means: a security issue, data loss or data written wrongly, or an error that takes down every request, a whole area, or the install or upgrade path once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by reading the vendored library source or the compiled container.

**Why this bundle matters:** `SequenceGeneratorInterface` is the only source of order, quote, invoice and shipment numbers (`OrderBundle/Resources/config/services/order.yml:5-15`, `order_invoice.yml:5-9`, `order_shipment.yml:5-9`, decorated with store prefix/suffix in `CoreBundle/Resources/config/services/order.yml:27-60`). The counter is global per type (`order`, `quote`, `invoice`, `shipment`), not per store. A duplicate or blocked number therefore hits checkout and back-office invoicing/shipping directly.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers. `php -l` (PHP 8.4.27) passes on every PHP file in the bundle and in `Component/Sequence`, every service definition resolves in the compiled container (`coreshop.repository.sequence`, `coreshop.factory.sequence`, `coreshop.manager.sequence`, `coreshop.admin_controller.sequence`, `coreshop.repository.factory.sequence`, `SequenceGenerator`), and the container deprecation log has no entry for this bundle or the component. No implicitly nullable parameters (PHP 8.4 deprecation). The modernisation and forward-compatibility items (Doctrine ORM 3 / DBAL 4, native types) are in TODO-3.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Data written wrongly

### C-01 — The locked `SELECT … FOR UPDATE` returns the stale entity from the identity map, so a second call in the same `EntityManager` can hand out a number that another process already used — (verified code path, plausible trigger)
- **Where:**
  - `Doctrine/ORM/SequenceRepository.php:28-37` runs a DQL query with `setLockMode(LockMode::PESSIMISTIC_WRITE)` but without `Query::HINT_REFRESH`.
  - `Component/Sequence/Generator/SequenceGenerator.php:35-46` increments the in-memory `index` of whatever object comes back and flushes it (`UPDATE coreshop_sequence SET idx = <in-memory value + 1>`).
  - `vendor/doctrine/orm/src/UnitOfWork.php:2933-2963` (`createEntity()`): when the row's id is already in the identity map and no `HINT_REFRESH` is set, the already managed object is returned **as is**; the fresh column values that the locking `SELECT` just read are thrown away.
- **Impact:** the row lock is taken, but the value is not re-read. The first `getNextSequenceForType('invoice')` in an `EntityManager` loads the row; every later call in the same `EntityManager` (same request, same CLI command, same Messenger message) works on the cached `index`. If any other process incremented the row in between, both write the same `idx` → **two orders / invoices / shipments with the same number** (and the counter goes backwards in the DB). Triggers:
  - CLI imports / migrations / custom commands that create several orders, invoices or shipments while the shop takes orders;
  - back-office actions or event listeners that create more than one invoice/shipment in one request (e.g. bulk invoicing), long-running workers that process several documents in one message, or any code that does not clear the `EntityManager` (Doctrine Bundle only clears it between Messenger messages, not inside one).
  - Document numbers must be unique (accounting, legal invoice numbering). Duplicates are written silently; nothing checks uniqueness on the Pimcore objects.
- **Fix:**
  - Smallest fix: `->setHint(Query::HINT_REFRESH, true)` on the query in `findForType()` (or `$em->refresh($sequence, LockMode::PESSIMISTIC_WRITE)` after the lookup) so the locked read always overwrites the in-memory values.
  - Better (see TODO-3 **I-01**): do not use the ORM for the counter at all. One atomic statement on the DBAL connection: `INSERT INTO coreshop_sequence (name, idx) VALUES (:type, 1) ON DUPLICATE KEY UPDATE idx = LAST_INSERT_ID(idx + 1)` followed by `SELECT LAST_INSERT_ID()` (Pimcore only supports MySQL/MariaDB). This removes C-01, C-02 and B-01 together and does not touch the unit of work.
  - Add a test: two `EntityManager` instances, call the generator on EM 1, increment via EM 2, call on EM 1 again → must not return the same number.

## Error that takes down a whole area

### C-02 — Any exception between `beginTransaction()` and `commit()` leaves the transaction open with the sequence row locked, and closes the `EntityManager` — (verified code path, plausible trigger)
- **Where:**
  - `Component/Sequence/Generator/SequenceGenerator.php:37-43`: `beginTransaction()` … `flush()` … `commit()` with no `try`/`catch`/`finally` and no `rollback()`.
  - Things that can throw in that window: `findForType()` (deadlock / lock wait timeout, `LockWaitTimeoutException`, `DeadlockException`), `SequenceRepository::add()` (`EntityRepository::add()` flushes, `UniqueConstraintViolationException` from B-01), `flush()` (any other pending entity in the unit of work, see B-02, or a DB error).
  - `vendor/doctrine/orm/src/UnitOfWork.php:486-497`: a failed flush closes the `EntityManager` and only rolls back its **own** nesting level. The outer level opened by `SequenceGenerator` stays active (DBAL 3 marks it rollback-only).
- **Impact:**
  - The connection keeps an open transaction that holds the `FOR UPDATE` lock on the `order` / `invoice` / … row. In a web request this ends when PHP closes the connection. In a long-running process (Messenger worker, CLI import, a `pimcore:maintenance` job) the lock is held until the process exits: every other checkout and every invoice/shipment creation blocks for `innodb_lock_wait_timeout` (50 s default) and then fails. **Checkout is down shop-wide** until the worker is killed.
  - Everything the same process writes afterwards on the shared Pimcore/Doctrine connection (`Pimcore\Db::get()` is the same DBAL connection) runs inside that never-committed transaction and is rolled back when the process exits → silent **data loss** of later saves in that worker.
  - The closed `EntityManager` makes every later Doctrine call in the request fail with "The EntityManager is closed" (carts, price rules, other CoreShop Doctrine resources), so the original error is hidden behind follow-up errors.
- **Fix:**
  - Use `$this->entityManager->wrapInTransaction(fn () => …)` (it rolls back and closes properly, `vendor/doctrine/orm/src/EntityManager.php:272-296`), or at least `try { … commit(); } catch (\Throwable $e) { if ($conn->isTransactionActive()) { $conn->rollBack(); } throw $e; }`.
  - With the atomic DBAL statement from C-01 the explicit transaction is not needed at all (single autocommit statement, row lock held only for the statement).
  - Retry once or twice on `RetryableException` (deadlock / lock wait timeout) before giving up (TODO-2 **B-03**).
  - Add a test: make `flush()` throw, then assert `isTransactionActive() === false` and that a second call works.
