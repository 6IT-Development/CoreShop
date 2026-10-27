# RuleBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, performance, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items (Symfony 8, Doctrine DBAL 4 / ORM 3). None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as sub-bullets.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(verified)** were confirmed by reading the vendored library source or the compiled container.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — `composer.json` does not describe what the bundle needs
- **Where:** `composer.json:26-57`.
- **Problem / Fix:**
  - No `php` constraint. Add `"php": "^8.4"` (Pimcore source requires `~8.4.0`).
  - Directly used packages are not declared: `symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel` (bundle, `DataCollector`), `symfony/http-foundation`, `symfony/console` (command), `symfony/form`, `symfony/options-resolver`, `symfony/validator` (`NotBlank`), `symfony/event-dispatcher-contracts`, `doctrine/orm`, `doctrine/collections`, `nesbot/carbon` (used directly in `Assessor/RuleAvailabilityAssessor.php:21`), `twig/twig` + `symfony/web-profiler-bundle` as `suggest` (collector template). Add them with `^7.4` for Symfony.
  - `jms/serializer-bundle` is configured in `Resources/config/pimcore/config.yml:4-9` but not required.
  - `require-dev` pins PHPStan 1.x; bump to PHPStan 2 together with the other bundles.
  - `"minimum-stability": "dev"` and `repositories` (`path` `../../*/*`) have no effect for consumers of a library package; drop them or move them to the root project.

### I-02 — Bundle, extension and configuration: use the Symfony 7.x APIs
- **Where:** `DependencyInjection/Configuration.php`, `DependencyInjection/CoreShopRuleExtension.php`, `DependencyInjection/Compiler/*.php`, `CoreShopRuleBundle.php`.
- **Fix:**
  - `Configuration.php:106,110,114,118`: `->prototype('scalar')` → `->scalarPrototype()`. `:36` the `/** @var ArrayNodeDefinition */` is not needed. `:70,87` remove the commented-out `form` nodes (they even reference `CurrencyType`). `:41` see TODO-2 **B-06**.
  - `CoreShopRuleExtension.php:38`: `$configs = $this->processConfiguration(...)` overwrites the input with a different shape; name it `$config`. `:43` `array_key_exists('pimcore_admin', …)` is always true (`addDefaultsIfNotSet()`); remove the condition.
  - `RuleAvailabilityAssessorPass.php:23-34`: make it `final`, fix the empty-constructor formatting (`public function __construct(\n        ) {`), or drop the class and instantiate `RegisterSimpleRegistryTypePass` subclass-free in `CoreShopRuleBundle::build()`.
  - `TraceableValidationProcessorPass.php:41-42`: `sprintf()` → string interpolation; `:53` `count($validationProcessors) > 0` → `$validationProcessors !== []`.
  - Consider the Symfony 7.1+ `AbstractBundle` style only if `ResourceBundle`'s `AbstractResourceBundle`/`AbstractModelExtension` are migrated too (dependent); not worth doing alone.

### I-03 — Replace the DBAL `array` column type (forward compatibility DBAL 4 / ORM 3, no more `unserialize()` of DB content) — (verified) (dependent: Component/Rule, every rule bundle)
- **Where:** `Resources/config/doctrine/model/Condition.orm.xml:12`, `Action.orm.xml:12` (`type="array"`). `vendor/doctrine/dbal/src/Types/ArrayType.php`: `@deprecated Use JsonType instead`, triggers a deprecation on every conversion; removed in DBAL 4. Pimcore currently pins `doctrine/dbal ^3.8`, so this is not a blocker for Symfony 7.4, but it blocks DBAL 4.
- **Fix:**
  - Switch to `type="json"`. This needs a data migration for `coreshop_rule_condition.configuration` and `coreshop_rule_action.configuration` (`unserialize()` → `json_encode()`).
  - Nested conditions hold `Condition` **objects** inside `configuration` (TODO-1 **C-01**); convert them to `['type' => …, 'configuration' => …]` arrays during the migration and let `NestedConditionChecker` build conditions from arrays (dependent: Component/Rule, all `NestedConfigurationType`s).
  - Do this **before** adding typed properties to `Component/Rule/Model/Condition`/`Action`: `unserialize()` of already stored nested objects would then fail with "Cannot assign null to property …" for old rows.
  - Removing `unserialize()` also removes an object-injection sink for anyone who can write to these tables.

### I-04 — PHP 8.4 modernisation
- **Where / Fix:**
  - Promoted constructor properties that are never reassigned → `private readonly`: `Assessor/RuleAvailabilityAssessor.php:30`, `Collector/RuleCollector.php:28`, `Command/RuleAvailabilityProcessorCommand.php:29`, `Processor/RuleAvailabilityProcessor.php:31-33`, `Form/DataMapper/*FormMapper.php:32`, `Form/Type/AbstractConfigurableRuleElementType.php:34`, `Form/Type/RuleActionChoiceType.php:28`, `Form/Type/RuleConditionChoiceType.php:28`, `Event/RuleAvailabilityCheckEvent.php:27-28` (`$rule`, `$ruleType`; `$available` stays mutable or becomes `public private(set) bool $available`).
  - Attributes: `Attribute/AsRuleConditionsValidationProcessor.php` → `public function __construct(public readonly ?string $type = null)` is not possible while `Autoconfiguration::getProperties()` only reads getters (dependent: Registry); keep getters, but make the properties `readonly`.
  - Missing native types: `Form/Type/AbstractConfigurableRuleElementType.php:93` `$configurationType` → `string`, `:101` `$data = null` → `mixed $data = null`, `Doctrine/ORM/RuleRepository.php:35,46` → `string`, `Collector/RuleCollector.php:28` → `/** @param list<TraceableRuleConditionsValidationProcessorInterface> */` (or `iterable` + `#[AutowireIterator]`).
  - `Command/RuleAvailabilityProcessorCommand.php`: use `#[AsCommand(name: 'coreshop:rules:check-availability', description: '…')]` (lazy `list`, no need to build the processor + EM + all repositories just to list commands), `return Command::SUCCESS`; Symfony 7.3+ also allows an invokable command with `__invoke(SymfonyStyle $io): int`.
  - Event name: add `public const string NAME = 'coreshop.rule.availability_check';` (typed class constants, PHP 8.3+) to `RuleAvailabilityCheckEvent` and use it in the processor and in listeners. Same for `TraceableValidationProcessorPass::RULE_CONDITIONS_VALIDATIONS_PROCESSOR` and `RuleAvailabilityAssessorPass::RULE_AVAILABILITY_ASSESSOR_TAG` (`public const string`).
  - Make non-extension-point classes `final` or mark them `@internal` consistently: `Form/DataMapper/*` (docblock `@internal` but not final), `Doctrine/ORM/RuleRepository.php` (must stay open, it is the base for five repositories).

### I-05 — Clock and timespan logic: one implementation, testable — (dependent: Component/Product, Component/Order)
- **Where:** `Assessor/RuleAvailabilityAssessor.php:47-70` duplicates the date logic of `Component/Product/Rule/Condition/TimeSpanConditionChecker.php` and `Component/Order/Cart/Rule/Condition/TimeSpanConditionChecker.php`, all with `Carbon::now()` and `/ 1000` (millisecond timestamps from the ExtJS date fields).
- **Fix:** inject `Psr\Clock\ClockInterface` (`symfony/clock`, alias `clock`) instead of `Carbon::now()`; move the "is this timespan active / expired / in the future" logic into one small value object in `Component/Rule` used by the assessor and both checkers. Carbon is then not needed here at all (`(int) ($ms / 1000)` + `DateTimeImmutable`).

## Performance

### I-06 — The availability run loads every active rule of every type, with N+1 queries, every maintenance cycle
- **Where:** `Assessor/RuleAvailabilityAssessor.php:34-37` (`findActive()` = all active rules), `:42` (lazy `conditions` → one query per rule), `Processor/RuleAvailabilityProcessor.php:40-47` (everything stays in the unit of work until one `flush()` at the end, then flushes the whole default EM).
- **Impact:** shops with many product specific price rules (one or more per product) load tens of thousands of rules and run as many queries in every maintenance run; memory grows with the number of rules.
- **Fix:**
  - Add a repository method that returns only active rules with at least one top-level `timespan` condition whose `dateTo` could have passed (join + `addSelect` on `conditions`), or at least `toIterable()` + batch `flush()`/`clear()`.
  - Dispatch the event and `persist()` only for rules that change; `persist()` of an already managed entity is not needed at all.
  - Use the entity manager of the rule class (`ManagerRegistry::getManagerForClass()`) instead of the hard-coded `@doctrine.orm.default_entity_manager` (`Resources/config/services/rule_active_validator.yml:6`).
  - Log (PSR logger) or print (command) which rules were disabled; add `--dry-run` to the command.
  - `ProductQuantityPriceRule` has `timespan` conditions but no assessor is registered for it (dependent: ProductQuantityPriceRulesBundle); register one or document why not.

## Logic / debug tooling

### I-07 — Collector and traceable processors: lighter and only when the profiler is on
- **Where:** `DependencyInjection/Compiler/TraceableValidationProcessorPass.php:34`, `Collector/RuleCollector.php`, `Resources/views/Collector/rule.html.twig`.
- **Fix:**
  - Gate on `$container->hasDefinition('profiler')` instead of `kernel.debug` (no decoration in the debug test kernel or in debug CLI without profiler; see TODO-2 **B-04**).
  - Store scalars / `cloneVar()` data only (TODO-2 **B-05**); then the template can drop `dump()` (which needs `symfony/debug-bundle`) and use the profiler's `profiler_dump()`.
  - `getProcessedConditions()`/`getProcessedRules()`/`getTypes()` (`:32-45`) read keys that do not exist before `collect()` or after `reset()`; return `$this->data[...] ?? []`.
  - `:58-62`: `processedRules` counts a rule once per subject; the toolbar "Processed N" is inflated. Count unique rule ids.
  - Consider `LateDataCollectorInterface` (rules evaluated in `kernel.terminate` listeners or streamed responses are currently missed).
  - Template: two empty lines and an empty `class=""` (`:52`), `Conditions`/`Actions` header rows have one `<th>` for a 4-column table (use `colspan="4"`).

### I-08 — Form types: remove dead options and duplicated code — (dependent: IndexBundle, all rule bundles)
- **Where / Fix:**
  - `Form/Type/AbstractConfigurableRuleElementType.php:80-88`: the `configuration_type` option is set by `AbstractConfigurationCollectionType` for each prototype but never read; either use it in `PRE_SET_DATA` (it would make prototypes work) or remove it.
  - `Form/Type/Core/AbstractConfigurationCollectionType.php:36-53`: prototypes are created eagerly for every registered type on every form build, and only used by `buildView()`; the ExtJS admin never renders a view. Build them in `buildView()` only, or drop them (see TODO-2 **B-07**).
  - `Form/DataMapper/ActionsFormMapper.php` and `ConditionsFormMapper.php` are identical except for the `instanceof` check; merge into one mapper with the interface as a constructor argument. `IndexBundle` has its own copies of `AbstractConfigurationCollectionType`, `ConditionsFormMapper` and `EmptyConfigurationFormType`; reuse these instead (dependent).
  - Remove the commented-out code in both mappers (`:38`).
  - `RuleConditionType.php:26`, `RuleActionType.php:26`: `array $options = []` default differs from the parent signature; drop the default.
  - `RuleConditionChoiceType.php:46`: block prefix `coreshop_action_condition_choice` → `coreshop_rule_condition_choice` (consistent with `coreshop_rule_action_choice`; check JS/templates for the old name first).
  - `RuleType.php:30`: `name` has no `NotBlank`/`Length(max: 255)`; the columns are `VARCHAR(255)` in every rule table (validation files live in the rule bundles, check they all have it).
  - `Rule/Condition/AbstractNestedConfigurationType.php:33`: `groups: ['coreshop']` is hard-coded; accept the validation groups like the subclasses do (already tracked as PaymentBundle TODO-3 **I-11**). Put `['and', 'or', 'not']` into constants shared with `NestedConditionChecker`.

### I-09 — Repository cleanup
- **Where:** `Doctrine/ORM/RuleRepository.php`.
- **Fix:** `->andWhere('o.active = 1')` → `->andWhere('o.active = :active')->setParameter('active', true)` (portable, typed); add the typed/`DISTINCT` changes from TODO-2 **B-09**; add a DB index on `coreshop_rule_condition.type` / `coreshop_rule_action.type` (mapping `Condition.orm.xml`, `Action.orm.xml`) since both finders filter on it.

### I-10 — Service and config file cleanup
- **Where / Fix:**
  - `Resources/config/services/form.yml` and `Resources/config/pimcore/routing.yml` are empty; remove them and the import in `services.yml:2`.
  - `Resources/config/services/rule_active_validator.yml`: the file name does not match its content (availability processor + assessor registry); rename to `availability.yml`.
  - The tag name `coreshop.registry.rule_availability_assessor` (`RuleAvailabilityAssessorPass.php:25`) is identical to the registry **service id** (`rule_active_validator.yml:9`), and the parameter is called `coreshop.registry.rule_availability_assessors`. Use a tag like `coreshop.rule.availability_assessor` and a parameter `coreshop.rule.availability_assessors` (keep the old tag as an alias for one minor version; five service files in Order/Payment/Shipping/Product use it).
  - `RegisterSimpleRegistryTypePass` creates the autowiring alias `ServiceRegistryInterface $registryRuleAvailabilityAssessorServiceRegistry` from the tag name (dependent: Registry); with the rename above it becomes `$ruleAvailabilityAssessorServiceRegistry`.
  - `Resources/config/services/commands.yml:6`: add `description` to the `console.command` tag (or use `#[AsCommand]`, I-04) so the command stays lazy.

### I-11 — Serializer metadata consistency
- **Where:** `Resources/config/serializer/Model.Condition.yml`, `Model.Action.yml`.
- **Fix:** `sort` uses `type: int` while `id` uses `integer`; pick one. `id` is only in `Detailed`/`Version`; add `List` if list views of rules should show condition/action ids. Give `configuration` a real type once nested conditions are plain arrays (I-03 / TODO-1 **C-01**), e.g. `array<string, mixed>`.

### I-12 — No tests
- **Where:** the bundle has no `Tests/` directory.
- **Fix:** add unit tests for `RuleAvailabilityAssessor` (future/active/expired/missing keys/multiple timespans, with a mock clock, TODO-2 **B-01**), `RuleAvailabilityProcessor` (event type, only changed rules persisted, one failing rule does not stop others), `ConditionsFormMapper`/`ActionsFormMapper` (existing id updated, unknown id → new entity, removed entries), a JMS round trip of a `nested` condition (TODO-1 **C-01**), and a container test that the traceable decorators keep `shared: false` (TODO-2 **B-04**).

### I-13 — Attributes: make them carry what the registries need
- **Where:** `Attribute/AsRuleAvailabilityAssessor.php` (empty), `Attribute/AsRuleConditionsValidationProcessor.php` (`type` is turned into a tag attribute but `TraceableValidationProcessorPass` never reads it).
- **Fix:** add `type` (and optionally `priority`) to `AsRuleAvailabilityAssessor` (TODO-2 **B-03**); either use `type` of `AsRuleConditionsValidationProcessor` (e.g. as collector tab name instead of `getType()`) or remove it. Document both attributes in the README together with `autoconfigure_with_attributes`.
