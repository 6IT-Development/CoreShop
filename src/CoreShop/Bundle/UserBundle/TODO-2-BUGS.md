# UserBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs that give wrong results, wrong UI behaviour or avoidable 500s in a limited area, but do not take down a whole area on their own.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code (PHP 8.4.27) or by reading the vendored library source / the compiled container.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Password forms

### B-01 — `ResetPasswordType` accepts an empty password: the token is used up and the account is left without a password — (verified code path) (dependent)
- **Where:** `Form/Type/ResetPasswordType.php:30-35` (no constraints, no `invalid_message`), `FrontendBundle/Controller/RegisterController.php:142-150`, `vendor/six-it/pimcore-source/models/DataObject/ClassDefinition/Data/Password.php:135-139` (`empty($data)` → stored as `null`).
- **Problem:** `required: true` is only an HTML attribute. A submission with two empty fields (or `"0"`, which PHP's `empty()` treats as empty) is valid, so the controller clears the reset token and saves `password = null`. The user can no longer log in and has to request a new reset; there is no error message.
- **Fix:** `constraints: [new NotBlank(), new Length(min: 8, max: 4096), new NotCompromisedPassword()]` (or `PasswordStrength`) on the repeated field; set `invalid_message` (the "must match" key is used in `ChangePasswordType` already). Pass the constraints via an option so projects can change the policy.

### B-02 — No password policy anywhere in the bundle — (verified code path) (dependent)
- **Where:** `Form/Type/ChangePasswordType.php:37-43`, `Form/Type/ResetPasswordType.php:30-35`, `Resources/config/validation/User.yml:1-4` (only `NotBlank` on `plainPassword`), `Resources/install/pimcore/classes/CoreShopUser.json` (`password` field has no `minimumLength`).
- **Problem:** one-character passwords are accepted on registration, change and reset. There is no maximum either (`password_hash()` with bcrypt silently truncates at 72 bytes; very long inputs cost CPU).
- **Fix:** one shared constraint set (`Length(min: 8, max: 4096)`, `NotCompromisedPassword`, optionally `PasswordStrength`), applied to `plainPassword` and to both form types; document how to override it. The same applies to `CustomerBundle/Form/Type/ChangePasswordType.php` (the copy that the frontend really uses).

### B-03 — `ChangePasswordType` has no validation on the new password, and `UserPassword` needs a configured hasher — (verified code path) (dependent)
- **Where:** `Form/Type/ChangePasswordType.php:31-43`.
- **Problem:** see **B-01**/**B-02** for the empty/weak password. In addition, `UserPassword` uses the `PasswordHasherFactory` for the authenticated user's class; CoreShop registers its hasher through Pimcore's `encoder_factories` (`CoreBundle/Resources/config/pimcore/security.yml:5-7`), so the constraint only works while that bridge exists. The class is not used by the frontend (`FrontendBundle/Controller/CustomerController.php:22` uses `CustomerBundle`'s copy), so the bug is latent here but real for projects using it.
- **Fix:** add the constraints; decide which of the two `ChangePasswordType` classes is the canonical one (TODO-3 **I-08**).

## Model / repository

### B-04 — `getUserIdentifier(): string` returns `null` for users without a login identifier → `TypeError` on every request of that session — (verified code path) (dependent)
- **Where:** `Component/User/Model/User.php:76-79` and `Component/Core/Model/User.php:26-29` (`return $this->getLoginIdentifier();` with `?string` source, `declare(strict_types=1)`); `Resources/install/pimcore/classes/CoreShopUser.json` (`loginIdentifier` `mandatory: false`).
- **Problem:** a user whose `loginIdentifier` is empty (created or edited in the Pimcore admin, imported, or emptied while logged in) makes `getUserIdentifier()` throw. Symfony calls it on every request with a token (`ContextListener`, `TokenInterface::getUserIdentifier()`, remember-me), so the affected session gets a 500 on every page until the cookie is removed.
- **Fix:** make `loginIdentifier` mandatory in the class definition (with a migration), and throw a clear `\LogicException` (or return `''` and refuse authentication via a `UserChecker`) instead of a `TypeError`.

### B-05 — `findByLoginIdentifier()` silently returns the first of several matches; `findByResetTokenSecure()` needs exactly one — (verified code path)
- **Where:** `Pimcore/Repository/UserRepository.php:88-105`, `:58-64`.
- **Problem:**
  - `loginIdentifier` is `unique` in the shipped class (`CoreShopUser.json`), but a project class (or one created before the flag) may not be. With duplicates, `findByLoginIdentifier()` logs in / resets the password of whichever row the DB returns first, without `ORDER BY` (non-deterministic). `UniqueCustomerValidator` (`CoreBundle/Validator/Constraints/UniqueCustomerValidator.php:55`) has the same blind spot.
  - `findByResetTokenSecure()` treats `count !== 1` as "no user"; the reset hash column has no unique index (`passwordResetHash` `index: false`), so the (astronomically unlikely) collision case is fine, but the inconsistency between the two finders is confusing.
- **Fix:** `setLimit(2)` and treat `> 1` as an error (log + `null`) in `findByLoginIdentifier()`; add a deterministic `setOrderKey('o_id')`. Document that `loginIdentifier` must be unique.

### B-06 — Inconsistent label keys, none of them shipped as translations — (verified)
- **Where:** `Form/Type/RequestResetPasswordType.php:36` (`coreshop.form.customer.%s` → `coreshop.form.customer.email` / `.username`), `Form/Type/ResetPasswordType.php:33-34` (`coreshop.form.user.password`, `coreshop.form.user.password_repeat`), `Form/Type/ChangePasswordType.php:32,41-42`, `Form/Type/UserLoginType.php:33-39` vs. `CoreBundle/Form/Type/UserRegistrationType.php:41-42` (`coreshop.form.user.password.label` / `.confirmation`). `Resources/translations/` only contains `admin.*.yaml` and `validators.*.yml` (the `must_match` messages); no `messages.*` file in any CoreShop bundle defines these label keys — they rely on Pimcore shared translations that the project must create.
- **Problem:** the same concept uses different keys in registration (`coreshop.form.user.password.label`) and reset (`coreshop.form.user.password`), so a project has to translate both, and a fresh install shows raw keys in the reset / change forms. `ResetPasswordType` also has no `invalid_message`, so the mismatch error is Symfony's generic "The values do not match." while the other two forms use `coreshop.form.user.password.must_match`.
- **Fix:** use one key set for all password forms, ship `messages.{en,de}.yml` (or document the required shared translations), add `invalid_message: 'coreshop.form.user.password.must_match'` to `ResetPasswordType`.

## Password reset flow (dependent)

### B-07 — User enumeration via timing in the reset request — (plausible) (dependent)
- **Where:** `FrontendBundle/Controller/RegisterController.php:89-112`.
- **Problem:** the response text is the same for known and unknown identifiers (good), but only known identifiers trigger `$user->save()` (Pimcore save + version), the notification rule and mail sending in the same request. The response time difference makes enumeration easy. There is also no rate limit, so the endpoint can be used to flood a user's mailbox.
- **Fix:** move token generation + mail to a Messenger message (async) so both branches return equally fast; add `symfony/rate-limiter` (`RateLimiterFactory` per IP + per identifier) to the request action.

### B-08 — Reset token TTL is hard-coded and `findByResetToken()` (deprecated) bypasses it — (verified code path)
- **Where:** `Pimcore/Repository/UserRepository.php:27-41,43`, `Component/User/Repository/UserRepositoryInterface.php:26-39`, `FrontendBundle/Controller/RegisterController.php:132`.
- **Problem:** the 1-hour TTL can only be changed by editing the controller. `findByResetToken()` is still public API, compares the given value directly with the stored **hash** column and has no TTL: any caller that still uses it (custom controllers written against 4.0) finds users by the hash (which is stored data, not a secret the user ever sees) and never expires tokens.
- **Fix:** add a `core_shop_user.password_reset.ttl` config (parameter) used by the controller; remove `findByResetToken()` in the next major and add `#[\Deprecated]` (PHP 8.4) now so callers get a runtime deprecation.
