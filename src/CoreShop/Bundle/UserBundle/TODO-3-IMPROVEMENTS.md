# UserBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items (Symfony 8). None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as sub-bullets.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle.

**Already fine for Symfony 7.4:** the extension extends CoreShop's `AbstractModelExtension` (based on `Symfony\Component\DependencyInjection\Extension\Extension`), service config is YAML (not the XML format deprecated in 7.4), `TreeBuilder` is constructed with a root name, the validation YAML uses named options, `RequestPasswordChangeEvent` extends `Symfony\Contracts\EventDispatcher\Event`, every form method has native return types, the user implements `getUserIdentifier()` and `PasswordAuthenticatedUserInterface`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — `composer.json` does not describe what the code needs
- **Where:** `composer.json:26-56`.
- **Problem / Fix:**
  - No `php` constraint. Add `"php": "^8.4"`.
  - Directly used packages are not declared: `symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel`, `symfony/form`, `symfony/options-resolver`, `symfony/validator`, `symfony/security-core` (`UserPassword` constraint, `UserInterface`), `symfony/event-dispatcher-contracts` (all `^7.4` / `^3`). `Component/User` uses `nesbot/carbon` and Symfony security without declaring them either (dependent).
  - `require-dev` pins PHPStan 1.x; bump to PHPStan 2 together with the other bundles.
  - `"minimum-stability": "dev"` and `repositories` (`path` `../../*/*`) have no effect for consumers of a library; move them to the monorepo root. `:41-46` uses 4-space indent while the rest uses 2.

### I-02 — `eraseCredentials()` is deprecated since Symfony 7.3 and does nothing useful — (dependent)
- **Where:** `Component/User/Model/User.php:58-69`; `vendor/symfony/security-core/User/UserInterface.php:56-58` (`@deprecated since Symfony 7.3`), `vendor/symfony/security-http/Authentication/AuthenticatorManager.php:295-325` (triggers "Implementing eraseCredentials() is deprecated" at every login unless the method has `#[\Deprecated]`).
- **Fix:** move the credential clean-up into `__serialize()` (drop `plainPassword`, TODO-1 **C-02**), mark `eraseCredentials()` with `#[\Deprecated]` and an empty body for 7.4, remove it for Symfony 8. The current body computes a hash and throws it away; the docblock ("remove the plain text password from the instance") describes something it does not do.

### I-03 — Dead Symfony 5 leftovers on the user model — (dependent)
- **Where:** `Component/User/Model/User.php:26,44-56,81-84`.
- **Fix:** `getUsername()` was removed from Symfony's `UserInterface` in 6.0; `getSalt()` / `setSalt()` / `$salt` are only used with `LegacyPasswordAuthenticatedUserInterface`, which the model does not implement — remove all of them (keep `getSalt()` only if a project hasher still needs it, and then implement the legacy interface explicitly). `private array $roles = []` is never written; `Component/Core/Model/User::getRoles()` builds the roles — make the base `getRoles()` return `[self::CORESHOP_ROLE_DEFAULT]` and drop the property.

### I-04 — CoreBundle's security config for this user model is Symfony 5 style — (dependent)
- **Where:** `CoreBundle/Resources/config/pimcore/security.yml` (`anonymous: ~`, `IS_AUTHENTICATED_ANONYMOUSLY`, Pimcore `encoder_factories`), `CoreBundle/Security/ObjectUserProvider.php:35-38` (`loadUserByUsername()`), `:66` (untyped `supportsClass($class)`).
- **Fix:** tracked in CoreBundle; listed here because the user model, `UserPassword` (TODO-2 **B-03**) and `isEqualTo()` (TODO-1 **C-01**) depend on it. Target: `password_hashers` for `%coreshop.model.user.class%` (`auto`), `PUBLIC_ACCESS`, no `anonymous`, `loadUserByUsername()` removed, a `UserChecker` that rejects unpublished users.

### I-05 — Configuration tree
- **Where:** `DependencyInjection/Configuration.php`.
- **Fix:**
  - `:33` the tree root is `coreshop_user` but the extension alias (and every YAML file, e.g. `Resources/config/pimcore/admin.yml:1`, `CoreBundle/Resources/config/pimcore/coreshop/coreshop_user.yml:1`) is `core_shop_user`; error messages and `config:dump-reference` show the wrong name. Use `core_shop_user`.
  - `:39` `driver` is read nowhere (the only consumer is the commented-out `registerResources()` call in the extension, `CoreShopUserExtension.php:33`); remove both, or turn it into an `enumNode`.
  - `:102,106,110,114` `->prototype('scalar')` → `->scalarPrototype()`.
  - `:70` `cannotBeOverwritten(true)` → `cannotBeOverwritten()`.
  - `:60` `options` `variableNode` and `:61` `path` have no `info()`; add `info()` to every node.
  - Add the TTL / password-policy options suggested in TODO-2 **B-01**, **B-08**.

### I-06 — PHP 8.4 language features
- `Event/RequestPasswordChangeEvent.php`: `final class … { public function __construct(public readonly UserInterface $user, #[\SensitiveParameter] public readonly string $resetLink) }` (or `private(set)`); keep the getters for BC. The reset link contains the raw token; mark it `#[\SensitiveParameter]` so it is not shown in stack traces.
- `Pimcore/Repository/UserRepository.php:30`: `#[\Deprecated(message: …, since: '4.1')]` instead of the docblock only.
- `Component/User/Model/User.php:32,44`: setters without return type → `: static`; `setPlainPassword(#[\SensitiveParameter] string $password)`.
- `Component/User/Model/UserInterface.php:36` parameter typo `$loginIdentifer`; setters without return types; `Carbon` in the interface → `\DateTimeInterface` (Carbon implements it) to drop the hard Carbon dependency from the contract.
- `Component/User/Model/UserAwareInterface.php:25` `setUser()` without return type.

## Code quality and readability

### I-07 — Repository
- **Where:** `Pimcore/Repository/UserRepository.php`.
- **Fix:**
  - `:88-105` `findByLoginIdentifier()` builds `$conditions` / `$conditionsValues` arrays for a single condition; `setCondition('loginIdentifier = ?', [$value])` + `setLimit(2)` (TODO-2 **B-05**) reads better. `load()` and then `getObjects()` loads twice in spirit; use the return value of `load()` like the other methods.
  - `:43-86` `findByResetTokenSecure()`: comments restate the code; the TTL check is simpler as `$createdAt->getTimestamp() + $ttlSeconds < time()` (no `DateTimeImmutable::modify()` string building). Use `hash_equals()` is not needed (DB lookup by hash), but document why.
  - `passwordResetHash` has `index: false` in `CoreShopUser.json`; every reset lookup is a full table scan — add an index (class definition + migration in CoreBundle, dependent).
  - Shared "load exactly one" helper for the three finders.

### I-08 — Form types: unused / duplicated classes
- **Where:** `Form/Type/*`, `Resources/config/services/form.yml`.
- **Fix:**
  - `UserLoginType` is not used anywhere in the code base (the frontend login uses its own form) and is not registered in `form.yml`; the `_username`/`_password` names would also not reach `form_login` when created with `createNamed('coreshop', …)`. Remove it or document its use.
  - `ChangePasswordType` is duplicated by `CustomerBundle/Form/Type/ChangePasswordType.php`, which is the one the frontend uses. Keep one.
  - Make the classes `final` (they have no extension points), add `configureOptions()` with `translation_domain` / constraint options.
  - `form.yml:1-2` `coreshop.form.type.user.validation_groups` is defined but never used; `_defaults: public: true` makes the form types public for no reason. All three form types have no dependencies; the explicit service definitions can go (Symfony instantiates form types by FQCN) or use `autoconfigure: true`.

### I-09 — Service and Pimcore config files
- **Where:** `Resources/config/services.yml`, `Resources/config/pimcore/*.yml`.
- **Fix:**
  - `services.yml:4-8` an empty `services:` block with `_defaults` only; remove it (keep the import).
  - `pimcore/routing.yml` is empty; delete it or add a comment why it exists.
  - `pimcore/admin.yml:6` key `usre` (typo for `user`); the key is the identifier that other bundles would use to override the CSS.
  - `Resources/public/pimcore/js/resource.js` registers an empty resource; fine, but the CSS/JS only exist for the menu icon — document it.

### I-10 — Validation
- **Where:** `Resources/config/validation/User.yml`.
- **Fix:** `NotBlank` on `plainPassword` in the generic `coreshop` group applies to every validation of a user in that group (also profile edits that do not touch the password, if a project validates the user there). Move it into a dedicated `coreshop_registration` group; add the password policy (TODO-2 **B-02**) and `NotBlank` + `Length(max: 190)` on `loginIdentifier` (column length in `CoreShopUser.json`).

### I-11 — Class definition JSON
- **Where:** `Resources/install/pimcore/classes/CoreShopUser.json` (only used standalone; CoreBundle installs its own copy, `CoreBundle/Resources/config/pimcore/coreshop/coreshop_user.yml:6`).
- **Fix:** keep both JSON files in sync (the CoreBundle copy needed migration `Version20260419121310` for `passwordResetHashCreatedAt`); `loginIdentifier` `mandatory: true` (TODO-2 **B-04**); index on `passwordResetHash` (**I-07**); `minimumLength` on `password` (TODO-2 **B-02**).

## Logic / maintainability

### I-12 — Tests
- The bundle has no tests. Add at least: unit tests for `UserRepository::findByResetTokenSecure()` (format check, TTL boundaries, missing `createdAt`), a model test for `isEqualTo()` after a password change (TODO-1 **C-01**) and for serialization without `plainPassword` (TODO-1 **C-02**), form tests for `RequestResetPasswordType` in both identifier modes (TODO-1 **C-03**) and for `ResetPasswordType` rejecting empty / short passwords (TODO-2 **B-01**), and a configuration tree test.
