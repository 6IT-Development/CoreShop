# CustomerBundle – TODO 3: Improvements (code quality, readability, logic, PHP 8.4 / Symfony 7.4 idioms)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
None of these are defects on their own. They make the bundle cleaner, safer, easier to test, or more idiomatic for the target stack.
Paths are relative to the bundle root unless noted otherwise.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 language features

### I-01 — Add `#[\Override]` everywhere a parent or interface method is implemented
- **Where:** `CoreShopCustomerBundle.php` (`getSupportedDrivers`, `registerDependentBundles`, `build`, `getModelNamespace`), `DependencyInjection/*` (`getConfigTreeBuilder`, `load`), `Form/Type/*` (`buildForm`, `configureOptions`, `getBlockPrefix`), `Pimcore/Repository/*`, `CoreExtension/Provider/RoleOptionsProvider.php`, and `Component/Customer/**` (contexts, resolvers, `Company::isEqualTo`)
- PHP 8.3+ then reports signature drift at compile time. That matters most for `RoleOptionsProvider`, whose Pimcore interface is being replaced (I-06).

### I-02 — Constructor promotion and `readonly`
- `Attribute/AsCustomerContext.php` and `Attribute/AsRequestResolverBasedCustomerContext.php`: `public readonly ?int $priority = null`. Keep `getPriority()`, because `Component/Registry/Autoconfiguration::getProperties()` reads the `get*` methods, or change that helper to read public properties. The two attribute classes are identical, so consider an abstract base or a shared trait.
- Mark the promoted dependencies `readonly`: `Form/Type/CustomerLoginType.php` (`$loginIdentifier`), `Form/Type/CustomerType.php` (`$guestValidationGroups`, if it stays; see B-16), `Component/Customer/Context/RequestBased/CustomerContext.php` (`$requestResolver`, `$requestStack`), `Component/Core/Context/TokenBasedCustomerContext.php`.
- `CompositeCustomerContext` / `CompositeRequestResolver`: initialise in the property declaration (`private readonly PriorityQueue $customerContexts;`, set in the constructor) or use a `new` initializer in a promoted parameter.

### I-03 — Remove the odd empty-constructor formatting left over from an automated refactor
- **Where:** `CoreExtension/Provider/RoleOptionsProvider.php:32-33`, `DependencyInjection/Compiler/CompositeCustomerContextPass.php:29-30`, `DependencyInjection/Compiler/CompositeRequestResolverPass.php:29-30`, `Component/Customer/Context/CompositeCustomerContext.php:33-34`, `Component/Customer/Context/RequestBased/CompositeRequestResolver.php:35-36`
- `public function __construct(\n        ) {` should be `public function __construct()`. Run php-cs-fixer (`method_argument_space`, `no_spaces_inside_parenthesis`) over the bundle and the component.

### I-04 — Native types on the model interfaces
- **Where:** `Component/Customer/Model/{CustomerInterface,CompanyInterface,CustomerGroupInterface,CustomerAwareInterface}.php`
- All setters lack a return type (`setName(?string $name);`). Pick `void` (or `static`, matching what Pimcore's generated classes return). That is a BC break for implementors, so plan it for a major/minor version and note it in UPGRADE.
- `getCustomerGroups(): ?array` → document `@return list<CustomerGroupInterface>|null` (or `array` with `[]` instead of `null`). `getRoles(): ?array` → `@return list<string>|null`.
- `CustomerGroupInterface` does not extend `ResourceInterface`, while `CustomerInterface` and `CompanyInterface` do. Make them consistent.
- `getGender(): ?string` → consider a backed enum `Gender: string { case Male = 'male'; case Female = 'female'; case Unknown = ''; }` for the form choices, the validation and the class options (see B-17).

### I-05 — Clean up `CustomerNotFoundException`
- **Where:** `Component/Customer/Context/CustomerNotFoundException.php:21-28`
- `?\Exception $previousException` → `?\Throwable $previous`, so `Error`/`TypeError` from an inner context can be chained. Add an optional `string $message` (or named constructors such as `::noToken()` or `::noRequest()`) so logs say *why* no customer was found.
- `Component/Customer/Context/RequestBased/CustomerContext.php:37-41` wraps a `\UnexpectedValueException` it threw itself one method earlier. Throw `CustomerNotFoundException` directly from `getMainRequest()`.

## Pimcore integration

### I-06 — Rewrite `RoleOptionsProvider` as a service that implements the current Pimcore interface
- **Where:** `CoreExtension/Provider/RoleOptionsProvider.php`; `Resources/install/pimcore/classes/CoreShopCustomerGroup.json` (`optionsProviderClass`)
- It implements `MultiSelectOptionsProviderInterface`, which is deprecated since Pimcore 11.2 (`OptionsProviderResolver::resolveProvider()` triggers a deprecation on every resolve). Switch to `SelectOptionsProviderInterface` and type the signatures: `getOptions(array $context, Data $fieldDefinition): array`, `hasStaticOptions(array $context, Data $fieldDefinition): bool`, `getDefaultValue(array $context, Data $fieldDefinition): string|array|null`.
- Replace the service-locator call `\Pimcore::getKernel()->getContainer()->getParameter(...)` in the constructor with constructor injection (`#[Autowire('%security.role_hierarchy.roles%')] array $roleHierarchy`). Register it as a service and reference it as `@CoreShop\Bundle\CustomerBundle\CoreExtension\Provider\RoleOptionsProvider` in the class definition. `ClassResolver` supports the `@` prefix. This also makes the class unit-testable.
- `hasStaticOptions()` should return `true`: the roles do not depend on the object, and `false` disables batch assignment and option filtering in the object grid.
- Simplify the flattening: `array_values(array_unique(array_merge(array_keys($h), ...array_values($h))))` → `array_map(fn (string $r) => ['key' => $r, 'value' => $r], …)`. Apply the B-03 filter after flattening and sort the options for a stable UI.
- Drop `protected` on `$originalRoles` / `$invalidRoles` (make the class `final`, or move the denylist to a constant or parameter).

### I-07 — Move the Core-dependent install data and services to CoreBundle
- See B-12 and B-13 for the bugs. The structural improvement: CustomerBundle ships only what works with `coreshop/customer` alone (its own class definitions, a grid config without address columns, and no `TokenBasedCustomerContext`). CoreBundle already overrides `install_file` and adds its definitions. Keeping one source of truth per class definition removes the drift for good.

## Symfony 7.4 / DI configuration

### I-08 — Tidy `services.yml` / `services/form.yml`
- `_defaults: public: true` makes every service public, including form types and contexts. Only `FixedCustomerContext` (Behat) and the `coreshop.repository.*` services need to be public. Default to private and mark the exceptions.
- `CustomerContextInterface: '@…CompositeCustomerContext'` and `RequestResolverInterface: '@…CompositeRequestResolver'` duplicate what `PrioritizedCompositeServicePass::addAliasForCompositeIfServiceDoesNotExist()` already does. Keep one of them (prefer the explicit YAML alias, then drop the pass logic, or the reverse).
- Use named arguments (`$dataClass`, `$validationGroups`) in `services/form.yml` instead of positional lists, and give the contexts explicit `priority` tags (see B-04) so the resolution order is intentional and documented.
- Consider PHP config (`services.php`) for the bundle, the format Symfony recommends for bundles since 7.x and the one that will be most future-proof (Symfony 7.4 deprecates XML service config; YAML is fine, but PHP gives IDE and static-analysis support).

### I-09 — Request-based customer context has no resolvers anywhere
- **Where:** `Resources/config/services.yml:12-18`, `:24-26`; `DependencyInjection/Compiler/CompositeRequestResolverPass.php`
- No service in the repository is tagged `coreshop.context.customer.request_based.resolver`. So `RequestBased\CustomerContext` always ends in `CustomerNotFoundException`, and that exception is created and caught on **every** `CustomerContextInterface::getCustomer()` call, which happens many times per request through `ShopperContext`.
- Either remove the request-based layer (BC: deprecate first), or register the context only when at least one resolver exists (check in the compiler pass and remove the definition otherwise). Document the extension point in the README if it stays.

### I-10 — Optional per-request memoisation in `CompositeCustomerContext`
- **Where:** `Component/Customer/Context/CompositeCustomerContext.php:43-54`
- The composite walks all contexts, and throws and catches exceptions, on every call. A small cache keyed by the current token or request (`spl_object_id`) plus `ResetInterface` / `kernel.reset` would remove that overhead. Invalidate it on login and logout (`LoginSuccessEvent`, `LogoutEvent`). Do this only together with B-04, so that the cache cannot itself leak between requests.

### I-11 — (dependent) Guard `PrioritizedCompositeServicePass` against tagging the composite into itself
- **Where:** `Component/Registry/PrioritizedCompositeServicePass.php:48-56`; `DependencyInjection/CoreShopCustomerExtension.php:52-66`
- `registerForAutoconfiguration(CustomerContextInterface::class)->addTag(...)` tags every autoconfigured implementation, including an app decorator of `CustomerContextInterface` that receives the composite as its inner service. The pass then adds the decorator to the composite, so `getCustomer()` recurses until the stack overflows. Skip `$id === $this->compositeId`, skip definitions that decorate the composite, and skip the composite's own class.

### I-12 — `Configuration` readability and consistency
- **Where:** `DependencyInjection/Configuration.php`
- Three almost identical `pimcore.<model>` blocks: extract a `addPimcoreModelNode(string $name, string $model, string $interface, ?string $repository, string $installFile, string|array $path)` helper.
- Inconsistencies to resolve while doing that: only `company` has `pimcore_class_name`; `company` has no `admin_controller`; `customer_group.classes.repository` has no default (so it falls back silently to `PimcoreRepository`), while the other two have one.
- `$rootNode->children()->…` is called three times on separate builders. Fine, but one `children()` block with helper calls reads better.
- `->prototype('scalar')` → `->scalarPrototype()` (lines 153-165), as already used on line 179.
- `CoreShopCustomerExtension::load()`: rename the processed `$configs` to `$config` (it is no longer a list of configs), and pass `$configs` (not `[]`) to `getConfiguration()` for clarity. The base implementation ignores the argument today.

## Forms

### I-13 — Consolidate the two `ChangePasswordType`s
- **Where:** `Form/Type/ChangePasswordType.php`; `UserBundle/Form/Type/ChangePasswordType.php`
- They are copies of each other, apart from the translation keys (`coreshop.form.customer.*` versus `coreshop.form.user.*`). `FrontendBundle` uses the CustomerBundle one, but the password belongs to the *user*. Keep the UserBundle type, add the B-02 constraints there, alias or deprecate the CustomerBundle class, and switch `FrontendBundle/Controller/CustomerController.php:291` to it.
- Add `'attr' => ['autocomplete' => 'current-password']` / `'new-password'`, plus `'toggle' => true` (Symfony 6.3+ `PasswordType` toggle, with `symfony/ux-toggle-password`) if UX matters.
- Set `data_class => null` explicitly and document that the form returns an array.

### I-14 — `CustomerLoginType` / `CustomerType` option hygiene
- `CustomerLoginType`: use `EmailType` when `$loginIdentifier === 'email'`, add `autocomplete` attributes (`username`, `current-password`), and set the CSRF options from B-01 in `configureOptions()`. The class can be `final`.
- `CustomerType`:
  - The `customer` option (default `false`) is consumed only by `CoreBundle/Form/Extension/CustomerTypeExtension.php`. Define it in the extension (`setDefined('customer')->setAllowedTypes('customer', ['null', CustomerInterface::class])`), and use `null` as the default instead of `false`.
  - `allow_email` → `setAllowedTypes('allow_email', 'bool')`.
  - `csrf_protection: true` overrides `AbstractResourceType`'s `false`. That has no effect when the type is embedded (the CSRF field exists only on the root form), so it is confusing. Remove it or document when `CustomerType` is used as a root form.
  - `gender` choices: build them from the enum suggested in I-04, and add a `placeholder` (B-17).

### I-15 — Repository clean-up
- **Where:** `Pimcore/Repository/CustomerRepository.php`, `Pimcore/Repository/CompanyRepository.php`
- `findUniqueByEmail(string $email, bool $isGuest)` is a boolean-flag API. The two public wrappers already exist, so make the base method `private`/`protected`, or drop it from the interface (BC).
- Use the same idiom in every finder: `setLimit(1)`, `setOrderKey()`, `current()`. Today one method uses `load()` and another uses `load()` followed by `getObjects()`.
- Build the condition directly instead of `implode(' AND ', $conditions)` with a ternary-like if/else: `sprintf('email = ? AND user__id IS %sNULL', $isGuest ? '' : 'NOT ')`, or two prepared conditions.
- The `user__id` column belongs to CoreBundle's class definition. Move the queries that use it (see B-13).
- Both classes can be `final`, or kept open but documented as extension points (CoreBundle extends `CustomerRepository`).

## Packaging, admin assets, translations

### I-16 — Small bundle-class clean-ups
- **Where:** `CoreShopCustomerBundle.php:55-58`
- `getModelNamespace()` returns the string `'CoreShop\Component\Customer\Model'`. Derive it (`(new \ReflectionClass(CustomerInterface::class))->getNamespaceName()`) or use a constant, so a namespace move does not break silently.
- `registerDependentBundles()` checks `class_exists(PimcoreNewsletterBundle::class)` in two places (the bundle and the extension). Extract a small `NewsletterIntegration::isAvailable()` helper, or a bundle constant.

### I-17 — `composer.json` modernisation
- Besides the missing requires (B-14): add `"php": "^8.4"`, move `require-dev` to phpstan 2.x, and drop `"minimum-stability": "dev"` from the package file. It matters only for the monorepo path repositories and has no effect for consumers, so it is just noise.
- `extra.branch-alias.dev-master: 4.1-dev`: check it against the version this branch is released as.

### I-18 — Translation file consistency
- **Where:** `Resources/translations/`
- `admin.de.yaml` uses `.yaml`, every other file uses `.yml`. Pick one (Symfony recommends `.yaml`).
- `admin.*` files use flat keys, `validators.*` use nested keys. Pick one style per domain.
- Add a translation-lint step (`bin/console lint:yaml` / `debug:translation --only-missing`) to CI to catch B-18 automatically.

### I-19 — Admin JS/CSS clean-up
- **Where:** `Resources/public/pimcore/js/customer/list.js:32-35`, `customer_group/list.js:32-35`, `css/customer.css:15-25`
- The two `list.js` files are identical apart from `type` and the route names. Derive the route names from `type` (`'coreshop_' + this.type + '_folderConfiguration'`) in `coreshop.resource.list`, and keep only the overrides.
- Remove the commented-out `//coreshop.order.helper.openOrder(id, callback);` and the unused `callback` parameter.
- `customer.css` defines `.coreshop_icon_customer` twice (lines 15 and 23). There is no nav icon for the singular `customer_group`, and no `company` icon class, although `company.svg` ships. Merge the rules and add the missing ones if a company list is planned.

### I-20 — De-duplicate `grid-config.yml`
- **Where:** `Resources/install/pimcore/grid-config.yml`
- The `*_en` and `*_de` entries are 330-line copies that differ only in labels. Use YAML anchors and merge keys (`<<: *customer_columns`) with per-language label overrides, or generate both from one definition in the installer. Today a column change has to be made twice, and B-20 shows how the copies drift.

### I-21 — Tests
- There are no unit or functional tests for the bundle or for `Component/Customer` (`tests/` has no references). Good first candidates:
  - `Configuration` (`[]` → defaults, covers B-09, B-10 and B-11),
  - `CompositeCustomerContext` / `CompositeRequestResolver` (priority order and fall-through),
  - `RoleOptionsProvider` (filtering, B-03),
  - `FixedCustomerContext` reset (B-04),
  - a functional test for login CSRF (B-01) and the change-password constraints (B-02),
  - repository finders against a Pimcore test kernel (B-06, B-07).
- Add the bundle and the component to the per-package phpstan run (`phpstan-package.neon`) at the root level used by the other packages.
