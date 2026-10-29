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

---

# Scan 3 (2026-10-29): `EventListener/`, `Form/`, `Pimcore/`, `Routing/`

Scope and evidence: see the "Scan 3" section in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md).

---

## Pimcore repositories and object manager

### B-27 — `StackRepository::findBy()` always produces invalid SQL, and `findOneBy()` crashes when nothing matches — (reproduced, `findOneBy`)
- **Where:** `Pimcore/Repository/StackRepository.php:86-91`, `:93-102`. Services: `coreshop.repository.stack.<name>` (`DependencyInjection/Compiler/StackRepositoryPass.php`), used by `CoreBundle` (`ProductValidationController`, `ProductsReport`, `GiftProductActionProcessor`, `CartCreationCartItemTypeExtension`).
- **Problem:**
  - `findBy()` adds `$criteria['variable'] = '"Product","Variant"'`. `normalizeCriteria()` (`Pimcore/PimcoreDaoRepository.php:198-201`) turns that into the condition `variable = ?`. `objects` has no `variable` column, so every `findBy()` / `findOneBy()` on a stack repository fails with `Unknown column 'variable'`. The class filter it was apparently meant to add is already applied by `getList()` (`className IN (…)`).
  - `findOneBy()` calls `class_implements($instance)` without a null check. When nothing matches, it throws `TypeError: class_implements(): Argument #1 ($object_or_class) must be of type object|string, null given` (reproduced under `strict_types`).
  - This is latent: the current core callers only use `find()` / `findAll()`.
- **Fix:** remove the `variable` line (or delegate to `parent::findBy()` unchanged), and add `if (null === $instance) { return null; }` in `findOneBy()`. Also use `$instance instanceof $this->interface` instead of `in_array(…, class_implements())`.

### B-28 — `PimcoreDaoRepository::findBy()` does not follow Doctrine's `findBy()` semantics
- **Where:** `Pimcore/PimcoreDaoRepository.php:99-153`, `:177-210`. Pimcore: `lib/Model/Listing/AbstractListing.php:189` (`addConditionParam(string $condition, …)`).
- **Problem:**
  - An array value (`findBy(['id' => [1, 2]])`, which means `IN` in Doctrine) has no `condition` key, so the array itself becomes the condition → `TypeError` in `addConditionParam(string …)`.
  - A `null` value becomes `field = ?` with `NULL`, which never matches (Doctrine generates `IS NULL`).
  - The array key is concatenated into SQL unquoted (`$key . ' = ?'`). No caller passes request-controlled keys today (checked), but the injected `$connection` could quote it (`quoteIdentifier()`).
  - `findOneBy()` loads **all** matching objects and returns the first. Pass `limit: 1`.
  - `getList()` (duplicated in `PimcoreRepository.php:46-65`) checks `class_exists($className)` instead of `class_exists($listClass)`, so a model without a `Listing` class fails with `Class "…\Listing" not found` instead of the intended `InvalidArgumentException`.
- **Fix:** map arrays to `field IN (?)`, `null` to `field IS NULL`, quote the field with `$this->connection->quoteIdentifier()`, set limit 1 in `findOneBy()`, fix the `class_exists()` check, and remove the duplicated `getList()` from `PimcoreRepository`.

### B-29 — `Pimcore\ObjectManager`: new objects are treated as existing ones, and a failed `flush()` replays everything
- **Where:** `Pimcore/ObjectManager.php:49-78`, `:116-139`, `:174-183` (`getResourceId()`). See also TODO-2 **B-25** (`getRepository()` always throws).
- **Problem:**
  - `getResourceId()` falls back to `spl_object_hash()` for objects without an ID. `if ($id)` is therefore always true: new objects go to `modelsToUpdate` (`modelsToInsert` is never filled), and `remove()` of an **unsaved** object schedules `->delete()` on it at `flush()`. That calls Pimcore's delete on an element without an ID — (plausible).
  - `flush()` clears the queues only after the loop. If one `save()`/`delete()` throws (validation, mandatory field, DB error), every queued delete/save before it already happened, and the next `flush()` runs them **again**. There is no transaction, so a failure part-way through leaves a partial result.
  - `contains()`, `detach()`, `refresh()`, `getClassMetadata()`, `getMetadataFactory()`, `initializeObject()` throw `InvalidArgumentException('Not implemented')`. Generic code that receives `coreshop.manager.<pimcore model>` as a `Doctrine\Persistence\ObjectManager` (for example `ResourceController::saveAction()`'s `clear()`/`refresh()` pattern from TODO-2 **B-08**) fails with a misleading exception type.
- **Fix:** use the real ID only (`$object->getId()`), and put objects without an ID in `modelsToInsert`. Ignore `remove()` for unsaved objects. In `flush()`, take the queues into local variables and reset them first (or wrap them in `Db::get()->transactional()`), so a retry does not replay finished work. Throw `\BadMethodCallException` / `\LogicException` for unsupported methods, and implement `contains()` (check the queues) and `refresh()` (`$class::getById($id, ['force' => true])`).

---

## Doctrine listeners

### B-30 — Translation `locale` is mapped as `VARCHAR(5)`, but Pimcore allows longer locales — (plausible)
- **Where:** `EventListener/ORMTranslatableListener.php:137-144` (`'length' => 5`), unique constraint `:147-162`; the table DDL in `CoreBundle/Migrations/Version20230829163505.php:38` (`locale VARCHAR(5)`). Locales come from `Component/Resource/Translation/Provider/PimcoreTranslationLocaleProvider.php` → `Pimcore\Tool::getValidLanguages()`.
- **Problem:** Pimcore system languages can be longer than 5 characters (`zh_Hant_TW`, `zh_Hans_CN`, `sr_Latn_RS`, `en_US_POSIX`). With such a language configured, every translatable resource save fails with `Data too long for column 'locale'` (strict SQL mode), or the value is silently truncated. Two languages then truncate to the same value (`zh_Ha`) and collide on the unique `(translatable_id, locale)` constraint, so one language's translations overwrite the other's.
- **Fix:** map `locale` with `length: 10` (or 20) and add a migration that widens all `*_translation.locale` columns.

### B-31 — `ErrorSerializer` translates the message **template** with the wrong domain: validation errors are shown untranslated — (plausible)
- **Where:** `Form/Helper/ErrorSerializer.php:39-54`. Symfony: `form/Extension/Validator/ViolationMapper/ViolationMapper.php:213-219` (`new FormError($message, $messageTemplate, …)`, where `$message` is already translated by the validator in the `validators` domain).
- **Problem:** the serializer ignores `$e->getMessage()`, translates `getMessageTemplate()` with the default `messages` domain (where the Symfony constraint texts do not exist), and then substitutes parameters by hand. Admin users on a non-English locale therefore see English constraint messages. Pluralized messages (`getMessagePluralization()`) lose their plural form (`… character.|… characters.` is shown raw).
- **Fix:** `$message = $e->getMessage();` and keep the field-name prefix. Drop the manual `str_replace()` loop.

### B-32 — Selection types silently turn an unknown ID into "no value"
- **Where:** `Form/DataTransformer/PimcoreResourceDataTransformer.php:41-48`; `Form/Type/PimcoreResourceSelectionType.php` (parent `NumberType`). Users: `CustomerBundle/Form/Type/CustomerSelectionType.php`, `OrderBundle/Form/Type/PurchasableSelectionType.php` (admin order creation).
- **Problem:** `reverseTransform()` returns `$this->repository->find($value)` and so `null` for an ID that does not exist (deleted customer, wrong product ID, typo). There is no `TransformationFailedException`, so the form is valid, and the field is just empty. Compare `PimcoreAssetDataTransformer`, which does throw. The `NumberType` parent also produces a `float` (`12.0`), which only works because the repositories cast to `int`.
- **Fix:** throw `TransformationFailedException` when `find()` returns `null` for a non-empty value, and use `IntegerType` as the parent.

### B-33 — `ResourceFieldGetter` grid operator throws `TypeError` for resource forward targets and array elements — (reproduced, `method_exists`)
- **Where:** `Pimcore/GridColumnConfig/ResourceFieldGetter.php:52`, `:64-76`. Interface: `admin-ui-classic-bundle/src/DataObject/GridColumnConfig/ConfigElementInterface.php:30` (`getLabeledValue(array|ElementInterface $element)`).
- **Problem:**
  - `method_exists($element, $getter)` with an `array` element (allowed by the signature) throws `TypeError: method_exists(): Argument #1 … array given` under `strict_types` (reproduced).
  - When `forwardAttribute` returns a CoreShop Doctrine resource (for example `store`, `currency`, `country`, which is exactly what the operator is for), it is passed to the child operator's `getLabeledValue(array|ElementInterface)` → `TypeError`. The grid / export request ends with a 500.
- **Fix:** guard with `is_object($element)`. For the forward branch, when `$forwardObject` is not an `ElementInterface`, resolve the child's attribute directly on the resource (as the no-children branch does) instead of delegating to the child operator.

---

## Request handling

### B-34 — `BodyListener` reads every request body into memory, on every route — (plausible)
- **Where:** `EventListener/BodyListener.php:28-50`; registered for **all** requests in `DependencyInjection/CoreShopResourceExtension.php` (`kernel.request`, priority 10).
- **Problem:**
  - `$request->getContent()` (`:35`) runs **before** the method / content-type checks, so the full body of every request is copied into a PHP string, including Pimcore WebDAV `PUT` uploads and other raw binary uploads. Large files can hit `memory_limit` even though the listener does nothing with them.
  - The listener is not limited to the admin. Every frontend, webhook (payment providers, `Payum` notify) and DataHub request with a JSON content type is rewritten. A JSON body that is valid but not an object or array (`true`, `"ok"`, `123`) is rejected with **400** on any route.
- **Fix:** check method and content type first, and only then read the body. Limit the listener to admin-context requests (`PimcoreContextResolver::CONTEXT_ADMIN`) and the main request. Use `json_decode(..., flags: JSON_THROW_ON_ERROR)` instead of `@`. See TODO-3 **I-38**.

---

## Doctrine ORM 3 / persistence 4

### B-35 — ORM 3 and doctrine/persistence 4 blockers in the listeners and `ObjectManager` — (dependent)
- **Where:**
  - `EventListener/ORMMappedSuperClassSubscriber.php:25`, `:41-52`, `:92-116` and `ORMTranslatableListener.php:29`, `:51-53`: `Doctrine\ORM\Mapping\ClassMetadataInfo` (ORM 3 merged it into `ClassMetadata`).
  - `ORMMappedSuperClassSubscriber.php:83-86`, `:98-101`: association mappings used as arrays (`$value['type']`) and array assignment into `associationMappings`. In ORM 3 these are `AssociationMapping` objects.
  - `ORMMappedSuperClassSubscriber.php:46`: `LoadClassMetadataEventArgs::getEntityManager()`, which ORM 3 removed. Use `getObjectManager()`.
  - `ORMTranslatableListener.php:104`: `'cascade' => ['persist', 'merge', 'remove']`. ORM 3 rejects the `merge` cascade. `EntityMerger` (TODO-2 **B-21**) relies on this `merge` cascade on translations.
  - `Pimcore/ObjectManager.php`: implements `Doctrine\Persistence\ObjectManager` with untyped methods. persistence 3.4.5 (installed) only documents the types in docblocks; persistence 4 declares native return types, so `contains()`, `getClassMetadata()`, `getMetadataFactory()`, `getRepository()` without them become fatal signature errors. `merge()` is no longer part of the interface.
- **Fix:** switch to `ClassMetadata` and `getObjectManager()`, rebuild the copied mappings through `$metadata->mapOneToMany()` / `mapManyToMany()` instead of array assignment, remove `merge` from the cascade (together with replacing `EntityMerger`, TODO-3 **I-25**), and add native types to `ObjectManager` matching persistence 3.4's docblocks (compatible with both 3 and 4).

### B-36 — `ORMTranslatableListener::hasUniqueConstraint()` fails on constraints declared with `fields` — (plausible)
- **Where:** `EventListener/ORMTranslatableListener.php:165-178`
- **Problem:** Doctrine also accepts unique constraints declared with `fields` instead of `columns`. A translation entity that declares its `(translatable, locale)` constraint with `fields` hits `Undefined array key "columns"`, and then `array_diff(null, …)` → `TypeError` during metadata loading. The schema can then not be generated at all.
- **Fix:** `$constraint['columns'] ?? []` (and map `fields` to column names via `$metadata->getColumnName()`).

---

# Scan 4 (2026-10-29): `Installer/`, `Serialization/`, `Slug/`, `Validator/`

Scope and evidence: see the "Scan 4" section in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md).

---

## Validator

### B-37 — `Active` and `UniqueEntity` trigger Symfony 7.3/7.4 deprecations and will stop working on Symfony 8 — (reproduced)
- **Where:** `Validator/Constraints/Active.php`, `Validator/Constraints/UniqueEntity.php` (`getRequiredOptions()`, public properties filled from the options array). Symfony: `vendor/symfony/validator/Constraint.php`, `Mapping/Loader/AbstractLoader.php`.
- **Problem:** constructing them the way the YAML/XML loaders do (options array) emits, with Symfony 7.4.21 on PHP 8.4.27:
  - `Since symfony/validator 7.4: Support for evaluating options in the base Constraint class is deprecated. Initialize properties in the constructor of …\Active instead.` (same for `UniqueEntity`).
  - `Since symfony/validator 7.3: Using constraints not supporting named arguments is deprecated. Try adding the HasNamedArguments attribute to …\UniqueEntity.` (when loaded from YAML).
  - On Symfony 8 the options array path is removed: `message`, `fields`, `values`, `allowSameEntity` and `groups` would no longer be applied, and the required-options check for `fields` / `values` disappears.
  - Neither class has `#[\Attribute]`, so they cannot be used as PHP attributes.
- **Fix:** add `#[\Attribute(\Attribute::TARGET_CLASS)]` (`UniqueEntity`) / `#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]` (`Active`), and a `#[HasNamedArguments]` constructor that sets every property and calls `parent::__construct(null, $groups, $payload)`. Validate the required `fields` in the constructor and drop `getRequiredOptions()`.

### B-38 — `UniqueEntityValidator` evaluates the **message** as an expression, and passes non-string values to `setParameter()` — (reproduced)
- **Where:** `Validator/Constraints/UniqueEntityValidator.php:39-46`, `:59`, `:123-129`; service `Resources/config/services/validators.yml` (`@coreshop.expression_language`, `@service_container`).
- **Problem:**
  - `$this->evaluateExpression([$constraint->message])` runs the message through ExpressionLanguage. The default message `This entity already exists.` throws `SyntaxError: Variable "This" is not valid around position 1` (reproduced). So any use with the default message crashes with a 500 exactly when a duplicate is found. Messages only work when written as quoted expression literals (`"'my.translation.key'"`).
  - `fields` are evaluated as expressions too. A plain `fields: [email]` fails with `Variable "email" is not valid`. It has to be written `"'email'"`. Nothing documents this.
  - `->setParameter('{{ value }}', $criteria[$fields[0]])`: `ConstraintViolationBuilder::setParameter(string $key, string $value)` takes a string (verified on 7.4.21). An `int` field (or an object such as a relation) throws `TypeError` under `strict_types`. `setInvalidValue()` would accept it.
  - Expressions get the whole `service_container` as a variable. That is not a vulnerability (expressions come from mapping config), but it lets any mapping file call any public service.
- **Fix:** do not evaluate `message`, and use it as a translation key like every Symfony constraint. Evaluate `fields`/`values` only when they start with an explicit marker (for example `expr:`), or move the dynamic part to `values` only. Use `$this->formatValue($value)` for the parameter. Pass only the variables that are needed (or a small service locator) instead of `service_container`.

### B-39 — `UniqueEntityValidator` flags the object being validated as its own duplicate, and builds SQL that fails for localized fields or `null` — (plausible)
- **Where:** `Validator/Constraints/UniqueEntityValidator.php:66-130`
- **Problem:**
  - Unless `allowSameEntity: true` is set, the object itself is never excluded. Re-saving an existing object that is already stored with the same values reports "already exists" for itself. The flag name suggests an edge case, but without it the constraint can only be used for objects that are being created.
  - The listing has no limit: every matching object (including unpublished ones) is loaded just to test whether at least one exists. Use `setLimit(2)` (or a count query) and exclude the current ID in SQL.
  - Field names are put into SQL as column names unquoted. Localized fields (stored in `object_localized_*`) and relation fields have no column in the object's table → `Unknown column` SQL error. A `null` value becomes `field = NULL` and never matches.
- **Fix:** add `AND id != ?` for persisted objects (keep `allowSameEntity` only for BC), check that each field is a plain, non-localized field definition (`$class->getFieldDefinition($name)`, otherwise `ConstraintDefinitionException`), use `IS NULL` for `null`, and quote identifiers.

### B-40 — `ActiveValidator` throws on `null` and other non-toggleable values instead of ignoring them
- **Where:** `Validator/Constraints/ActiveValidator.php:34-43`
- **Problem:** any value that is not a `ToggleableInterface`, including `null`, throws `UnexpectedTypeException`. Symfony constraints treat `null` as valid and leave it to `NotNull`. Putting `Active` on an optional relation (for example an optional carrier or payment provider) therefore turns every submission without a value into a 500 instead of a valid form. `formatValue($value)` on an object prints only `object`.
- **Fix:** `if (null === $value) { return; }`. Throw `UnexpectedValueException` (not `UnexpectedTypeException`) for other types, as Symfony recommends for value mismatches.

---

## Installer

### B-41 — `PimcoreClassInstaller` aborts `coreshop:resources:install` for models registered with `#[AsPimcoreModel]` without an install file — (dependent)
- **Where:** `Installer/PimcoreClassInstaller.php:60-85`; data from `DependencyInjection/CoreShopResourceExtension.php` `autoRegisterPimcoreModels()` (`'install_file' => $resource->getInstallFile()`, default `null`). See TODO-2 **B-13**.
- **Problem:** `array_key_exists('install_file', …)` is `true` for the attribute's `null`. `$this->kernel->locateResource(null)` then throws `TypeError` (`locateResource(string $name)`), which the `catch (\InvalidArgumentException)` does not catch. The whole composite install stops (classes are the first installer, priority 100 in ascending order), so routes, documents, thumbnails, permissions and grid configs are not installed either. `CoreShop\Behat\Model\Car` is such a model.
- **Fix:** `if (!empty($pimcoreModel['classes']['install_file']))`. Also log the "file not found" case (currently a silent `continue`), and count only installable items for the progress bar (`$progress->start()` uses all models, so the bar never completes).

### B-42 — `PimcoreDocumentsInstaller` never links document translations and rejects its own documented options
- **Where:** `Installer/PimcoreDocumentsInstaller.php:81`, `:100-135`, `:144-215`; `Installer/Configuration/DocumentConfiguration.php`
- **Problem:**
  - `$languagesDone` is initialized to `[]` and never appended to. The "Link translations" block (`:119-131`) therefore never runs, and installed documents (for example the shop pages and e-mail documents from `FrontendBundle/Resources/install/pimcore/documents.yml`) are never connected as translations of each other.
  - `installDocument()` reads `$properties['title']` and `$properties['template']`, but `DocumentConfiguration` does not define those keys. `processConfiguration()` rejects any YAML that sets them (`Unrecognized option "title"`), so a page title or template can never be installed. `module` and `action` are defined but never used.
  - `setParent(Document::getByPath(…/path))` gets `null` when the parent path does not exist (documents listed before their parent, or a typo in `path`). Saving a document without a parent then fails.
  - The root is the **first** site's root document (`$sites[0]`), which is arbitrary for multi-site installs. `!empty($content)` skips the legitimate value `"0"`.
- **Fix:** `$languagesDone[] = $language;` after each language. Add `title` / `template` to the config tree (and remove `module` / `action`). Check that the parent exists and fail with a clear message. Make the target site configurable. Use `null !== $content && '' !== $content`.

### B-43 — `PimcoreRoutesInstaller` drops the `defaults` of static routes
- **Where:** `Installer/PimcoreRoutesInstaller.php:90-109`, `Installer/Configuration/RouteConfiguration.php:45`; Pimcore `bundles/StaticRoutesBundle/src/Model/Staticroute.php:251` (`setDefaults(string)`)
- **Problem:** the config tree accepts `defaults` (and `module`, `action`), but `installRoute()` never sets them. A route defined in an install YAML with `defaults` (for example a default `_locale` or format) is created without them, and URL generation or matching then differs from what the YAML says. Existing routes are never updated, so a corrected YAML is not applied on upgrade. That is fine for the migration that only adds `coreshop_payment_token` (`CoreBundle/Migrations/Version20240117102103.php`), but it should be documented.
- **Fix:** `if (null !== $properties['defaults']) { $route->setDefaults($properties['defaults']); }`. Remove `module` / `action` from the tree (Pimcore 11 static routes only have `controller`). Optionally add an `update` option like the class installer has.

### B-44 — `PimcoreGridConfigInstaller` can only resolve a class ID when the class installer ran in the same process — (dependent)
- **Where:** `Installer/PimcoreGridConfigInstaller.php:95-120`
- **Problem:**
  - The first lookup, `$this->objectManager->getRepository($metadata->getParameter('model'))`, can never succeed: `ObjectManager::getRepository()` always throws, because no repository is ever registered (TODO-2 **B-25**). Also `model` is a **class** entry (`getClass('model')`), not a parameter, so `getParameter('model')` would throw as well.
  - The fallback only knows classes that `PimcoreClassInstaller` created or loaded **earlier in the same run** and that have an `install_file`. Running the grid-config installer on its own (or for an application whose classes were installed in an earlier run without install files) fails with `Could'nt find ClassID for Identifier …` (also misspelled).
- **Fix:** resolve the ID directly: `$model = $metadata->getClass('model'); return $model::classId();` (or `ClassDefinition::getByName($metadata->getParameter('pimcore_class'))->getId()`). Drop the `ObjectManager` and `PimcoreClassInstaller` dependencies.

---

## Serialization

### B-45 — JMS handlers fail on types declared without a class parameter, and `CarbonHandler` accepts only `Carbon` — (reproduced, mechanism)
- **Where:** `Serialization/{PimcoreObjectHandler,PimcoreAssetHandler,PimcoreDocumentHandler}.php` `deserializeRelation()` (`$obj instanceof $className` with `$className = $type['params'][0]['name'] ?? null`); `Serialization/RelationsHandler.php:57-65` (`getClassMetadata($className)` with `null`); `Serialization/CarbonHandler.php:28-35` (`: int`, `return $value`). Mappings without a parameter: `PaymentBundle/…/Model.PaymentProvider.yml:24` and `ShippingBundle/…/Model.Carrier.yml:24` (`type: pimcoreAsset`), `AddressBundle/…/Model.{Country,Zone}.yml`, `CoreBundle/…/Model.PaymentProvider.yml:7`, `IndexBundle/…/Model.Filter.yml:28` (`type: relation`).
- **Problem:**
  - Deserializing such a property runs `$obj instanceof null` → `Error: Class name must be a valid object or a string` (reproduced), or `getClassMetadata(null)` → `TypeError`. Today deserialization only goes through the `fromArray()` calls in the `StoreValues` / price-rule / unit-definition CoreExtensions, whose types all carry a parameter, so this is latent. Any new `fromArray()` / `deserialize()` on those resources (imports, APIs) crashes.
  - `CarbonHandler::serializeRelation()` returns the value unchanged when it is not a `Carbon`, but the return type is `int`. A getter that returns `CarbonImmutable`, `\DateTime` or `\DateTimeImmutable` for a `type: carbon` property → `TypeError`.
- **Fix:** when no class parameter is given, fall back to the element base class (`Concrete`, `Asset`, `Document`) and, for `relation`, throw a clear `RuntimeException('type "relation" needs a class parameter')`. Or add the missing parameters to the mapping files. In `CarbonHandler`, accept `\DateTimeInterface` and return `?int`.
