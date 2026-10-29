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
