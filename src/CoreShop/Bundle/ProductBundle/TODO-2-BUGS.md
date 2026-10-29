# ProductBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
The scope and markers are the same as in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md): **(dependent)** means the root cause or part of the fix is outside this bundle, **(reproduced)** means it was run with PHP 8.4.27 against the vendored libraries, and **(plausible)** means the trigger depends on data or configuration.
Paths are relative to the bundle root unless noted otherwise. `vendor/` means `../pimcore2teszt10211524/vendor/`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Attributes and autoconfiguration

### B-01 — (dependent: Registry C-01) `#[AsProduct(Specific)PriceRule{ConditionChecker,ActionProcessor}(formType: …)]` never registers the form type
- **Where:** `Attribute/AsProductPriceRuleActionProcessor.php`, `AsProductPriceRuleConditionChecker.php`, `AsProductSpecificPriceRuleActionProcessor.php`, `AsProductSpecificPriceRuleConditionChecker.php` (`getFormType()`); `DependencyInjection/CoreShopProductExtension.php:120-150`; `Component/Registry/Autoconfiguration.php:57-72` (`getFormType()` → tag key `formType`); `Component/Registry/RegisterRegistryTypePass.php:58` (reads `form-type`).
- **Problem:** with `autoconfigure_with_attributes: true`, the checker/processor is registered and shows up in the admin (`getConfigAction`, `ProductSpecificPriceRules::getDataForEditmode()`), but no form is added to `coreshop.form_registry.product_(specific_)price_rule.*`. Saving a rule that uses it fails in `ProductPriceRuleType` / `ProductSpecificPriceRuleType` ("no form registered for type …"), or its configuration is dropped.
- **Fix:** fix it centrally as described in Registry **C-01** (normalise the keys, or let the pass read both). Until then, document that rule elements must be tagged in YAML (`form-type:`) even in attribute mode.

### B-02 — `#[AsProductPriceCalculator]` registers the class as a **retail** price calculator; the `price_calculators` registry it suggests is never filled
- **Where:** `Attribute/AsProductPriceCalculator.php`; `DependencyInjection/CoreShopProductExtension.php:96-102` (`ProductPriceCalculatorInterface` + `ProductRetailPriceCalculatorsPass::PRODUCT_RETAIL_PRICE_CALCULATOR_TAG` + `AsProductPriceCalculator`); `Resources/config/services.yml:120-126` (`coreshop.registry.product.price_calculators`, no pass, no consumer).
- **Problem:**
  - The name says "price calculator", but a class with this attribute ends up in `coreshop.registry.product.retail_price_calculators` and is called as `getRetailPrice()`. A class that only implements `ProductPriceCalculatorInterface` semantics (overall price) becomes a retail price source. Because `CompositeRetailPriceCalculator` lets the **last** calculator win, it can override the real retail price.
  - `coreshop.registry.product.price_calculators` is declared as a prioritized registry, but no compiler pass registers anything into it and nothing reads it.
  - See TODO-1 **C-03** for the recursion this mapping enables.
- **Fix:** deprecate `AsProductPriceCalculator` (trigger a deprecation from the extension when it is found) and remove the `:96-102` block. Remove the dead registry, or wire it properly if a pluggable price-calculator chain is wanted.

### B-03 — Calculator attributes cannot set a priority, although all four calculator registries are prioritized
- **Where:** `Attribute/AsProduct{Retail,Discount,DiscountPrice,CustomAttribute,}Calculator.php` (only `type`); `Resources/config/services.yml:12-42` (`PrioritizedServiceRegistry`); `Component/Registry/RegisterSimpleRegistryTypePass.php` (`$tag['priority'] ?? 1000`).
- **Problem:** every attributed calculator gets priority 1000. The composites iterate in priority order, and `CompositeRetailPriceCalculator` / `CompositeDiscountPriceCalculator` let the last result win, so the order decides the price. With equal priorities the order is the reverse registration order (Registry TODO-2 **B-06**), which depends on file discovery order. There is no way to put a custom calculator before or after the CoreShop default with the attribute.
- **Fix:** add `public int $priority = 1000` (or `?int`) to the calculator attributes and pass it through as the `priority` tag attribute. See TODO-3 **I-01**.

### B-04 — (reproduced) `autoconfigure_with_attributes` is a `scalarNode`, but reaches a `bool` parameter under `strict_types`
- **Where:** `DependencyInjection/Configuration.php:75`; `DependencyInjection/CoreShopProductExtension.php:3` (`declare(strict_types=1)`), `:85-149`; `Component/Registry/Autoconfiguration.php:31` (`bool $autoconfigureWithAttributes`).
- **Problem:** `autoconfigure_with_attributes: 1`, `"true"`, `'%env(…)%'` or any other non-bool scalar passes config validation and then throws `TypeError: Autoconfiguration::registerForAutoConfiguration(): Argument #5 … must be of type bool` during container build. Same issue as in the other bundles listed in Registry TODO-1 "Already tracked elsewhere".
- **Fix:** `->booleanNode('autoconfigure_with_attributes')->defaultFalse()->end()`.

### B-05 — `services.yml` aliases a non-existent interface; the real custom-attributes calculator interface has no autowiring alias
- **Where:** `Resources/config/services.yml:59` (`CoreShop\Component\Product\Calculator\ProductCustomAttributeCalculatorInterface`, singular), `:134`; `CoreBundle/Resources/config/services/product.yml:93` (same id). The interface is `Component/Product/Calculator/ProductCustomAttributesCalculatorInterface.php` (plural).
- **Problem:** the service id looks like an FQCN but no such interface exists. Type-hinting the real interface `ProductCustomAttributesCalculatorInterface` in an autowired service fails with "Cannot autowire … no such service exists", while all the other calculator interfaces autowire.
- **Fix:** register `ProductCustomAttributesCalculatorInterface` as the alias, keep the singular id as a deprecated alias (`deprecated:`) for BC, and switch both references (`:134`, CoreBundle `product.yml:93`) to the correct id.

---

## Controllers and routes

### B-06 — The `coreshop_product_unit_getConfig` route points to a method that does not exist
- **Where:** `Resources/config/pimcore/routing.yml:12-21`; `DependencyInjection/Configuration.php:203` (`product_unit` admin controller is the plain `ResourceController`).
- **Problem:** the route resolves to `ResourceController::getConfigAction`, which does not exist. Calling `/admin/coreshop/product_units/get-config` returns a 500 ("controller is not callable"). The JS does not call it (`grep` over all bundles), so the route is dead.
- **Fix:** remove the `additional_routes.config` block for `coreshop_admin_product_unit`.

### B-07 — The controller actions do no permission or object-right check
- **Where:** `Controller/ProductPriceRuleController.php:27-33` (`getConfigAction`, no `isGrantedOr403()`); `Controller/ProductUnitDefinitionsController.php:34-63` (both actions, no `isGrantedOr403()`, no `$product->isAllowed('view')`).
- **Problem:**
  - Any backend user can read the unit definitions of any product ID, including the **unpublished latest version** (`productAdditionalUnitDefinitionsListAction` loads `VersionHelper::getLatestVersion()`), regardless of their workspace.
  - `getConfigAction` lists the registered action/condition types to users without the `coreshop_permission_product_price_rule` permission. This is low impact, but inconsistent with the other `ResourceController` actions of the same resource.
- **Fix:** call `$this->isGrantedOr403()` in `getConfigAction`. In the unit-definition actions, deny when `!$product->isAllowed('view')`. See also TODO-1 **C-01**.

### B-08 — The two unit-definition endpoints read different object states: the quantity-price-rule ranges and the store-value unit prices disagree for drafts
- **Where:** `Controller/ProductUnitDefinitionsController.php:34-46` (published object) vs `:48-63` (`VersionHelper::getLatestVersion()`); `:78-80` (parent lookup always uses the published parent). Callers: `CoreBundle/Resources/public/pimcore/js/productquantitypricerules/ranges.js:26` (`productUnitDefinitionsList`), `CoreBundle/Resources/public/pimcore/js/coreExtension/tags/coreShopStoreValues.js:45` (`productAdditionalUnitDefinitionsList`).
- **Problem:** after the editor adds a unit definition and saves a draft, the store-values panel shows the new unit, but the quantity price rule ranges do not. Editors can then configure ranges against a unit that the other panel does not offer, or the other way round. Definitions without an ID (never persisted) are filtered out in both, so draft-only units are partly visible.
- **Fix:** use the same state in both actions (latest version when the user may see drafts, published otherwise). Pass the state explicitly from the JS (`?version=latest`) if both are needed.

### B-09 — `productId` is taken unvalidated from attributes, query or body; arrays are cast to `1`
- **Where:** `Controller/ProductUnitDefinitionsController.php:39`, `:53`; `ResourceBundle/Controller/AdminController.php:48-63` (`getParameterFromRequest()` returns arrays as is); `ResourceBundle/Pimcore/PimcoreDaoRepository.php:97` (`getById((int) $id)`).
- **Problem:** `?productId[]=x` is cast to `(int) [..] === 1` and loads object 1. A missing or non-numeric ID silently returns `[]` with status 200, so the JS cannot tell "no definitions" from "bad request".
- **Fix:** use `#[MapQueryParameter] int $productId` (Symfony 7.4, returns 404/400 on invalid input) and return 404 when the object is not a product. See TODO-3 **I-04**.

---

## CoreExtension: `coreShopProductSpecificPriceRules`

### B-10 — `getDataForEditmode()` ignores `$data` and always shows the rules from the database: drafts and older versions show the wrong rules, and draft changes are lost when publishing
- **Where:** `CoreExtension/ProductSpecificPriceRules.php:275-296` (`$this->load($object, ['force' => true])`). Compare `CoreExtension/ProductUnitDefinitions.php:388-403`, which serializes `$data`.
- **Problem:**
  - The Pimcore admin opens the **latest version** of an object and calls `getDataForEditmode($value, $latestVersionObject)`. `$value` contains the rules from the version (`unmarshalVersion()`), but the method reloads the published rules from the DB.
  - An editor changes a rule, clicks "Save" (draft only, `save()` is not called), reopens the object: the change is gone from the UI. If the editor publishes now, the UI posts the published rules back, and the draft changes are **lost for good**.
  - Opening an old version from the version history also shows today's rules, not the rules of that version.
- **Fix:** serialize `$data` when it is an array (`$this->getSerializer()->toArray($data, $context)`), and only fall back to `load()` when `$data` is `null`.

### B-11 — (reproduced) The form-error message building throws a `TypeError` when a constraint parameter is an integer
- **Where:** `CoreExtension/ProductSpecificPriceRules.php:326-335`, `CoreExtension/ProductUnitDefinitions.php:436-445` (`str_replace($key, $value, $errorMessageTemplate)` under `declare(strict_types=1)`).
- **Problem:**
  - `Length`, `Count` and similar validators pass `{{ limit }}` as `int` (`vendor/symfony/validator/Constraints/LengthValidator.php:81`). With strict types, `str_replace('{{ limit }}', 5, …)` throws `TypeError: str_replace(): Argument #2 ($replace) must be of type array|string, int given` (reproduced on PHP 8.4.27). Instead of the validation message, the editor gets a generic error, and the real reason is lost.
  - The stock constraints in this bundle (`NotBlank`, `Range`, `GreaterThan`) pass strings, so it is triggered as soon as a project adds a `Length` on `name` or similar.
  - The loop throws at the first invalid row, so the errors of later rows are never shown.
- **Fix:** use `$e->getMessage()` (already interpolated and translated) instead of rebuilding the template. Collect the errors of all rows before throwing. Throw `Pimcore\Model\Element\ValidationException` so the admin shows the message as a validation error. See TODO-3 **I-08**.

### B-12 — `preGetData()` asserts `ProductInterface`: the data type throws when it is used on any other class, in a brick, a field collection or localized fields
- **Where:** `CoreExtension/ProductSpecificPriceRules.php:91-97` (`Assert::isInstanceOf($object, ProductInterface::class)` before the `Concrete` check); `:343-345`, `:386-388` (`save()` / `delete()` silently do nothing for non-products).
- **Problem:** the data type is registered globally (`Resources/config/pimcore/config.yml`) and offered in the class editor for every class. Adding it to a non-product class, or into an object brick / field collection, makes every getter call throw `InvalidArgumentException`. The `!$object instanceof Concrete` branch after the assert can never be reached for non-products.
- **Fix:** replace the assert with `if (!$object instanceof Concrete || !$object instanceof ProductInterface) { return []; }`, like `ProductUnitDefinitions::preGetData()`. Restrict where the type can be used in the JS definition (`allowIn` in `coreShopProductSpecificPriceRules.js`), so the class editor does not offer it in bricks, collections and localized fields.

### B-13 — (plausible) `createDataCopy()` shares the translations collection with the source rule: rule labels of the copied product are lost or still owned by the source rule
- **Where:** `CoreExtension/ProductSpecificPriceRules.php:125-171` (resets `id`, `product`, `conditions`, `actions`, but not `translations`); `Component/Product/Model/AbstractPriceRule.php` (`TranslatableTrait`); `Model.ProductSpecificPriceRule.yml` (rules have translated labels). Called from `vendor/six-it/pimcore-source/models/Element/Service.php::cloneMe()` (object copy / paste in the admin).
- **Problem:** the cloned rule keeps the same `PersistentCollection` of translation entities, which point back to the source rule. On flush Doctrine does not persist new translation rows for the copy, so the copied product's rules have no label in the storefront. Editing the label on the copy can change the source product's translation.
- **Fix:** reset `translations` to a new `ArrayCollection` and clone each translation with `setTranslatable($newPriceRule)` (and a null ID). Better, move the copy logic into `__clone()` of the models (TODO-3 **I-05**).

---

## CoreExtension: `coreShopProductUnitDefinitions`

### B-14 — Clearing the unit definitions in the editor never deletes them: they come back on reload
- **Where:** `CoreExtension/ProductUnitDefinitions.php:350-371` (`save()` only handles `instanceof ProductUnitDefinitionsInterface`); `:405-409` (`getDataFromEditmode()` returns `null` for non-arrays).
- **Problem:** when the editor removes the default unit (or an import sets the field to `null`), `save()` does nothing. The old row is still in `coreshop_product_unit_definitions` and is loaded again by `preGetData()` → `load()`. The editor cannot remove units from a product.
- **Fix:** in `save()`, when the object var is `null` and the lazy key is loaded (`$object->isLazyKeyLoaded($this->getName())`), load the stored entity and remove it (like `delete()`).

### B-15 — (plausible) `createDataCopy()` crashes when the product has no default unit definition
- **Where:** `CoreExtension/ProductUnitDefinitions.php:223` (`clone $data->getDefaultUnitDefinition()`).
- **Problem:** `getDefaultUnitDefinition()` can return `null` (`Component/Product/Model/ProductUnitDefinitions.php` handles it in `getAdditionalUnitDefinitions()`, `defaultUnitDefinition` is `on-delete="SET NULL"` in `ProductUnitDefinitions.orm.xml`). `clone null` throws `Error: __clone method called on non-object`, so copying such a product in the admin fails.
- **Fix:** only clone and set the default definition when it is not `null`. Copy the other unit definitions in that case too (today only `getAdditionalUnitDefinitions()` is copied, which is empty without a default).

### B-16 — (plausible) `getDataFromEditmode()` dereferences a nullable `$object`
- **Where:** `CoreExtension/ProductUnitDefinitions.php:405`, `:423` (`findOneForProduct($object)`), `:429` (`$object->getId()`).
- **Problem:** the signature allows `?Concrete $object = null` (the Pimcore `Data` contract). Any caller that uses it without an object (custom import code, a grid or DataHub integration, a test) gets `Error: Call to a member function getId() on null` instead of `null`. The call paths of the stock admin always pass the object; this was not traced for every Pimcore entry point.
- **Fix:** return `null` early when `!$object instanceof ProductInterface`.

### B-17 — `getForCsvExport()` always returns `'{}'`
- **Where:** `CoreExtension/ProductUnitDefinitions.php:469-478`.
- **Problem:** `getDataFromObjectParam()` returns a `ProductUnitDefinitionsInterface` object, not an array, so `!is_array($data)` is always true. A CSV export never contains the unit definitions, and `json_encode()` would not serialize the entity anyway.
- **Fix:** serialize through JMS (`toArray($data, group List)`) and `json_encode(…, JSON_THROW_ON_ERROR)`, or return a readable string like `getVersionPreview()`.

---

## CoreExtension: `coreShopProductUnitDefinition` (single select)

### B-18 — (plausible) `getDataForEditmode()` crashes on a unit definition without a unit
- **Where:** `CoreExtension/ProductUnitDefinition.php:233-240` (`$data->getUnit()->getName()` and four more calls).
- **Problem:** `ProductUnitDefinitionInterface::getUnit()` is nullable (the model's `getUnitDefinition()` checks it). A definition without a unit (new entity, broken data) makes opening the object fail with `Error: Call to a member function getName() on null`.
- **Fix:** use `$data->getUnit()?->getName()` (and the same for the labels), or fall back to the empty array.

### B-19 — `createDataCopy()` is dead and checks the wrong type: a copied product keeps pointing at the source product's unit definition
- **Where:** `CoreExtension/ProductUnitDefinition.php:155-180` (checks `ProductUnitDefinitionsInterface`, plural; the class does not implement `Data\CustomDataCopyInterface`).
- **Problem:** Pimcore only calls `createDataCopy()` for `CustomDataCopyInterface` fields, so the method is never called. Even if it were, the type check would always fail. When a product is copied, the single-select field keeps the ID of the **source** product's definition. Deleting the source product's units then leaves the copy with a dangling ID, and conversions use the source product's rate.
- **Fix:** remove the method. Remap the field on copy to the copy's definition with the same unit (`CoreBundle`'s `UnitMatcher` already does this for store values and quantity price rules; extend it, or implement `CustomDataCopyInterface` here and match by unit name).

### B-20 — `getDataFromEditmode()` accepts any unit-definition ID, including those of other products
- **Where:** `CoreExtension/ProductUnitDefinition.php:216-219`, `:137-144` (`getDataFromResource()` → `find($data)`).
- **Problem:** the field stores whatever ID is posted. The admin only offers the product's own definitions, but there is no server-side check. A foreign definition gives wrong conversion rates and dangles when that other product is changed.
- **Fix:** after loading, check `$definition->getProductUnitDefinitions()?->getProduct()?->getId() === $object->getId()` (or the parent's, when inheritance is used), and reject otherwise.

---

## Persistence

### B-21 — (plausible) `save()` / `delete()` flush the whole default entity manager on every product save
- **Where:** `CoreExtension/ProductSpecificPriceRules.php:373`, `:395`; `CoreExtension/ProductUnitDefinitions.php:369`, `:385`.
- **Problem:** `flush()` without arguments writes **every** pending change in `doctrine.orm.entity_manager`, not only the rules or unit definitions. Code that has unrelated half-built entities in the EM when it saves a product (event listeners, imports, workers) gets them written in the middle of the Pimcore object save. When that flush fails (constraint violation in an unrelated entity), the product save fails with an unrelated error. When it succeeds, unrelated changes are committed inside Pimcore's object transaction.
- **Fix:** collect the affected entities and flush them through a dedicated unit of work (for example via the temporary EM that already exists, or `UnitOfWork::commit()` scoped to them), or at least document the side effect. Long term, see TODO-3 **I-15**.

---

# Second scan: `DependencyInjection`, `Doctrine`, `EventListener`, `Pimcore`

Scope and method: see the "Second scan" paragraph in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). **B-04** above was reproduced in this scan: `CoreShopProductExtension::load()` with `autoconfigure_with_attributes: 'true'` throws the `TypeError` on Symfony 7.4.21 / PHP 8.4.27.

## Configuration

### B-22 — (reproduced) `pimcore_admin.permissions` is a `scalarNode` with an array default: it cannot be configured as a list, and a scalar value silently removes both admin permissions
- **Where:** `DependencyInjection/Configuration.php:403-406`; consumer `ResourceBundle/DependencyInjection/Extension/AbstractModelExtension.php:154-190` (`foreach ($bundleResources['permissions'] as $permission)`).
- **Problem (reproduced with `Processor` + `ContainerBuilder`):**
  - `permissions: [product_price_rule, product_unit, x]` → `InvalidTypeException: Invalid type for path "core_shop_product.pimcore_admin.permissions". Expected "scalar", but got "array"`. The default can only be used, never extended.
  - `permissions: x` passes validation. `load()` then emits `foreach() argument must be of type array|object, string given` and registers **no** permission: `coreshop.permissions` lacks `coreshop_permission_product_price_rule` and `coreshop_permission_product_unit`. Those permissions disappear from the Pimcore user editor, and `ResourceController::isGrantedOr403()` denies every non-admin user the price-rule and unit panels.
  - `cannotBeOverwritten()` on a node that is not meant to be configured at all is misleading.
- **Fix:** `->arrayNode('permissions')->scalarPrototype()->end()->defaultValue([...])->end()` (or remove the node from the user-facing tree and set the permissions in the extension). Same pattern as `ProductQuantityPriceRulesBundle` TODO-2 **B-03**.

---

## Event listeners

### B-23 — `CategoryPersistEventListener` only listens to `preUpdate`: a category created with a single `save()` has no `parentCategory` and shows up as a first-level category
- **Where:** `EventListener/CategoryPersistEventListener.php:27-44`; `Resources/config/services.yml:136-138` (only `pimcore.dataobject.preUpdate`); `vendor/six-it/pimcore-source/models/DataObject/AbstractObject.php:500-508` (a new object dispatches `PRE_ADD`, not `PRE_UPDATE`). Consumers: `Pimcore/Repository/CategoryRepository.php:27-33` (`parentCategory__id is null`), `CoreBundle/Pimcore/Repository/CategoryRepository.php:42`, `:52` (storefront navigation via `FrontendBundle/Controller/CategoryController.php:51-66`).
- **Problem:** `parentCategory` is a non-editable field (`CoreShopCategory.json:136-147`, `noteditable: true`) that only this listener maintains. Imports, fixtures, the REST/DataHub API and any code that creates and publishes a category in one `save()` never trigger `preUpdate`. The new subcategory is returned by `findFirstLevel()` (root menu) and missing from `findChildCategories()` of its real parent, until someone saves it a second time.
  - In the admin the gap is mostly hidden, because a new object is unpublished and the editor's first "Save & Publish" is an update.
- **Fix:** also register the listener for `pimcore.dataobject.preAdd` (one method, two `#[AsEventListener]` attributes, see TODO-3 **I-22**). Add a one-off migration/command that recalculates `parentCategory` for existing categories.

---

## Repositories

### B-24 — `findChildCategories()` ignores the configured sort order, and `findFirstLevel()` has no order at all
- **Where:** `Pimcore/Repository/CategoryRepository.php:27-53`; the same code in `CoreBundle/Pimcore/Repository/CategoryRepository.php:139-147` (dependent).
- **Problem:**
  - Pimcore lets the editor choose "sort children by key/index" **and** "ascending/descending" (`getChildrenSortOrder()`). The repository always appends `ASC`, so a category tree sorted descending in the admin is shown ascending in the storefront.
  - `method_exists($category, 'getChildrenSortBy')` is always true (`AbstractObject::getChildrenSortBy()`), so the `else` branch is dead.
  - `findFirstLevel()` has no `ORDER BY`. The root categories come back in whatever order MySQL returns them, which can change between requests or after an index change.
- **Fix:** use both values and whitelist them: `$by = $category->getChildrenSortBy() === AbstractObject::OBJECT_CHILDREN_SORT_BY_INDEX ? 'index' : 'key'; $dir = $category->getChildrenSortOrder() === 'DESC' ? 'DESC' : 'ASC'; $list->setOrderKey($by)->setOrder($dir);` (let Pimcore quote the column). Give `findFirstLevel()` a deterministic order (`index`, then `key`).

### B-25 — The ORM repositories accept only `ProductInterface`, but the CoreExtension data types call them with any container: using the data types outside a product class is a `TypeError`
- **Where:** `Doctrine/ORM/ProductUnitDefinitionsRepository.php:28` (`findOneForProduct(ProductInterface $product)`), `Doctrine/ORM/ProductSpecificPriceRuleRepository.php:26` (`findForProduct(ProductInterface $product)`); callers `CoreExtension/ProductUnitDefinitions.php:341-345` (`load(Concrete|…AbstractData|Localizedfield $object)` → `findOneForProduct($object)`), `:316-317` (`preGetData()` passes any `Concrete`), `CoreExtension/ProductSpecificPriceRules.php:377-381`.
- **Problem:** `coreShopProductUnitDefinitions` on a class that does not implement `ProductInterface` makes every getter call throw `TypeError: …findOneForProduct(): Argument #1 ($product) must be of type ProductInterface, Pimcore\Model\DataObject\… given` (strict types). The specific-price-rules type hits the assert first (**B-12**). Static analysis does not see it because `load()` returns `mixed`.
- **Fix:** guard in the data types (`if (!$object instanceof ProductInterface) return null;` in `load()`/`preGetData()`), together with **B-12**. Optionally widen the repository methods to `int $productId` (they only use the ID), which also allows calling them without loading the object (TODO-3 **I-20**).

### B-26 — `PriceRuleRepository::findActive()` loads every active rule with lazy conditions and actions on each price calculation (N+1 queries)
- **Where:** `Doctrine/ORM/PriceRuleRepository.php:26-34`; caller `Component/Product/Rule/Fetcher/ValidProductPriceRuleFetcher.php:36` (per product and context, cached only per request by `MemoryCachedValidRuleFetcher`). Mapping: `Resources/config/doctrine/model/ProductPriceRule.orm.xml` (`conditions`, `actions` and `translations` lazy).
- **Problem:** every rule's `conditions` and `actions` collections are loaded on first access by the validation processor: 1 + 2 × N queries for N active rules, on every category page that calculates prices for many products (the identity map prevents repeats in the same request, not across requests or workers). With a few dozen rules this is a measurable part of a listing request.
- **Fix:** fetch-join in `findActive()`: `->leftJoin('o.conditions', 'c')->addSelect('c')->leftJoin('o.actions', 'a')->addSelect('a')`. Optionally cache the result (rules change rarely) with a cache tag invalidated on rule save.

### B-27 — (plausible) The `product_specific_price` availability assessor loads **all** active specific price rules of all products at once
- **Where:** `Resources/config/services/rules.yml` (`coreshop.rule.availability_assessor.product_specific_price` → `@coreshop.repository.product_specific_price_rule`); `RuleBundle/Assessor/RuleAvailabilityAssessor.php:36` (`findActive()`), `RuleBundle/Processor/RuleAvailabilityProcessor.php:38-47` (one EM, one `flush()` at the end), `findActive()` inherited by `ProductSpecificPriceRuleRepository` from `PriceRuleRepository`.
- **Problem:** the rule-availability check (`coreshop:rules:check-availability`, and the Pimcore maintenance job via `CoreBundle/Maintenance/RuleAvailabilityTask.php`) hydrates every active specific price rule of the catalog, with their conditions, into one entity manager. On catalogs with many product-specific rules this grows without bound and can exhaust the worker's memory. After it fails, expired rules stay active.
- **Fix:** iterate in batches (`toIterable()` + `$em->clear()` every N), or let the assessor query only rules with a timespan condition (`findWithConditionOfType('timespan')`, which already exists in `RuleRepository`).

---

# Third scan: `Form`

Scope and method: see the "Third scan" paragraph in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md).

## Unit definitions

### B-28 — (reproduced at model level) `ProductUnitDefinitionsType::onSubmit()` runs on unvalidated data: a default unit definition without a unit ends in a `TypeError` (HTTP 500) instead of a validation error
- **Where:** `Form/Type/Unit/ProductUnitDefinitionsType.php:34`, `:47-70` (`FormEvents::SUBMIT` listener); `Component/Product/Model/ProductUnitDefinitions.php:80-85` (`setDefaultUnitDefinition()` → `getUnitDefinition($definition->getUnitName())`, `string $identifier`, `strict_types`).
- **Problem:**
  - `SUBMIT` listeners run before validation and also when child forms failed to transform. When `defaultUnitDefinition` is posted with an empty or unknown `unit`, `ProductUnitChoiceType` leaves `unit` as `null`, but the child form still produces a `ProductUnitDefinition` object (`data_class` + `required` → `empty_data` creates an instance), so `if ($defaultDefinition)` is true.
  - `setDefaultUnitDefinition()` then calls `getUnitDefinition(null)`: `TypeError: ProductUnitDefinitions::getUnitDefinition(): Argument #1 ($identifier) must be of type string, null given` (reproduced on PHP 8.4.27 by calling the model with a definition without a unit). The product save fails with a generic error instead of "please select a unit".
  - The same path is taken for every additional definition: `getUnitDefinition($unitDefinition->getUnitName())` with `null`.
- **Fix:** validation runs after `SUBMIT`, so `isValid()` cannot be used in the listener. Skip definitions whose `getUnit()` is `null` in `onSubmit()`, and add a `NotNull` constraint on `unit` (see **B-29**), so the form reports the error. Alternatively move the logic into a `DataMapper` that only runs on transformed data (TODO-3 **I-31**).

### B-29 — Unit, unit-definition and unit-price forms have no validation at all: zero or negative conversion rates, negative precision and duplicate unit names reach the database
- **Where:** `Form/Type/Unit/ProductUnitDefinitionType.php:28-35` (`unit`, `conversionRate`, `precision` without constraints), `Form/Type/Unit/ProductUnitType.php:28-36` (`name`), `Form/Type/Unit/ProductUnitDefinitionPriceType.php:36-42`; `Resources/config/validation/` (only `ProductPriceRule.yml` and `ProductSpecificPriceRule.yml` exist); `Resources/config/doctrine/model/ProductUnit.orm.xml:12` (`name` `unique="true"`). Consumer of the rate: `Component/Core/Order/Modifier/CartItemQuantityModifier.php:61` (`defaultUnitQuantity = conversionRate × quantity`).
- **Problem:**
  - A conversion rate of `0` makes every cart item in that unit count as 0 default units (inventory, shipping weight and quantity price ranges use the default-unit quantity); a negative rate gives negative stock movements. A negative `precision` is accepted and used for rounding quantities.
  - A unit definition without a unit is persisted (the join column is nullable), and crashes later (**B-18**, **B-28**).
  - Saving a product unit with an existing `name` passes the form and fails on the unique index: `UniqueConstraintViolationException` → HTTP 500 from `ResourceController::saveAction()` instead of a form error. An empty `name` is accepted too.
  - The `validation_groups` parameters in `units.yml` (`coreshop.form.type.product_unit*.validation_groups`) are passed to the types but no constraint uses them.
- **Fix:** add `Resources/config/validation/{ProductUnit,ProductUnitDefinition,ProductUnitDefinitionPrice}.yml` (group `coreshop`): `ProductUnit.name` → `NotBlank`, `Length(max: 190)`, class-level `UniqueEntity(fields: name)`; `ProductUnitDefinition.unit` → `NotNull`; `conversionRate` → `NotBlank`, `Positive`; `precision` → `NotNull`, `PositiveOrZero`; `ProductUnitDefinitionPrice.price` → `PositiveOrZero`, `unitDefinition` → `NotNull`. Add `Valid` on the collections in `ProductUnitDefinitionsType`.

### B-30 — `ProductUnitDefinitionSelectionType` turns unknown, fractional or foreign IDs into `null` silently; it also breaks without the CSRF extension
- **Where:** `Form/Type/Unit/ProductUnitDefinitionSelectionType.php:36-73` (parent `NumberType`, reverse transformer `find($value)`, `csrf_protection` default). Users: `CoreBundle/Form/Extension/ProductQuantityRangeTypeExtension.php:42` (quantity price rule ranges), `Form/Type/Unit/ProductUnitDefinitionPriceType.php:40` (store unit prices, `CoreBundle/Form/Type/Product/ProductStoreValuesType.php:42`).
- **Problem:**
  - The reverse transformer returns `find($value)`, i.e. `null` for an ID that does not exist (deleted definition, stale editor), and the field is valid. The range or unit price is saved **without** its unit definition; in the storefront it then applies to the default unit instead of the intended one. No error is shown.
  - The parent is `NumberType`, so `12.7` is accepted for an ID.
  - Any unit-definition ID is accepted, also one that belongs to a different product (same class of problem as **B-20**): a quantity range of product A can point to a unit of product B.
  - `configureOptions()` sets `csrf_protection`, an option that only exists when the CSRF form extension is registered. In an application with `framework.csrf_protection: false` every form that uses this type throws `UndefinedOptionsException: The option "csrf_protection" does not exist` (the same exception was observed on Symfony 7.4.21 when building forms without the extension).
- **Fix:** parent `IntegerType` (or `HiddenType`); throw `TransformationFailedException` with an `invalid_message` when `find()` returns `null`; check the owning product (pass it as an option from the store-values / quantity-range forms); drop the `csrf_protection` default (child types do not need it).

### B-31 — (plausible) `ProductUnitDefinitionsChoiceType` crashes when the `product` option is missing or a definition has no unit
- **Where:** `Form/Type/Unit/ProductUnitDefinitionsChoiceType.php:30-56`.
- **Problem:** `product` defaults to `null` and is neither required nor typed, so `$product->hasUnitDefinitions()` is `Error: Call to a member function hasUnitDefinitions() on null` for any caller that forgets it. `choice_label` calls `$definition->getUnit()->getFullLabel()` without a null check (**B-18** in the storefront cart). The CoreBundle callers (`CartItemTypeExtension.php:68`, `CartCreationCartItemTypeExtension.php:57`) pass a product today, so this is a trap for project code.
- **Fix:** `$resolver->setRequired('product')->setAllowedTypes('product', ProductInterface::class)`; `getUnit()?->getFullLabel() ?? (string) $definition->getId()`. Remove the meaningless `entry_type` default (TODO-3 **I-30**).

---

## Price rule forms

### B-32 — `PriceConfigurationType` accepts negative prices
- **Where:** `Form/Type/Rule/Action/PriceConfigurationType.php:43-48` (`NotBlank` + `Type(numeric)` only). Compare `DiscountAmountConfigurationType.php:48` (`GreaterThan(0)`). Used for the `price` and `discountPrice` actions of both product price rules and specific price rules (`Resources/config/services.yml:85-99`).
- **Problem:** a typo (`-10` instead of `10`) in a price or discount-price action is saved, and the product is offered at a negative price in the storefront and cart. Nothing downstream clamps it.
- **Fix:** add `new GreaterThanOrEqual(value: 0, groups: $this->validationGroups)` (zero stays possible for free items).

### B-33 — (reproduced) `TimespanConfigurationType` has no constraints: an empty or reversed time span is saved, and such a rule silently never (or always) applies
- **Where:** `Form/Type/Rule/Condition/TimespanConfigurationType.php:35-41` (`$validationGroups` is injected but unused); consumers `Component/Product/Rule/Condition/TimeSpanConditionChecker.php:27-45`, `RuleBundle/Assessor/RuleAvailabilityAssessor.php` (`isValid()`).
- **Problem (reproduced with Symfony 7.4.21 form factory + validator):**
  - `dateFrom = 1800000000000`, `dateTo = 1700000000000` → `isValid() === true`. The checker then never matches (now cannot be after `dateFrom` and before `dateTo`), and the availability check deactivates the rule once `dateFrom` has passed. The editor gets no hint.
  - `{}` (both missing) → valid, `null` values stored. The checker treats them as "no limit", so a time-span condition without dates makes the rule apply always.
  - The same form type is used for specific price rules (`services.yml:79-82`).
- **Fix:** at least one of both dates `NotBlank`; `PositiveOrZero` on each; a form-level `Callback` constraint (or `Expression`) `dateTo === null || dateFrom === null || dateFrom <= dateTo`. Use the injected `$validationGroups`.

### B-34 — (plausible) The currency model transformer of `PriceConfigurationType` / `DiscountAmountConfigurationType` maps stored currency IDs to `null`
- **Where:** `Form/Type/Rule/Action/DiscountAmountConfigurationType.php:58-73`, `Form/Type/Rule/Action/PriceConfigurationType.php:56-71` (both closures of the `CallbackTransformer` are identical); `CurrencyBundle/Form/Type/CurrencyChoiceType.php` (choices are currency entities).
- **Problem:** the stored configuration holds the currency **ID** (that is what `reverseTransform` returns). `transform()` only handles `CurrencyInterface` and returns `null` for an `int`, so a form initialised from a stored rule shows no currency. The admin posts the full configuration on every save, so it works there, but any consumer that renders the form from stored data (Twig, API with `submit($data, false)`) loses the currency, and a partial submit then fails `NotBlank`.
- **Fix:** `transform: fn ($c) => $c instanceof CurrencyInterface ? $c : ($c ? $this->currencyRepository->find($c) : null)` (inject the repository), or use one shared `ResourceToIdentifierTransformer` (TODO-3 **I-29**).

### B-35 — Rule names longer than 255 characters fail in the database instead of the form
- **Where:** `Form/Type/ProductPriceRuleType.php:36`, `Form/Type/ProductSpecificPriceRuleType.php:36` (`TextareaType`, no `Length`); `Resources/config/doctrine/model/Product{,Specific}PriceRule.orm.xml:12` (`string`, default length 255); `Resources/config/validation/Product{,Specific}PriceRule.yml` (`NotBlank` only).
- **Problem:** the field is a textarea, so long names are easy to enter. On MySQL in strict mode the save fails with `Data too long for column 'name'` (HTTP 500 for price rules via `ResourceController`, a generic product-save error for specific price rules).
- **Fix:** `Length(max: 255)` in both validation files, and `TextType` (TODO-3 **I-34**). For specific price rules this message goes through the error flattening that **B-11** breaks for integer parameters (`{{ limit }}`): fix **B-11** first, or the new constraint turns the 500 into a different 500.

### B-36 — The nested-condition types wipe the stored nested conditions whenever the form is initialised with data
- **Where:** `Form/Type/Rule/Condition/ProductPriceNestedConfigurationType.php:49-57`, `Form/Type/Rule/Condition/ProductSpecificPriceNestedConfigurationType.php:49-57` (`PRE_SET_DATA` → `$data['conditions'] = []`).
- **Problem:** the listener replaces the stored nested conditions with an empty list on every `setData()`. The admin always re-posts the whole tree, so a full submit restores them. A partial submit (`submit($data, false)`), a form rendered from stored data, or a client that omits `conditions` (to change only the operator) silently deletes all nested conditions of that group; the rule then matches more products than intended.
- **Fix:** document why the reset is needed (the stored conditions are not shaped for the collection's data mapper), and convert them instead of discarding them (map the stored arrays/entities to the collection's entry data in `PRE_SET_DATA`). Add a test with a partial submit.
