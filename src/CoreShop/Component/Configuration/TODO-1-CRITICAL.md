# Configuration Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: all PHP in this component (`Model/*`, `Repository/*`, `Service/*`), `composer.json`, `README.md`, and the code it depends on or that builds on it:
`Component/Resource/Model/{ResourceInterface,TimestampableInterface,TimestampableTrait,SetValuesTrait}.php`, `Component/Resource/{Factory/FactoryInterface,Repository/RepositoryInterface}.php`, `Component/Core/Configuration/ConfigurationService{,Interface}.php`, `Component/Core/Model/Configuration{,Interface}.php`, `Component/Core/Repository/ConfigurationRepositoryInterface.php`, `ConfigurationBundle/Doctrine/ORM/ConfigurationRepository.php`, `ConfigurationBundle/Resources/config/{services.yml,doctrine/model/Configuration.orm.xml}`, `CoreBundle/Doctrine/ORM/ConfigurationRepository.php`, `CoreBundle/Resources/config/{services.yml,doctrine/model/Configuration.orm.xml}`, `CoreBundle/Controller/ConfigurationController.php`, `ResourceBundle/Controller/ResourceController.php` (generic `add`), and the consumers of the service (`CoreBundle/EventListener/{RequestCartRecalculation,ProductUpdateEventListener,PriceRuleUpdateEventListener}.php`, `CoreBundle/Maintenance/RuleAvailabilityTask.php`, `CoreBundle/Twig/ConfigurationExtension.php`, `CoreBundle/Fixtures/Data/Application/ConfigurationFixture.php`, `Component/Core/Order/NumberGenerator/SaleNumberGenerator.php`, `FrontendBundle/Controller/CategoryController.php`, Behat `ConfigurationContext`/`CategoryContext`).

"Critical" here means: a security issue, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers in this component. All files pass `php -l` on PHP 8.4.27. There are no implicit nullable parameters, no removed functions, and no Symfony APIs at all (the component is framework-agnostic). The only stack risk is the Doctrine `object` column type used by the bundle mapping for `data` (removed in DBAL 4), tracked as ConfigurationBundle TODO-2 **B-01**. Modernisation items are in TODO-3.

**Overlap with ConfigurationBundle TODOs:** the bundle TODOs (`ConfigurationBundle/TODO-*.md`) already cover the mapping, repository, controller and serializer side. Items here focus on the component's own contract (model, repository interface, service) and on the Core subclass that extends it. Where an item shares a root cause with a bundle item, it says so, and both should be fixed together.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## C-01 — The component's write API cannot keep keys unique, and one duplicate row turns into a site-wide 500 on frontend requests

- **Where:**
  - `Service/ConfigurationService.php:46-60`: `set()` is read-then-insert (`get($key, true)` → `createNew()` → `persist()` → `flush()`), with no lock, no transaction and no handling of a unique-constraint violation.
  - `Repository/ConfigurationRepositoryInterface.php:26`: `findByKey(string $key): ?ConfigurationInterface` promises "zero or one". Nothing in the component (or in the mapping, see below) guarantees it.
  - **(dependent)** `ResourceBundle/Controller/ResourceController.php:177-200` (`addAction`): the generic `coreshop.configuration` resource `add` route always calls `$this->factory->createNew()` and copies every request parameter onto it through `setValue()`. It never checks whether the key exists, so a single admin "add" with `key=SYSTEM.PRICE_RULE.UPDATE` creates a second row on its own, with no race needed. (Its other problems are TODO-2 **B-02**.)
  - **(dependent)** `ConfigurationBundle/Resources/config/doctrine/model/Configuration.orm.xml:11`: no unique constraint or index on `configKey`; `ConfigurationBundle/Doctrine/ORM/ConfigurationRepository.php:27-35`: `getOneOrNullResult()` without `setMaxResults(1)`.
- **Problem:**
  - `CoreBundle/EventListener/ProductUpdateEventListener.php:40` and `PriceRuleUpdateEventListener.php:46` call `set('SYSTEM.PRICE_RULE.UPDATE', time())` on **every** purchasable save. Bulk imports, parallel Messenger workers, or two editors saving at the same time all run `set()` concurrently for that key. If the row does not exist yet (fresh install, or after a `remove()`), both processes see `null` and both insert.
  - From then on `findByKey()` throws `Doctrine\ORM\NonUniqueResultException`. `CoreBundle/EventListener/RequestCartRecalculation.php:61` calls `get('SYSTEM.PRICE_RULE.UPDATE')` on every main frontend request with a persisted cart, so every shopper with a cart gets a 500 until someone deletes the extra row by hand. `RuleAvailabilityTask` (`get('system.rule.availability_check.last_run')`) fails on every maintenance run in the same way, and every later `set()`/`remove()` for that key throws too, so the application cannot repair itself.
  - The same failure is reachable through the admin `add` action alone (see above), so any backend user who can reach the resource routes can trigger it. (Missing permission check: ConfigurationBundle TODO-1 **C-01**.)
- **Fix direction:**
  - Component: document on `ConfigurationRepositoryInterface::findByKey()` and `ConfigurationServiceInterface::set()` that keys are unique (per store in Core). Make `set()` race-safe: wrap it in `wrapInTransaction()`, catch `Doctrine\DBAL\Exception\UniqueConstraintViolationException`, then reset the manager or registry, re-read, and update. Alternatively, have the repository expose an `upsert(string $key, mixed $data)` that issues `INSERT … ON DUPLICATE KEY UPDATE`.
  - Mapping and repository: add the unique constraint (`configKey`, or `(configKey, store_id)` with the Core mapping), plus a cleanup migration for existing duplicates. Make `findByKey()` deterministic. Both are tracked as ConfigurationBundle TODO-1 **C-02**; fix them together with this item.
  - Generic `add`: route configuration creation through `ConfigurationServiceInterface::set()` (upsert semantics), or disable the `add`/`clone` actions for this resource.
  - Add a regression test: two `set()` calls for a new key from two connections must leave exactly one row.
