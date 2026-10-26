# FrontendBundle – TODO 3: Improvements (code quality, readability, logic, PHP 8.4 / Symfony 7.4 idioms)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
None of these are defects on their own. They make the bundle cleaner, safer, easier to test, or more idiomatic for the target stack.
Paths are relative to the bundle root unless noted otherwise.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 language features

### I-01 — Add `#[\Override]` everywhere a parent or interface method is implemented
- **Where:** `CoreShopFrontendBundle.php` (`registerDependentBundles`, `build`, `getNiceName`, `getDescription`, `getVersion`), `DependencyInjection/*` (`getConfigTreeBuilder`, `load`, `process`), `Command/InstallFrontendCommand.php` (`configure`, `execute`), `Installer/*` (`installFrontend`), `TemplateConfigurator/TemplateConfigurator.php` (`findTemplate`), `Twig/*` (`getFunctions`, `getFilters`), `Form/Type/SearchType.php`, and every controller's `getSubscribedServices()`
- PHP 8.3+ then reports signature drift at compile time. That is useful here because projects routinely subclass these controllers (`core_shop_frontend.controllers.*`).

### I-02 — Constructor promotion, `readonly` and visibility
- `Installer/FrontendInstaller.php:25-28`, `TemplateConfigurator/TemplateConfigurator.php:23-26` and `Twig/LocaleSwitcherExtension.php:36-42`: mark the promoted properties `readonly`. Better still, make the classes `final readonly class` where nothing extends them.
- `Command/InstallFrontendCommand.php:34`: `protected FrontendInstallerInterface $frontendInstaller` on a `final` class should be `private readonly`.
- `TemplateConfigurator/TemplateConfigurator.php:24`: typo `$tempaltePrefix` → `$templatePrefix`. `:29` drops the `string` type from the interface's `findTemplate(string $templateName)`. Restore it.
- Make the `Installer/*` classes `final`, as well as `TemplateConfigurator` and `SearchType`. If extension is a supported use case, document it and keep them open on purpose. `RegisterFrontendControllerPass` is not `final` either.

### I-03 — Remove the odd empty-constructor formatting left over from an automated refactor
- **Where:** `DependencyInjection/CompilerPass/FrontendInstallerPass.php:27-28` (`public function __construct(\n        ) {`)
- Should be `public function __construct()`. Run php-cs-fixer (`method_argument_space`, `no_spaces_inside_parenthesis`) over the bundle.

### I-04 — Complete the native return and parameter types
- `CartController::createQuoteAction()` (`:85`) and `QuoteController::showAction()` (`:32`) have no return type → `: Response`.
- `CheckoutController::stepParams()` (`:334`): no return type, and `$request` is unused → `private function stepParams(string $stepIdentifier): array`. Better still, inline `['stepIdentifier' => $id]`.
- `FrontendController::getParameterFromRequest()` (`:60`): `mixed $default = null): mixed` (or remove it, see I-06).
- `CartController::getQuantityModifer()` (`:382`) has a typo → `getQuantityModifier()`. Keep the old name as a deprecated proxy if it is part of the extension API.
- Add `@return list<array{language: string, target: string, displayLanguage: string}>` to `LocaleSwitcherExtension::getLocalizedLinks()`, and `array<string, mixed>` shapes on the various `$viewParameters`.

### I-05 — Small PHP idiom cleanups
- `CoreShopFrontendBundle.php:58-60`: `class_exists('\\CoreShop\\…\\Version')` + FQCN call → `use … Version;` + `class_exists(Version::class)`.
- `CategoryController.php:264`: `sprintf(sprintf(...))` → one `sprintf`. `:252-256` / `ProductController.php:76-80`: the `$isFrontendRequestByAdmin` flag dance → `$isAdmin = $this->container->get(RequestHelper::class)->isFrontendRequestByAdmin($request);`.
- `CategoryController.php:138`: `$variantMode ? $variantMode : …` → `$variantMode ?: …`.
- `CustomerController.php`: `sprintf('%s.%s.%s_post', 'coreshop', 'address', $eventType)` → string constants or a small `ResourceEvents` helper. The current form is hard to grep.
- `CartController.php:110-115`: `method_exists($form, 'getClickedButton')` is always true for `Form`. Use `$form->getClickedButton()?->getName() === 'submit_voucher'` directly.
- `CartController.php:289-291`: `array_map(function (FormError $e) {…}, iterator_to_array(...))` → `array_map(static fn (FormError $e): string => $e->getMessage(), …)`.

## Symfony 7.4 idioms

### I-06 — Replace `getParameterFromRequest()` with typed request access
- **Where:** `Controller/FrontendController.php:55-75`, used about 25 times across the controllers.
- It re-implements `Request::get()`, which is **deprecated since Symfony 7.4** ("use properties `->attributes`, `query` or `request` directly"), and it returns unvalidated `mixed` (root cause of TODO-2 B-01, B-02, B-15, B-16).
- Route placeholders (`product`, `category`, `token`, `stepIdentifier`, `address`, …) belong in `$request->attributes->getInt()` / `getString()`. Query options (`page`, `perPage`, `sort`, `type`) can use `#[MapQueryParameter(filter: \FILTER_VALIDATE_INT, options: ['min_range' => 1])] int $page = 1` on the action. Body fields belong to forms.
- Keep a deprecated BC shim for one minor release (projects call it from subclasses), and trigger `trigger_deprecation('coreshop/frontend-bundle', '4.x', …)`.

### I-07 — Service-locator hygiene: inject what is used and remove what is not
- Wrong or unused subscriptions:
  - `CartController.php:425`: `'coreshop.storage'` typed as `CartPriceRuleUnProcessorInterface`. It is never used and the type is wrong.
  - `CartController.php:414`, `:426-427`: `coreshop.factory.order`, `coreshop.storage_list.context_provider.order`, `coreshop.storage_list.storage.order` are unused.
  - `CategoryController.php:301-321`: three parameter "services" (`validSortProperties`, …) are never read, because the code calls `getParameter()`.
  - `CheckoutController.php:365-368`: `getPayum()` is unreachable (no subscription) and unused.
  - `CartController.php:432-438`: `getCartItemErrors()` is private, unused, and needs an unsubscribed `validator`.
- Missing subscriptions are the bugs in TODO-2 B-04 and B-10. Add a smoke test that calls every action, or a unit test that resolves every `$this->container->get()` id against `getSubscribedServices()`, so this cannot drift again.
- Longer term: these controllers are services already. Constructor injection (`public function __construct(private readonly CartManagerInterface $cartManager, …)`) would remove the string ids, give static analysis something to work with, and make the `container->get('coreshop.repository.x')` typing problems go away. The subscriber pattern can stay for the BC-sensitive `protected get*()` hooks.

### I-08 — Remove the dead `coreshop.frontend.controller.abstract` service
- **Where:** `Resources/config/services.yml:13-17`.
- It is never used as a `parent:` anywhere in the repo, and it injects the **full** `@service_container` through `setContainer()`. That is an anti-pattern since Symfony 4 and conflicts with the PSR locator `FrontendController::__construct()` expects. Delete it, or deprecate it if third parties reference it.

### I-09 — `#[AsCommand]`, invokable commands, `Command::SUCCESS`
- **Where:** `Command/InstallFrontendCommand.php:39-69`; `services.yml:6-11` (tag with explicit `command:`).
- Use `#[AsCommand(name: 'coreshop:frontend:install', description: 'Install CoreShop Demo Frontend.')]` and drop `setName()`/`setDescription()`. On 7.3+ an invokable `__invoke(SymfonyStyle $io, #[Option] string $templatePath = 'templates'): int` is possible, but keep it compatible with the `AbstractInstallCommand` parent.
- `return 0;` → `return Command::SUCCESS;`. Rename the misleading `$coreBundle` variable (`:55`); it holds the *frontend* bundle.
- Fix the help text grammar: "command install" → "command installs".

### I-10 — Rate limiting for abuse-prone endpoints (`symfony/rate-limiter`)
- **Where:** voucher submission (`CartController::summaryAction()` `:117-139`), password-reset request (`RegisterController.php:74-120`), registration (`:44-72`), login (firewall `login_throttling`, see `CoreBundle/Resources/config/pimcore/security.yml`), search (`SearchController`), add-to-cart.
- Voucher codes in particular can be brute-forced today without limit, one request per guess. Use a `RateLimiterFactory` per IP and session, plus `login_throttling: { max_attempts: 5 }` on `coreshop_frontend`.

### I-11 — Account-security hardening for password change and reset
- After `changePasswordAction()` (`CustomerController.php:291-305`) and `passwordResetAction()` (`RegisterController.php:143-158`), invalidate the user's other sessions and remember-me tokens. For example, make the user implement `EquatableInterface` on the password hash, and add `remember_me.signature_properties: [password]`. After a password change, migrate the current session (`$request->getSession()->migrate(true)`).
- The reset page carries the raw token in the query string. Send `Referrer-Policy: no-referrer` (and `Cache-Control: no-store`) on that response, so the token does not leak to any third-party resource the layout loads.
- Show the success flash only after the event dispatch (`RegisterController.php:152-155`), so a listener exception does not leave a "success" message behind.

### I-12 — Use `#[IsGranted]` and fix the meaning of the `CORESHOP_*` attributes
- The `denyAccessUnlessGranted('CORESHOP_…')` lines can become `#[IsGranted('CORESHOP_CART')]` on the action, which is less noise.
- Document in the bundle README that `CoreBundle/Security/AccessVoter` grants all of them by default, and that they are extension points. Projects otherwise assume `CORESHOP_CUSTOMER_PROFILE` protects something. Consider having the voter deny `CORESHOP_CUSTOMER_PROFILE*` when there is no customer, which would replace the scattered `if (!$customer) redirect` blocks.
- `CustomerController::addressAction()` (`:135-155`) decides between `…_EDIT` and `…_ADD` before the ownership check. Fold the ownership check into a voter on the address subject (`isGranted('CORESHOP_CUSTOMER_PROFILE_ADDRESS_EDIT', $address)`).

### I-13 — Twig extensions: attribute-based registration, static callables, lazy runtime
- Twig 3.21+ (installed with Symfony 7.4) supports `#[AsTwigFilter]` / `#[AsTwigFunction]` on plain service methods, so `MergeRecursiveExtension`, `TypeCastExtension` and `LocaleSwitcherExtension` lose their boilerplate and their manual `twig.extension` tags (`services.yml:26-41`).
- If they stay classic: use first-class callables (`array_merge_recursive(...)`, `$this->getLocalizedLinks(...)`) or `static fn`.
- `LocaleSwitcherExtension` builds `Document\Service`, `ShopperContext`, `RequestStack` and `Router` on every Twig init. Move the logic to a `RuntimeExtensionInterface` class so it is only instantiated when `coreshop_locale_switcher()` is called.
- `TypeCastExtension::coreshop_array` accepts only `object` (an array input gives a `TypeError`), and `coreshop_object` accepts only `array`. Accept `mixed` and return the value unchanged when it already has the target type. Add native return types to the closures (`: int`, `: float`, …).

### I-14 — Configuration tree cleanups
- **Where:** `DependencyInjection/Configuration.php`, `DependencyInjection/CoreShopFrontendExtension.php`.
- `default_sort_direction`: `->enumNode()->values(['asc', 'desc'])` instead of `scalarNode` + `validate()->ifNotInArray()`.
- Validate that `default_sort_name` is in `valid_sort_options` (`->validate()->ifTrue(...)` on the `category` node). Otherwise `parseSorting()` silently falls back to `name`.
- `controllers.*`: `->cannotBeEmpty()`, and validate `class_exists()` in a compiler pass for a clear error message.
- The `array_key_exists('pimcore_admin' | 'controllers', $configs)` checks in the extension are always true (`addDefaultsIfNotSet`). Remove them.
- Mark `view_bundle` for removal in 5.0 in `UPGRADE`, and fix B-20 first.
- Consider `AbstractBundle::configure()` / `loadExtension()` (Symfony 6.1+) to merge `Configuration` and the extension into the bundle class, but only if `AbstractModelExtension` stops being required.

## Logic, structure and readability

### I-15 — Move `getCart()` / `getCustomer()` / `getCartContext()` into `FrontendController`
- `getCart()` is duplicated in `CartController.php:392-400` and `CheckoutController.php:350-363` (with an unnecessary `@var` dance). `getCustomer()` is duplicated in `CustomerController.php:350-365` and `RegisterController.php:183-196`, with different semantics (see B-04). `CartContextInterface` is already subscribed in the parent.
- Pull them up as `protected` helpers with one implementation (`ShopperContextInterface::hasCustomer()` / `getCustomer()`), and let subclasses override them.

### I-16 — Search: move the query into the repository and the index
- `SearchController.php:52-67` builds raw SQL conditions against the Pimcore listing inside the controller, with hard-coded field names (`name`, `description`, `shortDescription`, `sku`) and a fixed `$itemsPerPage = 10`.
- Add `ProductRepositoryInterface::createSearchListing(string $text, StoreInterface $store)` (or use `IndexBundle`). Make the per-page value configurable (`core_shop_frontend.search.per_page`). Validate the form (see B-18).

### I-17 — Checkout controller: extract the repeated "dispatch → stopped? → flash → response/redirect" block
- **Where:** `CheckoutController.php:79-91`, `:131-143`, `:190-202`, `:224-238`, `:259-271`, which are five near-copies with subtle differences (B-12, B-13).
- Use something like `private function dispatchCheckoutEvent(string $name, CheckoutEvent $event, ?string $fallbackRoute = 'coreshop_index'): ?Response`. One code path makes the behaviour consistent and easier to read.
- Also remove the commented-out narrative block (`:162-177`) and the stale `TODO` (`:273-275`). Move the legacy-token fallback (`:209-216`, "@Todo: remove with CoreShop 5.0") into a migration or a cart-token listener, so the controller does not write to the database on a GET-reachable route.
- `prepareMessage()` (`:342-348`) stores an *array* in the flash bag. Document that `flash_messages.html.twig` must handle `{message, parameters}`, or translate it here with `$this->container->get('translator')->trans($message, $parameters)`.

### I-18 — Installers: tagged iterator instead of a custom registry, and a better UX
- **Where:** `DependencyInjection/CompilerPass/FrontendInstallerPass.php`, `Resources/config/services/frontend-installer.yaml`, `Installer/*`.
- Replace `RegisterSimpleRegistryTypePass` + `ServiceRegistry` with `#[AutoconfigureTag('coreshop.frontend.installer')]` on the interface and `#[AutowireIterator('coreshop.frontend.installer')] iterable $installers` in `FrontendInstaller`. That removes the pass and the registry service. Add `priority` so the order (templates → config → public) is explicit, not YAML order.
- `TemplatesInstaller` silently skips existing files (`:45-47`). Add `--force` / `--dry-run`, and return a list of written and skipped files for the command to print. Use `Filesystem::readFile()` (Symfony 7.1+) instead of `file_get_contents()` without an error check (`:49`).
- `PublicInstaller` copies to `public/coreshop/static`. That duplicates `assets:install` (`public/bundles/coreshopfrontend`), and the copy never updates. Document this, or symlink with `Filesystem::symlink()` as an option.
- Pass a small value object (`FrontendInstallContext(bundlePath, rootPath, templatePath, viewPrefix)`) instead of three loose strings, so a new parameter does not break every implementor.

### I-19 — Controller registration: simplify `RegisterFrontendControllerPass`
- **Where:** `DependencyInjection/CompilerPass/RegisterFrontendControllerPass.php`.
- `sprintf('…\\%sController', ucfirst($key))` + the `payment` special case → an explicit `const DEFAULT_IDS = ['cart' => CartController::class, 'payment' => PaymentController::class, …]`, shared with `Configuration::addControllerSection()`.
- Use `$container->register($id, $class)` / `setAlias()` consistently. Make the pass `final`. Document in the README how custom controllers are wired: the FQCN of the default controller stays the service id, because routes and templates hard-code it, for example `render(controller('CoreShop\\Bundle\\FrontendBundle\\Controller\\…'))`.
- Fix the bugs in TODO-2 B-21 first.

### I-20 — Templates: avoid building HTML in Twig strings, and sanitize rich text
- `Resources/views/Checkout/_order-steps.html.twig:8-10`: `title` concatenates `<a href="…">` + `identifier|trans` and prints it with `|raw`. A translation that contains `<`/`"` (or a step URL with quotes) becomes markup. Render the `<a>` in the template and escape the parts.
- `Resources/views/Product/detail.html.twig:136`: `product.description|raw`. It is admin-edited WYSIWYG, but it may come from imports or PIM feeds. Consider the `sanitize_html` filter (`symfony/html-sanitizer`, Symfony 6.1+).
- (dependent) `TestBundle/Twig/Extension/TestHtmlAttributeExtension.php`: `sprintf('data-test-%s="%s"', $name, $value)` with `is_safe: html` does not escape `$value`. Use `htmlspecialchars()` when it moves here (C-01).

### I-21 — Cache and indexing headers for private pages
- Customer area, cart, checkout, thank-you, revise and password-reset responses should send `Cache-Control: private, no-store` (and `X-Robots-Tag: noindex` for the token pages). Do it with a small `kernel.response` listener keyed on the route names, or `#[Cache(public: false, noStore: true)]` per action. Shared caches (Varnish, Cloudflare in front of Pimcore) then cannot store personal data.

### I-22 — Tests and static analysis
- The bundle has no tests. Minimum set:
  - functional smoke tests of every static route (GET + the POST forms)
  - a container lint for the split package (C-01)
  - an installer test (C-02, B-23)
  - unit tests for `parseSorting()`, `TemplateConfigurator`, `RegisterFrontendControllerPass` (default and custom controller)
  - a test asserting that every `container->get()` id is subscribed (I-07)
- Run PHPStan 2 at a level that catches `TypeError` paths (`getStores()` nullability, `getPrettyVersion()`), and add `phpstan/phpstan-symfony` with the container XML so service-locator ids get checked.

### I-23 — Documentation
- README: document `core_shop_frontend.*` config (`view_prefix`, `view_suffix`, `category.*`, `controllers.*`), the installer command and its options, the `CORESHOP_*` attribute semantics (I-12), and that static routes are installed once (route fixes such as B-03 / B-08 need a migration for existing shops).
