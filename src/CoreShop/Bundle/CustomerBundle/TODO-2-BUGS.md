# CustomerBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this bundle, but they come from how this bundle's forms, repositories, services or config are used, and they should be fixed together with it.
"Standalone" means `coreshop/customer-bundle` installed on its own, as its `composer.json` allows, without `CoreBundle` overriding its config.
Paths are relative to the bundle root unless noted otherwise. Items are roughly ordered by impact.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security and authentication

### B-01 — The login form renders a CSRF token, but nothing checks it: login CSRF is possible
- **Where:** `Form/Type/CustomerLoginType.php:34-50`; `FrontendBundle/Controller/SecurityController.php:38` (`createNamed('', CustomerLoginType::class)`); `config/packages/security.yaml:35-42` (`coreshop_frontend.form_login` has no `enable_csrf`)
- **Problem:** the root form has `csrf_protection` on by default, so it renders a hidden `_token` field. The token id is derived from the form type, because the form name is empty. But `form_login` reads `_username` and `_password` and never validates that token, because `enable_csrf` is off and the authenticator expects `_csrf_token` with token id `authenticate`. A third-party page can therefore log the victim into the attacker's account (classic login CSRF). Whatever the victim then enters (addresses, payment details, orders) ends up in the attacker's account.
- **Fix direction:** in `CustomerLoginType::configureOptions()` set `csrf_field_name: '_csrf_token'` and `csrf_token_id: 'authenticate'`, and enable `form_login.enable_csrf: true` in the CoreShop firewall config (CoreBundle `security.yml` and the documented app `security.yaml`). Add a functional test.

### B-02 — `ChangePasswordType` has no constraints on the new password: an empty submit locks the user out, and any weak password is accepted
- **Where:** `Form/Type/ChangePasswordType.php:37-44`; consumer `FrontendBundle/Controller/CustomerController.php:291-297` (`$customer->getUser()->setPassword($formData['password'])`)
- **Problem:**
  - `RepeatedType` has no `NotBlank` or `Length`. With HTML5 validation bypassed, both fields can be empty. The form is still valid (both values match: `null`), and `UserInterface::setPassword(?string)` stores `null`. Symfony then refuses empty passwords at login, so the account can only be recovered through "reset password".
  - Any 1-character password is accepted. There is no `PasswordStrength` or `NotCompromisedPassword` check, both of which are available in Symfony 7.4.
- **Fix direction:** add `constraints: [new NotBlank(), new Length(min: 8, max: 4096), new PasswordStrength(...)]` (optionally `NotCompromisedPassword`) on `password`. Mirror this in `UserBundle/Form/Type/ChangePasswordType.php` (see I-13) and in the registration and reset forms.

### B-03 — `RoleOptionsProvider` only filters the top-level hierarchy keys, so privileged roles can still be assigned to storefront customers
- **Where:** `CoreExtension/Provider/RoleOptionsProvider.php:28-35`, `:42-64`; consumers `Resources/install/pimcore/classes/CoreShopCustomerGroup.json` (`roles` multiselect) and `Component/Core/Model/User.php:31-52` (merges the group roles into the security user)
- **Problem:**
  - `array_diff_key($systemRoles, array_flip($this->invalidRoles))` removes only the key `ROLE_PIMCORE_ADMIN`. If the app hierarchy contains, for example, `ROLE_SUPER_ADMIN: [ROLE_PIMCORE_ADMIN, ROLE_ALLOWED_TO_SWITCH]`, then `ROLE_SUPER_ADMIN`, `ROLE_PIMCORE_ADMIN` **and** `ROLE_ALLOWED_TO_SWITCH` are all offered, because inherited values are not filtered. A customer group with such a role grants it to every member's frontend token. That matters as soon as the frontend firewall uses `switch_user`, `is_granted('ROLE_…')` checks, or shares a context with another firewall.
  - It is a denylist of one role. Any future privileged role leaks through by default.
  - `getDefaultValue()` returns `'ROLE_USER'`, which normally is not in `role_hierarchy` and is therefore not one of the offered options.
- **Fix direction:** apply the filter after flattening (`array_diff($roles, $invalid)`), extend the denylist to `ROLE_PIMCORE_*`, `ROLE_SUPER_ADMIN` and `ROLE_ALLOWED_TO_SWITCH`, or better, switch to an allowlist parameter (`coreshop.customer.assignable_roles`). Make the default value consistent with the options (or `null`). See I-06 for the structural rewrite.

### B-04 — `FixedCustomerContext` is a shared, stateful, public service that is never reset, so a customer set once leaks into later requests
- **Where:** `Component/Customer/Context/FixedCustomerContext.php:23-40`; `Resources/config/services.yml:20-22` (tagged `coreshop.context.customer`, priority 0, registered **before** `TokenBasedCustomerContext`)
- **Problem:** after any code calls `setCustomer()` (today Behat, and also custom importers, Messenger handlers or CLI commands that "act as" a customer), that customer wins over the logged-in user for the rest of the service's life. With equal priorities, `Laminas\Stdlib\PriorityQueue` keeps insertion order, so the fixed context is asked before the token context. In a worker runtime (FrankenPHP worker mode, RoadRunner, Messenger consumers) visitor B is then served as customer A: cart, addresses, order history and prices. There is no `clear` method, no `ResetInterface` and no `kernel.reset` tag.
- **Fix direction:** implement `Symfony\Contracts\Service\ResetInterface` (`reset(): void { $this->customer = null; }`), tag it `kernel.reset`, and add `clearCustomer()`. Give the contexts explicit priorities in `services.yml` so the order is intentional. Consider registering the fixed context only in `test`/Behat config.

### B-05 — (dependent) `customer_list` / `customer_group_list` permissions only hide the menu entry; the admin endpoints do not check them
- **Where:** `DependencyInjection/Configuration.php:167-173` (permissions declared), `Resources/config/pimcore/routing.yml` (`folder-configuration` routes), `ResourceBundle/Controller/ResourceController.php:59-78` (`isGrantedOr403()` checks only when the resource metadata has a `permission` parameter, and none of the customer resources set one)
- **Problem:** `coreshop_customer_folderConfiguration` and `coreshop_customer_group_folderConfiguration` can be called by any Pimcore backend user. The leak is small today (folder id, class id, grid config), but every route added to these resources later inherits "no permission check". `CoreBundle/Menu/MainMenuBuilder.php:320,330` is the only place that reads the permissions.
- **Fix direction:** add a `permission` option to the `customer` and `customer_group` model config (`customer_list` / `customer_group_list`) so that `ResourceController::isGrantedOr403()` enforces it.

## Repositories and models

### B-06 — `findUniqueByEmail()` returns an arbitrary match and loads every matching row
- **Where:** `Pimcore/Repository/CustomerRepository.php:40-63`
- **Problem:**
  - Guests are never de-duplicated (see C-02), so one e-mail address can have dozens of guest rows. The listing has no `ORDER BY` and no limit, so `$users[0]` is whichever row the database returns first, and the result can change between calls. It decides which customer `GuestRegistrationType`, `CustomerRegistrationFormSubscriber` and `RegisteredUserValidator` act on.
  - It hydrates every matching object only to return the first one.
  - `count($users) > 0 && $users[0] instanceof …`: if the first row is not a `CustomerInterface`, it returns `null` even when later rows would match.
  - The name says "unique", but nothing enforces uniqueness for guests.
- **Fix direction:** `$list->setOrderKey('o_id' /* or creationDate */)->setOrder('DESC')->setLimit(1)`, then `$list->current()`. Rename it or document it as "latest". Resolve the duplicate-guest design together with C-01 and C-02.

### B-07 — `findCompanyByName()` and `findByNewsletterToken()` silently return `null` when the value is duplicated
- **Where:** `Pimcore/Repository/CompanyRepository.php:27-38`, `Pimcore/Repository/CustomerRepository.php:27-38`
- **Problem:** `count($objects) === 1` turns "two companies called ACME" or "a token collision" into "not found". Callers (for example `FrontendBundle/Controller/CustomerController::confirmNewsletterAction()`) then report an invalid token, and nobody notices the data problem. `newsletterToken` has no unique index in the class definition. `name` on `CoreShopCompany` is mandatory but not unique.
- **Fix direction:** make the contract explicit. Either mark the fields `unique` in the class definitions and use `setLimit(1)`, or throw a dedicated `NonUniqueResultException` so the duplicate surfaces. Log it at minimum.

### B-08 — `Company::isEqualTo()` treats two unsaved companies as equal, and `Company` should not be a Security `EquatableInterface` at all
- **Where:** `Component/Customer/Model/Company.php:26-29`, `Component/Customer/Model/CompanyInterface.php:25`
- **Problem:** `$user->getId() === $this->getId()` is `true` for `null === null`, so any two new companies compare equal. Also, `EquatableInterface::isEqualTo(UserInterface $user)` is only ever called with a security user. A `Company` is never a user, so in practice the method is either dead or wrong (it compares a company to a user instance).
- **Fix direction:** remove `EquatableInterface` from `CompanyInterface` (BC: note it in UPGRADE). If an equality helper is needed, add `isSameAs(CompanyInterface $other): bool` with a `null`-id guard.

### B-09 — (standalone) `customer.path` has no `addDefaultsIfNotSet()`, so the `customers` / `guests` defaults never apply and folder creation throws
- **Where:** `DependencyInjection/Configuration.php:100-105`
- **Problem:** verified with `symfony/config` 7.4.17: with an empty config, `pimcore.customer` contains only `classes`. There is no `path` key, so the `defaultValue('customers')` / `('guests')` lines are dead. `PimcoreDriver::load()` then skips `addPimcoreClass()` (no `coreshop.folder.customer*` parameters), and both `FolderCreationService::createFolderForResource()` and `ResourceController::folderConfigurationAction()` call `Metadata::getParameter('path')`, which throws `InvalidArgumentException`. This works only because `CoreBundle/Resources/config/pimcore/coreshop/coreshop_customer.yml` always sets the path.
- **Fix direction:** add `->addDefaultsIfNotSet()` to the `path` array node. Add a Configuration unit test that processes `[]` and asserts the defaults.

### B-10 — `autoconfigure_with_attributes` is a `scalarNode`: any non-bool value causes a `TypeError` at container build
- **Where:** `DependencyInjection/Configuration.php:44`; `DependencyInjection/CoreShopCustomerExtension.php:52-66` → `Component/Registry/Autoconfiguration::registerForAutoConfiguration(..., bool $autoconfigureWithAttributes)`
- **Problem:** the node accepts `"yes"`, `1` or `"%env(bool:X)%"` (verified: `"yes"` is accepted as-is). The extension file is `strict_types=1`, so passing a string to a `bool` parameter throws a `TypeError` and the whole container fails to compile. An env placeholder is also a string at that point.
- **Fix direction:** use `->booleanNode('autoconfigure_with_attributes')->defaultFalse()`. Env placeholders cannot drive compile-time autoconfiguration anyway, so document that.

### B-11 — `pimcore_admin.permissions` is a `scalarNode` with an array default, so it cannot be configured
- **Where:** `DependencyInjection/Configuration.php:167-173`
- **Problem:** verified: `permissions: ['a']` throws `InvalidTypeException: Expected "scalar", but got "array"`. `permissions: 'x'` is accepted and then breaks `AbstractModelExtension::registerPimcoreResources()`, which does `foreach` over a string. The only working value is the default.
- **Fix direction:** `->arrayNode('permissions')->scalarPrototype()->end()->defaultValue([...])`. Drop `cannotBeOverwritten()` unless it really is intended (it only blocks merging across files).

### B-12 — (standalone) The bundle depends on `coreshop/core` and SecurityBundle without declaring them
- **Where:**
  - `Resources/config/services.yml:28-32`: registers `CoreShop\Component\Core\Context\TokenBasedCustomerContext` (a `coreshop/core` class) with `@Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface` (needs `symfony/security-bundle`).
  - `Resources/install/pimcore/classes/CoreShopCompany.json`: `"parentClass": "CoreShop\\Component\\Core\\Model\\Company"` (also `coreshop/core`).
  - `Resources/install/pimcore/grid-config.yml`: columns `addresses` and `defaultAddress` exist only in the CoreBundle class definition.
  - `composer.json:26-30`: requires only `coreshop/customer`, `coreshop/resource-bundle` and `six-it/pimcore-source`.
- **Problem:** standalone:
  - Resolving `CustomerContextInterface` instantiates the composite, which instantiates `TokenBasedCustomerContext`, which fails with `Error: Class not found`. Without SecurityBundle, the container does not compile at all.
  - Installing the bundle's own `CoreShopCompany` class generates `Pimcore\Model\DataObject\CoreShopCompany extends CoreShop\Component\Core\Model\Company`, so every load of a company fatals.
  - The grid configs reference fields that do not exist.
- **Fix direction:** move the `TokenBasedCustomerContext` service definition to CoreBundle (`services/context.yml`). Point the bundle's `CoreShopCompany.json` at `CoreShop\Component\Customer\Model\Company`, as the customer and group definitions already do. Drop the Core-only columns from this bundle's grid config (CoreBundle can ship its own).

### B-13 — (standalone) The bundle's own `CoreShopCustomer.json` has drifted away from the repository and model it ships with
- **Where:** `Resources/install/pimcore/classes/CoreShopCustomer.json` versus `Pimcore/Repository/CustomerRepository.php:30,48,50` and `CoreBundle/Resources/install/pimcore/classes/CoreShopCustomerBundle/CoreShopCustomer.json`
- **Problem:**
  - The repository queries `user__id` (`findUniqueByEmail`) and `newsletterToken` (`findByNewsletterToken`). Neither field exists in this bundle's class definition, so standalone both methods fail with `SQLSTATE[42S22] Unknown column`.
  - The definition still contains the legacy CoreShop 2 fields `password`, `isGuest` and `passwordResetHash`. Credentials moved to `CoreShopUser` long ago. A standalone install would store password hashes on the customer object, with no code that uses them.
- **Fix direction:** decide what the standalone bundle is. Either move the `user`-based queries (`findUniqueByEmail`, `findGuestByEmail`, `findCustomerByEmail`) into `CoreBundle\Pimcore\Repository\CustomerRepository`, where `user` exists, and add `newsletterToken` to this bundle's class definition. Or declare `coreshop/core` as required and stop shipping a separate definition. Remove the legacy credential fields in any case.

### B-14 — `composer.json` files do not list what the code imports
- **Where:** `composer.json`, `Component/Customer/composer.json`
- **Problem:**
  - The bundle uses `symfony/form`, `symfony/options-resolver`, `symfony/security-core` (`UserPassword`), `symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel`, `coreshop/registry` (`PrioritizedCompositeServicePass`, `Autoconfiguration`) and `jms/serializer-bundle` (metadata config), and depends on `coreshop/core` (B-12). None of these are declared.
  - The component uses `symfony/http-foundation` (`Request`, `RequestStack`), `symfony/security-core` (`EquatableInterface`, `UserInterface`) and `coreshop/locale` (`CustomerInterface extends LocaleAwareInterface`). Only `coreshop/resource` and `laminas/laminas-stdlib` are declared.
  - Neither file has a `php` constraint. The root project uses `~8.4.0`.
  - `require-dev` pins `phpstan/phpstan: ^1.10`, while the root uses `^2.1`.
- **Fix direction:** declare `"php": "^8.4"`, the Symfony components at `^7.4`, and the CoreShop packages actually used. Align `require-dev` with the root (`phpstan ^2.1`, `phpstan-doctrine ^2`, `phpstan-webmozart-assert ^2`). Run `composer-require-checker` (or `composer-dependency-analyser`) in CI per package.

## Forms and validation

### B-15 — Two block-prefix collisions: `coreshop_customer` (CustomerType / CustomerSelectionType) and `coreshop_change_password` (this bundle / UserBundle)
- **Where:** `Form/Type/CustomerType.php:80-83` and `Form/Type/CustomerSelectionType.php:25-28` both return `coreshop_customer`. `Form/Type/ChangePasswordType.php:47-50` and `UserBundle/Form/Type/ChangePasswordType.php` both return `coreshop_change_password`.
- **Problem:** form themes match on block prefix. A theme block `coreshop_customer_widget` written for the customer sub-form is also applied to the admin customer *selection* field (`OrderBundle/Form/Type/CartCreationType.php:38`, `AdminAddressCreationType.php:34`), and the reverse. `createNamed()` / `create()` default names collide too. The two `ChangePasswordType`s have different labels and translation keys but the same theme hook.
- **Fix direction:** rename `CustomerSelectionType::getBlockPrefix()` to `coreshop_customer_selection`. Remove one of the `ChangePasswordType`s (see I-13). Note it in UPGRADE for custom themes.

### B-16 — `CustomerType::$guestValidationGroups` is never used, and the `coreshop_customer_guest` group is effectively dead
- **Where:** `Form/Type/CustomerType.php:31-37`; `Resources/config/services/form.yml:3,13`; (dependent) `CoreBundle/Resources/config/services/form.yml:3` overrides the parameter to `[coreshop]`; `CoreBundle/Resources/config/validation/Customer.yml` puts `RegisteredUser` in group `coreshop_customer_guest`
- **Problem:** the constructor accepts guest validation groups and stores them, but no code reads them. In CoreBundle the parameter is overridden to `[coreshop]`, so `RegisteredUser` (group `coreshop_customer_guest`) does not run through this parameter either. Whether guest checkout validates "this e-mail is already registered" depends on hard-coded groups elsewhere (`GuestRegistrationType.php:87`). The configuration says one thing and the runtime does another.
- **Fix direction:** either remove the argument and parameter (BC: deprecate first), or apply it (for example a `validation_groups` closure that picks the guest groups when `$data->getUser() === null`). Then check which groups guest checkout really validates, and fix the CoreBundle override so it matches.

### B-17 — Customer validation does not check the e-mail format, and the gender rules contradict the class definition
- **Where:** `Resources/config/validation/Customer.yml:1-10`; `Form/Type/CustomerType.php:42-48`; `Resources/install/pimcore/classes/CoreShopCustomer.json` (`gender` options include `'' => unknown`)
- **Problem:**
  - `email` has only `NotBlank`. `EmailType` is just an `<input type=email>`, so any string is accepted server-side. This affects admin customer creation (`OrderBundle/Form/Type/AdminCustomerCreationType.php:33-37`, `allow_email: true`), which posts JSON and has no browser validation at all.
  - `gender` is `NotBlank`, but the class allows "unknown" (`''`), and the form offers only male/female without a `placeholder`. The `<select>` therefore preselects "male": users who do not choose are stored as male, and customers created in the admin with "unknown" fail validation the first time they edit their profile.
- **Fix direction:** add `Email: { mode: html5, groups: [coreshop] }` (and `Length: { max: 190 }` to match `columnLength`). Align gender: either add the unknown/diverse option and drop `NotBlank`, or keep `NotBlank` and add `placeholder: 'coreshop.form.customer.gender.placeholder'`.

## Admin UI, install data and serialization

### B-18 — Wrong or missing admin translations
- **Where:** `Resources/translations/admin.en.yml:34`; `Resources/translations/admin.de.yaml`
- **Problem:**
  - `coreshop_permission_customer_list: 'CoreShop: Customer Group List'` is a copy-paste error, so the user-permission screen shows two "Customer Group List" checkboxes.
  - `admin.de.yaml` is missing `coreshop_customer`, `coreshop_customers`, `coreshop_customer_manage`, `coreshop_customer_quick_open`, `coreshop_customer_group`, `coreshop_customer_groups`, `coreshop_customer_group_manage` and `coreshop_customer_group_quick_open`, so German admins see raw keys in the menu.
  - There is no `admin.it` file, although `validators.it.yml` exists.
- **Fix direction:** fix the label (`'CoreShop: Customer List'`) and add the missing de keys. Optionally add an `it` admin file. See I-18 for the file-extension cleanup.

### B-19 — `resource.js` registers a store whose URL does not exist
- **Where:** `Resources/public/pimcore/js/resource.js:16`; `ResourceBundle/Resources/public/pimcore/js/global.js:17-19`; `Resources/config/pimcore/routing.yml:5,17` (`no_default_routes: true`)
- **Problem:** `addStore('coreshop_customergroups', 'coreshop/customer_groups')` points to `/admin/coreshop/customer_groups/list`. The resource has no default routes, so any combo or grid that loads this store gets a 404. No JS in the repository uses the store, so today it is dead code that will fail as soon as someone uses it.
- **Fix direction:** remove it, or back it with a real route (add a `list` additional route, or use `addStoreWithRoute()` with an existing route).

### B-20 — `grid-config.yml`: English configs are marked `language: de`, the class ids are stale, and the column positions are duplicated
- **Where:** `Resources/install/pimcore/grid-config.yml:6,9,302-303,636-637,674,677,734,737`
- **Problem:**
  - `customer_en` and `customer_group_en` have `language: de`, so the "English" overview renders localized fields in German.
  - `classId: '12'` and `'11'` are leftovers from an old install. The installer passes the real id separately (`PimcoreGridConfigInstaller.php:83`), but the stale value is still stored in the saved JSON and is misleading.
  - Two columns share `position: 11` (address and groups) in both customer configs, so their order is undefined.
- **Fix direction:** set `language: en` for the `*_en` entries, remove the `classId` keys (or let the installer overwrite them), and renumber the positions to 11 and 12. See I-20 for removing the en/de duplication.

### B-21 — JMS metadata: `id` is declared as `string`, and `CustomerGroup` exposes no id
- **Where:** `Resources/config/serializer/Model.Customer.yml:5-9`, `Model.CustomerGroup.yml`
- **Problem:** `getId(): ?int` is serialized as a JSON string (`"42"`), so admin JS (or API consumers) comparing it with `===` to numeric ids from other endpoints get mismatches. `customerGroups` is serialized through `Model.CustomerGroup.yml`, which has no `id`, so clients receive the names and roles of the groups but cannot reference them.
- **Fix direction:** `type: int` for `id`. Add `id` (`int`) to `CustomerGroup` (and add a `Company` metadata file if companies are serialized anywhere).
