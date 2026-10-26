# Configuration Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this component, but they come from how its model, repository contract or service are extended or used, and they should be fixed together with it.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.
Items are roughly ordered by impact.

Already tracked in the ConfigurationBundle TODOs, and **not repeated here**: Doctrine `object` type / `unserialize()` (bundle B-01), write-time HTML escaping (B-02), base `saveAllAction` (B-03), numeric keys (B-04), `findByKey()` ignoring the store (B-05), flush per key / no transaction (B-06), `getForStore()`/`setForStore()` without a store and `setForStore()` moving the global row (B-07), generic form/validation (B-08), serializer (B-09), and most of `composer.json` (B-10). Fix those together with the component items they mention.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Multi-store (Core subclass)

### B-01 — (dependent) `removeForStore()` deletes the **global** default when the store has no row of its own
- **Where:** `Component/Core/Configuration/ConfigurationService.php:90-102` → `getForStore($key, $store, true)` (`:45-66`)
- **Problem:** `getForStore()` falls back to the global row (`store = null`) when no row exists for the given store. `removeForStore()` reuses it to find "the row to delete", so `removeForStore('system.order.prefix', $storeA)` removes the global default for **all** stores whenever store A only inherits it. Each later `getForStore()` then returns `null`, and, for example, `SaleNumberGenerator` (`Component/Core/Order/NumberGenerator/SaleNumberGenerator.php:52`) silently drops the prefix and suffix from every new order, quote, invoice and shipment number. This is the same root cause as ConfigurationBundle B-07 point 2 (`setForStore`), but the result is data loss rather than a moved row.
- **Fix direction:** add an exact lookup (`findForKeyAndStore()` / a new `findExact(string $key, ?StoreInterface $store)`) and use it in `setForStore()` and `removeForStore()`. Only reads (`getForStore()`) may fall back to the global row. Add unit tests for "store row exists", "only global row exists" and "none exists" for all three methods.

### B-02 — (dependent) The generic resource `add` action cannot create a configuration correctly and crashes on several request fields
- **Where:** `ResourceBundle/Controller/ResourceController.php:177-200` (`addAction`); `Model/Configuration.php` (no `setName()`); `Component/Resource/Model/SetValuesTrait.php:32-43`
- **Problem:** the `coreshop.configuration` resource exposes the generic `add` route (`ConfigurationBundle/Resources/config/pimcore/routing.yml`), but that action assumes a "named" resource:
  - A non-empty `name` parameter is required (`:181-185`), although the model has no name. `setValue('name', …)` is then a silent no-op.
  - Every request parameter goes through `setValue($key, $value)` with the raw string. `declare(strict_types=1)` is on in `SetValuesTrait`, so `creationDate=…` / `modificationDate=…` → `setCreationDate(\DateTime)` throws `TypeError` (500). With the Core model, `store=1` → `setStore(?StoreInterface)` does the same.
  - `data` is always stored as a string, even for keys whose consumers expect an int or an array (`system.category.list.per_page` → `CategoryController.php:113` passes it on as the allowed page sizes).
  - It creates a new row even when the key exists (see TODO-1 **C-01**).
- **Fix direction:** disable `add`/`clone` for this resource (route `except`), or give the configuration resource its own `add` that calls `ConfigurationServiceInterface::set()` with validated and cast input (see TODO-3 **I-04**, **I-08**).

### B-03 — (dependent) `SetValuesTrait::setValue()` calls the wrong method in its `o_` compatibility branch, so any `o_`-prefixed key throws `Error`
- **Where:** `Component/Resource/Model/SetValuesTrait.php:37-39` (used by `Model/Configuration.php:30`)
- **Problem:** the `elseif` branch checks for `'set' . preg_replace('/^o_/', '', $key)` but then calls `$this->$method($value)`, and `$method` is still built from the full key, `'set' . $key` (for example `seto_key`). So `setValue('o_key', 'x')` always ends in `Error: Call to undefined method …::seto_key()` (500), which is the opposite of what the compatibility branch is for. It is reachable through `ResourceController::addAction()` (B-02) by sending an `o_key` parameter, and the same goes for every other resource model that uses the trait.
- **Fix direction:** compute the stripped method name once and call that: `$method = 'set' . preg_replace('/^o_/', '', $key);`. Better, drop the Pimcore 10 `o_` compatibility altogether (see TODO-3 **I-14**). Track it in the Resource component TODO as well.

### B-04 — (dependent) The admin "get all" view merges store and global rows in an undefined order, so the global value can hide the store value and then overwrite it on the next save
- **Where:** `CoreBundle/Controller/ConfigurationController.php:53-76` (`getAllAction`, `findBy(['store' => [$store, null]])` at `:64`, then `$storeValues[$c->getKey()] = $c->getData()`)
- **Problem:** for each store, the global row and the store row for the same key both come back in one list with no `ORDER BY`. The last one wins. Today it usually works because fixtures create the global rows first (lower IDs, and InnoDB tends to return them in primary-key order), but nothing guarantees that order. When a global row is created **after** the store rows (for example by `set()` in a listener, a fixture reload, or a migration), the settings UI shows the global value for that store. The next "Save" then writes that global value into the store row (`setForStore()`), which silently loses the store-specific setting.
- **Fix direction:** read globals and store rows separately and merge them explicitly (`array_replace($global, $store)`), or order by `store` with `NULLS FIRST` semantics. Better, add a component-level method `ConfigurationServiceInterface::all(?StoreInterface $store): array<string, mixed>` with defined precedence (see TODO-3 **I-03**), and use it in the controller.

## Key handling

### B-05 — Key lookups depend on the database collation: keys that differ only in case or trailing spaces resolve to the same row
- **Where:** `Repository/ConfigurationRepositoryInterface.php:26`; `Service/ConfigurationService.php:35-70`; **(dependent)** `ConfigurationBundle/Doctrine/ORM/ConfigurationRepository.php:27-35` (`o.key = :key`) and the mapping (`configKey`, `string(100)` with the table's default collation)
- **Problem:** the component treats keys as exact, case-sensitive strings (PHP side). Pimcore's default MySQL/MariaDB collations (`utf8mb4_general_ci`, `utf8mb4_0900_ai_ci`, `utf8mb4_unicode_520_ci`) are case-insensitive and accent-insensitive, and some of them also ignore trailing spaces. As a result:
  - `get('system.price_rule.update')` returns the `SYSTEM.PRICE_RULE.UPDATE` row, and `set('System.Guest.Checkout', …)` overwrites `system.guest.checkout` while keeping the old casing in the database. Code that writes a key cannot be sure what another casing will return.
  - Once the unique constraint from TODO-1 **C-01** exists, keys that differ only in case collide. The current code base already mixes conventions (`SYSTEM.PRICE_RULE.UPDATE` vs `system.*`), so the migration must check for this case.
  - Keys longer than 100 characters are a DBAL error in strict SQL mode, and are silently truncated in non-strict mode, where they then match a different key.
- **Fix direction:** define the key format in the component (for example `/^[a-z0-9_.-]{1,100}$/`) and validate or normalise it in `ConfigurationService` (lowercase). Rename `SYSTEM.PRICE_RULE.UPDATE` in a migration. Alternatively, map `configKey` with a binary or `_bin` collation (`options: collation`). See TODO-3 **I-08**.

## Contract and typing

### B-06 — `set()` trusts the factory's `mixed` return value, so a misconfigured model class fails after the row is half-written
- **Where:** `Service/ConfigurationService.php:51-59`; **(dependent)** `Component/Core/Configuration/ConfigurationService.php:76-87`; `Component/Resource/Factory/FactoryInterface.php` (`createNew()` documented as `@return mixed`)
- **Problem:** `$this->configurationFactory->createNew()` is used as a `ConfigurationInterface` without a check. If `coreshop_configuration.resources.configuration.classes.model` is overridden with a class that does not implement the interface (for example an app model that forgets `StoreAwareInterface` when CoreBundle is on), the failure happens late: `setKey()`/`setStore()` raise `Error: Call to undefined method`, or the `ConfigurationInterface` return type raises `TypeError` **after** `persist()` + `flush()`. In that last case the row is already in the database, and the next `set()` sees it. Static analysis cannot see the problem, because the factory is untyped.
- **Fix direction:** assert the type right after creation (`if (!$config instanceof ConfigurationInterface) { throw new \UnexpectedValueException(...); }` or `Webmozart\Assert\Assert::isInstanceOf()`). Add `@template` generics to `FactoryInterface` (`@return T`) and type the constructor parameter as `FactoryInterface<ConfigurationInterface>`. Validate the model class at container compile time in the bundle's resource registration.

### B-07 — `getKey()` and the timestamps are documented as non-null, but they are `null` until the entity is set up and flushed
- **Where:** `Model/ConfigurationInterface.php:28-31` (`@return string`), `Model/Configuration.php:37-58`; **(dependent)** `Component/Resource/Model/TimestampableInterface.php` / `TimestampableTrait.php` (`@return \DateTime`)
- **Problem:** a new `Configuration` returns `null` from `getKey()`, `getCreationDate()` and `getModificationDate()` until `setKey()` is called and Gedmo fills the dates on flush (`modificationDate` is even `nullable="true"` in the mapping). The docblocks say otherwise, so phpstan/psalm treat `null` checks as dead code, and callers that trust the docs crash. For example, `$c->getModificationDate()->getTimestamp()` on a configuration created in the same request throws `Error: Call to a member function getTimestamp() on null`, and `strlen($c->getKey())` is a `TypeError` under strict types.
- **Fix direction:** document and type the getters as nullable (`?string`, `?\DateTimeInterface`) as part of TODO-3 **I-01**. Alternatively, require the key in a constructor and initialise `creationDate` there. Note that the Resource factory instantiates models with no arguments, so a constructor key needs a named factory method.

### B-08 — `get()` cannot tell "key missing" apart from "key stored with value `null`"
- **Where:** `Service/ConfigurationService.php:35-44`, `Service/ConfigurationServiceInterface.php:25-28`; **(dependent)** `Component/Core/Configuration/ConfigurationService.php:45-66`, consumers such as `CoreBundle/Maintenance/RuleAvailabilityTask.php:35-39` and `FrontendBundle/Controller/CategoryController.php:110-118`
- **Problem:** `set('k', null)` stores a row whose `data` is `null`, and `get('k')` then returns `null`, which is exactly what it returns for a missing key. Consequences:
  - In the Core subclass, a store row with `data = null` "wins" over the global row (`findForKeyAndStore()` returns it), so the store gets `null` instead of inheriting the global value. There is no way to "clear" a store override except deleting the row, and `removeForStore()` may delete the global row instead (B-01).
  - Callers use `null` as "not configured" (`RuleAvailabilityTask`: `null === $lastMaintenance` → run now). A stored `null` therefore behaves like "never ran", every maintenance run.
- **Fix direction:** decide the semantics in the component and document them. Either `set($key, null)` means `remove($key)` (simplest, and it restores inheritance per store), or keep a stored `null` and add `has(string $key): bool` plus a `$default` parameter (see TODO-3 **I-03**). Apply the same rule in `setForStore()`.

## Packaging

### B-09 — `composer.json` requires a package the component does not use, and does not declare what it does use
- **Where:** `composer.json:26-34`
- **Problem** (component-specific additions to ConfigurationBundle **B-10**):
  - `six-it/pimcore-source: ^2.0` is required, but nothing in this component references Pimcore. Installing the component on its own pulls in the whole Pimcore platform for a three-class key/value store. The root `composer.json` is already on `^2.1`, so the constraint is also stale.
  - `Service/ConfigurationService.php:24` imports `Doctrine\ORM\EntityManagerInterface`, but neither `doctrine/orm` nor `doctrine/persistence` is required here. It only resolves because `coreshop/resource` requires `doctrine/orm`.
  - There is no `php` constraint (root: `~8.4.0`). Once TODO-3 **I-01** introduces PHP 8.4 syntax (asymmetric visibility, property hooks), installing on 8.3 must fail at Composer time, not with a parse error.
  - `require-dev` pins `phpstan/phpstan ^1.10` (and the `^1.x` extensions). PHPStan 1.x cannot parse PHP 8.4 asymmetric visibility or property hooks, so the package-level analysis (`phpstan-package.neon`) breaks as soon as I-01 lands. The root uses `^2.1`.
- **Fix direction:** drop `six-it/pimcore-source`; require `php: ~8.4.0` and `doctrine/persistence` (or `doctrine/orm` if the ORM interface stays, see TODO-3 **I-05**); bump the phpstan packages to `^2.x` in line with the root.
