# Index Component – TODO 3: Improvements (code quality, readability, logic, PHP 8.4 / Symfony 7.4 idioms)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). None of these items is a functional defect on its own. The defects are in TODO-1 and TODO-2, and items here link to them where a cleanup also closes a bug.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.
Several items change public signatures of interfaces that the IndexBundle and projects implement (`ListingInterface`, `WorkerInterface`, the model interfaces, the condition classes). Group those into one BC-breaking step with IndexBundle **I-01**/**I-02**, and add `UPGRADE` notes. Items already covered by IndexBundle TODO-3 (**I-01** listing/worker typing, **I-16** `array` → `json` columns, **I-18** bound parameters and `LIKE` escaping in the renderers, **I-20** N+1 in the listings, **I-22** dead `buildSimilarityOrderBy()`) are not repeated.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 language features and typing

### I-01 — Native types on the four Doctrine models and their interfaces, and no more public properties
- **Where:** `Model/{Filter,FilterCondition,Index,IndexColumn}.php` and their interfaces. About 70 methods have no parameter or return type (`getName()`, `setName($name)`, `getConfiguration()`, `getIndex()`, …). The PHPDoc is the only contract.
- **What:**
  - `FilterCondition` and `IndexColumn` declare **every** property `public` (`FilterCondition.php:34-64`, `IndexColumn.php:34-94`), so any code can bypass the setters (and `Index::addColumn()`'s back-reference). Make them `protected` (like `Filter` and `Index`) with types: `protected ?int $id = null; protected string $name = ''; protected array $configuration = [];`.
  - The setters are inconsistent: `FilterCondition::setType()`/`setSort()` and `Index::set*()` return nothing, while `setLabel()`, `setQuantityUnit()`, `setConfiguration()` and all `Filter`/`IndexColumn` setters return `$this`, which the interfaces do not declare. Pick one (`: static`, to stay fluent) and declare it in the interfaces.
  - `getResultsPerPage(): ?int`, `getIndexLastVersion(): bool`, `getColumns(): Collection` (with `@return Collection<int, IndexColumnInterface>`), `getIndex(): ?IndexInterface`, and so on.
  - `Filter::__construct(\n        ) {` and `Index::__construct(\n        ) {` (`Filter.php:73-77`, `Index.php:68-71`) are leftovers from a removed promotion. Use `public function __construct()`.
  - Remove the `@psalm-suppress MissingConstructor` annotations (4 models) once the properties have defaults.
- **BC:** projects that extend the models or implement the interfaces must add the same types. Plan it for the next minor or major, together with IndexBundle I-01.

### I-02 — Make conditions and orders immutable value objects, with enums instead of magic strings and negation by subclass
- **Where:** `Condition/*.php` (21 classes, all with setters), `Order/SimpleOrder.php`.
- **What:**
  - Make each condition `final readonly class …`, with promoted public properties or getters only. No file in `src/` that uses `Component\Index\Condition\*` calls any of the setters (checked with grep). Immutability also closes TODO-2 **B-13**.
  - Add backed enums: `Concatenator` (`AND`/`OR`, TODO-1 **C-01**), `LikePattern` (`left`/`right`/`both`, replaces `LikeCondition::$allowedPatterns`), `CompareOperator` (`=`, `<>`, `<`, `<=`, `>`, `>=`), and `OrderDirection` (`ASC`/`DESC`, closes the component side of IndexBundle **B-01**).
  - Negation currently uses subclasses (`NotInCondition extends InCondition`, `NotLikeCondition extends LikeCondition`, `NotInArrayCondition extends InArrayCondition`, `IsNotNullCondition extends IsNullCondition`). Every renderer that checks only the parent class silently turns "not" into "is" (IndexBundle **B-21**). Use a `bool $negated` property (keep the subclasses as deprecated thin wrappers for one minor), so renderers cannot forget it.
  - `InCondition` is the only condition with manually assigned properties and an untyped setter (`InCondition.php:23-43`, `setFieldName($fieldName)`). Align it with the others.
  - `CompareCondition::$value` is `string` only. Numeric comparisons (`price > 10`) depend on the database's implicit cast. Allow `string|int|float|bool` and let the renderer bind the right type.
  - `IsCondition` (value `string`, meaning unclear, IndexBundle **B-21**): replace it with `IsNullCondition($field, negated: …)`, or document it and type the value as `bool`.

### I-03 — Tighten the interface contracts
- **Where and what:**
  - `Condition/DynamicRendererInterface.php:25-31`, `Order/DynamicOrderRendererInterface.php:25-31`, `Order/OrderRendererInterface.php:25-31`: add `: mixed` to `render()` (`ConditionRendererInterface` already has it). Fix the copy-pasted "Renders the condition." on the **order** interfaces, and the empty `*` lines.
  - `Worker/WorkerInterface.php:67`: `renderCondition(…, array|string $params = [])`, while `ConditionRendererInterface::render()` only accepts `array`. Drop `string`.
  - `Worker/WorkerDeleteableByIdInterface.php:28`: add `: void`, and make it extend `WorkerInterface` (it is only meaningful for workers; `IndexUpdaterService` already assumes that).
  - `Listing/OrderAwareListingInterface.php`: extend `ListingInterface`, and add `: void` to `addOrder()`/`resetOrder()`. Then callers (`Behat/Context/Domain/FilterContext.php`) do not need two `instanceof` checks.
  - `Listing/ExtendedListingInterface.php` only adds `getGroupByRelationValuesAndType()`, and only the MySQL listing implements it. Either move the method into `ListingInterface` (OpenSearch should support the type filter too) or document that it is MySQL-only.
  - `Filter/FilterProcessor.php:56`: `prepareConditionsForRendering(…, $currentFilter)` drops the `array` type from the interface. Restore it.
  - `Factory/FilteredListingFactoryInterface::createList(…, ParameterBag $parameterBag)`: take a small typed input object (`ListingRequest { ?string $orderKey; ?OrderDirection $order; ?int $perPage }`) instead of a raw bag. That moves the request parsing (and the validation from IndexBundle **B-04**) to one place.

### I-04 — `#[\Override]`, `final`, `readonly`, and `#[\Deprecated]`
- **What:**
  - Only 5 of 59 concrete classes are `final` (`ConditionRenderer`, `OrderRenderer`, `NestedFilterConditionProcessor`, `IteratorInterpreter`, `IndexUpdaterService`). Make the processors, interpreters, getters, `FilterGroupHelper` and `DecimalIndexColumnTypeConfigExtension` `final` as well (decorate through the interfaces), or document the ones that are meant as extension points. The models must stay open (Doctrine mapped superclasses).
  - Make constructor-promoted services `private readonly` (`ConditionRenderer`, `OrderRenderer`, `FilterProcessor`, `NestedFilterConditionProcessor`, `IteratorInterpreter`, `FilterGroupHelper`, `IndexUpdaterService`). The `protected` promoted properties in `Getter/{Brick,FieldCollection,LocalizedField}Getter.php` and `ExpressionInterpreter.php` should be `private readonly`.
  - `NestedTrait` declares `protected ServiceRegistryInterface $interpreterRegistry`, and the three users assign it by hand in their constructors. Use constructor promotion in each class and an abstract `getInterpreterRegistry()` in the trait, or turn the trait into a small `InterpreterChain` service (see I-08).
  - `Interpreter/RelationalValue.php`: make it a `final readonly` value object (no setters; `params` promoted).
  - Add `#[\Override]` to every method that implements an interface method (`render`, `supports`, `interpret`, `interpretForLanguage`, `interpretRelational`, `get`, `addCondition`, `prepareValuesForRendering`, …).
  - `Model/IndexColumnInterface.php:27-67`: the six `FIELD_TYPE_*` constants are marked `@deprecated` in PHPDoc only. PHP 8.4 supports `#[\Deprecated(message: '…', since: '4.1')]` on class constants, so callers get a runtime deprecation and static analysers see it.

### I-05 — Renderer dispatch: tagged iterators, a per-class cache, and a dedicated exception
- **Where:** `Condition/ConditionRenderer.php:31-45`, `Order/OrderRenderer.php:31-45`.
- **What:** both iterate every registered renderer on every call (a listing with 10 conditions × ~20 renderers is about 200 `supports()` calls per query). They type the loop variable with a `@var` comment instead of checking it.
  - Cache the result by `$worker::class . '|' . $condition::class`.
  - On Symfony 7.4, inject the renderers with `#[AutowireIterator('coreshop.index.condition.renderer')] iterable $renderers` instead of the registry, so the registry and its compiler pass become optional.
  - Throw a dedicated `RendererNotFoundException extends \InvalidArgumentException` with the worker class in the message (`No renderer for %s on worker %s`).
  - Rename the parameter `OrderInterface $condition` to `$order` in `OrderRendererInterface`/`OrderRenderer`.

---

## Logic, structure and readability

### I-06 — Filter processors: one base class instead of nine copies
- **Where:** `Filter/*ConditionProcessor.php`.
- **What:**
  - The comma-split-and-count loop is copied five times (`CategorySelect:41-60`, `CategoryMultiSelect:43-62`, `MultiselectFromMultiselect:37-57`, `SelectFromMultiselect:37-57`, `Worker/FilterGroupHelper.php:75-91`). Extract `GroupByValueSplitter::split(array $rawValues): array`.
  - `'quantityUnit' => $condition->getQuantityUnit() ? Unit::getById(…) : null` is copied eight times. `$isPrecondition ? 'PRECONDITION_' . $field : $field` is copied eleven times, written in two different styles. The `categoryIds`/`parentCategoryIds` selection is copied in the two category processors (four times in total).
  - Extract `AbstractFilterConditionProcessor` with `conditionKey(string $field, bool $isPrecondition)`, `quantityUnit(FilterConditionInterface)`, and request helpers built on `ParameterBag::getString()`/`getBoolean()`/`all()` that implement the "absent vs empty vs `EMPTY_STRING`" rules once (TODO-2 **B-02**, **B-03**).
  - `CategorySelectConditionProcessor.php:105` wraps the value as `'%,' . $v . ',%'` and then uses pattern `both`, which renders `%%,5,%%`. `CategoryMultiSelectConditionProcessor` uses `',' . $v . ','`. Use `InArrayCondition` in both (it exists for exactly this), so the comma format stays an internal detail of the MySQL worker (IndexBundle **I-21**).
  - `SearchConditionProcessor.php:69`: `unset($field)` inside the loop has no effect. Remove it.
  - Make the `prepareValuesForRendering()` result a typed view object (`FilterConditionView`) instead of an array with keys that differ per type (`currentValue` vs `currentValues`). The Twig templates then get one stable shape. Keep `toArray()` for BC.

### I-07 — Load related objects in one query, not one per filter value
- **Where:** `Filter/CategorySelectConditionProcessor.php:64-70`, `CategoryMultiSelectConditionProcessor.php:66-72`, `RelationalSelectConditionProcessor.php:38-43`, `RelationalMultiselectConditionProcessor.php:38-43`, `Worker/FilterGroupHelper.php:52-68`.
- **What:** each loops over the facet values and calls `Concrete::getById()` once per value. A brand or category facet with 200 values costs 200 object loads on every category page view. Collect the ids and load them with one listing (`$listing->setCondition('id IN (?)', [$ids])`), then keep the facet order. `FilterGroupHelper` also iterates `foreach ($values as &$id)` by reference without ever writing back through it: use a plain loop.

### I-08 — Interpreters: validate the configuration up front, use precise exceptions, and support all relation types
- **Where:** `Interpreter/*.php`.
- **What:**
  - Configuration keys are read without checks in `ExpressionInterpreter:40`, `IteratorInterpreter:41-48`, `NestedTrait:30-33` and `ObjectPropertyInterpreter` (which does check). Introduce small config DTOs (`NestedInterpreterConfig::fromArray()`) that validate once, when the index is saved, instead of on every object.
  - `LocaleMappingInterpreter::interpret()` and `LocalizedNestedInterpreter::interpret()` throw a bare `\Exception`. Use `\BadMethodCallException`, so callers can tell it apart from data errors.
  - `ObjectInterpreter::interpret()` and `interpretRelational()` repeat the same loop (`:28-66`). Derive `interpret()` from `interpretRelational()` (`array_map(fn (RelationalValue $r) => $r->getDestinationId(), …)`).
  - `ObjectInterpreter` and `ObjectIdInterpreter` accept only `AbstractObject` and `ObjectMetadata`, so assets and documents in a many-to-many relation, and `ElementMetadata`, are silently dropped, while `ObjectIdSumInterpreter` accepts any `ElementInterface`. Accept `ElementInterface` plus `ElementMetadata` and use `Element\Service::getElementType($el)` for the relation `type` (today it is always `'object'`).
  - `QuantityValueInterpreter` only handles `QuantityValue`. Also handle `InputQuantityValue` (Pimcore `AbstractQuantityValue`), and optionally convert to a configured base unit, so a range filter compares like with like.
  - Replace `NestedTrait` with an `InterpreterChain` service that `NestedInterpreter`, `LocalizedNestedInterpreter`, `RelationalNestedInterpreter` and `IteratorInterpreter` share. It would know the localized and relational rules in one place (TODO-2 **B-09**, **B-10**).

### I-09 — `IndexUpdaterService`: query only the relevant indices, and replace the operation string
- **Where:** `Service/IndexUpdaterService.php`.
- **What:**
  - `operationOnIndex($subject, 'update' | 'remove', …)` uses a string switch. Split it into two private methods, or pass a closure (`fn (WorkerInterface $w, IndexInterface $i) => $w->updateIndex($i, $subject)`).
  - `findAll()` runs on every object update, and then filters in PHP. Use `findBy(['class' => $className])` and memoise the result per class for the lifetime of the service (reset it with `ResetInterface` for long-running workers).
  - `isEligible()` requires `Concrete` only to call `getClass()->getName()`. `IndexableInterface::getClassName()` already provides that, so the method can work on the interface alone.
  - `removeFromIndicesById()` and `operationOnIndex()` duplicate the worker lookup. Extract `resolveWorker(IndexInterface $index): ?WorkerInterface` (see TODO-2 **B-16**).

### I-10 — Enums for variant modes and field types
- **Where:** `Listing/ListingInterface.php:30-52` (`VARIANT_MODE_*` string constants, and the untyped `setVariantMode($variantMode)`), `Worker/MysqlWorkerInterface.php:23-51`, `Worker/OpenSearchWorkerInterface.php:23-39`.
- **What:** `enum VariantMode: string { case Hide = 'hide'; case Include = 'include'; case VariantsOnly = 'variants_only'; case IncludeParentObject = 'include_parent_object'; }`. Today an unknown string, for example a typo in project configuration, silently behaves like `include` in the MySQL listing. The worker field types become `MysqlFieldType`/`OpenSearchFieldType` enums, and `WorkerInterface::getSupportedFieldTypes()` returns their cases. Keep the constants as aliases (`= VariantMode::Hide->value`) for one minor.

### I-11 — Typed configuration per filter condition type
- **Where:** every `Filter/*Processor.php` reads `$condition->getConfiguration()['…']` (about 30 places, many without `isset()`; TODO-2 **B-03** to **B-06**).
- **What:** introduce one small `readonly` configuration class per type (`SelectConfig { string $field; ?string $preSelect }`, `RangeConfig { string $field; ?float $preSelectMin; ?float $preSelectMax; ?float $stepCount }`, `SearchConfig { string $name; list<string> $fields; ?string $searchTerm; Concatenator $concatenator; LikePattern $pattern }`, …), built with `::fromArray()` and defaults. The processors then work with typed values. Combined with IndexBundle **I-16** (`json` columns), the same classes can validate the admin input.
- `FilterCondition::$field` (`Model/FilterCondition.php:49`) is not mapped by Doctrine and never read. Remove it (IndexBundle **I-19** covers the serializer side).

### I-12 — Limit and normalise free-text search input
- **Where:** `Filter/SearchConditionProcessor.php:48-75`.
- **What:** the search term goes straight into one `LIKE '%…%'` per configured field. There is no maximum length, no minimum length and no normalisation. A 10 000-character term, or 20 fields, makes every listing query a full scan with very long patterns. Apply `mb_substr($term, 0, 100)`, ignore terms shorter than 2–3 characters, collapse whitespace, and escape `%`/`_` (together with the renderer-side fix in IndexBundle **I-18**). Consider splitting the term into words (each word must match) instead of one literal substring.

### I-13 — Clean up docblocks, `@var` casts and leftover suppressions
- **Where:** 47 `@var` / `@psalm-suppress` annotations in the component. Examples: `Condition/ConditionRenderer.php:33-35`, `Order/OrderRenderer.php:33-35`, `Filter/FilterProcessor.php:72-74,82-84`, `Service/IndexUpdaterService.php:67-69,102-104`, `Interpreter/IteratorInterpreter.php:42-44`, `Getter/FieldCollectionGetter.php:48-50,59-61`. Plus the "@param string $fieldName" style docblocks in `Listing/ListingInterface.php` that only repeat the (missing) native types.
- **How:** replace the `@var` casts with `Assert::isInstanceOf()` or with typed registry accessors. Drop the docblocks once I-01/I-03 add native types. Add `@template`/generic annotations (`Collection<int, FilterConditionInterface>`) where the type cannot be expressed natively. `Getter/FieldCollectionGetter.php:51` builds a class name by string concatenation (`'Pimcore\Model\DataObject\Fieldcollection\Data\\' . $className`): resolve it through `Fieldcollection\Definition::getByKey($key)->getPhpClassName()` instead.

---

## Packaging, documentation and tests

### I-14 — `composer.json`: declare what the component really uses
- **Where:** `composer.json`.
- **What:**
  - Used directly but not required: `symfony/http-foundation` (`ParameterBag`, 9 files), `symfony/expression-language` and `symfony/dependency-injection` (`ExpressionInterpreter`; the container dependency should go away with IndexBundle **C-02**), `webmozart/assert`, `nesbot/carbon` (`^3.0`, TODO-1 **C-02**), `doctrine/collections`, `coreshop/pimcore` (`LocaleFallbackHelper`), and `psr/log` (only for the wrong exception in TODO-2 **B-16**; drop it after that fix).
  - Add `"php": "^8.4"` (or the project-wide minimum), so the PHP 8.4 features from this file can be used.
  - Upgrade `phpstan/phpstan ^1.10` and its extensions to `^2.1`.
  - `repositories: [{type: path, url: ../../*/*}]` and `minimum-stability: dev` belong to the monorepo, not to a split package (same as IndexBundle **I-15**). The `extra` block has odd indentation (three spaces).

### I-15 — Extension interfaces: naming and worker detection
- **Where:** `Extension/*.php`.
- **What:**
  - `IndexColumnTypeConfigExtension` and `IndexSystemColumnTypeConfigExtension` are interfaces without the `Interface` suffix used by every other interface in CoreShop. Add suffixed interfaces and keep the old names as deprecated `class_alias`es.
  - `IndexExtensionInterface::supports(IndexInterface $index)` receives only the index, so implementations compare worker **names** (`DecimalIndexColumnTypeConfigExtension.php:38`, TODO-2 **B-17**). Pass the worker too (`supports(IndexInterface $index, WorkerInterface $worker)`), or add a helper that resolves the worker type.
  - Document in the interfaces the expected shape of `getSystemColumns()`, `getLocalizedSystemColumns()` and `getRelationalColumns()` (`array<string, string>` name → field type).

### I-16 — Small PHP 8.4 idioms
- `Worker/FilterGroupHelper.php:48-108`: a two-case `switch` with a `$type` flag becomes `if ($interpreter instanceof RelationInterpreterInterface) { return $this->relationValues(…); } return $this->fieldValues(…);`. `array_key_exists('value', $value)` (`:94`) is always true, so the `EMPTY_STRING` branch (`:100-104`) is dead: remove it, or produce a real "empty" entry for `null` values.
- `Condition/LikeCondition.php:23`: `private array $allowedPatterns` is an instance property used as a constant. Make it `private const ALLOWED_PATTERNS`, or use the enum from I-02.
- `Filter/RangeFilterConditionProcessor.php:35-36`: `count($rawValues) > 0 ? $rawValues[0]['value'] : 0` becomes `min()`/`max()` over the non-null values (`array_filter(array_column($rawValues, 'value'), is_numeric(...))`), which also closes TODO-2 **B-05**. Do not use `array_first()`/`array_last()`: they only arrive in PHP 8.5.
- `Filter/CategoryMultiSelectConditionProcessor.php:41`: `$concatenator == 'AND' ? false : true` becomes `$concatenator !== Concatenator::And`.
- `Getter/FieldCollectionGetter.php:46-56`: the loop that collects matching items becomes `array_filter($collectionContainer->getItems(), static fn (object $item): bool => $item instanceof $className)`. Use `array_find()`/`array_any()` (PHP 8.4) where a loop only looks for one match.
- Use first-class callable syntax (`trim(...)`, `is_numeric(...)`) instead of `static function (string $value) { return trim($value, ','); }` closures where it fits (`MultiselectFilterConditionFromMultiselectProcessor.php:62-64`).

### I-17 — `README.md`: document the component instead of the generic boilerplate
- **Where:** `README.md` (only the generic CoreShop text).
- **What:** document the condition semantics (what `InArrayCondition`, `IsCondition` and the `Not*` variants mean on each worker, TODO-2 **B-14**), the filter condition configuration keys per type, the difference between pre-conditions and user conditions (TODO-2 **B-01**), the interpreter chain rules (localized and relational, TODO-2 **B-09**/**B-10**), and the `EMPTY_STRING` request contract.

### I-18 — Unit tests for the component
- **Where:** there are no tests under `Component/Index`. Behat (`Behat/Context/Setup/IndexConditionContext.php`) only covers the happy path of building conditions.
- **What to cover first:**
  - Every filter processor with absent, empty, `EMPTY_STRING`, scalar and array input, as a user condition and as a pre-condition (TODO-2 **B-01** to **B-07**).
  - The interpreter chains: `objectProperty → object` relational, `iterator(localeMapping)`, `nestedLocalized` (TODO-2 **B-09**, **B-10**).
  - `TimestampInterpreter` on Carbon 3 (TODO-1 **C-02**), and `SoundexInterpreter` edge cases (TODO-2 **B-12**).
  - `ConcatCondition` operator validation (TODO-1 **C-01**) and `LikeCondition` pattern validation (TODO-2 **B-13**).
  - `IndexUpdaterService` with one broken index among healthy ones (TODO-2 **B-16**).
  - `FilterGroupHelper` with float and bool values (TODO-2 **B-15**).
- Run PHPStan level 8 (after I-14) on the component in CI.
