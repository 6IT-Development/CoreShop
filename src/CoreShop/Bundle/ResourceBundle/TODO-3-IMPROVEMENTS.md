# ResourceBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
The scope and markers are the same as in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These items are about code quality, readability and logic. None of them is a functional bug on its own. Bugs are in [TODO-2-BUGS.md](TODO-2-BUGS.md).
Paths are relative to the bundle root unless noted otherwise.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Commands

### I-01 — Use `#[AsCommand]` and the `Command::SUCCESS` / `FAILURE` constants
- **Where:** `Command/{CreateDatabaseTablesCommand,DropDatabaseTablesCommand,InstallResourcesCommand}.php` `configure()`; `Resources/config/services/commands.yml`
- The name is defined twice: `setName()` in `configure()` and `command:` in the `console.command` tag. Move name and description into `#[AsCommand(name: 'coreshop:resources:create-tables', description: '…')]` and drop `setName()` / `setDescription()` and the tag attribute (autoconfiguration, or keep only the bare tag).
- `return 0;` → `return Command::SUCCESS;`.
- Help texts: "command install Resources" → "installs resources (static routes, Pimcore classes, …)"; "Create Tables." / "Drop Tables." → say which tables (the Doctrine tables of the given application).

### I-02 — Merge the create/drop commands and make them fail loudly
- **Where:** `Command/CreateDatabaseTablesCommand.php`, `Command/DropDatabaseTablesCommand.php` (about 90 identical lines)
- Extract an abstract base (`AbstractSchemaCommand`) with the shared argument/options, metadata collection and output. Only the SQL source (`getUpdateSchemaSql` vs `getDropSchemaSQL`) and the execute call differ. Fix TODO-2 **B-02** once, in the base.
- Unknown `application-name` → print the known application names and return `FAILURE`. Today it silently "succeeds" with 0 queries.
- Neither `--dump-sql` nor `--force` → print a hint and return `FAILURE` (as Doctrine's own `orm:schema-tool:*` commands do). Today it does nothing and exits 0.
- Only collect resources with `driver === CoreShopResourceBundle::DRIVER_DOCTRINE_ORM`, and use the resource's configured manager instead of always the default `EntityManagerInterface`.
- `drop-tables --force`: ask for confirmation (`$ui->confirm()`), unless `--no-interaction`.

### I-03 — `InstallResourcesCommand`: inject what it needs instead of pulling the kernel out of the application
- **Where:** `Command/InstallResourcesCommand.php:32-35`, `:63-84`
- `protected ResourceInstallerInterface $resourceInstaller` in a `final` class → `private readonly`.
- `$this->getApplication()->getKernel()` assumes a FrameworkBundle `Application` and fails with `Error` when the command runs without one (for example `CommandTester` without an application). Inject `%kernel.environment%` (`#[Autowire('%kernel.environment%')] string $environment`) instead.
- `--update-classes` is passed as a raw `mixed`. Cast it with `(bool)`.

---

## Controllers

### I-04 — `EventDispatcher`: type the event name and remove the four copies
- **Where:** `Controller/EventDispatcher.php:34-77`, `Controller/EventDispatcherInterface.php:27-45`
- `$eventName` is untyped in the interface and the implementation → `string $eventName`.
- The four methods only differ in the prefix (`''`, `pre_`, `post_`, `initialize_`). Use one private `doDispatch(string $prefix, …)`. Remove the empty `@param string            $eventName` docblocks.

### I-05 — `AsPimcoreModel`: tighter types, fewer getters, parity with YAML config
- **Where:** `Attribute/AsPimcoreModel.php`
- `?array $pimcoreController = []`, `?array $options = []` → `array` (null is never meaningful). `?bool $slug = null` → `bool $slug = false` (the extension does `?: false` anyway).
- `type`: accept only `CoreShopResourceBundle::PIMCORE_MODEL_TYPE_*`. Validate in the constructor, or use a backed enum.
- `repository: null` → default `PimcoreRepository::class`, like `Configuration.php` does for YAML.
- `adminController` is not supported for Pimcore models (TODO-2 **B-13**). Remove it or wire it.
- PHP 8.4: the 13 trivial getters can be replaced by `public private(set)` (or `public readonly`) promoted properties. Keep the getters as BC wrappers for one minor version if external code uses them.
- Add `@param class-string` for `pimcoreModel`, `interface`, `factory`, `repository`.

### I-06 — Make the stateless services `final readonly`
- **Where:** `Controller/ViewHandler.php`, `Controller/EventDispatcher.php`, `Controller/ResourceFormFactory.php`, the three commands
- `final class X { public function __construct(private Foo $foo) }` → `final readonly class X` (PHP 8.2+), or `private readonly` promoted properties in the commands (they extend `Command`, so the class itself cannot be readonly).

### I-07 — One permission check for all CoreShop admin controllers
- **Where:** `Controller/PimcoreController.php:45-68`, `Controller/ResourceController.php:59-78`
- There are two implementations with different behaviour (two different exception classes, `TokenStorageInterface` vs `TokenStorageUserResolver`, different null handling). See TODO-2 **B-05** and **B-06**.
- Put a single `protected function denyUnlessGranted(?string $permission): void` in `AdminController`, based on `UserAwareController::checkPermission()`. `ResourceController` builds the permission name from metadata. `PimcoreController` uses `getPermission()`.
- `ResourceController` then no longer needs the `TokenStorageInterface` constructor argument (remove it together with the matching argument in `AbstractDriver::addController()`).

### I-08 — `listAction()`: pagination or no pagination, but not dead variables
- **Where:** `Controller/ResourceController.php:80-89`
- `$start` / `$limit` are read and ignored ("TODO: use start and limit as soon as Admin UI has pagination"). Either implement them (`findBy([], ['id' => 'ASC'], min($limit, 500), $start)` + `total`) and adapt `Resources/public/pimcore/js/resource/list.js`, or delete the two lines.
- Add the missing permission check (TODO-1 **C-01**).

### I-09 — Return real HTTP status codes and map persistence errors
- **Where:** `Controller/ViewHandler.php:32-42`, `Controller/ViewHandlerInterface.php:25`, all `ResourceController` actions
- `ViewHandler::handle()` always returns 200, also for `success: false`. Add an `int $status = 200` parameter and use `400`/`404`/`409`/`422` for validation errors, a missing ID and constraint violations.
- `flush()` exceptions (`UniqueConstraintViolationException`, `ForeignKeyConstraintViolationException` on delete) become a raw 500 today. Catch them and return `success: false` with a translated message.
- `deleteAction()` returns `success: false` with 200 for an unknown ID. Use `findOr404()` like the other actions.

### I-10 — Read request data from the right place, with types
- **Where:** `Controller/AdminController.php:43-63` (`getParameterFromRequest()`), all actions
- The helper mirrors `Request::get()`, which Symfony 7.4 deprecates ("since Symfony 7.4, use properties `->attributes`, `query` or `request` directly instead", reproduced). As a replacement it is fine, but it returns `mixed` from three bags, which is the source of TODO-2 **B-03**.
- In each action, read from the bag the route actually uses (route attribute or query `id` for get/save/delete, POST body for add/save) with typed getters (`getInt()`, `getString()`). Add `requirements: { id: '\d+' }` to the generated routes in `Routing/ResourceLoader.php`.

### I-11 — Wrap pre-event + persist + flush + post-event in one transaction
- **Where:** `Controller/ResourceController.php:115-127`, `:151-167`, `:197-202`, `:216-221`
- If a `post_save` / `post_delete` listener throws, the entity is already flushed, but the client gets an error. Use `EntityManagerInterface::wrapInTransaction()` (the `ObjectManager` type has to become `EntityManagerInterface`, or check `instanceof`), or document that post-events run after commit.

### I-12 — `ResourceSettingsController::getConfigAction()`: stable response shape and cleaner code
- **Where:** `Controller/ResourceSettingsController.php:45-97`
- `full_stack` is only created inside the loop. Initialize it as `[]` next to `classMap` and `stack`. `coreShopRelation(s).js:53` uses `coreshop.full_stack` as a combo store, and `undefined` breaks the class editor when there is no stack.
- `$alias` holds an array and then a string. Use `[$application, $name] = explode('.', $key, 2);`.
- The result only changes on deploy. Consider caching it (or an `ETag`), because it is requested on every admin load.
- `getParameter()` (`:84`) and `parameterBag->get()` (`:56`) are mixed. Use one.

### I-13 — `folderConfigurationAction()` naming and null guard
- **Where:** `Controller/ResourceController.php:229-261`
- `$customerClassDefinition` is a leftover name. Use `$classDefinition`.
- With no `path` (or `null`, TODO-2 **B-13**), `'/' . $folderPath` is `/` and returns the root folder ID. Return `folderId: null` instead.

---

## CoreExtension (Pimcore data types)

### I-14 — Type the data-type classes and remove the blanket Psalm suppressions
- **Where:** `CoreExtension/Select.php`, `CoreExtension/CoreShopRelation.php`, `CoreExtension/CoreShopRelations.php`, `CoreExtension/Multiselect.php`, `CoreExtension/Document/Select.php`
- `Select`: `public $allowEmpty` → `public bool $allowEmpty = false`; `isAllowEmpty(): bool`; `setAllowEmpty(bool $allowEmpty): static`; `abstract protected function getRepository(): RepositoryInterface`.
- `CoreShopRelation(s)`: `getStack(): ?string`, `setStack(?string $stack): static` (Pimcore setters return `static`), `getCoreShopPimcoreClasses(): array`.
- `Multiselect` / `Select`: give the overridden methods the parent signatures (`isDiffChangeAllowed(Concrete $object, array $params = []): bool`, etc.). The untyped versions are legal, but they hide mismatches.
- Wrong docblocks: `@param string $data` / `@param null $object` / `@return int|string|null` on `getDataFor*Resource()`. Remove them or correct them to `ResourceInterface|null`.
- `@psalm-suppress InvalidReturnType, InvalidReturnStatement` on every class hides real issues (for example TODO-2 **B-15**). Remove it and fix what Psalm reports.

### I-15 — `Select`: simplify the resource checks
- **Where:** `CoreExtension/Select.php:169-208`
- `$data !== null && method_exists($data, 'getId') && is_a($data, $this->getModel())` → `$data instanceof ResourceInterface && is_a($data, $this->getModel())`. `method_exists()` also accepts class-name strings, which is not intended here.
- `getDataForQueryResource()` is a copy of `getDataForResource()`. Delegate to it.
- `preSetData()`: `if ((int) $data)` also accepts `"12abc"`. Use `is_numeric()` / `ctype_digit()`.
- `getQueryColumnType()` / `getColumnType()` return `int(11)`. MySQL 8.0.17+ deprecates display widths. Pimcore core still uses `int(11)`, so keep it for now, but track it for the MySQL 9 / MariaDB strict-mode switch.

### I-16 — `Select::preGetData()` reloads the resource on every getter call
- **Where:** `CoreExtension/Select.php:137-160`
- Every `$object->getCountry()` runs `repository->find()` and `$container->setValue()` (the "Dirty Fix" comment). The Doctrine identity map normally makes `find()` cheap, but after `ObjectManager::clear()` (TODO-2 **B-08**) each call queries the database again, and `setValue()` runs the generated setter and `preSetData()` every time.
- Skip the reload when the entity is already managed (`$em->contains($data)`). Explain in the comment which Pimcore path reads properties without the getter, so the side effect can be removed later.

### I-17 — `TempEntityManagerTrait`: document why a second EntityManager is needed, or remove it
- **Where:** `CoreExtension/TempEntityManagerTrait.php`; users listed in TODO-2 **B-01**
- Each call creates a full new `EntityManager` (new UnitOfWork) that shares the connection, configuration **and event manager** with the default one. Listeners that keep a reference to the default manager (Gedmo, optimistic lock) then act on the wrong manager. Add a docblock explaining the use case (isolated flush of price rules / store values during a Pimcore save). Check whether `$em->getUnitOfWork()->computeChangeSet()` or a `flush($entity)` replacement covers it. Return `EntityManagerInterface`, not the concrete class.

### I-18 — Dead code in `CoreShopRelation(s)`
- **Where:** `CoreExtension/CoreShopRelations.php:51-54`, `CoreExtension/CoreShopRelation.php:113-120`, `CoreShopRelations.php:109-116`
- `CoreShopRelations::enrichLayoutDefinition()` is never called. Pimcore only calls it on `LayoutDefinitionEnrichmentInterface` (`DataObject/Service.php:1347`), which `ManyToManyRelation` does not implement. Either implement the interface with its signature `enrichLayoutDefinition(?Concrete $object, array $context = []): static` and `return $this;`, or delete the method.
- `CoreShopRelation::__set_state()`: `$obj->classes = $obj->getClasses()` is a no-op (the parent getter returns `$this->classes`).
- `setClasses(array $classes)` ignores its argument by design (classes come from the stack). Add a comment so nobody "fixes" it.

### I-19 — Centralize the `\Pimcore::getContainer()` lookups
- **Where:** `CoreExtension/CoreShopRelation.php:65-81`, `CoreExtension/CoreShopRelations.php:56-89`, `CoreExtension/Document/Select.php:125-134`, all `Select` subclasses (`getRepository()` / `getModel()`)
- Pimcore instantiates data types and editables itself, so constructor injection is not available. However, the same container parameters are fetched several times per field per request (`coreshop.all.stack`, `coreshop.all.stack.pimcore_class_names`). Put them in one small static resolver with memoization, so there is one place to swap later.

### I-20 — `Document\Select` editable: fewer queries, safer input
- **Where:** `CoreExtension/Document/Select.php`
- `getConfig()` runs `findAll()` (unsorted, unlimited) on every editmode render. `isEmpty()` runs a `find()` each time. Memoize `getResourceObject()` per instance, and sort the store by the name property.
- `if ($this->resource)` treats `'0'` as empty. Use `null !== $this->resource && '' !== $this->resource`.
- `setDataFromEditmode()` / `setDataFromResource()` assign untyped data to `string|int|null`. An array or bool from a broken payload causes a `TypeError`. Type them `mixed $data` and normalize to `int|string|null`.
- `getResourceName()` builds a getter name by hand. Use `PropertyAccessor` (it also supports `isX` / `hasX`).

---

## Cache

### I-21 — `CoreCacheHandlerDecorator` is a subclass replacement, not a decorator
- **Where:** `Cache/CoreCacheHandlerDecorator.php`, `Resources/config/services.yml` (`decorates: Pimcore\Cache\Core\CoreCacheHandler`, explicit constructor args)
- The `.inner` service is never used. The YAML repeats Pimcore's constructor arguments (`pimcore.cache.pool`, `WriteLock`, `event_dispatcher`, `setLogger`). If Pimcore changes the constructor or adds a setter call, the replacement silently differs from core. Use `parent:` on the core definition, or add a test that compares the two definitions. Rename it (for example `CoreShopCacheHandler`).
- `CoreCacheHandler` is `@internal` (hence the `InternalClass` / `InternalMethod` suppressions). Record this as a known BC risk in the upgrade notes.
- `load()`: `$data->getClass()` throws when the class definition was deleted while objects are still cached. Catch it and treat the entry as a cache miss.
- Only top-level field definitions are (un)marshalled. Marshaller fields inside localized fields, object bricks or field collections are cached fully. This is consistent on both sides, but document it. See TODO-2 **B-18** for the marshalling side.
- Unmarshalling runs one repository `find()` per marshalled field per loaded object. For listings this is N×M queries. Consider batching by entity class.

---

## Config

### I-22 — Routes and service visibility
- **Where:** `Resources/config/pimcore/routing.yml`, `Resources/config/services.yml`, `Resources/config/services/{commands,controller}.yml`
- Add `methods:` to both routes (`coreshop_helper_get_nice_path`: `POST`; `coreshop_resource_class_map`: `GET`).
- `_defaults: public: true` makes every service public. Only controllers (and services fetched via `\Pimcore::getContainer()->get()`, see **I-19**) need that. Commands do not.
- `ViewHandler` / `EventDispatcher` are private, but their interface aliases inherit `public: true` from `_defaults`. That is fine, but state it explicitly so it is not accidental.

### I-23 — Docblocks and naming
- **Where:** the whole scope
- Remove `@return mixed` / `@param mixed` lines that only repeat the native types (`AdminController.php:44-47`, `Multiselect.php:38-43`).
- `EventDispatcherInterface` docblocks have odd spacing and no content. Remove them when the parameters get types (**I-04**).
- `ResourceController.php:110-124`: the repeated inline `@var ResourceInterface $resource` comments are not needed once `findOr404()` / `$form->getData()` are typed.

---

## Tests

### I-24 — Add tests; none exist for these directories
- **Where:** no `Tests/` folder in the bundle, nothing under `tests/` covers these classes
- Functional (admin kernel):
  - `list` / `get` / `save` / `add` / `delete` without the resource permission → 403 (TODO-1 **C-01**, TODO-2 **B-05**, **B-06**).
  - Missing or invalid parameters → 4xx, not 500 (TODO-2 **B-03**).
  - `get-nice-path` filters elements outside the user's workspace (TODO-2 **B-04**).
- Command: `create-tables --dump-sql` for an application never contains `DROP TABLE` for foreign tables (TODO-2 **B-02**). Unknown application → `FAILURE` (**I-02**).
- Unit:
  - `CoreShopRelation(s)` with `null` / `''` / unknown stack (TODO-2 **B-14**, **B-15**).
  - `Select::getDataForSearchIndex()` returns the selected resource's ID (TODO-2 **B-16**).
  - `AsPimcoreModel` registration produces the same metadata as the equivalent YAML (TODO-2 **B-12**, **B-13**).
- Cache: saving an object with `force: true` leaves the in-memory object unchanged, and a load returns entities again (TODO-2 **B-18**).

---

# Scan 2 (2026-10-29): `DataHub/`, `DeepCopy/`, `DependencyInjection/`, `Doctrine/`, `Event/`

Scope and evidence: see the "Scan 2" section in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md).

---

## Doctrine

### I-25 — Replace `EntityMerger` with explicit "load managed entity and copy fields" logic
- **Where:** `Doctrine/ORM/EntityMerger.php` (400 lines), only user `CoreBundle/CoreExtension/StoreValues.php:438`
- The class re-implements the `EntityManager::merge()` that ORM 3 removed, using internal `UnitOfWork` APIs, reflection on `PersistentCollection`'s private `collection` / `snapshot` properties, and the `isCascadeMerge` flag (TODO-2 **B-19**, **B-20**, **B-21**). It is hard to reason about. For example, it swaps the managed instance in the identity map (`:94-95`), but other entities still reference the old instance.
- `StoreValues` only needs to apply the submitted values of a `ProductStoreValues` (and its `productUnitDefinitionPrices`) onto the managed row. Do this explicitly (`find()` the managed entity, copy scalar fields, sync the child collection by ID), or use a form / Symfony `ObjectMapper`. Then delete the class.
- Until then: `spl_object_hash()` → `spl_object_id()` (`:56`, `:82`, `:187`); type `object $entity` instead of `@param mixed`; remove `$newData = &$newCollection;` (`:200`, the code's own `@todo`); replace the reflection on `collection` with `PersistentCollection::unwrap()`; drop the `setAccessible(true)` calls (no-op since PHP 8.1, deprecated in PHP 8.5).

### I-26 — `EntityRepository`: remove dead helpers and the extra query
- **Where:** `Doctrine/ORM/EntityRepository.php`
- `getAll()` duplicates `findAll()` and is not part of `RepositoryInterface`. `getPropertyName()` is never called anywhere in `src/`. Remove both, or document them as extension points.
- `remove()` runs `find($resource->getId())` before `remove()` to check existence. Use `$this->getEntityManager()->contains($resource)`, or just remove the entity.
- `add()` / `remove()` flush the whole unit of work on every call. Document this, or offer a `$flush = true` flag (as Sylius does).

### I-27 — `ResourceMappingDriverChain`: limited reach and overlap with `ORMMappedSuperClassSubscriber`
- **Where:** `Doctrine/ResourceMappingDriverChain.php`, `Resources/config/services/integrations/doctrine/orm.yml` (`decorates: doctrine.orm.default_metadata_driver`), `EventListener/ORMMappedSuperClassSubscriber.php`
- Only the **default** entity manager's driver is decorated. Resources configured with `options.object_manager` (supported by `AbstractDoctrineDriver::getObjectManagerName()`) on another manager never get the mapped-superclass → entity conversion. Decorate per manager, or move the logic into the existing `loadClassMetadata` subscriber (which already handles mapped superclasses for associations) and drop the decorator.
- `isset($metadata->isMappedSuperclass)` on the persistence `ClassMetadata` interface (needs a Psalm suppression) → check `$metadata instanceof \Doctrine\ORM\Mapping\ClassMetadata` first.
- `loadMetadataForClass($className, …)` → `string $className`.

---

## Event

### I-28 — `ResourceControllerEvent`: typed API
- **Where:** `Event/ResourceControllerEvent.php`
- PHP 8.3+ typed constants (`public const string TYPE_ERROR = 'error';`), or a backed enum `MessageType` used by `stop()`.
- `setMessageType($messageType)` → `string`. `getResponse(): ?Response` (TODO-2 **B-22**).
- Default `$errorCode = 500` for a listener veto is misleading. A vetoed action is a 4xx (`409` / `422`). Change the default together with B-22.
- `isStopped()` is just an alias of `isPropagationStopped()`. Keep it for BC, but document it as the veto check.

---

## DataHub

### I-29 — Remove or register the unused DataHub query types and resolvers
- **Where:** `DataHub/QueryType/{Select,Multiselect}.php`, `DataHub/Resolver/{ResourceResolver,MultiResourceResolver}.php`; `Resources/config/services/data_hub.yml` (registers only `CoreShopRelation` / `CoreShopRelations`)
- No service definition in any bundle uses these four classes. The `coreShop*` select fields are served by `Component/Resource/DataHub/QueryType/Resource(List)`. Delete them, or register them if they are meant as the generators for `CoreExtension\Select` / `Multiselect`.
- If kept: `MultiResourceResolver` runs one `find()` per ID only to return the same IDs (use one `findBy(['id' => $ids])` or return the ints directly). Both resolvers read `$value[$name]` without `??` (undefined-key warning). `protected $repository` → promoted `private readonly`. The method signatures are untyped.
- pimcore/data-hub is not installed in the reference project (`vendor/`), so the overridden `Href` / `Multihref` / `Base` / `Input` signatures could not be checked against the DataHub version for six-it/pimcore-source 2.x. Check this before the next DataHub upgrade.

---

## DeepCopy

### I-30 — `PimcoreFieldCollectionDefinition*`: typed, cheaper, explicit about copying
- **Where:** `DeepCopy/PimcoreFieldCollectionDefinitionMatcher.php`, `DeepCopy/PimcoreFieldCollectionDefinitionReplaceFilter.php`; used in `EventListener/DeepCopySubscriber.php` for version (un)marshalling
- The matcher calls `Fieldcollection\Definition::getByKey()` for **every property** of every field-collection item it sees during a version copy. Memoize per type.
- The filter looks the definition up a second time (`getDefinition()`). Pass the field definition from the matcher, or cache it.
- A matching non-chainable filter stops DeepCopy from copying the property (`vendor/myclabs/deep-copy/src/DeepCopy/DeepCopy.php:245-267`). The early `return`s in `apply()` therefore leave the clone sharing the original value, and the callback's return value is never deep-copied. Today the matcher makes those paths unreachable and the callbacks return IDs or managed entities, which is fine. Document this, or call `$objectCopier($value)` for non-marshalled values.
- `apply($object, $property, $objectCopier)` → add native types. Remove `setAccessible(true)` (no-op since 8.1, deprecated in 8.5). `protected \Closure $callback` → `private readonly`.

---

## DependencyInjection

### I-31 — Small dead code and inconsistencies in the driver / compiler-pass layer
- **Where:** `DependencyInjection/Driver/DriverProvider.php`, `Driver/Exception/*.php`, `Compiler/*.php`
- `DriverProvider::$drivers` is checked but never written, so a new driver is created on every call. Either store the instance or remove the static cache.
- `InvalidDriverException` is not used anywhere. Both exception constructors take untyped `$driver` / `$className` → `string`.
- `RegisterInstallersPass::__construct()` has an empty parameter list split over two lines (`__construct(\n        )`). Reformat it to `public function __construct()`.
- `StackRepositoryPass` checks `hasParameter('coreshop.all.stack.pimcore_class_names')` but reads `coreshop.all.stack.fqcns`. Check the parameter it reads.
- `StackRepositoryPass:61`, `PimcoreDriver:164`, `:200` hard-code `doctrine.dbal.default_connection`. Make the connection configurable or use the `Doctrine\DBAL\Connection` alias.
- `StackClassesPass:52` uses `@` error suppression. Remove it together with TODO-2 **B-24**.

### I-32 — Remove duplicated registration logic
- **Where:** `CoreShopResourceExtension::loadResources()` vs `Extension/AbstractModelExtension::registerResources()`; `CoreShopResourceExtension::loadPimcoreModels()` vs `AbstractModelExtension::registerPimcoreModels()` (identical `pimcore_class` `match` block); `DoctrineORMDriver::addRepository()` vs `PimcoreDriver::addRepository()`; Pimcore class-name derivation in `StackClassesPass:59-64` and `Controller/ResourceSettingsController.php:71-76`
- Extract one `ResourceRegistrar` (or a trait) used by both extensions, a single `PimcoreClassName::fromModel(string $fqcn, string $type)` helper, and pull `addRepository()` up into `AbstractDriver` with a `getDefaultRepositoryClass()` / `getRepositoryArguments()` hook. The duplicate class-name logic has already drifted: `StackClassesPass` uses `!empty()`, the controller uses `isset()` (TODO-2 **B-12**).

### I-33 — `DoctrineTargetEntitiesResolverPass` resolves unrelated third-party interfaces
- **Where:** `DependencyInjection/Compiler/DoctrineTargetEntitiesResolverPass.php:56-90`
- Every interface implemented by exactly one resource model becomes a resolve-target. The compiled container includes `Payum\Core\Security\CryptedInterface`, `Payum\Core\Security\TokenInterface`, `Payum\Core\Model\DetailsAwareInterface` and `DetailsAggregateInterface`. A project entity that maps an association to one of those (or a second model implementing it, which silently drops the mapping) gets surprising behaviour. Limit the automatic mapping to interfaces in the model's own vendor namespace, or to the configured `classes.interface` plus an explicit allow-list.
- The comment "Symfony 7 no longer supports the doctrine.event_subscriber tag" refers to DoctrineBundle, not Symfony. Fix the wording.

### I-34 — `ValidatorAutoMappingFixPass` is a Symfony 4 workaround; check it against Symfony 7.4 and remove it
- **Where:** `DependencyInjection/Compiler/ValidatorAutoMappingFixPass.php`; origin: commit `fd16b25dd` "[Symfony4] introduce AutoMapping Validator fix until the issue is actually sorted"
- When `framework.validation.auto_mapping` is empty, the pass replaces it with `['AppBundle\Entity' => …]`. The compiled container shows the resulting regex `{^AppBundle\\Entity$}` on three loaders. The pattern matches nothing in a current project (`App\Entity`), so the net effect is "auto mapping configured but matching nothing". With Symfony 7.4, an empty `auto_mapping` already means "only classes with `#[EnableAutoMapping]`". Verify that removing the pass changes no constraints on CoreShop entities (run the validator metadata dump before and after), then delete it.

### I-35 — `AbstractModelExtension`: types and a parameter-append helper
- **Where:** `DependencyInjection/Extension/AbstractModelExtension.php`
- Untyped `$applicationName`, `$driver`, `$bundleResources`, `$bundles` (only covered by `@param` docblocks) → `string` / `array`.
- The "read parameter or `[]`, merge, write back" pattern appears in most methods of the class. Use a private `appendToParameter(ContainerBuilder $c, string $name, array $values)`.
- `registerResources()` / `registerPimcoreModels()` reassign the `$resources` / `$models` variables they are iterating over (`:42`, `:96`). This works (`foreach` iterates a copy), but it reads like a bug. Rename them.
- Remove the commented-out `$aliasParameter` line (`:139`).

### I-36 — `Configuration`: validate what the drivers require
- **Where:** `DependencyInjection/Configuration.php`
- `pimcore.*.classes` → `isRequired()`. `classes.interface` → `isRequired()` or default `ResourceInterface::class` (TODO-2 **B-24**).
- `->prototype('scalar')` (7×) → `->scalarPrototype()`.
- `drivers` defaults to `[doctrine/orm]`, while the Pimcore driver is always used for `pimcore` models. Document this, so nobody "enables" `pimcore` in `drivers` expecting it to matter.
- `#[AsPimcoreModel]` writes keys the tree does not define (`slug`, `route`, `admin_controller`, TODO-2 **B-13**). Add them to the `pimcore` prototype so attribute and YAML go through the same schema.

---

## Tests

### I-37 — Tests for the scan 2 findings
- **Where:** none exist
- Container: compile the kernel and assert that no `…RepositoryInterface $xRepositoryFactory` alias points to a factory (TODO-2 **B-23**), that a Pimcore model without `interface` compiles (**B-24**), and that `coreshop.resources` contains resources of a bundle registered before `CoreShopResourceBundle` (**B-26**).
- `ResourceController`: a `pre_save` listener calling `stop()` prevents the flush and returns the listener's message (**B-22**).
- `EntityMerger` (as long as it exists): merge an entity with a non-merge one-to-many and initialize the collection afterwards (**B-19**). Merge `ProductStoreValues` with changed unit-definition prices (the `StoreValues` regression path).
- `ValidatorAutoMappingFixPass` removal: compare validator metadata of all CoreShop entities with and without the pass (**I-34**).

---

# Scan 3 (2026-10-29): `EventListener/`, `Form/`, `Pimcore/`, `Routing/`

Scope and evidence: see the "Scan 3" section in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md).

---

## EventListener

### I-38 — `BodyListener`: use Symfony's payload handling and limit the scope
- **Where:** `EventListener/BodyListener.php`, registration in `DependencyInjection/CoreShopResourceExtension.php`
- Symfony 6.3+ has `Request::getPayload()` (it decodes JSON or returns the form bag) and `#[MapRequestPayload]`. Admin controllers can read the body that way, and the listener can go.
- If it stays: register it with `#[AsEventListener]` or a service definition in YAML instead of building a `Definition` in the extension. Return early for sub-requests and non-admin contexts. `in_array(…, true)`. Read the `Content-Type` header once. Make the class `final`. See TODO-2 **B-34**.

### I-39 — Doctrine listeners: one registration style, cheaper metadata work
- **Where:** `EventListener/{AbstractDoctrineSubscriber,ORMMappedSuperClassSubscriber,ORMRepositoryClassSubscriber,ORMTranslatableListener}.php`, `Resources/config/services/integrations/{doctrine/orm,translation}.yml`
- The classes implement `Doctrine\Common\EventSubscriber` (`getSubscribedEvents()`), but are registered as `doctrine.event_listener` with explicit events, so `getSubscribedEvents()` is never used. Drop the interface and use `#[AsDoctrineListener(event: Events::loadClassMetadata, priority: 8192)]`.
- Registration is inconsistent: `orm.yml` listeners have no `connection` (all connections), `translation.yml` uses `connection: default`. Resources on a second entity manager (`options.object_manager`) therefore get repository classes and superclass mappings, but no translation mapping.
- `ORMMappedSuperClassSubscriber::setAssociationMappings()` calls `$metadataDriver->getAllClassNames()` (a scan of all mapping files) for **every parent class of every entity** while metadata loads, and builds a throw-away `ClassMetadata` per parent. Cache the class-name list once per request (or once per instance). This only costs time on metadata cache warmup, but it is the slowest part of `cache:clear` for large projects.
- `AbstractDoctrineSubscriber` creates its own `RuntimeReflectionService`. Use the one from the event's entity manager (`$em->getMetadataFactory()` / `getReflectionService()` in ORM 2.x), so a custom reflection service stays in effect.
- `ORMMappedSuperClassSubscriber` and `ResourceMappingDriverChain` (TODO-3 **I-27**) both handle "resource mapped superclass" mapping. Keep one.

### I-40 — `ORMTranslatableListener`: configurable column and clearer constraint handling
- **Where:** `EventListener/ORMTranslatableListener.php`
- Make the `locale` length a constant or parameter (TODO-2 **B-30**).
- The generated unique-constraint name `<table>_uniq_trans` differs from the migration's `translatable_id_locale` (`CoreBundle/Migrations/Version20230829163505.php:38`). `doctrine:schema:validate` / `diff` then reports a rename for the tables created by that migration. Align the name, or let the listener detect the existing index by columns only (it already does) and keep its name.
- `mapTranslatable()` fetches `<alias>_translation` with `get()`, which throws if a resource declares `translation` but the translation resource was not registered. Catch it and raise a clear configuration exception.
- `postLoad` runs the locale assigner for every translatable entity load. Document that the locale comes from the `LocaleContextInterface` (in CLI without a request this is the default locale).

---

## Form

### I-41 — Form types: lazy choices, lazy locales, documented defaults
- **Where:** `Form/Type/*.php`, `Form/Helper/ErrorSerializer.php`
- `PimcoreClassChoiceType::configureOptions()` loads `ClassDefinition\Listing` every time the options are resolved, even when `choices` is overridden. Use `'choice_loader' => ChoiceList::lazy($this, fn () => …)`.
- `ResourceTranslationsType` reads the locales in its **constructor**. In long-running processes (Messenger workers, FrankenPHP/RoadRunner workers) a language added in Pimcore settings is not picked up until restart. Read them in `configureOptions()` closures (`'entries' => fn () => …`, normalizer).
- `ResourceTranslationsType` SUBMIT listener: guard `null === $translations` before `foreach`.
- `AbstractResourceType` sets `allow_extra_fields: true` and `csrf_protection: false` for every resource form. Add a comment that CSRF is covered by Pimcore's admin CSRF header (`CsrfProtectionListener`), and consider `allow_extra_fields: false` for admin forms so typos in JS field names are reported instead of silently ignored.
- `ErrorSerializer`: `protected TranslatorInterface $trans` in a `final` class → `private readonly`. After TODO-2 **B-31**, the translator is no longer needed at all.
- The transformers: declare `@implements DataTransformerInterface<Asset, int>` (Symfony 7 templates), make them `final`, and use `int` instead of `%d` with a `mixed` value in the asset exception message.
- `PimcoreResourceSelectionType`: `protected RepositoryInterface $repository` → `private readonly` (the subclasses only pass it to the parent constructor). Use `IntegerType` as the parent (TODO-2 **B-32**).

---

## Pimcore

### I-42 — Merge `PimcoreRepository` / `PimcoreDaoRepository` duplication and add types
- **Where:** `Pimcore/PimcoreRepository.php`, `Pimcore/PimcoreDaoRepository.php`, `Pimcore/Repository/StackRepository.php`
- `getList()` exists twice with identical code (`PimcoreDaoRepository.php:52-71`, `PimcoreRepository.php:46-65`). Keep one.
- `forceFind()` casts to `int` in both classes. Keep it in the base.
- Untyped methods (`getClassName()`, `getList()`, `findAll()`, `find($id)`, `forceFind($id, …)`, `findBy(…, $limit, $offset)`, `normalizeCriteria($criteria)`) → native types matching `PimcoreRepositoryInterface` / Doctrine `ObjectRepository`.
- `add()` / `remove()` throw a plain `\Exception` with `__CLASS__` repeated in `__METHOD__` (`"…PimcoreDaoRepository:…PimcoreDaoRepository::add not supported"`). Use `\BadMethodCallException(sprintf('%s() is not supported', __METHOD__))`.
- `StackRepository` wraps class names in double quotes (`'"' . $name . '"'`) inside SQL. That breaks with the `ANSI_QUOTES` SQL mode. Use `$this->connection->quote()` or a parameterised `IN (?)`.
- `StackRepository::getClassIds()` calls `$stackClass::classId()` per call. Cache it.
- `normalizeCriteria()` docblock describes `conditionVariables`, but the code reads `variable`. Fix the doc.

### I-43 — `Pimcore\ObjectManager`: smaller and honest
- **Where:** `Pimcore/ObjectManager.php`
- `remove()` repeats the `Concrete` class-name lookup that `getResourceClassName()` already does (`:69-73`).
- `find($className, $id)` calls `$className::getById()` without checking the class. Add `Assert::methodExists()` or type `class-string<Concrete>`.
- Replace the many "Not implemented" methods with a clear `\BadMethodCallException` (TODO-2 **B-29**) and list the supported subset in the class docblock.
- Remove the `@psalm-suppress InvalidReturnType` / `InvalidReturnStatement` by giving `find()` a correct generic return type.

### I-44 — Rename `Pimcore\CacheMarshallerInterface`
- **Where:** `Pimcore/CacheMarshallerInterface.php`; Pimcore has `Pimcore\Cache\Core\CacheMarshallerInterface` with a different contract (`marshalForCache(): mixed`, no arguments)
- Two interfaces with the same short name and different signatures, both used in the cache code path (`CoreCacheHandler::storeCacheData()` and `DeepCopySubscriber`), are easy to mix up in `use` statements. Rename the CoreShop one (for example `FieldDefinitionCacheMarshallerInterface`) and keep a deprecated alias.

### I-45 — `ResourceFieldGetter`: readability
- **Where:** `Pimcore/GridColumnConfig/ResourceFieldGetter.php`
- Only `$children[0]` is used. Document that, or reject more than one child in the JS config.
- Duplicate "call getter if it exists, map resource to ID" logic in three places. Extract a private `readAttribute(object $o): mixed`.
- `$result->label = $this->label` and the `stdClass` result: use Pimcore's `ResultContainer` (the interface already allows it).

---

## Routing

### I-46 — `ResourceLoader`: use the configured admin prefix and remove dead options
- **Where:** `Routing/ResourceLoader.php`, `Routing/Configuration.php`
- `$rootPath = '/admin/' . …` is hard-coded, while the bundle's own routes use `%coreshop.admin.route.base%` (`/admin/coreshop`). Resource routes therefore do not follow a changed admin prefix, and could end up outside the admin firewall pattern. Inject the parameter.
- `Configuration` defines `path` and `identifier`, but the loader ignores them (the code that used them is commented out, `:92-93`). `getRouteName()` reads `$configuration['section']`, which the config tree does not allow, so `processConfiguration()` would reject it. Remove all three, or implement them.
- `alias` is not `isRequired()`. Without it, `$this->modelRegistry->get($configuration['alias'])` gets `null` → `TypeError` while routes are loading. Make it required.
- `no_default_routes` is a `scalarNode` with `defaultFalse()` → `booleanNode`.
- `additional_routes` entries: `path` and `action` are optional. A missing `action` produces `_controller: …::Action`, and a missing `methods` produces a route that accepts every method. Make `path`/`action` required and default `methods` to `['GET']`.
- `->prototype('scalar')` → `->scalarPrototype()`.
- Add `requirements` support (and pass it through) so additional routes with placeholders can be constrained.

### I-47 — `RouteFactory`: types
- **Where:** `Routing/RouteFactory.php`, `Routing/RouteFactoryInterface.php`
- The interface declares `string|array $schemes` / `$methods`, but `$path`, `$host`, `$condition` are untyped, and the implementation drops the types of `$schemes` / `$methods` entirely. Use `string $path, …, ?string $host = '', string|array $schemes = [], string|array $methods = [], ?string $condition = ''`, matching `Symfony\Component\Routing\Route::__construct()`. Remove the parameter docblocks that only repeat the types.
