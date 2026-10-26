# IndexBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: all PHP in this bundle (`Attribute/**`, `Command/**`, `Condition/**`, `Controller/**`, `CoreExtension/**`, `DependencyInjection/**`, `EventListener/**`, `Extension/**`, `Factory/**`, `Form/**`, `Menu/**`, `Messenger/**`, `Migrations/**`, `Order/**`, `ProcessManager/**`, `Worker/**`, `Installer.php`, bundle class), `Resources/config/**` (services, pimcore config/admin/messenger/routing, doctrine mappings, serializer, validation) and `composer.json`. Admin JS under `Resources/public/pimcore/js` was only checked where PHP sends data to it or reads data from it. Also checked: the code this bundle depends on or that builds on it:
`Component/Index/{Condition/*,Order/SimpleOrder.php,Interpreter/ExpressionInterpreter.php,Filter/{Range,Search}*Processor.php,Worker/FilterGroupHelper.php,Service/IndexUpdaterService.php,Model/*,Getter/*}`, `Component/Pimcore/BatchProcessing/DataObjectBatchListing.php`, `Bundle/ResourceBundle/{Controller/{ResourceController,AdminController}.php,DependencyInjection/Driver/**,Command/InstallResourcesCommand.php}`, `Bundle/PimcoreBundle/DependencyInjection/Compiler/RegisterTypeHintRegistriesPass.php`, `Bundle/FrontendBundle/Controller/CategoryController.php` (the main consumer of the listings).

Reference vendor for API checks: `../pimcore2teszt10211524/vendor` (symfony/* 7.4.21, doctrine/dbal 3.10.6, doctrine/migrations 3.9.8, doctrine/doctrine-bundle 2.19.1, opensearch-project/opensearch-php 2.3.1, nesbot/carbon 3.15.0, jms/serializer-bundle 5.5.2), plus `../pimcore-source` (requires `doctrine/dbal ^3.8`, `nesbot/carbon ^3.0`).

"Critical" here means: a security issue that gives more power than the user's role should allow, data loss, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Items marked **(dependent)** live outside this bundle, but they come from how this bundle uses them, and they should be fixed together with it.

**Symfony 7.4 / PHP 8.4 compatibility:** `php -l` (PHP 8.4.27) passes on every PHP file in the bundle. There are no implicit nullable parameters. Controller argument aliases (`RepositoryInterface $indexRepository`, `ServiceRegistry $indexWorkersRegistry`) resolve through `registerAliasForArgument()` in ResourceBundle/PimcoreBundle. The one hard runtime blocker is the Carbon 3 API removal in C-03. DBAL 4 is **not** a blocker today (`pimcore-source` pins `doctrine/dbal ^3.8`), but the bundle uses several APIs that are deprecated in 3.x and removed in 4.0. They are listed in TODO-3 I-17.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## C-01 — OpenSearch: every save of an index in the admin deletes the whole OpenSearch index (all documents), and renaming an index makes the save fail

- **Where:**
  - `Worker/OpenSearchWorker.php:72-115`: `createOrUpdateIndexStructures()` runs `if ($client->indices()->exists(...)) { $this->deleteIndexStructures($index); }` and then `indices()->create(...)`.
  - `EventListener/CreateIndexListener.php:46` + `Resources/config/services/listeners.yml:5-9`: this runs on `coreshop.index.post_save`, so on **every** admin save of an index, even when only the label of a column changed or nothing changed.
  - `Worker/OpenSearchWorker.php:136-158`: `renameIndexStructures()` calls `indices()->clone()` on the live index. It is called from `EventListener/UpdateIndexListener.php:53`, a Doctrine `preUpdate` listener, so it runs inside `flush()`.
  - `Worker/OpenSearchWorker.php:244-255`: `getIndexName()` slugs the name, so `"Shoes 2"` and `"shoes-2"` map to the same OpenSearch index.
- **Problem:**
  - After any save, the OpenSearch index is empty until someone runs `coreshop:index` (or until every product is saved again). All category and filter pages that use this index show **no products** in production. Nothing tells the admin that a full reindex is now needed.
  - The OpenSearch `_clone` API only works when the source index is write-blocked (`index.blocks.write: true`). The live index is not, so `clone` throws `BadRequest400Exception`. Because this happens in `preUpdate`, the exception aborts the Doctrine flush, so **renaming an OpenSearch index in the admin is impossible** (500). If the clone did work, C-01 would delete the clone again right afterwards in `post_save`.
  - Two indices whose names slug to the same value share one OpenSearch index. Saving or deleting one wipes the other.
- **Fix direction:**
  - Use versioned physical indices behind an alias: `coreshop_index_os_<slug>` becomes an **alias**, and the physical index is `…_<timestamp>`.
  - `createOrUpdateIndexStructures()`: if the alias does not exist, create the physical index and the alias. If the mapping change is only additive, call `indices()->putMapping()`. Otherwise create a new physical index, `_reindex` into it (or tell the admin to run `coreshop:index <name>`), swap the alias atomically, and delete the old index.
  - A rename then only adds or moves the alias. Do it after the commit (see TODO-2 B-37), not in `preUpdate`.
  - Make the slug unique, for example by adding the index id: `coreshop_index_os_<id>_<slug>`. Plan a migration for existing installations.

## C-02 — The `expression` interpreter gets the whole service container, so any admin with the `index` permission can run arbitrary service calls (SQL, filesystem, mail, …) in the indexer

- **Where:**
  - `Resources/config/services.yml:220-225`: `ExpressionInterpreter` gets `'@coreshop.expression_language'` and `'@service_container'`.
  - (dependent) `Component/Index/Interpreter/ExpressionInterpreter.php`: `evaluate($expression, ['value' => …, 'object' => …, 'config' => …, 'container' => $this->container])`.
  - `Form/Type/Interpreter/ExpressionInterpreterFormType.php`: the only check on the expression is `NotBlank`.
  - The expression runs on every index update: `IndexMessageHandler` (messenger worker), `coreshop:index`, and the admin save of an object.
- **Problem:** Symfony ExpressionLanguage allows method calls on the objects it is given. An expression such as `container.get('doctrine.dbal.default_connection').executeStatement('UPDATE users SET admin=1 …')` or `container.get('kernel')…` runs with the full rights of the application. The only gate is `coreshop_permission_index`, a shop-configuration permission that is often given to merchandisers, not to developers. The result is privilege escalation to full database and application control. The code runs asynchronously in the worker, so it does not even show up in the admin request log.
- **Fix direction:**
  - Remove `container` from the expression variables, and remove `'@service_container'` from the service definition.
  - If projects need services, expose a small, explicit set of functions through an `ExpressionFunctionProviderInterface`, registered by tag.
  - Validate the expression when the index is saved: call `ExpressionLanguage::lint($expr, ['value', 'object', 'config'])` in a constraint on `ExpressionInterpreterFormType`.
  - Add an UPGRADE note, because existing expressions that use `container` will break.

## C-03 — With ProcessManagerBundle installed, `coreshop:index` always crashes: `Carbon::formatLocalized()` no longer exists in Carbon 3

- **Where:**
  - `ProcessManager/IndexListener.php:45`: `$date->formatLocalized('%A %d %B %Y')`.
  - `Resources/config/services/process_manager.yml:2-11`: the listener runs on `coreshop.index.classes`.
  - `Command/IndexCommand.php`: the command dispatches `classes` (`dispatchInfo('classes', …)`) before it indexes anything.
  - `../pimcore-source/composer.json`: `"nesbot/carbon": "^3.0"`. The reference vendor has 3.15.0, and its `src/` contains no `formatLocalized` (removed together with PHP's deprecated `strftime`).
- **Problem:** Carbon's `__call` throws `BadMethodCallException: Method formatLocalized does not exist.` The listener is registered whenever `ProcessManagerBundle` is in `kernel.bundles` (`CoreShopIndexExtension.php` loads `process_manager.yml` in that case). So **every** run of `coreshop:index` aborts before it indexes a single object. That includes the `coreshop_index` process started from the Process Manager UI (`ProcessManager/IndexProcess.php`) and every cron job that rebuilds the index. The index is never rebuilt, and combined with C-01 it stays empty.
- **Fix direction:**
  - Replace the call with `$date->isoFormat('dddd DD MMMM YYYY')`, or `$date->format('l d F Y')`, or `IntlDateFormatter` if the name must be localised.
  - Add a smoke test that dispatches the `coreshop.index.*` events into `IndexListener` with a mocked factory and logger.

## C-04 — OpenSearch: every filtered listing fails (HTTP 400 from OpenSearch, so a 500 in the shop) as soon as the index contains one `text` column

- **Where:**
  - `Worker/OpenSearchWorker/Listing.php:304-347`: `doLoadGroupByValues()` builds **one** search request with a `terms` aggregation for **every** index column (`foreach ($columns as $column) { $aggregations[$columnName] = ['terms' => ['field' => $columnName, 'order' => ['_term' => 'asc']]]; }`).
  - It is reached from every filter condition processor that calls `getGroupByValues()` / `getGroupByRelationValues()` (select, multiselect, relational, category, boolean) in `prepareValuesForRendering()`. That happens through `FilterProcessor::prepareConditionsForRendering()` in (dependent) `FrontendBundle/Controller/CategoryController.php:170`.
  - `Worker/OpenSearchWorker.php:351-370`: columns are mapped with the type chosen in the admin. `text` is one of the offered types (`Resources/config/pimcore/config.yml:40`).
- **Problem:**
  - A `text` field has `fielddata` disabled by default. OpenSearch rejects the aggregation with `illegal_argument_exception: Text fields are not optimised for operations that require per-document field data like aggregations…`. That is a shard failure, so the whole request fails with 400, `BadRequest400Exception` bubbles up, and **every category page with a filter returns 500**. A single `description` column of type `text`, which is the normal reason to use OpenSearch at all, is enough.
  - The same request also asks for a `terms` aggregation on localized columns (mapped as `object` with per-locale sub-fields). Those return empty buckets, so localized filters silently show no values.
- **Fix direction:**
  - Aggregate only the fields that are actually requested, lazily per `getGroupBy*Values($fieldName)` call, or batch the fields requested by the active filter conditions.
  - Aggregate only on aggregatable types (`keyword`, numeric, `boolean`, `date`). For `text` columns, add a `fields: { raw: { type: keyword } }` multi-field in `getIndexColumns()` and aggregate on `<name>.raw`. For localized columns, aggregate on `<name>.<locale>`, using the listing's locale.
  - Wrap aggregation errors so that one bad column cannot take down the page (log the error and return `[]` for that field).
  - The other defects in this method (cache never reset, excluded field ignored, 10-bucket limit, `_term`) are in TODO-2 B-17.

## C-05 — MySQL: an index whose name is not a plain short identifier silently stays empty, or breaks every listing that uses it

- **Where:**
  - `Resources/config/validation/Index.yml`: `name` only has `NotBlank`. There is no character or length restriction.
  - `Worker/MysqlWorker.php:592-610`: table and view names are built by plain concatenation: `'coreshop_index_mysql_' . $name`, `'coreshop_index_mysql_localized_' . $name . '_' . $language`.
  - These names are used **unquoted** in `Worker/MysqlWorker.php:469` and `:530` (`'INSERT INTO ' . $this->getTablename(...)`), in `Worker/MysqlWorker/Listing/Dao.php:52,87,133,138,172,198` (`->from($this->model->getQueryTableName(), 'q')`), and in `Worker/MysqlWorker/Listing.php:430` (subquery string). They are backtick-quoted in `:298-308` (view), `:345-350` (DROP) and `:373-378` (RENAME), but not escaped there either.
  - `Worker/MysqlWorker.php:401-432`: `updateIndex()` catches every exception from the inserts and only logs a warning (see TODO-2 B-10).
- **Problem:**
  - MySQL identifiers are limited to 64 characters. The localized view name is `31 + strlen(name) + 1 + strlen(locale)` characters, so with `de_AT` any index name longer than **27** characters makes `CREATE VIEW` fail. `createOrUpdateIndexStructures()` throws in `post_save`, after the entity is already flushed. The admin gets a 500, but the index exists without its view, and every listing on it fails with `Table … doesn't exist`. The admin gives no hint why.
  - A name with a space or another non-identifier character (`"Main Index"`) also breaks the DDL. DBAL only quotes asset names that are reserved keywords or were given quoted, so `CREATE TABLE coreshop_index_mysql_Main Index …` fails in `post_save`, again after the entity is saved. From then on, every object save sends an `IndexMessage` whose unquoted `INSERT INTO coreshop_index_mysql_Main Index …` fails. `updateIndex()` swallows the error, so **the index stays empty forever** and the logs only show warnings. Listing queries (`FROM coreshop_index_mysql_localized_Main Index_de`) fail with a 500. A dash (`"b2b-index"`) fails the same way.
  - A backtick or a quote in the name is an SQL injection vector for anyone who can edit indices. It is limited to admins, but it combines with C-02-style privilege escalation.
- **Fix direction:**
  - Add `Regex: { pattern: '/^[a-z0-9_]+$/i' }` and `Length: { max: 24 }` to `Index.name`. Apply the same rule to `IndexColumn.name`, because column names become SQL identifiers too (see TODO-2 B-02).
  - Quote every identifier with `$connection->quoteIdentifier()` (DBAL `QueryBuilder::from()` does not quote).
  - Add a migration or an installer check that lists existing indices with invalid names, so admins can rename them.
