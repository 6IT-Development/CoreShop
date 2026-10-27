# UserBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (10 files: `CoreShopUserBundle.php`, `DependencyInjection/*`, `Event/*`, `Form/Type/*`, `Pimcore/Repository/*`), `composer.json`, all of `Resources/config/**` (`services.yml`, `services/form.yml`, `pimcore/{config,admin,routing}.yml`, `validation/User.yml`), `Resources/install/pimcore/{classes/CoreShopUser.json,admin-translations.yml}` and `Resources/public/pimcore/js/resource.js`. The model and repository contract live in `Component/User`, so every class there was read (`Model/*`, `Repository/*`). Call paths were followed into the consumers that define how this bundle behaves at runtime: `Component/Core/Model/User.php`, `CoreBundle` (`Security/ObjectUserProvider.php`, `Customer/{CustomerManager,CustomerLoginService}.php`, `EventListener/CustomerLoginListener.php`, `Form/Type/UserRegistrationType.php`, `Validator/Constraints/UniqueCustomerValidator.php`, `Resources/config/pimcore/{security.yml,coreshop/coreshop_user.yml}`, `Migrations/Version20260419121310.php`), `FrontendBundle/Controller/RegisterController.php`, `CustomerBundle/DependencyInjection/Configuration.php` (`login_identifier`) and `ResourceBundle` (`AbstractModelExtension`, `Pimcore/PimcoreRepository`).

Reference for API checks: `vendor/` (`symfony/*` 7.4.21, `six-it/pimcore-source`), the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml` (+ its `…Deprecations.log`).

"Critical" here means: a security issue, money charged wrongly / data written wrongly, or an error that takes down every request, a whole area (cart, checkout, admin section, login / password reset), or the install / container build once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code (PHP 8.4.27) or by reading the vendored library source / the compiled container.

**Symfony 7.4 / PHP 8.4 compatibility:** no container-level blockers. `php -l` passes, the bundle's services resolve in the compiled container, and the container deprecation log has no entry for this bundle. There are runtime deprecations that will become errors in Symfony 8: `User::eraseCredentials()` (deprecated since 7.3, triggered at login by `AuthenticatorManager::checkEraseCredentials()`), the dead `getUsername()` / `getSalt()` leftovers, and the Symfony 5-style `security.yml` in CoreBundle that the user model is wired into. See TODO-3 **I-01** to **I-05**.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security

### C-01 — `isEqualTo()` compares only the id: changing or resetting the password (or unpublishing the user) never ends other sessions — (verified) (dependent)
- **Where:** `Component/User/Model/User.php:89-92` (`EquatableInterface` from `Component/User/Model/UserInterface.php:28`); `vendor/symfony/security-http/Firewall/ContextListener.php:303-306`: when the user implements `EquatableInterface`, `hasUserChanged()` is **only** `!isEqualTo()`; the built-in password / salt / identifier / roles comparison at `:309+` is skipped. `CoreBundle/Security/ObjectUserProvider.php:57` refreshes via `find($id)` (`getById()`, includes unpublished objects).
- **Impact:**
  - After a password reset (`FrontendBundle/Controller/RegisterController.php:145-150`) or a password change in the customer area, every existing session of that user stays logged in, including a session an attacker obtained with the old password. The standard "reset your password to kick out the attacker" response does not work.
  - Unpublishing / disabling the user in the Pimcore admin does not log out active sessions either.
  - Remember-me cookies are invalidated by the password change (signature includes `password`), so only live sessions are affected, which hides the problem in testing.
- **Fix:** compare what identifies an authenticated state: `$user instanceof self && $user->getId() === $this->getId() && $user->getPassword() === $this->getPassword() && $user->getUserIdentifier() === $this->getUserIdentifier() && $user->isPublished() === $this->isPublished()` — or remove `EquatableInterface` and let Symfony's default comparison run. Add a functional test: log in, reset the password in a second client, the first client must be logged out.

### C-02 — The plain-text password is stored in Pimcore version history and in the session after registration — (verified code path) (plausible: impact) (dependent)
- **Where:**
  - `Component/User/Model/User.php:30,32-37`: `protected ?string $plainPassword` is a normal property of the Pimcore object; nothing ever clears it. `:64-69` `eraseCredentials()` computes the hash and **discards** it; it does not touch `plainPassword`.
  - `CoreBundle/Form/Type/UserRegistrationType.php:39` writes the registration password into `plainPassword`; `CoreBundle/Customer/CustomerManager.php:91,99` copies it into `password` and calls `$userBackup->save()` with `plainPassword` still set.
  - `vendor/six-it/pimcore-source/models/DataObject/Concrete.php:674-694` / `models/Element/AbstractElement.php:642-650`: `__sleep()` serializes every property except a small block list; `plainPassword` is not in it. Pimcore's version on save serializes the object in dump state, so the version file / row contains the plain password.
  - `CoreBundle/EventListener/CustomerLoginListener.php:36` → `CustomerLoginService::loginCustomer()` puts the **same** object into a `UsernamePasswordToken`, which is serialized into the session at the end of the registration request.
- **Impact:** every customer who registers has the plain-text password written to the Pimcore version store (DB `versions` table / `var/versions`, part of backups, visible to anyone with file or DB access) and to the session storage. This is a credential-storage vulnerability (CWE-256/CWE-312).
- **Fix:**
  - Clear `plainPassword` right after hashing (`CustomerManager` before `save()`; generally in a `pre_add`/`pre_update` listener for `UserInterface`).
  - Implement `__serialize()` / extend Pimcore's blocked vars (`getBlockedVars()`) so `plainPassword` is never serialized; mark it `#[\SensitiveParameter]` in `setPlainPassword()`.
  - Replace `eraseCredentials()` with this (see TODO-3 **I-02**).
  - Migration / maintenance task: scrub existing versions of `CoreShopUser` objects.

## Password reset broken

### C-03 — Password reset crashes when the shop uses `login_identifier: username` — (verified code path) (dependent)
- **Where:** `Form/Type/RequestResetPasswordType.php:32-40` names the field after the identifier (`email` **or** `username`); `FrontendBundle/Controller/RegisterController.php:76-87` always reads `$passwordResetData['email']`. `CustomerBundle/DependencyInjection/Configuration.php:43` allows `username`.
- **Impact:** with `core_shop_customer.login_identifier: username` the form field is `username`, `$passwordResetData['email']` is an undefined key (warning → `ErrorException` in debug; `null` in prod) and `findByLoginIdentifier(null)` throws a `TypeError` (`string` parameter). Every password-reset request returns a 500: users of such shops cannot recover their accounts at all.
- **Fix:** read `$passwordResetData[$resetIdentifier]` in the controller, or give the form a fixed field name (`identifier`) and only switch the type/label. Add a functional test for both identifier modes. For the label keys see TODO-2 **B-06**.
