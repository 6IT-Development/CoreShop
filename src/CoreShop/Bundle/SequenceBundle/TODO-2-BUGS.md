# SequenceBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs that are not critical on their own (limited trigger, workaround exists, or wrong behaviour without data loss).
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by reading the vendored library source or the compiled container.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Concurrency

### B-01 — First use of a sequence type is not safe against concurrent requests — (plausible)
- **Where:** `Component/Sequence/Generator/SequenceGenerator.php:48-58`, `Doctrine/ORM/SequenceRepository.php:28-37`, unique index on `name` (`Resources/config/doctrine/model/Sequence.orm.xml:10`).
- **Problem:** when the row for a type does not exist yet, `SELECT … FOR UPDATE` locks nothing that the other request respects (InnoDB gap locks are compatible with each other). Two requests both get `null`, both create a new `Sequence` and both `INSERT`. Result: either a deadlock (one request fails) or a `UniqueConstraintViolationException` from the unique `name` index. The exception then runs into TODO-1 **C-02** (open transaction, closed `EntityManager`).
- **When:** the first order / quote / invoice / shipment after go-live or after truncating `coreshop_sequence`, and the first use of any custom type (docs suggest `getNextSequenceForType('my_sequence')`). Only once per type, so not critical, but the first customers of a new shop hit it.
- **Fix:** the atomic `INSERT … ON DUPLICATE KEY UPDATE` from TODO-1 **C-01** / TODO-3 **I-01** solves it. Alternative: catch `UniqueConstraintViolationException`, reset/reopen the EM, and retry the locked select. Seeding the four built-in types in a migration (TODO-3 **I-06**) removes the common case.

### B-02 — The generator flushes the whole unit of work (twice), so unrelated pending changes are written inside the sequence transaction — (verified code path)
- **Where:** `Component/Sequence/Generator/SequenceGenerator.php:42` (`$this->entityManager->flush()`), `:54` → `ResourceBundle/Doctrine/ORM/EntityRepository.php` `add()` (`persist()` + `flush()`).
- **Problem:** `flush()` without argument writes every scheduled change in the shared default `EntityManager` (carts, cart price rule voucher codes, other CoreShop Doctrine resources, plus their listeners), not only the sequence. On the first use of a type, `add()` flushes and the generator flushes again.
  - Unrelated changes get committed earlier than the caller intended (they are no longer part of the caller's own transaction / rollback).
  - A failure in an unrelated entity makes number generation fail (and triggers TODO-1 **C-02**).
  - The sequence row lock is held for the duration of all those writes, which slows every concurrent checkout.
- **Fix:** do not use the ORM unit of work for the counter (TODO-3 **I-01**). If the ORM path is kept: `persist()` only (no `add()`), and flush only the `Sequence` (or use a dedicated `EntityManager` / DBAL query).

### B-03 — Deadlocks and lock wait timeouts are not retried — (plausible)
- **Where:** `Component/Sequence/Generator/SequenceGenerator.php:35-46`.
- **Problem:** with many parallel checkouts (or a long transaction holding the row, see TODO-1 **C-02**), `findForType()` can throw `Doctrine\DBAL\Exception\DeadlockException` / `LockWaitTimeoutException` (`RetryableException`). The exception goes straight up to `OrderCommitter::commitOrder()` (`Component/Core/Order/Committer/OrderCommitter.php:62`) and the customer sees a failed checkout even though a retry would succeed.
- **Fix:** retry a few times on `RetryableException` (with rollback in between). With the atomic statement from TODO-3 **I-01** the lock is held for a single statement and this becomes very unlikely.

## Behaviour

### B-04 — Numbers are taken outside the caller's save, so failed saves leave gaps; invoice numbers may need to be gapless — (dependent) (plausible)
- **Where:** `Component/Sequence/Generator/SequenceGenerator.php:37-43` commits on its own; callers: `Component/Core/Order/Committer/OrderCommitter.php:62`, `QuoteCommitter`, `Component/Order/Transformer/OrderToInvoiceTransformer.php`, `OrderToShipmentTransformer.php`.
- **Problem:** the number is committed before the Pimcore object is saved. If the save fails (validation, Pimcore error, PHP timeout), the number is used up. For orders that is acceptable; for **invoices** several EU jurisdictions (e.g. HU, DE, AT) expect a continuous series without unexplained gaps. If the caller already runs inside a transaction (shared `Pimcore\Db::get()` connection), the commit is only a nested savepoint/level and the row lock is held until the outer commit — behaviour differs depending on the caller, which is not documented.
- **Fix:** document the contract on `SequenceGeneratorInterface` (non-transactional, may leave gaps). Optionally offer a "transactional" variant that requires an active transaction and does not commit on its own, and use it for invoices in `OrderToInvoiceTransformer` together with the invoice save. Log the type + number + reason when a number is taken but the document save fails.

### B-05 — `type` longer than 100 characters is not validated — (plausible)
- **Where:** `Resources/config/doctrine/model/Sequence.orm.xml:10` (`length="100"`), `Component/Sequence/Factory/SequenceFactory.php:39-45`, `Component/Sequence/Model/Sequence.php:60-63`.
- **Problem:** with MySQL strict mode the insert fails with a generic DBAL error (→ TODO-1 **C-02**). Without strict mode the value is truncated, and two different long types that share the first 100 characters share one counter (the next lookup by the full string does not find the truncated row either → B-01 unique violation on every call).
- **Fix:** validate in the factory / generator (`Assert::lengthBetween($type, 1, 100)` or a typed exception). Empty string is also accepted today; reject it.

### B-06 — Documented service id `coreshop.sequence.generator` does not exist; install docs are outdated — (verified)
- **Where:** `docs/03_Bundles/Sequence_Bundle.md:16-37,53`; `Resources/config/services.yml:5-10`.
- **Problem:** the docs tell users to call `$container->get('coreshop.sequence.generator')`. The compiled container only has `CoreShop\Component\Sequence\Generator\SequenceGenerator` and the interface alias; `coreshop.sequence.generator` is not defined → `ServiceNotFoundException`. The docs also show `app/AppKernel.php` / `registerBundlesToCollection()` and `doctrine:schema:update --force` (CoreShop 4 registers the bundle through `CoreShopCoreBundle::registerDependentBundles()` and uses migrations).
- **Fix:** either add a `coreshop.sequence.generator` alias (deprecated, pointing to the interface) or fix the docs to show constructor injection of `SequenceGeneratorInterface`. Update the install section (`config/bundles.php` or "registered by CoreBundle", `doctrine:migrations:migrate`).

### B-07 — `SequenceFactory::createNew()` always throws, but the bundle registers a generic admin controller and a repository factory that rely on it — (verified)
- **Where:** `Component/Sequence/Factory/SequenceFactory.php:34-37`; `DependencyInjection/Configuration.php:59` (`admin_controller` default `ResourceController`); compiled container: `coreshop.admin_controller.sequence`, `coreshop.repository.factory.sequence`.
- **Problem:** any generic code that uses the resource factory (`ResourceController::addAction()`, fixtures, `RepositoryFactory`, generic resource tooling iterating over `coreshop.resources`) throws `UnsupportedMethodException`. No route points to the admin controller today (`ResourceBundle/Routing/ResourceLoader` only loads explicitly configured resources), so this is dormant, but the service is public and listed as a resource with an admin controller.
- **Fix:** remove the `admin_controller` default for this resource (TODO-3 **I-04**). Either implement `createNew()` (empty `Sequence`) or keep throwing but do not advertise the resource to generic tooling.
