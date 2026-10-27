# StorageListBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items (Symfony 8). None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as sub-bullets.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle.

**Already fine for Symfony 7.4:** service config is YAML (not the XML service format deprecated in 7.4), `TreeBuilder` is constructed with a root name, constraints use named arguments, no `ContainerAwareInterface`, form types have native return types, controllers do not call the 7.4-deprecated `Request::get()` (the local `getParameterFromRequest()` re-implements it), all constructor parameters use property promotion.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — `composer.json` does not describe what the code needs
- **Where:** `composer.json:26-34`.
- **Fix:**
  - No `php` constraint → add `"php": "^8.4"`.
  - Undeclared direct dependencies: `symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel`, `symfony/http-foundation`, `symfony/framework-bundle` (`AbstractController`), `symfony/form`, `symfony/options-resolver`, `symfony/validator`, `symfony/security-http` (`LogoutEvent`, `InteractiveLoginEvent`), `symfony/event-dispatcher`, `symfony/routing`, `symfony/translation-contracts` (all `^7.4`); `pimcore/pimcore` (`PimcoreContextResolver`, `RequestHelper`, `Pimcore\Cache`, `Concrete`); `coreshop/registry` (`PrioritizedCompositeServicePass`).
  - `Core/**` and the Core branch of the extension use `coreshop/core`, `coreshop/core-bundle` (`CustomerRegistrationEvent` in `StorageListBlamerListener.php:21`), `coreshop/customer`, `coreshop/store`. Either declare them in `suggest` and guard every use with `class_exists()`, or move `Core/**` to CoreBundle (dependent) — currently the bundle hard-depends on CoreBundle for a type hint.
  - `require-dev` pins PHPStan 1.x → PHPStan 2 (PHP 8.4 rules) together with the other bundles.
  - `"minimum-stability": "dev"` and `repositories` (`path` `../../*/*`) have no effect for consumers; move to the monorepo root.

### I-02 — Configuration tree: names, node types, validation, docs
- **Where:** `DependencyInjection/Configuration.php`.
- **Fix:**
  - `:39` root name `coreshop_storage_list` differs from the extension alias `core_shop_storage_list` used in every config file (`OrderBundle/…/config.yml:19`, `WishlistBundle/…/config.yml:1`). Error messages and `config:dump-reference` show the wrong path. Either override `getAlias()` to `coreshop_storage_list` (and migrate configs) or rename the tree root.
  - `:72` `enable_default_store_based_decorator` is a `scalarNode` with `defaultFalse()` → `booleanNode()`.
  - `:63`, `:91-106`, `:138-139` conditional requirements → see TODO-1 **C-05**.
  - `:155` `expiration.days` → `integerNode()->min(0)`; `:156` `params` `variableNode` → `arrayNode()->variablePrototype()` (so a scalar is rejected); `:154` `service` should be validated as non-empty string when set.
  - `:145` `multi_list.form.class` has no default and no `cannotBeEmpty()`; nullable is intended (controller arg `string|null`), document it with `->info()`.
  - Add `->info()`/`->example()` for every node (the bundle has no documentation, README is generic).
  - `:34-36` `@inheritdoc` docblock is redundant.

### I-03 — Split `CoreShopStorageListExtension::load()` (315 lines, 5 nesting levels)
- **Where:** `DependencyInjection/CoreShopStorageListExtension.php:58-373`.
- **Fix:**
  - One private method per feature: `registerContext()`, `registerSession()`, `registerController()`, `registerMultiList()`, `registerCoreServices()`, `registerExpiration()`; use a small value object / array shape for the list config.
  - `:183` duplicated `setArgument('$repository', …)` line.
  - `:219-223` vs `:231-234`, `:270`: `context_provider.<name>` and `storage.<name>` are defined and then **re-defined**; decide once.
  - `:333`, `:343` `$list['expiration']['params'] ?? []` — the key always exists (default `[]`), `??` is dead.
  - Service-id prefixes (`'coreshop.storage_list.context.factory.' . $name` etc., 15+ places) → private constants / `sprintf` helpers; `"…{$name}"` interpolation instead of concatenation + `sprintf`.
  - `:75-83` `interface_exists()` check happens after `$contextCompositeServiceName` is computed; do validation first.
  - Magic priorities `-999`, `-777`, `-555` → named constants with a comment explaining the resolution order (session > customer/store > factory).
  - `:334-340` `setTags([...])` overwrites all tags → `addTag('pimcore.maintenance.task', ['type' => …])`.

### I-04 — Compiler passes
- **Where:** `DependencyInjection/Compiler/`.
- **Fix:**
  - `RegisterAllStorageListsPass.php:26` `process()` has no `: void` → **container deprecation** "might add void as a native return type" (verified in `App_KernelDevDebugContainerDeprecations.log`). Required for Symfony 8.
  - `:32-35` `$tags` from a parameter spread into a constructor (`...$tag`) → typed array shape `list<array{0: string, 1: string, 2: string}>` + `@var`; or store the tuples on the pass itself instead of a container parameter (`coreshop.storage_list.tags` is a public parameter nobody else reads).
  - `RegisterStorageListProcessorPass.php` is **unused** (no reference in the repo) → delete or document as extension point.
  - Both pass classes are trivial `PrioritizedCompositeServicePass` subclasses; the call with named args would do.

### I-05 — Controllers: readability, duplication, Symfony 7.4 idioms
- **Where:** `Controller/StorageListController.php`, `Controller/StorageMultiListController.php`.
- **Fix:**
  - `getParameterFromRequest()` is copy-pasted (`StorageListController.php:276-291`, `StorageMultiListController.php:184-199`) → trait or drop it (only route attributes should be honoured, see TODO-1 **C-02**); `$default` untyped → `mixed $default = null`, return type `mixed`.
  - 19 / 14 constructor args: group the list config into a `StorageListControllerConfig` value object (identifier, routes, templates, form types) built in the extension.
  - Promoted properties are `protected` mutable → `protected readonly` (classes are extensible via `controller.class`, so not `final`).
  - JSON responses → `$this->json([...])`; `in_array(…, [...])` → `in_array(…, [...], true)` or `$request->isMethod()`; closures → arrow functions (`:145-147`).
  - Consistent form passing to `render()`: pass the `FormInterface` (not `createView()`) everywhere so `AbstractController::render()` sets 422 on invalid submits (`vendor/symfony/framework-bundle/Controller/AbstractController.php:459,477`). Today `StorageListController` passes views, `StorageMultiListController` passes the form.
  - `@var FormError $error` / `@var AddToStorageListInterface` inline docblocks → `assert()`/`instanceof`.
  - Privilege strings built 8× with `sprintf(strtoupper(...))` → one method / constants (see TODO-2 **B-09**).
  - Consider `#[IsGranted]` and `#[IsCsrfTokenValid]` attributes (Symfony 7.1+) for the guards.

### I-06 — Event listeners
- **Where:** `Core/EventListener/*`, `EventListener/*`.
- **Fix:**
  - All listeners are stateless → `final readonly class` (PHP 8.2+).
  - `SessionStoreStorageListLogoutSubscriber` is not a subscriber (no `EventSubscriberInterface`) → rename to `…LogoutListener`; `onLogoutSuccess` → `onLogout` (the event is `LogoutEvent`, "success" is the pre-5.1 name); `:46` duplicate `$request` assignment.
  - `StorageListBlamerListener.php:83` unreachable `return null;`; `:53` variable `$user` holds a customer; event names `'security.interactive_login'` / `'coreshop.customer.register'` in the extension (`:311-312`) → `SecurityEvents::INTERACTIVE_LOGIN` and a constant.
  - `SessionSubscriber.php:76` `sprintf('%s', $this->sessionKeyName)` is a no-op; `:59-60`, `SessionStoreStorageListSubscriber.php:61-62` `/** @var Request */` docblocks unnecessary.
  - `SessionSubscriber` and `SessionStoreStorageListSubscriber` share 80 % of their guard code (admin context, main request, `_wdt`, session) → extract a shared guard.
  - `CacheListener.php:42-46` registers the ignored cache tags on **every** response (including admin/sub-requests) and twice per request when more than one list disables caching; it also queries `getClassId()` each time. Do it once on `kernel.request` (main request only) or pass the class ids as constructor scalars resolved at build time.

### I-07 — Forms: dead and incomplete types
- **Where:** `Form/Type/`.
- **Fix:**
  - `AddToStorageListType`, `StorageListType`, `StorageListItemType` are **never registered** as services (they extend `AbstractResourceType`, whose constructor needs `$dataClass`, so the form registry cannot instantiate them) and are not referenced anywhere. `AddToStorageListType.php:30` also maps a property `wishlistItem` that does not exist on `AddToStorageList` (only `storageListItem`). Delete them or register + fix them as generic defaults.
  - `CreatedNamedStorageListType`: add constraints (TODO-2 **B-08**), `label` / `translation_domain`, `getBlockPrefix()`; the name "Created" should be "Create".
  - `StorageListChoiceType.php:53` option `'active' => true` is not used by `ChoiceType` or the subclasses → remove; `:41-50` closures → typed arrow functions; `:50` label `'default'` should be a translation key; `:31-33` `private` → `private readonly`.

### I-08 — Services, repository, misc
- **Fix:**
  - `Resources/config/services.yml:5-6` `_defaults: public: true` makes every service public → keep only those fetched via `$container->get()` / static routes public (controllers are already public via their tags).
  - `services.yml:8` `StorageListsManager` is wired (`addList()` per list) but **no code in the repo reads it** → document as public API or drop. Missing trailing newline at EOF.
  - `Pimcore/Repository/PimcoreStorageListRepository.php` is not registered or referenced (Order/Wishlist repositories implement the interface themselves); `findByStorageListId()` returns `find()` (mixed) without type check → delete or make it the base class of those repositories (dependent).
  - Extension defines definitions with `new Definition(...)` without `setAutowired`/`setAutoconfigured`; add explicit `setPublic(false)` where not needed.
  - `CoreShopStorageListBundle.php:44` model namespace string → `'CoreShop\\Component\\StorageList\\Model'` is fine, but could be `StorageListInterface::class`-derived to survive refactors.
  - `README.md` is the generic CoreShop template (http links to coreshop.org) → document the `core_shop_storage_list` config tree, the service ids created per list, the permission attributes and the context resolution order.
  - No tests: add a `ContainerBuilder`-based extension test (all flags on/off, two lists sharing a controller class, missing optional values) and functional tests for add/remove/summary/redirect handling.
