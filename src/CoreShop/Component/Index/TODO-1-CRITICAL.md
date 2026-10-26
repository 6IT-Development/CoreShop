# Index Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: all PHP in this component (`Condition/*`, `Extension/*`, `Factory/*`, `Filter/*`, `Getter/*`, `Interpreter/*`, `Listing/*`, `Model/*`, `Order/*`, `Service/*`, `Worker/*`), `composer.json` and `README.md`. Also checked: the code and config that depend on it or build on it:
`IndexBundle/Resources/config/{services.yml,doctrine/model/*.orm.xml,validation/{Filter,FilterCondition}.yml}`, `IndexBundle/DependencyInjection/Compiler/RegisterFilterConditionTypesPass.php`, `IndexBundle/Condition/{Mysql,OpenSearch}/*`, `IndexBundle/Order/Mysql/*`, `IndexBundle/Worker/{AbstractWorker,MysqlWorker,OpenSearchWorker}.php`, `IndexBundle/Worker/MysqlWorker/{Listing,Listing/Dao}.php`, `IndexBundle/Factory/{FilteredListingFactory,ListingFactory}.php`, `IndexBundle/Form/Type/Filter/*`, `IndexBundle/Controller/IndexController.php` (getter config it writes), `FrontendBundle/Controller/CategoryController.php` and `FrontendBundle/Resources/views/Category/filter/*.html.twig` (the main consumer), `Component/Pimcore/DataObject/LocaleFallbackHelper.php`.

Reference vendor for API checks: `../pimcore2teszt10211524/vendor` (symfony/* 7.4.21, nesbot/carbon 3.15.0), plus `../pimcore-source` (`AbstractObject::getById(int|string $id)`, `FieldDefinitionEnrichmentModelTrait::getFieldDefinition()`).

"Critical" here means: a security issue that gives more power than the user's role should allow, data loss, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** live outside this component, but they come from how it is used, and they should be fixed together with it.

**Symfony 7.4 / PHP 8.4 compatibility:** `php -l` (PHP 8.4.27) passes on all 97 PHP files. There are no implicit nullable parameters and no removed PHP functions. The Symfony APIs used are `ParameterBag`/`InputBag` (`http-foundation`), `ExpressionLanguage` and the DI `ContainerInterface`. All of them still exist in 7.4. Note that `ParameterBag::all($key)` and `InputBag::get()` throw `BadRequestException` for an unexpected shape (see TODO-2 **B-03**). The one hard runtime break is the **Carbon 3** behaviour change in **C-02**. Packaging gaps (undeclared dependencies, PHPStan 1.x, no `php` constraint) are in TODO-3 **I-14**.

**Overlap with other TODOs:** the IndexBundle TODOs (`Bundle/IndexBundle/TODO-*.md`) already cover several component files. The items below are **not repeated here**. Fix them together with this file:
- IndexBundle **C-02** (`Interpreter/ExpressionInterpreter.php` gets the whole service container).
- IndexBundle **B-01** (`Order/SimpleOrder.php` accepts any direction string), **B-08** (`ListingInterface::addQueryCondition()`/`setEnabled()` have no effect), **B-12** (`RangeCondition` with a `null` bound renders invalid SQL), **B-21** (`IsCondition` means different things per worker; `NotLikeCondition` treated as `LikeCondition` on OpenSearch), **B-24** (`Service/IndexUpdaterService.php` re-indexes every index of the class), **B-02** (`IndexColumn::$type` is never set or mapped).
- IndexBundle **I-01** (native types on `ListingInterface`, `WorkerInterface`, `DynamicRendererInterface`), **I-16** (`configuration` columns stored as PHP `serialize()`), **I-22** (`ListingInterface::buildSimilarityOrderBy()` is dead).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## C-01 — The filter `concatenator` is free text and goes into the storefront SQL unescaped, so anyone with the `filter` permission can inject SQL that runs on every category page

- **Where:**
  - `Filter/SearchConditionProcessor.php:72-74`: `$concatenator = $condition->getConfiguration()['concatenator'] ?: 'OR'; … new ConcatCondition($name, $concatenator, $likeConditions)`.
  - `Filter/CategoryMultiSelectConditionProcessor.php:118-120`: the same pattern.
  - `Condition/ConcatCondition.php:25-31`: the constructor accepts any string as the operator.
  - (dependent) `IndexBundle/Condition/Mysql/ConcatRenderer.php:57`: `'(' . implode(' ' . trim($condition->getOperator()) . ' ', $conditions) . ')'`. The operator is concatenated raw into the `WHERE` clause.
  - (dependent) `IndexBundle/Form/Type/Filter/FilterConditionSearchType.php:46` and `FilterConditionCategoryMultiSelectType.php:47`: `->add('concatenator', TextType::class)`, with no `Choice` constraint. `IndexBundle/Resources/config/validation/FilterCondition.yml` only checks `type` and `label`.
- **Problem:**
  - A back-office user who may only edit filters (`coreshop_permission_filter`, a merchandising permission) can save a search or category-multiselect condition with a concatenator like `OR 1=1) OR (SELECT … FROM users …) --`. The text is stored unchanged and rendered into the listing query on **every** storefront request for each category that uses the filter. That allows boolean or time-based extraction of any table the shop's DB user can read (customer data, admin password hashes), and denial of service (`SLEEP()`).
  - **(plausible)** With PDO MySQL's default `MYSQL_ATTR_MULTI_STATEMENTS = true`, and because `Dao` runs the built SQL without bound parameters (IndexBundle I-17), stacked statements (`…; UPDATE users …`) may also work. Not reproduced.
  - Even without bad intent, a typo (`"and "` works because of `trim()`, but `"UND"` does not) turns every category page that uses the filter into a 500.
- **Fix direction:**
  - Add `enum Concatenator: string { case And = 'AND'; case Or = 'OR'; }` to the component. Type `ConcatCondition::$operator` with it, and normalise legacy strings with `Concatenator::tryFrom(strtoupper(trim($s))) ?? throw new \InvalidArgumentException()`.
  - In the processors, read the value through the enum and fall back to `Or` (log a warning) for invalid stored values. Never pass the raw configuration value on.
  - (dependent) In the bundle, use a `ChoiceType` with `['AND', 'OR']` in both form types. In `ConcatRenderer` (MySQL and OpenSearch), render from the enum only.
  - Add a migration (or an install check) that lists the stored filter conditions whose `concatenator` is not `AND`/`OR`.
  - Look at `CompareCondition::$operator` in the same change (TODO-3 **I-02**). It is concatenated raw too, but only internal code sets it today.

## C-02 — Carbon 3: `TimestampInterpreter` throws a `TypeError` for every object with an empty date, so those objects are never (re)indexed, and filled dates are now converted in UTC

- **Where:**
  - `Interpreter/TimestampInterpreter.php:33-37`: `if ($value instanceof Carbon) { return $value; } return Carbon::createFromTimestamp($value);`.
  - Reference vendor `nesbot/carbon/src/Carbon/Traits/Timestamp.php:30-37` (3.15.0): `createFromTimestamp(float|int|string $timestamp, DateTimeZone|string|int|null $timezone = null)` and `return $timezone === null ? $date : $date->setTimezone($timezone);`, where `$date = static::createFromTimestampUTC($timestamp)`. `../pimcore-source/composer.json` requires `nesbot/carbon ^3.0`.
  - (dependent) `IndexBundle/Worker/AbstractWorker.php:162-191`: an exception from an interpreter aborts `prepareData()` for the whole object (and leaves Pimcore's admin mode and the unpublished-objects flag changed, IndexBundle **B-11**).
- **Problem:**
  - The interpreter is registered as `timestamp` (`IndexBundle/Resources/config/services.yml:216-218`) and is meant for date and timestamp fields. An empty date field returns `null`. In Carbon 2, `createFromTimestamp(null)` silently returned 1970-01-01. In Carbon 3 the parameter is typed, so `null` gives `TypeError: … must be of type string|int|float, null given`. The same happens for any `DateTimeInterface` that is not a mutable `Carbon` (`\DateTime`, `CarbonImmutable`, `\DateTimeImmutable`).
  - The `TypeError` aborts indexing of the **whole object**, not just the column. In the messenger path the message is retried and then ends up in the failure transport. With `coreshop:index`, the whole run aborts (IndexBundle **B-25**). Every product without a value in that date field (for example "available from", "new until") silently disappears from, or never appears in, every category listing and filter that uses this index.
  - For filled values, Carbon 3 now keeps the result in **UTC** instead of the default timezone. On a MySQL `DATE` column (`Doctrine DateType::convertToDatabaseValue()` → `format('Y-m-d')`), a timestamp between 00:00 and the UTC offset is stored as the **previous day**. Date range filters are off by one day for a part of the catalog.
- **Fix direction:**
  - `return match (true) { null === $value, '' === $value => null, $value instanceof \DateTimeInterface => Carbon::instance($value), is_numeric($value) => Carbon::createFromTimestamp($value, date_default_timezone_get()), default => throw new \InvalidArgumentException(sprintf('TimestampInterpreter: unsupported value of type %s', get_debug_type($value))) };`
  - Declare `nesbot/carbon: ^3.0` in `composer.json` (TODO-3 **I-14**).
  - Re-index the affected indices after the fix (UPGRADE note), because Carbon-2-era rows contain 1970-01-01 for empty dates.
  - Add unit tests for `null`, `''`, `int`, numeric string, `\DateTimeImmutable` and `Carbon` input (TODO-3 **I-18**).
