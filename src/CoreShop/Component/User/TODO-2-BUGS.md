# User Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs that give wrong results or avoidable 500s in a limited area, but do not take down a whole area on their own.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Prefixes and markers as in TODO-1; UserBundle items are referenced as UserBundle **C-/B-/I-nn**.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Model / User

### CB-01 — `getUserIdentifier(): string` returns the nullable login identifier → `TypeError` on every request of that session — (verified code path) (dependent: UserBundle TODO-2 **B-04**)
- **Where:** `Model/User.php:76-79` (and the identical override in `Component/Core/Model/User.php:26-29`), source `Model/UserInterface.php:34` (`getLoginIdentifier(): ?string`); `loginIdentifier` is `mandatory: false` in `UserBundle/Resources/install/pimcore/classes/CoreShopUser.json`.
- **Problem:** `declare(strict_types=1)` + `?string` → `string` return. A user whose identifier is empty (admin edit, import) throws on every request carrying a token. Symfony documents the return value as `non-empty-string`, so `''` is not a valid fallback either.
- **Fix (component side):** throw a descriptive `\LogicException('User %d has no login identifier')` instead of the bare `TypeError`, and refuse such users before authentication (a `UserChecker`, dependent). Remove the duplicated override in `Component/Core/Model/User.php` (TODO-3 **CI-04**). Making the field mandatory is UserBundle **B-04**.

### CB-02 — `eraseCredentials()` dereferences a field definition that may not exist → `Error` at every login — (verified code path) (plausible: trigger)
- **Where:** `Model/User.php:64-69`. `vendor/six-it/pimcore-source/models/DataObject/Traits/FieldDefinitionEnrichmentModelTrait.php:89` (`getFieldDefinition(): ?Data`). Called by `vendor/symfony/security-http/Authentication/AuthenticatorManager.php:212-213` after every successful authentication (form login, remember-me, `CustomerLoginService::loginCustomer()`).
- **Problem:**
  - The password field name `password` is hard-coded and the result is used without a null check. A project user class that names the field differently, or moves it into a field collection / localized field, gets `Call to a member function getDataForResource() on null` → every login returns 500.
  - The `/** @var Password $field */` annotation is wrong for any non-`Password` field; with e.g. an `input` field the call silently does nothing.
  - When the in-memory password is still plain text, `Password::getDataForResource()` (`…/ClassDefinition/Data/Password.php:135-184`) **writes the new hash back via `setPassword()`**, marking the object dirty during a read-only request; the doc block ("remove the plain text password") does not describe what actually happens, and `plainPassword` is left untouched (TODO-1 **CC-02**).
  - Each call resolves the class definition (`getClass()` → `ClassDefinition::getById()`), an extra lookup per login.
- **Fix:** see TODO-3 **CI-01** (the method is deprecated since Symfony 7.3 and should become a no-op). Until then: `$field = $this->getClass()->getFieldDefinition('password'); if (!$field instanceof Password) { return; }`.

### CB-03 — Base `getRoles()` always returns `[]`; `ROLE_USER` is only added in `Component/Core` — (verified code path)
- **Where:** `Model/User.php:28` (`private array $roles = []`, never written), `:71-74`; `Model/UserInterface.php:30` (`CORESHOP_ROLE_DEFAULT`); the only place that applies the constant is `Component/Core/Model/User.php:31-52`.
- **Problem:** `coreshop/user` is published as a standalone package. A project that extends `CoreShop\Component\User\Model\User` without the Core component gets users with **no roles**: every `access_control` / `#[IsGranted('ROLE_USER')]` rule denies them although they are logged in. The constant on the interface suggests the component guarantees the default role, but it does not. The `private` property is also invisible to Pimcore's `AbstractModel::__sleep()` (`get_object_vars()` runs in the parent scope), so a subclass that did fill it would lose the roles on every session round-trip.
- **Fix:** `getRoles(): array { return [self::CORESHOP_ROLE_DEFAULT]; }` in the base class; drop the property (UserBundle TODO-3 **I-03**). `Component/Core/Model/User::getRoles()` can then merge group roles into `parent::getRoles()` without the `in_array` check.

### CB-04 — `setPlainPassword()` / `setSalt()` signatures do not match their getters or the forms that use them — (reproduced)
- **Where:** `Model/User.php:32-37` (`setPlainPassword(string)` vs `getPlainPassword(): ?string`), `:44-56` (`setSalt(string)` stores a value that `getSalt()` never returns).
- **Problem:**
  - `setPlainPassword(null)` is a `TypeError`, so the value cannot be reset (blocks the fix of TODO-1 **CC-02**). An empty registration submission currently only avoids the `TypeError` because Symfony Form skips the setter when old and new value are both `null` (reproduced with `symfony/form` 7.4 + `RepeatedType`); a user object that already carries a value (re-submitted form after a validation error on another field, re-used object) gets an uncaught `Symfony\Component\PropertyAccess\Exception\InvalidTypeException: Expected argument of type "string", "null" given at property path "plainPassword"` → 500 instead of the `NotBlank` message (reproduced).
  - `setSalt()` silently accepts data that is ignored; a project hasher that relies on `getSalt()` (legacy hashes) gets `null` without any error.
  - Neither setter is declared on `UserInterface`, so typed callers (e.g. `CustomerManager`) cannot use them without a concrete-class assumption.
- **Fix:** `setPlainPassword(#[\SensitiveParameter] ?string $plainPassword): static` on the interface and the class; remove `$salt` / `setSalt()` / `getSalt()` (TODO-3 **CI-02**) or implement `LegacyPasswordAuthenticatedUserInterface` explicitly and return the stored salt.

## Repository contract

### CB-05 — Deprecated `findByResetToken()` is still part of the interface and matches the raw input against the stored **hash** without TTL — (verified code path) (dependent: UserBundle TODO-2 **B-08**)
- **Where:** `Repository/UserRepositoryInterface.php:26-29`; implementation `UserBundle/Pimcore/Repository/UserRepository.php:30-41`.
- **Problem:** the interface still forces every implementation to provide it, the deprecation is a doc block only (no runtime notice), and the documented meaning ("find by reset token") is wrong since the column holds `sha256(token)`: a caller passing the token never finds the user, a caller passing the hash (stored data, e.g. from a DB dump or admin export) gets a user without expiry check.
- **Fix:** `#[\Deprecated(message: 'Use findByResetTokenSecure()', since: '4.1')]` on the interface method and the implementation now; remove it in 5.0. Make the TTL configurable (UserBundle **B-08**).

### CB-06 — `findByLoginIdentifier()` contract does not define behaviour for duplicates — (dependent: UserBundle TODO-2 **B-05**)
- **Where:** `Repository/UserRepositoryInterface.php:41`.
- **Problem:** the interface returns a single `?UserInterface` but says nothing about uniqueness; the only implementation returns the first row of an unordered query when there are several. Authentication and password reset then act on a non-deterministic account.
- **Fix:** document "returns `null` (and logs) when the identifier is not unique" on the interface; implement in UserBundle **B-05**.

## Packaging

### CB-07 — `composer.json` does not declare what the component uses
- **Where:** `composer.json:26-35`, `:50-52`.
- **Problem:**
  - Missing `"php": "~8.4.0"` (root `composer.json:88`).
  - `nesbot/carbon` is used in the public contract (`Model/UserInterface.php:21,48,50`) but not required; it only arrives transitively through Pimcore.
  - `require-dev` pins `phpstan/phpstan ^1.10`, `phpstan-doctrine ^1.3.40`, `phpstan-webmozart-assert ^1.2.4`; the root uses `phpstan/phpstan ^2.1` (root `composer.json:134`).
  - `branch-alias` is `dev-master → 4.1-dev`; the root uses `dev-next → 4.1-dev` (root `composer.json:187`).
- **Fix:** add `php` and `nesbot/carbon` (or remove Carbon from the contract, TODO-3 **CI-04**), bump the phpstan packages to 2.x, align the branch alias with the root. Hygiene items: TODO-3 **CI-07**.
