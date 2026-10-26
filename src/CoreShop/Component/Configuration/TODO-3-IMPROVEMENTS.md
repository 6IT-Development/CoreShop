# Configuration Component – TODO 3: Improvements (code quality, readability, logic, PHP 8.4 / Symfony 7.4 idioms)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
None of these are defects on their own. They make the component cleaner, safer, easier to test, or more idiomatic for the target stack.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.
Where an item extends a ConfigurationBundle TODO-3 item, the bundle item number is given. Do them together.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 language features

### I-01 — Native types on the model and its interface, with PHP 8.4 asymmetric visibility for the ID (extends bundle I-01)
- **Where:** `Model/ConfigurationInterface.php:26-46`, `Model/Configuration.php:32-82`
- Replace the docblock-only types with native ones:
  - properties: `protected ?string $key = null; protected mixed $data = null;`;
  - ID: `public private(set) ?int $id = null;` (or `protected(set)` so the Core subclass can still write it). Doctrine hydrates through reflection, which ignores the `set` visibility. Check this against the ORM version in use (ORM 2.x reflection writes work; confirm with a hydration test).
  - methods: `getKey(): ?string`, `setKey(string $key): void`, `getData(): mixed`, `setData(mixed $data): void`.
- Remove the redundant docblocks (`@var int`, `@return string`, `@param mixed`) once the native types say the same thing, and remove `@psalm-suppress MissingConstructor` once all properties have defaults.
- This is a BC break for third-party implementations of `ConfigurationInterface`. `Component/Core/Model/Configuration` only extends the class, so it is unaffected. Ship it in a minor version with an `UPGRADE` note. It fixes TODO-2 **B-07**.

### I-02 — `#[\Override]`, `final` and `readonly` where they fit (extends bundle I-02)
- **Where:** `Model/Configuration.php` (`getId`, `getKey`, `setKey`, `getData`, `setData`), `Service/ConfigurationService.php` (`get`, `set`, `remove`); **(dependent)** `Component/Core/Configuration/ConfigurationService.php`
- Add `#[\Override]` to each implemented interface method, so signature drift after I-01 or I-03 is a compile-time error.
- Constructor properties: `private readonly` instead of `protected` once the Core subclass no longer inherits from the service (I-06).

### I-03 — Replace the boolean-flag `get($key, bool $returnObject)` with intention-revealing methods (extends bundle I-09)
- **Where:** `Service/ConfigurationServiceInterface.php:25-32`, `Service/ConfigurationService.php:35-70`; **(dependent)** `Component/Core/Configuration/ConfigurationServiceInterface.php:30`, `ConfigurationService.php:45`
- The current return type `ConfigurationInterface|mixed|null` hides which one the caller gets. Suggested API:
  - `get(string $key, mixed $default = null): mixed`
  - `has(string $key): bool`
  - `find(string $key): ?ConfigurationInterface` (the entity, for the rare callers that need it)
  - `set(string $key, mixed $data): ConfigurationInterface`, `remove(string $key): void`
  - `all(): array<string, mixed>` (and `allForStore()` in Core), with explicit global → store precedence, to replace the hand-rolled merge in `CoreBundle/Controller/ConfigurationController::getAllAction()` (TODO-2 **B-04**)
- `set()`/`remove()` then use `find()` directly, instead of `get($key, true)` followed by a loose `if (!$config)`.
- Keep `get($key, true)` as a deprecated path for one minor version (`trigger_deprecation('coreshop/configuration', '4.x', ...)`).

### I-04 — Typed accessors, so consumers stop guessing the stored type
- **Where:** `Service/ConfigurationServiceInterface.php`; consumers `CoreBundle/EventListener/RequestCartRecalculation.php:58-63` (`@var int|null` cast by comment only), `CoreBundle/Maintenance/RuleAvailabilityTask.php:35-41` (`time() - $lastMaintenance` on `mixed`), `FrontendBundle/Controller/CategoryController.php:110-118`, `Component/Core/Order/NumberGenerator/SaleNumberGenerator.php:52` (`sprintf('%s…')` on `mixed`)
- Add `getInt(string $key, int $default = 0): int`, `getBool()`, `getString()`, `getArray()` (and `…ForStore()` variants in Core). Each one validates the stored value and throws a clear exception, or returns the default, instead of a late `TypeError` deep in a consumer. Use them in the consumers listed above.
- Alternatively, combine this with the key registry from bundle I-08: each key declares its type, and `get()` casts.

### I-05 — Depend on `Doctrine\Persistence\ObjectManager` (or `ManagerRegistry`) instead of `Doctrine\ORM\EntityManagerInterface`
- **Where:** `Service/ConfigurationService.php:24, 28-32`; **(dependent)** `Component/Core/Configuration/ConfigurationService.php`, `ConfigurationBundle/Resources/config/services.yml:9-13`, `CoreBundle/Resources/config/services.yml:51-57`
- The service only calls `persist()`, `remove()` and `flush()`, which `ObjectManager` provides. The component then needs only `doctrine/persistence` (TODO-2 **B-09**), and it fits the Resource abstraction (`coreshop.manager.configuration`, bundle I-05).
- In long-running processes (Messenger workers, the Symfony 7.4 runtime in FrankenPHP worker mode, Pimcore maintenance), an exception during `flush()` closes the `EntityManager`, and every later `set()` in that process fails with "The EntityManager is closed". Injecting `ManagerRegistry` and using `getManagerForClass(Configuration::class)` lets the service get a fresh manager after `resetManager()`. This also helps the retry path in TODO-1 **C-01**.

### I-06 — Composition instead of inheritance for the Core multi-store service (extends bundle I-12)
- **Where:** `Service/ConfigurationService.php` (non-final, `protected` constructor properties); **(dependent)** `Component/Core/Configuration/ConfigurationService.php:30-44` (`$myConfigurationRepository` duplicate, constructor re-declaration), `CoreBundle/Resources/config/services.yml:52-57` (`decorates` without `.inner`)
- The Core service inherits `get()`/`set()`/`remove()` that ignore the store (bundle B-05), stores the repository twice to get a narrower type, and is registered as a "decorator" that never uses the decorated service.
- Suggested shape: make the base `ConfigurationService` `final` with `private readonly` dependencies; implement Core's `StoreConfigurationService` separately (or as a real decorator that receives the base service as `.inner` and adds store handling). Either way, the two services cannot drift apart silently.
- Make the Core interface's `getForStore(..., $returnObject = false)` signature `bool` again, as the interface already declares.

## Logic / API design

### I-07 — Single source of truth for internal keys
- **Where:** string literals `'SYSTEM.PRICE_RULE.UPDATE'` in `CoreBundle/EventListener/ProductUpdateEventListener.php:40`, `PriceRuleUpdateEventListener.php:46`, `RequestCartRecalculation.php:61`; `'system.rule.availability_check.last_run'` twice in `CoreBundle/Maintenance/RuleAvailabilityTask.php:35,46`; the 17 keys in `CoreBundle/Fixtures/Data/Application/ConfigurationFixture.php:41-57`
- A typo in any of these strings fails silently (the read returns `null`). Introduce a backed enum or constants class (for example `CoreShop\Component\Core\Configuration\ConfigurationKey::PriceRuleUpdate`), and accept `string|\BackedEnum $key` in the service. The bundle's key registry (bundle I-08) can be built from the same enum.

### I-08 — Validate keys in the component, not only in forms (supports TODO-2 **B-05**)
- **Where:** `Service/ConfigurationService.php:46-70`; `Model/Configuration.php:63-66`
- Today the only limit is the database column (`length="100"`, `NOT NULL`). Add a small `ConfigurationKey` value object, or a guard in `set()`/`setKey()`, that rejects empty keys, keys over 100 characters, and keys outside the agreed character set, with an `\InvalidArgumentException` and a clear message. That message is far more useful than a DBAL error from the generic form or the `saveAll` controller.

### I-09 — Read cache with explicit invalidation (extends bundle I-10)
- **Where:** `Service/ConfigurationService.php:35-44`
- `RequestCartRecalculation` hits the database on every frontend request with a cart. Add a decorator (`CachedConfigurationService`) that is backed by a Symfony cache pool (`cache.app`, tag-aware for per-store keys) and invalidated in `set()`/`remove()`. If it uses an in-process array instead, implement `Symfony\Contracts\Service\ResetInterface` so the memo is cleared between requests in worker runtimes (`kernel.reset`).
- Also note that `findByKey()` runs a DQL query but returns the already-managed entity from the identity map without refreshing it. In one long-running unit of work (a CLI import, one maintenance run) `get()` can therefore return a value that another process has since changed. Document that, or use `Query::HINT_REFRESH` for keys that are used as cross-process signals (`SYSTEM.PRICE_RULE.UPDATE`).

### I-10 — Domain event on change
- **Where:** `Service/ConfigurationService.php:46-70`
- Dispatch a small `ConfigurationChangedEvent(string $key, mixed $old, mixed $new, ?StoreInterface $store)` (PSR-14 `EventDispatcherInterface`, optional dependency) from `set()`/`remove()`. Cache invalidation (I-09), audit logging and "settings changed" admin notifications can then listen instead of each being wired into the service.

### I-11 — Bulk write API with a single flush (extends bundle B-06)
- **Where:** `Service/ConfigurationServiceInterface.php`
- Add `setMultiple(iterable<string, mixed> $values): void` (and `setMultipleForStore()` in Core). It runs in one transaction (`wrapInTransaction()`) with one `flush()`, so the settings `saveAll` controller and `ConfigurationFixture::load()` (17 keys → 17 flushes today) become atomic and faster.

### I-12 — Repository interface: generics and documented guarantees
- **Where:** `Repository/ConfigurationRepositoryInterface.php:24-27`; **(dependent)** `Component/Core/Repository/ConfigurationRepositoryInterface.php:26` (`findForKeyAndStore(string, StoreInterface)`)
- Add `@extends RepositoryInterface<ConfigurationInterface>` (once `RepositoryInterface` declares `@template T of ResourceInterface`, as `Doctrine\Persistence\ObjectRepository` already does), so `find()`/`findBy()` return typed results for phpstan level ≥ 6. Today `CoreBundle/Controller/ConfigurationController.php:62` needs an inline `@var` to get that.
- Document on `findByKey()` that it returns the **global** row (store-agnostic contract, see bundle B-05), and widen Core's `findForKeyAndStore()` to `?StoreInterface` with an explicit `IS NULL` branch (bundle B-07).

### I-13 — Do not expose `$returnObject` to Twig, and type-hint interfaces in Behat
- **Where:** `CoreBundle/Twig/ConfigurationExtension.php:35` (`[$this->configurationService, 'getForStore']`); `Behat/Context/Setup/ConfigurationContext.php:28`, `Behat/Context/Setup/CategoryContext.php:35` (type-hint the concrete `Component\Core\Configuration\ConfigurationService`)
- The Twig function maps directly to `getForStore()`, so templates can call `coreshop_configuration('key', null, true)` and get the Doctrine entity (and, with it, setters). Point the function at a dedicated method or closure that returns only the value.
- The Behat contexts are wired with `ConfigurationServiceInterface` (`setup.yml:47,360`) but type-hint the concrete class. Any decorator of the interface (I-09, I-10) then breaks the test suite with a `TypeError`. Type-hint the interface.

### I-14 — Drop the Pimcore 10 `o_` compatibility branch from `SetValuesTrait` (follow-up to TODO-2 **B-03**)
- **Where:** `Component/Resource/Model/SetValuesTrait.php:37-40`; used by `Model/Configuration.php:30`
- Configuration is a Doctrine entity, not a Pimcore DataObject, and `o_` fields were removed in Pimcore 11. Once B-03 is fixed, consider removing the branch completely, or let the Configuration model stop using `SetValuesTrait` once the generic `add` route is disabled (TODO-2 **B-02**). That makes `setValue()` a plain dispatcher that can be typed and tested.

### I-15 — Immutable timestamps (extends bundle I-14)
- **Where:** `Component/Resource/Model/TimestampableInterface.php`, `TimestampableTrait.php` (used by `Model/Configuration.php:29`)
- Untyped getters and the mutable `\DateTime` let callers change an entity's timestamps by accident (`$c->getCreationDate()->modify('+1 day')` changes the managed entity). Moving to `?\DateTimeImmutable` (`datetime_immutable` in the mapping) is a cross-resource change. Plan it in the Resource component, and list Configuration as one of the affected models.

## Tooling, docs, tests

### I-16 — Unit tests for the component (extends bundle I-15)
- The component has no tests (no `spec/` or `Tests/` directory). Minimum set, with a mocked repository, manager and factory:
  - `get`: missing key, existing key, stored `null` (TODO-2 **B-08**);
  - `set`: new key (factory used, key set, persisted, flushed), existing key (no factory call), factory returning a wrong type (TODO-2 **B-06**);
  - `remove`: existing and missing key;
  - Core subclass: `getForStore`/`setForStore`/`removeForStore` with "store row", "only global row", "none" (TODO-2 **B-01**, bundle B-07).
- Add an integration test for the race in TODO-1 **C-01**.

### I-17 — `composer.json` and README hygiene (extends bundle I-16, I-17)
- **Where:** `composer.json`, `README.md`
- `branch-alias dev-master: 4.1-dev`, `minimum-stability: dev` and the `repositories` path entry `../../*/*` come from upstream CoreShop. Align them with the fork's versioning, or document them.
- The `"extra"` key is indented with 3 spaces and the `repositories` block is indented differently from the rest of the file (2 spaces). Normalise the indentation (`composer normalize`).
- The README is the generic boilerplate (typo "It is build from", `http://` link). Describe what the component provides (`ConfigurationInterface`, `ConfigurationRepositoryInterface`, `ConfigurationServiceInterface`), the key format (I-08), the value types allowed after bundle B-01 (JSON-serialisable scalars and arrays), and the `null` semantics (TODO-2 **B-08**).
