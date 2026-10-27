# ShippingBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items (Symfony 8). None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as sub-bullets.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle.

**Already fine for Symfony 7.4:** the extension extends CoreShop's `AbstractModelExtension` (based on `Symfony\Component\DependencyInjection\Extension\Extension`), service config is YAML (not the XML service format deprecated in 7.4; the `*.orm.xml` files are Doctrine mappings and not affected), `TreeBuilder` is constructed with a root name, constraints use named arguments (no array options, deprecated in 7.3), no `ContainerAwareInterface`, every `buildForm`/`configureOptions`/`getParent` has native return types.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — `composer.json` does not describe what the code needs
- **Where:** `composer.json:26-40`.
- **Problem / Fix:**
  - No `php` constraint. Add `"php": "^8.4"`.
  - Directly used packages are not declared: `symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel`, `symfony/http-foundation`, `symfony/form`, `symfony/options-resolver`, `symfony/validator` (all `^7.4`), `doctrine/orm` + `gedmo/doctrine-extensions` (mappings use `gedmo:timestampable`), `jms/serializer-bundle` (`pimcore/config.yml:12-17`). Pimcore pulls them in today, but the bundle should not rely on that.
  - `coreshop/registry` is `^4.1`, every other CoreShop package `^4.0`; align.
  - `require-dev` pins PHPStan 1.x; bump to PHPStan 2 (PHP 8.4 rules) together with the other bundles.
  - `"minimum-stability": "dev"` and `repositories` (`path` `../../*/*`) have no effect for consumers of a library; move them to the monorepo root. `:47-50` uses 4-space indent while the rest uses 2.

### I-02 — Configuration tree: wrong node types, dead `array_key_exists`, missing docs
- **Where:** `DependencyInjection/Configuration.php`.
- **Fix:**
  - `:52` `autoconfigure_with_attributes` → `booleanNode()` (a string like `"yes"` currently reaches `Autoconfiguration::registerForAutoConfiguration(bool …)` under `strict_types` → `TypeError`).
  - `:53` `default_resolver`: see TODO-1 **C-05**.
  - `:178-181` `permissions` is a `scalarNode` with an array default; make it `arrayNode()->scalarPrototype()->defaultValue([...])`.
  - `:164,168,172,176` `->prototype('scalar')` → `->scalarPrototype()` (the generic `prototype()` is the legacy spelling).
  - `:46` the `/** @var ArrayNodeDefinition $rootNode */` is not needed with Symfony 7 generics.
  - `shipping_rule_group` has no `permission` and no `admin_controller` (not an admin resource) — fine, but say so with `->info()`. Add `->info()` texts for all non-obvious options so `config:dump-reference core_shop_shipping` documents them.

### I-03 — Extension: naming, repetition, data-hub check
- **Where:** `DependencyInjection/CoreShopShippingExtension.php:47-110`.
- **Fix:**
  - `$configs = $this->processConfiguration(...)` overwrites the input with a different shape; name it `$config`.
  - `:50` `__DIR__ . '/../Resources/config'` → `\dirname(__DIR__) . '/Resources/config'`.
  - `:54` `array_key_exists('pimcore_admin', $configs)` is always true (`addDefaultsIfNotSet()`); drop the `if`.
  - `:58-61` `$container->setAlias(DefaultCarrierResolverInterface::class, $config['default_resolver'])->setPublic(true);`.
  - `:71-109` five near-identical `registerForAutoConfiguration()` calls → loop over a `const` map `interface => [tag, attribute]` (and fix the action interface, TODO-1 **C-04**).
  - `:63-67` `kernel.bundles` lookup for `PimcoreDataHubBundle`: use `class_exists(\Pimcore\Bundle\DataHubBundle\PimcoreDataHubBundle::class)` / `ContainerBuilder::willBeAvailable()` together with the bundle check so a removed-but-still-listed bundle does not break the load.
  - Optional (Symfony ≥ 6.1): merge bundle, extension and configuration into one `AbstractBundle`. Only worth doing for all CoreShop bundles at once (they share `AbstractResourceBundle` / `AbstractModelExtension`).

### I-04 — Compiler passes: formatting, typo, PHP 8.3 typed constants
- **Where:** `DependencyInjection/Compiler/*.php`.
- **Fix:**
  - Every pass has an empty constructor formatted as `public function __construct(\n        ) {` — collapse to `public function __construct()`, or drop the constructor and pass the arguments in `CoreShopShippingBundle::build()`.
  - `CompositeShippableValidatorPass::SHIPABLE_VALIDATOR_TAG` (typo "SHIPABLE"): add `SHIPPABLE_VALIDATOR_TAG` and keep the old one as a deprecated alias (it is public API).
  - Typed class constants (PHP 8.3): `public const string SHIPPING_RULE_ACTION_TAG = '…';`.
  - Same empty-constructor formatting in `Component/Shipping/Validator/CompositeShippableCarrierValidator.php:30-33` **(dependent)**.

### I-05 — Attribute classes: `readonly`, missing/extra arguments
- **Where:** `Attribute/*.php`.
- **Fix:**
  - `final readonly class` with `public` promoted properties (or PHP 8.4 `public private(set)`), and drop the getters only together with `Autoconfiguration::getProperties()` which reads getters **(dependent)**.
  - `AsCarrierPriceCalculator`: add `priority` (TODO-2 **B-02**).
  - `AsShippingTaxCalculatorStrategy::$formType` has no meaning (tax strategies have no configuration form, `coreshop.form_registry.shipping.tax_calculation_strategies` is never read); remove or document.
  - `AsShippableValidator` has only `priority`; fine.
  - Consider `#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]` for the rule attributes so one class can register under two types (YAML tags already allow that).

## Code quality and readability

### I-06 — Controllers
- **Where:** `Controller/CarrierController.php`, `Controller/ShippingRuleController.php`.
- **Fix:**
  - `CarrierController:28-40`: `@var array` block + `foreach` → `array_map(static fn (string $s): array => ['value' => $s, 'label' => 'coreshop_shipping_tax_strategy_' . $s], array_values($strategies))`. The comment `// key length has a maximum` explains nothing; remove or explain.
  - `ShippingRuleController:27` unused `Request $request` parameter; return type `Response` vs `JsonResponse` in the sibling — align to `JsonResponse`.
  - `getConfigActions()` / `getConfigConditions()` return maps whose keys equal their values; `array_keys()` works but `array_values()` states the intent. Inject the parameters (`#[Autowire(param: 'coreshop.shipping_rule.actions')]`) instead of `getParameter()` once `ResourceController` allows constructor additions **(dependent)**.
  - Permission check: TODO-2 **B-12**.

### I-07 — Pimcore core extensions use the static container
- **Where:** `CoreExtension/Carrier.php:36-44`, `CoreExtension/CarrierMultiselect.php`.
- **Fix:**
  - `\Pimcore::getContainer()->get('coreshop.repository.carrier')` / `getParameter()` — Pimcore data types are not services, so this is the documented way, but at least add the return type (`getRepository(): RepositoryInterface`) and cache the result in a property.
  - `@psalm-suppress InvalidReturnType, InvalidReturnStatement` hides real type issues of the parent; check with PHPStan 2 and remove if not needed.
  - `getInterface()` returns `'\\' . CarrierInterface::class` — leading backslash only needed for generated PHP code; add a comment.

### I-08 — Form types: duplication, visibility, naming
- **Where:** `Form/Type/**`.
- **Fix:**
  - `AdditionPercentActionConfigurationType` and `DiscountPercentActionConfigurationType` are identical except the block prefix → one `abstract class AbstractPercentActionConfigurationType`.
  - All configuration types: `protected array $validationGroups` in `final` classes → `private readonly array`. Mixed `final` / non-final for no visible reason (`CarrierType`, `ShippingRuleGroupType`, `ShippingRuleGroupCollectionType`, `ShippingTaxCalculationStrategyChoiceType`, the two percent types, `ShippingRuleConditionCollectionType`, `ShippingRule{Action,Condition}ChoiceType`); decide per class (extension points stay open, the rest `final`).
  - `Type(type: 'numeric')` after `NumberType` / `IntegerType` / `MoneyType` is redundant (the view transformer already guarantees a number or a transformation error).
  - `PostcodeConfigurationType:39-40` empty options array `[ ]`.
  - `ShippingRuleType:31` `name` is a `TextareaType` while `RuleBundle/Form/Type/RuleType` uses `TextType`; rule names are single-line. It also does not call `parent::buildForm()` and re-adds all fields — either call the parent and only override `conditions`/`actions`, or extend `AbstractResourceType` directly.
  - `ShippingRuleActionType:26` / `ShippingRuleConditionType:26` `array $options = []` deviates from the `FormTypeInterface` signature (no default); remove the default.
  - Block prefixes are inconsistent: `coreshop_product_shipping_action_collection` (`ShippingRuleActionCollectionType:35`), `coreshop_shipping_rule_action_condition_choice` (`ShippingRuleConditionChoiceType:27`), `coreshop_shipping_rule_condition_shipping_rule` for a type used for both condition and action (`ShippingRuleConfigurationType:68`). Fixing them changes form field names → check admin JS / templates first.
  - `CarrierChoiceType` / `ShippingRuleChoiceType`: `function (Options $options)` with unused `$options`, `usort` with a closure → `static fn () => …` and `strcmp`/`<=>` in an arrow function; better: let the repository sort (`findBy([], ['identifier' => 'ASC'])`).
  - `ShippingTaxCalculationStrategyChoiceType`: `private array $strategies` without `@param array<string, string>`; `array_flip()` of a map whose keys equal values does nothing; add `choice_label` with the `coreshop_shipping_tax_strategy_*` translation keys used in `CarrierController`.
  - `NestedConfigurationType:49-57`: the `PRE_SET_DATA` listener that empties `conditions` needs a comment (it exists because the stored configuration holds entities, not arrays).

### I-09 — Service definitions
- **Where:** `Resources/config/services.yml`, `Resources/config/services/*.yml`.
- **Fix:**
  - `_defaults: public: true` in every file; Symfony's best practice is private services. Keep public only what is fetched from the container (`coreshop.repository.*` users via `\Pimcore::getContainer()`, controllers, document editables); the rest private. Do it together with the other bundles (BC for projects that call `$container->get()`).
  - `services.yml:40-41` `CarrierPriceCalculator:` with a bare list (`- '@…'`) relies on the YAML short syntax for arguments; write `arguments:` explicitly for readability.
  - `services.yml:43-45` `CompositePriceCalculator` wraps exactly one calculator and is not referenced anywhere in the bundle (only the prioritized registry is used); deprecate or document why it exists.
  - `services.yml:11` comment indented with 2 spaces inside a 4-space block (`  # Carrier Validator`).
  - `services/shipping-rules.yml:28-41` `shared: false` on the two rule processors without a comment (the processors are stateless; if the reason is the traceable decorator in RuleBundle, say so).
  - `services/form.yml`: 21 hand-written form type definitions; most could be `autowire` + `autoconfigure` with `#[Autowire(param: …)]` on the constructor arguments.
  - Missing trailing newline in `services/pimcore.yml`, `pimcore/admin.yml`, `serializer/Model.{CarrierTranslation,ShippingRuleGroup}.yml`, `validation/ShippingRule{,Group}.yml`.
  - Optional: `services.php` (`ContainerConfigurator`) for static analysis of class names; YAML is not deprecated, so this is not required.

### I-10 — Doctrine mapping
- **Where:** `Resources/config/doctrine/model/*.orm.xml`.
- **Fix:**
  - `Carrier.identifier`: add a unique index (it is used as the technical key, fixtures and `CarrierChoiceType` labels).
  - `ShippingRuleGroup`: `cascade-persist` on both many-to-one relations is unnecessary (carrier and rule always exist already) and can persist detached objects by accident; remove. FK `on-delete`: TODO-1 **C-03**.
  - `ShippingRule.conditions` / `actions` are `many-to-many` with `orphan-removal` to `ConditionInterface` / `ActionInterface`: a condition belongs to exactly one rule, so this is a one-to-many with a join table; document it (changing it needs a migration in all rule bundles **(dependent)**).
  - `generator strategy="AUTO"` → explicit `IDENTITY` (ORM 3 / DBAL 4 changed what `AUTO` means on PostgreSQL).
  - `datetime` → `datetime_immutable` for `creationDate`/`modificationDate` (optional, needs model changes).
  - Long-term: Doctrine is moving to attribute mapping; XML mapping is still supported in ORM 3, no action needed now.

## Logic / maintainability

### I-11 — Validation coverage
- **Where:** `Resources/config/validation/*.yml`.
- **Fix:** besides TODO-1 **C-03** and TODO-2 **B-06**–**B-08**: `Valid` on `Carrier.translations` and `Carrier.shippingRules` (so translation/group constraints run when the carrier is validated without the form), `Choice(callback)` on `Carrier.taxCalculationStrategy` against `%coreshop.shipping.tax_calculation_strategies%`, `NotNull` on `ShippingRuleGroup.stopPropagation`, `Length` on `ShippingRule.name`.

### I-12 — Carrier resolution evaluates every rule twice per request — (dependent)
- **Where:** `Component/Shipping/Resolver/CarriersResolver.php:32-46` (`findAll()` + validator per carrier), `Component/Shipping/Validator/ShippingRuleCarrierValidator.php:31-37` and `Component/Shipping/Calculator/CarrierShippingRulePriceCalculator.php:44` both call `findValidShippingRule()`.
- **Fix:** memoize `findValidShippingRule()` per (carrier, shippable, address) within a request (reset on cart change), or let the validator store the found rule for the calculator. Load carriers with their rule groups in one query (`JOIN FETCH`).

### I-13 — Small component clean-ups — (dependent)
- `PostcodeConditionChecker::checkPostCode($postcode, $deliveryPostcode)` untyped parameters with `@param` docblock → native `string` types; `strpos(...) > 0` → `str_contains()`; `return $configuration['exclusion'] ? false : true;` → `return !$configuration['exclusion'];`.
- `AbstractConditionChecker`: both exceptions have the same message, and it describes `$subject` as "an array" although it must be a `CarrierInterface`; give each case its own message.
- `ShippingRuleGroup` / `Carrier` models: untyped getters/setters (`getPriority()`, `setPriority($priority)`, `getShippingRule()`, …) → native types.
- `PrioritizedServiceRegistry::$priortyMap` typo.

### I-14 — Tests
- The bundle has no tests. Add at least: a container test (compile with the bundle's config, all five autoconfiguration modes, `default_resolver` missing), a configuration tree test, unit tests for the postcode / amount / weight / dimension checkers (TODO-1 **C-01**, TODO-2 **B-04**, **B-07**), and a form test for `ShippingRuleConfigurationType` (TODO-2 **B-05**).
