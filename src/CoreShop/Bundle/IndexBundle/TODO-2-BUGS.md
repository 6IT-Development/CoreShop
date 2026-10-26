# IndexBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this bundle, but they come from how this bundle uses them, or how its consumers call it, and they should be fixed together with it.
Paths are relative to the bundle root unless noted otherwise. Items are roughly ordered by impact within each section. Items marked **(plausible)** follow from the code, but were not reproduced against a running database or cluster.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security

### B-01 — SQL injection in `ORDER BY`: the sort direction is concatenated into the SQL without any check
- **Where:**
  - `Order/Mysql/SimpleOrderRenderer.php:36`: `$this->quoteFieldName($order->getKey(), $prefix) . ' ' . $order->getDirection()`.
  - `Worker/MysqlWorker/Listing.php:180-186` (`setOrder()` wraps the raw value in `new SimpleOrder(...)`) and `:205-209`.
  - (dependent) `Component/Index/Order/SimpleOrder.php`: the constructor accepts any string.
  - `Factory/FilteredListingFactory.php:42-55`: `orderKey` and `order` are taken straight from the `ParameterBag` that the caller passes in (request data).
- **Problem:** `order=ASC, (SELECT SLEEP(5))` or a `UNION`-free boolean or time-based payload ends up verbatim in the `ORDER BY` clause. In core, `CategoryController` happens to overwrite the direction with a whitelisted value *after* `createList()` (dependent `FrontendBundle/Controller/CategoryController.php:162-163`). But `FilteredListingFactoryInterface::createList()` is the public API, and every project controller, API endpoint or GraphQL resolver that hands request data to it is injectable. The key is quoted with `quoteIdentifier()`, so only the direction is affected.
- **Fix direction:** normalise the direction in `SimpleOrder::__construct()` (`strtoupper()`, then only `ASC`/`DESC` are allowed, otherwise throw) **and** in `SimpleOrderRenderer` (defence in depth). In `FilteredListingFactory`, accept `orderKey` only if it is an index column or a system column, and accept the direction only from `['asc','desc']`.

### B-02 — Index column names (and therefore SQL identifiers) are never validated
- **Where:**
  - `Resources/config/validation/IndexColumn.yml`: the constraints are attached, but they never run. `IndexColumnCollectionType` / `IndexType` have no `Valid` constraint on `columns`. One of the constraints is on `type`, a property that the form never sets and Doctrine never maps (`Component/Index/Model/IndexColumn.php:39`), so it would fail every save if it ever did run.
  - `Worker/MysqlWorker.php:179`, `:224` (`addColumn($column->getName(), …)`), `:443-466`, `:505-527` (`quoteIdentifier($column->getName())`).
  - `Worker/OpenSearchWorker.php:351-370` (mapping property names).
- **Problem:** a column called `o_id`, `active`, `oo_id`, `language` or `name` clashes with the system columns. DBAL throws `SchemaException: column already exists`, or (MySQL) the view gets duplicate columns (see B-13). A name with a dot breaks OpenSearch mappings (`a.b` becomes an object path). An empty name passes the form, because the `NotBlank` never runs.
- **Fix direction:** add `Valid` to the `columns` collection. Replace the constraints in `IndexColumn.yml` with: `name` → `NotBlank`, `Regex(/^[a-zA-Z][a-zA-Z0-9_]*$/)`, `Length(max: 60)`, and a callback that rejects the reserved system names of the selected worker. Drop the `type` constraint. Add a `UniqueEntity`-like check (unique column names within one index).

### B-03 — The custom admin actions skip the permission check that the CRUD actions perform
- **Where:**
  - `Controller/IndexController.php:34` (`getTypesAction`), `:49` (`getConfigAction`), `:123` (`getClassDefinitionForFieldSelectionAction`), `:174` (`getOpenSearchClientsAction`).
  - `Controller/FilterController.php:33` (`getConfigAction`), `:44` (`getFieldsForIndexAction`), `:64` (`getValuesForFilterFieldAction`).
  - Compare with (dependent) `ResourceBundle/Controller/ResourceController.php:59-90` (`isGrantedOr403()`), which every inherited action calls first.
- **Problem:** any authenticated Pimcore backend user, including users without `coreshop_permission_index` / `coreshop_permission_filter`, can list every class definition with all field names, list the configured OpenSearch clients, and read the distinct **indexed values** of any column (`get-values-for-filter-field?index=1&field=price`). That is an information disclosure across permission boundaries.
- **Fix direction:** call `$this->isGrantedOr403();` at the start of each custom action. Add a functional test that sends a user without the permission and expects 403.

### B-04 — `FilteredListingFactory` passes request input into typed properties without casting or limits (TypeError, then DoS)
- **Where:** `Factory/FilteredListingFactory.php:50-56`: `$limit = $parameterBag->get('perPage'); … $list->setLimit($limit);`. Typed properties: `Worker/MysqlWorker/Listing.php:49` (`protected ?int $limit`, file has `strict_types=1`) and `Worker/OpenSearchWorker/Listing.php:36`. (dependent) `FrontendBundle/Controller/CategoryController.php:133-136` passes `$request->request`.
- **Problem:**
  - `InputBag::get()` returns a string. Assigning `"24"` to `?int` under `strict_types` throws `TypeError`, so any POST to a category page that contains `perPage` returns 500.
  - If the value is cast later, it is still unbounded: `perPage=100000` makes the MySQL listing load 100 000 objects one by one with `AbstractObject::getById()` (`Listing.php:271-279`). That is a cheap DoS.
- **Fix direction:** `$parameterBag->getInt('perPage')`, clamped to `[1, max]`, where `max` comes from configuration (for example the frontend `*_per_page_allowed` lists). Treat `orderKey` and `order` as in B-01.

---

## Broken features: MySQL worker and listing

### B-05 — `Dao::loadGroupByRelationValuesAndType()` drops conditions: `where()` overwrites `where()`, and the subquery is built from the wrong `WHERE`
- **Where:** `Worker/MysqlWorker/Listing/Dao.php:131-188`.
- **Problem:**
  - `$queryBuilder->where('fieldname = …')` followed by `if (null !== $type) { $queryBuilder->where('type = …'); }` (`:143-147`, `:150-154`, `:164-168`). `where()` **replaces** the predicate, so with a `$type` the `fieldname` filter is lost and the values of *every* relation field of that type come back.
  - In the non-count branch (`:163-174`), the `fieldname` `where()` is applied **before** the subquery copies `getQueryPart('where')`. The subquery is therefore `SELECT o_id FROM <view> q WHERE fieldname = '…'`. The view has no `fieldname` column, so MySQL resolves it to the **outer** relation table (correlated subquery). The current user conditions, `active = 1` and the variant mode are all dropped. This branch is used by (dependent) `Component/Index/Worker/FilterGroupHelper.php` (`getGroupByRelationValues($field)`) and by any caller with `$countValues = false`.
- **Fix direction:** build the subquery from the original `WHERE` first, then use `andWhere()` for `fieldname` and `type`. Bind the values as parameters (`createNamedParameter()`) instead of `quote()`. Add tests for both branches, with and without `$type`.

### B-06 — `Listing::addJoins()`: the join type never matches, so every join is an INNER join (and `queryJoins` can never be filled anyway)
- **Where:** `Worker/MysqlWorker/Listing.php:454-483`. `$joinType = isset($tableJoins['type']) ? ' ' . $tableJoins['type'] : ' LEFT';` then `switch (strtolower($joinType)) { case 'inner': … case 'left': … }`.
- **Problem:** because of the leading space, no `case` ever matches. The `default` keeps `$function = 'join'`, which is DBAL's INNER join, so a LEFT join silently drops rows. On top of that, `$queryJoins` (`:90`) has no setter or adder at all. Only `resetConditions()` touches it, so the whole block is unreachable, and anyone who subclasses to use it gets the wrong join type.
- **Fix direction:** either delete `queryJoins` and the loop (extensions already have `MysqlIndexQueryExtensionInterface::addJoins()`), or add `addQueryJoin()` and fix the matching (`trim()`, `match`).

### B-07 — The MySQL listing caches its state badly: stale `count()`, and the sort order depends on call order
- **Where:** `Worker/MysqlWorker/Listing.php`.
  - `totalCount` (`:45`) is only set in `count()` (`:493-503`) and in `load()` (`:268`). `addCondition()`, `resetCondition()`, `addRelationCondition()`, `addQueryCondition()`, `resetConditions()` and `setVariantMode()` reset `$objects`, but not `$totalCount`.
  - `setOrder()` (`:180-186`) is silently ignored unless `setOrderKey()` was called first, and `setOrderKey()` (`:205-209`) always resets the direction to `ASC`.
  - `resetOrder()` (`:194-198`) does not reset `$objects`.
- **Problem:** once `count()` has run, for example in a template or in Knp's paginator, any later condition change keeps the old total. The pagination then shows pages that do not exist, or hides results. `setOrder('desc'); setOrderKey('price');` sorts ascending. After `resetOrder()`, `getObjects()` still returns the old order.
- **Fix direction:** add a single `invalidate()` that resets `$objects` and `$totalCount`, and call it from every mutator. Keep the key and the direction as separate fields, and build the `SimpleOrder` only in `addOrderBy()`.

### B-08 — `setEnabled()` and `addQueryCondition()` are accepted but have no effect (both workers)
- **Where:** `Worker/MysqlWorker/Listing.php:164-178` (`queryConditions` stored), `:242-250` (`enabled`), `:381-421` (`addQueryFromConditions()` always renders `MatchCondition('active', '1')` and never reads `queryConditions`; the only code that would use them is commented out at `:414-420`). Same in `Worker/OpenSearchWorker/Listing.php:73-77`, `:166-174`, `:420-514`.
- **Problem:** `ListingInterface::addQueryCondition()` is part of the public contract (dependent `Component/Index/Listing/ListingInterface.php:82`), but the condition is silently dropped, so results are unfiltered. `setEnabled(false)` (to list inactive objects, for example in an admin preview) does nothing.
- **Fix direction:** render `queryConditions` like `conditions` (or remove the method from the interface in the next major and deprecate it now). Make `active = 1` depend on `$this->enabled`.

### B-09 — (plausible) `VARIANT_MODE_INCLUDE_PARENT_OBJECT` combined with any sort fails under `ONLY_FULL_GROUP_BY`
- **Where:** `Worker/MysqlWorker/Listing/Dao.php:58-64`: `if (null !== $queryBuilder->getQueryPart('orderBy')) { … ->addGroupBy('q.o_virtualObjectId'); }`.
- **Problem:** DBAL initialises `orderBy` to `[]`, never to `null` (reference vendor `Query/QueryBuilder.php:82`), so the `GROUP BY` is **always** added. `SELECT DISTINCT q.o_virtualObjectId … GROUP BY q.o_virtualObjectId ORDER BY q.price` fails with `Expression #1 of ORDER BY clause is not in GROUP BY clause` under MySQL's default `sql_mode` (5.7+/8.x). The `if/else` branches are also identical apart from the group by.
- **Fix direction:** order by an aggregate (`MIN(q.price)`/`MAX(...)`, depending on the direction) when grouping. Decide on the condition with `!empty(...)`, or better, track the orders yourself (see TODO-3 I-17).

### B-10 — `MysqlWorker::updateIndex()` swallows every database error, so objects silently drop out of the index and messenger never retries
- **Where:** `Worker/MysqlWorker.php:401-432`: three separate `try { … } catch (\Exception $e) { $this->logger->warning(...); }` blocks around the data insert, the localized insert, and the relation delete plus insert. There is no transaction.
- **Problem:** a failed main insert (constraint, type error, C-05 identifier, lock wait timeout) is logged as a *warning*, and the message is acked. `coreshop_index` has `max_retries: 3` and a failure transport (`Resources/config/pimcore/messenger.yml`), but they never fire. A partial failure leaves, for example, the new localized rows next to old main rows, or no relation rows at all (deleted first, insert failed).
- **Fix direction:** run the three writes in `$this->database->transactional(...)` and let exceptions propagate (wrap them in a domain exception with the object id and the index name). Let the messenger retry or failure transport handle them. In `coreshop:index`, catch them per object (see B-25).

### B-11 — `AbstractWorker::prepareData()` leaves Pimcore's global state changed when a getter or interpreter throws
- **Where:** `Worker/AbstractWorker.php:102-206`. `\Pimcore::unsetAdminMode()` and `AbstractObject::setHideUnpublished(false)` at `:104-107`. The restore at `:199-203` only runs on success. The `catch` at `:186-190` re-throws. The `catch` also calls `$this->logger->error()`, but `LoggerAwareTrait::$logger` is `null` unless `setLogger()` was called (only the DI definitions call it).
- **Problem:** after one failing object, the rest of the process (the messenger worker, or the remaining loop of `coreshop:index`) runs with admin mode off and **unpublished objects visible**. Later indexing, and any other code in the same worker, then sees unpublished relations and variants. A worker created outside the container dies with `Call to a member function error() on null` instead of the real error.
- **Fix direction:** use `try { … } finally { restore }`. Use `$this->logger?->error(...)`, or default to a `NullLogger` in the constructor.

### B-12 — The MySQL `RangeRenderer` produces invalid SQL for open ranges, and it interpolates the values
- **Where:** `Condition/Mysql/RangeRenderer.php:39-40`. (dependent) `Component/Index/Condition/RangeCondition.php`: `?float $from, ?float $to`.
- **Problem:** `new RangeCondition('price', 10, null)` (a "from 10" price filter, allowed by the type) renders `` q.`price` >= 10 AND q.`price` <= `` and fails with a syntax error. The core range processor only adds the condition when both bounds are set, but project processors and API filters do not have to. The OpenSearch renderer handles `null` correctly, so the two workers behave differently.
- **Fix direction:** emit only the bounds that are set (and nothing when both are `null`). Quote the values or bind them as parameters instead of concatenating floats.

### B-13 — (plausible) The MySQL localized view is `SELECT *` over two tables, so a column name that exists in both breaks `CREATE VIEW`
- **Where:** `Worker/MysqlWorker.php:287-314` (`SELECT * FROM <table> LEFT JOIN <localized> …`). The localized table always has `oo_id`, `language` and `name` (`:213-215`), plus localized extension columns.
- **Problem:** a non-localized index column called `name`, which is very common, or an extension system column that is also returned as a localized system column, gives `Duplicate column name 'name'`. View creation fails in `post_save`, and every listing on the index fails afterwards.
- **Fix direction:** build an explicit column list (`t.*` plus `l.<each localized column>`), or reject the clash in validation (B-02).

### B-14 — `renameIndexStructures()` (MySQL) misses views with mixed-case names and leaves orphaned views behind
- **Where:** `Worker/MysqlWorker.php:356-385`: `$allViews = …->listViews();` then `array_key_exists($oldTable, $allViews)`. DBAL keys `listViews()` by `strtolower($view->getQuotedName())` (reference vendor `Schema/AbstractSchemaManager.php:1560`). `tablesExist($oldTable)` with a string is deprecated (`:312-321`). Every error is swallowed (`:382-384`).
- **Problem:** for an index called `Products`, the views `coreshop_index_mysql_localized_Products_de` are never found, so they are not renamed and stay behind, pointing at tables that no longer exist. `post_save` then creates new views under the new name. The old views break `mysqldump`/`SHOW FULL TABLES` tooling ("references invalid table") and pile up with every rename.
- **Fix direction:** compare case-insensitively, or simply `DROP VIEW IF EXISTS` the old views and let `createOrUpdateIndexStructures()` recreate them. Pass an array to `tablesExist([...])`. Re-throw instead of logging.

### B-15 — The auto-generated migration (`mysql_auto_generate_migrations: true`) has a wrong `down()` and is marked as executed blindly
- **Where:** `Worker/MysqlWorker.php:109-156`. `:114`: `$downQueries = array_merge($downQueries, $localizedViews);`. `:128-130`: silent `return` when no `App\` migration namespace exists. `:154-155`: `metadataStorage->complete(...)`.
- **Problem:** `down()` first restores the old schema and then runs the **new** `CREATE OR REPLACE VIEW` statements, so the views end up selecting from the restored old columns. That fails or produces a view with the wrong shape. When the project has no `App\…` migration directory, the feature silently does nothing. The migration is written to disk *and* marked as executed in the same request, so on a multi-server deployment the other nodes never run the DDL.
- **Fix direction:** generate the views for the *old* schema in `down()` (or `DROP VIEW` plus recreate from the old definition). Log a warning when no namespace is found. Document the multi-node behaviour, or make the namespace configurable (`core_shop_index.migrations_namespace`).

---

## Broken features: OpenSearch worker and listing

### B-16 — OpenSearch `Listing::count()` returns the number of items on the current page, so pagination always shows exactly one page. Without a limit, only 10 hits are returned
- **Where:** `Worker/OpenSearchWorker/Listing.php:259-262` (`return \count($this->getObjects());`), `:176-203` (`load()` sets `size` only when a limit is set; it never reads `hits.total`).
- **Problem:** Knp/Pimcore pagination calls `count()` for the total. On OpenSearch it equals `resultsPerPage`, so the shop never renders page 2. Code that iterates a listing without a limit gets OpenSearch's default `size` of **10** documents, for example sitemaps, feeds and exports.
- **Fix direction:** send `track_total_hits: true`, store `$result['hits']['total']['value']` in `$totalCount`, and return it from `count()`. Without a limit, page through with `search_after` + PIT (or `scroll`) instead of relying on the default size.

### B-17 — OpenSearch group-by values ignore the excluded field, are cached forever, crash in parent-object mode, and are capped at 10 buckets
- **Where:** `Worker/OpenSearchWorker/Listing.php:205-218`, `:282-389`.
- **Problem:**
  - `$fieldNameShouldBeExcluded` is accepted but never used. `getSearchParams()` is called without `$excludedFieldName` (`:307`), so the options of a multiselect are narrowed by its **own** selection. After you tick one manufacturer, all other manufacturers disappear. MySQL excludes the field (`Worker/MysqlWorker/Listing.php:315`).
  - `$preparedGroupByValuesLoaded` (`:56`) is never reset by `addCondition()`/`reset*()`/`setVariantMode()`. The first call fixes the values for the life of the listing.
  - In `VARIANT_MODE_INCLUDE_PARENT_OBJECT`, `:366` reads `$bucket['objectCount']['value']`, but no `objectCount` sub-aggregation is ever requested, so you get `Undefined array key "objectCount"` (an exception in debug) and `count = null`.
  - `terms` uses the default `size` of 10, so filters show at most 10 options. `order: {_term: asc}` is deprecated (use `_key`). `from`/`size` from the listing's offset and limit (`:313-319`) are applied to an aggregation-only query, which also returns hits that are not needed.
  - `getGroupByRelationValues()` (`:210-213`) aggregates on the column instead of the nested `_relations.dest`, so relational filters show the interpreted column value instead of the related ids.
- **Fix direction:** compute the aggregations per field, with `getSearchParams($fieldName)` when the field should be excluded (or use a `post_filter` plus a `filter` aggregation per facet, which is the standard faceting pattern). Add `cardinality` on `o_virtualObjectId` as `objectCount` when grouping by parent. Set `size: 0` for hits and an explicit `terms.size`. Use `nested` + `filter(fieldname)` + `terms(dest)` for relation values. Reset the cache in every mutator.

### B-18 — OpenSearch ignores the sort order completely, and `getOrder()`/`getOrderKey()` throw before a value is set
- **Where:** `Worker/OpenSearchWorker/Listing.php:32-34` (`protected string $order;`, `protected string|array $orderKey;`, no defaults), `:105-125`, `:420-514` (no `sort` is ever added to the body).
- **Problem:** the result order is relevance or `_doc` order, whatever the filter or the user's "sort by price" says. Calling `getOrder()` or `getOrderKey()` before setting them throws `Error: Typed property … must not be accessed before initialization`. The listing does not implement `OrderAwareListingInterface` either, so `addOrder()` callers fail with "undefined method".
- **Fix direction:** make both properties nullable with defaults. Map the key and direction (plus `addOrder()`) to `body.sort` (localized fields: `<name>.<locale>`; text fields: the keyword sub-field). Implement `OrderAwareListingInterface`.

### B-19 — OpenSearch `VARIANT_MODE_INCLUDE_PARENT_OBJECT` is not implemented
- **Where:** `Worker/OpenSearchWorker/Listing.php:436-444` handles only `HIDE` and `VARIANTS_ONLY`. `:194-200` loads `$hit['_source']['o_id']`.
- **Problem:** in parent-object mode, which the frontend uses when `variantMode` is configured, MySQL returns the parent (`o_virtualObjectId`), deduplicated. OpenSearch returns every variant as its own product, so the same product shows several times.
- **Fix direction:** use `collapse: { field: 'o_virtualObjectId' }` and load `o_virtualObjectId` instead of `o_id` in that mode. Use a `cardinality` aggregation for the total.

### B-20 — The OpenSearch `ConcatRenderer` ignores AND/OR and merges clauses into invalid queries
- **Where:** `Condition/OpenSearch/ConcatRenderer.php:54`: `return array_merge_recursive([], ...$conditions);`. `$condition->getOperator()` is never read.
- **Problem:**
  - A search filter over several fields (dependent `SearchConditionProcessor`, concatenator `OR`) becomes AND, so a term has to match in *all* fields.
  - Worse, `array_merge_recursive` merges two `['filter' => ['wildcard' => [a => …]]]` into **one** `wildcard` with two fields. OpenSearch rejects that (`[wildcard] query doesn't support multiple fields`), so **the search filter fails on every request** as soon as it has two or more fields. Two `term`s on the same field merge into an array value, which is also invalid.
- **Fix direction:** return `['must' => [['bool' => [$op === 'OR' ? 'should' : 'must' => $clauses, 'minimum_should_match' => 1]]]]`, where each sub-condition stays a separate clause (`['bool' => $rendered]`).

### B-21 — Missing or wrong OpenSearch condition renderers: `NotLike` acts as `Like`, comparisons throw, `IsCondition` means something different
- **Where:**
  - `Condition/OpenSearch/LikeRenderer.php:35-60`: there is no check for `NotLikeCondition`, so a "not like" becomes a positive `wildcard`. `*`/`?` in the user value are not escaped. `wildcard` on an analysed `text` field matches tokens, not the value.
  - `Resources/config/services/condition_renderer.yml:58-82`: there is no OpenSearch renderer for `CompareCondition`, `GreaterThan(Equal)Condition`, `LowerThan(Equal)Condition` or `NotMatchCondition` (dependent `Component/Index/Condition/*`), so `ConditionRenderer` throws `No Renderer found for condition with type …`.
  - `Condition/OpenSearch/IsRenderer.php:52`: `supports()` checks the concrete `OpenSearchWorker`, while every other renderer checks `OpenSearchWorkerInterface`, so decorated or custom workers get "No Renderer found".
  - `IsCondition` means `IS [NOT] NULL` on MySQL (`Condition/Mysql/IsRenderer.php:38`) but `term field = value` on OpenSearch.
- **Problem:** the same filter returns opposite or different results depending on the worker, or throws only on OpenSearch.
- **Fix direction:** add a `CompareRenderer` (`range` for `<`/`<=`/`>`/`>=`, `term` for `=`, `must_not term` for `!=`). Honour `NotLikeCondition`/`NotInArrayCondition`. Escape wildcard metacharacters and use `case_insensitive: true` on keyword fields. Check the interface in `IsRenderer`. Document a single `IsCondition` meaning and implement it in both workers.

### B-22 — (plausible) `_relations` has no explicit mapping, so relation filters on camelCase field names never match
- **Where:** `Worker/OpenSearchWorker.php:84-86` (`'_relations' => ['type' => 'nested']`, no `properties`), `Worker/OpenSearchWorker/Listing.php:480` (`['term' => ['_relations.fieldname' => $fieldName]]`).
- **Problem:** with dynamic mapping, `fieldname` and `type` become `text` + `.keyword`. A `term` query on the analysed `text` field compares the raw value with lower-cased tokens, so `productCategory` never equals the token `productcategory`. Relational select and multiselect filters on such fields return no products.
- **Fix direction:** map `_relations` explicitly: `src`/`src_virtualObjectId`/`dest` as `integer`, `fieldname`/`type` as `keyword`, and the extension relational columns from `IndexRelationalColumnsExtensionInterface` as well. Use `dynamic: strict` for the index (see TODO-3 I-25).

### B-23 — The OpenSearch worker is always registered, even when `PimcoreOpenSearchClientBundle` / `opensearch-php` is missing
- **Where:** `Resources/config/services.yml:145-162` (worker, always), `:112-118` (registry for the `OpenSearch\Client` interface), `Resources/config/services/condition_renderer.yml:58-82`, `Form/Type/Worker/OpenSearchWorkerType.php`. Compare `CoreShopIndexBundle.php:70-72`, which adds the client pass only when the bundle class exists.
- **Problem:** the admin offers `opensearch` as a worker type in every installation. If an admin picks it, saving fails with `Missing client in index configuration` or `Class "OpenSearch\Client" not found`, and the half-saved index then breaks `ListingFactory` for that index.
- **Fix direction:** load an `opensearch.yml` service file only `if (class_exists(PimcoreOpenSearchClientBundle::class) && isset($bundles['PimcoreOpenSearchClientBundle']))`.

---

## Broken features: indexing pipeline (command, messenger, listeners)

### B-24 — `coreshop:index <index>` re-indexes every index of the class, not just the given one, and does so without inherited values
- **Where:** `Command/IndexCommand.php:182` (`$this->indexUpdater->updateIndices($object)`). (dependent) `Component/Index/Service/IndexUpdaterService.php:38-41,78-108` loops over `findAll()` indices. `Messenger/Handler/IndexMessageHandler.php:72-77` wraps the same call in `InheritanceHelper::useInheritedValues(...)`, but the command does not.
- **Problem:**
  - `coreshop:index products_os`, run to rebuild one broken index, also rewrites all other indices for that class. That is slow, and on OpenSearch combined with C-01 it is risky.
  - The CLI writes **non-inherited** values. Variants lose every inherited attribute (price, manufacturer, categories…) after a full rebuild, while the messenger path stores them, so the content of the index depends on how it was last built.
- **Fix direction:** add `IndexUpdaterServiceInterface::updateIndex(IndexInterface $index, IndexableInterface $o)` (or a filter argument) and call it for the selected indices only. Wrap the per-object work in `InheritanceHelper::useInheritedValues()`.

### B-25 — `IndexCommand`: wrong messages, a single failure aborts the run, and the command always exits with 0
- **Where:** `Command/IndexCommand.php:87-105` (`if (null === $indexIds)`; an `IS_ARRAY` argument returns `[]`, never `null`), `:170-183` (no `try/catch`), `:105`, `:196` (`return 0`).
- **Problem:** a fresh installation without indices prints `No Indices found for ` (with an empty list) instead of "you have to first create an Index". One object whose getter throws (or any OpenSearch error) aborts the whole rebuild halfway, and cron sees exit code 1 only for that uncaught case. Missing indices and skipped objects still return `0`.
- **Fix direction:** use `if ([] === $indexIds)`. Catch per object, count the failures, print a summary, and return `Command::FAILURE` if anything failed. Report unknown index names as an error.

### B-26 — The messenger worker indexes stale objects: there is no cache reset between messages
- **Where:** `Messenger/Handler/IndexMessageHandler.php:41` (`Concrete::getById($id)`, no `force`), `:62` (`getChildren()` unpaged). `Resources/config/pimcore/messenger.yml` routes to `coreshop_index` on the **default** bus. Pimcore's `CollectGarbageMiddleware` is only configured for the GenericExecutionEngine bus (`../pimcore-source/bundles/GenericExecutionEngineBundle/config/pimcore/config.yaml:14`).
- **Problem:** a long-running `messenger:consume coreshop_index` keeps every loaded object in Pimcore's `RuntimeCache`. The second update message for the same object (or for its parent or variants) indexes the **first** version it loaded, so the index lags behind the real data until the worker restarts. Memory also grows with every object, until `--memory-limit` kills the worker in the middle of a batch.
- **Fix direction:** load with `Concrete::getById($id, ['force' => true])` and call `\Pimcore::collectGarbage()` after each message (or add `Pimcore\Messenger\Middleware\CollectGarbageMiddleware` to the bus that handles `coreshop_index`). Page through children with a `Listing` (`setLimit`/`setOffset`).

### B-27 — `IndexDeleteObjectListener`: the recursion checks the parent instead of the child, and messages are duplicated
- **Where:** `EventListener/IndexDeleteObjectListener.php:59-69`: `foreach ($object->getChildren(...) as $child) { … if (!$object instanceof IndexableInterface) { continue; } … }`.
- **Problem:** the check is always false (`$object` was checked before), so non-indexable children of an indexable object are dispatched too. Pimcore itself deletes children one by one, and each fires its own `preDelete`, so every descendant is dispatched twice (or n times for deep trees), each time with `getChildren(…, true)` loading full child lists.
- **Fix direction:** check `$child`. Better: drop the manual recursion and rely on the per-child `preDelete`, or keep the recursion and skip it when the event comes from a recursive delete.

### B-28 — `ProcessManager/IndexProcess::run()` drops `$params`
- **Where:** `ProcessManager/IndexProcess.php:34`: `return parent::run($executable);`.
- **Problem:** parameters that the Process Manager passes at runtime (for example the index ids chosen by the user in the start dialog) are lost, so the process always rebuilds everything.
- **Fix direction:** `return parent::run($executable, $params);`.

### B-29 — `Installer::install()` passes the application name as a list item, so `coreshop:resources:install` runs for every application and errors are swallowed
- **Where:** `Installer.php:43-47`: `['--no-interaction' => true, '--application-name object_index']`. The second entry has the integer key `0`, so `ArrayInput::parse()` calls `addArgument(0, '--application-name object_index')`, which fills the `command` slot (`InputDefinition::hasArgument(int)` in Symfony 7.4). It is not an option. `:47`/`:54`: return codes are ignored, and `Application` catches exceptions by default.
- **Problem:** `--application-name` is never set, so the resource installer runs for **all** applications. The intended value `object_index` is also wrong, because the application is `coreshop`. A failing `create-tables` still marks the bundle as installed (`parent::install()`), so the next request fails with missing tables.
- **Fix direction:** `['command' => 'coreshop:resources:install', '--application-name' => 'coreshop', '--no-interaction' => true]`. Call `setCatchExceptions(false)`, check the exit code, and throw on failure (or call the install services directly instead of running console commands, see TODO-3 I-12).

---

## Broken features: admin, forms and configuration

### B-30 — `indexLastVersion` is never sent to the admin, so every save resets it to `false`
- **Where:** `Resources/config/serializer/Model.Index.yml`: `exclusion_policy: ALL`, and `indexLastVersion` has `type`/`groups` but no `expose: true`. `Resources/public/pimcore/js/index/item.js:123-124` (`checked: this.data.indexLastVersion`). `Form/Type/IndexType.php` (`CheckboxType` `indexLastVersion`).
- **Problem:** the checkbox is always unticked on load, so the next save of the index, for any reason, silently turns "index last version" off again.
- **Fix direction:** add `expose: true`. While there, also check the other serializer files: `Model.Filter.yml` declares `resultsPerPage` as `string`, and `Model.FilterCondition.yml` exposes `field`/`filter`, which are not mapped by Doctrine (see TODO-3 I-19).

### B-31 — `IndexController` suggests the interpreter `' quantityValue'` (with a leading space) for classification-store quantity values
- **Where:** `Controller/IndexController.php:416-418`.
- **Problem:** the admin takes the suggested interpreter into the column. On save, `IndexColumnType` looks up `' quantityValue'` in the registry (`Form/Type/IndexColumnType.php:117`, no `has()`; see B-33), and indexing calls `$this->interpreterServiceRegistry->get(' quantityValue')`. Both throw "service not found".
- **Fix direction:** `'quantityValue'`. Also use a constant shared with the `type: quantityValue` tag in `services.yml:208-210`.

### B-32 — `FilterController::getValuesForFilterFieldAction()` returns the values of the **last** column when the field does not exist, and `IndexController` crashes on missing definitions
- **Where:**
  - `Controller/FilterController.php:77-88`: `$column` keeps the last loop value when nothing matches, so the `instanceof` check is always true.
  - `Controller/IndexController.php:296-300`: `Fieldcollection\Definition::getByKey()` can return `null` (a definition that was deleted but is still allowed in the class), and `->getFieldDefinitions()` is then called on `null`.
  - `:375-377`: `KeyConfig::getById()` returns `null` for a deleted key, which gives a `TypeError` in `getClassificationStoreFieldConfiguration()`.
  - `:101-103`: `class_implements('Pimcore\Model\DataObject\Foo')` returns `false` (plus a warning) when the generated class is missing, and `in_array(…, false)` then throws a `TypeError`.
- **Problem:** the filter editor shows wrong values for a typo or a removed column. One stale class, brick, collection or classification definition breaks the whole index editor (`get-config`, `get-class-definition-for-field-selection`).
- **Fix direction:** use `array_find()` (PHP 8.4) for the column, and null checks with `continue` for the definitions. Use `is_a($fqcn, IndexableInterface::class, true)` after `class_exists()`.

### B-33 — `IndexColumnType` resolves the getter and interpreter form types without `has()`, so an empty or unknown value turns a save into a 500
- **Where:** `Form/Type/IndexColumnType.php:69`, `:86`, `:100`, `:117`. The sibling listeners in `IndexType`, `FilterConditionType`, `InterpreterType` and `AbstractConfigurableIndexColumnElementType` do check `has()`.
- **Problem:** `isset($data['getter'])` is `true` for `''`. `FormTypeRegistry::get('')` throws, so the admin JSON gets an exception instead of a validation error. The same happens for stale getter or interpreter types from removed services.
- **Fix direction:** `if ('' === ($data['getter'] ?? '') || !$registry->has($data['getter'], 'default')) { return; }`, and let the choice type validation report the invalid value.

### B-34 — `FilterPre/UserConditionCollectionType::buildForm()` does not call `parent::buildForm()`
- **Where:** `Form/Type/FilterPreConditionCollectionType.php:36-41`, `Form/Type/FilterUserConditionCollectionType.php:36-41`. Compare `Form/Type/Core/AbstractConfigurationCollectionType.php` (`setAttribute('prototypes', …)`) and its `buildView()` (`foreach ($form->getConfig()->getAttribute('prototypes') …)`).
- **Problem:** the `prototypes` attribute is never set, so `buildView()` iterates over `null`. That is a warning, and in debug an `ErrorException`, whenever a view of the filter form is created (Twig rendering, API docs, tests).
- **Fix direction:** call `parent::buildForm($builder, $options);` first (or make `buildView()` default to `[]`).

### B-35 — Migration `Version20200224161101` fails on a database where `coreshop_index` does not exist yet
- **Where:** `Migrations/Version20200224161101.php:28`: `$schema->getTable('coreshop_index')->hasColumn(...)`.
- **Problem:** `Schema::getTable()` throws `SchemaException` for an unknown table. Running `doctrine:migrations:migrate` before the bundle's tables are created (a fresh project, CI, or `pimcore:bundle:install` order) aborts the whole migration run.
- **Fix direction:** `if (!$schema->hasTable('coreshop_index') || $schema->getTable('coreshop_index')->hasColumn('indexLastVersion')) { return; }`.

### B-36 — The bundle's own default config uses the deprecated `mapping_types` key, so every container build triggers its deprecation
- **Where:** `Resources/config/pimcore/config.yml:16-23` sets `core_shop_index.mapping_types`. `DependencyInjection/Configuration.php:454-458` marks it `setDeprecated(…, '4.1', …)`. `DependencyInjection/CoreShopIndexExtension.php:165-172` merges it, and also sets `coreshop.index.mapping_types`, a parameter that nothing reads.
- **Problem:** every application logs `The "core_shop_index.mapping_types" configuration key is deprecated` on every cache warmup. Projects cannot get rid of it, and it hides the deprecations they need to act on.
- **Fix direction:** remove the key from `config.yml` (the `worker_mapping_types.mysql` block already has the same values). Drop the unused parameter, and keep the merge for projects that still set the old key.

### B-37 — (plausible) MySQL rename runs DDL inside Doctrine's flush transaction, so the admin save fails with "There is no active transaction"
- **Where:** `EventListener/UpdateIndexListener.php:33-54` (a Doctrine `preUpdate` entity listener, `Resources/config/services/listeners.yml:30-34`) calls `MysqlWorker::renameIndexStructures()` (`Worker/MysqlWorker.php:371-380`, `RENAME TABLE`).
- **Problem:** in MySQL, `RENAME TABLE` causes an implicit commit. The ORM's `UPDATE coreshop_index` then runs in autocommit, and `Connection::commit()` fails with `PDOException: There is no active transaction` (PHP ≥ 8.0). The admin gets an error although the tables are renamed and the row is updated. Any later failure in the flush can no longer be rolled back. For OpenSearch, see C-01.
- **Fix direction:** in `preUpdate`, only record `[index id => old name]`. Run the rename in `postFlush` (outside the transaction) or in the ResourceController `post_save` event before `createOrUpdateIndexStructures()`.
