# ProductBundle – TODO 1: Critical errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: `Attribute/*` (9 files), `Controller/*` (2 files), `CoreExtension/*` (4 files), and the config they depend on: `DependencyInjection/{CoreShopProductExtension,Configuration}.php`, `DependencyInjection/Compiler/*`, `CoreShopProductBundle.php`, `Resources/config/{services.yml,services/units.yml,services/data_hub.yml,pimcore/*.yml,serializer/Model.{ProductUnitDefinitions,ProductUnitDefinition,ProductSpecificPriceRule,AbstractPriceRule,ProductUnit}.yml,doctrine/model/{ProductUnitDefinition,ProductUnitDefinitions,ProductSpecificPriceRule}.orm.xml,validation/ProductSpecificPriceRule.yml}` and `composer.json`. Call paths were followed into `Component/Registry/{Autoconfiguration,RegisterRegistryTypePass,RegisterSimpleRegistryTypePass}.php`, `Component/Product/{Calculator/*,Model/ProductUnitDefinitions.php,Model/Product.php}`, `Component/Pimcore/DataObject/VersionHelper.php`, `ResourceBundle/{Controller/ResourceController,Controller/AdminController,Routing/ResourceLoader,Doctrine/ORM/EntityMerger,CoreExtension/TempEntityManagerTrait,Pimcore/PimcoreDaoRepository}.php`, and the Pimcore sources (`models/DataObject/Concrete.php`, `Concrete/Dao.php`, `Element/Service.php::cloneMe()`, `ClassDefinition/Data/*Interface.php`).
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4.21, six-it/pimcore-source 2.x, Doctrine ORM 2.20.13, JMS Serializer 3.32.9, PHP 8.4.27). Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(reproduced)** were run with PHP 8.4.27 against the bundle code and the vendored libraries. Items marked **(plausible)** follow from the code, but the trigger depends on data or configuration.

"Critical" here means: security hole, shop-wide breakage on a common path, data written wrongly on a common path, or a blocker for Symfony 7.4 / PHP 8.4.

**No Symfony 7.4 / PHP 8.4 blocker was found.** All 15 PHP files in scope pass `php -l` on 8.4.27. All of them were loaded through the vendored autoloader with `E_ALL` and an error handler in place: no fatal error, no signature mismatch with the Pimcore `Data` interfaces, no deprecation. Forward-compatibility issues (PHP 8.5, Doctrine ORM 3, Symfony 8) are tracked in TODO-3.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security

### C-01 — The `product_unit_definitions` resource routes expose full CRUD with no permission check: any backend user can list every product's unit definitions and delete any product's units (and, by cascade, its unit prices)
- **Where:** `Resources/config/pimcore/routing.yml:23-37` (no `only:` / `no_default_routes`). `DependencyInjection/Configuration.php:233-253` (`product_unit_definitions` has **no** `permission` node, unlike `product_price_rule` `:112` and `product_unit` `:191`). Route generation: `ResourceBundle/Routing/ResourceLoader.php:48-82` (`get`, `list`, `add`, `save`, `delete` are always generated unless restricted). Permission gate: `ResourceBundle/Controller/ResourceController.php:59-78` (`isGrantedOr403()` is a **no-op** when the metadata has no `permission` parameter). Cascade: `Resources/config/doctrine/model/ProductUnitDefinitions.orm.xml` (`unitDefinitions` `cascade-all` + `orphan-removal`), `ProductUnitDefinition.orm.xml` / `ProductUnitDefinitionPrice.orm.xml` (`on-delete="CASCADE"`).
- **Problem:**
  - Next to the two custom routes the admin UI uses, the loader also registers `coreshop_product_unit_definitions_{get,list,add,save,delete}` under `/admin/coreshop/…`. They are handled by `ProductUnitDefinitionsController` → `ResourceController`.
  - Because no `permission` is configured, `isGrantedOr403()` returns immediately. **Any** logged-in backend user (no CoreShop permission, no workspace right on the product) can:
    - `DELETE …/delete?id=N` → removes the `ProductUnitDefinitions` row of any product. Doctrine cascades to all of its `ProductUnitDefinition` rows, and the DB cascades to `coreshop_product_unit_definition_price` (store unit prices). Quantity price rule ranges and store values that reference those unit definitions are left dangling. This is silent, irreversible data loss on the pricing data of a product (versions only hold serialized snapshots; restoring needs a re-save of every affected product).
    - `GET …/list` → `findAll()` (`ResourceController.php:80-89`, no pagination) dumps the unit definitions of the **whole catalog** in one response. On a large catalog this is also an easy way to exhaust memory on the admin node.
    - `GET …/get?id=N`, `POST …/add`, `POST …/save` → read and write the entity outside the Pimcore object editor (no object-level `isAllowed()` check, no versioning, no Pimcore events).
  - The admin UI never calls these routes (only `productUnitDefinitionsList` / `productAdditionalUnitDefinitionsList`, see `CoreBundle/Resources/public/pimcore/js/productquantitypricerules/ranges.js:26` and `CoreBundle/Resources/public/pimcore/js/coreExtension/tags/coreShopStoreValues.js:45`). They exist only as a side effect of the route type.
- **Fix:**
  - In `routing.yml` add `no_default_routes: true` (or `only: []`) to `coreshop_admin_product_unit_definitions`, so only the two additional routes remain.
  - Give the resource a permission anyway (`->scalarNode('permission')->defaultValue('product_unit')->cannotBeOverwritten()`), so that a future default route is not open by default.
  - In the two remaining actions, check the object right after the product is resolved: `if (!$product->isAllowed('view')) throw $this->createAccessDeniedException();` (see TODO-2 **B-09**).
  - **(dependent)** In `ResourceBundle`, consider making `isGrantedOr403()` deny by default when no permission is configured, and call it from `listAction()` as well.

### C-02 — `ProductSpecificPriceRules::getDataFromEditmode()` loads rules by the posted ID without checking that they belong to the product: a product editor can move (steal) or overwrite the specific price rules of any other product
- **Where:** `CoreExtension/ProductSpecificPriceRules.php:306-324` (`find($ruleId)` on an unscoped repository, then `$formData->setProduct($object->getId())`), `:343-375` (`save()` merges and persists the rule with the new product ID). Merge semantics: `ResourceBundle/Doctrine/ORM/EntityMerger.php` (`doMerge()` registers the detached instance as managed with the original data, so the product change becomes an `UPDATE`).
- **Problem:**
  - Each posted row may contain an `id`. The rule with that ID is loaded from **any** product, bound to the form, and its `product` is overwritten with the ID of the product being saved.
  - On save, `EntityMerger` turns the change into `UPDATE coreshop_product_specific_price_rule SET product = <A> … WHERE id = <rule of B>`. The rule disappears from product B and appears on product A, with the conditions and actions changed as posted.
  - The user only needs the right to save product A. No right on product B is checked, and product B gets no version, no event and no cache invalidation. This means product B's prices in the storefront change silently (its discount rule is gone, or a different rule now applies), and the change is invisible in product B's history.
  - The admin UI only sends the IDs of the product's own rules, so this is reached by a crafted request (or by a buggy client). It is still a write IDOR across objects, which is why it is listed here.
- **Fix:**
  - Scope the lookup: `$specificPriceRuleRepository->findOneBy(['id' => $ruleId, 'product' => $object->getId()])`. Treat an ID that does not belong to the product as a new rule (strip the `id`), or reject the row with a validation error.
  - In `save()`, before merging, skip (or reject) entities whose `getProduct()` is set and differs from `$object->getId()`.
  - Add a functional test: post product A with the ID of a rule of product B → product B's rule is unchanged.

---

## Price calculation

### C-03 — (plausible, dependent) Interface-based autoconfiguration tags any service that implements `ProductPriceCalculatorInterface` as a retail, discount-price, discount **and** custom-attributes calculator: a decorated price calculator recurses until the stack overflows on every price lookup
- **Where:** `DependencyInjection/CoreShopProductExtension.php:80-118` (four calculator interfaces), especially `:96-102` (`ProductPriceCalculatorInterface` → `PRODUCT_RETAIL_PRICE_CALCULATOR_TAG`). `Component/Product/Calculator/ProductPriceCalculatorInterface.php` (extends all four calculator interfaces). `Component/Registry/Autoconfiguration.php:33-40` (default mode `autoconfigure_with_attributes: false` → `registerForAutoconfiguration($interface)->addTag($tag)`). Composites: `Component/Product/Calculator/Composite*Calculator.php` (iterate every registered calculator). `Resources/config/services.yml:44-62`, `:128-134`.
- **Problem:**
  - The bundle's own services are not autoconfigured, so the stock setup works. But Symfony's default `config/services.yaml` has `autoconfigure: true`. An app that decorates the price calculator — the usual way to add caching, logging or a custom rounding (`decorates: CoreShop\Component\Product\Calculator\ProductPriceCalculatorInterface`), or that ships its own `ProductPriceCalculatorInterface` implementation — gets that service tagged with **all four** calculator tags, because the interface extends all four calculator interfaces. `:96-102` adds the retail tag a second time.
  - The decorator is then registered into `CompositeRetailPriceCalculator`, `CompositeDiscountPriceCalculator`, `CompositeDiscountCalculator` and `CompositeProductCustomAttributesCalculator`. Calling `getPrice()` goes `decorator → ProductPriceCalculator → CompositeRetailPriceCalculator → decorator → …` → `Maximum function nesting level` / segfault. Every product price in the storefront, the cart and the admin fails.
  - The same mechanism hits `MemoryCachedProductPriceCalculator` (`Component/Product/Calculator/MemoryCachedProductPriceCalculator.php`) as soon as someone wires it in with autoconfiguration.
  - With `autoconfigure_with_attributes: true`, `#[AsProductPriceCalculator]` is mapped to the same **retail** tag (TODO-2 **B-02**), so the attribute path has the same trap.
  - Same class of problem as `Component/Registry/TODO-1-CRITICAL.md` **C-02** (composite passes), but here through `RegisterSimpleRegistryTypePass`.
- **Fix:**
  - Remove the `ProductPriceCalculatorInterface` → retail tag registration (`:96-102`). `ProductPriceCalculatorInterface` is the facade, not a strategy.
  - Make the facade not extend the strategy interfaces (or add a marker so the passes skip it). For example, let `RegisterSimpleRegistryTypePass` skip definitions that implement `ProductPriceCalculatorInterface`, are a decorator (`$definition->getDecoratedService() !== null`), or are the composite itself.
  - Document that calculators must implement exactly one strategy interface, and that decorating the facade requires `autoconfigure: false` until this is fixed.
  - Add a container test: an autoconfigured decorator of `ProductPriceCalculatorInterface` must not appear in any of the four calculator registries.

---

## Already tracked elsewhere (not repeated)
- `Component/Registry/TODO-1-CRITICAL.md` **C-01** — attribute `formType` vs tag `form-type`. The consumer side for this bundle's four rule attributes is TODO-2 **B-01**.
- `Component/Registry/TODO-2-BUGS.md` **B-15** — `autoconfigure_with_attributes` switches registration mode for the whole bundle (interface-only implementations disappear in attribute mode).
