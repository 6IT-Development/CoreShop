# ResourceBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
The scope and markers are the same as in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md): **(dependent)** means the root cause or part of the fix is outside the scanned directories, **(reproduced)** means it was run with PHP 8.4.27 against the vendored libraries, and **(plausible)** means the trigger depends on data or configuration.
Paths are relative to the bundle root unless noted otherwise. `vendor/` means `../pimcore2teszt10211524/vendor/`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Symfony 7.4 / PHP 8.4 / Doctrine

### B-01 — `TempEntityManagerTrait` uses `EntityManager::create()`, which is deprecated and removed in Doctrine ORM 3 — (reproduced) (dependent)
- **Where:** `CoreExtension/TempEntityManagerTrait.php:28`. Used by `CoreBundle/CoreExtension/StoreValues.php`, `ProductBundle/CoreExtension/{ProductSpecificPriceRules,ProductUnitDefinitions}.php`, `ProductQuantityPriceRulesBundle/CoreExtension/ProductQuantityPriceRules.php`.
- **Problem:** `vendor/doctrine/orm/src/EntityManager.php` `create()` is tagged `@deprecated Use DriverManager::getConnection() to bootstrap the connection and call the constructor` (ORM 2.20.13) and triggers a deprecation on every call. In ORM 3 the method does not exist, so every save of a product / store-values / price-rule field fails with `Call to undefined method`. `composer.json` allows `doctrine/orm: ^2.9` only, so this is not a blocker today, but it is the first thing that breaks on the ORM 3 upgrade.
- **Fix:** `return new EntityManager($entityManager->getConnection(), $entityManager->getConfiguration(), $entityManager->getEventManager());` (the constructor exists in 2.x and 3.x). See TODO-3 **I-17** for whether a temp manager is needed at all.

### B-02 — The create/drop table commands pass the deprecated `$saveMode` flag; just removing it would make `create-tables` drop every non-CoreShop table — (dependent)
- **Where:** `Command/CreateDatabaseTablesCommand.php:86` (`getUpdateSchemaSql($metadatas, true)`), `:107` (`updateSchema($metadatas, true)`). Doctrine: `vendor/doctrine/orm/src/Tools/SchemaTool.php:941-1033`.
- **Problem:**
  - Passing `$saveMode` triggers `Passing $saveMode to …() is deprecated and will not be possible in Doctrine ORM 3.0.` In ORM 3 the parameter is removed.
  - **Trap:** `getUpdateSchemaSql()` compares `$toSchema` (only the selected CoreShop classes) against `createSchemaForComparison()`, which introspects the **whole** database when no schema asset filter is set (`:1016-1018`). `$saveMode = true` is the only thing that currently filters out the `DROP TABLE` statements for all Pimcore and other bundle tables. Removing the argument (the obvious fix for the deprecation) would make `coreshop:resources:create-tables <app> --force` drop the rest of the database.
- **Fix:** Do not rely on `$saveMode`. Build the diff only for the tables in `$toSchema`: for example introspect each target table with `$schemaManager->tablesExist()` / `introspectTable()` into a `Schema` with just those tables and compare that, or keep `getCreateSchemaSql()` for missing tables and `compareTables()` for existing ones. Add a test that asserts no `DROP TABLE` statement for tables outside the application (TODO-3 **I-24**).

### B-03 — Missing or wrongly typed request parameters raise `TypeError` (HTTP 500) under `strict_types` — (reproduced)
- **Where:**
  - `Controller/ResourceController.php:181-183` — `strlen($name)` with `name` missing → `strlen(): Argument #1 ($string) must be of type string, null given`.
  - `Controller/ResourceController.php:104` — `findOr404($this->getParameterFromRequest($request, 'id'))`: `findOr404(int|string $id)` gets `null` (no `id`) or an array (`id[]=1`) → `TypeError`. `getAction` (`:95`) casts to `int`, `saveAction` does not.
  - `Controller/ResourceController.php:67` — `get_class($user)` with no token / anonymous token (`$user === null`) → `get_class(): Argument #1 ($object) must be of type object, null given` instead of the intended exception.
  - `Controller/ResourceSettingsController.php:31` — `json_decode($this->getParameterFromRequest($request, 'targets'), true)` without `targets` → `json_decode(): Argument #1 ($json) must be of type string, null given`. With invalid JSON it returns `null` and `foreach` warns. Elements without `type`/`id` keys raise `Undefined array key`.
- **Problem:** all of these are client errors but surface as 500s with stack traces in the log. All three internal-function errors were reproduced on PHP 8.4.27 with `strict_types=1`.
- **Fix:** validate input at the top of each action and return `400` (or throw `BadRequestHttpException`): `$request->request->getString('name')`, `$request->attributes->getInt('id')` / `query->getInt()`, `json_decode(..., flags: JSON_THROW_ON_ERROR)` in a `try`, and check `is_array($target) && isset($target['type'], $target['id'])`.

---

## Controllers

### B-04 — `getNicePathAction()` returns paths of elements the user is not allowed to see (hardening)
- **Where:** `Controller/ResourceSettingsController.php:29-43`, route `Resources/config/pimcore/routing.yml:1-5`.
- **Problem:** The action resolves `{type, id}` pairs to full paths with no `isAllowed()` check, so workspace restrictions do not apply. `type` is not validated. Pimcore's own `admin-ui-classic-bundle/src/Controller/Admin/ElementController.php` `getNicePathAction()` has the same behaviour, so this is hardening rather than a CoreShop-specific hole. See also **B-03** for the crash on bad input.
- **Fix:** skip elements where `!$element->isAllowed('list')`; validate `type` against `object|asset|document`; add `methods: [POST]` to the route.

### B-05 — `PimcoreController::isGrantedOr403()` throws the Finder component's `AccessDeniedException`: a missing permission becomes a 500, not a 403 — (reproduced) (dependent)
- **Where:** `Controller/PimcoreController.php:27` (`use Symfony\Component\Finder\Exception\AccessDeniedException;`), `:45-63`. Subclasses that rely on it: `OrderBundle/Controller/OrderCreationController.php:332` (`coreshop_permission_order_create`), `OrderEditController.php`, `OrderController.php`.
- **Problem:**
  - `Symfony\Component\Finder\Exception\AccessDeniedException` extends `UnexpectedValueException → RuntimeException` (reproduced). It is a filesystem exception. It is not a Security `AccessDeniedException` or an `HttpException`, so the kernel turns it into a **500**. The admin UI shows a generic server error instead of "access denied". The request is still blocked, so this fails closed.
  - `getAdminUser()` returns `UserProxy|User|null`. With `null` (session expired between firewall and controller, or a non-Pimcore token), `$user->isAllowed()` is called on `null` → `Error`.
- **Fix:** replace the body with `$this->checkPermission($this->getPermission())` from `Pimcore\Controller\UserAwareController` (it handles `null` and throws `AccessDeniedHttpException`), or throw `$this->createAccessDeniedException()`. Drop the Finder import. See TODO-3 **I-07** for sharing this with `ResourceController`.

### B-06 — `ResourceController::isGrantedOr403()` turns a non-Pimcore or missing user into a 500
- **Where:** `Controller/ResourceController.php:59-78`
- **Problem:** if the token user is not a `Pimcore\Security\User\User` (for example another firewall's user, or a token without a user), a `RuntimeException` is thrown (500). With no user at all, `get_class(null)` throws `TypeError` first (**B-03**). Both should be a 403.
- **Fix:** `if (!$user instanceof User) { throw new AccessDeniedException(); }`. Better: use the injected `TokenStorageUserResolver` like `PimcoreController` does, or `checkPermission()` (TODO-3 **I-07**).

### B-07 — `addAction()` writes every POST field straight into the entity through `setValue()`, with no form and no validation
- **Where:** `Controller/ResourceController.php:177-205`. Setter dispatch: `Component/Resource/Model/SetValuesTrait.php:32-40`.
- **Problem:**
  - `foreach ($request->request->all() as $key => $value) { $resource->setValue($key, $value); }` calls `set<Key>()` for **any** key the client sends. The form type and the validation constraints (used by `save` and `clone`) are skipped, so a resource can be persisted in a state the form would reject (for example a duplicate identifier, an invalid code or an empty required field). The next save then fails, or a DB constraint error causes a 500.
  - Request values are strings. Typed setters (`setPosition(int)`, `setActive(bool)`, entity setters) throw `TypeError` (500) under `strict_types` in the entity.
  - `name` is set twice (`:190` and again in the loop).
  - `SetValuesTrait.php:37-39` (dependent): the `o_` compatibility branch checks the stripped method name but calls the unstripped `$method`, so it calls an undefined method.
- **Fix:** create through the form like `cloneAction()` does (`$this->resourceFormFactory->create(...)->submit($request->request->all(), false)` + `isValid()`), or whitelist `name` only. Fix the `SetValuesTrait` branch to call the stripped name.

### B-08 — `saveAction()` / `cloneAction()` call `ObjectManager::clear()`, which detaches every managed entity in the request — (plausible)
- **Where:** `Controller/ResourceController.php:120`, `:160`
- **Problem:** `clear()` without arguments detaches **all** entities of the manager, not only the saved one. Anything else that holds a managed entity in the same request is affected: post-save listeners (`dispatchPostEvent('save', …)` runs after the clear), optimistic-lock and Gedmo listeners, the store/currency context services, and other CoreShop listeners. Those listeners then work with detached objects. A later `flush()` with relations to them throws `A new entity was found through the relationship …`, or silently skips changes. Also, `Select::preGetData()` (`CoreExtension/Select.php:137-160`) then has to re-query every resource.
- **Fix:** reload only the saved resource: `$this->manager->refresh($resource)` (or `clear()` is not needed at all, because the form already updated the managed instance). If the goal is to drop stale translations, refresh the collection explicitly.

### B-09 — `cloneAction()` builds the new name as `name + time()`: it collides within the same second and breaks for translated names — (plausible)
- **Where:** `Controller/ResourceController.php:153-155`
- **Problem:**
  - Two clones in the same second get the same name. That is a problem where `name` is unique.
  - `method_exists($resource, 'getName')` is also true for translatable resources, whose `getName()` reads the current translation. Without a translation for the current locale, it returns `null` and the clone is named only `1730000000`. `setValue('name', …)` then writes into the current-locale translation only.
- **Fix:** use a deterministic copy suffix (`sprintf('%s (copy %d)', …)` with an existence check), or leave renaming to the form data the admin UI already sends.

### B-10 — `ResourceController` requires the concrete final `ViewHandler`, while the DI wiring passes `ViewHandlerInterface` — (plausible)
- **Where:** `Controller/ResourceController.php:46` (`ViewHandler $viewHandler`), wiring `DependencyInjection/Driver/AbstractDriver.php:83` (`new Reference(ViewHandlerInterface::class)`), alias `Resources/config/services/controller.yml:5`.
- **Problem:** the parent `AdminController` and `PimcoreController` accept the interface. Overriding or decorating `ViewHandlerInterface` (the reason the alias exists) makes **every** resource controller fail with `TypeError` at instantiation, so the whole CoreShop admin breaks.
- **Fix:** type the parameter as `ViewHandlerInterface`.

### B-11 — `AdminController` gets the full `service_container` instead of a service locator, and resource controllers never get the Pimcore helpers injected — (plausible)
- **Where:** `Controller/AdminController.php:30-36`, `:71-74`; `Resources/config/services.yml` (`AdminController` definition: `@service_container`, `setTokenResolver` call); `DependencyInjection/Driver/AbstractDriver.php:73-94` (resource controllers: plain `Definition`, no parent, no calls, not autowired). Vendor: `six-it/pimcore-source/lib/Controller/UserAwareController.php` and `Traits/JsonHelperTrait.php` (`#[Required]` setters).
- **Problem:**
  - `$this->container` is the real container. `AbstractController` helpers fetch their services from it by id (`security.authorization_checker`, `security.token_storage`, `twig`, `form.factory`, `serializer`, `security.csrf.token_manager`…). Most of those are **private** in Symfony 7.4, so `isGranted()`, `getUser()`, `createForm()`, `render()`, `isCsrfTokenValid()` throw `ServiceNotFoundException`. The `container.service_subscriber` tag and the `getSubscribedServices()` override have no effect, because the explicit `@service_container` argument is never replaced by the locator.
  - Resource controllers are built in `AbstractDriver::addController()` without autowiring and without `setTokenResolver` / `setPimcoreSerializer` calls. In any `ResourceController` subclass, `getAdminUser()`, `checkPermission()`, `adminJson()` and `jsonResponse()` therefore throw `Typed property … $tokenResolver / $pimcoreSerializer must not be accessed before initialization`. No current subclass calls them, so this is latent.
- **Fix:** drop the `$container` constructor argument and let Symfony call `setContainer()` with the subscriber locator (add `->addMethodCall('setContainer', [new Reference('service_container')])`, or better `->setAutowired(true)` / `->setAutoconfigured(true)` so the `#[Required]` setters run). In `AbstractDriver::addController()` add the same `setTokenResolver` / `setPimcoreSerializer` calls as the `AdminController` parent, or make resource controllers a `ChildDefinition` of `AdminController` like `PimcoreDriver::addPimcoreController()` does.

### B-12 — Models registered with `#[AsPimcoreModel]` appear in the admin class map with an empty class name — (plausible)
- **Where:** `Controller/ResourceSettingsController.php:71-76`; `DependencyInjection/CoreShopResourceExtension.php` `autoRegisterPimcoreModels()` (`'pimcore_class_name' => ''`).
- **Problem:** `isset($definition['classes']['pimcore_class_name'])` is `true` for `''`, so the class map entry becomes `''` instead of the name derived from the model FQCN. The admin JS (`Resources/public/pimcore/js/resource.js`) then cannot open, create or filter objects of that model. Models configured in YAML do not set the key, so they are not affected.
- **Fix:** use `!empty(...)` / `($definition['classes']['pimcore_class_name'] ?? '') !== ''` in the controller, and stop writing `''` in `autoRegisterPimcoreModels()` (or derive the real name there).

### B-13 — `#[AsPimcoreModel]` bypasses the bundle configuration, so its `null` defaults reach the drivers as real values — (plausible)
- **Where:** `Attribute/AsPimcoreModel.php:27-41`; `DependencyInjection/CoreShopResourceExtension.php` `autoRegisterPimcoreModels()` (runs after `processConfiguration()`); `DependencyInjection/Driver/Pimcore/PimcoreDriver.php:57-59`, `:110-140`; `Component/Resource/Metadata/Metadata.php:95-98` (`hasParameter()` = `array_key_exists`).
- **Problem:**
  - The attribute array is merged into the already-processed config, so `Configuration` defaults and validation never apply to it.
  - `path: null` (the default) is stored as `'path' => null`. `hasParameter('path')` is then `true`, so `addPimcoreClass()` registers `<app>.folder.<name> = null` and a `null` entry in `coreshop.resource.folders` / `<app>.folders`. Consumers of those parameters (folder creation, `ResourceController::folderConfigurationAction()` → `DataObject::getByPath('/')` = the root folder) get `null` or the root folder.
  - `adminController` is written as `classes.admin_controller`, a key the `pimcore` resource config does not know. It is silently ignored.
  - `type` is any string. A typo produces a resource that matches none of the `match` arms in `loadPimcoreModels()` and is treated as an object.
- **Fix:** run the attribute data through the same `Configuration` tree (build an array and pass it with the YAML configs to `processConfiguration()`), or at least drop `null` values before merging. Validate `type` (TODO-3 **I-05**).

---

## CoreExtension (Pimcore data types)

### B-14 — `CoreShopRelations::$stack` has no default: reading it before `setStack()` is a fatal `Error` — (reproduced)
- **Where:** `CoreExtension/CoreShopRelations.php:28` (`public string|null $stack;`), read in `getStack()`, `getClasses()`, `getPhpdoc*()`, `__set_state()` (`:113`).
- **Problem:** `new CoreShopRelations()` followed by `getClasses()`, and `CoreShopRelations::__set_state([...])` without a `stack` key, both throw `Typed property …::$stack must not be accessed before initialization` (reproduced). An uninitialized property is also left out of Pimcore's `var_export` definition files, so a definition saved in that state can never be loaded again. Every request that touches the class then fails. The class editor normally sends `stack` (empty or not), so the trigger is programmatic creation (installers, migrations, JSON class imports without `stack`). `CoreShopRelation` declares `= null` and is not affected.
- **Fix:** `public ?string $stack = null;`.

### B-15 — An empty or unknown `stack` produces invalid generated PHP and `TypeError`s in `CoreShopRelation(s)` — (plausible)
- **Where:** `CoreExtension/CoreShopRelation.php:69-81` (`'?\\' . $stack[$this->stack]`), `:102-110` (`fixAllowedTypes($this->getCoreShopPimcoreClasses()[$this->stack], …)`); `CoreExtension/CoreShopRelations.php:71-89`, `:91-107`.
- **Problem:**
  - `getParameterTypeDeclaration()` / `getPhpdoc*()` index `coreshop.all.stack` with `$this->stack`. For `null` / `''` (field added but no stack chosen; combo sends `''`) or a stack whose bundle was removed, this raises `Undefined array key` and returns `'?\'`. Pimcore writes that into the generated `DataObject\<Class>` getter and setter signatures (`?\ $value`), which is a **parse error**, so the class can no longer be loaded.
  - `setClasses()` checks only `null === $this->stack`. For `''` or an unknown stack it passes `null` to `Element\Service::fixAllowedTypes(array …)` → `TypeError`. `CoreShopRelations::getClasses()` runs `foreach` over `null`.
- **Fix:** treat `''` like `null`. Look up the stack with `?? null`. When it is missing, return `null` from the type-declaration methods (Pimcore then omits the type) and `[]` from `getClasses()`, and log a warning, or reject the definition on save with a clear message.

### B-16 — `Select::getDataForSearchIndex()` checks the container instead of the field value: search index gets the wrong ID — (plausible)
- **Where:** `CoreExtension/Select.php:225-232`. Pimcore: `ClassDefinition/Data.php:139-148` (`getForCsvExport()` → `getDataFromObjectParam($object) ?? ''`).
- **Problem:** `$object` is the **container** (the Concrete / fieldcollection / brick / localizedfield), not the selected resource. CoreShop's Pimcore models (addresses, customers, orders, products…) implement `ResourceInterface`, so for them the method returns the **container's own ID**. The backend search therefore indexes, for example, an address's own ID as its "country". For other containers it falls back to `getForCsvExport()`, which returns the entity object and depends on `__toString()` being present (`Data.php` has no `strict_types`). All twelve `Select` subclasses (Country, State, Currency, Store, Carrier, TaxRate, TaxRuleGroup, CartPriceRule, PaymentProvider, ProductUnit, Filter, AddressIdentifier) inherit this.
- **Fix:** `$data = $this->getDataFromObjectParam($object, $params); return $data instanceof ResourceInterface ? (string) $data->getId() : '';`.

### B-17 — `Multiselect::preGetData()` is never called, because Pimcore's `Multiselect` does not implement `PreGetDataInterface`
- **Where:** `CoreExtension/Multiselect.php:44-57`; vendor `ClassDefinition/Data/Multiselect.php:34-45` (interface list without `PreGetDataInterface`). Subclasses: `CountryMultiselect`, `CurrencyMultiselect`, `PaymentProviderMultiselect`.
- **Problem:** generated getters only call `preGetData()` on field definitions that implement `PreGetDataInterface`. The intended "`null` → `[]`" normalization therefore never happens, and callers that `foreach`/`count()` the getter result can get `null`.
- **Fix:** implement `Data\PreGetDataInterface` with the interface signature `preGetData(mixed $container, array $params = []): mixed` (then regenerate the classes), or remove the method and make callers null-safe.

---

## Cache

### B-18 — Cache marshalling for CoreShop fields mutates the live object and switches off deep copying of the whole object graph — (reproduced, mechanism) (dependent)
- **Where:** `EventListener/DeepCopySubscriber.php:105-140` (type filter for `Concrete` when `source === CoreCacheHandler::storeCacheData`), counterpart `Cache/CoreCacheHandlerDecorator.php:30-53`. Vendor: `myclabs/deep-copy/src/DeepCopy/DeepCopy.php:137-142`, `six-it/pimcore-source/lib/Cache/Core/CoreCacheHandler.php:480-510`.
- **Problem:**
  - DeepCopy returns the result of the first matching **type filter** directly, without copying (`recursiveCopy()` → `return $filter->apply($var)`). The CoreShop `ReplaceFilter` matches every `Concrete`, including the root object passed to `storeCacheData()`. So it receives the **original** instance, overwrites its `CacheMarshallerInterface` fields with marshalled values (IDs/arrays instead of entities, via `setObjectVar()`), and returns that same instance. Reproduced with DeepCopy from vendor: `copy($o) === $o` and the original is mutated.
  - Effect 1: after a cache write, the in-memory object (still in Pimcore's runtime cache) holds marshalled data in `StoreValues`, `MoneyCurrency`, `ProductSpecificPriceRules`, `ProductUnitDefinition(s)`, `ProductQuantityPriceRules` and every `Select` field. Queued writes happen in `Pimcore::shutdown()` (`register_shutdown_function`, `lib/Kernel.php:166-177`), so only other shutdown functions running after it see the mutated object. Forced writes (`pimcore:cache:warming`, `Cache::setForceImmediateWrite(true)`, CLI with `handleCli`) mutate objects that code is still using.
  - Effect 2: because the root `Concrete` is never deep-copied, the filters Pimcore adds afterwards never run for anything inside the object: `SetDumpStateFilter` on nested elements and Pimcore's own `CacheMarshallerInterface` replacement (for example `BlockElement::marshalForCache()`). Nested data is therefore serialized unmarshalled.
- **Fix:** marshal a **copy**. Options: (a) override `storeCacheData()` (or `prepareCacheData()`) in `CoreCacheHandlerDecorator`: let the parent deep-copy run first, then marshal the copy (the class already extends `CoreCacheHandler`), and remove the type filter from `DeepCopySubscriber`; or (b) replace the type filter with a property filter plus matcher (like `PimcoreFieldCollectionDefinitionReplaceFilter`) so that DeepCopy keeps recursing and the callback only gets copied property values. Add a test: after `Cache::save($product, …, force: true)`, `$product->getStoreValues()` still returns entities.

---

# Scan 2 (2026-10-29): `DataHub/`, `DeepCopy/`, `DependencyInjection/`, `Doctrine/`, `Event/`

Scope and evidence: see the "Scan 2" section in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). "Compiled container" means `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml`.

---

## Doctrine

### B-19 — `EntityMerger` builds `PersistentCollection`s with a class-name string or the wrong metadata: loading them later crashes — (reproduced, mechanism) (dependent)
- **Where:** `Doctrine/ORM/EntityMerger.php:322` (`new PersistentCollection($this->em, $assoc['targetEntity'], …)`: a **string**), `:171-175` (MANY_TO_MANY: passes the **owner's** `$class` instead of the target's metadata). Only user: `CoreBundle/CoreExtension/StoreValues.php:438`. Doctrine: `vendor/doctrine/orm/src/PersistentCollection.php:107-113`, `:140-205`.
- **Problem:**
  - `cascadeMerge()` resets every to-many association that is **not** `cascade merge` to an uninitialized `PersistentCollection` whose type class is the target **class name**. `PersistentCollection::__construct()` does not type `$class`, so nothing fails at that point. When the collection is initialized later, the hydrator calls `hydrateAdd()`. For a bidirectional one-to-many, that runs `$this->typeClass->reflFields[...]` → `Error: Call to a member function setValue() on null`, and `getTypeClass()` → `TypeError: Return value must be of type ClassMetadataInfo, string returned`. Both were reproduced with ORM 2.20.13 on PHP 8.4.27.
  - For MANY_TO_MANY, the new collection carries the owner's metadata, so `matching()` and `indexBy` handling use the wrong class.
  - This is latent today: `ProductStoreValues` has one to-many (`productUnitDefinitionPrices`, `cascade-all`, so it includes merge) and no many-to-many. Any new non-merge to-many association on a merged entity, or a project-level extension of `ProductStoreValues`, triggers it.
- **Fix:** pass `$this->em->getClassMetadata($assoc['targetEntity'])` in both places (as `getData()` already does at `:400`).

### B-20 — `EntityMerger` looks up the identity map with the wrong class/metadata, which creates duplicate instances for inherited or composite-key entities — (plausible) (dependent)
- **Where:** `Doctrine/ORM/EntityMerger.php:109`, `:336` (`tryGetById($id, $class->getName())`); `:209` (`flattenIdentifier($class, $origId)`, where `$class` is the owner, not `$assocClass`).
- **Problem:** Doctrine keys the identity map by **root** entity name. For an entity in an inheritance hierarchy, `getName()` misses the managed instance. The merger then loads a second copy with `find()`, or does not remove the stale one from the identity map before `__load()`. `flattenIdentifier()` with the owner's metadata produces a wrong ID for targets with a foreign-key identifier. CoreShop's own merged entities use simple integer IDs and no inheritance, so this is latent.
- **Fix:** use `$class->rootEntityName` / `$relatedEntityClass->rootEntityName`, and `flattenIdentifier($assocClass, …)`.

### B-21 — Doctrine ORM 3 blockers in `EntityMerger` and `EntityRepository` — (dependent)
- **Where:** `Doctrine/ORM/EntityMerger.php:300`, `:306` (`$assoc['isCascadeMerge']`), `:94-95` (`removeFromIdentityMap()`, `registerManaged()`, marked "INTERNAL" in `UnitOfWork.php:3585-3596`), `:27` (`IdentifierFlattener`, an ORM utility); `Doctrine/ORM/EntityRepository.php:29-30`, `:36-37` (`$this->_em`).
- **Problem:**
  - ORM 3 removed `merge()` and the `merge` cascade, so the `isCascadeMerge` mapping key no longer exists (`ClassMetadataInfo.php:1842` is the 2.x source). In ORM 3 association mappings are objects, and array access on them is deprecated.
  - ORM 3 no longer exposes the protected `EntityRepository::$_em`. Use `getEntityManager()` (available since 2.x).
  - `EntityMerger` is a re-implementation of the removed `EntityManager::merge()` on top of internal `UnitOfWork` APIs. Any ORM minor release can break it. Together with TODO-2 **B-01** (`EntityManager::create()`) this is the ORM 3 upgrade work for this bundle.
- **Fix:** `$this->_em` → `$this->getEntityManager()` now (no behaviour change). For `EntityMerger`, see TODO-3 **I-25** (replace it with explicit "load managed entity + copy fields" logic in `StoreValues`).

---

## Event

### B-22 — `ResourceControllerEvent::stop()` is ignored by the admin `ResourceController`: listeners cannot veto save, create or delete
- **Where:** `Event/ResourceControllerEvent.php:44-52` (`stop()`), `:109-112` (`getResponse()`); `Controller/EventDispatcher.php` (all methods return `void`); `Controller/ResourceController.php:115`, `:151`, `:197`, `:216` (pre-events, no check afterwards). For comparison, `FrontendBundle/Controller/CheckoutController.php:83-86` does check `isStopped()` / `hasResponse()` on its own events.
- **Problem:**
  - The event offers a veto API (`stop($message, $type, $parameters, $errorCode)`, `setResponse()`), but the controller never checks it. A `pre_save` / `pre_delete` / `pre_create` listener that calls `$event->stop('…')` only stops other listeners. The entity is still persisted or removed, and the client gets `success: true`. No core listener uses `stop()` today (`NotificationRuleEventListener`, `PriceRuleUpdateEventListener`, `RecursiveVariantCacheInvalidationEventListener` only read the subject), so this mostly affects project listeners written against the documented API.
  - `getResponse(): Response` returns the nullable `$response` → `TypeError` when called without a preceding `hasResponse()`.
- **Fix:** let `EventDispatcherInterface::dispatchPreEvent()` return the event. In each action: `if ($event->isStopped()) { return $this->viewHandler->handle(['success' => false, 'message' => $translator->trans($event->getMessage(), $event->getMessageParameters())], status: $event->getErrorCode()); }`, and return `$event->getResponse()` when one is set. Make `getResponse(): ?Response`.

---

## DependencyInjection

### B-23 — Repository-factory services get autowiring aliases for the **repository's** interfaces: 308 wrong aliases in the compiled container — (reproduced)
- **Where:** `DependencyInjection/Driver/Doctrine/DoctrineORMDriver.php:78-109` (`foreach (class_implements($repositoryClass) …)`), `DependencyInjection/Driver/Pimcore/PimcoreDriver.php:179-212` (same loop).
- **Problem:** `addRepositoryFactory()` registers `registerAliasForArgument(<repository.factory id>, <repository interface>, '<name> repository factory')`. The compiled container has 308 of these, for example `.CoreShop\Component\Resource\Repository\RepositoryInterface $rule condition repository factory` → `coreshop.repository.factory.rule_condition` (a `RepositoryFactory`). Autowiring `RepositoryInterface $ruleConditionRepositoryFactory` (or any `…RepositoryInterface $xRepositoryFactory`) injects a factory into a repository-typed argument → `TypeError` at container build/instantiation. `AbstractDriver::addFactory()` does this correctly with `class_implements($factoryClass)`.
- **Fix:** iterate `class_implements($repositoryFactoryClass)` (for example `RepositoryFactoryInterface`) in both drivers.

### B-24 — A Pimcore model without `classes.interface` (or without `classes`) breaks container compilation with a `TypeError` — (reproduced)
- **Where:** `DependencyInjection/Compiler/StackClassesPass.php:52` (`@interface_exists($definition['classes']['interface'])`, then `:56`); `DependencyInjection/Configuration.php` `pimcore.*.classes` (not `isRequired()`) and `classes.interface` (`cannotBeEmpty()`, but neither required nor defaulted); `CoreShopResourceExtension.php:205` / `Extension/AbstractModelExtension.php:77` (`$…['classes']['type']`).
- **Problem:** the file has `strict_types=1`, so `interface_exists(null)` throws `TypeError: interface_exists(): Argument #1 ($interface) must be of type string, null given` (reproduced). `@` only hides the "Undefined array key" warning, not the `TypeError`. A project that registers a model under `core_shop_resource.pimcore` without `interface` (allowed by the config tree) can therefore not compile the container at all. If `classes` is omitted entirely, `match (null)` falls to `default` and `str_replace(…, null)` throws.
- **Fix:** `isRequired()` on `classes`, a default (`ResourceInterface::class`) or `isRequired()` for `interface`. In the pass, `$interface = $definition['classes']['interface'] ?? null; if (!is_string($interface) || !interface_exists($interface)) continue;`, and remove the `@`.

### B-25 — `RegisterPimcoreRepositoriesPass` never runs, and it would crash if it did
- **Where:** `DependencyInjection/Compiler/RegisterPimcoreRepositoriesPass.php:30-52`; target `Pimcore/ObjectManager.php:170` (`registerRepository(string $className, string $repository)`).
- **Problem:**
  - The pass returns at once unless a service `pimcore.dao.object_manager` exists. That id is not defined in six-it/pimcore-source, in CoreShop, or in the compiled container. Pimcore repositories are therefore never registered. `Pimcore/ObjectManager::$repositories` stays empty, and `ObjectManager::getRepository()` (`:141-148`) always throws `Repository for class … not found` for every Pimcore model manager (`coreshop.manager.order`, `coreshop.manager.product`, …). It also stores `string` values but declares a `PimcoreRepositoryInterface` return type, so even a successful registration would end in a `TypeError` there.
  - If the service existed: the pass passes a `Reference` to `registerRepository(string, string)` → `TypeError`. It also calls `$container->get(RegistryInterface::class)` **during compilation**, which instantiates the registry and all metadata at compile time. The exception message says the tag needs "`type` and `priority`", but the code checks `alias`.
- **Fix:** decide whether the registration is needed. If yes: target `CoreShop\Bundle\ResourceBundle\Pimcore\ObjectManager`, pass the repository **service id** or change `registerRepository()` to accept the repository object (or use a service locator), read the model class from the `coreshop.all.pimcore_classes` parameter instead of instantiating the registry, and fix the message. If no: delete the pass.

### B-26 — `CoreShopResourceExtension::load()` resets `coreshop.resources`, so resources from bundles whose extension loaded earlier are lost — (plausible)
- **Where:** `DependencyInjection/CoreShopResourceExtension.php:72` (`$container->setParameter('coreshop.resources', [])`); writers: `Extension/AbstractModelExtension.php:42-44`, `:54-56`. Readers: `RegisterResourcesPass`, `DoctrineTargetEntitiesResolverPass`, `CreateDatabaseTablesCommand`, `DropDatabaseTablesCommand`.
- **Problem:** every extension's `load()` starts from the parameters merged so far and appends to `coreshop.resources`. This extension overwrites the accumulated list with `[]`. In the current setup the ResourceBundle extension loads first (CoreShopCoreBundle registers it as a dependency), and the compiled container has all 50 Doctrine resources in the parameter. A project that registers a resource bundle before `CoreShopResourceBundle` in `config/bundles.php` silently loses those resources from the registry, the target-entity resolution and the table commands, while their services still exist. `coreshop.all.pimcore_classes` / `coreshop.all.stack` are guarded with `hasParameter()` and are not affected.
- **Fix:** `if (!$container->hasParameter('coreshop.resources')) { $container->setParameter('coreshop.resources', []); }`, like the other two parameters.
