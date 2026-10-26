# ConfigurationBundle – TODO 3: Improvements (code quality, readability, logic, PHP 8.4 / Symfony 7.4 idioms)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
None of these are defects on their own. They make the bundle cleaner, safer, easier to test, or more idiomatic for the target stack.
Paths are relative to the bundle root unless noted otherwise.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 language features

### I-01 — Native types on the model and its interface (`Component/Configuration/Model`)
- **Where:** `Component/Configuration/Model/Configuration.php`, `ConfigurationInterface.php`
- The properties are untyped (`protected $id; $key; $data;`), and the getters and setters rely on docblocks (`@return string`, `@param mixed`). Use `protected ?int $id = null; protected ?string $key = null; protected mixed $data = null;`, plus `getKey(): ?string`, `setKey(string $key): void`, `getData(): mixed`, `setData(mixed $data): void`.
- Optionally use PHP 8.4 asymmetric visibility for the identifier: `public protected(set) ?int $id = null;`. Doctrine hydrates through reflection, so this works.
- Drop the `@psalm-suppress MissingConstructor` once the properties have defaults.
- Changing the interface signatures is a BC break for implementors (CoreBundle `Configuration` extends the model, which is fine). Plan it for a minor version and note it in UPGRADE.

### I-02 — Add `#[\Override]` everywhere a parent or interface method is implemented
- **Where:** `CoreShopConfigurationBundle.php` (`getSupportedDrivers`, `getModelNamespace`), `DependencyInjection/Configuration.php` (`getConfigTreeBuilder`), `DependencyInjection/CoreShopConfigurationExtension.php` (`load`), `Form/Type/*` (`buildForm`, `getBlockPrefix`), `Doctrine/ORM/ConfigurationRepository.php` (`findByKey`), `Component/Configuration/Service/ConfigurationService.php` (`get`, `set`, `remove`)
- PHP 8.3+ then reports signature drift at compile time, for example after I-01 or I-09 changes the interfaces.

### I-03 — Modernise `Serialization/ObjectHandler`
- **Where:** `Serialization/ObjectHandler.php`, `Resources/config/services.yml:15-17`
- Make it `final`, and type the signature: `serializeRelation(JsonSerializationVisitor $visitor, mixed $value, array $type, Context $context): mixed`.
- Implement `JMS\Serializer\Handler\SubscribingHandlerInterface` (`getSubscribingMethods()`) instead of configuring the type, direction and format in the YAML tag, so the PHP class says what it handles.
- Rename the method (`serializeRelation` → `serializeData`) and the JMS type (`object` → `coreshop_configuration_data`). It does not serialise relations, and a global `object` handler reaches into other bundles (see B-09).

### I-04 — Use first-class constants and imports in the config tree
- **Where:** `DependencyInjection/Configuration.php:56-57`
- Import `CoreShop\Component\Configuration\Model\Configuration` / `ConfigurationInterface` (with aliases such as `ConfigurationModel`, because the class name clashes with `Configuration`) instead of inline FQCNs, as the other bundles do.
- `CoreShopConfigurationBundle::getModelNamespace()`: build the namespace from a class constant, e.g. `(new \ReflectionClass(ConfigurationModel::class))->getNamespaceName()`, or at least escape it consistently (`'CoreShop\\Component\\Configuration\\Model'`).

## Symfony 7.4 idioms / DI

### I-05 — Service definitions: use the resource's own manager, and keep only what needs to be public
- **Where:** `Resources/config/services.yml:5-13`, `Resources/config/services/form.yml:5-6`; **(dependent)** `CoreBundle/Resources/config/services.yml:51-57`
- `ConfigurationService` is wired with the hard-coded `@doctrine.orm.default_entity_manager`. Inject `@coreshop.manager.configuration`, which the resource driver registers (`ResourceBundle/DependencyInjection/Driver/Doctrine/DoctrineORMDriver.php:63,116`), so that a non-default object manager works.
- `_defaults: public: true` makes every service public. Only the `ConfigurationServiceInterface` alias (used by `ResourceController` service locators, Twig, Behat) needs to be public. Add `autowire: true` / `autoconfigure: true` so the form types and the JMS handler (I-03) do not need explicit tags.
- CoreBundle "decorates" the base service but never injects `.inner`. That is a replacement, not a decoration. Override the service ID or alias directly; it reads more honestly and avoids registering a dead inner service.
- Consider moving to PHP service config (`services.php`, `ContainerConfigurator`). It is the Symfony 7.x recommended format and is checked by static analysis.

### I-06 — Tidy the config tree
- **Where:** `DependencyInjection/Configuration.php:42-70`
- `->variableNode('options')` is never read. Remove it, or document what it is for.
- `form` lacks the `->cannotBeEmpty()` that its siblings have.
- Add the `permission` node (C-01) with `->cannotBeOverwritten()`, matching AddressBundle and CurrencyBundle.
- `addModelsSection` → `addResourcesSection`; the section is `resources`.

### I-07 — Controller: one implementation, input via a DTO, explicit authorisation
- **Where:** `Controller/ConfigurationController.php`; **(dependent)** `CoreBundle/Controller/ConfigurationController.php`
- The base `saveAllAction()` is dead and broken (B-03), and CoreBundle reimplements it completely. Remove the base action, or extract a shared `ConfigurationBulkWriter` service that both controllers call.
- Read the payload with `$request->request->getString('values')` + `json_decode(..., true, flags: JSON_THROW_ON_ERROR)` instead of the loosely typed `getParameterFromRequest()`.
- Use `#[IsGranted('coreshop_permission_settings')]` or `isGrantedOr403()` (C-01).
- Return `JsonResponse` (the return type of the parent resource actions) instead of the wider `Response`.

### I-08 — Allowlist the configuration keys the settings UI may write
- **Where:** `Controller/ConfigurationController.php:33-35`; **(dependent)** CoreBundle controller
- Today any key in the payload is persisted, so internal keys (`SYSTEM.PRICE_RULE.UPDATE`, `system.rule.availability_check.last_run`) can be overwritten from the UI.
- Keep a registry (tagged `ConfigurationKeyInterface` providers, or a config tree listing the key, type and constraints). It enables per-key validation and casting (int, bool, list), which also replaces the blanket `array_htmlspecialchars()` (B-02), and the admin UI can be generated from it.

## Logic / API design

### I-09 — Replace the boolean-flag API `get($key, bool $returnObject)` with two methods
- **Where:** `Component/Configuration/Service/ConfigurationServiceInterface.php:28`, `ConfigurationService.php:35-44`; **(dependent)** Core `getForStore(..., $returnObject)`
- The return type is `ConfigurationInterface|mixed|null`, so neither callers nor static analysis can tell which one they get. Split it into `get(string $key, mixed $default = null): mixed` and `find(string $key): ?ConfigurationInterface`.
- Then `set()` and `remove()` can use `find()` directly instead of `get($key, true)` + the loose `if (!$config)`.
- Add `has(string $key): bool` and a `$default` parameter so callers stop writing `?? 'fallback'`.

### I-10 — Cache reads
- **Where:** `Component/Configuration/Service/ConfigurationService.php:35-44`
- `RequestCartRecalculation` calls `get()` on every frontend request (one DB query each time). Settings change rarely. Put a Symfony Cache pool (or a per-request in-memory array) in front of `findByKey`, and invalidate it on `set()`/`remove()`. A tag-aware pool keyed by `key` + `store` works with the Core override.

### I-11 — Repository: simpler query, generics, deterministic result
- **Where:** `Doctrine/ORM/ConfigurationRepository.php:27-35`
- `return $this->findOneBy(['key' => $key]);` replaces the hand-built QueryBuilder. If C-02 is not fixed by a constraint, add `orderBy('o.id')` + `setMaxResults(1)`.
- Add `@extends EntityRepository<ConfigurationInterface>` / `@implements` generics for phpstan level ≥ 6.
- **(dependent)** In Core `ConfigurationService::getForStore()`, replace `findBy(['key'=>…, 'store'=>null])` + `is_array` + `[0]` with `findOneBy(...)`. `is_array` is always true for `findBy`.

### I-12 — **(dependent)** Core `ConfigurationService`: remove the `$myConfigurationRepository` duplicate property
- **Where:** `Component/Core/Configuration/ConfigurationService.php` (property + constructor)
- The same repository is stored twice, once as the base-typed `$configurationRepository` and once as `$myConfigurationRepository`, only to get the narrower type. Use a `@var` / `@property` override, or make the base class generic (`@template TRepo of ConfigurationRepositoryInterface`).
- `getForStore(string $key, ?StoreInterface $store = null, $returnObject = false)`: the implementation drops the `bool` type that the interface declares. Restore it.
- `setForStore(): \CoreShop\Component\Core\Model\ConfigurationInterface`: import the class with an alias instead of an inline FQCN.
- `setForStore()` calls `setStore($store)` twice (once in the create branch, once after). One call is enough.

### I-13 — Remove the unused `ConfigurationCollectionType`, or wire it in
- **Where:** `Form/Type/ConfigurationCollectionType.php`, `Resources/config/services/form.yml:15-17`
- Nothing in the codebase references it: no controller, JS, or Twig template. It has no `data_class` / `configureOptions()`, so it could not be bound to anything meaningful. Remove it together with its service definition. If it is meant as the bulk-save form (I-07), finish it instead.

## Persistence

### I-14 — Doctrine mapping clean-up
- **Where:** `Resources/config/doctrine/model/Configuration.orm.xml`
- `<generator strategy="AUTO"/>` → explicit `IDENTITY` (forward-compatible with ORM 3 / DBAL 4 on PostgreSQL, consistent with the AddressBundle TODO).
- Add `<indexes>` / `<unique-constraints>` for `configKey` (see C-02). In CoreBundle's mapping the constraint must be on `(configKey, store_id)`.
- `creationDate` / `modificationDate`: consider `datetime_immutable`. That needs `TimestampableTrait` to accept `\DateTimeInterface`, a shared change across resources.
- Remove the unused `xmlns:gedmo` prefix only if timestampable moves to attributes; otherwise keep it.
- Ship a Doctrine migration for these changes. The bundle has no migrations, so schema changes rely on `doctrine:schema:update`.

## Tooling, docs, tests

### I-15 — Tests
- The bundle and the component have no tests. Minimum set:
  - unit: `ConfigurationService::get/set/remove` (new key, existing key, missing key), with a mocked repository;
  - functional: `saveAllAction` with a JSON string payload, numeric keys (B-04), special characters round-trip (B-02), permission denied (C-01);
  - integration (Core): multi-store `get` vs `getForStore` fallback (B-05, B-07), duplicate-key race (C-02).

### I-16 — `composer.json` hygiene
- **Where:** `composer.json`
- `branch-alias dev-master: 4.1-dev` and `minimum-stability: dev` are leftovers from upstream CoreShop. Align them with the fork's versioning.
- The `repositories` path entry `../../*/*` is only useful for standalone development of the monorepo split. Document it, or remove it from published packages.
- See B-10 for the missing `require` entries.

### I-17 — README / docs
- **Where:** `README.md`
- The README is the generic CoreShop boilerplate (with a typo, "It is build from"), and there is no `docs/03_Bundles/Configuration_Bundle.md`. Document:
  - what the bundle provides (a key/value store, `ConfigurationServiceInterface`);
  - the global vs per-store semantics once CoreBundle is installed, which keys are internal, and the escaping and serialisation format after B-01 and B-02.
