# Resource Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs that produce wrong results, warnings / deprecations or errors in one place, but do not take down a whole area on their own.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Prefixes, markers and the "DataHub not installed in the reference project" caveat as in TODO-1.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

# Scan 1 (2026-10-29): `DataHub/`, `Doctrine/`, `Exception/`

## DataHub – type mapping

### CB-01 — `datetime`, `object` and `simple_array` columns are exposed as `String`: every query of `creationDate` / `modificationDate` fails — (verified)
- **Where:** `DataHub/DoctrineProvider.php:360-381` (`mapFieldType()`): everything not in the `switch` falls back to `Type::string()`.
- **Affected mappings in CoreShop:** 79 `datetime` fields (e.g. `TaxationBundle/.../TaxRuleGroup.orm.xml:14,17`, `StoreBundle/.../Store.orm.xml` — both exposed via `Resource` query types), 3 `object`, 1 `simple_array` field.
- **Effect:** the resolver (`DataHub/Resolver/DoctrineField.php:40-52`) returns a `\DateTime` / object / array for a `String` field; webonyx's `StringType::serialize()` throws "String cannot represent value" → the field is `null` and the response carries an error for every row.
- **Fix:** `match` on Doctrine `Types::*` constants: `DATE*_MUTABLE/IMMUTABLE`, `TIME*` → `DateTimeType` (or an ISO-8601 `DateTime` scalar, CB-11); `SIMPLE_ARRAY` → `Type::listOf(Type::string())`; `OBJECT` / unknown → **exclude** the field (log it) instead of guessing `String`. See TODO-3 **CI-07**.

### CB-02 — `DateTimeType`, `BigIntType` and their filters are never used; the date filter types are built with a `null` field type — (verified)
- **Where:**
  - `mapFieldType()` never returns `DateTimeType` / `BigIntType`, so the branches `DoctrineProvider.php:174-183` are dead.
  - `:182` asks for `FilterNumber::NAME . '_bigint'`, a key that is never registered (only `_int`, `:82`).
  - Constructor `:83-84` calls `$this->getType('datetime')`, which is not registered either → `FilterDateTimeBetween` / `FilterDateTime` are created with `'type' => null` for all their fields. They are stored in `$this->types` and returned by `getTypes()`; adding them to a schema fails validation.
- **Fix:** register the scalar types (`datetime`, `bigint`) in `$this->types` **before** building filters, and register `filternumber_bigint`; or delete the dead branches together with CB-01's new mapping. Add a test that every registered type passes `$schema->assertValid()`.

### CB-03 — Doctrine `bigint` is mapped to GraphQL `Int` (32-bit): large values fail to serialize; `decimal` is mapped to `Float` — (verified)
- **Where:** `DoctrineProvider.php:363-371`.
- **Effect:** GraphQL `Int` is 32-bit signed; webonyx throws "Int cannot represent non 32-bit signed integer value" for any `bigint` > 2 147 483 647 (ids, amounts in minor units). `decimal` values (strings in Doctrine) become `float` → precision loss for money/tax rates.
- **Fix:** `bigint` → `BigIntType` (serialised as string) or `Type::string()`; `decimal` → `Type::string()`. (Custom `bigintInteger` already falls back to `String`, which is fine.)

### CB-04 — `{Name}__List` type name is shared by two different shapes: top-level list (`totalCount`, `items`, `pageInfo`) and to-many field list (`total`, `items`) — (verified)
- **Where:** `DataHub/DoctrineProvider.php:430-439` (`$niceName . '__List'`, built by `GraphResultList::getType()`), and `DataHub/Resolver/DoctrineToMany.php:65-79` (`$listType->name . '__List'` = the same string, built by its own `getListType()` with `total`). Both reuse whichever was registered first (`getType($outputTypeName) === null`).
- **Effect:** depending on build order, either the top-level query returns a `GraphResultList` against a type without `totalCount`/`pageInfo`, or nested to-many fields return `['items','total']` against a type that expects `totalCount`/`pageInfo` (→ always `null`). Clients see fields disappear depending on which resource was touched first.
- **Fix:** use distinct names (`{Name}__Connection` for the top-level list, `{Name}__Collection` for nested ones) or one shared shape (TODO-3 **CI-12**).

### CB-05 — Associations to non-exposed entities break input / query-filter types; parent-interface lookup can yield `null` — (verified) (plausible trigger)
- **Where:**
  - Output fields check `array_key_exists($doctrineClass, $this->classMap)` (`DoctrineProvider.php:208`), but the input closure (`:268-294`, lookups at `:272`, `:284`) and the query-filter closure (`:305-320`, `:309`) do **not** → `getTypeName()` (`:383-388`) reads an undefined key (warning, `null`) → `getInputType(null)` → `'type' => null` / `Type::listOf(null)`.
  - `:252-261`: parent interface `getType($parentName . '__Interface')` is `null` when the child is initialized before the parent (order of `$this->classMap`) or when the parent is not exposed.
- **Effect:** the `__Input` / `__QueryFilter` types (used by `getGraphQlQuery()`) fail as soon as an exposed entity has an association to a non-exposed one (e.g. a project disables one resource — compare TODO-1 **CC-03**).
- **Fix:** one `isExposed(string $class): bool` helper, used by all three closures; build interfaces lazily (`'interfaces' => fn () => ...`) so order does not matter; make `getTypeName()` throw a descriptive exception instead of returning `null` with a warning (TODO-3 **CI-14**).

### CB-06 — `resolveType` of inheritance interfaces calls `getDataValue()` on a Doctrine entity — (verified) (plausible trigger: no CoreShop mapping uses inheritance today)
- **Where:** `DoctrineProvider.php:236-242` expects a `GraphEntity` (`DataHub/GraphEntity.php:45-48`), but the list resolver returns plain entities from `$query->getResult()` (`:575`). `GraphHydrator`, the only producer of `GraphEntity`, is not used anywhere.
- **Effect:** `Error: Call to undefined method ...::getDataValue()` for any entity with `subClasses` (single/joined table inheritance in a project).
- **Fix:** resolve via `$this->em->getClassMetadata($value::class)->getName()` → `getTypeName()`; delete `GraphEntity` / `GraphHydrator` (TODO-3 **CI-03**).

### CB-07 — Scalar types `array` / `json`: broken literal parsing — (verified)
- **Where:**
  - `DataHub/Type/ArrayType.php:37-40`: `parseLiteral()` returns `$valueNode->value`; `ListValueNode` / `ObjectValueNode` have no `value` property → warning + `null`.
  - `DataHub/Type/JsonType.php:37-40`: passes the undefined `$result` by reference; for a non-object literal `$valueNode->fields` does not exist → warning + `null`. `JsonType` itself is only produced for `json_array`, a type removed in DBAL 3 (`DoctrineProvider.php:372-373`), so it is effectively dead.
- **Fix:** use webonyx `AST::valueFromASTUntyped($valueNode, $variables)` for both; drop `JsonType` (or map `json` → `JsonType` and drop `ArrayType`) — one generic `JSON` scalar is enough.

### CB-08 — `DateTimeType` throws `TypeError` under `strict_types` and has no timezone handling — (verified)
- **Where:** `DataHub/Type/DateTimeType.php:37-66`.
  - `parseLiteral()`: `IntValueNode::$value` is a **string** → `\DateTime::setTimestamp(string)` → `TypeError` (file has `declare(strict_types=1)`). `StringValueNode` (an ISO date) also ends there.
  - `parseValue()`: a variable given as JSON string has the same problem; `$valueNode != null` is a loose comparison.
  - `serialize()` assumes `getTimestamp()` exists.
- **Effect:** every datetime filter/argument (once CB-01/CB-02 activate it) fails.
- **Fix:** accept ISO-8601 strings and int timestamps; return `\DateTimeImmutable` in the configured timezone; throw `GraphQL\Error\Error` for invalid input; `serialize()` → `DATE_ATOM` string (`instanceof \DateTimeInterface` check).

## DataHub – list query (`DoctrineProvider::getGraphQlQuery()`)

### CB-09 — `first: N` returns N+1 items; `totalCount` is the page size, not the total — (verified)
- **Where:** `DataHub/GraphPageInfo.php:79-84` sets `setMaxResults($first + 1)` to detect `hasMore`; `DataHub/GraphResultList.php:42-70` counts up to `first` but stores the **full** `$dataList` in `$this->items` (`:67`) and the page count in `totalCount` (`:66`).
- **Effect:** clients get one extra row on every page (which is then also the first row of the next `after` page → duplicates); `totalCount` cannot be used for page navigation.
- **Fix:** `array_slice($dataList, 0, $first)` for `items`; compute `totalCount` with a separate `COUNT()` query (or rename it `count`). `if ($dataList !== null)` on an `array` parameter is always true — remove.

### CB-10 — `first` / `offset` are not validated: negative values and unbounded result sets — (verified) (plausible impact)
- **Where:** `GraphPageInfo.php:79-91`.
- **Effect:** no default/max page size → a query without `first` loads the whole table into the ORM (memory/time DoS on large tables such as payments); `first: -1` → `setMaxResults(0)`, negative `offset` → SQL error 500.
- **Fix:** default `first` (e.g. 50) and a configurable maximum; `Assert::greaterThanEq($offset, 0)`; `Type::nonNull`/`@constraint`-like validation in the arg definitions.

### CB-11 — No cursor is ever returned; `after` is silently ignored when `offset` is present — (verified)
- **Where:** `GraphPageInfo.php` has a `$cursor` property (`:39`) that is never set; the `PageInfo` type (`:41-51`) only exposes `hasMore`. `:96-102` drops `after` when `offset` is given.
- **Effect:** keyset pagination via `after` is unusable (clients cannot get the cursor of the last row); combined use is silently wrong. Keyset on identifiers is also only correct when sorting by identifiers, but `sort` can be anything (`:111-129`).
- **Fix:** expose `endCursor` (built from the last returned row, CC-01 format); reject `after` together with `offset` or with a non-identifier `sort`.

### CB-12 — Filter parameter names collide — (verified)
- **Where:** `DoctrineProvider.php`
  - `:511-514`: `between` uses fixed `:from` / `:to` → two `between` filters on different fields overwrite each other's parameters.
  - `:543-550`: association filters bind `:<associatedField>` → collides with a root filter of the same name (`id`, `name`, …) and with the same field of another joined association; the last `setParameter()` wins for **all** placeholders.
- **Effect:** wrong rows returned without any error.
- **Fix:** generate unique parameter names (`sprintf('p%d', $i++)` or `"{$alias}_{$field}"`).

### CB-13 — Only the first operator of a filter object is applied; LIKE wildcards are not escaped — (verified)
- **Where:** `DoctrineProvider.php:478-535` (`if / elseif` chains for `FilterString`, `FilterDateTime`, `FilterNumber`).
- **Effect:** `{price: {greaterOrEquals: 10, lessOrEquals: 20}}` silently ignores the upper bound; `{name: {equals: "x", in: [...]}}` ignores `equals`. User input containing `%` / `_` in `contains` / `startsWith` / `endsWith` matches unintended rows.
- **Fix:** apply every present operator (AND), i.e. iterate a `match`/operator map (TODO-3 **CI-04**); escape with `addcslashes($v, '%_\\')`.

### CB-14 — `GraphPageInfo::$hasMore` is a dynamic property: deprecated since PHP 8.2 — (reproduced)
- **Where:** `DataHub/GraphResultList.php:69` assigns `$this->pageInfo->hasMore`; `GraphPageInfo` (`DataHub/GraphPageInfo.php:35-40`) declares only `$cursor`.
- **Effect:** `Deprecated: Creation of dynamic property GraphPageInfo::$hasMore` on every list query on PHP 8.4 (fills logs, fails tests with `failOnDeprecation`); becomes an `Error` in PHP 9.
- **Fix:** declare `public bool $hasMore = false;` (or make `GraphPageInfo` a readonly DTO, TODO-3 **CI-06**).

### CB-15 — Boolean list filter is guarded by `PHPStan\Type\BooleanType`, so it never runs (and would be wrong if it did) — (verified)
- **Where:** `DoctrineProvider.php:44` imports `PHPStan\Type\BooleanType` (a dev-only static-analysis class) instead of `GraphQL\Type\Definition\BooleanType`; used at `:557-565`.
- **Effect:** the `instanceof` is always false (in production PHPStan isn't even installed). If the import were "fixed", booleans would be converted to the strings `'true'`/`'false'`, which MySQL compares to `TINYINT` as `0` → every boolean filter would match only `false` rows.
- **Fix:** delete the import and the conversion block; bind booleans as-is (Doctrine converts them via the field type).

### CB-16 — The JSON filter branch is unreachable and would be DQL injection if reached — (verified)
- **Where:** `DoctrineProvider.php:463-476`. Query-filter fields of JSON columns are `Type::listOf($fieldType)` (`:190-193`), so `$fieldType instanceof JsonType` is never true. The branch concatenates `$path` and `$value` into DQL and calls a `JSON_PATH_EQUALS` DQL function that is not registered anywhere.
- **Fix:** delete it (with `JsonType`, CB-07). If JSON filtering is needed later, implement it with bound parameters and a registered DQL function.

### CB-17 — `graphql.enabled` check has an operator-precedence bug — (reproduced)
- **Where:** `DoctrineProvider.php:103`: `!$metadata->getParameter('graphql')['enabled'] ?? false` is parsed as `(!$x['enabled']) ?? false`.
- **Effect:** when the key is missing: `Warning: Undefined array key "enabled"` and the resource is treated as **enabled** (reproduced: result `true`). Today the bundle configurations always set the key via `defaultTrue()`, so this only hits resources registered by projects/other code without the key.
- **Fix:** `if (!($metadata->getParameter('graphql')['enabled'] ?? false))` — or a typed accessor on `MetadataInterface` (`isGraphQlEnabled(): bool`).

## DataHub – query types / resolvers

### CB-18 — `ResourceList` resolver crashes on empty fields and does N+1 lookups — (plausible) (dependent: depends on what the data-hub parent resolver returns)
- **Where:** `DataHub/QueryType/ResourceList.php:32-45`.
- **Effect:** `array_map(..., null)` → `TypeError` when the Pimcore field is empty (the parent `Input` resolver returns `null`); one `find()` per id; ids of deleted resources become `null` items.
- **Fix:** `$value ?? []`; one `findBy(['id' => $ids])` re-ordered by the input ids; `array_filter()` out `null`s (or make the list type `listOf(nonNull(...))` and filter).

### CB-19 — Field resolvers assume shapes that do not hold — (verified)
- **Where:** `DataHub/Resolver/DoctrineField.php:41-47`, `DoctrineToOne.php:48-54`, `DoctrineToMany.php:47-60`.
  - `$value[$this->name]` without `??` → undefined-key warning for arrays.
  - Any entity that happens to have a public `get()` method short-circuits the property accessor.
  - `DoctrineToMany` returns the raw value for arrays / `get()` objects, but `['items' => ..., 'total' => ...]` for entities — the type expects the latter. `$collection` may be `null` (uninitialised collection on a new entity) → `Error: Call to a member function toArray() on null`.
- **Fix:** one shared resolver (`PropertyAccessorInterface::getValue()`), `iterable` → `['items' => [...], 'total' => n]` normalisation in one place, `null` → empty list.

## Doctrine DBAL types

### CB-20 — Pimcore element types silently write `NULL` for anything that is not an element instance — (verified) (plausible trigger)
- **Where:** `Doctrine/Type/PimcoreObject.php:44-51`, `PimcoreAsset.php:44-51`, `PimcoreDocument.php:44-51`, `PimcoreSite.php:51-58`.
- **Effect:**
  - An `int` id (e.g. `$repo->findBy(['product' => $productId])`, or `setParameter('product', $id, 'pimcoreObject')`) is converted to `NULL` → `product = NULL` matches nothing, no error. (Current CoreShop repositories pass ids **without** a type — `CoreBundle/Doctrine/ORM/ProductStoreValuesRepository.php:53,69`, `PaymentBundle/Doctrine/ORM/PaymentRepository.php:31` — so they are not affected today.)
  - An unsaved element (`getId() === null`) or a wrong value type is persisted as `NULL` on nullable columns (`PaymentProvider.logo`, `Carrier.logo`) → silent data loss.
- **Fix:** accept `int|numeric-string` ids (return `(int)`), `null` → `null`, element with `getId() === null` → throw; anything else → `ConversionException::conversionFailedInvalidType()` (DBAL 3) / `InvalidType::new()` (DBAL 4).

### CB-21 — Reading a row whose Pimcore element was deleted yields `null` behind non-nullable getters — (plausible)
- **Where:** `PimcoreObject.php:35-42` (and the asset/document/site variants): `AbstractObject::getById()` returns `null` for a deleted object. `ProductStoreValues.product` and `Payment.order` are mapped `pimcoreObject` (`CoreBundle/Resources/config/doctrine/model/{ProductStoreValues,Payment}.orm.xml`) with no FK to `objects`.
- **Effect:** after deleting a product/order in Pimcore, hydrating the dangling `ProductStoreValues` / `Payment` rows returns `null` for a field whose model getter may be typed non-null → `TypeError` far from the cause (dependent: check the getters in Core/Payment components).
- **Fix (dependent):** clean up the rows on element delete (event listener) or declare the getters nullable; document the behaviour in the type class.

## Exceptions

### CB-22 — `ImplementedByPimcoreException` is returned instead of thrown in a Behat model — (verified) (dependent)
- **Where:** `Behat/Model/Index/TestIndexFields.php:46,51` (`return new ImplementedByPimcoreException(...)`); all other call sites (`Behat/Model/Index/TestIndex.php`, `Behat/Model/Lock/OptimisticLock.php`, the component models) throw it.
- **Effect:** the method returns an exception object where callers expect a value → misleading `TypeError` (or silent wrong value) in Behat runs.
- **Fix (dependent):** `throw`. Component side: TODO-3 **CI-11** (`never`-returning named constructor makes the mistake impossible to type-check through).

---

# Scan 2 (2026-10-29): `Factory/`, `Metadata/`, `Model/`

Scope and followed callers: see TODO-1, "Scope (scan 2)". "(reproduced)" items were run with PHP 8.4.27 against this component's classes (autoloaded from the reference project's vendor for `doctrine/collections` and `doctrine/inflector`).

## Model – `SetValuesTrait`

### CB-23 — `setValue()` calls a non-existent method for `o_*` keys; `$ignoreEmptyValues` is ignored — (reproduced)
- **Where:** `Model/SetValuesTrait.php:32-43`. The `elseif` (`:37-39`) checks for `'set' . preg_replace('/^o_/', '', $key)` but then calls `$this->$method($value)` — the **unstripped** `set<o_key>` that was just found not to exist.
- **Effect:** `setValue('o_foo', …)` → `Error: Call to undefined method ...::seto_foo()` (reproduced). Reachable from `ResourceBundle/Controller/ResourceController.php:193-195` (`addAction()` passes every POST key — ResourceBundle **B-07**) and `OrderBundle/Controller/{OrderInvoiceController.php:135-142,OrderShipmentController.php:138}` (every key of the decoded request payload except `items`/`id`/`state`) → HTTP 500. `$ignoreEmptyValues` (declared in `ResourceInterface::setValue()`, `Model/ResourceInterface.php:31`) has no effect, unlike Pimcore's `Concrete::setValue()` that the same interface also covers.
- **Fix:** call the stripped method name — or, better, drop the `o_` compatibility branch (Pimcore 11 has no `o_` fields any more) and honour `$ignoreEmptyValues` (`if ($ignoreEmptyValues && ($value === null || $value === '' || $value === [])) return $this;`). Restricting **which** setters are callable is TODO-3 **CI-21**.

## Metadata

### CB-24 — `Metadata::getTemplatesNamespace()` throws `TypeError` when no `templates` key is configured — (reproduced)
- **Where:** `Metadata/Metadata.php:27,37` stores `?string`, `:81-84` returns it with return type `string` (interface `Metadata/MetadataInterface.php:35` also says `string`).
- **Effect:** `TypeError: ...::getTemplatesNamespace(): Return value must be of type string, null returned` for every resource without `templates` (= all CoreShop resources). No caller in `src/` today, but it is public API.
- **Fix:** `?string` on interface and class (BC-relevant for implementors, see TODO-3 **CI-19**), or remove the method if templates are not a CoreShop concept.

### CB-25 — `Metadata` accepts malformed input: missing `driver` → warning + `TypeError`; alias `a.b.c` silently becomes `a.b`; `a.` gives an empty name — (reproduced)
- **Where:** `Metadata/Metadata.php:31-40` (`$parameters['driver']` without check), `:132-139` (`parseAlias()` only checks `str_contains($alias, '.')`, then `explode('.')` and the caller destructures the first two parts).
- **Effect:** `Warning: Undefined array key "driver"` + `TypeError: Cannot assign null to property ...$driver of type string`; `fromAliasAndConfiguration('a.b.c', …)->getAlias()` returns `'a.b'` and two resources can end up with the same alias (the later one overwrites the earlier one in `Registry::add()`, CB-26) — all silently.
- **Fix:** `explode('.', $alias, 2)` + assert both parts are non-empty `[a-z0-9_]+`; `Assert::keyExists($parameters, 'driver')` with a message naming the alias.

### CB-26 — `Registry::hasClass()` / `getByClass()` compare exact class names: Doctrine proxies and subclasses are "not resources"; `hasClass()` throws instead of returning `false` — (reproduced: throw) (plausible: proxies)
- **Where:** `Metadata/Registry.php:39-59`; `:61-64` overwrites duplicate aliases silently.
- **Effect:**
  - Callers pass `$object::class` (`CoreBundle/Pimcore/LinkGenerator/ResourceConfigurationLinkGenerator.php:37,41`, `ResourceBundle/Slug/ResourceConfigurationSlugGenerator.php:35`). A lazily loaded association (e.g. `$productStoreValues->getStore()`) is a `Proxies\__CG__\...` instance → `hasClass()` is `false` → no link / no slug, without error. A project subclass that is not configured as `classes.model` behaves the same.
  - Any metadata without a `model` class makes `hasClass()` throw `InvalidArgumentException: Class "model" is not configured …` for **every** lookup (reproduced), because the loop calls `getClass('model')` unguarded. Today all resource configs require `model`, so only programmatic `add()` calls are affected.
- **Fix:** resolve the real class (`Doctrine\Persistence\Proxy` check + `get_parent_class()`, or DoctrineBundle's proxy-class resolver) and fall back to `is_a($class, $model, true)`; guard with `$metadata->hasClass('model')`; index by class (TODO-3 **CI-20**); throw on duplicate alias in `add()`.

## Model – translations

### CB-27 — `TranslatableTrait::getTranslation()` creates and attaches an empty translation on a read — (reproduced) (dependent: persisted through the ORM mapping)
- **Where:** `Model/TranslatableTrait.php:58-93` (`:85-90`); the association is mapped `cascade: [persist, merge, remove]`, `orphanRemoval: true`, `indexBy: locale` by `ResourceBundle/EventListener/ORMTranslatableListener.php:98-105`.
- **Effect:** reading e.g. `$country->getName('it')` (`Component/Address/Model/Country.php:99`) when neither `it` nor the fallback locale has a translation adds an empty `it` translation to the collection (reproduced: count 0 → 1); the next `flush()` on the entity manager inserts it (the "has a translation" state then changes for the admin too, and `NOT NULL` translation columns can turn the flush into a DB error).
- **Fix:** keep `getTranslation()` read-only (return a detached, non-attached empty translation, or throw), and add an explicit `getOrCreateTranslation(string $locale)` for setters (the models already call `getTranslation($locale, false)` from setters — switch those). TODO-3 **CI-22**.

### CB-28 — `addTranslation()` silently drops a second translation object for an existing locale — (reproduced)
- **Where:** `Model/TranslatableTrait.php:100-113`: `hasTranslation()` compares by **locale**, so a new object for `de` is neither added, nor replaces the old one, nor gets its `translatable` set.
- **Effect:** code (forms, importers, serializer denormalisation) that builds new translation objects for existing locales loses the data without error (reproduced: `getTranslatable()` of the new object is `null`, `getTranslation('de')` still returns the old one).
- **Fix:** either replace (`set()` + detach the old one) or throw `\LogicException('Translation for locale "de" already exists')`; document which semantics apply.

### CB-29 — Translation cache gets out of sync with the collection — (reproduced)
- **Where:** `Model/TranslatableTrait.php:37` (`$translationsCache`), `:65-67` and `:102` read the cache first; `getTranslations()` (`:95-98`) hands out the mutable collection.
- **Effect:** after `$entity->getTranslations()->remove('de')` (what Symfony collection forms / `EntityMerger` / `refresh()` effectively do), the collection is empty but `getTranslation('de')` still returns the removed object and `hasTranslation()` is `true`, so a new `de` translation can **not** be added (reproduced: count 0, old object returned). Clones share the cache, see CB-31.
- **Fix:** drop the cache (`indexBy: locale` makes `$this->translations->get($locale)` O(1) already) or only cache what is verified to still be in the collection; return a read-only view from `getTranslations()`.

### CB-30 — `AbstractTranslation` can't be attached before its locale is set; `setLocale(null)` → `TypeError` — (reproduced)
- **Where:** `Model/AbstractTranslation.php:28` (`protected string $locale;` without default), `:37-53` (`setTranslatable()` → `addTranslation()` → `hasTranslation()` → `getLocale()`), `:60-63` (untyped `setLocale($locale)` into a typed property).
- **Effect:** `$t = new FooTranslation(); $t->setTranslatable($foo);` → `Error: Typed property ...::$locale must not be accessed before initialization` (reproduced). Form/serializer field order decides whether this happens.
- **Fix:** `protected ?string $locale = null;` with `getLocale(): ?string`, and make `addTranslation()` reject translations without locale with a clear message; type `setLocale(string $locale): void`.

### CB-31 — Cloning a translatable resource shares the translation collection and cache with the original — (reproduced) (plausible trigger)
- **Where:** `Model/TranslatableTrait.php` has no `__clone()`; none of the nine users (`Component/{Order/Model/CartPriceRule,Shipping/Model/Carrier,Payment/Model/{PaymentProvider,PaymentProviderRule},Taxation/Model/TaxRate,Product/Model/{ProductUnit,AbstractPriceRule},Address/Model/{State,Country}}.php`) defines one.
- **Effect:** `clone $carrier` → the clone's `getTranslation('de')` returns the original's object (reproduced: identical); editing the clone's name edits the original, and Doctrine sees the same translation entities owned by two parents. (CoreShop's admin "clone" re-creates via the form, and price-rule copies use DeepCopy, so the core paths are not affected; projects using `clone` are.)
- **Fix:** a `__clone()` in the trait (or a `cloneTranslations()` helper the models call) that deep-clones each translation, re-points `translatable`, and resets the cache.

## Model – timestamps

### CB-32 — `TimestampableInterface` promises `\DateTime`, but `getModificationDate()` is `null` until the first update, and setters reject `\DateTimeImmutable` — (verified) (plausible impact)
- **Where:** `Model/TimestampableInterface.php:23-35`, `Model/TimestampableTrait.php:23-57`; mappings use `<gedmo:timestampable on="update"/>` with `nullable="true"` (e.g. `TaxationBundle/Resources/config/doctrine/model/TaxRuleGroup.orm.xml:17-19`).
- **Effect:** callers that trust the `@return \DateTime` (`->format()`) fail with `Error: Call to a member function format() on null` for never-updated resources; code that passes `new \DateTimeImmutable()` to the setters gets a `TypeError`. Adding native return types later without `?` would break hydration of these rows.
- **Fix:** `getModificationDate(): ?\DateTimeInterface` (and the same for `getCreationDate()` before the first persist), setters taking `\DateTimeInterface`; see TODO-3 **CI-24** for the BC plan.

---

# Scan 3 (2026-10-29): `Pimcore/`, `Reflection/`, `Repository/`, `Service/`, `Storage/`, `TokenGenerator/`, `Translation/`

Scope and followed callers: see TODO-1, "Scope (scan 3)". Note for **CB-27**: because of TODO-1 **CC-04** the fallback locale is always equal to the current locale in the wired setup, so CB-27 hits for *every* untranslated locale, not only when the fallback is missing too.

## Service

### CB-33 — `FolderCreationService`: an unknown `path` key silently creates the folder in the wrong place; path segments are not sanitised — (verified) (plausible trigger)
- **Where:** `Service/FolderCreationService.php:35-77`.
  - `:71`: `$resourceConfig[$options['path']]` without `isset` → `Warning: Undefined array key` and `$path = null`; `sprintf('%s%s%s', prefix, null, suffix)` then creates e.g. `/A` (just the suffix) at the **object tree root** and the customer/cart is saved there.
  - `:66-67`: with a single configured path, a given `path` option is ignored without notice (a typo is never detected).
  - `:46-52`: `prefix` / `suffix` are concatenated unvalidated. Callers pass user data: `mb_strtoupper(mb_substr($customer->getLastname(), 0, 1))` (`CoreBundle/Customer/CustomerManager.php:66-69`, `OrderBundle/Controller/CustomerCreationController.php:67-68`) — a last name starting with `/`, `.` or a character Pimcore keys reject produces `customers//`, `customers/.` or a key exception; a prefix already ending in `/` becomes `//`.
  - `:42`: `getByClass($resource::class)` — exact class match, see **CB-26**.
- **Fix:** `$resourceConfig[$options['path']] ?? throw new \InvalidArgumentException(sprintf('Unknown path "%s", configured: %s', …))`; reject a `path` option that is not configured; sanitise each segment with `Pimcore\Model\Element\Service::getValidKey($segment, 'object')` and join with `rtrim(..., '/')`; `OptionsResolver` with `setAllowedTypes('prefix', ['null','string'])` etc. TODO-3 **CI-29**.

## Translation

### CB-34 — `PimcoreTranslationLocaleProvider::getDefaultLocaleCode()` throws `TypeError` when Pimcore has no default language — (verified) (LocaleBundle **B-02**)
- **Where:** `Translation/Provider/PimcoreTranslationLocaleProvider.php:30-33` returns `Tool::getDefaultLanguage()`, which is `?string` (`vendor/six-it/pimcore-source/lib/Tool.php:158`).
- **Effect:** on fresh installs / broken `system_settings` / CLI test kernels every translatable entity load (`postLoad` → TODO-1 **CC-04** assigner) and every `TranslatableFactory::createNew()` (`Factory/TranslatableFactory.php:41-42`) fails with `TypeError` instead of a domain exception.
- **Fix:** `?? throw new LocaleNotFoundException('No default Pimcore language configured')` (or fall back to the first of `getValidLanguages()`); same fix as LocaleBundle **B-02** — close in both files.

## Pimcore

### CB-35 — `ResourceSlugLinkGenerator` calls `getFullPath()` on any object; throws an exception without message — (verified)
- **Where:** `Pimcore/ResourceSlugLinkGenerator.php:34-55`.
- **Effect:** `generate(object $object)` accepts any object; for a non-`SluggableInterface` object without `getFullPath()` (e.g. a Doctrine resource) the error path itself throws `Error: Call to undefined method …::getFullPath()` instead of `LinkGenerationNotPossibleException`. `:50` throws `LinkGenerationNotPossibleException()` without message → empty log lines. `:46-47` uses `$object::class`, i.e. exact-class registry lookup (**CB-26**).
- **Fix:** `$object instanceof ElementInterface ? $object->getFullPath() : get_debug_type($object)`; message "Slugs are disabled for resource %s"; lookup through the proxy/subclass-aware registry.

### CB-36 — `ResourceDocumentTagFactory` spreads the editable params into the constructor: string keys become named arguments — (verified) (plausible trigger)
- **Where:** `Pimcore/ResourceDocumentTagFactory.php:36-45`; target `ResourceBundle/CoreExtension/Document/Select.php:32-36` (`__construct(string $repositoryName, string $nameProperty, string $type)`); caller `PimcoreBundle/Loader/DependencyInjectionImplementationLoader.php:37-45` passes Pimcore's `$params` through.
- **Effect:** with `array_unshift()` the three CoreShop values become positional args 0-2; any associative entry in `$params` becomes a **named** argument → `Error: Unknown named parameter $foo`; additional positional entries are silently dropped. Today Pimcore calls the loader with empty `$params`, so this is latent.
- **Fix:** `return new $className($this->repositoryName, $this->nameProperty, $type);` and ignore/validate `$params` explicitly; `Assert::subclassOf($class, EditableInterface::class)` in the constructor.

## Token generator

### CB-37 — `UniqueTokenGenerator::generate()` returns an empty string for `$length <= 0` — (reproduced)
- **Where:** `TokenGenerator/UniqueTokenGenerator.php:49-60`.
- **Effect:** `generate(0)` and `generate(-5)` both return `''` (reproduced). Callers store the result as order token (`FrontendBundle/Controller/CheckoutController.php:214`), storage-list token (`Component/StorageList/Factory/StorageListFactory.php:49`) and payment number (`Component/Order/Payment/OrderPaymentProvider.php:41-42`, `OrderBundle/Controller/OrderPaymentController.php:100-101`); a length coming from configuration as `0` would silently produce empty — and therefore shared — tokens.
- **Fix:** `if ($length < 1) throw new \InvalidArgumentException(...)`; TODO-3 **CI-31**.
