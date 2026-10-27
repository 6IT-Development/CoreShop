# PimcoreBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items (Symfony 8, Pimcore/data-hub updates). None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as sub-bullets.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(verified)** were confirmed by reading the vendored library source or the compiled container.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — `composer.json` does not describe what the bundle needs
- **Where:** `composer.json:26-36`.
- **Problem / Fix:**
  - No `php` constraint. Add `"php": "^8.4"` (or `~8.4.0`, same as the components).
  - Directly used packages are not declared: `symfony/http-kernel`, `symfony/dependency-injection`, `symfony/config`, `symfony/console`, `symfony/http-foundation`, `symfony/event-dispatcher(-contracts)`, `symfony/expression-language`, `symfony/string` (slugger), `symfony/translation-contracts`, `laminas/laminas-stdlib` (via `CompositeLinkGenerator`), `doctrine/doctrine-migrations-bundle` (the two commands call `doctrine:migrations:*`). Add them with `^7.4` for Symfony.
  - Optional integrations should be `suggest` (or `conflict` with old versions): `pimcore/data-hub` + `webonyx/graphql-php` (`DataHub/**`, `services/data_hub.yml`), `pimcore/web-to-print-bundle` / `six-it/web-to-print-bundle` (`services/print.yaml`).
  - `coreshop/pimcore: ^4.0` next to `coreshop/registry: ^4.1`; align both with the fork's `branch-alias 4.1-dev` and its package names (`six-it/*`).
  - `require-dev` pins PHPStan 1.x (`phpstan/phpstan ^1.10`, extensions `^1.x`); PHPStan 2 is current and has the PHP 8.4 rules. Bump together with the other bundles.
  - `"minimum-stability": "dev"` in a library package has no effect for consumers; drop it or move it to the root project.

### I-02 — Bundle and extension: use the Symfony 7.x configuration APIs
- **Where:** `CoreShopPimcoreBundle.php`, `DependencyInjection/CoreShopPimcoreExtension.php`, `DependencyInjection/Configuration.php`, `DependencyInjection/Extension/AbstractPimcoreExtension.php`.
- **Fix:**
  - `Configuration.php:34`: `booleanNode` (TODO-2 **B-16**); `:50,54,58,62` `->prototype('scalar')` → `->scalarPrototype()`; `:30` the `/** @var ArrayNodeDefinition */` is not needed (`TreeBuilder::getRootNode()` is generic-typed in 7.x).
  - Consider `AbstractBundle` (`configure()` / `loadExtension()` / `prependExtension()`) instead of the separate Extension + Configuration classes; it removes the `getConfiguration([], $container)` call and the extra class. Keep `AbstractPimcoreExtension::registerPimcoreResources()` as a small static helper/trait that the other CoreShop bundles can call.
  - `CoreShopPimcoreExtension.php:37`: `$configs = $this->processConfiguration(...)` overwrites the input with a different shape; name it `$config`.
  - `CoreShopPimcoreExtension.php:43,47`: bundle detection by the `kernel.bundles` key works; `ContainerBuilder::willBeAvailable()` / `class_exists(PimcoreDataHubBundle::class)` make the intent clearer and also handle a removed package.

### I-03 — Controllers: route attributes, `final`, typed request access, explicit permissions
- **Where:** `Controller/Admin/GridController.php`, `Controller/Admin/DynamicDropdownController.php`, `Resources/config/pimcore/routing.yml`, `Resources/config/services/{grid,dynamic_dropdown}.yml`.
- **Fix:**
  - Use `#[Route]` attributes on the actions (with `methods: ['GET']` for read actions) and import them with `type: attribute`; keep the route names and `options: { expose: true }`.
  - `GridController` is not `final` (the other controller is); make both `final`.
  - Use the typed `InputBag` getters: `getString('folderName')`, `getString('methodName')`, `getBoolean('recursive')` instead of `(string) ->get()` and `=== 'true'` (`DynamicDropdownController.php:37,128-130,151,163`).
  - Declare permissions with `#[IsGranted]` or an explicit `$this->checkPermission(...)` at the top of each action (TODO-1 **C-01**, TODO-2 **B-11**).
  - `services/dynamic_dropdown.yml` wires the controller by hand (`setContainer` with `@Psr\Container\ContainerInterface`, no autowire), while `services/grid.yml` autowires. Use `_defaults: { autowire: true, autoconfigure: true }` for both; then the manual `setContainer` call and the two tags are not needed. In the compiled container `DynamicDropdownController` has no `setTokenResolver` / `setPimcoreSerializer` calls (verified, container ~10142-10147), so `getAdminUser()` / `adminJson()` would not work there today.

### I-04 — Commands: `#[AsCommand]`, invokable signature, safe nested call
- **Where:** `Command/AppMigrateCommand.php`, `Command/AppMigrationGenerateCommand.php`, `Resources/config/services.yml:98-104`.
- **Fix:**
  - `#[AsCommand(name: 'coreshop:app:migrate', description: 'Execute App migrations.')]` and drop `configure()`; then the `command:` attribute on the `console.command` tag is not needed. With Symfony 7.4, commands can also be invokable (`__invoke(OutputInterface $output, …): int` without extending `Command`).
  - Run the nested command with `$this->getApplication()?->find('doctrine:migrations:migrate')->run($input, $output)` instead of `Application::run()`; no need to touch `autoExit` / `catchExceptions`. Forward `isInteractive()` and the relevant options (TODO-2 **B-19**).
  - `array_merge(['command' => …], ['--prefix' => …])` → a plain array literal.
  - Help text says "executes all CoreShop migrations", but the command runs `App\Migrations`. Make the namespace configurable (`core_shop_pimcore.app_migrations_namespace`) or document the assumption.
  - The two commands are almost identical; a shared private helper or base class removes the duplication.

### I-05 — PHP 8.4 typing: typed properties, return types, readonly / asymmetric visibility
- **Where / Fix:**
  - `CoreExtension/DynamicDropdownTrait.php:26-160`: untyped public properties with `@var`, untyped setters without return types, `@return mixed` docblocks. Use typed properties (`public ?string $folderName = null; public ?string $className = null; public ?string $methodName = null; public bool $recursive = false; public string $sortBy = 'byid'; public bool $onlyPublished = false;`) and `: static` setters, like Pimcore's own data types. Check stored class definitions first: `recursive` is saved as a bool by the JS checkbox but documented as `string`; Pimcore's `setValues()` and `__set_state()` must accept the old values (cast in the setter).
  - `CoreExtension/SerializedData.php:87,92,111`: `getDataFromGridEditor()`, `getQueryColumnType()`, `getDataForGrid()` have no types.
  - `DataHub/QueryType/SerializedData.php:27,29,43`: untyped `private $instance` and untyped method parameters/returns.
  - `DataHub/Type/SerializedDataType.php:27-40`: add `mixed` / `Node` types matching webonyx 15.
  - Promoted constructor properties should be `private readonly`: `EventListener/AdminJavascriptListener.php:28-31`, `EventListener/Grid/ObjectListFilterListener.php:28`, `EventListener/SluggableListener.php:30` (`protected` in a `final` class), `Mail/MailProcessor.php:30`, `Loader/DependencyInjectionImplementationLoader.php:29`, `DependencyInjection/Compiler/RegisterImplementationLoaderPass.php:28-29`.
  - `Attribute/AsGridAction.php`, `Attribute/AsGridFilter.php`: `public readonly ?string $type = null` (or PHP 8.4 `public private(set)`) instead of a private property plus getter. Note that `Component/Registry/Autoconfiguration::getProperties()` reads attribute values through `get*` methods, so change both together (dependent).
  - `Event/MailEvent.php:27-39`: `protected` in a `final` class, untyped `$subject`, `$params` assigned by hand; promote it. `shouldSendMail` is a good fit for PHP 8.4 asymmetric visibility (`public private(set) bool`) plus a `cancel()` method.
  - `Mail/MailProcessor.php:34`: `$subject = null, $recipients = null` untyped; the interface (`Component/Pimcore/Mail/MailProcessorInterface`) should type them (`mixed $subject`, `array|string|null $recipients`) (dependent).

### I-06 — PHP 8.4 idioms that make the code shorter
- **Where / Fix:**
  - `DataHub/QueryType/SerializedData.php:45-51`: `return $this->instance ??= new SerializedDataType();`.
  - `DependencyInjection/Compiler/RegisterTypeHintRegistriesPass.php:41-44`: `!in_array(…, $implements) && !in_array(…, $implements)` → `!is_subclass_of($class, ServiceRegistryInterface::class) && !is_subclass_of($class, PrioritizedServiceRegistryInterface::class)` (or `array_any()`), with strict comparison.
  - `Controller/Admin/DynamicDropdownController.php:38-40,112-120`: closures → arrow functions / `array_filter(…, static fn (string $m): bool => str_starts_with($m, 'get'))`; `preg_split('/\//', …)` → `array_filter(explode('/', …), 'strlen')`.
  - `EventListener/AdminJavascriptListener.php:47,56,65,74`: `count($x) === 0` → `[] === $x`.
  - `Controller/Admin/DynamicDropdownController.php:33`: `private string $separator` is a constant → `private const string SEPARATOR = ' - ';` (typed class constants, PHP 8.3+).

## Code quality, readability and logic

### I-07 — Service configuration cleanup
- **Where:** `Resources/config/services.yml`, `Resources/config/services/*.y(a)ml`.
- **Fix:**
  - Every file sets `_defaults: { public: true }`. Only controllers and services fetched from the container at runtime need to be public; make the rest private.
  - Mixed `.yml` / `.yaml` extensions and 2-space vs 4-space indentation (`link_generator.yaml`, `print.yaml`); pick one style.
  - `services/data_hub.yml:39`: typo in the id `coreshop.datahub.graphql.dataobjectmutationtypegenerator.redsource.dynamic_dropdown_multiple`.
  - `services/link_generator.yaml:2-8`: `coreshop.registry.link_generators` is never filled and never used (no other reference in `src/`, verified); `CompositeLinkGenerator` gets its generators through `LinkGeneratorPass` / `addContext`. Remove the registry or switch the composite to it.
  - `services.yml:7-8`: `coreshop.admin.route.base: /admin/coreshop` already starts with `/`, and the routes use `/%coreshop.admin.route.base%/…`. The router normalises the double slash, but the parameter should be either `admin/coreshop` or the routes should drop the leading `/`. Several other CoreShop bundles use this parameter (dependent).
  - `services.yml:38-54` + `ExpressionLanguageServicePass`: `ExpressionLanguage` accepts providers in its constructor; `arguments: ['@cache.system', !tagged_iterator coreshop.expression_language_provider]` replaces the compiler pass (after TODO-1 **C-03**).
  - `services.yml:25-28`: `NoteService` uses `@monolog.logger.pimcore`; tag-based logger injection (`monolog.logger` tag with `channel: pimcore`) is the Symfony way and survives channel renames.

### I-08 — Compiler passes: formatting, `final`, duplicated code
- **Where:** `DependencyInjection/Compiler/*.php`.
- **Fix:**
  - The odd `public function __construct(\n        ) {` formatting in `LinkGeneratorPass.php:28-29`, `RegisterGridActionPass.php:27-28`, `RegisterGridFilterPass.php:27-28`, `RegisterPimcoreDocumentTagImplementationLoaderPass.php:25-26`, `RegisterPimcoreDocumentTagPass.php:25-26` (also in `Component/Pimcore/DataObject/CompositeLinkGenerator.php`) → `public function __construct()`; run the code style fixer.
  - `RegisterGridActionPass` / `RegisterGridFilterPass` are not `final` while the others are; their tag constants are public API, keep them.
  - `RegisterPimcoreDocumentTagImplementationLoaderPass.php:28`: a `@psalm-suppress` docblock inside an argument list does nothing for Psalm; move it to the method or drop it.
  - `RegisterTypeHintRegistriesPass.php:55-73`: the same `strtolower(trim(preg_replace(…)))` expression is written twice; compute the alias name once. `Component/Registry/RegisterSimpleRegistryTypePass` has a third copy (dependent).
  - `RegisterImplementationLoaderPass.php` is abstract with one subclass; it could be a plain `final` pass with constructor arguments, or use `tagged_iterator` on the loader definition.

### I-09 — Admin resource registration: keyed config is flattened, commented-out code
- **Where:** `DependencyInjection/Extension/AbstractPimcoreExtension.php:26-55`, `EventListener/AdminJavascriptListener.php`.
- **Fix:**
  - `:32` commented-out `$aliasParameter` line; remove.
  - `:45` `array_values()` drops the keys from `admin.yml` (`pimcore_extension_multiselect`, `broker`, …), so a project cannot override or remove a single file by key, and the same file registered by two bundles is added twice. Keep the keys (`array_replace`) or `array_unique()` the result.
  - `:44` `array_key_exists($resourceType, $bundleResources)` is always true because the config node uses `addDefaultsIfNotSet()`.
  - `AdminJavascriptListener` has four copies of the same method; one private `appendPaths(PathsEvent $event, array $paths)` helper is enough. The subscriber could also be configured with `#[AsEventListener]` attributes.

### I-10 — `SerializedData`: dead methods, unreadable output, storage format
- **Where:** `CoreExtension/SerializedData.php`.
- **Fix:**
  - `getQueryColumnType()` (`:92-95`) is not part of any interface the class implements (it does not implement `QueryResourcePersistenceAwareInterface`); remove it.
  - `getDataFromGridEditor()` (`:87-90`) is not used by Pimcore for a non-editable type; remove or type it.
  - `getForCsvExport()` returns `''` and `getFilterCondition()` returns `''`: say so explicitly with `isFilterable(): false` and export JSON instead of nothing.
  - `$phpdocType = ''` (`:30`) is unused for a type whose getters are untyped (`getReturnTypeDeclaration(): null`); either type the generated getters (`?array`) or drop the property.
  - Store JSON instead of PHP `serialize()` (with a migration for existing `paymentSettings` / `attributeValue` data). This removes the object-injection class of problems (TODO-1 **C-02**) and makes the column readable in SQL.
  - The class-level `@psalm-suppress InvalidReturnType, InvalidReturnStatement` hides real type problems; remove it and fix what Psalm reports.

### I-11 — DataHub classes
- **Where:** `DataHub/QueryType/SerializedData.php`, `DataHub/Type/SerializedDataType.php`.
- **Fix:** make them `final`, add types (**I-05**), `??=` (**I-06**); the bad indentation of the array passed to `enrichConfig()` (`:35-38`) hides the structure. Add a mutation generator for `coreShopSerializedData` or document that it is read-only.

### I-12 — `DynamicDropdownController`: structure and performance
- **Where:** `Controller/Admin/DynamicDropdownController.php`.
- **Fix:**
  - `methodsAction()` (`:93-124`) lists every public method starting with `get` (`getDao`, `getClass`, `getChildren`, `getProperty`, …) by reflecting a model instance. List the class definition's data fields instead (`$classDefinition->getFieldDefinitions()` + localized fields) and return `get<Name>`; this list is also the allow-list for TODO-1 **C-01**.
  - `walkPath()` (`:126-170`) re-reads the request, rebuilds `$fqcn`, re-loads the class definition and re-runs `isUsingI18n()` on every recursion level. Compute these once and pass a small value object down.
  - Recursion loads every child object one level at a time (N+1) with no limit. For large folders use one listing: `$list->setCondition('path LIKE ?', [$folder->getRealFullPath() . '/%'])` plus the class filter, sorted in SQL, with a sane limit.
  - `:45-56`: the `if ($parentFolderPath)` / `rtrim` / `str_replace('//', '/')` block cannot change the already normalised path; remove it with the dead `else` (TODO-2 **B-05**).
  - `:147-149,156,188`: `@var` / `@psalm-suppress` comments that are wrong or unnecessary after the refactor; the inline comment `// Did I forget something?` (`:184`) should go with `parseTree()` (TODO-2 **B-01**).
  - `methodsAction` route accepts `POST` but reads only `query`; same for `optionsAction`.

### I-13 — Mail processor, mail event and event names
- **Where:** `Mail/MailProcessor.php`, `Event/MailEvent.php`, `Events.php`.
- **Fix:**
  - `MailProcessor.php:50-55`: the "BC Remove with 3.1" `method_exists()` checks are dead: neither `setEnableLayoutOnPlaceholderRendering` nor `setEnableLayoutOnRendering` exists in `vendor/six-it/pimcore-source/lib/Mail.php` (verified). Remove.
  - The `$subject` parameter is the event subject (usually the order), not the mail subject; rename it (`$context` / `$subjectObject`) in the interface and implementation (dependent).
  - `MailEvent` has `getParams()` but no setter, and the params are copied into `Mail` before `PRE_MAIL_SEND`; a listener cannot change them. Either document that listeners must use `getMail()->setParams()` or apply the event params after dispatch.
  - `Events.php:23-31`: docblocks say "order mail" but the events fire for every mail sent through `MailProcessor`.

### I-14 — Bundle version lookup: duplicated and coupled to CoreBundle
- **Where:** `CoreShopPimcoreBundle.php:44-68`.
- **Fix:**
  - `getVersion()` depends on `CoreBundle\Application\Version` by string class name; `class_exists(InstalledVersions::class)` is always true with Composer 2.
  - The same `getComposerVersion()` is copied into many CoreShop bundles (dependent). Move it to one trait in `Component/Pimcore` (or `ResourceBundle`) that tries `six-it/core-shop`, then `coreshop/core-shop`, then the bundle package, and returns `?? ''` (TODO-2 **B-14**).

### I-15 — Core extension data types: redundant overrides and leftovers
- **Where:** `CoreExtension/*.php`.
- **Fix:**
  - `ItemSelector.php:28-36` re-implements `getFieldType()` and `getObjectsAllowed()` exactly as the parent/trait already do; remove. `SuperBoxSelect` already relies on the parent.
  - `DynamicDropdownMultiple.php:40-55`: `$data === false` is a leftover from old Pimcore; the comments (`//if not set, return null`, `//must return array if data shall be set`) state the obvious.
  - `DynamicDropdown.php:38`: the return type lists `Asset|Document` although only objects can be returned; `?DataObject\AbstractObject` is clearer (allowed covariance).
  - Class-level `@psalm-suppress InvalidReturnType, InvalidReturnStatement, InvalidArgument, MissingConstructor` on every class; remove and fix what is reported.

### I-16 — `GridController`: duplicated actions
- **Where:** `Controller/Admin/GridController.php:34-86`.
- **Fix:** `getGridFiltersAction` and `getGridActionsAction` differ only in the registry; extract one private method `listServices(ServiceRegistryInterface $registry, string $listType)`. Use `array_filter` / `array_map` or a generator instead of the manual loop, and the translator's default locale when the admin user has no language.

### I-17 — Tests and static analysis
- **Fix:**
  - The bundle has no tests. Minimum set: functional tests for the four admin endpoints (permission, CSRF, bad input → 4xx, TODO-1 **C-01**, TODO-2 **B-01** to **B-06**, **B-11**); unit tests for `SerializedData` round trips (`null`, `[]`, nested arrays, strings that look serialized; TODO-2 **B-08**); a container test that every registry alias resolves to a class implementing the aliased type (TODO-2 **B-15**).
  - Run PHPStan 2 at a high level on the bundle and remove the blanket `@psalm-suppress` annotations (**I-10**, **I-15**).

### I-18 — Documentation
- **Where:** `README.md`.
- **Fix:** the README does not describe the bundle's configuration (`core_shop_pimcore.autoconfigure_with_attributes`, `pimcore_admin.{js,css,editmode_js,editmode_css}`), the grid action/filter extension points (`#[AsGridAction]`, `#[AsGridFilter]`, tags `coreshop.grid.action` / `coreshop.grid.filter`), the `coreshop.mail.pre_send` / `post_send` events, or the data types it adds. Add short sections, including the security notes for expressions (TODO-1 **C-03**).
