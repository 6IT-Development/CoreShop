# Sequence Component – TODO 1: Critical errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-28 (branch `s74-p84`).
Scope: every PHP file of `src/CoreShop/Component/Sequence` (7 files: `Factory/SequenceFactory{,Interface}.php`, `Generator/SequenceGenerator{,Interface}.php`, `Model/Sequence{,Interface}.php`, `Repository/SequenceRepositoryInterface.php`), `composer.json` and `README.md`, plus the config and code that wire, map or consume this component: `SequenceBundle/Resources/config/services.yml`, `SequenceBundle/Resources/config/doctrine/model/Sequence.orm.xml`, `SequenceBundle/DependencyInjection/{Configuration,CoreShopSequenceExtension}.php`, `SequenceBundle/Doctrine/ORM/SequenceRepository.php`, `OrderBundle/Resources/config/services/{order,order_invoice,order_shipment}.yml`, `CoreBundle/Resources/config/services/order.yml` (prefix/suffix decorators, committers), `Component/Order/NumberGenerator/SequenceNumberGenerator.php`, `Component/Order/Transformer/OrderTo{Invoice,Shipment}Transformer.php`, `Component/Core/Order/Committer/{Order,Quote}Committer.php`, `ResourceBundle/Doctrine/ORM/EntityRepository.php`, `Component/Resource/{Factory/FactoryInterface,Repository/RepositoryInterface,Model/SetValuesTrait}.php`, `PayumBundle/EventListener/TransactionListener.php`.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4.21, Doctrine ORM 2.20.13, DBAL 3.10.6, DoctrineBundle 2.19.1, PHP 8.4.27). Items marked **(dependent)** have their root cause or part of the fix outside this component. Items marked **(verified)** were confirmed by reading the vendored library source. Items marked **(plausible)** follow from the code, but the trigger depends on data, load or configuration. A runtime reproduction was not possible (no `pdo_sqlite` in the CLI, and no test tables were created in the dev database).

"Critical" here means: duplicate or wrong document numbers (orders, quotes, invoices, shipments), data loss, a shop-wide outage of checkout / invoicing, or a blocker for Symfony 7.4 / PHP 8.4.

**No Symfony 7.4 / PHP 8.4 blocker was found in this component.** Every file lints on PHP 8.4, there are no implicitly nullable parameters, and nothing from the component appears in the dev container deprecation log. Modernisation is tracked in TODO-3.

**Relation to SequenceBundle TODOs:** `SequenceBundle/TODO-*.md` (scan 2026-10-27) already scanned this component as a dependency. The items below whose root cause lives in this component are the **owning** entries; the bundle entries with the same ID in the "Same as" line describe the same defect and should be closed together. Bundle-only items are listed under "Overlap" at the end.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

**Why this matters:** `SequenceGeneratorInterface` is the only source of order, quote, invoice and shipment numbers (`OrderBundle/Resources/config/services/order.yml:5-15`, `order_invoice.yml:5-9`, `order_shipment.yml:5-9`, decorated with prefix/suffix in `CoreBundle/Resources/config/services/order.yml:27-60`, consumed by `OrderCommitter.php:62`, `QuoteCommitter`, `OrderToInvoiceTransformer.php:69`, `OrderToShipmentTransformer.php:59`). Counters are global per type, not per store.

---

## Data written wrongly

### C-01 — The generator increments a possibly stale in-memory `index`, so a second call in the same `EntityManager` can return a number another process already used — (verified code path, trigger plausible) (dependent: SequenceBundle repository)
- **Same as:** SequenceBundle TODO-1 **C-01**.
- **Where:**
  - `Generator/SequenceGenerator.php:39-45`: `getSequence()` → `incrementIndex()` → `flush()` → `getIndex()`. The generator trusts that `findForType()` returned fresh data.
  - `SequenceBundle/Doctrine/ORM/SequenceRepository.php:28-37`: DQL with `setLockMode(LockMode::PESSIMISTIC_WRITE)`, no `Query::HINT_REFRESH`.
  - `vendor/doctrine/orm/src/UnitOfWork.php:2958-2963` (`createEntity()`): if the row is already in the identity map and `HINT_REFRESH` is not set, the managed object is returned unchanged and the values the locking `SELECT` just read are discarded.
- **Problem:** the row lock is taken, but the value is not re-read. The first call for a type in an `EntityManager` loads the row. Every later call in the same `EntityManager` increments the cached value and writes `UPDATE … SET idx = <cached + 1>`. If another process incremented the row in between, both processes hand out the same number, and the DB counter goes backwards.
  - Triggers: CLI imports or custom commands that create several orders / invoices / shipments while the shop is live, back-office bulk invoicing/shipping in one request, a Messenger message that creates several documents, worker-mode runtimes (FrankenPHP / RoadRunner) that keep the `EntityManager` between requests.
  - Nothing downstream checks uniqueness of `orderNumber` / `invoiceNumber` / `shipmentNumber` on the Pimcore objects, so the duplicate is written silently. Duplicate invoice numbers are a legal / accounting problem (e.g. HU NAV, DE GoBD).
- **Fix:**
  - Component side: the generator must not depend on the repository returning a fresh object. Either call `$em->refresh($sequence, LockMode::PESSIMISTIC_WRITE)` after the lookup, or (preferred, see TODO-3 **I-01**) move the read-modify-write out of the ORM: a single atomic DBAL statement (`INSERT … ON DUPLICATE KEY UPDATE idx = LAST_INSERT_ID(idx + 1)` + `SELECT LAST_INSERT_ID()`).
  - Bundle side (minimum): `->setHint(Query::HINT_REFRESH, true)` in `SequenceRepository::findForType()`.
  - Test: two `EntityManager` instances on the same DB; call on EM 1, increment via EM 2, call on EM 1 again → must return a higher number than EM 2's last one.

## Outage / data loss

### C-02 — No rollback: any exception between `beginTransaction()` and `commit()` leaves the transaction open, the sequence row locked and the `EntityManager` closed — (verified code path, trigger plausible)
- **Same as:** SequenceBundle TODO-1 **C-02** (root cause is in this component).
- **Where:** `Generator/SequenceGenerator.php:37-43`: `beginTransaction()` … `flush()` … `commit()` without `try` / `catch` / `finally` and without `rollback()`.
  - Things that can throw inside the window: `findForType()` (`DeadlockException`, `LockWaitTimeoutException`, `TransactionRequiredException`), `$this->sequenceRepository->add()` (it flushes, `ResourceBundle/Doctrine/ORM/EntityRepository.php:27-31`; `UniqueConstraintViolationException` from TODO-2 **B-01**), `flush()` (any unrelated pending entity, TODO-2 **B-02**), or "The EntityManager is closed" from an earlier failure in the request (TODO-2 **B-04**).
  - `vendor/doctrine/orm/src/UnitOfWork.php:486-497`: a failed flush closes the `EntityManager` and rolls back only its own nesting level. The level opened by the generator stays active (DBAL 3 marks it rollback-only).
- **Problem:**
  - The connection keeps an open transaction that holds `FOR UPDATE` on the `order` / `invoice` / `shipment` / `quote` row. In a short web request this ends when the connection closes. In a long-running process (Messenger worker, CLI import, maintenance job) it lasts until the process exits: every other checkout and every invoice / shipment creation waits `innodb_lock_wait_timeout` (50 s by default) and then fails. **Checkout is blocked shop-wide.**
  - The connection is shared with Pimcore (`Pimcore\Db::get()` and `doctrine.orm.entity_manager` use the default DBAL connection). Everything the same process saves afterwards (Pimcore objects, other CoreShop resources) runs inside the dangling transaction and is rolled back when the process ends: **silent data loss**.
  - The closed `EntityManager` makes every later Doctrine call in the request fail, which hides the original error.
- **Fix:**
  - Use `$this->entityManager->wrapInTransaction(fn () => …)` (`vendor/doctrine/orm/src/EntityManager.php:272-295` rolls back on failure), or `try { … $em->commit(); } catch (\Throwable $e) { $em->getConnection()->isTransactionActive() && $em->rollback(); throw $e; }`.
  - With the atomic DBAL statement from TODO-3 **I-01**, no explicit transaction is needed at all.
  - Wrap DBAL errors in a domain exception (`SequenceGenerationException`, TODO-3 **I-03**) so callers (`OrderCommitter`) can show a clean checkout error.
  - Test: make `flush()` throw, then assert `isTransactionActive() === false` and that a second call on a fresh EM works.

---

## Overlap with other TODOs (not repeated here)

- SequenceBundle TODO-2 **B-05**: `type` longer than 100 characters (`Sequence.orm.xml:10`) is truncated or fails; the component part (validation) is TODO-2 **B-05** here.
- SequenceBundle TODO-2 **B-06**: the documented service id `coreshop.sequence.generator` does not exist (`docs/03_Bundles/Sequence_Bundle.md`).
- SequenceBundle TODO-3 **I-04**, **I-05**, **I-10**, **I-11**: bundle class, configuration and service definition cleanup.
- PayumBundle `EventListener/TransactionListener.php:61,70` opens a connection-level transaction for the whole Payum controller request and only commits on `kernel.response` (never rolls back). Any number generated inside it keeps the sequence row locked for the whole request, including gateway HTTP calls (see TODO-2 **B-06**). Root cause is in PayumBundle.
