# Index Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this component, but they come from how it is used, and they should be fixed together with it.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items are roughly ordered by impact within each section. Items marked **(plausible)** follow from the code, but were not reproduced against a running shop.
Items already covered by the IndexBundle TODOs are not repeated (see the overlap list in TODO-1).

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security

### B-01 — Pre-conditions read the shopper's request parameters, so any visitor can override a merchant-defined pre-condition
- **Where:**
  - `Filter/FilterProcessor.php:47-51`: pre-conditions get the same `$parameterBag` as the user conditions (`$request->query` from (dependent) `FrontendBundle/Controller/CategoryController.php:167-171`).
  - (dependent) `IndexBundle/DependencyInjection/Compiler/RegisterFilterConditionTypesPass.php:38-39`: every generic condition type (`select`, `multiselect`, `boolean`, `range`, `relational_*`, `category_*`, `search`) is registered as **both** a user type and a pre-condition type. So the same processor class runs for both.
  - Every processor reads the request first and only falls back to the configured value when the parameter is missing, for example `Filter/SelectFilterConditionProcessor.php:49-53` (`$value = $parameterBag->get($field); if (empty($value) && isset(…['preSelect'])) { … }`), `MultiselectFilterConditionProcessor.php:48-52`, `RangeFilterConditionProcessor.php:66-90`, `BooleanFilterConditionProcessor.php:59-63`.
  - `Filter/FilterProcessor.php:41-51`: the pre-conditions run **after** the user conditions and write into the same `$currentFilter[$field]` key.
- **Problem:**
  - A pre-condition is the merchant's fixed restriction for a filter (for example `sale = 1` on a "Sale" page, `brand = 12` on a brand landing page, or a price window). Adding `?sale=0`, `?brand=99` or `?price=0,999999` to the URL replaces the configured value. The shopper then sees products that the merchant deliberately excluded from that page. Any SEO landing page built on pre-conditions can be turned into a different listing.
  - When a user condition and a pre-condition share a field, the pre-condition overwrites `$currentFilter[$field]`. The rendered filter then shows the pre-condition's value as the user's selection, and the user's own choice is lost in the UI.
- **Fix direction:**
  - In `FilterProcessor::processConditions()`, give the pre-condition processors an **empty** `new ParameterBag()`, so that only the configured values apply. Or add an explicit `$isPrecondition` branch in each processor that ignores the request.
  - Process the pre-conditions **first** and keep their state out of `$currentFilter` (or under a `PRECONDITION_` key, matching the condition key already used on the listing).
  - Add a Behat scenario: a filter with a `select` pre-condition, and a request that sends the same parameter, must still show only the pre-condition's products.

---

## Broken features: filter condition processors

### B-02 — `select`, `relational_select` and `boolean` turn empty or invalid input into a condition that matches nothing (or the wrong thing)
- **Where:**
  - `Filter/SelectFilterConditionProcessor.php:49-69`: `if (null !== $value)` after `trim()`. An empty string passes, so `?brand=` adds `brand = ''`.
  - `Filter/SelectFilterConditionProcessor.php`, `RelationalSelectConditionProcessor.php`, `BooleanFilterConditionProcessor.php`: none of them handles `FilterConditionProcessorInterface::EMPTY_STRING` (`'##EMPTY##'`), while every other processor maps it to `null`. `##EMPTY##` becomes `brand = '##EMPTY##'`.
  - `Filter/BooleanFilterConditionProcessor.php:65-75`: `filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` returns `null` for invalid input and `false` for `"0"`. Both are then cast with `(string) $value`, which gives `''` in both cases. So the condition is `field = ''` for "false" **and** for garbage input.
- **Problem:**
  - A form that submits an empty "all" option (a common theme customisation of the radio list in `FrontendBundle/Resources/views/Category/filter/_select.html.twig`), or a link that clears a filter with `?brand=`, shows **zero products** instead of the unfiltered list.
  - On MySQL, `tinyint_col = ''` happens to match `0`, so "false" works by accident. `?inStock=abc` then silently acts as "not in stock" instead of being ignored. On OpenSearch, a `term` on a `boolean` field with `''` is not equivalent to `false` and returns other results, so the two workers behave differently.
- **Fix direction:** treat `''` and `EMPTY_STRING` as "no condition" in all three processors. For booleans, use `$parameterBag->getBoolean()` / `filter_var` and skip the condition when the result is `null`. Render `true`/`false` as `'1'`/`'0'` (or, better, pass a `bool` to a typed condition, TODO-3 **I-02**).

### B-03 — Multiselect processors: the empty marker can never match, so preselected values cannot be cleared, and a scalar parameter gives a 400
- **Where:** `Filter/MultiselectFilterConditionProcessor.php:48-63`, `RelationalMultiselectConditionProcessor.php:60-76`, `MultiselectFilterConditionFromMultiselectProcessor.php:74-100`, `CategoryMultiSelectConditionProcessor.php:93-121`.
- **Problem:**
  - `$values = $parameterBag->all($field)` always returns an **array** (reference vendor `symfony/http-foundation/ParameterBag.php:45-56`). The next check, `if ($values === static::EMPTY_STRING)`, is therefore never true: it is dead code.
  - When `preSelects` are configured, `empty($values)` replaces "nothing selected" with the preselection. The only way to send "nothing" is the `EMPTY_STRING` marker, but it arrives as `['##EMPTY##']`. That array is not empty, so it becomes `IN ('##EMPTY##')` (or `LIKE '%,##EMPTY##,%'`) and returns **zero products**. A shopper can never untick every preselected value.
  - `?brand=5` (a scalar, for example from a hand-written link or an older theme) makes `ParameterBag::all()` throw `BadRequestException` ("expecting array"), so the whole category page answers **400**.
  - `MultiselectFilterConditionFromMultiselectProcessor.php:77` and `CategoryMultiSelectConditionProcessor.php:96` read `['preSelects']` without `isset()`. In debug mode that is an `ErrorException` ("Undefined array key") when the condition was saved without preselects.
- **Fix direction:** read the parameter with `$parameterBag->has($field) ? (array) $parameterBag->all()[$field] : null` (scalar becomes a one-element list). Treat `[EMPTY_STRING]` as "explicitly empty" (no condition, no preselect). Apply the preselect only when the parameter is **absent**, not when it is empty. Use `?? []` for `preSelects`.

### B-04 — `SearchConditionProcessor`: breaks as a pre-condition, crashes on an incomplete configuration, and loads the whole listing once more just to render the search box
- **Where:** `Filter/SearchConditionProcessor.php`.
  - `:64-68`: `$fieldName = $isPrecondition ? 'PRECONDITION_' . $field : $field; $likeConditions[] = new LikeCondition($fieldName, …)`. The prefix is applied to the **column name**, not to the condition key.
  - `:51`, `:62`, `:72`: `['searchTerm']`, `['pattern']`, `['concatenator']` are read without `isset()`. `LikeCondition::__construct(string $pattern)` gets `null` when the pattern was never set.
  - `:32`: `prepareValuesForRendering()` calls `$list->load()`.
  - `:37`, `:54`: the state is always stored under the fixed key `'searchTerm'`.
- **Problem:**
  - A search used as a **pre-condition** renders `` q.`PRECONDITION_name` LIKE … ``, so every page with that filter fails with `Unknown column` (500).
  - A search condition saved without a `pattern` (a free `TextType` in `IndexBundle/Form/Type/Filter/FilterConditionSearchType.php:47`) throws `TypeError` (null to `string`) as soon as someone searches. A pattern other than `left`/`right`/`both` throws `InvalidArgumentException`. Both are 500s.
  - `load()` runs the full listing query and (MySQL) loads every object of the current page one by one (IndexBundle **I-20**), only to put them into `objects`. `FrontendBundle/Resources/views/Category/filter/_search.html.twig` never uses that variable. Every page view with a search filter costs one extra listing query and N extra object loads. It also fills the listing's object cache before the controller has finished adding conditions and ordering.
  - Two search conditions in one filter share the `searchTerm` key, so the second one shows the first one's term.
- **Fix direction:** keep `$field` as the column name and use `PRECONDITION_<name>` only as the condition key (`addCondition(…, $key)`). Validate `pattern` with the `LikePattern` enum (TODO-3 **I-02**) and default it to `both`. Remove the `load()` call and the `objects` entry (or make them lazy). Store the state under `$condition->getConfiguration()['name']`.

### B-05 — `RangeFilterConditionProcessor`: undefined index on a single value, the shopper's request object is mutated, and the slider minimum becomes `null`
- **Where:** `Filter/RangeFilterConditionProcessor.php:33-46`, `:64-103`.
- **Problem:**
  - `explode(',', $parameterBag->get($field))` followed by `$values[1]`. `?price=10` (no comma) is `Undefined array key 1` (`ErrorException` in debug). `?price[]=1` throws `BadRequestException` from `InputBag::get()`.
  - `$parameterBag->set($field . '-min', …)` writes into `$request->query`. Every later reader of the request (other processors, pagination links, Twig `app.request.query`) sees the synthetic `price-min` and `price-max` parameters. Generated URLs then contain both `price=` and `price-min=`.
  - The bounds are cast with `(float)` without validation. `?price-min=abc` becomes `0.0`, so the filter silently becomes `price >= 0`.
  - The min and max for rendering come from `$rawValues[0]` and `$rawValues[count - 1]`. (dependent) `IndexBundle/Worker/MysqlWorker/Listing/Dao.php:87-88` orders the group-by values with `ORDER BY q.<field>`, and MySQL sorts `NULL` **first**. As soon as one product has no value in that column, `minValue` is `null` and the slider starts at nothing. For a non-numeric column type the order is lexicographic (`"100"` < `"20"`), so both bounds are wrong.
  - `['preSelectMin']`, `['preSelectMax']` and `['stepCount']` are read without `isset()`.
- **Fix direction:** parse `price=<min>,<max>` with `sscanf()`/`explode(',', $v, 2) + [null, null]`, and validate with `is_numeric()`. Keep the parsed values in local variables, never in the bag. Skip `null` values when computing min and max (`array_filter` + `min()`/`max()` over floats), or ask the listing for `MIN()`/`MAX()` directly. Use `?? null` for the configuration keys.

### B-06 — `NestedFilterConditionProcessor::addCondition()` crashes when the nested condition has no children, and its type check is dead
- **Where:** `Filter/NestedFilterConditionProcessor.php:63-80` (and `:36-52` for rendering).
- **Problem:**
  - `prepareValuesForRendering()` checks `is_array($conditions)`, but `addCondition()` does `foreach ($condition->getConfiguration()['conditions'] as …)` without any check. A nested condition saved empty (`conditions` is `null` or missing) gives `Undefined array key` plus `foreach() argument must be of type array|object`. In debug that is a 500 on every category page that uses the filter.
  - `$this->conditionProcessors->get($cond->getType())` throws for an unknown type, so the `instanceof FilterConditionProcessorInterface` check after it can never be false, and one stale child type breaks the whole filter (see B-07).
  - The rendered child entries carry no id or field, so a theme cannot tell two children of the same type apart.
- **Fix direction:** `$conditions = $condition->getConfiguration()['conditions'] ?? []; if (!is_iterable($conditions)) { return $currentFilter; }`. Check `has()` before `get()` (log and skip unknown types). Key the rendered children by `$cond->getId()`, like `FilterProcessor::prepareConditionsForRendering()` does.

### B-07 — One filter condition with an unknown type takes down every page that uses the filter
- **Where:** `Filter/FilterProcessor.php:70-88` (`$this->userConditionProcessors->get($condition->getType())` with a `@var` cast), `Filter/NestedFilterConditionProcessor.php:41`, `:66`.
- **Problem:** the condition type is stored in the database (`coreshop_filter_condition.type`). After a project removes or renames a custom processor (or a bundle that provides one is disabled), `ServiceRegistry::get()` throws a `NonExistingServiceException`. Every category page with that filter, and `prepareConditionsForRendering()`, returns 500 until someone edits the filter in the admin.
- **Fix direction:** check `has()`, skip the condition and log a warning (`LoggerInterface`, with the filter id and the type). Optionally add an `IndexBundle` installer or `lint` command that reports conditions with unknown types.

### B-08 — (dependent) Facet counts and values ignore the listing's variant mode, so filters offer values that lead to empty results
- **Where:** (dependent) `IndexBundle/Worker/MysqlWorker/Listing.php:307-331` passes `AbstractListing::VARIANT_MODE_INCLUDE` to `addQueryFromConditions()` for `getGroupByValues()` and `getGroupByRelationValues()`. In `:401-412`, only `VARIANT_MODE_HIDE` adds `q.o_type != 'variant'`. The component processors (`Filter/*ConditionProcessor.php`, `prepareValuesForRendering()`) display these values and counts as they are.
- **Problem:** the frontend default is `VARIANT_MODE_HIDE` (`CategoryController.php:138`). The facets still count the variants, and they offer values that **only** variants have (for example a size or colour). Clicking such a value returns an empty page, and every count is higher than the number of products shown.
- **Fix direction:** use the listing's own variant mode for the group-by queries (`null` → `$this->getVariantMode()`). In `VARIANT_MODE_INCLUDE_PARENT_OBJECT`, count `DISTINCT o_virtualObjectId` (the Dao already does this for counts). Document the contract in `ListingInterface::getGroupByValues()`: "values and counts reflect the current variant mode".

---

## Broken features: interpreters and getters

### B-09 — `RelationalNestedInterpreter` skips every non-relational step when it builds the relation rows
- **Where:** `Interpreter/RelationalNestedInterpreter.php:35-50`: in `interpretRelational()`, the callback returns `$value` **unchanged** when a step is not a `RelationInterpreterInterface`. (dependent) `IndexBundle/Worker/AbstractWorker.php:272-279` calls `interpret()` for the column value and `interpretRelational()` for the relation rows, both with the **original** value.
- **Problem:**
  - A typical chain is `objectProperty(property: manufacturer)` → `object`. `interpret()` correctly gives the manufacturer id. `interpretRelational()` skips `objectProperty` and passes the **product itself** to `ObjectInterpreter`, so the relation table stores `dest = <product id>`. Relational filters (`relational_select`, `relational_multiselect`) on that column then offer and match the wrong objects.
  - After one relational step the value is a `RelationalValue[]`. A second relational step (`ObjectInterpreter`) ignores those objects and returns `[]`.
  - The method is typed `: array`. If the last step is not relational and the value is not an array, `loop()` returns it unchanged and PHP throws a `TypeError`.
- **Fix direction:** apply `interpret()` for every step except the **last**, and `interpretRelational()` only for the last step (which must be a `RelationInterpreterInterface`; assert that when the configuration is saved). Return `[]` when the final value is `null`. Add unit tests for `objectProperty → object` and for a single `object` step.

### B-10 — `IteratorInterpreter` and `NestedInterpreter` call `interpret()` on localized children, which always throws
- **Where:** `Interpreter/IteratorInterpreter.php:39-51`, `Interpreter/NestedInterpreter.php:41-45`, `Interpreter/LocalizedNestedInterpreter.php:53-59`. `LocaleMappingInterpreter::interpret()` and `LocalizedNestedInterpreter::interpret()` throw `\Exception('method "interpret" in Localized Interpreter not allowed…')`.
- **Problem:**
  - `iterator(localeMapping)`, or `nested([…, localeMapping])`, can be saved in the admin, but every indexing run of that column throws, and the object is dropped from the index (same impact as TODO-1 **C-02**).
  - `nestedLocalized([iterator(localeMapping)])` fails as well, because `LocalizedNestedInterpreter` only forwards the language to **direct** children. `IteratorInterpreter` is not localized, so its child gets `interpret()`.
  - `iterator(object)` produces ids for the column, but no relation rows, because `IteratorInterpreter` is not a `RelationInterpreterInterface`.
  - `$interpreterConfig['interpreter']`, `['type']` and `['interpreterConfig']` (`IteratorInterpreter.php:41-48`, `NestedTrait.php:30-33`) are read without checks, so a half-saved configuration gives "Undefined array key" warnings and then a registry error.
- **Fix direction:** make `IteratorInterpreter` implement `LocalizedInterpreterInterface` and forward `interpretForLanguage()` to localized children (same pattern as `LocalizedNestedInterpreter`). Reject localized children inside `nested`/`iterator` when the column is saved (form validation), with a clear message. Validate the configuration keys with `Assert::keyExists()` and `?? []`.

### B-11 — Getters call methods without checking that they exist, read configuration keys blindly, and index localized classification-store values only in the default language
- **Where:**
  - `Getter/LocalizedFieldGetter.php:35-45`: `$object->$getter($locale)` with no `method_exists()`.
  - `Getter/FieldCollectionGetter.php:39-42`: `$config->getGetterConfig()['collectionField']` and `$object->$collectionContainerGetter()` with no checks. `:51`: `$columnConfig['className']` without `isset()`.
  - `Getter/ClassificationStoreGetter.php:29-38`: `['classificationStoreField']`, `['groupConfigId']`, `['keyConfigId']` without checks. `getLocalizedKeyValue($groupId, $keyId)` always uses the `default` language.
  - (dependent) `Component/Pimcore/DataObject/LocaleFallbackHelper.php` (`useFallbackValues()`): it restores `Localizedfield::setGetFallbackValues()` without `try/finally`.
- **Problem:**
  - After a class-definition change (a field renamed, or moved out of the localized fields), the index column still points to the old getter. `Call to undefined method` then aborts indexing of **every** object of that class (and leaks the global state, IndexBundle **B-11**). `BrickGetter` already guards this case and returns `null`.
  - When a getter throws inside `useFallbackValues()`, the global fallback flag stays changed for the rest of the worker process. Later reads in the same messenger worker then return (or do not return) fallback values unexpectedly.
  - **(plausible)** For a classification store with localized keys, the value is read only for the `default` language, so the index column is `null` for every language-specific value.
- **Fix direction:** check with `method_exists()`/`isset()` and return `null`, like `BrickGetter`. Wrap `LocaleFallbackHelper::useFallbackValues()` in `try { … } finally { restore }`. For classification stores, add a localized variant: return `[$locale => getLocalizedKeyValue($g, $k, $locale)]` and let the column use `localeMapping`, or document that only the default language is indexed.

### B-12 — `SoundexInterpreter` emits a warning for values without letters and crashes on arrays of objects
- **Where:** `Interpreter/SoundexInterpreter.php:36-45`.
- **Problem:** `soundex('')`, `soundex('123')` and `soundex('€')` return `''`. `$soundEx[0]` is then `Uninitialized string offset 0` (a warning, so an `ErrorException` in debug, which aborts the object). `sort($value)` plus `implode()` on an array of objects (a relation field) throws `Object of class … could not be converted to string`.
- **Fix direction:** `if ('' === $soundEx) { return null; }`. Map the array values with `is_scalar()` / `Stringable` before `implode()`, and skip everything else.

---

## Broken features: conditions, services and extensions

### B-13 — `LikeCondition::setPattern()` bypasses the allowed-pattern check, and the renderer then silently matches only empty strings
- **Where:** `Condition/LikeCondition.php:30-32` (the check is only in the constructor), `:50-53` (setter without a check). (dependent) `IndexBundle/Condition/Mysql/LikeRenderer.php:49-62`: `switch ($pattern)` has no `default`, so `$patternValue` stays `''`.
- **Problem:** `$cond->setPattern('contains')`, or a misspelled pattern from a project processor, renders `field LIKE ''`, which only matches empty values. There is no error, so the filter just shows nothing.
- **Fix direction:** validate in the setter too, or better, make the condition immutable with a `LikePattern` enum (TODO-3 **I-02**). Add `default => throw` to the renderer (`match`).

### B-14 — (dependent) `InArrayCondition` with more than one value means "these values in this order next to each other" on MySQL, but "any of them" on OpenSearch
- **Where:** `Condition/InArrayCondition.php` (no documented semantics). (dependent) `IndexBundle/Condition/Mysql/LikeRenderer.php:34-36`: `sprintf('%1$s%2$s%1$s', ',', implode(',', $values))` with pattern `both`, so `[3, 7]` becomes `LIKE '%,3,7,%'`. (dependent) `IndexBundle/Condition/OpenSearch/InRenderer.php:40-52`: `terms`, which means "any".
- **Problem:** core only passes single values today (`CategoryController.php:140-151`: the current store and category). A project that passes several store ids or categories (`new InArrayCondition('parentCategoryIds', [$a, $b])`) gets almost no results on MySQL (only products where `a` and `b` happen to be stored next to each other in that order), and the full "any" set on OpenSearch.
- **Fix direction:** define the semantics in the class docblock ("matches if the array column contains **any** of the values"). Render as `(col LIKE '%,a,%' OR col LIKE '%,b,%')` on MySQL. Add an `InArrayCondition::$matchAll` flag (or an `AllInArrayCondition`) if "all" is needed.

### B-15 — `FilterGroupHelper` uses float and bool values as array keys, which merges different values and triggers a deprecation
- **Where:** `Worker/FilterGroupHelper.php:80-90`: `$explode = is_string($v['value']) ? explode(',', $v['value']) : [$v['value']]; … $values[$e] = …`.
- **Problem:** non-string values (OpenSearch returns native JSON numbers and booleans in the aggregation buckets) are used as keys directly. `1.5` and `1.9` both become key `1` (PHP 8.1+: "Implicit conversion from float 1.5 to int loses precision" deprecation). `true` becomes `1` and merges with the integer `1`. So the admin's "values for filter field" list (used for preselects) loses values and shows wrong counts.
- **Fix direction:** key by `(string) $e` (or `json_encode($e)`) and keep the original value in the entry.

### B-16 — `IndexUpdaterService`: one index with an unknown worker blocks indexing for every other index, and throws a PSR-log exception
- **Where:** `Service/IndexUpdaterService.php:63-65`, `:98-100` (`throw new InvalidArgumentException(…)` inside the loop over all indices). `:28`: `use Psr\Log\InvalidArgumentException`.
- **Problem:** if one index references a worker that is no longer registered (for example `opensearch` after the client bundle was removed, IndexBundle **B-23**), **every** save of an object of that class throws before the remaining indices are updated. The other, healthy indices go stale, and the messenger retries forever. The exception class is `Psr\Log\InvalidArgumentException` ("invalid log level"), so handlers that catch `\InvalidArgumentException` still work, but anyone who looks at it is misled, and the component depends on `psr/log` without declaring it.
- **Fix direction:** collect the errors per index, continue with the remaining indices, and throw one aggregated domain exception (`IndexWorkerNotFoundException extends \InvalidArgumentException`) at the end. Or log and skip a misconfigured index. Use `\InvalidArgumentException` (or the new domain exception), not the PSR one.

### B-17 — `DecimalIndexColumnTypeConfigExtension` sets `scale: 2` without a precision: large values overflow and extra decimals are silently rounded
- **Where:** `Extension/DecimalIndexColumnTypeConfigExtension.php:27-39`. (dependent) `IndexBundle/Worker/MysqlWorker.php:542-551` maps `DOUBLE` to the DBAL `decimal` type.
- **Problem:**
  - With DBAL 3, `decimal` falls back to `precision: 10` (deprecated since DBAL 3.7: "relying on the default decimal column precision"), so every `DOUBLE` index column is `DECIMAL(10,2)`. Values ≥ 100 000 000 fail the insert in strict SQL mode (`Out of range value`; the row is then missing, IndexBundle **B-10**) or are clipped without strict mode. Values with more than two decimals (weights, dimensions, unit prices) are rounded, so `RangeCondition` filters on them are inaccurate.
  - In DBAL 4, `DecimalType` requires an explicit precision (`ColumnPrecisionRequired`). This extension is then the blocker for every index with a `DOUBLE` column.
  - `supports()` compares `$index->getWorker() === 'mysql'` (a hard-coded registry key) instead of the worker type, so a MySQL worker registered under another name (a decorated or custom worker) gets DBAL's default scale `0`, and all its `DOUBLE` columns store whole numbers only.
- **Fix direction:** return `['precision' => 20, 'scale' => 6]` (or make both configurable per column via `IndexColumn::getConfiguration()`). Decide `supports()` from the worker class (`MysqlWorkerInterface`), not from the name. Write a migration note, because every existing index table changes on the next save.
