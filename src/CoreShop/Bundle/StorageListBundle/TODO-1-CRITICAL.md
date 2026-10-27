# StorageListBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (20 files: `CoreShopStorageListBundle.php`, `Controller/*`, `Core/EventListener/*`, `DependencyInjection/**`, `EventListener/*`, `Form/Type/*`, `Pimcore/Repository/*`), `composer.json`, `Resources/config/services.yml`. Because the bundle is a pure wiring layer, every service it creates was followed into `Component/StorageList` (`Context/*`, `Core/**`, `Factory/*`, `Storage/*`, `Manager/*`, `Maintenance/ExpireTask`, `StorageListsManager`) and `Component/Registry/PrioritizedCompositeServicePass`. Dependent config that feeds this bundle was read too: `OrderBundle/Resources/config/pimcore/config.yml` (list `order`), `WishlistBundle/Resources/config/pimcore/config.yml` (list `wishlist`), `WishlistBundle/Resources/config/services{,/commands}.yml`, `OrderBundle/Resources/config/services/{context,cart,commands}.yml`, `CoreBundle/Resources/config/services/context.yml`, `CoreBundle/Security/AccessVoter.php`, `CoreBundle/EventListener/CartBlamerListener.php`, `FrontendBundle/Controller/CartController.php`, `FrontendBundle/Resources/install/pimcore/staticroutes.yml`.

Reference for API checks: `vendor/` (`symfony/*` **v7.4.21**), the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml` (+ its `…Deprecations.log`).

"Critical" here means: a security issue, data written wrongly / lost, or an error that takes down every request, a whole area (cart, wishlist, checkout) or the container build once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code (PHP 8.4.27), by reading the vendored library source, or in the compiled container.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers. `php -l` (8.4.27) passes on every PHP file, all `coreshop.storage_list.*` services of the `order` and `wishlist` lists resolve in the compiled container, no implicitly nullable parameters. One container deprecation belongs to this bundle (`RegisterAllStorageListsPass::process()` without `: void`, TODO-3 **I-04**). The modernisation items are in TODO-3 (**I-01** to **I-08**).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security

### C-01 — Open redirect: `_redirect` request parameter is passed to `redirect()` unchecked — (verified by code path)
- **Where:**
  - `Controller/StorageListController.php:88`: `$redirect = $this->getParameterFromRequest($request, '_redirect', …)` reads route attribute → query → body.
  - Used at `:99` (product not found, **also on GET**), `:132` (success) and `:151` (form invalid).
  - The privilege checks at `:74-86` never stop it: `CoreBundle/Security/AccessVoter.php` grants **every** `CORESHOP_*` attribute (`voteOnAttribute()` returns `true`).
- **Reproduce:** `GET /en/shop/wishlist/add/999999999?_redirect=https://evil.example` → product not found → `302 Location: https://evil.example`. The link is on the shop's own domain, so it is a good phishing vector.
- **Fix:**
  - Accept only relative paths (`str_starts_with($r, '/') && !str_starts_with($r, '//')`), or compare the host with `$request->getHost()`, else fall back to the summary route.
  - Better: drop `_redirect` and use a route name + params whitelist; or use `$request->headers->get('referer')` only if same host.
  - Same check for the `Referer` based redirects in `StorageMultiListController.php:157-159,165-167` (TODO-2 **B-07**).

### C-02 — Template name comes from the request: any Twig template known to the loader can be rendered by a visitor
- **Where:**
  - `Controller/StorageListController.php:78` (GET without privileges) and `:160` (`$template = $this->getParameterFromRequest($request, 'template', …)`).
  - `Controller/StorageMultiListController.php:98` and `:174`.
  - Attribute/query/body are all accepted (`getParameterFromRequest()` `:276-291`, `StorageMultiListController.php:184-199`).
- **Impact:**
  - `?template=@SomeBundle/admin/…html.twig` renders arbitrary templates of every registered namespace (admin, email, PDF templates) with the controller's variables: information disclosure, error pages with stack traces in dev, unexpected side effects of templates that call `render(controller())` / Twig functions.
  - A query string value that is an array (`?template[]=x`) reaches `render(string $view)` → `TypeError` → 500.
  - It also defeats HTTP caching (each value is a different cache key).
- **Fix:** remove the request override. If it is needed for `_partial` routes, allow it only from **route defaults** (`$request->attributes`), or validate against a configured whitelist (`templates:` config already exists).

## Container build / whole area down

### C-03 — Parameters and services exist only when a feature is enabled, but other bundles read them unconditionally — (verified by code) (dependent)
- **Where:**
  - `DependencyInjection/CoreShopStorageListExtension.php:165-169`: `coreshop.storage_list.multi_list.<name>` is set **only** if `multi_list.enabled: true`.
    → `FrontendBundle/Controller/CartController.php:67,71` calls `$this->getParameter('coreshop.storage_list.multi_list.order')` → `ParameterNotFoundException` → **cart summary 500** as soon as a project sets `multi_list.enabled: false` for the `order` list.
  - `:317-346`: `coreshop.storage_list.expiration.<name>` (service) and `coreshop.storage_list.expiration.<name>.days` / `.params` (parameters) exist only when `expiration.enabled: true`.
    → `WishlistBundle/Resources/config/services/commands.yml:7-9` and `OrderBundle/Resources/config/services/commands.yml:8,15` reference them → **container build fails** when a project disables expiration for `wishlist` / `order`.
- **Fix:**
  - Always define `coreshop.storage_list.multi_list.<name>` (`false` when disabled).
  - Always define `…expiration.<name>.days` / `.params`; register the expire command in the extension (or via `ifTrue`) only when expiration is enabled, or give the command an `on-invalid="ignore"`/nullable service.
  - Add a kernel test that boots with every feature flag `false`.

### C-04 — Logout listener is hard-wired to the firewall dispatcher `security.event_dispatcher.coreshop_frontend` — (verified in vendor source)
- **Where:** `DependencyInjection/CoreShopStorageListExtension.php:289-306` (`'dispatcher' => 'security.event_dispatcher.coreshop_frontend'`).
- **Why it breaks:** `vendor/symfony/event-dispatcher/DependencyInjection/RegisterListenersPass.php:107-108` calls `$container->findDefinition($event['dispatcher'])` → `ServiceNotFoundException` if that firewall does not exist.
- **Impact:** `wishlist` has `enable_logout_subscriber: true` by default (`WishlistBundle/Resources/config/pimcore/config.yml:13`). Any project that renames / removes the `coreshop_frontend` firewall (or uses CoreShop headless with another firewall name) gets a **container build failure** with an error message that does not mention the storage list.
- **Fix (pick one):**
  - Register the listener on the **global** dispatcher (no `dispatcher` attribute). `vendor/symfony/security-bundle/DependencyInjection/Compiler/RegisterGlobalSecurityEventListenersPass.php:44` copies global `LogoutEvent` listeners to every firewall dispatcher; inside the listener, filter on the firewall if needed (`$event->getToken()` / firewall map).
  - Or add a config option `session.logout_firewall` (default `coreshop_frontend`) and resolve it in a compiler pass (not in `load()`; the security bundle creates the dispatcher later), skipping the tag with a clear error/log if the dispatcher does not exist.

### C-05 — Missing or wrong config values crash with PHP `TypeError` / "undefined array key" instead of a config validation error — (verified by code)
- **Where (all in `DependencyInjection/`):**
  - `Configuration.php:73` `list_resolver` defaults to `null`; `CoreShopStorageListExtension.php:195` does `new Reference($list['services']['list_resolver'])` → `Reference::__construct(string $id)` under `strict_types` → **`TypeError` at container build** for every list with `multi_list.controller.enabled: true` and no resolver.
  - `Configuration.php:63` `context.tag` is `cannotBeEmpty()` but not required and has no default; `context` uses `addDefaultsIfNotSet()`, so the key is simply missing → `$list['context']['tag']` "undefined array key" warning → `addTag(null)` (`:101,107,242,277`) → `TypeError`.
  - `Configuration.php:91-106` `form.type`, `form.add_type`, `routes.summary`, `routes.index`, `templates.*` are optional; `CoreShopStorageListExtension.php:152-157` copies them into `string` constructor arguments → the controller can't be instantiated → **every wishlist route 500** (`TypeError` at runtime, not at build).
  - `Configuration.php:138-139` `multi_list.templates.*` same problem (`:197-198`).
  - `CoreShopStorageListExtension.php:226`: `\class_implements($list['resource']['interface'])` on a non-existing class / unresolved `%param%` → `false` + warning → Customer/Store contexts are **silently not registered** (TODO-2 **B-06**).
- **Fix:** model the dependencies in `Configuration` (`isRequired()` for `context.tag`; `validate()->ifTrue(fn ($v) => $v['controller']['enabled'] && (empty($v['form']['type']) || …))->thenInvalid(…)`; same for `multi_list.controller.enabled` ⇒ `services.list_resolver` + templates). In the extension assert `class_exists()/interface_exists()` of `resource.interface` and throw an `InvalidConfigurationException` naming the list.

## Data written wrongly

### C-06 — A controller class that is already a service gets one shared `Definition` for all lists: the last list overwrites the arguments of the previous ones — (plausible)
- **Where:** `DependencyInjection/CoreShopStorageListExtension.php:135-139` and `:173-177`:
  ```php
  if ($container->has($class)) { $controllerDefinition = $container->getDefinition($class); } else { new Definition($class); }
  …->setArgument('$identifier', $name); … $container->setDefinition('coreshop.storage_list.controller.' . $name, $controllerDefinition);
  ```
- **Impact:** if a project registers its own controller class (e.g. via `App\` resource autoloading) and uses it for two lists (`wishlist` + a custom `compare` list), both service ids point to the **same `Definition` object**. All `setArgument()` calls of the second list overwrite the first: the wishlist routes then use the other list's repository, context, modifier and manager → items are written into the wrong list type / wrong Pimcore class, permissions are checked with the wrong identifier.
  - Additionally `has()` is `true` for **aliases**, but `getDefinition()` throws for an alias → container build error. Use `findDefinition()`.
- **Fix:** always create a new definition per list: `$def = $container->has($class) ? clone $container->findDefinition($class) : new Definition($class);` (or `new ChildDefinition($class)`), then `setDefinition()`. Never mutate the shared definition.
