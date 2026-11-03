# Resource Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items. None of them is a functional bug on its own.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Prefixes and markers as in TODO-1; ResourceBundle items are referenced as ResourceBundle **C-/B-/I-nn**.

**Already fine for Symfony 7.4 / PHP 8.4:** every file has `declare(strict_types=1)`; no implicitly nullable parameters; the resolvers already use constructor property promotion; `UnexpectedTypeException` already uses `get_debug_type()`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

# Scan 1 (2026-10-29): `DataHub/`, `Doctrine/`, `Exception/`

## Package / architecture

### CI-01 — `composer.json` does not describe what this code needs
- **Where:** `composer.json:26-39`.
- **Fix:**
  - Add `"php": "^8.4"`.
  - Undeclared direct dependencies of the scanned folders: `doctrine/dbal` (`Doctrine/Type/*`), `doctrine/persistence`, `symfony/property-access` (`DataHub/Resolver/*`), `webonyx/graphql-php` and `pimcore/data-hub` (`DataHub/**`). The last two are optional → `suggest` + document that `DataHub/**` requires them (or move it, CI-02).
  - `doctrine/orm: ^2.9` → `^2.20` (what is installed, and the first version with all ORM-3 forward-compat APIs used in CI-08); `doctrine/common` is not used by the scanned folders (check the rest of the component before removing).
  - `require-dev` PHPStan 1.x → 2.x together with the other components; `minimum-stability` / `repositories` belong to the monorepo root.

### CI-02 — Move `DataHub/**` out of the component (and remove the component → bundle dependency)
- **Where:** `DataHub/DoctrineProvider.php:21,95,405` uses `CoreShop\Bundle\ResourceBundle\CoreShopResourceBundle::DRIVER_DOCTRINE_ORM`; `DataHub/QueryType/*` extend `Pimcore\Bundle\DataHubBundle\...` classes.
- **Problem:** a component must not depend on a bundle; the whole folder is Pimcore-DataHub integration, which is bundle territory (ResourceBundle already has its own `DataHub/` folder — ResourceBundle **I-29**).
- **Fix:** move `DataHub/**` to `Bundle/ResourceBundle/DataHub/` (or a dedicated `coreshop/data-hub-bridge` package), keep class aliases for one minor version; move the driver constant to a component-level `enum ResourceDriver: string` (or `Metadata` constant).

### CI-03 — Delete dead code
- `DataHub/GraphHydrator.php` — not used anywhere; references `\Doctrine\Common\Persistence\ObjectManagerAware` (`:83`), a class that no longer exists in doctrine/persistence 3 (now `Doctrine\Persistence\ObjectManagerAware`); uses ORM internals (`getProxyFactory()->getProxy()`, `reflFields`, `UnitOfWork::registerManaged()`) that change in ORM 3.
- `DataHub/GraphEntity.php` — only consumer is the broken `resolveType` (TODO-2 **CB-06**).
- `DataHub/GraphPageInfo.php`: `$cursor` (`:39`, unless used for CB-11), `getFilters()` (`:68-75`, unused).
- `DataHub/GraphSortField.php`: public `$field` / `$order` properties (`:38-40`, never instantiated).
- `DataHub/DoctrineProvider.php`: `$filterFields` (`:163-166`, written, never read), `$class` (`:129`, captured in the closure `:305` but unused), the JSON filter branch (`:463-476`, CB-16), the `PHPStan` import and boolean block (CB-15), `case 'json_array'` (`:372`, type removed in DBAL 3), `getTypes()` / `getGraphQlQueries()` if the project decides not to offer the generic list query (TODO-1 **CC-01/02**).
- `DataHub/Type/JsonType.php` or `ArrayType.php` (keep one, CB-07).
- Commented code `DoctrineProvider.php:473`.

### CI-04 — Split `DoctrineProvider` (646 lines, 5 responsibilities)
- **Where:** `DataHub/DoctrineProvider.php`.
- **Problem:** one class builds output types, input types, filter types, list queries, executes the query and applies filters/pagination; the 120-line resolver closure (`:445-581`) with nested `if/elseif` is untestable.
- **Fix:**
  - `TypeRegistry` (owns `$types`, scalar types, name ↔ class maps).
  - `EntityTypeBuilder` (output/interface/input/query-filter types per entity).
  - `QueryFilterApplier` with one small strategy per filter type (`StringFilter`, `NumberFilter`, `DateTimeFilter`, `AssociationFilter`), each using an operator map, e.g. `private const OPERATORS = ['greater' => '>', 'less' => '<', 'greaterOrEquals' => '>=', 'lessOrEquals' => '<=', 'equals' => '=']` and generated parameter names (fixes TODO-2 **CB-12**, **CB-13**).
  - `Paginator` (replaces the static `GraphPageInfo::paginateQuery/sortQuery/addAfter/generateQuery`, CC-01/02).
  - `ListQueryFactory` (`getGraphQlQuery()`).

### CI-05 — Build the schema lazily, not in the service constructor
- **Where:** `DoctrineProvider.php:75-87` builds every type and calls `EntityManager::getClassMetadata()` for every exposed resource at instantiation. DataHub instantiates all tagged query-type generators per GraphQL request, so this runs on every request even if no CoreShop field is queried.
- **Fix:** lazy `getGraphQlType()` (build on first use + memoize), `#[Lazy]`/`lazy: true` on the service, and/or cache the computed name maps per container build (they only depend on configuration). Inject `PropertyAccessorInterface` (`property_accessor` service, cached) instead of `new PropertyAccessor()` on **every field resolution** (`DataHub/Resolver/*.php:49,55,56`).

### CI-06 — Native types, `final`, readonly / PHP 8.4 asymmetric visibility
- **Where:** almost every method in `DataHub/**` is untyped (`getType($typeName)`, `getTypeName($className)`, `storeTypeName($className, $name)`, `getRepository($class)`, `addType($typeName, $type)`, `getInputTypeKey`, `getTypeIdentifiers`, `getTypeClass`, filter `getType($dataType)`, `GraphResultList::getType(string $name, $listType, $pageInfoType)` …); `private $niceNameMap` (`DoctrineProvider.php:73`) has no type; `GraphHydrator::$em` public and untyped; `QueryType/Resource.php:28-41` assigns properties manually.
- **Fix:**
  - Add parameter/return types (`?Type`, `ObjectType`, `InputObjectType`, `EntityRepository`, `list<string>` docblocks for arrays); `array<string, Type>` generics in PHPDoc.
  - `final` on every class not designed for extension (filters, scalar types, resolvers, DTOs); `readonly` promoted constructor properties in resolvers and `QueryType/Resource`.
  - `GraphResultList` / `GraphPageInfo` as DTOs using PHP 8.4 asymmetric visibility: `public private(set) array $items`, `public private(set) int $totalCount`, `public private(set) bool $hasMore` — fixes TODO-2 **CB-14** at the same time.
  - `static` filter factories → constants for names (`public const string NAME = ...`, PHP 8.3 typed class constants).

### CI-07 — One explicit Doctrine → GraphQL type map
- **Where:** `DoctrineProvider.php:360-381` (`switch` on string literals, fallback to `String`), plus the `instanceof` chain `:169-194` that decides filter types.
- **Fix:** a single `match ($doctrineType)` on `Doctrine\DBAL\Types\Types::*` constants returning `?Type` (null = not exposed), and a second map `GraphQL type → filter type`. Covers TODO-2 **CB-01**, **CB-02**, **CB-03**. Make it extensible (tagged `TypeMapperInterface` services) so projects can map their own DBAL types (`pimcoreObject` → Pimcore DataHub object type, `bigintInteger` → `BigInt`).

### CI-08 — Doctrine ORM 3 forward-compatibility
- **Where:** `DoctrineProvider.php:36,124,217,270,282,307,329,336` (`ClassMetadataInfo` type + constants), association arrays (`$association['fieldName']`, `['targetEntity']`, `['type']` at `:205-206,217,270-272,282-284,307-309`), `discriminatorColumn['fieldName']` / `discriminatorMap` (`:237-239`), `subClasses` / `parentClasses` (`:253,392,610`); `GraphHydrator.php` (CI-03).
- **Problem:** `ClassMetadataInfo` is deprecated in ORM 2.x and removed in ORM 3; in ORM 3 associations are `AssociationMapping` objects (array access is deprecated), `discriminatorColumn` is a `DiscriminatorColumnMapping`.
- **Fix:** type against `Doctrine\ORM\Mapping\ClassMetadata`; use `ClassMetadata::ONE_TO_ONE` etc. or better `$metadata->isSingleValuedAssociation($field)` / `isCollectionValuedAssociation($field)` and `getAssociationTargetClass($field)`, `getAssociationNames()` — these work on ORM 2.20 **and** 3.x.

### CI-09 — Doctrine DBAL 4 forward-compatibility and de-duplication of the custom types
- **Where:** `Doctrine/Type/*.php`; registered in `Bundle/ResourceBundle/Resources/config/pimcore/config.yml:30-41`.
- **Problem:** the four Pimcore types are copies of each other (only the element class and constant differ). In DBAL 4: `getBindingType()` must return the `ParameterType` **enum** (the current `: int` return type is a fatal signature error there), `getName()` and `requiresSQLCommentHint()` are removed (both already `@deprecated` in the installed DBAL 3.10.6, `vendor/doctrine/dbal/src/Types/Type.php:101-108,276-298`), parameters are `mixed`.
- **Fix:**
  - `abstract class AbstractPimcoreElementType extends Type` with `abstract protected function loadElement(int $id): ?ElementInterface` and `abstract protected function elementClass(): string`; four `final` one-method subclasses.
  - Signatures: `convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Asset`, `convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int` (with TODO-2 **CB-20**).
  - Drop `getBindingType()` overrides where the parent default fits (`IntegerType` already binds `INTEGER`) — simplest: `extends IntegerType` / `extends BigIntType` instead of `Type`.
  - Keep `getName()` only while DBAL 3 is supported; remove `requiresSQLCommentHint()` (schema comparison in DBAL 3.10 with DoctrineBundle 2.19 no longer relies on comments; verify with `doctrine:schema:validate` / `doctrine:migrations:diff` that no diff appears).
  - Pimcore element ids are `INT UNSIGNED`; declare `'unsigned' => true` in the column options or merge it in `getSQLDeclaration()` to match.
  - Move the type names (`'pimcoreObject'`, …) into a shared constants class/enum used by `config.yml` (`!php/const`), the ORM XML mappings and the serializer handlers (ResourceBundle TODO-3 references the same strings).

### CI-10 — `BigIntIntegerType` is incomplete and its intent is undocumented
- **Where:** `Doctrine/Type/BigIntIntegerType.php`.
- **Problem:** binds as `STRING` (`:39-42`) but returns `int` from the DB (`:44-47`) and has no `convertToDatabaseValue()` (an `int` is passed through untouched, a non-numeric string reaches the DB). The difference to DBAL's own `bigint` (which returns `string`, or `int` since DBAL 4) is not documented.
- **Fix:** `final class BigIntIntegerType extends BigIntType` with `convertToPHPValue(): ?int` and a validating `convertToDatabaseValue()`; docblock "BIGINT column, always hydrated as PHP int (amounts in minor units)"; on DBAL 4 consider replacing it with the native `bigint` type.

### CI-11 — Exceptions: types, base classes, a marker interface
- **Where:** `Exception/*.php`.
- **Fix:**
  - Native types on constructors: `ImplementedByPimcoreException(string $class, string $method)` (the parameter is named `$property` but all callers pass `__METHOD__`; the message "%s of \"%s\"" therefore prints the class twice — use `sprintf('Method "%s" must be implemented by the Pimcore model.', $method)`), `UnexpectedTypeException(mixed $value, string $expectedType)`, `UnsupportedMethodException(string $methodName)`; add `?\Throwable $previous = null`.
  - Semantically correct parents: `ImplementedByPimcoreException` → `\LogicException` / `\BadMethodCallException` (it is not an invalid argument); `UnsupportedMethodException` → `\BadMethodCallException` (currently a bare checked `\Exception`). Check callers' `catch` blocks first (none found for these in `src/`).
  - `interface ExceptionInterface extends \Throwable` implemented by all four, so callers can catch "any Resource component exception".
  - `final` on all four; `NiceValidationException` gets a docblock explaining that `__toString()` intentionally prints only the message (used as sub-item by `VariantBundle/EventListener/{ProductListener,AttributeListener}.php`, where the parent `ValidationException::__toString()` concatenates sub-items, `vendor/six-it/pimcore-source/models/Element/ValidationException.php`).
  - `UnexpectedTypeException` duplicates Symfony's `Validator\Exception\UnexpectedTypeException`; the bundle validators already use Symfony's. Keep this one for non-validator code only and say so in a docblock.

### CI-12 — GraphQL schema polish
- **Where:** `DataHub/**` type definitions.
- **Fix:**
  - Identifiers as `Type::nonNull(Type::id())`; lists as `listOf(nonNull(...))`.
  - Sort: generated `EnumType` per resource + `SortOrder` enum (backed by a PHP native `enum SortOrder: string`) — see TODO-1 **CC-02**.
  - Prefix the generic scalar names (`array`, `json`, `bigint`, `datetime`) with `CoreShop` — generic lower-case names risk "Schema must contain unique named types" collisions with other DataHub type providers.
  - One list shape for top-level and nested lists (Relay-like `{items, totalCount, pageInfo{hasMore,endCursor}}`) — TODO-2 **CB-04**, **CB-11**.
  - Field descriptions from the Doctrine metadata / translations; deprecation reasons instead of silent field removal.

### CI-13 — Static scalar-type cache in `DoctrineProvider`
- **Where:** `DoctrineProvider.php:52-53,348-358` (`private static ?array $standardTypes`).
- **Problem:** a process-wide static shared by all provider instances (tests, kernel reboots in workers); `getStandardType($name = null)` returns either one type or the whole array.
- **Fix:** instance property in the `TypeRegistry` (CI-04); two explicit methods (or a `match`), no mixed return.

### CI-14 — Error handling: throw instead of undefined-key warnings and `null`
- **Where:** `DoctrineProvider.php:383-388` (`getTypeName`), `:632-635` (`getInputTypeKey`), `:637-640` (`getTypeIdentifiers`), `:642-645` (`getTypeClass`), `:585-620` (`get*Type` returning `null`).
- **Fix:** `?? throw new \LogicException(sprintf('Doctrine class "%s" is not exposed to GraphQL.', $className))`; keep `?Type` returns only where `null` is a legitimate answer and document it. Makes TODO-1 **CC-03** and TODO-2 **CB-05** fail with a clear message.

### CI-15 — Doctrine element types load every element eagerly on hydration
- **Where:** `Doctrine/Type/Pimcore*.php::convertToPHPValue()`.
- **Problem:** hydrating N `ProductStoreValues` / `Payment` rows calls `AbstractObject::getById()` N times (Pimcore's runtime cache helps only for repeats), even when the element is never accessed.
- **Fix (option):** keep the id in the entity and resolve the element lazily in the getter (or via a PHP 8.4 native lazy ghost, `ReflectionClass::newLazyProxy()`), or batch-load via a postLoad listener. Measure first; document the current behaviour.

### CI-16 — Readability
- **Where / Fix:**
  - The nice-name formula `ucfirst($app) . ucfirst(str_replace('_', '', ucwords($name, '_')))` is duplicated (`DoctrineProvider.php:113`, `:410`) → one `niceName(MetadataInterface): string` method; same for the "is graphql-enabled Doctrine resource" filter (`:94-109` vs `:404-411`, TODO-1 **CC-03**).
  - `$className` is overwritten with the same value inside the loop (`:111-112`).
  - `!(count($x) === 0)` → `$x !== []` (`:392`, `:610`); `strpos(...) === 0` → `str_starts_with()` (`:516`).
  - `if (count($interfaces) > 0)` after unconditionally appending (`:259`) is always true.
  - `$inputFields[$fieldName]` is assigned twice per field (`:158-161` and `:197-200`).
  - Docblock `@var MetadataInterface` inside the loop (`:91-93`) → typed generic on `RegistryInterface::getAll()`.
  - Webonyx field arrays: use the `name => config` map form consistently (some builders use lists, some maps).

### CI-17 — Tests
- **Where:** no tests exist for `DataHub/**`, `Doctrine/Type/*` or `Exception/*`.
- **Fix:**
  - PHPUnit for each DBAL type (round-trip, `null`, id, unsaved element, wrong type — TODO-2 **CB-20**) using a stubbed Pimcore element loader (CI-09's abstract method makes this possible without a DB).
  - Schema test: build all types for the default CoreShop resources with a test EntityManager and run `$schema->assertValid()` (catches TODO-1 **CC-03**, TODO-2 **CB-02**, **CB-04**, **CB-05**).
  - Query-builder tests for the filter applier and paginator: generated DQL + parameters, injection payloads (TODO-1 **CC-01**, **CC-02**), parameter collisions (TODO-2 **CB-12**).
  - Add `webonyx/graphql-php` to the monorepo `require-dev` so DataHub code is at least autoloadable in CI.

---

# Scan 2 (2026-10-29): `Factory/`, `Metadata/`, `Model/`

Scope and followed callers: see TODO-1, "Scope (scan 2)". Many interfaces here (`FactoryInterface`, `MetadataInterface`, `RegistryInterface`, `TimestampableInterface`, `ToggleableInterface`, `ImmutableInterface`, `TranslationInterface`) are implemented across all components and by projects: adding native return types is a **BC break** for implementors. Plan them for the next major and use `@return` PHPDoc + PHPStan in the meantime; adding native **parameter** types to classes that only implement (not define) the interface is safe.

## Factory

### CI-18 — Typed, generic factories; merge the identical `Factory` and `PimcoreFactory`
- **Where:** `Factory/*.php`.
- **Fix:**
  - `FactoryInterface` (`:21-27`): `@template T of object` + `@return T` now; `createNew(): object` in the next major. Same for `RepositoryFactoryInterface`.
  - `Factory` and `PimcoreFactory` (`Factory/Factory.php`, `Factory/PimcoreFactory.php`) are byte-for-byte the same apart from the marker interface → one class (`PimcoreFactory extends`/aliases it), `PimcoreFactory implements FactoryInterface, PimcoreFactoryInterface` lists `FactoryInterface` redundantly (`:21`).
  - `private readonly string $className` + `Assert::classExists()` in the constructor (fail at container build instead of at `createNew()`); `@psalm-param class-string` → `@param class-string<T>`.
  - `RepositoryFactory` / `PimcoreRepositoryFactory`: `final`, readonly promoted properties; `PimcoreRepositoryFactory::createNewRepository()` ignores its `ObjectManager` argument (`:38-41`) — document why (Pimcore repositories use DBAL + metadata).
  - `PimcoreFactoryInterface` / `TranslatableFactoryInterface` are empty marker interfaces; `TranslatableFactoryInterface` is used for detection in `ResourceBundle/DependencyInjection/Driver/AbstractDriver.php:106` — keep, but add a docblock saying so (or replace with a service tag / PHP attribute).

## Metadata

### CI-19 — Make `Metadata` a typed, readonly value object
- **Where:** `Metadata/Metadata.php`, `Metadata/MetadataInterface.php`.
- **Fix:**
  - `final readonly class` style (or PHP 8.4 `public private(set)` properties): `$driver`, `$templatesNamespace`, `$parameters` never change after construction.
  - Interface types: `getParameter(string $name): mixed`, `getClass(string $name): string` (`@return class-string`), `getTemplatesNamespace(): ?string` (TODO-2 **CB-24**); class: `getPermissionCode(string $permissionName)` (`:124` is untyped), `parseAlias(string $alias)` (`:132`).
  - `getPluralName()` builds a new Doctrine inflector on every call (`:69-74`) → static/lazy instance.
  - `preg_replace()` in `getHumanizedName()` (`:66`) can return `null` → `strtolower(null)` deprecation; use `?? ''` or `u($name)->snake()->replace('_', ' ')` (symfony/string).
  - Typed accessors for frequently read parameters instead of `getParameter('graphql')['enabled']` array digging: `isGraphQlEnabled(): bool` (TODO-2 **CB-17**), `getDriver()` as a backed `enum ResourceDriver: string` (TODO-3 **CI-02**).
  - Document the expected `$parameters` shape with a PHPStan `array{driver: string, classes: array{model: class-string, ...}, ...}` type.

### CI-20 — `Registry`: index by class, typed API
- **Where:** `Metadata/Registry.php`, `Metadata/RegistryInterface.php`.
- **Fix:** keep a second map `array<class-string, MetadataInterface>` filled in `add()` → `getByClass()` / `hasClass()` become O(1) instead of a linear scan on every call (they run per Doctrine `loadClassMetadata` event, per link/slug generation); native parameter types (`get(string $alias)`, `getByClass(string $className)`, `hasClass(string $className)`, `addFromAliasAndConfiguration(string $alias, array $configuration)`); `@return array<string, MetadataInterface>` on `getAll()`; reject duplicate aliases/classes in `add()`; proxy/subclass resolution from TODO-2 **CB-26** in one private `resolveClass()`.

## Model

### CI-21 — `SetValuesTrait`: restrict what can be set
- **Where:** `Model/SetValuesTrait.php`; callers `ResourceBundle/Controller/ResourceController.php:193-195`, `OrderBundle/Controller/{OrderInvoiceController.php:135-142,OrderShipmentController.php:138}`.
- **Problem:** any `set*()` method of the resource can be called with request data (mass assignment — ResourceBundle **B-07**): `setId`, `setCurrentLocale`, `setTranslatable`, `setImmutable`, …; wrong value types end as `TypeError` 500s.
- **Fix:** the controllers should use the resource form types; component side, give `setValues()` an allow-list (`protected static function settableFields(): array` or a `#[Settable]` attribute) and use `PropertyAccessorInterface::isWritable()`; `str_starts_with()` / `ucfirst()` instead of the regex; remove the `o_` branch (TODO-2 **CB-23**). Consider splitting `setValues()/setValue()` out of `ResourceInterface` into a `ValuesAwareInterface` — a resource identity interface should not force a mass-setter on every model.

### CI-22 — `TranslatableTrait`: read/write separation, clone support, typed collection
- **Where:** `Model/TranslatableTrait.php`, `Model/TranslatableInterface.php`.
- **Fix:**
  - `getTranslation()` without side effects + `getOrCreateTranslation()` (TODO-2 **CB-27**); remove or harden the cache (TODO-2 **CB-29**); add `__clone()` support (TODO-2 **CB-31**).
  - `$currentTranslation` (`:41-44`) is never used → delete (its "Doctrine 2.4+" comment is obsolete).
  - The trait's constructor (`:48-51`, oddly formatted `__construct(\n ) {`) is aliased by its users under **two** names (`TranslatableTrait::__construct as private initializeTranslationsCollection` in `TaxRate`, `ProductUnit`, `State`, `Country`; `initializeTranslationCollection as private initializeTranslationsCollection` in `CartPriceRule`, `PaymentProviderRule`, `AbstractPriceRule`). Remove the trait constructor and keep one documented `protected function initializeTranslationsCollection(): void`.
  - Typed property `protected Collection $translations` with `@var Collection<string, TranslationInterface>`; `@return Collection<string, TranslationInterface>` on `getTranslations()` — the current docblock says `Collection|TranslatableInterface[]` (`TranslatableInterface.php:26`), i.e. the wrong interface.
  - The trait passes `$this` to `TranslationInterface::setTranslatable(?TranslatableInterface)`, so it only works in classes implementing `TranslatableInterface` — declare that with `@phpstan-require-implements TranslatableInterface` (PHPStan 2).
  - `$locale ?: $this->currentLocale` (`:60`) treats `'0'`/`''` as "no locale" — use `??` and validate non-empty.
  - Remove the `Doctrine\ORM\PersistentCollection` import (`:23`, only used in a docblock) so the trait does not reference the ORM.

### CI-23 — `AbstractTranslation`: abstract, typed
- **Where:** `Model/AbstractTranslation.php`, `Model/TranslationInterface.php`.
- **Fix:** declare it `abstract` (the name says so, and it is only extended — e.g. `Component/Order/Model/CartPriceRuleTranslation.php:27`); `getTranslatable(): ?TranslatableInterface`, `getLocale(): ?string`, `setLocale(string): void`, `setTranslatable(...): void` (interface docblocks claim non-null returns — fix them now); remove `@psalm-suppress MissingConstructor` once `$locale` has a default (TODO-2 **CB-30**).

### CI-24 — Small model interfaces/traits: native types, immutable dates, two-state booleans
- **Where:** `Model/{Toggleable,Immutable,Timestampable}{Interface,Trait}.php`.
- **Fix:**
  - Traits: typed properties (`protected bool $active = false;`, `protected bool $immutable = false;`, `protected ?\DateTimeInterface $creationDate = null;`) — safe for Doctrine (it hydrates via reflection) but check every `*.orm.xml` / Pimcore class definition that maps these fields allows the declared nullability.
  - `?bool` getters/setters on fields that default to `false` create a third "null" state nobody handles (`ToggleableTrait.php:28-36`, `ImmutableTrait.php:28-36`); `ImmutableInterface` already has `isImmutable(): bool` — add `isActive(): bool` and deprecate the nullable getters.
  - `ToggleableInterface::activate()` / `disable()` naming is asymmetric → `enable()`/`disable()` (keep `activate()` as deprecated alias); all setters `: void` (next major).
  - `TimestampableInterface`: `\DateTimeInterface` in parameters now (widening is safe for the trait, a BC break only for other implementors), `?\DateTimeInterface` returns in the next major (TODO-2 **CB-32**); consider `datetime_immutable` mappings.

### CI-25 — Tests for scan-2 classes
- **Where:** no unit tests for `Factory/*`, `Metadata/*`, `Model/*` in the component.
- **Fix:** PHPUnit tests that pin the behaviour reproduced in TODO-2 **CB-23** … **CB-31** (setValue with `o_` keys, alias parsing, missing driver/templates, registry with proxy subclasses, translation read without side effect, duplicate-locale add, cache vs. collection removal, translation without locale, clone isolation). They need no kernel: `doctrine/collections` and `doctrine/inflector` are enough (add them to `composer.json` — `Model/TranslatableTrait.php` and `Metadata/Metadata.php` use them but they are not declared; extends TODO-3 **CI-01**).

---

# Scan 3 (2026-10-29): `Pimcore/`, `Reflection/`, `Repository/`, `Service/`, `Storage/`, `TokenGenerator/`, `Translation/`

Scope and followed callers: see TODO-1, "Scope (scan 3)".

## Reflection

### CI-26 — Replace the regex class scanner with a tokenizer / Composer's class-map generator
- **Where:** `Reflection/ClassReflection.php`.
- **Fix:**
  - `getResourcesByPath()`: `Composer\ClassMapGenerator\ClassMapGenerator::createMap($path)` (handles namespaces, enums, interfaces, traits, comments, heredocs) or a small `PhpToken::tokenize()` loop (TODO-1 **CC-05**). Only yield `class_exists($name)` (not interfaces/traits/enums) and non-abstract classes, since `#[AsPimcoreModel]` only makes sense there.
  - Return types: `@return iterable<class-string>` / `list<class-string>`; `getClassAttributes()` → `@template T of object`, `@param class-string<T>|null`, `@return list<\ReflectionAttribute<T>>`, and pass `\ReflectionAttribute::IS_INSTANCEOF` so subclasses of the attribute are found.
  - Register the scanned directories as container resources (`$container->addResource(new DirectoryResource($path, '/\.php$/'))` in the extension, dependent) so adding a model invalidates the cache in dev.
  - The silent `is_dir()` skip (`:34-37`) hides typos in `coreshop_resource.mapping.paths`; log or fail for non-default paths.

## Repository

### CI-27 — Generic, typed repository interfaces; merge the two Pimcore interfaces
- **Where:** `Repository/{RepositoryInterface,PimcoreRepositoryInterface,PimcoreDaoRepositoryInterface}.php`.
- **Fix:**
  - `@template T of ResourceInterface` + `@extends ObjectRepository<T>` on `RepositoryInterface`; `add(T)`, `remove(T)` in PHPDoc.
  - `getList()` / `forceFind($id, bool $force = true)` are untyped in both Pimcore interfaces (`:23-25`, `:25-27`): `getList(): Listing` (resp. the DAO listing), `forceFind(int|string $id, bool $force = true): ?object` — next major for the return types (implemented by every Pimcore repository).
  - `PimcoreDaoRepositoryInterface` is `PimcoreRepositoryInterface` minus `getClassId()`; extract the shared part into one parent and let `PimcoreRepositoryInterface` add `getClassId()`.
  - `ORDER_ASCENDING` / `ORDER_DESCENDING` (`RepositoryInterface.php:26-28`): only two usages in `src/` — replace by a backed `enum SortDirection: string` (shared with TODO-1 **CC-02**'s `SortOrder`), keep the constants as deprecated aliases.

## Pimcore

### CI-28 — Pimcore model base classes: remove duplication, align with Pimcore signatures
- **Where:** `Pimcore/Model/{AbstractPimcoreModel,PimcoreModelInterface,AbstractPimcoreFieldcollection}.php`, `Model/AbstractObject.php`.
- **Fix:**
  - `Model/AbstractObject` (referenced in 5 files) and `Pimcore/Model/AbstractPimcoreModel` (referenced in 22 files) both are `abstract class … extends Concrete implements ResourceInterface` — deprecate `Model\AbstractObject` in favour of `AbstractPimcoreModel` (which also carries `PimcoreModelInterface`).
  - `PimcoreModelInterface` re-declares methods that `ElementInterface` / `Concrete` already define (`getId`, `getKey`, `setKey`, `getParent`, `setParent`, `save`, `delete`, `getPublished`, …). Keep only what CoreShop needs beyond `ElementInterface`, so Pimcore signature changes do not have to be mirrored.
  - `getChildren(array $type = [], bool $includingUnpublished = false)` (`:50`) differs from Pimcore (`AbstractObject::getChildren(array $objectTypes = [object, variant, folder], …)`, `vendor/six-it/pimcore-source/models/DataObject/AbstractObject.php:327-333`) in parameter name and default; named-argument calls and static analysis disagree. Align or drop.
  - `AbstractPimcoreFieldcollection` is an empty extension point — add a docblock (what generated field-collection classes use it).

### CI-29 — `FolderCreationService`: strict options, sanitised segments, `final`
- **Where:** `Service/FolderCreationService.php`, `Service/FolderCreationServiceInterface.php`.
- **Fix:** see TODO-2 **CB-33**; additionally: configure the `OptionsResolver` once (constructor or `configureOptions()`), `setAllowedTypes()` for `prefix`/`suffix`/`path`; `final` + `private readonly` promoted properties (currently `protected`, class non-final); typed options with a PHPStan `array{prefix?: string|null, suffix?: string|null, path?: string|null}` shape on the interface; a small `Path::join(...$segments)` helper instead of the `.= '/'` string surgery (`:46-52`). `symfony/options-resolver` is used but not declared in `composer.json` (extends **CI-01**).

### CI-30 — `ResourceDocumentTagFactory`, `ResourceSlugLinkGenerator`: `final`, readonly, explicit construction
- **Where:** `Pimcore/ResourceDocumentTagFactory.php`, `Pimcore/ResourceSlugLinkGenerator.php`.
- **Fix:** both `final` with `private readonly` promoted properties; `@param class-string<EditableInterface> $class` + assertion; explicit constructor call (TODO-2 **CB-36**); typed metadata accessor for the `slug` parameter (`isSluggable(): bool`, compare **CI-19**).

## Translation

### CI-31 — `UniqueTokenGenerator`: PHP 8.3 `Randomizer`, an interface, an honest name
- **Where:** `TokenGenerator/UniqueTokenGenerator.php`.
- **Fix:**
  - Body → `(new \Random\Randomizer())->getBytesFromString($this->keys, $length)` (PHP 8.3+, CSPRNG-backed, no manual index loop); alphabet as class constants (`private const string ALPHANUMERIC = 'abc…XYZ0123456789'`, `DIGITS = '0123456789'`) instead of `implode(range())` in the constructor; `readonly` properties.
  - The class guarantees *randomness*, not *uniqueness*: rename to `RandomTokenGenerator` (keep the old name as a deprecated alias) and document the collision space (`62^32` for order/storage-list tokens, `10^15` for payment numbers — `OrderPaymentProvider.php:41-42`, `OrderPaymentController.php:100-101`; callers that need uniqueness must check the DB).
  - Add `TokenGeneratorInterface` so callers stop doing `new UniqueTokenGenerator()` inline (`CheckoutController.php:213`, `StorageListFactory.php:48`, `OrderPaymentProvider.php:41`, `OrderPaymentController.php:100`) and the generator can be replaced in tests (dependent).
  - Length validation from TODO-2 **CB-37**. Update the vendored copy in the reference project (still `openssl_random_pseudo_bytes()`).

### CI-32 — Translation services: one assigner, injectable locale source
- **Where:** `Translation/TranslatableEntityLocaleAssigner.php`, `Translation/Provider/{PimcoreTranslationLocaleProvider,TranslationLocaleProviderInterface}.php`.
- **Fix:**
  - After TODO-1 **CC-04**, delete the duplicate `Component/Core/Translation/TranslatableEntityPimcoreLocaleAssigner.php` (LocaleBundle **I-19**).
  - `PimcoreTranslationLocaleProvider` calls static `Pimcore\Tool` — inject the system-settings config (or a small `PimcoreLanguageProvider` service) so it is unit-testable and cacheable per request; `@return list<string>` on `getDefinedLocalesCodes()` (and fix the typo-ish name `LocalesCodes` → `LocaleCodes` with a deprecated alias).
  - The assigner's `catch (LocaleNotFoundException)` is dead in practice (Locale component TODO-2 explains why); keep it, but log at debug level so a missing locale context is visible.

## Storage / package

### CI-33 — `StorageInterface` and README
- **Where:** `Storage/StorageInterface.php` (implemented by `CoreBundle/Storage/{CookieStorage,SessionStorage}.php`, `Component/Core/Currency/CurrencyStorage.php`), `README.md`.
- **Fix:** `@template TValue` generics on `get()`/`set()`/`all()` (`@return array<string, TValue>`); document whether `get()` on a missing key must return `$default` without side effects. README: describe what the component contains (resources, metadata, factories, translations, Pimcore glue, DataHub) and its optional dependencies (**CI-01**), replace the `http://www.coreshop.org` link with `https://www.coreshop.com`.

### CI-34 — Tests for scan-3 classes
- **Fix:** `ClassReflection` against a fixture directory with docblocks/enums/interfaces (TODO-1 **CC-05**), `TranslatableEntityLocaleAssigner` with stub providers (TODO-1 **CC-04**), `FolderCreationService` with a stub `ObjectServiceInterface` (TODO-2 **CB-33**), `UniqueTokenGenerator` length/alphabet (TODO-2 **CB-37**), `ResourceDocumentTagFactory` constructor arguments (TODO-2 **CB-36**).
