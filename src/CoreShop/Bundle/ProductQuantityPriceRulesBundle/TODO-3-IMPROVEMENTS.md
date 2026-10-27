# ProductQuantityPriceRulesBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, performance, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items (Symfony 8, Doctrine ORM 3). None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as sub-bullets.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(verified)** were confirmed by reading the vendored library source or the compiled container.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — `composer.json` does not describe what the bundle needs
- **Where:** `composer.json:32-42`.
- **Problem / Fix:**
  - No `php` constraint. Add `"php": "^8.4"`.
  - Directly used packages are not declared: `symfony/form`, `symfony/validator`, `symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel`, `symfony/event-dispatcher` (+ `-contracts`), `symfony/options-resolver`, `twig/twig`, `doctrine/orm`, `doctrine/collections`, `jms/serializer-bundle`, `webmozart/assert`, `stof/doctrine-extensions-bundle` / `gedmo/doctrine-extensions` (Timestampable in `ProductQuantityPriceRule.orm.xml`), `coreshop/resource-bundle`, `coreshop/registry`. Add them with `^7.4` for Symfony.
  - `coreshop/product-bundle` is a hidden hard dependency (services `coreshop.registry.product_specific_price_rule.conditions` / `coreshop.form_registry.product_specific_price_rule.conditions`, JS `coreshop.product.pricerule.conditions.nested`). Remove it with TODO-2 **B-01** for the PHP side; the JS dependency stays, so declare it or copy the nested condition JS.
  - `coreshop/money-bundle` is required but not used by this bundle (only by CoreBundle's form extension); move it to where it is used.
  - Optional integration: `pimcore/data-hub` → `suggest` (`services/data_hub.yml`).
  - `require-dev` pins PHPStan 1.x; PHPStan 2 is current and has the PHP 8.4 rules. Bump together with the other bundles.
  - `"minimum-stability": "dev"` and `repositories` (`path` `../../*/*`) in a library package have no effect for consumers; drop them or move them to the root project.

### I-02 — Bundle, extension and configuration: use the Symfony 7.x APIs
- **Where:** `CoreShopProductQuantityPriceRulesBundle.php`, `DependencyInjection/CoreShopProductQuantityPriceRulesExtension.php`, `DependencyInjection/Configuration.php`, `DependencyInjection/Compiler/*.php`.
- **Fix:**
  - `Configuration.php:49,116,120,124,128`: `->prototype('scalar')` → `->scalarPrototype()`. `:37` the `/** @var ArrayNodeDefinition */` is not needed (`TreeBuilder::getRootNode()` is generic-typed in 7.x). `:48` `useAttributeAsKey('name')` on `groups` is wrong for a plain list (it keys list entries by index anyway); use `->scalarPrototype()` only.
  - `Configuration.php:42,130-133`: node types, see TODO-2 **B-03**.
  - `CoreShopProductQuantityPriceRulesExtension.php:41`: `$configs = $this->processConfiguration(...)` overwrites the input with a different shape; name it `$config`.
  - `:49-53`: bundle detection by the `kernel.bundles` key works; `ContainerBuilder::willBeAvailable('pimcore/data-hub', PimcoreDataHubBundle::class, [])` or `class_exists()` makes the intent clearer and also handles a removed package.
  - `:57-79`: three nearly identical `Autoconfiguration::registerForAutoConfiguration()` calls → loop over a `[interface, tag, attribute]` list.
  - `Compiler/*Pass.php:27-28`: empty constructors formatted as `__construct(\n        )`. Pass the values as constants or use a one-line `public function __construct()`.
  - Consider `AbstractBundle` (`configure()` / `loadExtension()`) for the next major once `AbstractResourceBundle` supports it (dependent).

### I-03 — Doctrine ORM 3 forward compatibility — (verified) (dependent: ResourceBundle)
- **Where:**
  - `ResourceBundle/CoreExtension/TempEntityManagerTrait.php` uses `EntityManager::create()`; deprecated in ORM 2.x (`vendor/doctrine/orm/src/EntityManager.php:993`), removed in ORM 3. Used by `CoreExtension/ProductQuantityPriceRules.php:230,322`.
  - `CoreExtension/ProductQuantityPriceRules.php:441` docblock `@throws \Doctrine\ORM\ORMException` (class removed in ORM 3).
  - `EntityMerger` relies on `UnitOfWork::registerManaged()` / `removeFromIdentityMap()` internals.
- **Fix:** `new EntityManager($conn, $config, $eventManager)` in the trait; remove the stale docblock. Longer term remove the temp EM completely (see **I-07**, **I-09**).

### I-04 — PHP 8.4 modernisation
- **Where / Fix:**
  - `CoreExtension/ProductQuantityPriceRules.php:155-195`: seven `ReflectionProperty::setAccessible(true)` calls; it is a no-op since PHP 8.1. Remove them (or the whole reflection block, see **I-11**).
  - `CoreExtension/ProductQuantityPriceRules.php:57-60`: `public $height;` untyped with `@var int` → `public ?int $height = null;` (check the Pimcore class-definition serializer accepts it).
  - Missing return/param types: `markAsLoaded($object)` (`:429`), `checkForRangeOrphans()` (`:443`, returns `ProductQuantityPriceRuleInterface`), all private getters `:485-552`, `ProductQuantityPriceRuleRepository::findWithConditionOfType($conditionType)` / `findWithActionOfType($actionType)`.
  - `Attribute/*.php`: `private ?string` + getters → `public readonly ?string` promoted properties (`final readonly class` is fine for attributes). Keep the getters only if `Autoconfiguration::getProperties()` keeps relying on them (dependent).
  - `Event/ProductQuantityPriceRuleValidationEvent.php`: constructor property promotion; the `$object` property duplicates `GenericEvent::$subject` (see **I-13**).
  - `Form/Type/ProductQuantityRangeType.php:66` / `Form/Type/Rule/Condition/ProductQuantityPriceRuleNestedConfigurationType.php:37`: closures that do not use `$this` → `static fn` / `static function`.
  - Component models (`Component/ProductQuantityPriceRules/Model/*`) have untyped properties and untyped setters (`setName($name)`, `setPriority($priority)`, `setProduct($product)`, `setRule($rule)`): add types together with the interfaces (dependent, BC break → next major).

### I-05 — Service visibility and service location in the field type
- **Where:**
  - `Resources/config/services/product-quantity-price-rules.yml:6-8` and `services/rules.yml:1-3`: `_defaults: public: true` makes every form type, registry, fetcher and detector public.
  - `CoreExtension/ProductQuantityPriceRules.php:485-552` fetches seven services and three parameters from `\Pimcore::getContainer()` (needs them public), and reads the same parameter twice (`:282` and `getConfigActions()` `:549-552`).
- **Fix:** Pimcore data types cannot get constructor injection. Add one public helper service (e.g. `ProductQuantityPriceRulesFieldService`) with everything injected (EM, repository, repository factory, form factory, serializer, event dispatcher, parameters) and let the field type fetch only that. Then drop `public: true` from the defaults and mark only what really needs to be public. Use the interface types in docblocks (`EntityManagerInterface`, `SerializerInterface`, `FormFactoryInterface`).

## Performance

### I-06 — Every product load, save and cache hit does more DB work than needed
- **Where / Fix:**
  - `Resources/config/doctrine/model/ProductQuantityPriceRule.orm.xml:15`: `product` has no index, but `findForProduct()` (`Doctrine/ORM/ProductQuantityPriceRuleRepository.php:36-44`) filters on it on every lazy load and on every save. Add `<indexes><index columns="product"/></indexes>` + a migration.
  - `findForProduct()` loads ranges and conditions lazily (`fetch="LAZY"`): one query for rules, then one per rule for ranges and one per rule for conditions. Fetch-join both (`leftJoin('o.ranges', 'r')->addSelect('r')->leftJoin('o.conditions', 'c')->addSelect('c')`).
  - `CoreExtension/ProductQuantityPriceRules.php:367-402` `save()` re-merges, persists and flushes all rules on **every** object save, even when the field was not touched (it ignores `$params['isUntouchable']`, and the field has no dirty detection). Skip when the field is untouched / not loaded; flush only the rule entities instead of the whole shared EM (a global `flush()` also writes unrelated pending changes of other code).
  - `:259-267` `unmarshalForCache()`: every cache hit of a product creates a new `EntityManager` and `VersionObjectConstructor` loads every rule and range by id from the DB again (dependent: ResourceBundle). The cache therefore saves no queries for this field. Either cache plain arrays and hydrate without the DB, or do not marshal this field into the cache at all and let it lazy-load (with the fetch-join above it is one query).
  - `Twig/ProductQuantityPriceRuleRangesExtension.php`: rule detection runs twice per product page (`…_available()` + `coreshop_quantity_price_rule()`), see TODO-2 **B-11**.

## Logic / data model

### I-07 — Do not use a second entity manager for editmode parsing
- **Where:** `CoreExtension/ProductQuantityPriceRules.php:322-323`, `:230`.
- **Fix:** the temp EM is only there so that the form can bind onto managed entities without touching the main EM. With id-based binding (**I-09**) the form can bind onto detached copies (or onto the entities returned by `findForProduct()` cloned into DTOs), and `save()` merges them. This also removes the root cause of TODO-1 **C-01** and the EM-per-call cost in **I-06**. Rename `$specificPriceRuleRepository` (`:323`) to `$ruleRepository`; it is not a specific-price repository.

### I-08 — Doctrine mapping cleanup
- **Where:** `Resources/config/doctrine/model/*.orm.xml`.
- **Fix:**
  - `QuantityRange.orm.xml:16-18`: `rule_id` is nullable and has no `on-delete`; set `nullable="false"` and `on-delete="CASCADE"` (prevents orphan rows, TODO-2 **B-06**).
  - `QuantityRange.orm.xml:11,13`: `rangeStartingFrom` and `percentage` are `float`; a `decimal` (`precision="20" scale="6"` / `scale="4"`) avoids float compare issues when matching ranges (`2.3` stored as `2.2999…`). Needs a migration and a `string`↔`float` mapping in the model.
  - `ProductQuantityPriceRule.orm.xml:12`: give `name` an explicit `length="255"` (matches TODO-2 **B-10**).
  - `ProductQuantityPriceRule.orm.xml:15`: `product` is a plain integer with no FK to `objects`. Rules of deleted products stay when the product is deleted by SQL / `pimcore:…` bulk tools that skip `delete()` on the field. Add a cleanup command or listener on `pimcore.dataobject.postDelete` as a safety net.
  - `Component/ProductQuantityPriceRules/Model/ProductQuantityPriceRule.php`: `$inherit` property is not mapped and not used (dead), remove it (dependent).
  - `Component/ProductQuantityPriceRules/Model/ProductQuantityPriceRule.php`: `hasAction()`/`addAction()`/`removeAction()` throw `\Exception`; `findWithActionOfType()` too (TODO-2 **B-14**). Return `false`/no-op or throw `\LogicException` consistently (dependent).

### I-09 — Bind ranges by id instead of by collection index
- **Where:** `Form/Type/ProductQuantityRangeType.php` has no `id` field; `ProductQuantityRangeCollectionType` binds posted ranges by array index; `CoreExtension/ProductQuantityPriceRules.php:443-475` (`checkForRangeOrphans()`) works around it by deleting ranges whose id was not posted, flushing, and `refresh()`-ing the rule.
- **Fix:** add `->add('id', HiddenType::class, ['mapped' => false])` (as `RuleConditionType` does for conditions) and a data mapper / `PRE_SUBMIT` listener that re-keys the posted ranges by id so existing entities are updated in place, new ones are added and missing ones are removed via `removeRange()` (orphan removal). Then delete `checkForRangeOrphans()`. Fixes the root cause of TODO-1 **C-01** and gives the hook for the ownership check of TODO-1 **C-02**.

### I-10 — Field-type metadata: say clearly what the field supports
- **Where:** `CoreExtension/ProductQuantityPriceRules.php:62-132`, `:477-480`.
- **Fix:**
  - Override `supportsInheritance(): false`, `isFilterable(): false`, `supportsDirtyDetection()` as appropriate, so the class editor / grid / inheritance code do not treat it like a normal value field.
  - `getDataFromResource()` returns `[]` and `getDiffDataForEditMode()` returns `[]` with `isDiffChangeAllowed(): false`; add a short comment why (data lives in Doctrine tables), or remove the overrides if the base class already does the same.
  - `getForCsvExport()` returns `''`: export something useful (rule names + ranges as JSON) or document that it is not exported. Implement `getVersionPreview()` to show rule names/ranges in the versions tab.
  - `getParameterTypeDeclaration()` / `getReturnTypeDeclaration()` say `array`; `preGetData()` can return `null` when lazy loading was already marked but the var is empty (`:98`). Return `?array` or always normalise to `[]`.
  - Block the field in object bricks, field collections and localized fields (save/load assume a `Concrete` owner, TODO-2 **B-08**).

### I-11 — `createDataCopy()` re-implements cloning with reflection
- **Where:** `CoreExtension/ProductQuantityPriceRules.php:134-202`.
- **Fix:** add `__clone()` to the component models (`ProductQuantityPriceRule`: reset `id`, `product`, timestamps, deep-clone conditions and ranges and re-attach them via `addRange()`; `QuantityRange`: reset `id`, `rule`; CoreBundle's `QuantityRange`: decide about `unitDefinition`, see TODO-2 **B-07**). `createDataCopy()` is then `array_map(static fn ($r) => clone $r, array_filter($data, …))`. Removes five copies of the same reflection block and the hard-coded property names (dependent: Component, CoreBundle).

### I-12 — Attributes: remove dead options, keep the three attributes in sync
- **Where:** `Attribute/AsProductQuantityPriceRuleActionProcessor.php:26`.
- **Fix:** `formType` is never read (the action registry is a simple registry, ranges use `pricingBehaviour` choices). Remove it or document what it is for. Add a `priority` option only if the registries become prioritized. See TODO-2 **B-02** for the `formType` key on the condition attribute.

### I-13 — Validation event: simpler and typed
- **Where:** `Event/ProductQuantityPriceRuleValidationEvent.php`.
- **Fix:** it extends `GenericEvent` but keeps its own `$object`, so `getSubject()` and `getObject()` return the same thing and `GenericEvent`'s arguments API is unused. Extend `Symfony\Contracts\EventDispatcher\Event` with promoted `public readonly Concrete $object` and `private array $data`, and document the shape of `$data` (`array<int, array{id?: int|string, name?: string, ranges?: list<array<string, mixed>>, …}>`). Keep `getObject()`/`getData()`/`setData()` for BC with `CoreBundle\EventListener\QuantityRangeUnitValidationListener`.

## Readability / code quality

### I-14 — `CoreExtension/ProductQuantityPriceRules.php` cleanup
- **Where / Fix:**
  - `:367,404,413,477`: the long union `Concrete|\Pimcore\Model\DataObject\Objectbrick\Data\AbstractData|…|\Pimcore\Model\DataObject\Localizedfield` is written out four times with FQCNs → import the classes.
  - `:241`: `$data = $this->getSerializer()->fromArray(...)` reuses the method parameter name `$data` inside the loop over `$data`. Rename to `$entity`.
  - `:396`: `in_array($quantityPriceRule->getId(), $founds)` is not strict; use `true` as third argument (or a `array_flip` lookup).
  - `:406`: `isset($params['force']) && $params['force']` → `($params['force'] ?? false)`.
  - `:332`: `is_numeric($rule['id'])` accepts `"1e3"` / `" 12"`; use `filter_var($rule['id'], FILTER_VALIDATE_INT)`.
  - `:271-299`: the two loops that build `calculationBehaviourTypes` / `pricingBehaviourTypes` → `array_map` over the parameter; the translation key prefixes are duplicated in JS — define them once.
  - `:41-43`: the class-wide `@psalm-suppress InvalidReturnType, InvalidReturnStatement` hides real issues; fix the return types and remove it.

### I-15 — Form types: small inconsistencies
- **Where / Fix:**
  - `Form/Type/ProductQuantityRangeCollectionType.php:29`: `parent::configureOptions()` on `AbstractType` is a no-op; remove. Make the class `final` like the others (or document why `ProductQuantityPriceRuleConditionChoiceType`, `…ConditionCollectionType` and `ProductQuantityRangeCollectionType` are extendable while the rest are `final`).
  - `Form/Type/ProductQuantityRangeType.php:50`: `CheckboxType::class, []` → drop the empty array. `:66-73`: `$data` can be `null` if `empty_data` is changed; use `$data?->getPricingBehaviour()` and skip the behaviour group when it is empty.
  - `Form/Type/ProductQuantityRangeType.php:58-62`: constraints are instantiated in `configureOptions()`; build them once in the constructor (see also TODO-2 **B-04**).
  - `Form/Type/Rule/Condition/TimespanConfigurationType.php`: `dateFrom`/`dateTo` are `NumberType` (timestamps) with no constraints; add `NotBlank`/`PositiveOrZero` and a callback that `dateTo >= dateFrom`, or reuse the ProductBundle type (it is the one used today, TODO-2 **B-01**).
  - `Form/Type/Rule/Condition/ProductQuantityPriceRuleNestedConfigurationType.php:37-45`: the `PRE_SET_DATA` listener clears `conditions` with no comment; explain why (the nested conditions come from the submitted data, the stored array would conflict with the collection mapper).
  - `Form/Type/ProductQuantityPriceRuleConditionType.php:26` / `ProductQuantityRangeType.php:41`: `array $options = []` default on `buildForm()` differs from the parent signature; drop the default.

### I-16 — Wire the Twig extension and CoreBundle consumers to the detector interface
- **Where:** `Resources/config/services.yml:8-13` passes `@CoreShop\Component\ProductQuantityPriceRules\Detector\QuantityReferenceDetector` (the concrete class), although the interface alias exists (`services/product-quantity-price-rules.yml:88`). `CoreBundle/Resources/config/services/product.yml:37` and `cart-processor.yml:19` do the same (dependent). `QuantityReferenceDetector`'s own constructor takes the concrete `QuantityRuleFetcher` / `QuantityPriceFetcher` (dependent: Component).
- **Fix:** inject the interfaces everywhere so a project can decorate the detector/fetchers. Use autowiring + `#[AsTwigFunction]` (Twig ≥ 3.21 / TwigBundle 7.3) for the extension if the project standard allows it.

### I-17 — Assets and translations
- **Where:** `Resources/public/pimcore/css/product-quanity-price-rules.css`, `Resources/public/pimcore/img/product-quanity-price-rules.svg` ("quanity" typo, referenced from `pimcore/admin.yml:12`); `Resources/public/pimcore/img/price-rules.svg` (check whether it is still referenced).
- **Fix:** rename the files (keep the old path as an alias for one minor version if projects reference it), remove unused images.

### I-18 — No tests for the field type
- **Where:** the bundle has no `Tests/` directory; behaviour is only covered indirectly by Behat contexts in `CoreShop/Behat`.
- **Fix:** add kernel tests for `getDataFromEditmode()` + `save()` (add/update/remove rule and range, foreign rule id rejected, nothing written before `save()`), for `marshalVersion()`/`unmarshalVersion()` round trip with fractional `rangeStartingFrom` and `rule` back-reference, and for `createDataCopy()`. These cover TODO-1 **C-01**–**C-03** and TODO-2 **B-05**–**B-07** as regression tests.
