# IndexBundle – TODO 3: Improvements (code quality, readability, logic)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). None of these items is a functional defect on its own; the defects are in TODO-1 and TODO-2, and items here link to them where a cleanup also closes a bug.
Paths are relative to the bundle root unless noted otherwise. Several items change public signatures (`ListingInterface`, `WorkerInterface` live in `Component/Index`). Group those into one BC-breaking step and add `UPGRADE` notes.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 language features

### I-01 — Add native parameter and return types (67 methods have none)
- **Where:** mostly `Worker/MysqlWorker/Listing.php` (every getter and setter: `setOrder($order)`, `setLimit($limit)`, `getObjects()`, `getTableName()`, `quote($value)`, …), `Worker/MysqlWorker/Listing/Dao.php`, `Worker/AbstractWorker.php:73,86,208,210,246,311-321` (`renderCondition()`, `renderOrder()`, the abstract methods), `Worker/MysqlWorker.php:159,208,258,287,316,325,334,339,537,561,574`, `Worker/MysqlWorker/TableIndex.php`, `Condition/Mysql/{InRenderer,IsNullRenderer,LikeRenderer}.php` (`render()` has no `: string`), the messenger handlers (`__invoke()`), `EventListener/IndexDeleteObjectListener.php:54`.
- **Why:** the docblock-only types hide real mismatches. For example, `Worker/OpenSearchWorker/Listing.php` already declares `: void`/`: ?array` while the MySQL listing has no types, and `setLimit($limit)` accepts strings until the typed property throws (TODO-2 B-04).
- **How:** type the `Component/Index` interfaces first (`ListingInterface`, `WorkerInterface`, `DynamicRendererInterface::render(): mixed`), then the implementations. Check with `phpstan` level 8 (see I-15).

### I-02 — Use `readonly` promoted properties, `final`, and readonly value classes
- **Where:** every constructor-injected dependency (`EventListener/*`, `Factory/*`, `Messenger/Handler/*`, `Command/IndexCommand.php`, `Form/Type/*` registries, `Condition/*`, `Order/*`, `Worker/*`). `Messenger/IndexMessage.php` and `IndexDeleteMessage.php` (mutable `protected` properties in a non-final class).
- **How:** `private readonly ServiceRegistryInterface $workerServiceRegistry`. `final readonly class IndexMessage { public function __construct(public int $indexableId, public bool $saveVersionOnly = false) {} }`. Keep the getters for BC, or deprecate them. Mark handlers, listeners and factories `final` where nothing in core extends them (`IndexController`/`FilterController` must stay open, because they are configurable `admin_controller` classes).

### I-03 — Replace `switch` and hand-written loops with `match`, `array_find()`, `array_any()` and `is_a()`
- **Where:**
  - `Worker/MysqlWorker.php:542-551` (`renderFieldType()`): `$doctrineType = match ($type) { self::FIELD_TYPE_DATE => 'date', self::FIELD_TYPE_DOUBLE => 'decimal', default => strtolower($type) };`.
  - `Worker/MysqlWorker/Listing.php:464-480` (join type, see TODO-2 B-06).
  - `Controller/FilterController.php:79-83` → `array_find($index->getColumns()->toArray(), fn ($c) => $c->getName() === $field)` (closes TODO-2 B-32).
  - `Controller/IndexController.php:67-68,103`: `in_array(X::class, class_implements($obj), true)` → `$obj instanceof X` / `is_a($fqcn, X::class, true)`.
  - `Worker/MysqlWorker.php:443`, `:485`: `array_map(function (IndexColumnInterface $column) { return $column->getName(); }, …)` → `array_map(static fn (IndexColumnInterface $c): string => $c->getName(), …)`, used as a lookup set (`array_flip` + `isset`) instead of `in_array` inside the loop.

### I-04 — Add `#[\Override]` and remove redundant docblocks and suppressions
- **Where:** 42 `@var` casts and 11 `@psalm-suppress` in the bundle. Examples: `CoreShopIndexBundle.php:88-90`, `Command/IndexCommand.php:115-117,127-135,152-154`, `Worker/MysqlWorker/Listing.php:110-115`, `Worker/MysqlWorker.php:92-96,134-153,554` (the `DeprecatedMethod` and `InternalMethod` suppressions hide exactly the DBAL 4 blockers listed in I-17).
- **How:** put `#[\Override]` on every method that implements an interface or overrides a parent (`buildForm`, `getBlockPrefix`, `render`, `supports`, `createOrUpdateIndexStructures`, …), so renames in `Component/Index` surface as errors. Replace `@var` casts with `assert()`/`Assert::isInstanceOf()` or with proper return types (I-01).

### I-05 — Turn `TableIndex` into a typed value object with an enum
- **Where:** `Worker/MysqlWorker/TableIndex.php` (mutable, `@psalm-suppress MissingConstructor`, string constants), `Form/Type/Worker/MysqlWorkerTableIndexType.php:31-37` (choices without labels), `Worker/MysqlWorker.php:192-203,242-253` (duplicated loop).
- **How:** `enum TableIndexType: string { case Index = 'INDEX'; case Unique = 'UNIQUE'; }`. Make `TableIndex` `final` with `public private(set) array $columns` (PHP 8.4 asymmetric visibility) and an `EnumType` form field. Extract `addTableIndexes(Table $table, array $indexes)` in `MysqlWorker`. Because the objects are currently PHP-`serialize()`d into the `configuration` column, combine this with I-16.

---

## Symfony 7.4 idioms

### I-06 — `IndexCommand`: use `#[AsCommand]`, an invokable command, and exit-code constants
- **Where:** `Command/IndexCommand.php:47-64` (`setName()`/`setDescription()`), `:105`, `:196` (`return 0`), `Resources/config/services/commands.yml` (`command:` attribute on the tag).
- **How:** use `#[AsCommand(name: 'coreshop:index', description: 'Reindex all objects')]`. On 7.3+ you can write an invokable `__invoke(#[Argument] array $indices = [], #[Option] bool $unpublished = false, SymfonyStyle $io)`. Return `Command::SUCCESS`/`Command::FAILURE` (see TODO-2 B-25). Add a `--batch-size` option (see I-26). Use `SymfonyStyle::progressIterate()` instead of a manual `ProgressBar`.

### I-07 — Messenger: `#[AsMessageHandler]`, readonly messages, no dead state
- **Where:** `Messenger/Handler/*.php`, `Resources/config/services/messenger.yml` (`autoconfigure: false` plus manual tags). `Messenger/Handler/IndexDeleteMessageHandler.php:27`: `$validObjectTypes` is never used.
- **How:** use `#[AsMessageHandler]` on `__invoke(IndexMessage $message): void` and drop the YAML tags. Delete the unused property. Make the messages `final readonly` (I-02). Consider a `DelayStamp`/dedup middleware, so that ten saves of the same object within a second produce one index run instead of ten (`IndexObjectListener` dispatches on every `postAdd`/`postUpdate`, including autosave and saves of objects whose class has no index at all). Skip the dispatch when `IndexUpdaterService` has no eligible index for the class.

### I-08 — Event listeners: attributes and typed events instead of YAML tags and `GenericEvent` with string names
- **Where:** `Resources/config/services/listeners.yml`, `Resources/config/services/process_manager.yml` (5 tags), `Command/IndexCommand.php:198-201` (`new GenericEvent($info)`, `sprintf('coreshop.index.%s', $type)`), `Worker/OpenSearchWorker.php:100-106` (`GenericEvent` + `'coreshop.index.create_or_update_index_structures'`), `EventListener/UpdateIndexListener.php` (Doctrine `entity_listener` tag).
- **How:** use `#[AsEventListener(event: IndexEvents::POST_SAVE)]`, and `#[AsEntityListener(event: Events::preUpdate, entity: Index::class)]` (configurable entity class: keep the YAML for that one, or use `#[AsDoctrineListener]` + `instanceof`). Introduce `final class IndexEvents { public const START = 'coreshop.index.start'; … }` and small event classes (`IndexProgressEvent(string $message)`, `IndexStartEvent(int $total)`, `OpenSearchIndexStructureEvent(IndexInterface, string $name, array $body)`). Rename `onClasssesEvent` (ProcessManager, see I-23).

### I-09 — Configuration tree: real node types and no positional `setArgument()`
- **Where:** `DependencyInjection/Configuration.php:319-320` (`scalarNode('autoconfigure_with_attributes')`, `scalarNode('mysql_auto_generate_migrations')`; both are booleans), `:440-443` (`scalarNode('permissions')->defaultValue([...])`, a scalar node with an array default), `:425-439,457,463` (`prototype('scalar')` → `scalarPrototype()`). `DependencyInjection/CoreShopIndexExtension.php:192-195` (`->setArgument(7, …)`).
- **How:** use `booleanNode(...)->defaultFalse()`, and `arrayNode('permissions')->scalarPrototype()->end()->defaultValue([...])`. Set `->setArgument('$generateMigrations', …)` by name, so that reordering the constructor cannot silently inject the bool into the wrong slot. Add `->info()` texts, so that `config:dump-reference core_shop_index` is self-documenting. Remove `mapping_types` from the bundle's own `config.yml` (TODO-2 B-36).

### I-10 — Service definitions: autowiring, fewer public services, no duplicate or unused arguments
- **Where:**
  - `Resources/config/services.yml:11-12` and most other files: `_defaults: public: true` for everything (registries, renderers, form types, listeners).
  - `:146-157`: `OpenSearchWorker` gets `coreshop.registry.index.interpreter` twice (argument 3 `$interpreterServiceRegistry` **and** argument 9 `$interpreterRegistry`). `Worker/OpenSearchWorker.php:56,356` uses the duplicate.
  - `:26-29`: `FilteredListingFactory` gets a second argument (`FilterProcessorInterface`) that the constructor does not have. PHP silently ignores it, and `Factory/FilteredListingFactory.php:58` still has the commented-out use.
  - `:65-71`: the `pre_condition_types` registry is typed to `FilterConditionProcessorInterface`, not `FilterPreConditionProcessorInterface`. Both filter registries share the context name `filter-processor` (`:69`, `:80`), so error messages cannot tell them apart.
  - `:139`, `:158`: both workers are `shared: false`, so every `registry->get()` builds a new worker with all its registries. They are stateless (apart from the logger), so this is not necessary.
  - `Resources/config/services/form.yml:42`: `IndexColumnType` uses `…index.column_type.validation_groups`, while `…index.column.validation_groups` (`:3`) is defined but unused. `:5`, `:6` (`columns`, `worker`) are unused too.
- **How:** move to `_defaults: { autowire: true, autoconfigure: true, public: false }` with explicit aliases only where IDs are used from outside (`coreshop.registry.*`, `ListingFactoryInterface`, `IndexUpdaterServiceInterface`). Remove the duplicate and dead arguments. Type the pre-condition registry correctly. Drop `shared: false` (or document why it is needed).

### I-11 — `CoreShopIndexExtension::load()`: replace nine copy-pasted autoconfiguration calls with a map
- **Where:** `DependencyInjection/CoreShopIndexExtension.php:197-267`. Also `:163-188`: `array_key_exists('X', $bundles)` → `isset($bundles['X'])`.
- **How:** `foreach ([DynamicRendererInterface::class => [RegisterConditionRendererTypesPass::INDEX_CONDITION_RENDERER_TAG, AsDynamicRenderer::class], …] as $interface => [$tag, $attribute]) { Autoconfiguration::registerForAutoConfiguration($container, $interface, $tag, $attribute, $configs['autoconfigure_with_attributes']); }`. Load an `opensearch.yml` only when the client bundle is present (TODO-2 B-23).

### I-12 — `Installer`: do not run console commands in-process
- **Where:** `Installer.php:37-57`.
- **Why:** besides the argument bug (TODO-2 B-29), building a second `Pimcore\Console\Application` on the running kernel is marked `@internal` (`@psalm-suppress InternalClass`). It swallows output (`getOutput()` returns `NullOutput` and is never used) and hides exit codes.
- **How:** inject the services behind `coreshop:resources:install` / `create-tables` (the resource installer and the schema tool) and call them directly. Or use `Symfony\Component\Process\Process` with `bin/console`, and check `isSuccessful()`. Remove the unused `getOutput()` override.

### I-13 — Controllers: typed request access, injected parameters, shared permission handling
- **Where:** `Controller/IndexController.php:81,125,444-461`, `Controller/FilterController.php:46,66,76,99-110`.
- **How:** for the GET-only actions, use `#[MapQueryParameter] string $class` / `int $index` / `string $field` instead of `getParameterFromRequest()`, which reads attributes, then the query, then the body. Inject `#[Autowire(param: 'coreshop.index.interpreters')] array $interpreters` (or a small `IndexTypesProvider` service) instead of `getParameter()` at runtime. Call `isGrantedOr403()` in every custom action (TODO-2 B-03). `getObjectbrickFields()` / `getFieldcollectionFields()` both take `&$result` **and** return it: pick one.

### I-14 — Forms: remove the five copies of the "configurable type" listener, and fix the block prefixes
- **Where:**
  - The same PRE_SET_DATA / POST_SET_DATA / PRE_SUBMIT trio appears in `Form/Type/IndexType.php:52-91`, `AbstractConfigurableIndexColumnElementType.php:44-80`, `IndexColumnType.php:63-120` (twice: getter and interpreter), `FilterConditionType.php:54-90` and `Interpreter/InterpreterType.php:46-79`.
  - Block prefixes: `Worker/MysqlWorkerType.php:47` and `Worker/MysqlWorkerTableIndexType.php:55` are both `coreshop_index_worker_mysql`. `IndexColumnChoiceType.php:46` is `coreshop_rule_action_choice` (copy-paste from RuleBundle, which collides with its theme blocks). `EmptyConfigurationFormType` says `…_interpreter_empty` but is also used for getters. `Interpreter/IteratorInterpreterType.php` has none.
  - `Core/AbstractConfigurationCollectionType.php:36-61` and `Interpreter/InterpreterCollectionType.php:36-61` (duplicated code) build one full prototype form **per registered type on every form build**. They are only needed for `buildView()`, and the admin uses JSON, so this is pure overhead.
  - `DataMapper/ConditionsFormMapper.php:37-40`: `mapDataToForms()` is an empty body with commented code.
- **How:** extract one `ConfigurableTypeSubscriber(string $field, string $targetField, FormTypeRegistryInterface $registry)` (an `EventSubscriberInterface`) and add it in each type. Give every type a unique prefix. Build the prototypes lazily in `buildView()`. Implement `mapDataToForms()` by delegating to the property mapper, or document why it must stay empty.

### I-15 — `composer.json`: declare what the bundle really uses, and update the dev tools
- **Where:** `composer.json`.
- **What:**
  - Used directly but not required: `doctrine/dbal`, `doctrine/orm` (`PreUpdateEventArgs`), `symfony/messenger`, `symfony/console`, `symfony/string` (`u()`, slugger), `symfony/form`, `webmozart/assert`, `psr/log`, and `coreshop/pimcore` (`InheritanceHelper`, `DataObjectBatchListing`).
  - Optional integrations to put under `suggest`: `dpfaffenbauer/process-manager`, `pimcore/data-hub` (`services/data_hub.yml`), `nesbot/carbon` (used only in the ProcessManager listener; see TODO-1 C-03).
  - `phpstan/phpstan ^1.10` and its extensions → `^2.1`.
  - `repositories: [{type: path, url: ../../*/*}]` and `minimum-stability: dev` belong to the monorepo, not to a split package.

---

## Logic, structure and readability

### I-16 — Doctrine mappings: replace `type="array"` (PHP `serialize()`) with `json`
- **Where:** `Resources/config/doctrine/model/Index.orm.xml` (`configuration`), `IndexColumn.orm.xml` (`getterConfig`, `interpreterConfig`, `configuration`), `FilterCondition.orm.xml` (`configuration`). `Index.orm.xml`: `<order-by-field name="name">ASC</order-by-field>` (the direction belongs in the `direction` attribute).
- **Why:** the DBAL `array` type is deprecated in 3.x and removed in 4.0. Serialized PHP in the database stores `TableIndex` objects by class name, so renaming that class breaks every MySQL index config. `unserialize()` of database content is also an object-injection sink.
- **How:** add a migration that converts each column to `JSON` (`unserialize` → `json_encode`, with `TableIndex` mapped to `['type' => …, 'columns' => […]]`), then switch the mapping to `type="json"`. Add a `DataTransformer` in `MysqlWorkerTableIndexType` so that the PHP side keeps the value object (I-05).

### I-17 — DBAL 4 readiness (deprecated in the installed 3.10, removed in 4.0)
- **Where and replacement:**
  - `Worker/MysqlWorker.php:93,96`: `Schema::getMigrateFromSql()` → `$sm->createComparator()->compareSchemas($old, $new)` + `$platform->getAlterSchemaSQL($diff)`.
  - `:555`: `Type::getType(...)->getName()` → `Type::getTypeRegistry()->lookupName($type)` (or just return the normalised name).
  - `:372`: `tablesExist($string)` → `tablesExist([$string])`.
  - `:100,106,345-350,373`: `executeQuery()` for DDL → `executeStatement()`.
  - `Worker/MysqlWorker/Listing/Dao.php:59,139,173`: `QueryBuilder::getQueryPart()` (deprecated) and `Worker/MysqlWorker/Listing.php:446,450`: `QueryBuilder::add('orderBy', …)` → keep the where and order parts in the listing, or use `orderBy()`/`addOrderBy()`.
  - `Dao.php:70,98,102,160,177,204`: `$this->database->fetchAllAssociative($qb->getSQL())` discards any bound parameters → `$qb->executeQuery()->fetchAllAssociative()`.
- **Why now:** these calls currently need `@psalm-suppress DeprecatedMethod` (I-04), and the B-09 bug comes from relying on the internal `getQueryPart()` state.

### I-18 — Bind parameters instead of `quote()` or concatenation in the MySQL renderers, and escape `LIKE`
- **Where:** `Condition/Mysql/{Compare,In,Like,Range}Renderer.php`, `Order/Mysql/SimpleOrderRenderer.php:36`, `Worker/MysqlWorker/Listing/Dao.php:143-168`, `Worker/MysqlWorker/Listing.php:387,407` (`'q.o_type != \'variant\''`).
- **How:** pass the `QueryBuilder` (or a parameter collector) through `$params` and use `createNamedParameter()`. In `LikeRenderer`, escape `%`, `_` and `\` in the user value (`addcslashes($value, '%_\\')`), so that a search for "50%" does not match everything. `CompareRenderer` should check `$operator` against an allow-list (`=, !=, <, <=, >, >=`). Today it is concatenated raw, which is only safe because all callers are internal.

### I-19 — Clean up the serializer and validation metadata
- **Where:**
  - `Resources/config/serializer/Model.Filter.yml`: `resultsPerPage: type: string` should be `integer`.
  - `Model.FilterCondition.yml`: `field` (property exists but is not mapped by Doctrine) and `filter` (no such property).
  - `Model.Index.yml`: `indexLastVersion` is missing `expose` (TODO-2 B-30).
  - `Resources/config/validation/IndexColumn.yml`: a constraint on `type`, which is never set (TODO-2 B-02).
  - `Filter.yml`: `resultsPerPage` only has `Type: integer` (add `Positive` and an upper bound). `orderKey` should be checked against the index columns. Neither `conditions` nor `preConditions` has `Valid`, so the `FilterCondition` constraints never run either.
- **How:** align each file with the Doctrine mapping. Add a small test that loads the JMS and validator metadata for every model and fails on unknown properties.

### I-20 — Avoid N+1 object loading in both listings
- **Where:** `Worker/MysqlWorker/Listing.php:270-279` (`AbstractObject::getById()` per row, plus a class-name check afterwards), `Worker/OpenSearchWorker/Listing.php:194-200` (`Concrete::getById()` per hit).
- **How:** collect the ids and load them with one Pimcore listing of the index class (`$listing->setCondition('id IN (?)', [$ids])`), then re-order by the id order. Filtering by class then happens in SQL. That is 1 query per page instead of N, and it matters with large `perPage` values (TODO-2 B-04).

### I-21 — MySQL array columns are stored as `,a,b,` strings and filtered with `LIKE '%,x,%'`
- **Where:** `Worker/MysqlWorker.php:334-337` (`handleArrayValues()`), `Condition/Mysql/LikeRenderer.php:34-37` (the `InArrayCondition` branch).
- **Why:** a leading-wildcard `LIKE` can never use an index, so the core shop filters (`stores`, `parentCategoryIds`) are full scans on every category page. Values that contain a comma break the format, and objects in the array are cast to strings implicitly.
- **How:** in the long run, move multi-value columns into the relations table (they are relations already) or into a `JSON` column with a multi-valued index (`CAST(col AS UNSIGNED ARRAY)` + `MEMBER OF`, MySQL 8.0.17+). For now, document the limitation and validate that the values are scalars.

### I-22 — Remove dead code
- `Worker/MysqlWorker/Listing.php:414-420` (commented fulltext block), `:90`, `:454-483` (`queryJoins`, TODO-2 B-06).
- `Worker/MysqlWorker/Listing/Dao.php:236-291` (`buildSimilarityOrderBy()` always returns `''`, with a 50-line commented body), `:301-310` (`buildFulltextSearchWhere()`, unused), `:28,72,315-318` (`lastRecordCount`, unused).
- `Factory/FilteredListingFactory.php:58` (commented processor call) and the extra service argument (I-10).
- `DependencyInjection/CoreShopIndexExtension.php:172` (`coreshop.index.mapping_types` parameter, unused).
- `Messenger/Handler/IndexDeleteMessageHandler.php:27` (I-07). `Installer.php:69-72` (I-12).
- `Worker/OpenSearchWorker/Listing.php:375` (`isset($bucket) && \is_array($bucket)`, always true inside the loop).

### I-23 — Typos and inconsistent names
- `ProcessManager/IndexListener.php:37` + `Resources/config/services/process_manager.yml:7`: `onClasssesEvent` → `onClassesEvent`.
- `EventListener/IndexDeleteObjectListener.php:54,69`: `recurisveDeleteMessage` → `recursiveDeleteMessage`.
- `Worker/MysqlWorker.php:325`: `typeCastValueSQLDecleration` → `…Declaration`.
- `Worker/MysqlWorker.php:592` defines `getTablename()`, but `:295` calls `getTableName()` (PHP is case-insensitive, so it works, but it is confusing). Pick one: `getTableName()`, `getLocalizedTableName()`, `getRelationTableName()`.
- `Worker/MysqlWorker/Listing/Dao.php:116,129`: "Grouo".
- `Worker/AbstractWorker.php:237`: the message says "array with \`id\` and \`type\`", but the code requires `dest` and `type`.
- `Worker/AbstractWorker.php:298-300`: the message prints the interpreter name twice instead of the class that was actually returned (`get_debug_type($interpreterObject)`).

### I-24 — Split `AbstractWorker::prepareData()` into smaller steps
- **Where:** `Worker/AbstractWorker.php:102-206` (about 100 lines: global state, variant resolution, system data, extensions, localized names, the column loop, merging).
- **How:** extract `resolveVirtualObject()`, `collectSystemData()`, `collectColumnData()`, and wrap the global-state handling in a `withIndexingContext(callable)` helper (`try/finally`, closes TODO-2 B-11). `array_merge_recursive()` on `$relationData` (`:174`, `:278`) re-indexes numeric keys anyway, so plain `[...$a, ...$b]` is clearer. `$data['active']` is computed twice (`:112`, `:144`).

### I-25 — OpenSearch: explicit mappings, bulk indexing, configurable settings
- **Where:** `Worker/OpenSearchWorker.php:72-115` (settings hard-coded apart from shards and replicas), `:197-226` (one `index` request per object), `:328-373`. `Worker/OpenSearchWorker/Listing.php:329` (`_term`).
- **How:**
  - Set `mappings.dynamic: strict` and map `_relations` explicitly (TODO-2 B-22).
  - Add a `.raw` keyword sub-field for `text` columns (TODO-1 C-04).
  - Use `_key` instead of `_term`.
  - Add a bulk path (`$client->bulk()`) for `coreshop:index`.
  - Add `refresh` and `refresh_interval` handling for full rebuilds (`-1` during the rebuild, restored afterwards).
  - Allow extra index settings and analyzers from the worker configuration form.
  - `OpenSearch\Common\Exceptions\Missing404Exception` (opensearch-php 2.3.1 in the reference vendor) is deprecated from 2.4 in favour of `OpenSearch\Exception\NotFoundHttpException`. Catch both while supporting the 2.x range.

### I-26 — `coreshop:index` and Process Manager performance
- **Where:** `Command/IndexCommand.php:139` (`$perLoop = 10`, hard-coded), `:170-183` (no garbage collection between batches). `ProcessManager/IndexListener.php:68-76` (`$this->process->save()` plus a log line for **every** object).
- **How:** add `--batch-size` (default 100) and call `\Pimcore::collectGarbage()` after each batch. In the listener, save the progress every N objects or every X seconds. Combine with the bulk indexing in I-25.

### I-27 — `RegisterOpenSearchClientPass`: do not scan every service definition by prefix
- **Where:** `DependencyInjection/Compiler/RegisterOpenSearchClientPass.php:32-40`.
- **Why:** `getDefinitions()` + `str_starts_with()` on every build is fragile. It misses clients that are registered as aliases, and it picks up any unrelated service whose id happens to start with `pimcore.open_search_client.`.
- **How:** read the client names from the client bundle's configuration or parameter (`PimcoreOpenSearchClientExtension` builds them from its `clients` config), or tag the clients and use `findTaggedServiceIds()`. Register them with a `ServiceLocator` instead of method calls.

### I-28 — Do not build Pimcore class names by string concatenation
- **Where:** `Controller/IndexController.php:101` (`'Pimcore\Model\DataObject\\' . ucfirst($class->getName())`), `Command/IndexCommand.php:124-134` (`'\Pimcore\Model\DataObject\\' . $class . '\Listing'` with `@psalm-suppress UndefinedClass`).
- **How:** resolve through `ClassDefinition::getByName($name)` and its PHP class name (or `DataObject\ClassDefinition\Helper` / the generated `Listing` via `$className::getList()`), and guard with `class_exists()` (closes part of TODO-2 B-32).

### I-29 — `IndexController::getClassificationStoreFields()` loads all groups before filtering
- **Where:** `Controller/IndexController.php:338-347`: `$list->load();` and then `$list->setCondition('ID in (...)')` + `$list->getList()`.
- **How:** set the condition first and load once. Use a bound parameter (`setCondition('id IN (?)', [$ids])`) instead of `implode()`.

### I-30 — Tests for the bundle
- **Where:** there are no unit or functional tests for this bundle. Behat covers only the happy path of the MySQL worker.
- **What to cover first:**
  - Each condition and order renderer, MySQL and OpenSearch: the expected SQL or query DSL, including `Not*`, `null` bounds and concatenation (TODO-2 B-12, B-20, B-21).
  - `Dao::loadGroupByRelationValuesAndType()` (B-05).
  - Listing state invalidation (B-07).
  - OpenSearch `count()`, sorting and aggregations, using a mocked `Client` (B-16 to B-18).
  - The admin controller permission checks (B-03).
  - The ProcessManager listener on Carbon 3 (TODO-1 C-03).
  - The `ExpressionInterpreter` sandbox (TODO-1 C-02).
