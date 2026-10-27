# StoreBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items (Symfony 8). None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as sub-bullets.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle.

**Already fine for Symfony 7.4:** the extension extends CoreShop's `AbstractModelExtension` (based on `Symfony\Component\DependencyInjection\Extension\Extension`), service config is YAML (not the XML service format deprecated in 7.4; `Store.orm.xml` is a Doctrine mapping and not affected), `TreeBuilder` is constructed with a root name, the validation YAML uses named options (no array-options constructor, deprecated in 7.3), no `ContainerAwareInterface`, `DataCollector::collect()` has the 7.x signature, every form method has native return types, `RequestStack::getMainRequest()` is used (not the removed `getMasterRequest()`).

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — `composer.json` does not describe what the code needs
- **Where:** `composer.json:26-58`.
- **Problem / Fix:**
  - No `php` constraint. Add `"php": "^8.4"`.
  - Directly used packages are not declared: `symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel`, `symfony/http-foundation`, `symfony/form`, `symfony/options-resolver`, `symfony/doctrine-bridge` (`CollectionToArrayTransformer` in `Form/Type/StoreChoiceType.php:23`) (all `^7.4`), `doctrine/orm` (`QueryBuilder`), `gedmo/doctrine-extensions` (mapping uses `gedmo:timestampable`), `jms/serializer-bundle` (`pimcore/config.yml:12-17`), `six-it/admin-ui-classic-bundle` (admin routes / JS). `symfony/web-profiler-bundle` + `twig/twig` belong in `suggest` (collector template).
  - `require-dev` pins PHPStan 1.x; bump to PHPStan 2 (PHP 8.4 rules) together with the other bundles.
  - `"minimum-stability": "dev"` and `repositories` (`path` `../../*/*`) have no effect for consumers of a library; move them to the monorepo root. `:43-48` uses 4-space indent while the rest uses 2.

### I-02 — Configuration tree: wrong node types, dead check, missing docs
- **Where:** `DependencyInjection/Configuration.php`.
- **Fix:**
  - `:40` `autoconfigure_with_attributes` → `booleanNode` (TODO-2 **B-05**); add a `debug` boolean node (TODO-2 **B-06**).
  - `:108-111` `permissions` is a `scalarNode` with an array default → `arrayNode()->scalarPrototype()`; `cannotBeOverwritten()` on it is pointless if the value never comes from user config — make it a constant in the extension instead.
  - `:94,98,102,106` `->prototype('scalar')` → `->scalarPrototype()`.
  - `:74` `repository` has no `cannotBeEmpty()` while all other classes do.
  - `:59` `options` `variableNode` has no info / default; add `->info()` to every node (shown by `config:dump-reference`).
  - Indentation of the root `children()` block (`:38-42`) does not follow the nesting style of the other sections.

### I-03 — Extension: naming, dead `array_key_exists`, conditional loading
- **Where:** `DependencyInjection/CoreShopStoreExtension.php`.
- **Fix:**
  - `$configs` is reused for the processed config → `$config`.
  - `:44` `array_key_exists('pimcore_admin', $configs)` is always true (`addDefaultsIfNotSet()`).
  - `:59` `replaceArgument(3, true)` depends on argument position; use a named argument (`$storeChangeSupport`) or a parameter (`coreshop.store.debug`).
  - `services/profiler.yml` is loaded in every environment; load it only when `kernel.debug` / `WebProfilerBundle` is present (the `data_collector` tag is ignored otherwise, but the service definition and its template reference stay).
  - `:62-76` the two `registerForAutoConfiguration()` calls differ only in three arguments; a small loop over a map reads better.

### I-04 — Compiler passes: formatting, typed constants, Symfony-native alternative
- **Where:** `DependencyInjection/Compiler/CompositeRequestResolverPass.php:27-37`, `CompositeStoreContextPass.php:27-37`.
- **Fix:**
  - `public function __construct(\n        ) {` — broken formatting (an empty parameter list split over two lines); same in `Component/Store/Context/CompositeStoreContext.php:33-34`, `CompositeRequestResolver.php:30-31`, `Model/StoresAwareTrait.php:31-32` (dependent). Run php-cs-fixer.
  - PHP 8.3 typed class constants: `public const string STORE_CONTEXT_TAG = …`.
  - Long term: replace the pass + `addContext()` method calls with `#[AutowireIterator(tag, defaultPriorityMethod: …)]` in the composites (Symfony sorts by priority natively), which also removes the Laminas `PriorityQueue` dependency (**I-15**).

### I-05 — Attribute classes
- **Where:** `Attribute/AsStoreContext.php`, `Attribute/AsRequestBasedResolverStoreContext.php`.
- **Fix:** `final readonly class` (or PHP 8.4 `public private(set) ?int $priority`). The getter must stay while `Autoconfiguration::getProperties()` reads `get*()` methods. The two classes are identical apart from their name; document which tag each produces in a docblock.

### I-06 — Data collector and template
- **Where:** `Collector/StoreCollector.php`, `Resources/config/services/profiler.yml`, `Resources/views/Collector/store.html.twig`.
- **Fix:**
  - Extend `Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector` and implement `static getTemplate()`; then `#[AutoconfigureTag('data_collector', …)]` / autoconfigure replaces the manual tag.
  - `:35` `private $storeChangeSupport = false` → `private readonly bool $storeChangeSupport = false`; mark promoted properties `readonly`.
  - `data['admin']` is written but never read; either show "admin request" in the toolbar or drop it.
  - Template `:5` `status_color` is computed inside `{% if collector.store is not null %}`, so it is always `''`; `|default('Undefined')` on `name` is dead too. The "change" link `?_store_id=…` drops the current query string — build it from `app.request` (`path(app.request.attributes.get('_route'), app.request.query.all|merge({_store_id: store.id}))`).
  - `findAll()` loads full entities for the toolbar; only id / name / siteId are needed.

### I-07 — Debug store classes
- **Where:** `Context/Debug/*`, `Resources/config/services/debug.yml`.
- **Fix:**
  - `DebugStorePersister:34` `HttpKernelInterface::SUB_REQUEST === $event->getRequestType()` → `!$event->isMainRequest()`; register with `#[AsEventListener(KernelEvents::RESPONSE)]` instead of the YAML tag; rename `$filterResponseEvent` / `$debugStoreCode` (it is an id).
  - `:45` `new Cookie('_store_id', …)` → `Cookie::create()` with explicit `secure`, `httpOnly`, `sameSite` and an expiry.
  - `DebugStoreProvider`: validate the id (`ctype_digit()`) before it reaches `find()`; share the `'_store_id'` literal as a constant between provider, persister and template.
  - `DebugStoreContext:45-55`: `@var` + `@psalm-suppress DocblockTypeContradiction` workaround → `$store = $this->storeRepository->find(…); if (!$store instanceof StoreInterface) throw …`; `$masterRequest` → `$mainRequest`.
  - `debug.yml:17` the `type: debug` tag attribute is not read by `PrioritizedCompositeServicePass`; remove it.

## Code quality and readability

### I-08 — Pimcore core extensions use the static container and untyped API
- **Where:** `CoreExtension/Store.php`, `CoreExtension/StoreMultiselect.php`, `CoreExtension/StoreOptionProvider.php`.
- **Fix:**
  - `Store.php:44-52` `\Pimcore::getContainer()->get(…)` / `getParameter(…)`: unavoidable in Pimcore field definitions today, but add native return types (`getRepository(): StoreRepositoryInterface`) and cache the lookups in a static/`private` property.
  - `Store.php:38-42` untyped public properties `$options` / `$optionsProviderClass` with docblocks → `public ?array $options = [];`, `public string $optionsProviderClass = …` (check compatibility with `ResourceBundle\CoreExtension\Select` first). `getOptionsProviderClass()`, `getDataForGrid()`, `setOptions()`, `getOptions()` have no native types.
  - `Store.php:84` `$optionsProvider->{'getOptions'}($context, $this)` → plain method call (assert `SelectOptionsProviderInterface`).
  - `Store.php:69-97` mutates the shared field definition (`setOptions()`) on every grid render; compute options locally instead.
  - `Store.php:23,109` `use InvalidArgumentException;` + unqualified class vs. `\Pimcore` qualified — pick one style.
  - `StoreMultiselect` and `Store` both hard-code `'@' . StoreOptionProvider::class`; share a constant.
  - `StoreOptionProvider`: make it `final`; sort options by name; `hasStaticOptions()` should be documented (true is correct because the options do not depend on the object).
  - `@psalm-suppress InvalidReturnType, InvalidReturnStatement` on both classes hides real type issues; remove and fix.

### I-09 — Controller
- **Where:** `Controller/StoreController.php:28-46`.
- **Fix:** `Site\Listing::setOrder('ASC')` without `setOrderKey()` adds no `ORDER BY`; use `setOrderKey('mainDomain')`. `getSites()` already returns `Site[]`, the `instanceof` check is redundant. Return type `JsonResponse` (like the parent actions). Add the "no site" entry (TODO-2 **B-07**) and the permission check (TODO-2 **B-09**).

### I-10 — Repository
- **Where:** `Doctrine/ORM/StoreRepository.php`.
- **Fix:**
  - `:46` `o.isDefault = 1` → `o.isDefault = :isDefault` with `setParameter('isDefault', true)` (DB-neutral, readable).
  - `setMaxResults(1)` in both finders (TODO-1 **C-01**).
  - Add a sorted finder (`findAllOrderedByName()`) for `StoreChoiceType` and `StoreOptionProvider` instead of sorting in PHP.
  - `createListQueryBuilder()` is identical to `createQueryBuilder('o')`; document why it exists (admin grid hook) or remove it from the interface.

### I-11 — Form types
- **Where:** `Form/Type/StoreChoiceType.php`, `Form/Type/StoreType.php`.
- **Fix:**
  - `StoreChoiceType:48-59`: the `choices` closure loads and sorts all stores on every form build; use `choice_loader` with `ChoiceList::lazy()` (cached per form type) or `EntityType` with a query builder sorted in SQL; `static fn` for the comparator; inject `StoreRepositoryInterface` instead of the generic `RepositoryInterface`.
  - `StoreType`: add `isDefault` (TODO-1 **C-02**); `siteId` as a `ChoiceType` fed by the same source as `listSitesAction()` so the form validates the value (today any integer is accepted); `empty_data` for `siteId` (TODO-2 **B-07**).

### I-12 — Doctrine mapping and validation
- **Where:** `Resources/config/doctrine/model/Store.orm.xml`, `Resources/config/validation/Store.yml`.
- **Fix:**
  - Explicit `type="string" length="255"` on `name` / `template`; index on `isDefault`; unique index on `siteId` after making it nullable (TODO-1 **C-01**).
  - `<generator strategy="AUTO"/>` → `IDENTITY` (ORM 3 changed the meaning of `AUTO` on PostgreSQL; explicit is clearer).
  - `currency` `many-to-one` has no `on-delete`; deleting a currency used by a store fails with an FK error — handled in CurrencyBundle TODO-1 (dependent).
  - Validation: `Length(max: 255)` on `name` / `template` (TODO-2 **B-08**), `NotNull` on `siteId` / `isDefault`, uniqueness of site and default store (TODO-1 **C-01**). `Type: boolean` on `isDefault` is meaningless while the form has no such field.
  - Consider validating that `template` is a known theme (`Callback` against the Sylius theme repository) — a typo silently falls back to the default theme.

### I-13 — Service definitions
- **Where:** `Resources/config/services.yml`, `services/{form,pimcore,profiler,theme,debug}.yml`.
- **Fix:**
  - `_defaults: public: true` in every file makes nearly every service public; only aliases used via `\Pimcore::getContainer()` (`coreshop.repository.store`, `StoreOptionProvider` for the `@`-provider lookup) and `FixedStoreContext` (Behat) need to be public. Mixed `public: false` overrides in `services.yml:13,16,39,42,51,58` show the intent.
  - `form.yml:12,19` double space in `-  { name: form.type }`.
  - Use `autowire: true` / `autoconfigure: true` for the bundle's own classes (forms, collector, theme resolver, debug classes) and drop the manual argument lists.
  - Tag `CachedStoreContext` / `FixedStoreContext` with `kernel.reset` (TODO-2 **B-01**).

### I-14 — Unused helper
- **Where:** `Helper/PimcoreSiteHelper.php`, `Helper/PimcoreSiteHelperInterface.php`.
- **Problem / Fix:** not registered as a service and not referenced anywhere in the code base. `getCurrentSite(): Site` throws a generic `Pimcore\Model\Exception` outside a site (`vendor/six-it/pimcore-source/models/Site.php:178-185`). Either delete it, or wire it and use it in `SiteBasedRequestResolver` (removes the static `Site::` calls, makes the resolver unit-testable) with `getCurrentSite(): ?Site`.

### I-15 — Store component clean-ups — (dependent)
- `Model/Store.php` / `StoreInterface.php`: untyped properties and accessors with docblocks (`getName()`, `setName($name)`, `getSiteId()`, …) → native types (`?string`, `bool`, `?int`); `setId($id)` untyped.
- `Model/StoresAwareTrait.php:31-34`: a trait with a constructor conflicts with every using class that has its own constructor; `hasStores()` is not in `StoresAwareInterface`; methods untyped.
- `Model/StoreAwareTrait.php:23-26`: `@var StoreInterface` on a nullable property → `protected ?StoreInterface $store = null`.
- `Context/StoreNotFoundException.php:24`: `?\Exception $previousException` → `?\Throwable`; same in `ThemeBundle/Service/ThemeNotResolvedException.php`.
- `Context/CompositeStoreContext.php` / `CompositeRequestResolver.php`: iterating a Laminas `PriorityQueue` clones the inner heap on every `getStore()` call; sort once into an array (or use `AutowireIterator`, **I-04**).
- `Context/SiteBasedResolver.php:42-48` → `return $this->storeRepository->findStandard();`.
- `Context/RequestBased/StoreContext.php`: `\UnexpectedValueException` is thrown only to be converted to `StoreNotFoundException` two lines later; throw `StoreNotFoundException` directly. `$masterRequest` → `$mainRequest`.
- `Context/RequestBased/PimcoreAdminSiteBasedRequestResolver.php:50` uses `@internal` `Document\Service::getNearestDocumentByPath()` (`@psalm-suppress InternalMethod`); check for a public alternative in Pimcore 11/12. Hard-coded admin route names in this class and `PimcoreAreaBrickRenderResolver` should be constants.

### I-16 — Theme resolver
- **Where:** `Theme/StoreThemeResolver.php:33-46`.
- **Fix:** assignment inside `if` (`if ($theme = $store->getTemplate())`) → separate statement; treat `''` and `null` explicitly; name the caught variable consistently with the other classes.

### I-17 — Admin JS (config-relevant parts)
- **Where:** `Resources/public/pimcore/js/item.js`.
- **Fix:** add the `isDefault` checkbox (TODO-1 **C-02**) and a "no site" entry for the site combo (TODO-2 **B-07**); trailing comma at `:94`; `getSaveData()` can return `getFieldValues()` directly.

## Logic / maintainability

### I-18 — Tests
- The bundle has no tests. Add at least: a container test (compile with the bundle's config, both autoconfiguration modes, `debug` on/off, B-05 boolean handling), a configuration tree test, unit tests for `CompositeStoreContext` / `CompositeRequestResolver` priority order and `null` handling (TODO-2 **B-02**), `CachedStoreContext` reset behaviour (TODO-2 **B-01**), `SiteBasedResolver` with zero / one / two default stores (TODO-1 **C-01**, **C-02**), and a `StoreCollector` test covering `reset()` + admin / exception paths (TODO-2 **B-11**).
