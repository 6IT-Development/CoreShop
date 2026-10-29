# User Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: all 4 PHP files in `src/CoreShop/Component/User` (`Model/User.php`, `Model/UserInterface.php`, `Model/UserAwareInterface.php`, `Repository/UserRepositoryInterface.php`), its `composer.json` and `README.md`. Dependent config and callers that were followed: `Bundle/UserBundle/{Pimcore/Repository/UserRepository.php,DependencyInjection/Configuration.php,Resources/config/validation/User.yml,Resources/install/pimcore/classes/CoreShopUser.json}`, `Component/Core/Model/{User.php,UserInterface.php,CustomerInterface.php}`, `Bundle/CoreBundle/{Security/ObjectUserProvider.php,Customer/CustomerManager.php,Form/Type/UserRegistrationType.php,Migrations/Version20260419121310.php,Resources/config/pimcore/security.yml}`, `Bundle/FrontendBundle/Controller/RegisterController.php`, and the generated classes of the test project (`../pimcore2teszt10211524/var/classes/DataObject/{CoreShopUser,CoreShopCustomer}.php`).

The bundle-side wiring has already been scanned in `src/CoreShop/Bundle/UserBundle` (its TODO files use `C-`, `B-`, `I-` numbers). Several root causes listed there live in this component; they are listed here again with the **component-side** part of the fix and a cross-reference, so either list can be worked from. When an item is fixed, close it in both files.

"Critical" here means: a security issue, data written wrongly, or an error that takes down every request / a whole area (login, session, registration, password reset) once it is triggered, or a blocker for Symfony 7.4 / PHP 8.4.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4, six-it/pimcore-source 2.x, PHP 8.4.27). Component items use the prefixes `CC-` (critical), `CB-` (bugs) and `CI-` (improvements).
Markers: **(dependent)** = root cause or part of the fix is outside this component; **(reproduced)** = run with PHP 8.4.27 against the vendored libraries; **(verified)** = confirmed by reading the vendored library source; **(plausible)** = follows from the code, but the trigger depends on data, configuration or deployment order.

**No Symfony 7.4 / PHP 8.4 language blocker was found.** All 4 PHP files pass `php -l` on 8.4.27; no implicit nullable parameters, no dynamic properties, signatures are compatible with `symfony/security-core` 7.4 (`UserInterface`, `PasswordAuthenticatedUserInterface`, `EquatableInterface`). Runtime deprecations that become errors in Symfony 8 (`eraseCredentials()`, `getUsername()`, `getSalt()`) are listed in TODO-3 **CI-01**, **CI-02**.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Model / User – security

### CC-01 — `isEqualTo()` compares only the id: password change / reset / unpublish never ends other sessions — (verified) (dependent: UserBundle TODO-1 **C-01**)
- **Where:** `Model/User.php:89-92`; `EquatableInterface` is pulled in by `Model/UserInterface.php:28`. `vendor/symfony/security-http/Firewall/ContextListener.php:303-306`: for an `EquatableInterface` user, `hasUserChanged()` is **only** `!isEqualTo()`; Symfony's own password / identifier / roles comparison is skipped.
- **Problem:**
  ```php
  return $user instanceof self && $user->getId() === $this->getId();
  ```
  - A session obtained with the old password survives a password reset or change; disabling (unpublishing) the user in the Pimcore admin does not log anyone out either. Full impact analysis: UserBundle **C-01**.
  - Component-specific edge: two **unsaved** users (`getId() === null`) are considered equal.
- **Fix (component side):**
  - Compare everything that defines the authenticated state: id (non-null), `getPassword()`, `getUserIdentifier()`, `isPublished()`. Or drop `EquatableInterface` from `UserInterface` and let Symfony's default comparison run (then `getRoles()` changes also log out, which is the Symfony default).
  - If the session password is later replaced by its `crc32c` hash (see TODO-3 **CI-01**), the comparison must use the same representation; Symfony's default comparison already handles that (`ContextListener.php:319`), a custom `isEqualTo()` would not — another reason to prefer removing `EquatableInterface`.
  - `isEqualTo()` parameter: use the imported alias (`SymfonyUserInterface`) instead of the FQCN.

### CC-02 — `plainPassword` is a normal, serialized property that can never be cleared → plain-text password in Pimcore versions, cache and session — (verified code path) (dependent: UserBundle TODO-1 **C-02**)
- **Where:** `Model/User.php:30` (`protected ?string $plainPassword`), `:32-37` (`setPlainPassword(string $password)` — **not nullable**), `:58-69` (`eraseCredentials()` does not touch `plainPassword`). `Model/UserInterface.php:42` exposes `getPlainPassword()` but no setter. Writers: `CoreBundle/Form/Type/UserRegistrationType.php:39` (property path `plainPassword`), `CoreBundle/Customer/CustomerManager.php:91,99` (copies it into `password`, then `save()` with `plainPassword` still set).
- **Problem:**
  - `vendor/six-it/pimcore-source/lib/Model/AbstractModel.php:192-199` serializes `get_object_vars($this)`; `Element/AbstractElement.php:637-650`, `DataObject/AbstractObject.php:131-144` and `DataObject/Concrete.php:674-694` only block Pimcore-internal names. `plainPassword` is therefore written into:
    - the Pimcore **version** of the object (dump state) on every `save()` while it is set — DB / `var/versions`, backups;
    - the **session** (the same object becomes the token user right after registration, `CoreBundle/EventListener/CustomerLoginListener.php`);
    - the Pimcore **cache** if the instance is cached while the value is set (plausible).
  - Even a caller that wants to clean up cannot: `setPlainPassword(null)` is a `TypeError` under `strict_types`, and the setter is not part of `UserInterface`, so code typed against the interface cannot call it at all.
- **Fix (component side):**
  - `public function setPlainPassword(#[\SensitiveParameter] ?string $plainPassword): static` and add it to `UserInterface`.
  - Override `getBlockedVars()` in `Model/User.php` (`[...parent::getBlockedVars(), 'plainPassword']`) so neither versions nor cache nor session ever contain it. **Do not** implement `__serialize()` on this class: it takes precedence over Pimcore's `__sleep()` and would break Pimcore versioning / caching of the object.
  - Clear it right after hashing: `CustomerManager` (dependent), or generically in a pre-add / pre-update listener for `UserInterface`.
  - Data clean-up for existing installations (scrub versions of user objects) is tracked in UserBundle **C-02**.

## Model / UserInterface – contract

### CC-03 — New abstract methods in `UserInterface` fatally break every user class that has no `passwordResetHashCreatedAt` field — (plausible) (dependent)
- **Where:** `Model/UserInterface.php:48-50` (`getPasswordResetHashCreatedAt()` / `setPasswordResetHashCreatedAt()`, added in a 4.x minor; the migration description cites upstream commit `bb2de14712`, which is not in this repository's history). Field is added by `CoreBundle/Migrations/Version20260419121310.php` (only if `coreshop.model.user.pimcore_class_name` points to the class). Callers: `Bundle/UserBundle/Pimcore/Repository/UserRepository.php:69`, `FrontendBundle/Controller/RegisterController.php:100,148`.
- **Problem:**
  - The Pimcore-generated user class (`Pimcore\Model\DataObject\CoreShopUser extends Component\Core\Model\User`) is concrete. If its class definition lacks the `passwordResetHashCreatedAt` field, the generated class does not implement the two interface methods and PHP aborts with *"Class … contains 2 abstract methods and must therefore be declared abstract"* as soon as the class is loaded: login, session refresh (`ObjectUserProvider::refreshUser()`), customer pages, registration, admin object editor — practically every request of a logged-in visitor.
  - Affected: any project between code deploy and `doctrine:migrations:migrate`; projects whose user class is not the configured `pimcore_class_name` (second user class, custom class with `UserInterface`); projects that skipped / marked the migration as executed; any non-Pimcore implementation of `UserInterface` (BC break for a minor release).
  - Observation: the test project `../pimcore2teszt10211524` currently has no `passwordResetHashCreatedAt` in `var/classes/definition_CoreShopUser.php` / `DataObject/CoreShopUser.php` (its generated classes are stale for other reasons too, so it could not be loaded to reproduce the fatal directly).
- **Fix:**
  - Do not add abstract methods to a published interface in a minor version. Move the two methods into a separate interface (e.g. `PasswordResetTokenAwareInterface`), implement it on `Component/Core/Model/User` only when the field exists, and have `UserRepository::findByResetTokenSecure()` / `RegisterController` check `instanceof` (or fall back to `getObjectVar('passwordResetHashCreatedAt')`).
  - Alternatively provide concrete default implementations in `Model/User.php` that delegate to `getObjectVar()` / `setObjectVar()` and return `null` / no-op when the field does not exist; the generated getters/setters override them once the field exists.
  - Add an UPGRADE note and a `coreshop:install`/`cache:clear`-time check that reports a missing field instead of a fatal error.
