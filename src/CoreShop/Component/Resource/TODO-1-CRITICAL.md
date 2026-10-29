# Resource Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope (scan 1): `DataHub/**` (15 files), `Doctrine/Type/*` (5 files), `Exception/*` (4 files), `composer.json`. Dependent config and callers that were followed: `Bundle/ResourceBundle/{Resources/config/services/data_hub.yml,Resources/config/pimcore/config.yml,DependencyInjection/{Configuration,CoreShopResourceExtension}.php}`, the `services/data_hub.yml` of `CoreBundle`, `TaxationBundle`, `StoreBundle`, `OrderBundle`, `PaymentBundle` (the users of `DataHub/QueryType/Resource(List)`), the `graphql.enabled` nodes in the bundles' `DependencyInjection/Configuration.php`, the Doctrine mappings that use the custom DBAL types (`CoreBundle/.../{ProductStoreValues,QuantityRange,Payment}.orm.xml`, `PaymentBundle/.../PaymentProvider.orm.xml`, `ShippingBundle/.../Carrier.orm.xml`), `CoreBundle/Doctrine/ORM/ProductStoreValuesRepository.php`, `PaymentBundle/Doctrine/ORM/PaymentRepository.php`, every caller of the four exceptions, `vendor/six-it/pimcore-source/models/Element/ValidationException.php`, `vendor/doctrine/{dbal,orm}`.

Other folders of this component (`Factory`, `Metadata`, `Model`, `Pimcore`, `Reflection`, `Repository`, `Service`, `Storage`, `TokenGenerator`, `Translation`) are **not** part of this scan; add them as "Scan 2" sections later and continue the numbering.

"Critical" here means: a security hole, data loss, or an error that takes down a whole area (the DataHub endpoint, the container build) once it is triggered, or a blocker for Symfony 7.4 / PHP 8.4.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4.x, doctrine/dbal 3.10.6, doctrine/orm 2.20.13, doctrine/persistence 3.4.5, six-it/pimcore-source 2.x, PHP 8.4.27). Component items use the prefixes `CC-` (critical), `CB-` (bugs) and `CI-` (improvements). ResourceBundle items are referenced as ResourceBundle **C-/B-/I-nn**.
Markers: **(dependent)** = root cause or part of the fix is outside this component; **(reproduced)** = run with PHP 8.4.27; **(verified)** = confirmed by reading the code / vendored library source; **(plausible)** = follows from the code, but the trigger depends on data, configuration or deployment.

**Important context for every DataHub item:** `pimcore/data-hub` and `webonyx/graphql-php` are **not installed** in the reference project, so the `DataHub/**` classes cannot even be autoloaded there and nothing in DataHub could be executed; all DataHub items are from reading the code against the webonyx 15 / data-hub APIs. `DataHub/**` is only loaded when `PimcoreDataHubBundle` is registered (`CoreShopResourceExtension.php:68-69`). The vendored copy (`vendor/six-it/core-shop/src/CoreShop/Component/Resource/{DataHub,Doctrine,Exception}`) is identical to this repo.

**No Symfony 7.4 / PHP 8.4 language blocker was found.** All 24 PHP files pass `php -l` on 8.4.27 with `error_reporting=-1`; no implicitly nullable parameters, no removed functions. One PHP 8.2+ deprecation (dynamic property) is in TODO-2 **CB-14**. Doctrine ORM 3 / DBAL 4 are not blockers for Symfony 7.4 (DoctrineBundle 2.19 still runs ORM 2.20 + DBAL 3.10) — forward-compatibility work is in TODO-3 **CI-08**, **CI-09**.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

# Scan 1 (2026-10-29): `DataHub/`, `Doctrine/`, `Exception/`

## Security – DQL injection in the generic list query

The resolver built by `DataHub/DoctrineProvider.php::getGraphQlQuery()` (`:418-583`) is public API of the `coreshop.data_hub.doctrine_provider` service (`public: true`, `ResourceBundle/Resources/config/services/data_hub.yml:2-7`). **No CoreShop bundle calls `getGraphQlQueries()` / `getGraphQlQuery()`** (grep over `src/`), so out of the box the resolver is not mounted in a DataHub schema. Any project that wires it (which is the only reason the method exists) is exposed to CC-01 and CC-02.

### CC-01 — `after` cursor is concatenated into DQL: blind DQL injection over every Doctrine entity (incl. payment gateway configuration) — (verified) (dependent: reachable once a project exposes `getGraphQlQueries()`)
- **Where:**
  - `DataHub/GraphPageInfo.php:96-102`: `paginateQuery()` passes the client-supplied `after` arg to `addAfter()`.
  - `DataHub/GraphPageInfo.php:137-144`: `addAfter()` does `explode(':', base64_decode($values))` with no validation.
  - `DataHub/GraphPageInfo.php:146-158`: `generateQuery()` builds `"e.<id> > '" . $values[0] . "'"` (and the composite-key variant) by string concatenation and passes it to `$queryBuilder->andWhere()`.
- **Effect:** `base64("0' OR 1=1 OR '0")` disables every other filter (reproduced: the cursor decodes to `1' OR 1=1 OR '1` unchanged). DQL accepts sub-selects in `WHERE`, so `0' OR EXISTS(SELECT g FROM Payum\...\GatewayConfig g WHERE g.config LIKE '%sk_live_a%') OR '0` is a boolean oracle against **any** mapped Doctrine entity: payment gateway credentials (`PaymentProvider` / gateway config), payments, store/tax configuration. Malformed cursors also raise `QueryException` → HTTP 500 with the DQL in the message in debug mode. `base64_decode()` without `strict: true` accepts garbage.
- **Fix:**
  - Encode the cursor as `base64(json_encode([id values]))`, decode with `base64_decode($v, true)` + `json_decode(..., flags: JSON_THROW_ON_ERROR)`, check `count($values) === count($identifiers)` and the scalar type of each value; reject otherwise with a GraphQL `UserError`.
  - Build the keyset condition with **bound parameters** (`$qb->expr()->gt('e.' . $id, ':__after_' . $i)` + `setParameter()`), never with string literals.
  - Identifier names come from Doctrine metadata (safe), but assert them anyway (`Assert::regex($id, '/^\w+$/')`).
  - Add a unit test that feeds a quote-containing cursor and asserts a validation error.

### CC-02 — `sort.field` / `sort.order` are concatenated into `ORDER BY`; the sanitised direction is computed and then ignored — (verified) (dependent: same reachability as CC-01)
- **Where:** `DataHub/GraphPageInfo.php:107-135`. `$sortDirection` is normalised to `asc|desc` (`:114-118`) but `:120` passes the raw `$sortField['order']` and `'e.' . $sortField['field']` to `addOrderBy()`. `GraphSortField::getType()` (`DataHub/GraphSortField.php:45-58`) types both as free `String`.
- **Effect:** `ORDER BY` is DQL injection (`field: "id, (SELECT ...)"`, `order: "ASC, e.secret"`), unknown field names produce `QueryException` → 500 with DQL in the message; missing `field` produces `ORDER BY e.` (`:120` reads `$sortField['field']` without default → warning + broken DQL).
- **Fix:**
  - Expose per resource a GraphQL `EnumType` of sortable field names (built from `ClassMetadata::getFieldNames()`), and a shared `SortOrder` enum (`ASC`/`DESC`, backed by a PHP 8.4 native `enum SortOrder: string`). webonyx validates both before the resolver runs.
  - In `sortQuery()` use the validated enum value only; delete the unused `$sortDirection` dance.
  - Always append the identifier(s) as the last sort key so that keyset pagination (CC-01) stays deterministic.

## DataHub endpoint taken down by configuration

### CC-03 — Disabling GraphQL for any CoreShop resource (`graphql.enabled: false`) breaks the whole DataHub schema — (verified code path) (plausible outcome: data-hub not installed for reproduction) (dependent)
- **Where:**
  - Every resource node has `->arrayNode('graphql')->...->booleanNode('enabled')->defaultTrue()` (e.g. `CoreBundle/DependencyInjection/Configuration.php:63-68`, `StoreBundle/.../Configuration.php:61`, `TaxationBundle/.../Configuration.php:87`, `OrderBundle/.../Configuration.php:120,141`, `PaymentBundle/.../Configuration.php:82,103,159`, `ResourceBundle/.../Configuration.php:85`).
  - `DataHub/DoctrineProvider.php:94-116` only builds types for resources with `enabled = true`.
  - But the DataHub field generators are registered **unconditionally** for fixed classes: `CoreBundle/Resources/config/services/data_hub.yml` (`product_store_values`), `TaxationBundle` (`tax_rule_group`), `StoreBundle` (`store`, multiselect), `OrderBundle` (`cart_price_rule`), `PaymentBundle` (`payment_provider`), ….
  - `DataHub/QueryType/Resource.php:58-61` / `ResourceList.php:27-30` → `DoctrineProvider::getGraphQlType()` (`:395-398`) → `getNiceName()` returns `null` (`:336-339`) → `getType(null)` returns `null`.
  - `getGraphQlQueries()` (`:400-416`) has the same problem: it iterates **all** Doctrine resources, ignoring the `graphql.enabled` flag, and dereferences `$graphType->name` on `null` (`:421`).
- **Effect:** the field config gets `'type' => null` (or `Type::listOf(null)` → `TypeError` in webonyx 15). Schema construction for **every** DataHub endpoint that contains a Pimcore class with one of these CoreShop field types fails, i.e. one config switch meant to hide one resource takes all GraphQL APIs offline. The same happens if a project replaces a model class (`classes.model`) with one that is not graphql-enabled.
- **Fix (component side):**
  - `getGraphQlType()` must return `?Type` and throw a descriptive `\LogicException` (or return a placeholder `Type::id()` / `Type::int()` that exposes the raw id) when the class is not exposed; never return `null` into a field config.
  - `getGraphQlQueries()` must skip resources whose `graphql.enabled` is false (reuse the filter from `initialize()`, extracted into one method — TODO-3 **CI-16**).
- **Fix (dependent):** register the bundle `QueryType` services only when the corresponding resource has `graphql.enabled = true` (in each extension, or via one compiler pass in ResourceBundle that removes the tagged services for disabled resources).
