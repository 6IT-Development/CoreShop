# ConfigurationBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this bundle, but they come from how this bundle's model, repository and service are used, and they should be fixed together with it.
Paths are relative to the bundle root unless noted otherwise.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## B-01 — `data` is mapped as Doctrine `object` type: deprecated in DBAL 3.4, removed in DBAL 4, and decoded with unrestricted `unserialize()`

- **Where:** `Resources/config/doctrine/model/Configuration.orm.xml:12` (`<field name="data" column="data" type="object"/>`)
- **Problem:**
  - `ObjectType` triggers a deprecation on DBAL 3.x and does not exist in DBAL 4 (`Unknown column type "object"`). The bundle works today only because the stack pins DBAL 3 (root `doctrine/orm: ^2.9`; the `six-it/pimcore-source` 2.x line requires `doctrine/dbal: ^3.8`). Any move to ORM 3 / DBAL 4 breaks mapping load for the whole app, because the mapping is compiled for every request.
  - `ObjectType::convertToPHPValue()` calls `unserialize()` without `allowed_classes`. Anyone who can write the `coreshop_configuration.data` column (SQL injection elsewhere, a shared DB, an imported dump) gets PHP object injection.
  - The stored values are only scalars and arrays (see `CoreBundle/Fixtures/Data/Application/ConfigurationFixture.php`), so the PHP-serialisation format buys nothing.
- **Fix direction:** switch to `type="json"`, with a data migration: `unserialize()` with `['allowed_classes' => false]`, then `json_encode()` for each row. The same `object`/`array` types are used in RuleBundle, IndexBundle and PayumBundle mappings; track those in their own TODOs.

## B-02 — Values are HTML-escaped when they are written, so they become double-encoded on every admin save and corrupted for non-HTML consumers

- **Where:** `Controller/ConfigurationController.php:31`; **(dependent)** `CoreBundle/Controller/ConfigurationController.php:37`
- **Problem:** `array_htmlspecialchars()` (from `six-it/pimcore-source` `lib/helper-functions.php`, ENT_COMPAT) runs before `set()`, so the database holds `&amp;`, `&lt;`, `&quot;`.
  - `getAllAction()` returns the stored, already-escaped value, ExtJS shows it literally, and the next "Save" escapes it again: `a&b` → `a&amp;b` → `a&amp;amp;b` → … after each round trip.
  - Consumers that are not HTML get the entities: order and invoice number prefixes/suffixes, wkhtmltopdf options (`--footer-html "x"` is broken into `&quot;`), URLs with query strings, and any value compared in PHP.
  - It is also no real XSS protection (see C-01): escaping belongs at output time, in Twig auto-escape and ExtJS `Ext.util.Format.htmlEncode`.
- **Fix direction:** remove the escaping at write time. Validate or normalise per key instead. If old rows need it, decode them once with a migration (`html_entity_decode`). Escape when rendering.

## B-03 — The base `saveAllAction()` does not JSON-decode `values`, so it throws a `TypeError` for the payload the admin UI actually sends

- **Where:** `Controller/ConfigurationController.php:30-31`
- **Problem:** the admin client sends `values: Ext.encode(values)`, a JSON string (`CoreBundle/Resources/public/pimcore/js/settings.js:197-198`). The base action passes that string, or `null` when the parameter is missing, straight to `array_htmlspecialchars(array $array): array`. Under `declare(strict_types=1)` that is a `TypeError`, so the response is a 500. The CoreBundle override decodes the JSON (`decodeJson(...)`). The base implementation is only reachable when the bundle is used without CoreBundle, and then no route points to it anyway: the bundle's `routing.yml` defines only the generic resource routes. So the action is both broken and unreachable.
- **Fix direction:** either delete the base action, or make it correct: decode with `json_decode(..., flags: JSON_THROW_ON_ERROR)`, check that the result is an array, add a route, and add the permission check from C-01.

## B-04 — Numeric keys crash `set(string $key)` under `strict_types`

- **Where:** `Controller/ConfigurationController.php:33-35`; **(dependent)** `CoreBundle/Controller/ConfigurationController.php:45-47`
- **Problem:** PHP turns numeric-string array keys (`"1"`, `"2024"`) into `int`. With `declare(strict_types=1)`, `$configurationService->set($key, …)` / `setForStore($key, …)` then throws `TypeError: Argument #1 ($key) must be of type string, int given`. The values saved earlier in the loop are already flushed (B-06), so the result is a partial save plus a 500. A crafted payload can trigger this, and so can any future settings field whose name is numeric.
- **Fix direction:** cast with `(string) $key`, or better, reject keys that are not on an allowlist (see I-08).

## B-05 — `findByKey()` ignores the store: in a multi-store setup the inherited `get()`, `set()` and `remove()` return, overwrite or delete an arbitrary store's row, or throw

- **Where:** `Doctrine/ORM/ConfigurationRepository.php:27-35`; `Component/Configuration/Service/ConfigurationService.php:35-70`; **(dependent)** `Component/Core/Configuration/ConfigurationService.php` inherits these methods unchanged.
- **Problem:** with CoreBundle loaded, the table has a nullable `store_id` and the settings UI writes one row per store (`setForStore`). The non-store API is still used: `RequestCartRecalculation`, `RuleAvailabilityTask`, `ProductUpdateEventListener`, `PriceRuleUpdateEventListener`, the fixtures, and Behat `CategoryContext`/`ConfigurationContext`. For a key that has rows for more than one store:
  - `get()` throws `NonUniqueResultException` (same failure as C-02).
  - With exactly one store-specific row, `get()` returns that store's value as if it were global, and `set()` overwrites that store's row instead of creating a global one.
  - `remove()` deletes a store-specific row.
- **Fix direction:** the base repository should query `key` only, and the Core repository should override `findByKey()` to add `o.store IS NULL`. Alternatively, document that the base API is "global only" and enforce it.

## B-06 — `saveAll` flushes once per key with no transaction (partial saves), and every `set()` flushes the whole EntityManager

- **Where:** `Controller/ConfigurationController.php:33-35` → `Component/Configuration/Service/ConfigurationService.php:56-57` (also `remove()` `:67-68`); **(dependent)** `Component/Core/Configuration/ConfigurationService.php` `setForStore()`/`removeForStore()`
- **Problem:**
  - The settings form posts about 25 keys per store, which means about 25 `flush()` calls and about 25 implicit transactions. Any failure part-way, such as B-04, the 100-char limit in B-08, or a DB error, leaves the configuration half-saved, and the client only gets a 500.
  - `EntityManager::flush()` with no argument also writes any other entity that is pending in the same unit of work. Calling `set()` from inside a Doctrine or Pimcore event listener (as `ProductUpdateEventListener` and `PriceRuleUpdateEventListener` do) can therefore flush unrelated half-built entities, or re-enter flush.
- **Fix direction:** add `setMultiple(array $values)` / a `$flush = false` mode, and wrap the save-all loop in `$em->wrapInTransaction()` with a single flush.

## B-07 — **(dependent)** `getForStore()` / `setForStore()` crash without a store context, and `setForStore()` turns the global default row into a store row

- **Where:** `Component/Core/Configuration/ConfigurationService.php` (`getForStore`, `setForStore`, `getStore`); `CoreBundle/Doctrine/ORM/ConfigurationRepository.php:28` (`findForKeyAndStore(string $key, StoreInterface $store)`)
- **Problem:**
  1. `getStore()` returns `null` when `StoreNotFoundException` is thrown (CLI, maintenance, some admin requests). `findForKeyAndStore()` requires a non-null `StoreInterface`, so the call ends in a `TypeError` instead of the intended fallback to the global value.
  2. `setForStore()` calls `getForStore($key, $store, true)`, which falls back to the **global** row (`store = null`) when no store row exists. It then calls `setStore($store)` on that object and persists it. The first admin save for store A therefore moves the global default (created by the fixtures) to store A. Store B, and any store added later, loses its default and gets `null`.
  3. `CoreBundle/Controller/ConfigurationController.php:43`: `$storeRepository->find($store)` can return `null` for an unknown or deleted ID. `setForStore(..., null)` then writes into whatever store the admin request context resolves to.
- **Fix direction:** make `findForKeyAndStore` accept `?StoreInterface` (`IS NULL` branch), or skip the lookup when there is no store. In `setForStore`, look up only the exact `(key, store)` row (never the fallback). In the controller, skip unknown store IDs or return 400 for them.

## B-08 — The generic resource `add`/`save` uses a `TextType` for `data` and has no validation: arrays fail to transform, and long or empty keys cause DB errors

- **Where:** `Form/Type/ConfigurationType.php:27-33`; `Resources/config/services/form.yml:2` (validation group `coreshop`), and the bundle has no `Resources/config/validation/*.yml`.
- **Problem:**
  - Several stored values are arrays (`system.category.list.per_page => [12, 24, 36]`, …). Opening one of them through the generic `save` action makes `TextType`'s view transformer throw `TransformationFailedException` ("Expected a scalar"), which returns a 500.
  - There are no constraints: `key` can be empty or `null`, but the column is `NOT NULL`, and it can be longer than `length="100"`. Those cases fail as a DBAL exception (500) instead of a form error. On MySQL in non-strict mode the key is truncated silently.
- **Fix direction:** add `Resources/config/validation/Configuration.yml` with `key: [NotBlank, Length(max: 100)]` in group `coreshop`. Map `data` with a type that accepts scalars and arrays (a custom `ConfigurationDataType` with a JSON data transformer), or make `data` read-only in the generic form.

## B-09 — JMS serializer metadata: typo in `xml_root_name`, and the `object` type handler exists only for JSON, so XML serialisation fails

- **Where:** `Resources/config/serializer/Model.Configuration.yml:3` (`configuraiton`); `Resources/config/services.yml:15-17` (handler tag `format: json` only); `Serialization/ObjectHandler.php`
- **Problem:**
  - The XML root element is spelled `<configuraiton>`.
  - Without a handler for `xml`, JMS resolves `type: object` as a class name and fails ("Class object does not exist") whenever a `Configuration` is serialised to XML, for example through a REST/XML export or `?_format=xml`.
  - The handler is also registered for the global type name `object`, so it silently changes how **any** property declared `type: object` in **any** bundle's JMS metadata is serialised (see I-03).
- **Fix direction:** fix the spelling. Register the handler for `xml` too, or switch the metadata to a dedicated type name such as `coreshop_configuration_data`.

## B-10 — `composer.json` does not declare what the bundle actually uses, and its constraints are out of sync with the root

- **Where:** `composer.json:26-35`; the same in `Component/Configuration/composer.json:26-34`
- **Problem:**
  - Missing `php` constraint (root: `~8.4.0`).
  - Direct dependencies that are used but not declared: `doctrine/orm` (repository, mapping), `doctrine/doctrine-bundle` (the `doctrine.orm.default_entity_manager` service), `symfony/form`, `symfony/http-foundation`, `symfony/config`, `symfony/dependency-injection`, `jms/serializer-bundle` (handler and metadata), and `gedmo/doctrine-extensions` / `stof/doctrine-extensions-bundle`. The `gedmo:timestampable` mapping relies on the listener being enabled (enabled in `ResourceBundle/Resources/config/pimcore/config.yml:58`). Without it, `creationDate NOT NULL` makes every insert fail.
  - `six-it/pimcore-source: ^2.0`, while the root was just bumped to `^2.1` (commit `d670a7c6b`).
  - `require-dev` uses `phpstan/phpstan ^1.10`, `phpstan-doctrine ^1.3.40`, `phpstan-webmozart-assert ^1.2.4`, while the root uses `^2.x` of all three. When analysed standalone, phpstan 1.x does not fully support PHP 8.4 syntax.
- **Fix direction:** align the constraints with the root `composer.json`, and add the missing `require` entries.
