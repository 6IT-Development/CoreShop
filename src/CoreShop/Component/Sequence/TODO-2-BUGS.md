# Sequence Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-28 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs that are not critical on their own (limited trigger, workaround exists, or wrong behaviour without data loss).
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this component. Items marked **(verified)** were confirmed by reading the vendored library source. Items marked **(plausible)** follow from the code, but the trigger depends on data, load or configuration.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Concurrency / transactions

### B-01 — First use of a type is not safe against concurrent requests — (plausible)
- **Same as:** SequenceBundle TODO-2 **B-01**.
- **Where:** `Generator/SequenceGenerator.php:48-58` (`findForType()` → `null` → `createWithType()` → `add()`); unique index on `name` (`SequenceBundle/Resources/config/doctrine/model/Sequence.orm.xml:10`).
- **Problem:** when the row does not exist, `SELECT … FOR UPDATE` only takes an InnoDB gap lock, and gap locks do not block each other. Two requests both get `null`, both insert → deadlock or `UniqueConstraintViolationException`. The exception then runs into TODO-1 **C-02**. Hits the first order / invoice / shipment / quote after go-live or after a reset of `coreshop_sequence`, and the first use of any custom type.
- **Fix:** atomic upsert (TODO-3 **I-01**). Alternatively catch `UniqueConstraintViolationException`, roll back, reset the manager and retry the locked select once. Seeding the built-in types in a migration (SequenceBundle TODO-3 **I-06**) removes the common case.

### B-02 — The generator flushes the whole unit of work, twice on first use — (verified code path)
- **Same as:** SequenceBundle TODO-2 **B-02**.
- **Where:** `Generator/SequenceGenerator.php:42` (`flush()` without argument) and `:54` → `ResourceBundle/Doctrine/ORM/EntityRepository.php:27-31` (`persist()` + `flush()`).
- **Problem:** every pending change in the shared default `EntityManager` (carts, voucher codes, other CoreShop resources and their listeners) is written inside the sequence transaction. Unrelated changes are committed earlier than their owner intended, an unrelated failure breaks number generation (→ TODO-1 **C-02**), and the row lock is held for all those writes.
- **Fix:** do not use the shared unit of work for the counter (TODO-3 **I-01**). If the ORM is kept: `persist()` instead of `RepositoryInterface::add()`, and only the `Sequence` must be written (dedicated EM or DBAL `UPDATE`).

### B-03 — Deadlocks / lock wait timeouts are not retried — (plausible)
- **Same as:** SequenceBundle TODO-2 **B-03**.
- **Where:** `Generator/SequenceGenerator.php:35-46`.
- **Problem:** `Doctrine\DBAL\Exception\RetryableException` (deadlock, lock wait timeout) goes straight to `OrderCommitter::commitOrder()` (`Component/Core/Order/Committer/OrderCommitter.php:62`); the customer sees a failed checkout although a retry would succeed.
- **Fix:** retry 2–3 times on `RetryableException` with rollback in between (after TODO-1 **C-02** is fixed). With TODO-3 **I-01** it becomes rare.

### B-04 — The generator cannot recover from a closed `EntityManager` — (verified code path, trigger plausible) (dependent: SequenceBundle `services.yml`)
- **Where:** `Generator/SequenceGenerator.php:28-33` takes `EntityManagerInterface` directly; wired with `@doctrine.orm.entity_manager` (`SequenceBundle/Resources/config/services.yml:6-10`).
- **Problem:** any earlier Doctrine failure in the same request / message (e.g. a failed cart or voucher flush) closes the default `EntityManager`. The generator then throws `EntityManagerClosed` on `beginTransaction()`/`flush()`, so the order or invoice cannot get a number, even though the sequence itself is fine. Because the service holds the EM instance (not the registry), it cannot reset it.
- **Fix:** with TODO-3 **I-01** the generator only needs the DBAL `Connection`, which is not affected. If the ORM is kept, inject `Doctrine\Persistence\ManagerRegistry` and call `resetManager()` when `! $em->isOpen()`.

### B-05 — `type` is not validated (empty string, length > 100) — (plausible)
- **Same as:** component part of SequenceBundle TODO-2 **B-05**.
- **Where:** `Generator/SequenceGeneratorInterface.php:23`, `Generator/SequenceGenerator.php:35`, `Factory/SequenceFactory.php:39-45`, `Model/Sequence.php:60-63`; column `name` `length="100"` (`Sequence.orm.xml:10`).
- **Problem:** `''` creates a valid counter row with an empty name. A type longer than 100 characters fails with a generic DBAL error under MySQL strict mode (→ TODO-1 **C-02**). Without strict mode it is truncated; the next lookup by the full string does not find the truncated row, so every call runs into the unique violation of **B-01**.
- **Fix:** validate once at the entry point (`getNextSequenceForType()`) and in the factory: non-empty, max 100 characters, throw `\InvalidArgumentException` (or `Webmozart\Assert\Assert::lengthBetween($type, 1, 100)`). Expose the limit as a constant on `SequenceInterface` (`TYPE_MAX_LENGTH = 100`) so the mapping and the check share one value.

### B-06 — Behaviour depends on whether the caller already has an open transaction; numbers can leave gaps or hold the row lock for a whole request — (plausible) (dependent: callers)
- **Overlaps:** SequenceBundle TODO-2 **B-04** (gaps). The Payum trigger is new here.
- **Where:** `Generator/SequenceGenerator.php:37-43`; callers `Component/Core/Order/Committer/OrderCommitter.php:62`, `QuoteCommitter`, `Component/Order/Transformer/OrderToInvoiceTransformer.php:69`, `OrderToShipmentTransformer.php:59`; `PayumBundle/EventListener/TransactionListener.php:61,70`.
- **Problem:**
  - No outer transaction (normal checkout): the number is committed **before** the Pimcore object is saved. If the save fails, the number is lost → gaps. For invoices, several EU jurisdictions (HU, DE, AT) expect a continuous series or a documented reason for each gap.
  - Outer transaction present (e.g. inside a Payum controller, where `TransactionListener` begins a transaction and commits only on `kernel.response`): `commit()` is only a nesting decrement, so the `FOR UPDATE` lock on the counter row is held until the response is sent, including payment gateway HTTP calls. Every parallel checkout / invoice creation waits. If the outer transaction is rolled back (or never committed because of an exception, since `TransactionListener` has no rollback path), the number is reused, which is fine, but the document may already have been e-mailed.
  - The interface does not say which of the two behaviours a caller gets.
- **Fix:**
  - Document the contract on `SequenceGeneratorInterface` (TODO-3 **I-03**): "commits on its own, may leave gaps, must not be called inside a long-running transaction".
  - Offer an explicit variant for gapless series (e.g. `SequenceGeneratorInterface::getNextSequenceForTypeInTransaction()` or a separate `TransactionalSequenceGeneratorInterface`) that requires an active transaction and does not commit, and use it in `OrderToInvoiceTransformer` around the invoice save.
  - Log type, number and reason when a number was taken but the document save failed.

## Contract

### B-07 — `SequenceRepositoryInterface::findForType()` throws `TransactionRequiredException` when called outside a transaction — (verified) (dependent: SequenceBundle repository)
- **Where:** `Repository/SequenceRepositoryInterface.php:25`; implementation `SequenceBundle/Doctrine/ORM/SequenceRepository.php:34` (`setLockMode(LockMode::PESSIMISTIC_WRITE)`); `vendor/doctrine/orm/src/Query.php:783-789` throws when no transaction is active.
- **Problem:** the interface method looks like a normal finder (`find…For…`, nullable return, no PHPDoc), but it only works inside a transaction because it always takes a write lock. Any reporting, admin, export or test code that wants to read the current counter (`$repo->findForType('invoice')->getIndex()`) crashes. When it is called inside an unrelated transaction, it silently takes a row lock that blocks checkout until that transaction ends.
- **Fix:** split the contract: `findForType(string $type): ?SequenceInterface` as a plain read (no lock), and a separate `findForTypeForUpdate()` / `lockForType()` used only by the generator, with PHPDoc `@throws TransactionRequiredException` (or make it obsolete with TODO-3 **I-01**).

### B-08 — `SequenceFactory` does not check that the configured model class implements `SequenceInterface` — (plausible)
- **Where:** `Factory/SequenceFactory.php:29-31,41-44`; class comes from `core_shop_sequence.resources.sequence.classes.model` (`SequenceBundle/DependencyInjection/Configuration.php`).
- **Problem:** a misconfigured model class (typo, or a class that does not implement the interface) is only detected on the first new sequence type, deep in checkout, as an `Error` (`setType()` undefined) or a `TypeError` from the `SequenceInterface` return type. That runs into TODO-1 **C-02** with the transaction open.
- **Fix:** check in the constructor (`is_a($className, SequenceInterface::class, true)` → `\InvalidArgumentException`), and type it `@param class-string<SequenceInterface> $className`. Better, validate in the bundle `Configuration` (`->validate()`), so the container fails at build time.
