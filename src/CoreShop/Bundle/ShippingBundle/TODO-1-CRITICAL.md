# ShippingBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (39 files: `CoreShopShippingBundle.php`, `Attribute/*`, `Controller/*`, `CoreExtension/*`, `DependencyInjection/**`, `Form/Type/**`), `composer.json` and all of `Resources/config/**` (`services.yml`, `services/{form,shipping-rules,shipping-taxation,pimcore,data_hub}.yml`, `pimcore/{config,admin,routing}.yml`, `doctrine/model/*.orm.xml`, `serializer/*.yml`, `validation/*.yml`). Because nearly all runtime logic of the bundle lives in `Component/Shipping`, every service the bundle wires was followed into it (`Calculator/*`, `Checker/*`, `Processor/*`, `Resolver/*`, `Rule/**`, `Validator/*`, `Model/*`). Call paths were also followed into `Component/Registry` (`Autoconfiguration`, `RegisterRegistryTypePass`, `RegisterSimpleRegistryTypePass`, `PrioritizedCompositeServicePass`, `ServiceRegistry`, `PrioritizedServiceRegistry`, `PriorityMap`), `Component/Rule` (`RuleValidationProcessor`, `ConditionCheckerInterface`), `RuleBundle` (`RuleType`, `AbstractNestedConfigurationType`), `ResourceBundle` (`ResourceController`, `CoreExtension/Select`, `Document/Select`), `CoreBundle` (`Resources/config/pimcore/coreshop/coreshop_shipping.yml`, `Form/Extension/{Cart,OrderShipment}CreationTypeExtension.php`) and the admin JS in `Resources/public/pimcore/js/**` where it defines what the forms receive.

Reference for API checks: `vendor/` (`symfony/*` 7.4.x, `six-it/pimcore-source`, `laminas/laminas-stdlib`), the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml` (+ its `…Deprecations.log`).

"Critical" here means: a security issue, money charged wrongly / data written wrongly, or an error that takes down every request, a whole area (cart, checkout, admin section), or the install / container build once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code (PHP 8.4.27) or by reading the vendored library source / the compiled container.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers. `php -l` passes on every PHP file, every service of the bundle resolves in the compiled container (e.g. `CarrierPriceCalculator`, `CompositePriceCalculator`, all registries and `coreshop.shipping_rule.*` / `coreshop.shipping.*` parameters), and the container deprecation log has no entry for this bundle. No implicitly nullable parameters. The modernisation items are in TODO-3 (**I-01** to **I-10**).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Money charged wrongly

### C-01 — An empty postcode entry matches every postcode: an empty field or a trailing comma makes a "postcodes" condition true (or false) for the whole world — (verified)
- **Where:**
  - `Form/Type/Rule/Condition/PostcodeConfigurationType.php:36-43`: `postcodes` has no constraint at all (the injected `$validationGroups` is never used), so `''` is accepted and saved.
  - `Component/Shipping/Rule/Condition/PostcodeConditionChecker.php:31,69-75`: `explode(',', $configuration['postcodes'])` keeps empty entries (`''` → `['']`, `'1010,'` → `['1010', '']`). In `checkPostCode()` `substr($deliveryPostcode, 0, strlen(''))` is `''` and `'' === ''` → `true`.
- **Reproduced:** running the checker's `checkPostCode()` loop with rule `''` and delivery postcode `1010` returns *match*.
- **Impact:**
  - `exclusion = false`: a rule meant to be limited to some postcodes becomes valid for **every** address (cheap regional rate / free shipping applied everywhere, a local-only carrier offered nationwide).
  - `exclusion = true`: the rule is **never** valid, so the carrier disappears or a surcharge is never applied.
  - A trailing comma (`1010,1020,`) — a very common way to edit CSV lists — triggers the same thing even though the list looks correct in the admin.
- **Fix:**
  - Checker: `array_filter(array_map('trim', explode(',', …)), static fn (string $p): bool => $p !== '')`; return the "no match" result if the list is empty.
  - Form: `new NotBlank(groups: $this->validationGroups)` on `postcodes`, plus a `Regex`/`Callback` that rejects empty CSV entries.
  - Add unit tests (empty string, trailing comma, whitespace, ranges — see TODO-2 **B-04**).

### C-02 — Deleting a shipping rule silently turns `shippingRule` conditions / actions of other rules into "always false" / "price 0" — (plausible) (dependent)
- **Where:**
  - `Form/Type/Rule/Common/ShippingRuleConfigurationType.php:48-63` stores only the **ID** of the referenced rule in the condition/action configuration (JSON), so there is no foreign key.
  - `Component/Shipping/Rule/Action/ShippingRuleActionProcessor.php:40-47,58-64`: `find($configuration['shippingRule'])` → `null` → `return 0`.
  - `Component/Shipping/Rule/Condition/ShippingRuleConditionChecker.php:35-42`: → `return false`.
  - `ResourceBundle/Controller/ResourceController.php:207-227` (`deleteAction`) removes the rule without checking references.
- **Impact:** a rule like "Base price = rule *DE base* + 10 %" (action `shippingRule`) charges **0 € shipping** after *DE base* is deleted, without any error or log entry. With the condition variant, carriers vanish from checkout. Nobody notices until orders come in.
- **Fix:**
  - Before deleting a `ShippingRule`, search the `coreshop_rule_condition` / `coreshop_rule_action` configurations of type `shippingRule` for its ID and refuse (or ask) in the admin (`pre_delete` event listener on `coreshop.shipping_rule`).
  - In the processor/checker, log a warning (or throw a dedicated exception that the price calculator turns into `NoShippingPriceFoundException`) instead of silently returning `0`.
  - Also see **C-03** (the direct FK from `ShippingRuleGroup` blocks the delete in the other case).

## Error that takes down a whole area

### C-03 — A carrier rule group without a shipping rule: admin save is a 500, and a `NULL` row takes down carrier resolution for every cart — (verified code path, plausible trigger) (dependent)
- **Where:**
  - `Resources/config/doctrine/model/ShippingRuleGroup.orm.xml:28-33`: `shippingRuleId` is `nullable="true"` with no `on-delete`.
  - `Resources/config/validation/ShippingRuleGroup.yml`: no constraint on `shippingRule`; `Form/Type/ShippingRuleGroupType.php:33` has no constraint either.
  - `Component/Shipping/Model/ShippingRuleGroup.php:97` `setShippingRule(ShippingRuleInterface $shippingRule)` is not nullable.
  - `Component/Shipping/Checker/CarrierShippingRuleChecker.php:47` passes `$rule->getShippingRule()` (untyped getter, can be `null`) to `RuleValidationProcessorInterface::isValid(ResourceInterface, RuleInterface, array)`.
- **Impact:**
  - Admin: saving a carrier with an empty "Shipping Rule" combo in a group → the form calls `setShippingRule(null)` → `Symfony\Component\PropertyAccess\Exception\InvalidTypeException` (not caught by `vendor/symfony/form/Extension/Core/DataAccessor/PropertyPathAccessor.php:setValue()`) → HTTP 500 instead of a validation message.
  - Runtime: any row with `shippingRuleId = NULL` (imports, SQL fixes, data from older versions — the column explicitly allows it) → `TypeError` in `CarrierShippingRuleChecker`. `CarriersResolver::resolveCarriers()` iterates **all** carriers, and `ShippingRuleCarrierValidator` runs for each, so the cart page, checkout shipping step and every cart price recalculation fail for every customer, not only for that carrier.
  - Deleting a `ShippingRule` that is used in a group fails with a SQL integrity-constraint violation (500, no message).
- **Fix:**
  - `validation/ShippingRuleGroup.yml`: `shippingRule: - NotBlank: { groups: [coreshop] }`.
  - Mapping: `nullable="false"` + `on-delete="CASCADE"` (deleting a rule removes its group rows), with a migration that deletes or reports existing `NULL` rows. Alternatively keep the FK restrictive but turn the integrity error into an admin message.
  - `CarrierShippingRuleChecker`: skip groups whose `getShippingRule()` is `null` (defensive, cheap).
  - Make `setShippingRule(?ShippingRuleInterface)` / `getShippingRule(): ?ShippingRuleInterface` explicit in the component.

### C-04 — Action autoconfiguration is registered on the wrong interface; an autoconfigured decorator of the rule processor breaks every shipping price calculation — (verified code path, plausible trigger)
- **Where:** `DependencyInjection/CoreShopShippingExtension.php:87-93` registers `CoreShop\Component\Shipping\Rule\Processor\ShippingRuleActionProcessorInterface` for the `coreshop.shipping_rule.action` tag. That interface is the **rule action processor** (the service that loops over a rule's actions, `Component/Shipping/Processor/ShippingRuleActionProcessor.php`), not an action. The action registry expects `CoreShop\Component\Shipping\Rule\Action\CarrierActionProcessorInterface` (`Resources/config/services/shipping-rules.yml:16-22`).
- **Impact:**
  - Any project service with `autoconfigure: true` that implements `ShippingRuleActionProcessorInterface` (the natural way to decorate/replace the processor) gets the action tag, without a `type`. `RegisterRegistryTypePass` derives a type from the class name and adds `register()`; `ServiceRegistry::register()` (`Component/Registry/ServiceRegistry.php:41-45`) throws `InvalidArgumentException("… needs to implement CarrierActionProcessorInterface")` when `coreshop.registry.shipping_rule.actions` is instantiated. That registry is needed for every shipping price → cart/checkout down. With `autoconfigure_with_attributes: true` the same happens for any class carrying `#[AsShippingRuleActionProcessor]` that implements the wrong interface.
  - The other way round, real custom actions (`CarrierPriceActionProcessorInterface`, `CarrierPriceModificationActionProcessorInterface`) are **never** autoconfigured; see TODO-2 **B-01**.
- **Fix:** use `CarrierActionProcessorInterface::class` in the `registerForAutoConfiguration()` call. Add a container test that compiles the bundle with an autoconfigured dummy action and an autoconfigured decorator of the processor.

## Container build / install

### C-05 — `default_resolver` has no default and is not required: the bundle cannot boot without CoreBundle — (verified code path)
- **Where:** `DependencyInjection/Configuration.php:53` (`scalarNode('default_resolver')->cannotBeEmpty()`, no `defaultValue`, no `isRequired()`), `DependencyInjection/CoreShopShippingExtension.php:58` `new Alias($configs['default_resolver'])`.
- **Impact:** the only value comes from `CoreBundle/Resources/config/pimcore/coreshop/coreshop_shipping.yml:7`. When the bundle is used standalone (it is published as its own `coreshop/shipping-bundle` package) or the CoreBundle config is not imported, the key is missing → `Warning: Undefined array key "default_resolver"` → `new Alias(null)` → `TypeError` → container build fails (every request, `cache:clear`, `composer install` scripts). `cannotBeEmpty()` does not help because it only applies when a value is given.
- **Fix:** either `->isRequired()` (clear config error) or make the node nullable (`->defaultNull()`) and only set the `DefaultCarrierResolverInterface` alias when a value is configured. Validate that the value is an existing service id (`$container->has()` in a compiler pass) to get a readable error.
