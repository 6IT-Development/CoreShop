# ProductBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
The scope and markers are the same as in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Paths are relative to the bundle root unless noted otherwise. `vendor/` means `../pimcore2teszt10211524/vendor/`.
These items are not bugs. They improve code quality, readability, logic and forward compatibility (PHP 8.5, Symfony 8, Doctrine ORM 3).

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Attributes

### I-01 — Collapse the 9 attribute classes into two shapes and use PHP 8.4 property syntax
- **Where:** `Attribute/*.php`
- The classes are copies of each other: five have only `type`, four have `type` + `formType`. Each has a private promoted property plus a getter.
- With PHP 8.4, use public readonly properties (`public function __construct(public readonly ?string $type = null, public readonly ?string $formType = null, public readonly int $priority = 1000)`) or asymmetric visibility (`public private(set)`). This needs `Autoconfiguration::getProperties()` to read properties instead of `get*()` methods (Registry TODO-3 **I-07**). Until then, keep the getters.
- Add `priority` to the calculator attributes (TODO-2 **B-03**).
- Consider `#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]`, so that one class can be registered under two `type`s of the same registry, which is possible in YAML today (several tags).
- Add a short class docblock to each attribute: which interface the class must implement, which registry it lands in, and what `type` defaults to (the snake-cased short class name).
- Longer term: replace the custom attributes with Symfony's native `#[AutoconfigureTag('coreshop.product_price_rule.condition', ['type' => …, 'form-type' => …])]` / `#[AsTaggedItem]`. This removes the reflection layer and fixes TODO-2 **B-01** by design. Keep the CoreShop attributes as thin BC wrappers.

### I-02 — Replace the nine copy-pasted `registerForAutoConfiguration()` blocks with a table
- **Where:** `DependencyInjection/CoreShopProductExtension.php:80-150`
- Use a `const AUTOCONFIGURATION = [[Interface::class, Pass::TAG, Attribute::class], …]` and loop over it. This makes the wrong `ProductPriceCalculatorInterface` row (TODO-1 **C-03**, TODO-2 **B-02**) easy to see and remove.
- `$configs = $this->processConfiguration($this->getConfiguration([], $container), $configs);` → `new Configuration()` is clearer.
- `array_key_exists('pimcore_admin', $configs)` / `array_key_exists('stack', $configs)` are always true because both nodes have defaults. Drop the checks or use `isset()` with a comment.

---

## Controllers

### I-03 — `ProductPriceRuleController`: inject the parameters, return `JsonResponse`
- **Where:** `Controller/ProductPriceRuleController.php`
- Use `JsonResponse` as the return type (the `viewHandler` returns one, and `ResourceController`'s actions already use it).
- `getConfigActions()` / `getConfigConditions()` read the container parameters on every call and return `array<string,string>` with key = value. Use `array_keys()` once or return a list (`array_values($map)`), and document the shape.
- The `$request` parameter of `getConfigAction()` is unused.

### I-04 — `ProductUnitDefinitionsController`: Symfony 7.4 argument resolving, one code path, no dead code
- **Where:** `Controller/ProductUnitDefinitionsController.php`
- The two actions are the same except for `'all'` / `'additional'` and the version lookup. Merge them into one private method, and replace the string `$type` with a small `enum UnitDefinitionScope: string { case All = 'all'; case Additional = 'additional'; }` or a `bool $onlyAdditional`.
- Use `#[MapQueryParameter] int $productId` (TODO-2 **B-09**) and `#[Autowire(service: 'coreshop.repository.stack.product')] StackRepositoryInterface $productRepository` instead of relying on the argument name `$coreshopProductStackRepository` for named-alias autowiring. Renaming the variable today silently breaks the action.
- The `/** @var ProductInterface $product */` docblocks are wrong: the next line checks `instanceof Concrete` and `instanceof ProductInterface`. Remove them and let the `instanceof` checks narrow the type.
- `getLatestVersion()` (`:88-104`) is dead: the action uses `VersionHelper::getLatestVersion()`. The dead copy also restores the published `modificationDate` ("to compare it in js-frontend"), which `VersionHelper` does not. Decide whether that is needed. If yes, move it into `VersionHelper` (parameter `$keepModificationDate`). Remove the method either way.
- `getUnitDefinitionsForProduct()` reimplements inheritance by walking `getParent()`. With `getAllowInherit()` enabled, the generated getter already returns the parent's value for an empty field (`DataObject::doGetInheritedValues()`). Use `InheritanceHelper::useInheritedValues(fn () => $product->getUnitDefinitions())` and drop the recursion. That also respects the inheritance flag per request.
- Return `JsonResponse` and the serializer group explicitly (`['group' => 'List']`).

---

## CoreExtension data types

### I-05 — Replace the reflection-based ID reset in `createDataCopy()` with `__clone()` in the models
- **Where:** `CoreExtension/ProductSpecificPriceRules.php:130-171`, `CoreExtension/ProductUnitDefinitions.php:203-243`, `CoreExtension/ProductUnitDefinition.php:155-180` (14 `ReflectionProperty::setAccessible()` calls).
- `setAccessible()` has no effect since PHP 8.1 and is deprecated in PHP 8.5. Drop the calls in any case.
- Better: give `ProductSpecificPriceRule`, `ProductUnitDefinitions`, `ProductUnitDefinition` and the rule `Condition` / `Action` models a `__clone()` that resets `id`, deep-clones the owned collections (conditions, actions, translations, unit definitions) and fixes the back-references. `createDataCopy()` then becomes `array_map(static fn ($r) => clone $r, $data)`. This also fixes TODO-2 **B-13** and **B-15** in one place.

### I-06 — Remove dead and copy-pasted code from `ProductUnitDefinitions`
- **Where:** `CoreExtension/ProductUnitDefinitions.php`
- `:56-137` `$width`, `$defaultValue`, `getWidth()`/`setWidth()`, `getDefaultValue()`/`setDefaultValue()` and `:493-500` `toNumeric()` are copied from a numeric data type. A unit-definitions field has no width setting in its JS definition and no meaningful default value. Check `Resources/public/pimcore/js/coreExtension/data/coreShopProductUnitDefinitions.js` and remove what is not used. Existing class definitions that contain the keys are still loaded, because Pimcore ignores unknown keys, but verify it.
- `:139-147` `getQueryColumnType()` / `getColumnType()` return `false` with no return type. The class implements neither persistence-aware interface, so they are never called. Remove them.
- `:149-195` 47 lines of commented-out getter/setter code generation. Remove it (git keeps the history).
- `:303-306` `getDataFromResource()` returns `[]`, and is never called for the same reason. Remove it. The same applies to `ProductSpecificPriceRules.php:196-199`.
- `:66` `public string $phpdocType = 'array'` contradicts `getPhpdocReturnType()` (`?ProductUnitDefinitionsInterface`). Fix or remove.
- `ProductSpecificPriceRules.php:57-61` `//private $formFactory;` and the untyped `$height` (used by the JS layout?) → remove the comment, type the property `public ?int $height = null`.

### I-07 — Native types everywhere
- **Where:** all four `CoreExtension/*.php`
- Missing return types: `ProductSpecificPriceRules::getDataFromResource()`, `ProductUnitDefinition::createDataCopy()`, `ProductUnit::getRepository()`, `ProductUnitDefinition::getRepository()`, and the private `getContainer()`, `getEntityManager()`, `getSerializer()`, `getFormFactory()`, `getProductUnitDefinitionsRepository()`, `getProductUnitDefinitionsRepositoryFactory()`, `getConfigActions()`, `getConfigConditions()`. Replace the `@return` docblocks with native types (`EntityManagerInterface`, `SerializerInterface`, `FormFactoryInterface`, `RepositoryInterface`, `array`).
- `ProductUnitDefinitions::toNumeric($value)` → `mixed $value` (or remove, I-06). `expandDotNotationKeys(array $array)` → `: array`, and document the shape (`array<string, mixed>`).
- Use the imported `Model\…` aliases instead of the long FQCN union in the signatures of `ProductSpecificPriceRules::save()/load()/delete()/getForCsvExport()` (`ProductUnitDefinitions` already uses `use Pimcore\Model;`). `ProductUnitDefinitions.php:453` uses the FQCN of an interface that is already imported.
- Remove the `@psalm-suppress InvalidReturnType, InvalidReturnStatement` class-level suppressions once the types are correct.

### I-08 — One helper for the form-submission / error-flattening code
- **Where:** `CoreExtension/ProductSpecificPriceRules.php:316-336`, `CoreExtension/ProductUnitDefinitions.php:426-446`
- Both blocks are identical. Extract them to a trait or a small service (`FormDataMapper::submitOrFail(string $type, mixed $data, mixed $entity): mixed`). Use `ResourceBundle`'s `FormErrorSerializer` (already used by `ResourceController`) instead of hand-written message building, and throw `Pimcore\Model\Element\ValidationException`. This fixes TODO-2 **B-11**.

### I-09 — Fix the `conversationRate` key in the edit-mode data
- **Where:** `CoreExtension/ProductUnitDefinition.php:222-241`; `Resources/public/pimcore/js/coreExtension/tags/coreShopProductUnitDefinition.js:42`
- The key is a typo of `conversionRate`. The JS reads the typo. Emit both keys for one release, switch the JS to `conversionRate`, then drop the typo.
- The empty default array lacks the label keys that the filled array has (`fullLabel`, …). Return the same keys in both cases (nullable values), so the JS does not need to guard each of them.

### I-10 — Version and cache marshalling: consistent, cheaper, readable
- **Where:** `CoreExtension/ProductSpecificPriceRules.php:201-264`, `CoreExtension/ProductUnitDefinitions.php:246-301`, `CoreExtension/ProductUnitDefinition.php:182-214`
- `ProductSpecificPriceRules::marshalVersion()` uses `setSerializeNull(true)`, `ProductUnitDefinitions::marshalVersion()` uses `false`. Pick one (`true` keeps explicit `null`s when restoring) and document why.
- `marshalVersion()` creates a new `SerializationContext` per rule inside the loop. Create it once, or serialize the whole array in one call.
- `unmarshalVersion()` reuses `$data` as the loop result variable (`ProductSpecificPriceRules.php:238`), shadowing the parameter. Rename it to `$entity`.
- `unmarshalForCache()` creates a new temporary `EntityManager` (`TempEntityManagerTrait`) on **every** cache hit of a product. Each one builds a new `UnitOfWork` and proxy factory. Cache one temporary EM per request, or deserialize without an EM for cache data (the entities are read-only there).
- `ProductUnitDefinition::marshalVersion()` stores the edit-mode array (labels and so on) while `unmarshalVersion()` only needs `id`. Store `['id' => …]`, so that versions do not grow with label data.
- `ProductUnitDefinition::marshalForCache()`/`unmarshalForCache()` do a repository `find()` per cache load. Since the field is a simple int column, it could cache only the ID and let `preGetData()` resolve it lazily.

### I-11 — `ProductUnitDefinition::preGetData()` reloads the entity from the DB on every getter call
- **Where:** `CoreExtension/ProductUnitDefinition.php:103-126` ("Dirty Fix, Pimcore sometimes calls properties without getter")
- Every `getXxx()` of the field calls `find()` and `setValue()`. The identity map makes repeated `find()`s cheap, but `setValue()` on every read can mark the object dirty. Track whether the entity was already refreshed (for example with the lazy-loaded-keys mechanism like the two collection types), and explain in the comment which Pimcore path reads the property directly.

### I-12 — Equality and dirty detection for the rules field
- **Where:** `CoreExtension/ProductSpecificPriceRules.php:53` (`SimpleComparisonTrait` → `===`), `:104-107` (`preGetData()` calls the setter)
- `===` between two arrays of entity objects is only true for the very same instances, so the field is dirty after every editor save and after the first read (`preGetData()` → setter → `isEqual()` → `markFieldDirty()`). Implement `isEqual()` by comparing IDs plus a serialized hash, or skip the setter in `preGetData()` and assign the object var directly (`$object->setObjectVar($name, $data)` + `markLazyKeyAsLoaded()`), as Pimcore's own lazy relation types do.
- `ProductUnitDefinitions` does not implement `EqualComparisonInterface` at all. Make the two types consistent.

### I-13 — Service location: one access point for the container
- **Where:** all four `CoreExtension/*.php` (`\Pimcore::getContainer()->get(…)`)
- Pimcore instantiates data types itself, so constructor injection is not possible. Still, put the lookups in one trait (for example `CoreShopServiceLocatorTrait` with typed accessors), so the service IDs are in one place and can be checked by a container lint test. `ProductSpecificPriceRules` has a private `getContainer()`, `ProductUnitDefinitions` calls `\Pimcore::getContainer()` six times.
- The service IDs used here (`coreshop.repository.product_unit_definition`, `coreshop.repository.factory.product_unit_definitions`, `coreshop.form.factory`, `jms_serializer`) must be public. Document that, or add a small service locator (`ServiceLocator` tagged `container.service_locator`) and fetch only that one service.

### I-14 — Column types, CSV export and grid output
- **Where:** `CoreExtension/ProductUnitDefinition.php:72-80` (`int(11)`), `ProductSpecificPriceRules.php:399-402` / `ProductUnitDefinition.php:256-259` (`getForCsvExport()` returns `''`)
- MySQL 8.0.17+ deprecates integer display width. Use `'int(11)'` only if Pimcore's schema diff needs it for existing installs, otherwise `'int'`. Consider `'int unsigned'` to match the referenced auto-increment ID.
- Implement a meaningful CSV export and grid value (`getDataForGrid()`, `getVersionPreview()`): for the rules, for example, `"<name> (<active>)"` per rule. For the single unit definition, `"<unit> × <rate>"`. `ProductUnitDefinitions::getVersionPreview()` already shows the format to follow.

### I-15 — (dependent) Prepare for Doctrine ORM 3
- **Where:** `CoreExtension/ProductSpecificPriceRules.php`, `CoreExtension/ProductUnitDefinitions.php` (`EntityMerger`, `TempEntityManagerTrait`); `Resources/config/doctrine/model/ProductUnitDefinitions.orm.xml` (`<cascade-merge/>`)
- `TempEntityManagerTrait` uses `EntityManager::create()`, which is deprecated in ORM 2.x and removed in ORM 3 (use `new EntityManager($connection, $config, $eventManager)`).
- `EntityMerger` reimplements the removed `EntityManager::merge()` with internal `UnitOfWork` calls (`registerManaged`, `removeFromIdentityMap`). `<cascade-merge/>` is removed from the ORM 3 XSD.
- Plan to replace "deserialize into a temp EM, then merge into the real EM" with "load the managed entities from the real EM and let the form map onto them". That also removes the need for a temporary EM per call (I-10) and for the global `flush()` (TODO-2 **B-21**).

---

## Configuration

### I-16 — Small config clean-ups
- **Where:** `Resources/config/pimcore/admin.yml:19` (`/specificprice//condition.js`, double slash); `Resources/config/pimcore/routing.yml` (add `no_default_routes` / `only:` for every resource, not only `product_unit_definitions`, TODO-1 **C-01**); `DependencyInjection/Configuration.php:157` (commented `admin_controller` for `product_specific_price_rule`), `:271` (commented `repository` for `product_unit_definition`); `Resources/config/services.yml:146-148` (trailing blank lines).
- The specific price rules have no admin controller and no routes, which is correct (they are edited inside the product). Remove the commented line, or replace it with a comment that explains why.
- Mark the ProductBundle services `public: false` where they are not fetched through the container (`_defaults: public: true` in `services.yml:9-10` makes all of them public). The CoreExtension classes need some of them public (I-13). List those explicitly.

### I-17 — `composer.json`: declare PHP 8.4 and the libraries used directly
- **Where:** `composer.json`
- Add `"php": "^8.4"`.
- The scoped code uses `symfony/form`, `symfony/http-foundation`, `symfony/dependency-injection`, `symfony/config`, `doctrine/orm`, `doctrine/collections`, `jms/serializer` and `webmozart/assert` directly. Declare them (with `^7.4` for Symfony) instead of relying on transitive requirements.
- `phpstan/phpstan` `^1.10` → `^2.1` (and the matching extensions), in line with the rest of the monorepo.

---

## Tests

### I-18 — Add tests for the attributes, controllers and data types (none exist)
- Container tests (compile a `ContainerBuilder` with the extension + passes):
  - Each of the nine attributes lands in the right registry, with `type`, `priority` and `form-type` (TODO-2 **B-01**, **B-03**).
  - An autoconfigured decorator of `ProductPriceCalculatorInterface` is **not** in any calculator registry (TODO-1 **C-03**).
  - `autoconfigure_with_attributes: 1` is rejected by the config, not by a `TypeError` (TODO-2 **B-04**).
  - The autowiring alias for `ProductCustomAttributesCalculatorInterface` exists (TODO-2 **B-05**).
- Functional tests (admin client):
  - The `product_unit_definitions` default CRUD routes are not registered, and the two list actions deny users without `view` rights (TODO-1 **C-01**, TODO-2 **B-07**).
  - Posting product A with a rule ID of product B leaves product B unchanged (TODO-1 **C-02**).
- Unit / kernel tests for the data types:
  - Draft round trip for specific price rules: save draft → reopen → edit-mode data equals the draft (TODO-2 **B-10**).
  - `getDataFromEditmode()` with a `Length` violation returns a readable validation error (TODO-2 **B-11**).
  - Copying a product copies rules with their translations and unit definitions without a default (TODO-2 **B-13**, **B-15**).
  - Clearing the unit definitions deletes the stored row (TODO-2 **B-14**).
  - `expandDotNotationKeys()` with nested and conflicting keys.

---

# Second scan: `DependencyInjection`, `Doctrine`, `EventListener`, `Pimcore`

Scope and method: see the "Second scan" paragraph in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md).

## DependencyInjection

### I-19 — Compiler passes: clean constructors, constants for every tag, autoconfiguration for rule fetchers
- **Where:** `DependencyInjection/Compiler/*.php`, `CoreShopProductBundle.php:46-60`, `Resources/config/services/rules.yml:5-15`
- All nine passes have an empty constructor written as `public function __construct(\n        ) {` (left over from an automated refactor). Use a plain `public function __construct()` or drop the constructor and pass the values through `parent::__construct()` in a single place — or replace the nine subclasses with instances of the generic pass (`new class(...)`, or a small factory), see Registry TODO-3 **I-08**.
- `ProductValidPriceRuleFetcherPass` is the only pass without a `…_TAG` constant. Its tag `coreshop.product.rules.fetcher` is also the id of a service alias (`rules.yml:12`) and almost the same as the parameter `coreshop.product.rules.fetchers`. Add `PRODUCT_RULES_FETCHER_TAG`, and consider renaming the tag to `coreshop.product.rules_fetcher` (keep the old tag for BC).
- `ValidRulesFetcherInterface` is not autoconfigured, unlike the calculators and rule elements. A custom fetcher is silently ignored unless it is tagged by hand. Add it to the autoconfiguration table (I-02), with an `#[AsProductValidRulesFetcher]` attribute if the attribute mode is kept.
- The fetcher registry is a plain `ServiceRegistry`, so the order of fetchers (price rules vs specific price rules) is the tag discovery order. Make it a `PrioritizedServiceRegistry` if the order matters for `CompositeValidRuleFetcher`.

### I-20 — Extension and bundle: Symfony 7.4 idioms
- **Where:** `DependencyInjection/CoreShopProductExtension.php`, `DependencyInjection/Configuration.php`, `CoreShopProductBundle.php`
- `processConfiguration($this->getConfiguration([], $container), $configs)` → `processConfiguration(new Configuration(), $configs)`.
- `$configs['…']` naming: after processing it is one array, so call it `$config`.
- `->prototype('scalar')` → `->scalarPrototype()` (`Configuration.php:389`, `:393`, `:397`, `:401`).
- `Configuration.php` (412 lines) repeats the same resource block (options / graphql / classes / translation) for every model. Extract `addResourceNode(string $name, array $classes, ?array $translation, ?string $permission)` helpers. This also makes the missing `permission` for `product_unit_definitions` (TODO-1 **C-01**) visible.
- `manufacturer.classes.repository` is `cannotBeEmpty()` without a default, while `product` and `category` have a default repository. Give it a default (`PimcoreRepository::class`) or document why it has none.
- The `stack` node accepts any string. Validate that each value is an existing interface (`->validate()->ifTrue(fn ($v) => !interface_exists($v))->thenInvalid(…)`), since `StackRepositoryPass` builds class lists from it.
- Longer term (Symfony 7.x): convert to `AbstractBundle` with `configure()` / `loadExtension()` and drop the separate extension and configuration classes, if `AbstractResourceBundle` allows it (dependent: ResourceBundle).

## Doctrine repositories

### I-21 — Typed results, DQL portability, abstract base
- **Where:** `Doctrine/ORM/*.php`
- Add generics so callers get real types: `/** @return list<ProductSpecificPriceRuleInterface> */` on `findForProduct()`, `/** @extends EntityRepository<ProductUnit> */` etc. (PHPStan/Psalm).
- `o.active = 1` (`PriceRuleRepository.php:29`, also `RuleRepository`) → `o.active = :active` with `true`, or `o.active = true`. The integer literal works on MySQL only by accident of the type mapping.
- `PriceRuleRepository` is only a base class. Make it `abstract`. `ProductPriceRuleRepository` is empty; keep it only as an extension point and say so in a comment.
- `ProductUnitDefinitionsRepository::findOneForProduct()` / `ProductSpecificPriceRuleRepository::findForProduct()` only use the ID. Offer `findOneForProductId(int $id)` / `findForProductId(int $id)` (TODO-2 **B-25**). For an unsaved product (`getId() === null`) return `null` / `[]` without a query.
- `ProductUnitRepository::findByName()` relies on the unique index on `name` (`ProductUnit.orm.xml:12`). Say so, or use `findOneBy(['name' => $name])`. `createListQueryBuilder()` returns a bare builder; add the default order (`o.name ASC`) that the admin list expects.
- The `findForProduct()` ordering (`priority ASC`) is the processing order of specific price rules. Document in the interface that lower priority means "applied first", so that it is not changed by accident.

## Event listeners

### I-22 — Register the listeners with attributes and the Pimcore event constants
- **Where:** `EventListener/*.php`, `Resources/config/services.yml:136-145`
- Use `#[AsEventListener(event: DataObjectEvents::PRE_UPDATE)]` (and `PRE_ADD`, TODO-2 **B-23**) on the methods instead of string event names in YAML. Type the argument as `DataObjectEvent` directly; the `instanceof DataObjectEvent` check and the extra nesting go away.
- `CategoryPersistEventListener`: only call `setParentCategory()` when the value actually changes, so that an unchanged category is not marked dirty on every save (and its versions do not grow).

### I-23 — `ProductDeleteListener` duplicates `ProductSpecificPriceRules::delete()` and flushes the whole EM once per deleted object
- **Where:** `EventListener/ProductDeleteListener.php:35-52`; `CoreExtension/ProductSpecificPriceRules.php:386-397` (Pimcore already calls the data type's `delete()` from `Concrete\Dao::delete()`).
- For a product class that has the field, the rules are already gone when `postDelete` runs; the listener queries again and calls `flush()` on the default EM, which writes any other pending changes (same issue as TODO-2 **B-21**). Deleting a product with many variants runs this once per variant.
- It does not clean up `ProductUnitDefinitions`, so the cleanup is inconsistent between the two Doctrine-backed data types.
- Decide on one mechanism: either keep the cleanup in the data types and drop the listener, or make the listener the single place for both entity types and use a bulk DQL `DELETE … WHERE o.product = :id` (no hydration, no global flush). Note that `postDelete` runs after Pimcore's transaction; a failure there leaves orphaned rows that no longer have a product.

## Pimcore repositories

### I-24 — `CategoryRepository`: no raw SQL fragments, shared with CoreBundle
- **Where:** `Pimcore/Repository/CategoryRepository.php`; `CoreBundle/Pimcore/Repository/CategoryRepository.php` (dependent)
- `sprintf('`%s` ASC', $category->getChildrenSortBy())` interpolates an object property into `ORDER BY`. The value is set from the admin and Pimcore does not validate it in `setChildrenSortBy()`. Whitelist it (TODO-2 **B-24**) instead of quoting by hand.
- The CoreBundle subclass copies the sorting code and builds `stores LIKE "%,<id>,%"` by concatenation. Move the sort logic into a protected helper here (`applyChildrenOrder(Listing $list, CategoryInterface $category)`) and use it in both. The `stores` condition should use a bound parameter (`stores LIKE ?`, `['%,' . $id . ',%']`).
- Add return types for the list results (`/** @return list<CategoryInterface> */`).
- `ProductRepository` is empty; keep it as an extension point but say so in a comment.

## Tests

### I-25 — Tests for this scan
- `Configuration`: `permissions` as a list and the boolean `autoconfigure_with_attributes` (TODO-2 **B-04**, **B-22**); `stack` validation (I-20).
- `CategoryPersistEventListener`: a category created and published with one `save()` under a parent category has `parentCategory` set (TODO-2 **B-23**).
- `CategoryRepository`: `findChildCategories()` respects `key`/`index` and `ASC`/`DESC`; `findFirstLevel()` is ordered (TODO-2 **B-24**).
- `PriceRuleRepository::findActive()`: query count stays constant with N rules (TODO-2 **B-26**).
- Deleting a product removes its specific price rules and unit definitions without flushing unrelated entities (I-23).

### I-26 — `composer.json`: declare the dependencies used by this scan's code
- **Where:** `composer.json`
- `Doctrine/ORM/*` and `EventListener/ProductDeleteListener.php` use `doctrine/orm`; `PriceRuleRepository` extends `CoreShop\Bundle\RuleBundle\Doctrine\ORM\RuleRepository` (`coreshop/rule-bundle` is declared). `CategoryPersistEventListener` and `Pimcore/Repository/*` use Pimcore event and listing classes (`six-it/pimcore-source`, declared). Add `doctrine/orm` and `symfony/dependency-injection` / `symfony/config` together with **I-17**.

---

# Third scan: `Form`

Scope and method: see the "Third scan" paragraph in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md).

### I-27 — Collapse the sixteen near-identical rule form types
- **Where:** `Form/Type/Product{,Specific}PriceRule{Action,Condition}{Type,CollectionType,ChoiceType}.php` (12 files), `Form/Type/Product{,Specific}PriceRuleTranslationType.php`, `Form/Type/Rule/Condition/Product{,Specific}PriceNestedConfigurationType.php`; `Resources/config/services/{price-rules,specific-price-rules}.yml`
- Each "specific" class is the "price rule" class with another child type and block prefix (spot-checked with `diff` for the action types and the condition collection types). Keep one parameterised type per role and configure the variants as services (registry, form registry, entry type and block prefix as options or constructor arguments). This halves the code and keeps the two rule kinds from drifting apart.
- Make all of them `final` or none: today the four `*ChoiceType`, the two `*ConditionCollectionType` and the two unit collection types are not final while their siblings are.
- Block prefixes must be unique: both nested types return `coreshop_rule_condition_nested`, which is also RuleBundle's prefix, so a form theme for one changes all three. Use `coreshop_product_price_rule_condition_nested` / `coreshop_product_specific_price_rule_condition_nested`.
- `ProductPriceNestedConfigurationType.php:44` passes `'constraints' => new Valid(…)`, the specific variant `[new Valid(…)]`. Use the array form in both.

### I-28 — Translation types extend `RuleType`
- **Where:** `Form/Type/ProductPriceRuleTranslationType.php`, `Form/Type/ProductSpecificPriceRuleTranslationType.php`
- They inherit from `RuleBundle`'s `RuleType` but override everything; a translation is not a rule. Extend `AbstractResourceType` (as `Unit/ProductUnitTranslationType.php` does). Add `Length(max: 255)` on `label` if the column is a default string.

### I-29 — Configuration types: PHP 8.4 style and shared transformers
- **Where:** `Form/Type/Rule/Action/*.php`, `Form/Type/Rule/Condition/*.php`
- `protected array $validationGroups` in `final` classes → `private readonly array $validationGroups` (promoted). Same for `protected` constructor properties in `Unit/ProductUnitChoiceType.php`, `Unit/ProductUnitDefinitionSelectionType.php`, `Unit/ProductUnitDefinitionPriceType.php`.
- The currency `CallbackTransformer` is copy-pasted in two types (and wrong in one direction, TODO-2 **B-34**). Move it to one reusable transformer class (ID ↔ resource), or let `CurrencyChoiceType` take an `as_id` option.
- `TimespanConfigurationType.php:38-39`: remove the `//TODO: Mabye DateType?` comments (typo) and decide: the stored value is a millisecond timestamp used by the JS date picker and the checkers, so `IntegerType` (or `NumberType` with `scale: 0`) states that better than a plain `NumberType`. Document the unit (ms) in a docblock.
- `DiscountPercentConfigurationType`: `Range(min: 0, max: 100)` makes `Type(numeric)` redundant after `NumberType` transformation; `Type` only adds a second error for the same input.

### I-30 — Unit form types: clean options and types
- **Where:** `Form/Type/Unit/*.php`
- `ProductUnitDefinitionsChoiceType`: drop the `entry_type` option (not a `ChoiceType` option; it only exists because it is set as a default), declare `product` as required and typed (TODO-2 **B-31**), and use `static fn` closures.
- `ProductUnitDefinitionCollectionType` / `ProductUnitDefinitionPriceCollectionType` call `parent::configureOptions()` on `AbstractType` (no-op). Remove the call, make the classes `final`.
- `ProductUnitDefinitionPriceType`: the constructor takes `$decimalFactor` and `$decimalPrecision` (wired in `units.yml` from `%coreshop.currency.decimal_*%`) but never uses them. Remove both from the class and the service definition, or pass them to `MoneyType` if that was the intention.
- `ProductUnitChoiceType` loads all units with `findAll()` in each form build. Pass `choice_loader` (`ChoiceList::lazy()`), so the query only runs when the choices are needed, and sort by name.

### I-31 — `ProductUnitDefinitionsType`: replace the `SUBMIT` listener with a data mapper
- **Where:** `Form/Type/Unit/ProductUnitDefinitionsType.php:47-70`
- The listener mixes three things: default-unit replacement, merging submitted additional definitions into existing ones by unit name, and forcing the collection through `PropertyAccess::createPropertyAccessor()` (created on every submit). A `DataMapperInterface` implementation (`mapFormsToData()`) expresses this directly, runs only on transformed data (fixes TODO-2 **B-28** structurally) and can be unit-tested.
- Inject a `PropertyAccessorInterface` (or call `addAdditionalUnitDefinition()` / `removeAdditionalUnitDefinition()` directly) instead of creating one.
- The `/** @var */` docblocks hide that `$additionalUnitDefinitions` can be `null`; type-check instead.
- **(dependent)** `Component/Product/Model/ProductUnitDefinitions.php` `addAdditionalUnitDefinition()` / `removeAdditionalUnitDefinition()` compare a `ProductUnitInterface` with a `ProductUnitDefinitionInterface` (`$productUnit === $defaultDefinitionUnit`, where `$defaultDefinitionUnit = $defaultDefinition`), so the "skip the default unit" guard never triggers. The form relies on that behaviour by accident; fix both together.

### I-32 — Service configuration for the forms
- **Where:** `Resources/config/services/{form,price-rules,specific-price-rules,units}.yml`
- Unused parameters: `coreshop.form.type.product_price_rule.rule.validation_groups`, `…product_price_rule.promotion_coupon.validation_groups` and the two `product_specific_price_rule` counterparts (one occurrence each = only the definition). Remove them.
- `coreshop.form.type.product_price_rule.condition.timespan.validation_groups` is also used for specific price rules; name it neutrally (`…rule.condition.timespan…`).
- Form types do not need to be public (`_defaults: public: true`). With `autowire`/`autoconfigure` (or `_instanceof: Symfony\Component\Form\FormTypeInterface: tags: [form.type]`) most of the ~30 explicit definitions reduce to argument bindings.

### I-33 — Naming and field types in the rule forms
- **Where:** `Form/Type/ProductPriceRuleType.php:36`, `Form/Type/ProductSpecificPriceRuleType.php:36`, `Form/Type/ProductSelectionType.php`
- `name` is a `TextareaType` while the base `RuleType` uses `TextType`; the admin JS sends a single line. Use `TextType` (together with TODO-2 **B-35**).
- The field order differs between the two rule types (`priority` before/after the flags). Align it, so the generic type from I-27 can build both.
- `ProductSelectionType` uses the block prefix `coreshop_product`, which reads like the product form itself. Use `coreshop_product_selection`.

### I-34 — Form tests
- `TypeTestCase` tests for every configuration type (valid/invalid input, including TODO-2 **B-32**, **B-33**), the currency transformer round trip (**B-34**), `ProductUnitDefinitionSelectionType` with unknown and foreign IDs (**B-30**), `ProductUnitDefinitionsType` with: new additional unit, changed default unit, removed unit, unit missing (**B-28**), and the nested types with a partial submit (**B-36**).
