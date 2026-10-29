# User Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility (Symfony 8) items. None of them is a functional bug on its own.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Prefixes and markers as in TODO-1; UserBundle items are referenced as UserBundle **C-/B-/I-nn**.

**Already fine for Symfony 7.4:** the model implements `getUserIdentifier()` and `PasswordAuthenticatedUserInterface`, all interface methods have native parameter types, no Symfony API removed in 7.x is required by the contract (`getUsername()` / `getSalt()` are extra methods, not interface implementations).

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / Symfony 8 forward compatibility

### CI-01 — Replace `eraseCredentials()` with a serialization-based clean-up — (dependent: UserBundle TODO-3 **I-02**)
- **Where:** `Model/User.php:58-69`; `vendor/symfony/security-core/User/UserInterface.php:51-58` (`@deprecated since Symfony 7.3, erase credentials using the "__serialize()" method instead`); `vendor/symfony/security-http/Authentication/AuthenticatorManager.php:212-213,295+` (deprecation notice at every login unless the method carries `#[\Deprecated]`).
- **Fix:**
  - 7.4: mark the method `#[\Deprecated]`, make it a no-op (or `$this->plainPassword = null;`), drop the class-definition lookup (TODO-2 **CB-02**). Remove it once Symfony 8 is the minimum.
  - Do the actual clean-up via Pimcore's `getBlockedVars()` (TODO-1 **CC-02**), **not** `__serialize()`: `__serialize()` overrides Pimcore's `__sleep()` and would change what goes into versions and cache.
  - Optional (Symfony 7.3+ feature, `vendor/symfony/security-core/User/PasswordAuthenticatedUserInterface.php:20-32`): store only `hash('crc32c', $password)` in the session. With Pimcore objects this needs a session-only serialization path (the same object is also cached/versioned), so evaluate together with dropping `EquatableInterface` (TODO-1 **CC-01**); otherwise document why the full hash stays in the session.

### CI-02 — Remove Symfony 5 leftovers from the model — (dependent: UserBundle TODO-3 **I-03**)
- **Where:** `Model/User.php:26` (`$salt`), `:44-56` (`setSalt()` / `getSalt()`), `:81-84` (`getUsername()`, removed from Symfony's `UserInterface` in 6.0), `:28` (`$roles`, see TODO-2 **CB-03**).
- **Fix:** delete them (BC: deprecate first with `#[\Deprecated]` in 4.x, remove in 5.0). Check projects / templates for `user.username` before removal; `getUserIdentifier()` / `getLoginIdentifier()` replace it.

### CI-03 — Password re-hashing (`PasswordUpgraderInterface`) — (dependent)
- **Where:** `Repository/UserRepositoryInterface.php`; consumer `CoreBundle/Security/ObjectUserProvider.php`; hasher wiring via Pimcore `encoder_factories` in `CoreBundle/Resources/config/pimcore/security.yml:3-7`.
- **Fix:** hashes are never upgraded when the algorithm or cost changes (`password_hash` defaults move with PHP versions). Let `ObjectUserProvider` (or the repository) implement `Symfony\Component\Security\Core\User\PasswordUpgraderInterface::upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword)` and declare a `setPassword()` contract that accepts an already-hashed value (Pimcore's `Password` field keeps hashes as-is, `…/ClassDefinition/Data/Password.php:141-146`). Needs the move from Pimcore `encoder_factories` to Symfony `password_hashers` (UserBundle TODO-3 **I-04**).

## PHP 8.4 language features and contract hygiene

### CI-04 — `UserInterface` cleanup
- **Where:** `Model/UserInterface.php`.
- **Fix:**
  - `:28` `extends ResourceInterface, PimcoreModelInterface, …` — `PimcoreModelInterface` already extends `ResourceInterface` (`Component/Resource/Pimcore/Model/PimcoreModelInterface.php:26`); drop the duplicate. `:32` `getId(): ?int` is redeclared from `PimcoreModelInterface:28`; drop it. `:38` `getPassword(): ?string` duplicates `PasswordAuthenticatedUserInterface`; keep only if intended as documentation.
  - `:30` typed class constant (PHP 8.3+): `public const string CORESHOP_ROLE_DEFAULT = 'ROLE_USER';`.
  - `:36` parameter typo `$loginIdentifer` → `$loginIdentifier` (named-argument callers would break on the rename later, so fix it now).
  - `:36,40,46,50` setters without return type → `: static` (the generated Pimcore setters already return `static`, see `var/classes/DataObject/CoreShopUser.php:79,115,151` in the test project).
  - `:21,48,50` `Carbon` in the contract → `?\DateTimeInterface` for the getter (Carbon implements it) and `?\DateTimeInterface` for the setter; removes the undeclared Carbon dependency (TODO-2 **CB-07**). Generated Pimcore getters returning `?Carbon` stay compatible (covariant return).
  - Add `setPlainPassword()` (TODO-2 **CB-04**) and the doc that `getPlainPassword()` is transient and never persisted.
  - Move `getPasswordResetHash*()` into a separate interface (TODO-1 **CC-03**).

### CI-05 — `Model/User.php` readability
- **Fix:**
  - `#[\Override]` (PHP 8.3+) on `getRoles()`, `getUserIdentifier()`, `isEqualTo()`, `eraseCredentials()` so signature drift in Symfony is caught at compile time.
  - `#[\SensitiveParameter]` on every parameter that carries a password (`setPlainPassword()`), so stack traces / Sentry do not contain it.
  - `:58-63` doc block claims the method removes the plain password; it does not (TODO-2 **CB-02**). `:62` `{@inheritdoc}` / `:87` `@inheritdoc` add nothing with native types — remove.
  - `:89` use the imported alias for Symfony's `UserInterface` instead of the inline FQCN (`UserInterface.php:26` already imports it as `SymfonyUserInterface`).
  - Setters `:32,44` return `$this` without a return type → `: static`.
  - Duplicate `getUserIdentifier()` in `Component/Core/Model/User.php:26-29` is identical to the base; remove it there.
  - Property hooks / asymmetric visibility (PHP 8.4) are **not** recommended here: Pimcore generates accessors and serializes properties by name; keep plain methods.

### CI-06 — `UserAwareInterface` / repository contract
- **Where:** `Model/UserAwareInterface.php:25`, `Repository/UserRepositoryInterface.php:26-41`.
- **Fix:**
  - `setUser(?UserInterface $user)` → `: static` (the generated `CoreShopCustomer::setUser()` already returns `static`).
  - `findByResetTokenSecure()` → after `findByResetToken()` is removed (TODO-2 **CB-05**), rename to `findByValidResetToken()` (keep the old name as a deprecated alias for one major); the "Secure" suffix only makes sense next to an insecure twin.
  - The TTL default `3600` is a magic number repeated in the interface and the implementation (`UserBundle/Pimcore/Repository/UserRepository.php:43`); define it once (`public const int DEFAULT_RESET_TOKEN_TTL = 3600;`) and feed it from configuration (UserBundle TODO-2 **B-08**).
  - Add `@throws \InvalidArgumentException` for `$ttlSeconds <= 0` (the implementation throws, the contract does not say so) and document the token format (64 hex characters) the implementation enforces.

## Packaging, docs and tests

### CI-07 — `composer.json` hygiene
- `:41-46` (`repositories`) use 4-space indentation, `:49` (`"extra"`) 3 spaces; the rest uses 2.
- `repositories` (`path` `../../*/*`) and `"minimum-stability": "dev"` have no effect for consumers of a library; keep them in the monorepo root only.
- After TODO-2 **CB-07**: `composer validate --strict` and `composer normalize` for the component.

### CI-08 — README / documentation
- `README.md` is the generic CoreShop boilerplate (links to the old `coreshop.org` domain, partly over `http://`). Document: what a Pimcore class must provide to implement `UserInterface` (`loginIdentifier`, `password` as Pimcore `password` field, `passwordResetHash`, `passwordResetHashCreatedAt`), that `plainPassword` is transient, the role model (`CORESHOP_ROLE_DEFAULT` + customer-group roles in Core), and the reset-token contract (raw token → `sha256` in DB, TTL).

### CI-09 — Unit tests
- The component has no tests. Add (no Pimcore DB needed, use a test double extending `User`):
  - `isEqualTo()`: same id / different password / unpublished / both ids `null` (TODO-1 **CC-01**).
  - `plainPassword` not contained in `serialize()` output, in dump state and in cache state (TODO-1 **CC-02**).
  - `getUserIdentifier()` with `null` identifier throws the documented exception (TODO-2 **CB-01**).
  - `getRoles()` contains `ROLE_USER` (TODO-2 **CB-03**).
  - `setPlainPassword(null)` (TODO-2 **CB-04**).
- Include the component in the root phpstan configuration at the root's level once TODO-2 **CB-07** is done.
