# PaymentBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Code quality, readability, logic and modernization items. None of them is a functional bug on its own. Items marked **(dependent)** live outside this bundle.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Design

### I-01 — One price API for payment rules, evaluated once per cart
- **Where:** `Component/Payment/Calculator/{PaymentPriceCalculatorInterface,PaymentProviderRulePriceCalculatorInterface,PaymentProviderRulePriceCalculator}.php`, **(dependent)** `Component/Core/Order/Processor/CartPaymentProcessor.php:54-66`.
- **Idea:**
  - Two calculator interfaces with different signatures exist; only one is used (TODO-2 **B-01**). Keep one.
  - `CartPaymentProcessor` calls `findValidPaymentProviderRule()` to get the label, then `PaymentProviderRulePriceCalculator::getPrice()` evaluates all conditions again. Return a small result object (`rule`, `price`, `modification`) from one call, or pass the found rule into the calculator.
  - Price and modification are computed from different bases (`$price` vs. `getPaymentTotal()`); make the base explicit in the API (TODO-2 **B-05**).

### I-02 — Remove dead or misleading wiring
- **Where / Idea:**
  - Price calculator registry, `PaymentCalculatorsPass`, `#[AsPaymentPriceCalculator]` and its autoconfiguration, unless TODO-2 **B-01** decides to use them.
  - `coreshop.payment.validator` tag on `PaymentProviderRuleValidator` (`Resources/config/services/paymentprovider-rules.yml:89`): nothing collects it.
  - The commented-out `PaymentProviderRuleConditionChecker` block (`paymentprovider-rules.yml:120-125`, duplicate of `:48-53`) and `//$this->registerPimcoreModels(…)` (`DependencyInjection/CoreShopPaymentExtension.php:45`).
  - Unused parameter `coreshop.form.type.payment_provider_rule.rule.validation_groups` (`services/form.yml:9`).
  - Unused `active` option in `PaymentProviderChoiceType` (`:49`) and `PaymentProviderRuleChoiceType` (`:54`).
  - `if (array_key_exists('pimcore_admin', $configs))` (`CoreShopPaymentExtension.php:47`) is always true because of `addDefaultsIfNotSet()`.
  - Empty constructor bodies formatted as `__construct(\n        ) {` in the three compiler passes and in `Component/Payment/Model/{PaymentProvider,PaymentProviderRule}.php` (leftover of a refactoring tool).

### I-03 — Make the resolver decoration chain readable
- **Where:** `Resources/config/services/paymentprovider-rules.yml:97-118`, **(dependent)** `CoreBundle/Resources/config/services/payment.yml:8-13`.
- **Idea:** `PaymentProviderResolverInterface` is an alias to `PaymentProviderResolver`, which itself `decorates` that alias (priority 800); `RuleBasedPaymentProviderResolver` (1) and `StoreBasedPaymentProviderResolver` (10, which ignores `.inner` and queries again) decorate it as well. The effective chain is hard to see and `StoreBased…` silently replaces the base resolver. Define the base resolver as the plain service, let the others decorate with explicit priorities and comment the order (base → store filter → rule filter). Let `StoreBased…` filter the inner result instead of re-querying, or replace the base on purpose and say so.

### I-04 — PHP 8.4 typing of the models and interfaces
- **Where:** `Component/Payment/Model/*.php` (untyped properties, `@var` docblocks, setters without return type, getters returning `mixed`), `Component/Payment/Model/PaymentInterface.php` (docblock-only types), `PaymentProviderInterface.php`, `PaymentProviderRuleGroupInterface.php`.
- **Idea:**
  - Typed properties with defaults matching the mapping (`?int $id = null`, `string $identifier`, `int $position = 1`, `bool $stopPropagation = false`, `?Asset $logo = null`, `?\DateTimeInterface $datePayment`), `: void` / `: static` setters, real return types. Mark nullability as the mapping says (TODO-1 **C-04**, TODO-2 **B-11**, **B-17**).
  - `PaymentProviderRuleGroup` uses `private` properties while all other models use `protected`; align (subclassing in Core).
  - `getInterface()` in `CoreExtension/PaymentProvider.php:49-52` returns `'\\' . PaymentProviderInterface::class`; keep as Pimcore expects, but add a comment.
  - This is a BC break for projects extending the models; do it in a major version, with a rector rule or an upgrade note.

### I-05 — Constructor promotion with `readonly`, `final` where possible
- **Where:** all services in `Component/Payment/{Calculator,Checker,Processor,Resolver,Rule,Validator}` (`protected` / `private` promoted properties without `readonly`), form types (`protected array $validationGroups`).
- **Idea:** `private readonly` for injected dependencies; `final` for classes that are not meant as extension points (form types such as `PaymentProviderRuleActionChoiceType`, `PaymentProviderRuleConditionCollectionType`, `PaymentProviderRuleGroup{,Collection}Type` are not `final` while their siblings are). Projects extend through decoration, not inheritance.

## Configuration / DI

### I-06 — Configuration tree cleanup
- **Where:** `DependencyInjection/Configuration.php`.
- **Idea:**
  - `autoconfigure_with_attributes` is a `scalarNode` (`:61`) → `booleanNode`.
  - `permissions` is a `scalarNode` with an **array** default (`:223-226`). The default bypasses validation, but any user value fails with `Expected "scalar"` (`symfony/config` 7.4 `ScalarNode::validateType()`). Use `arrayNode()->scalarPrototype()`.
  - `->prototype('scalar')` (`:209-221`) → `->scalarPrototype()`.
  - The four resource sections repeat the same node structure; extract a helper (`addResource($node, $name, $defaults)`), as other CoreShop bundles do.
  - `payment_provider_rule.translation.classes.repository`, `payment_provider.translation.classes.repository` and `payment_provider_rule_group.classes.repository` are `cannotBeEmpty()` without a default; give them the default `EntityRepository` explicitly or drop `cannotBeEmpty()`.

### I-07 — Service definitions
- **Where:** `Resources/config/services.yml`, `services/{form,paymentprovider-rules,workflow,pimcore}.yml`.
- **Idea:**
  - `_defaults: public: true` in every file. Make services private and keep only what is fetched with `$container->get()` public (`coreshop.repository.*` are public anyway, `CoreExtension/PaymentProvider.php` reads two of them).
  - Service id typo `coreshop.state_machine.marking_store.payment_orm_marketing_store` (`services/workflow.yml:11`, `pimcore/workflow.yml:6`) → `…payment_orm_marking_store`, with a deprecated alias.
  - `coreshop.state_machine.marking_store.single_state.state` is a generic id defined only in this bundle but describes a shared concept; move it to `WorkflowBundle` or prefix it with `payment`.
  - `RuleConditionsValidationProcessor` gets the type string `'payment Rules'` (`paymentprovider-rules.yml:33`); use a constant like the other bundles (`payment_provider_rule`).
  - `shared: false` on the processors (`:30,39,80`) has no visible reason (they are stateless); remove it or comment why.
  - Form types could use `autoconfigure: true` + bound `$validationGroups` instead of 14 parameters and explicit `form.type` tags.

### I-08 — Doctrine mapping cleanup and ORM 3 readiness
- **Where:** `Resources/config/doctrine/model/*.orm.xml`.
- **Idea:**
  - `PaymentProvider.orm.xml:27` writes `<order-by-field name="priority">ASC</order-by-field>`. The XSD (`doctrine/orm` `doctrine-mapping.xsd:495-502`) allows no text content; it only works because XSD validation is off in ORM 2.20. ORM 3 validates by default. Use `direction="ASC"` like `PaymentProviderRule.orm.xml:34`.
  - `one-to-many … target-entity="…\PaymentProviderRuleGroup"` (concrete class, `PaymentProvider.orm.xml:22`) while everything else targets interfaces; use `PaymentProviderRuleGroupInterface` (resolved via `resolve_target_entities`).
  - Column naming is mixed (`orderId`, `datePayment`, `paymentProviderId`, `stopPropagation` vs. `total_amount`, `currency_code`, `payment_provider_id`). Not worth a migration alone, but pick snake_case for new columns.
  - `Payment.state` has no length / default; `Payment.datePayment` is NOT NULL although the setter accepts anything.
  - `Payment.orderId` duplicates Core's `` `order` `` column (TODO-2 **B-16**). Long term: one relation.

### I-09 — Serializer metadata
- **Where:** `Resources/config/serializer/*.yml`.
- **Idea:** `PaymentProvider.active` is `type: string` (`Model.PaymentProvider.yml:10-13`), so JSON contains `"1"` / `""`; use `boolean`. `PaymentProviderRuleGroup` has `xml_root_name: payment_provider_rule` (`Model.PaymentProviderRuleGroup.yml:3`) → `payment_provider_rule_group`. Neither translation class exposes `locale`; the ExtJS editor relies on the collection being keyed by locale. Document that, or expose `locale` explicitly.

### I-10 — Symfony 7.4 bundle style
- **Where:** `CoreShopPaymentBundle.php`, `DependencyInjection/*`.
- **Idea:** optional: move to `AbstractBundle` (`configure()`, `loadExtension()`) once `AbstractResourceBundle` supports it; register autoconfiguration via `#[AutoconfigureTag]` on the component interfaces would couple the component to Symfony, so keep it in the extension. Not urgent, the current `Extension` API is fully supported in 7.4.

## Forms

### I-11 — Form type cleanups
- **Where:** `Form/Type/**`.
- **Idea:**
  - `PaymentProviderRuleTranslationType extends RuleType` (`:25`) but overrides `buildForm()` completely; it should extend `AbstractResourceType`.
  - `PaymentProviderRuleType` adds `name` as `TextareaType` (`:35`); the ExtJS field is a single-line `textfield` and `RuleType` uses `TextType`.
  - `PaymentProviderRuleGroupType::priority` is a `NumberType` (float) for an integer column (`:31`) → `IntegerType`.
  - Block prefixes are inconsistent: `coreshop_payment_action_collection` (`PaymentProviderRuleActionCollectionType.php:35`), `coreshop_payment_provider_rule_action_condition_choice` for the **condition** choice (`PaymentProviderRuleConditionChoiceType.php:27`). Rename with BC in mind (themes may reference them).
  - `AdditionPercentActionConfigurationType` and `DiscountPercentActionConfigurationType` are identical except for the block prefix; one `PercentActionConfigurationType` with a `block_prefix` option is enough.
  - `Type(type: 'numeric')` after a `NumberType` / `MoneyType` (`AmountConfigurationType.php:45,52`, percent types `:44`) can never fail; remove.
  - `NestedConfigurationType` receives `$validationGroups` but the parent hard-codes `groups: ['coreshop']` for `operator` (`RuleBundle/Form/Type/Rule/Condition/AbstractNestedConfigurationType.php:33`); pass them through.
  - `PaymentProviderRuleChoiceType` loads all rules and sorts in PHP (`:43-47`); `findBy([], ['name' => 'ASC'])`.
  - `PaymentProviderChoiceType::buildView()` builds description/instruction maps by iterating the choices again; `choice_attr` (`data-description`, `data-instructions`) would put the data on each option and remove the custom vars.

## Rule evaluation

### I-12 — Condition checker base: correct messages and type checks
- **Where:** `Component/Payment/Rule/Condition/AbstractConditionChecker.php:29-37`.
- **Idea:** the first message is copied from shipping ("needs to be an array with values shippable, address and carrier"). Use `Assert::isInstanceOf($subject, PaymentProviderInterface::class)` and `Assert::isInstanceOf($params['payable'] ?? null, PayableInterface::class)`; today a wrong `payable` type becomes a `TypeError` deeper down.

### I-13 — Avoid repeated rule evaluation and repository lookups per request
- **Where:** `Component/Payment/Resolver/RuleBasedPaymentProviderResolver.php:47-51`, `Component/Payment/Validator/PaymentProviderValidator.php:36-44`, `Rule/Condition/PaymentProviderRuleConditionChecker.php:41`, `Rule/Action/PaymentProviderRuleActionProcessor.php:41,57`.
- **Idea:**
  - `PaymentProviderValidator` resolves the full provider list (all rules of all providers) to check one provider. Check the given provider directly: store + active + `PaymentProviderRuleValidator`.
  - The payment step renders the choice list (all providers), then `validate()` runs again on submit, then `CartPaymentProcessor` once more. A per-cart memo (keyed by cart id + a cart hash) avoids evaluating the same rules 3–4 times per request.
  - Referenced rules are loaded with `find()` on every evaluation; Doctrine's identity map helps within one request, but the nested evaluation still runs every time.

### I-14 — Document and harden `stopPropagation` and ordering
- **Where:** `Component/Payment/Checker/PaymentProviderRuleChecker.php:44-58`, `Resources/public/pimcore/js/provider/item.js:220-237`.
- **Idea:** the semantics are "if this group's rule is **not** valid, stop and treat the provider as unavailable", and the grid allows only one group with the flag. That is the opposite of what "stop propagation" usually means (stop **after** a match) and is not documented. Describe it in the admin tooltip and docs. The checker relies on the collection being ordered by `priority` (Doctrine `order-by`); after an in-request change (admin save, fixtures) the collection is in insertion order. Sort explicitly (`Criteria::orderBy(['priority' => 'ASC'])`).

## Admin UI

### I-15 — Copy-paste leftovers and small JS issues
- **Where:** `Resources/public/pimcore/js/**`.
- **Idea:**
  - `provider/item.js:259` adds `carrier: this.data.id` to new rows; `:221` uses `coreshop_carriers_stop_propagation`, `:281` `coreshop_carrier_costs_icon` (shipping keys); `:279` comment "Payment locations and costs".
  - `provider/panel.js:86-88` and `:106-108` define `getItemClass()` twice; the first returns the non-existent `coreshop.payment.provider.item`. `:53` `autoload` should be `autoLoad`. `:79-81` swallows every error in `getConfig()` (the panel just never opens).
  - `paymentproviderrule/actions/additionPercent.js:26` labels the field with `coreshop_action_discountPercent_percent`.
  - `provider/item.js` sends `gatewayConfig.factoryName` / `gatewayConfig.config.*` although this bundle has no gateway config; that belongs to `PayumPaymentBundle` (**dependent**). The PayPal `password` and `signature` fields (`provider/gateways/paypal_express_checkout.js:26-38`) are plain text fields and are loaded into the browser in clear text; use `inputType: 'password'` and do not send stored secrets back to the client (write-only fields).
  - `Resources/translations/admin.hu.yml` and most other languages are empty or partial (20 keys in `en`/`de`).

### I-16 — CoreExtension data types: no static container access
- **Where:** `CoreExtension/PaymentProvider.php:39-47` (`\Pimcore::getContainer()->get(…)` / `getParameter(…)`).
- **Idea:** shared pattern of `ResourceBundle/CoreExtension/Select.php`; Pimcore instantiates data types without DI, so a static lookup is needed, but it can be one place in the base class (a lazy service locator) instead of every subclass. `public string $fieldtype` + `getFieldType()` returning it can be a constant.

## Packaging, tests, docs

### I-17 — `composer.json`
- **Where:** `composer.json`.
- **Idea:** no `php` constraint (the component has `~8.4.0`); add it. Declare what the bundle uses directly: `symfony/framework-bundle`, `symfony/form`, `symfony/validator`, `symfony/workflow`, `symfony/options-resolver`, `doctrine/orm`, `jms/serializer-bundle` (all `^7.4` / current majors). `phpstan/phpstan ^1.10` → `^2.1` with the matching extensions. `minimum-stability: dev` in a library package has no effect for consumers; remove or keep consistent across bundles.

### I-18 — Tests
- **Where:** no PHPUnit tests in the bundle or `Component/Payment`; Behat covers conditions and actions (`features/domain/payment_provider/**`).
- **Idea:**
  - Unit: `PaymentProviderRuleChecker` with a group without rule (TODO-1 **C-02**) and `stopPropagation`; self-referencing rule (TODO-1 **C-01**); `AmountConditionChecker` with `gross` / `useTotal` and factor 10 000 (TODO-2 **B-04**, **B-05**); `PaymentProviderRuleConfigurationType` round trip (TODO-2 **B-06**).
  - Container: compile with `autoconfigure_with_attributes: true` and one attribute-registered condition/action (TODO-2 **B-02**, **B-03**); instantiate `coreshop.registry.payment.price_calculators` (TODO-2 **B-01**).
  - Behat: "payment total equals order total including the payment fee" on a committed order (TODO-1 **C-03**).
  - Mapping: `doctrine:schema:validate` and an XSD-validated metadata load (**I-08**).

### I-19 — Documentation
- **Where:** `README.md`, `docs/03_Development/10_Payment/01_Payment_Provider.md`; there is no `docs/03_Bundles/Payment_Bundle.md`.
- **Idea:** document payment provider rules (conditions, actions, groups, priority and the `stopPropagation` semantics from **I-14**), how to add a custom condition/action (tags `coreshop.payment_provider_rule.{condition,action}` with `type` and `form-type`, or the attributes once TODO-2 **B-03** is fixed), the unit of `getPaymentTotal()` (TODO-2 **B-05**), and the payment state machine (`PaymentTransitions`, TODO-2 **B-12**). Fix "It is build" and the `coreshop.org` link in `README.md:9`.
