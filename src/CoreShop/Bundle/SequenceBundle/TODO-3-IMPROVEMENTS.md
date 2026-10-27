# SequenceBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items (Symfony 8, Doctrine ORM 3 / DBAL 4). None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as sub-bullets.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle.

**Already fine for Symfony 7.4:** the extension goes through `AbstractModelExtension` → `AbstractPimcoreExtension` (not the deprecated `HttpKernel\DependencyInjection\Extension`), service config is YAML (not the XML service format deprecated in 7.4; the `.orm.xml` file is Doctrine mapping, not service config), `TreeBuilder` is constructed with a root name, no `ContainerAwareInterface`, no removed `Kernel`/`Bundle` APIs. Constructor property promotion is already used in `SequenceFactory` and `SequenceGenerator`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Logic / design

### I-01 — Replace the ORM read-modify-write with one atomic DBAL statement
- **Where:** `Component/Sequence/Generator/SequenceGenerator.php`, `Doctrine/ORM/SequenceRepository.php`.
- **Proposal:** a `DbalSequenceGenerator` (in this bundle, since it is Doctrine-specific) that takes `Doctrine\DBAL\Connection`:
  - `INSERT INTO coreshop_sequence (name, idx) VALUES (:type, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE idx = LAST_INSERT_ID(idx + 1)`, then `SELECT LAST_INSERT_ID()` (per-connection, safe under concurrency on MySQL/MariaDB, the only DBs Pimcore supports).
  - Fixes TODO-1 **C-01**, **C-02**, TODO-2 **B-01**, **B-02**, **B-03** at once; no unit of work, no `EntityManager` closing, lock held for one statement only.
  - Keep `SequenceRepositoryInterface::findForType()` for reading/admin, but stop using `PESSIMISTIC_WRITE` there (a read method should not need a running transaction, see I-08).
  - Make the generator `final` and `readonly` (`final readonly class`), and keep `SequenceGeneratorInterface` as the extension point.

### I-02 — Clarify the generator contract in the interface
- **Where:** `Component/Sequence/Generator/SequenceGeneratorInterface.php:21-24`.
- **Proposal:** PHPDoc that states: returns a positive int, strictly increasing per type, unique, may leave gaps, commits on its own (or not, see TODO-2 **B-04**), thrown exceptions (`\InvalidArgumentException` for an invalid type, a domain `SequenceGenerationException` wrapping DBAL errors). Optionally add `getCurrentSequenceForType(string $type): int` (read-only, useful for admin/reporting and tests).

### I-03 — Column/field naming in the mapping is confusing
- **Where:** `Resources/config/doctrine/model/Sequence.orm.xml:10-11`.
- **Problem / Fix:**
  - Field `type` is stored in column `name`, field `index` in column `idx`. Readers of the DB or of raw SQL have to know the mapping. Keep the columns (BC, existing data), but document it in the mapping and the model PHPDoc, or rename in a major version with a migration.
  - Give the unique index an explicit name (`<unique-constraints><unique-constraint name="coreshop_sequence_name" columns="name"/></unique-constraints>`) instead of the generated `UNIQ_…` hash, so migrations and error handling (B-01) can reference it.
  - Declare `type="string"` explicitly on `type` and `options="unsigned"` / `default 0` on `idx`.
  - Add `xsi:schemaLocation` to the mapping file so IDEs/`doctrine:schema:validate` validate it.

### I-04 — Drop the unused generic admin controller for sequences
- **Where:** `DependencyInjection/Configuration.php:53,59`; compiled `coreshop.admin_controller.sequence`.
- **Proposal:** there is no admin UI, route or JS for sequences; the controller only adds a public service with a broken `createNew()` path (TODO-2 **B-07**). Remove the `admin_controller` default (check that `AbstractDriver` handles a missing class, `ResourceBundle/DependencyInjection/Driver/AbstractDriver.php:42,62`) and the unused `options` `variableNode` (nothing reads it), or document what they are for.

### I-05 — Remove the dead `build()` override and literal namespace string in the bundle class
- **Where:** `CoreShopSequenceBundle.php:34-42`.
- **Fix:** `build()` only calls `parent::build()` → delete it (and the `ContainerBuilder` import). `getModelNamespace()` returns a hard-coded string; derive it from the model class (`(new \ReflectionClass(Sequence::class))->getNamespaceName()`) or keep the string but reference it from one constant, so a namespace move is caught by static analysis.

### I-06 — Ship a migration (and seed rows) for `coreshop_sequence`
- **Where:** no migration in `CoreBundle/Migrations` or elsewhere creates or alters `coreshop_sequence`; docs say `doctrine:schema:update --force`. (dependent)
- **Proposal:** add a migration that creates the table if missing (with the named unique index from I-03) and seeds `order`, `quote`, `invoice`, `shipment` with `idx = 0` (`INSERT IGNORE`). Removes the first-use race (TODO-2 **B-01**) for the built-in types and makes installs reproducible.

### I-07 — Tests
- **Where:** no `Tests/` in the bundle or component; no Behat context covers sequences.
- **Proposal:** unit tests for `SequenceFactory` (type set, `createNew()` behaviour) and the generator (first use creates row, increments, invalid type), plus an integration test against MySQL for concurrency (two connections), the stale-identity-map case (TODO-1 **C-01**) and the rollback case (TODO-1 **C-02**).

## Symfony 7.4 / PHP 8.4 modernisation

### I-08 — Native types on the model, interface and factory
- **Where:** `Component/Sequence/Model/SequenceInterface.php:25-42`, `Model/Sequence.php:30-68`, `Factory/SequenceFactory.php:26-37`.
- **Fix:**
  - `getIndex(): int`, `getType(): string` (or `?string` until set), `setType(string $type): void`, `incrementIndex(): void`; remove the now-redundant `@return`/`@param` docblocks.
  - Typed properties: `protected ?int $id = null; protected string $type; protected int $index = 0;` (or `private` + `final` if no subclass needs them; check `ORMMappedSuperClassSubscriber` / custom model overrides via `classes.model` before making the class final). `@var mixed` on `$id` contradicts `getId(): ?int`.
  - Drop `@psalm-suppress MissingConstructor` once properties are initialised (or add a constructor `__construct(string $type)` and let the factory use it).
  - `SequenceFactory::createNew()`: add the return type (`never` if it keeps throwing, PHP 8.1+) — needs `FactoryInterface::createNew()` typed in `Component/Resource` first (dependent). Type the class-string as `@param class-string<SequenceInterface> $className` (phpstan, not psalm-only) and make the promoted property `private readonly string $className`.
  - `SequenceGenerator`: `private readonly` promoted properties (or `final readonly class`, see I-01).
  - Optional PHP 8.4 asymmetric visibility for the model: `public private(set) int $index = 0;` instead of getter + increment method, if the resource model conventions allow it.

### I-09 — Doctrine ORM 3 / DBAL 4 readiness
- **Where:** `Doctrine/ORM/SequenceRepository.php:24,34`, `Component/Sequence/Generator/SequenceGenerator.php:37-43`.
- **Fix:**
  - DBAL 4 turns `LockMode` into an enum; `setLockMode(LockMode::PESSIMISTIC_WRITE)` keeps working, but the repository's `int` assumptions elsewhere (`ResourceBundle` `EntityRepository`) should be checked. (dependent)
  - `beginTransaction()` / `commit()` on `EntityManagerInterface` are fine in ORM 3, but `wrapInTransaction()` is the documented API; ORM 3 removes `flush($entity)` (not used here, good).
  - DBAL 3 nested transactions without savepoints are deprecated; ensure `use_savepoints: true` in the Doctrine config so nesting inside Pimcore's transactions behaves (relevant for TODO-2 **B-04**). (dependent)
  - `EntityRepository::add()` uses `$this->_em` (deprecated `_em` property in ORM 2.x, removed in 3) → `getEntityManager()`. (dependent, ResourceBundle)

### I-10 — Service definitions
- **Where:** `Resources/config/services.yml:1-10`.
- **Fix:**
  - `_defaults: public: true` makes the generator public for no reason; only the interface alias needs to be public (BC for `$container->get()`), the concrete class can be private.
  - Use named arguments (`$sequenceRepository: '@coreshop.repository.sequence'`, …) instead of positional ones, or autowiring with `#[Autowire(service: 'coreshop.repository.sequence')]` on the constructor, so argument reordering cannot silently swap services.
  - Add the BC alias from TODO-2 **B-06** here (with `deprecated:`) if the docs are not changed.

### I-11 — Configuration class cleanup
- **Where:** `DependencyInjection/Configuration.php:34-41`, `DependencyInjection/CoreShopSequenceExtension.php:29-37`.
- **Fix:**
  - The `/** @var ArrayNodeDefinition $rootNode */` cast is not needed (`TreeBuilder::getRootNode()` is generic-typed in 7.x).
  - Add `->info()` to `resources.sequence.classes.*` so `config:dump-reference core_shop_sequence` explains them.
  - Extension: the processed config overwrites the `$configs` parameter; use `$config = $this->processConfiguration(new Configuration(), $configs);` for readability.

### I-12 — `composer.json` (bundle and component) does not describe what the code needs
- **Where:** `composer.json:26-35`, `Component/Sequence/composer.json:26-34`.
- **Fix:**
  - Add `"php": "^8.4"` to both.
  - Component: it uses `Doctrine\ORM\EntityManagerInterface` (`SequenceGenerator.php:24`) but does not require `doctrine/orm` (only via `six-it/pimcore-source`). Either require `doctrine/orm` / `doctrine/dbal` or, better, move the Doctrine-dependent generator into the bundle (I-01) so the component stays storage-agnostic.
  - Bundle: declare `doctrine/orm`, `doctrine/dbal`, `doctrine/doctrine-bundle`, `symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel` (`^7.4`) that it uses directly.
  - Both: `require-dev` pins PHPStan 1.x; bump to PHPStan 2 together with the other bundles. `"minimum-stability": "dev"` and the `path` repository have no effect for consumers; drop them or move them to the root project. `Component/Sequence/composer.json:48` has a stray indentation (`   "extra"`).

### I-13 — README / docs
- **Where:** `README.md`, `docs/03_Bundles/Sequence_Bundle.md`.
- **Fix:** README links to `coreshop.org` (http) and has typos ("It is build from"); point to the current docs. Docs: besides TODO-2 **B-06**, describe the table, the built-in types (`order`, `quote`, `invoice`, `shipment`), that counters are global (not per store; store prefix/suffix come from `SaleNumberGenerator`), and how to reset/seed a counter safely.
