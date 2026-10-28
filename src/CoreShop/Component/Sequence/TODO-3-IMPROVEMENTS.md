# Sequence Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-28 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are improvements to code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward compatibility (Symfony 8, Doctrine ORM 3 / DBAL 4). None of them is a functional bug on its own.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have their root cause or part of the fix outside this component.

**BC note:** `SequenceInterface`, `SequenceGeneratorInterface`, `SequenceFactoryInterface` and `SequenceRepositoryInterface` are public extension points (custom model via `classes.model`, decorated generators in projects, `Component/Order/NumberGenerator/SequenceNumberGenerator.php` as consumer). Adding native return types to an interface breaks implementers that do not declare them. Do it in one step together with `SequenceBundle/Doctrine/ORM/SequenceRepository.php` and mention it in the upgrade notes.

**Already fine:** `declare(strict_types=1)` everywhere, constructor property promotion in `SequenceFactory` and `SequenceGenerator`, no implicitly nullable parameters, no removed Symfony / Doctrine APIs.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Logic / design

### I-01 — Replace the ORM read-modify-write with one atomic DBAL statement — (dependent: SequenceBundle)
- **Same as:** SequenceBundle TODO-3 **I-01**.
- **Where:** `Generator/SequenceGenerator.php:35-58`.
- **Proposal:**
  - Keep `SequenceGeneratorInterface` in the component as the storage-agnostic contract. Move the Doctrine implementation into SequenceBundle (e.g. `SequenceBundle/Generator/DbalSequenceGenerator`, `final readonly class`) that takes `Doctrine\DBAL\Connection`:
    `INSERT INTO coreshop_sequence (name, idx) VALUES (:type, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE idx = LAST_INSERT_ID(idx + 1)`, then `SELECT LAST_INSERT_ID()` (per connection, safe under concurrency on MySQL / MariaDB, the only databases Pimcore supports).
  - Fixes TODO-1 **C-01**, **C-02** and TODO-2 **B-01**, **B-02**, **B-03**, **B-04** in one change; no unit of work, the row lock is held for one statement only.
  - Keep the current class as a deprecated alias for one minor version (`@deprecated`, `trigger_deprecation()`), since projects may decorate or extend it (it is not `final`).

### I-02 — Remove the Doctrine dependency from the component
- **Where:** `Generator/SequenceGenerator.php:24,31` (`Doctrine\ORM\EntityManagerInterface`); `composer.json:26-29` does not require `doctrine/orm`.
- **Proposal:** a CoreShop *component* should be storage-agnostic. After **I-01** the component only holds the model, factory and interfaces. If the ORM generator stays in the component, add `doctrine/orm` to `require` (today it comes in only transitively through `six-it/pimcore-source`).

### I-03 — Document the generator contract and add a domain exception
- **Where:** `Generator/SequenceGeneratorInterface.php:21-24`.
- **Proposal:**
  - PHPDoc: returns `positive-int`, strictly increasing per type, unique, may leave gaps, commits on its own, must not be called inside a long transaction (TODO-2 **B-06**), counters are global (not per store; prefix / suffix come from `Component/Core/Order/NumberGenerator/SaleNumberGenerator.php`).
  - Add `Exception/SequenceGenerationException` (wrapping DBAL errors) and `@throws` for it and for `\InvalidArgumentException` (TODO-2 **B-05**).
  - Optional: `getCurrentSequenceForType(string $type): int` (read-only, no lock) for admin / reporting / tests, which replaces the misuse of `findForType()` (TODO-2 **B-07**).

### I-04 — `SequenceFactory::createNew()` always throws
- **Where:** `Factory/SequenceFactory.php:34-37`.
- **Proposal:** the factory is registered as a generic resource factory (`coreshop.factory.sequence`), so generic tooling may call `createNew()` (SequenceBundle TODO-2 **B-07**). Either return an empty model (`new $this->className()`), or keep throwing but declare `: never` once `Component/Resource/Factory/FactoryInterface::createNew()` has a native return type (dependent). Add a PHPDoc explaining why `createWithType()` must be used.

### I-05 — Give the model a constructor and drop unneeded traits
- **Where:** `Model/Sequence.php:23-43`.
- **Proposal:**
  - `public function __construct(string $type)` (or keep it optional for Doctrine) so a `Sequence` can never exist without a type. Then `@psalm-suppress MissingConstructor` (`:23-25`) can go, and the factory becomes `new $this->className($type)`.
  - `use SetValuesTrait` (`:28`) is not used anywhere for sequences (`SetValuesTrait` is for admin form data, and there is no admin UI for sequences). Remove it, or keep it only if `ResourceInterface` users depend on `setValues()`.
  - Make the model `final` only after checking for project overrides via `classes.model` (the bundle allows replacing it); otherwise keep it open but make properties `private` with protected accessors.

### I-06 — Model API naming
- **Where:** `Model/SequenceInterface.php:25-42`, `Model/Sequence.php:50-68`.
- **Proposal:**
  - `incrementIndex()` returns nothing; returning the new value (`incrementIndex(): int`) removes the extra `getIndex()` call in the generator and makes the intent obvious.
  - The field is called `type` in PHP but `name` in the DB, and `index` in PHP but `idx` in the DB (`Sequence.orm.xml:10-11`). Add a class-level PHPDoc on the model that names the table and columns, so readers of raw SQL / migrations find the mapping (renaming is a BC break; see SequenceBundle TODO-3 **I-03**).
  - `setType()` should not be callable after the row was persisted (changing it renames the counter). With the constructor from **I-05**, drop `setType()` from the interface in the next major, or make it `private(set)` (PHP 8.4, see **I-08**).

## Symfony 7.4 / PHP 8.4 modernisation

### I-07 — Native types on the interfaces, model and factory — (dependent: SequenceBundle repository, custom models)
- **Same as:** SequenceBundle TODO-3 **I-08** (component part).
- **Where / Fix:**
  - `Model/SequenceInterface.php:27-42`: `getIndex(): int`, `getType(): string`, `setType(string $type): void`, `incrementIndex(): void` (or `: int`, **I-06**); remove the `@return` / `@param` docblocks that only repeat the types.
  - `Model/Sequence.php:30-68`: typed properties `protected ?int $id = null;`, `protected string $type;`, `protected int $index = 0;` and matching return types. `@var mixed` on `$id` (`:30-33`) contradicts `getId(): ?int`.
  - `Factory/SequenceFactory.php:26-31`: `private readonly string $className` with `@param class-string<SequenceInterface> $className` (PHPStan-compatible instead of psalm-only `@psalm-param class-string`).
  - `Generator/SequenceGenerator.php:28-33`: `private readonly` promoted properties, or `final readonly class` (see **I-01** for BC).
  - Strict typing also protects the generator's `int` return (`SequenceGenerator.php:45`): `getIndex()` is untyped today, so a custom mapping with `bigint` (hydrated as `string` by DBAL, `vendor/doctrine/dbal/src/Types/BigIntType.php:46-49`) would only fail at runtime with a `TypeError`.

### I-08 — Use PHP 8.4 property hooks / asymmetric visibility in the model (optional)
- **Where:** `Model/Sequence.php`.
- **Proposal:** `public private(set) int $index = 0;` and `public private(set) string $type;` can replace the getter boilerplate while keeping writes inside the class. Only do this if the CoreShop resource model conventions (getters for serializer / Pimcore admin) allow it; keep the getters on the interface for BC.

### I-09 — `composer.json` does not describe what the component needs
- **Same as:** SequenceBundle TODO-3 **I-12** (component part).
- **Where:** `composer.json:26-56`.
- **Fix:**
  - Add `"php": "~8.4.0"` (same as the root `composer.json:88`).
  - Add `doctrine/orm` (or remove the dependency, **I-02**). Add `webmozart/assert` if **B-05** uses it.
  - Remove `"six-it/pimcore-source"`: nothing in the component uses Pimcore.
  - Align `require-dev` with the root (`phpstan/phpstan ^2.1`, `phpstan/phpstan-doctrine ^2.0`); drop `phpstan-webmozart-assert` unless `Webmozart\Assert` is used.
  - `"minimum-stability": "dev"` and the `path` repository (`:40-47`) have no effect for consumers; move them to the root. Fix the indentation of `"extra"` (`:48`).

## Quality / tests / docs

### I-10 — Tests
- **Same as:** SequenceBundle TODO-3 **I-07**.
- **Where:** no `Tests/` folder, no PHPUnit / Behat coverage for sequences.
- **Proposal:** unit tests for `SequenceFactory` (type set, invalid class rejected, `createNew()` behaviour), `Sequence` (`incrementIndex()`), the generator with mocked repository / EM (creates on first use, increments, rolls back on failure → TODO-1 **C-02**, input validation → TODO-2 **B-05**). An integration test on MySQL with two connections for TODO-1 **C-01** and TODO-2 **B-01**.

### I-11 — README has no component-specific content
- **Where:** `README.md`.
- **Proposal:** add a short usage section (inject `SequenceGeneratorInterface`, call `getNextSequenceForType('my_type')`), list the built-in types (`order`, `quote`, `invoice`, `shipment`), state that counters are global and may leave gaps, and link to `docs/03_Development/06_Order/13_Number_Generator.md`. Fix "It is build from" → "It is built from" and the `http://www.coreshop.org` link.
