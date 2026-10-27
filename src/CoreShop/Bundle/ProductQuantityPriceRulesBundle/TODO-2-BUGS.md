# ProductQuantityPriceRulesBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope and references: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs: wrong results, crashes in one path, wrong wiring, wrong config. Items with data-loss or wrong-price impact on the whole shop are in TODO-1.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code against the vendored libraries or by reading the vendored library source or the compiled container. Items marked **(latent)** are not reachable today but break as soon as someone uses the feature.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Form and service wiring (`Resources/config/services/*.yml`, `Form/Type/**`)

### B-01 — Condition forms are wired to the *specific price rule* registries instead of this bundle's own ones — (verified by reading) (dependent: ProductBundle)
- **Where:**
  - `Resources/config/services/product-quantity-price-rules.yml:28-32`: `ProductQuantityPriceRuleConditionCollectionType` gets `@coreshop.registry.product_specific_price_rule.conditions` (prototypes are built from the specific-price list).
  - `:34-40`: `ProductQuantityPriceRuleConditionType` gets `@coreshop.form_registry.product_specific_price_rule.conditions`, so the configuration form of every condition type is looked up in the specific-price form registry.
  - `:49-58`: this bundle defines `coreshop.registry.product_quantity_price_rules.conditions` and `coreshop.form_registry.product_quantity_price_rules.conditions`, and `DependencyInjection/Compiler/ProductQuantityPriceRulesConditionPass.php` fills them (incl. `form-type` from `services/rules.yml:10` and `CoreBundle/Resources/config/services/product-quantity-price-rules.yml`), but no form uses the form registry.
- **Impact:**
  - The form type registered for a quantity condition is ignored: `nested` uses `ProductBundle\…\ProductSpecificPriceNestedConfigurationType` (not this bundle's `ProductQuantityPriceRuleNestedConfigurationType`, which is dead code), `timespan` uses `ProductBundle\…\TimespanConfigurationType` (not `Form/Type/Rule/Condition/TimespanConfigurationType`, which CoreBundle registers for it).
  - A condition that exists only for quantity rules (custom project condition tagged `coreshop.product_quantity_price_rules.condition` with a `form-type`) gets no `configuration` field; its configuration is rejected as an extra field or dropped.
  - Inside a `nested` condition the inner type is validated against the specific-price list, so a type such as `not_combinable_with_cart_price_voucher_rule` is accepted and saved. At runtime `RuleConditionsValidationProcessor::isConditionValid()` calls `$this->ruleRegistry->get()` on the *quantity* registry, which throws: the product page and every cart containing the product fail with HTTP 500 (needs a hand-made request; the JS only offers quantity types).
  - Hidden hard dependency on `ProductBundle` services that `composer.json` does not declare (TODO-3 **I-01**).
- **Fix:** pass `@coreshop.registry.product_quantity_price_rules.conditions` to the collection type and `@coreshop.form_registry.product_quantity_price_rules.conditions` to the condition type. Check that every quantity condition tagged by CoreBundle has a `form-type` (it does today) and that `ProductQuantityPriceRuleNestedConfigurationType` is then actually used for `nested`.

### B-02 — `#[AsProductQuantityPriceRuleConditionChecker(formType: …)]` never registers the form type — (verified by reading) (dependent: Registry)
- **Where:**
  - `DependencyInjection/CoreShopProductQuantityPriceRulesExtension.php:65-71` registers the attribute via `Component/Registry/Autoconfiguration::registerForAutoConfiguration()`.
  - `Autoconfiguration::getProperties()` turns every getter into a tag attribute named after the getter: `getFormType()` → `formType`.
  - `Component/Registry/RegisterRegistryTypePass.php` reads `$tag['form-type']`.
- **Impact:** with `autoconfigure_with_attributes: true`, a condition checker declared with the attribute has no configuration form; its configuration is never mapped (same effect as **B-01**). Without the attribute (interface autoconfiguration) there is no way to pass a form type at all.
- **Fix:** map `formType` → `form-type` in `Autoconfiguration` (or let the passes read both keys). `AsProductQuantityPriceRuleActionProcessor::$formType` is not read anywhere (actions use a simple registry without forms) – see TODO-3 **I-12**.

### B-03 — `autoconfigure_with_attributes` and `permissions` have the wrong node types; `permissions` defaults to another bundle's permission — (verified by reading)
- **Where:** `DependencyInjection/Configuration.php:42` and `:130-133`.
- **Impact:**
  - `autoconfigure_with_attributes` is a `scalarNode`: a string such as `"false"` or `%env(…)%` passes config validation and is then passed to `Autoconfiguration::registerForAutoConfiguration(bool …)` from a `strict_types` file → `TypeError` at container build.
  - `permissions` is a `scalarNode` with an array default `['notification']` (copied from `NotificationBundle/DependencyInjection/Configuration.php:100`). `AbstractModelExtension::registerPimcoreResources()` therefore registers `coreshop_permission_notification` a second time under this bundle in `coreshop.permissions` / `coreshop.all.permissions`. Setting it to a list in YAML fails validation.
- **Fix:** `booleanNode('autoconfigure_with_attributes')->defaultFalse()`; remove `permissions` (this bundle has no own admin permission) or make it `arrayNode()->scalarPrototype()->defaultValue([])`.

### B-04 — Range action constraints are created with the array-options constructor (deprecated in Symfony 7.4, ignored/broken in Symfony 8) — (verified by reading)
- **Where:** `Form/Type/ProductQuantityRangeType.php:58-62`: `new $constraintClass(['groups' => $constraint['groups']])`. The configured class is `CoreBundle\Validator\Constraints\QuantityRangePriceCurrencyAware` (`CoreBundle/Resources/config/pimcore/coreshop/coreshop_product_quantity_price_rules.yml:9-15`), which has no own constructor.
- **Impact:** `vendor/symfony/validator/Constraint.php:122` triggers "Support for evaluating options in the base Constraint class is deprecated" on every form build. In Symfony 8 the array is treated as the first positional argument and `groups` is not set, so the currency check for `fixed`/`amount_*` ranges silently stops running.
- **Fix:** `new $constraintClass(groups: $constraint['groups'])`, and give `QuantityRangePriceCurrencyAware` a constructor with `#[HasNamedArguments]` (dependent). Validate in `Configuration` that `class` exists and is a `Constraint` subclass (`->validate()->ifTrue(...)`), otherwise a typo gives a fatal "Class not found" on the first product edit.

## Editmode / persistence (`CoreExtension/ProductQuantityPriceRules.php`)

### B-05 — Validation errors are thrown as a plain `\Exception`, only for the first invalid rule, and the message builder can crash — (verified by reading)
- **Where:** `:350-361`.
- **Impact / details:**
  - `throw new \Exception(...)` inside the loop: only the errors of the first invalid rule are reported; the user fixes it, saves, and gets the next one. Pimcore's `saveAction` catches `Throwable` and re-runs `applyChanges()` on the DB copy (see TODO-1 **C-01**), then the error reaches the UI as a generic 500-style message instead of a validation message.
  - `str_replace($key, $value, …)`: message parameters can be non-string (arrays, `null`, objects for some constraints) → `TypeError` / "Array to string conversion".
  - `$e->getOrigin()` is nullable (`FormError::getOrigin(): ?FormInterface`) → "Call to a member function getConfig() on null".
  - The error path is the field name only (`getName()`), e.g. `rangeStartingFrom: …` without saying which rule/range.
- **Fix:** collect errors for all rules, use `strtr($e->getMessageTemplate(), $e->getMessageParameters())` (or simply `$e->getMessage()`, which is already translated/interpolated), build the path from `$e->getOrigin()?->getPropertyPath()` plus rule name/index, and throw `Pimcore\Model\Element\ValidationException` once after the loop.

### B-06 — Recycle-bin restore (and version publish of deleted rules) inserts ranges without their rule — (plausible) (dependent: ResourceBundle serializer)
- **Where:**
  - `:223-257` `unmarshalVersion()` / `unmarshalRecycleData()` deserialize with group `Version`. JMS fills `ranges` by reflection, so `ProductQuantityPriceRule::addRange()` (which calls `$range->setRule($this)`) is not called.
  - `ResourceBundle/Serialization/VersionObjectConstructor.php` loads existing rows from the DB (those keep their `rule`), but for rows that no longer exist (deleted product → recycle bin) it falls back to a new object with `rule = null`.
  - `Resources/config/serializer/Model.ProductQuantityPriceRule.yml` does not serialize `product` (nor `creationDate`/`modificationDate`).
- **Impact:** after restoring a product from the recycle bin, `save()` persists the rules, but the ranges are inserted with `rule_id = NULL` (the column is nullable, `QuantityRange.orm.xml:16-18`). The restored rule has no ranges; `Count(min: 1)` validation is not run on this path; orphan rows remain in the range table.
- **Fix:** after deserialization loop over the rules and re-attach: `foreach ($rule->getRanges() as $range) { $range->setRule($rule); }` (or a JMS `post_deserialize` handler on the rule). Make `rule_id` `nullable="false"` with `on-delete="CASCADE"` (TODO-3 **I-08**). Serialize `product` in group `Version` too.

### B-07 — Copying an object in the Pimcore tree drops the unit definition of every range — (plausible) (dependent: CoreBundle)
- **Where:** `:134-202` `createDataCopy()` sets `unitDefinition` to `null` on every cloned range (`:189-193`). Only `Component/Core/Product/Cloner/ProductQuantityPriceRulesCloner` (variant generator / cloner service) re-maps units afterwards (`reallocateRanges()`); Pimcore's own "copy / paste" calls `createDataCopy()` directly.
- **Impact:** a copied product has quantity ranges that silently apply to the default unit instead of the configured unit (e.g. "from 10 boxes" becomes "from 10 pieces").
- **Fix:** do the unit re-mapping in `createDataCopy()` (match by unit name on the target object once its unit definitions are copied) or move this field type's copy logic into the cloner service and call it from both paths. Also reset `creationDate`/`modificationDate` on the clone (Gedmo `on="create"` only fills an empty value, so the copy keeps the old creation date).

### B-08 — `preGetData()` throws for any class that is not `QuantityRangePriceAwareInterface`, while `save()`, `delete()` and `createDataCopy()` silently skip it — (verified by reading)
- **Where:** `:90-110`. `Assert::isInstanceOf()` runs before the `Concrete` check. `load()` (`:404-411`) passes the object to `findForProduct(QuantityRangePriceAwareInterface …)` → `TypeError` for other classes.
- **Impact:** adding the field to any other class (or to a field collection / object brick, where `$object` is the brick data) makes the getter, the admin editor and the save throw `InvalidArgumentException`.
- **Fix:** decide on one behaviour: either return `[]` for unsupported owners everywhere, or reject the field in `ClassDefinition` save (implement `ClassDefinition\Data\…\FieldDefinitionEnrichmentInterface`/class-save validation). Mark the field as not usable in bricks/collections/localized fields (`supportsInheritance()`, `isFilterable()`, etc. – see TODO-3 **I-10**).

### B-09 — `priority` is a `NumberType` (float) on an `integer` column; the rule sorter compares with `===` — (verified by reading) (dependent: Component)
- **Where:** `Form/Type/ProductQuantityPriceRuleType.php:50`; `Component/ProductQuantityPriceRules/Fetcher/QuantityRuleFetcher.php` sorts with `$a->getPriority() === $b->getPriority()`.
- **Impact:** after an editor save the in-memory rules have `float` priorities (`1.0`), rules loaded later have `int` (`1`). `1 === 1.0` is false, the comparator falls through to `>` and returns `1` for equal priorities in both directions (inconsistent comparator), so the chosen rule for equal priorities depends on load path. A value like `1.5` is accepted and silently cut on insert.
- **Fix:** `IntegerType` for `priority`; in the component use `$b->getPriority() <=> $a->getPriority()` and type the model property `int`.

### B-10 — `name` uses a `TextareaType` and has no length check, but the column is `VARCHAR(255)` — (verified by reading)
- **Where:** `Form/Type/ProductQuantityPriceRuleType.php:42`; `Resources/config/doctrine/model/ProductQuantityPriceRule.orm.xml:12` (no `length` → 255); `Resources/config/validation/ProductQuantityPriceRule.yml:3-4` only `NotBlank`.
- **Impact:** names longer than 255 characters cause a DBAL exception (strict SQL mode) during `save()` → the whole object save fails with a 500; non-strict mode truncates silently. Multi-line names break the tab titles built in `object/item.js`.
- **Fix:** `TextType` (same as the parent `RuleType`), add `Length(max: 255)` in the validation file.

## Frontend (`Twig/`, fetchers)

### B-11 — `coreshop_quantity_price_rule()` and `coreshop_quantity_price_rule_ranges()` throw when no rule matches — (verified by reading)
- **Where:** `Twig/ProductQuantityPriceRuleRangesExtension.php:39` exposes `QuantityReferenceDetector::detectRule()` directly; `:55-60` calls it without a `try`. Both throw `NoRuleFoundException`.
- **Impact:** any template that calls them without first calling `coreshop_quantity_price_rule_ranges_available()` renders a 500 for products without a valid rule. `FrontendBundle/Resources/views/Product/detail.html.twig:99-100` guards it, but runs the full rule detection twice per page.
- **Fix:** return `null` / an empty collection when no rule is found (`?ProductQuantityPriceRuleInterface`, `Collection|array` → always `Collection`), and let the template do one call: `{% set rule = coreshop_quantity_price_rule(product, coreshop.context) %}{% if rule %}`.

### B-12 — The memory-cached rule fetcher ignores the context and is never reset — (verified by reading) (dependent: Component)
- **Where:** `Resources/config/services/product-quantity-price-rules.yml:81-85` decorates the fetcher with `Component/ProductQuantityPriceRules/Rule/Fetcher/MemoryCachedValidRuleFetcher`, which caches by `$product->getId()` only.
- **Impact:**
  - Rules have context conditions (store, currency, country, zone, customer, customer group, guest, timespan). Within one request, a second call with a different context (admin order editor recalculating for another customer/store, a price export over several stores, a Twig loop with a modified context) gets the first context's rules → wrong prices.
  - The service is not `ResetInterface`: in Messenger workers / long-running commands the cache grows forever and serves rules that were changed or deactivated after the first lookup. Outside a request it still *writes* the cache, it only skips *reading* it.
- **Fix:** key the cache by product id + a hash of the context values that conditions read (store id, currency id, country id, customer id/groups, date), implement `ResetInterface` (tag `kernel.reset`), and do not write when not reading.

### B-13 — Percentage of a range is not validated — (plausible) (dependent: CoreBundle form extension)
- **Where:** `Resources/config/validation/ProductQuantityPriceRule.yml` has no rules for `QuantityRange`; `percentage` is added by `CoreBundle/Form/Extension/ProductQuantityRangeTypeExtension.php:40` as a bare `NumberType`. `rangeStartingFrom` is not checked either.
- **Impact:** `percentage_decrease` with a value > 100 gives a negative unit price; negative or duplicate `rangeStartingFrom` values give overlapping ranges where the effective price depends on sort order.
- **Fix:** add a `QuantityRange` validation file: `rangeStartingFrom` `NotNull` + `PositiveOrZero`; `percentage` `Range(min: 0, max: 100)` for group `…_behaviour_percentage_decrease`, `PositiveOrZero` for `…_increase`; a rule-level callback/`Unique` constraint on `rangeStartingFrom` per unit definition.

## Repository (`Doctrine/ORM/ProductQuantityPriceRuleRepository.php`)

### B-14 — `findWithActionOfType()` throws a generic `\Exception`; `findWithConditionOfType()` is untyped — (latent)
- **Where:** `:46-60`.
- **Impact:** both are part of `RuleRepositoryInterface`. No caller exists today, but any generic code that iterates all rule repositories (e.g. "is this condition/action type still in use?" before removing a type) crashes on this repository instead of getting `[]`.
- **Fix:** `findWithActionOfType(string $actionType): array { return []; }` (quantity rules have no actions); type the parameter of `findWithConditionOfType(string $conditionType)` and add `->distinct()` (a rule with two conditions of the same type is returned twice).
