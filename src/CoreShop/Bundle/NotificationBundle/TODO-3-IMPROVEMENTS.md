# NotificationBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Code quality, readability, logic and modernization items. None of them is a functional bug on its own. Items marked **(dependent)** live outside this bundle.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Design

### I-01 — Isolate rule execution per rule and give it a proper result
- **Where:** **(dependent)** `Component/Notification/Messenger/Handler/NotificationMessageHandler.php`, `Component/Notification/Processor/RulesProcessor.php`.
- **Idea:** this is the structural version of TODO-2 **B-01**. Options, from smallest to cleanest:
  1. Catch `\Throwable` per rule in the handler, log it with the rule id and subject, continue, and only rethrow (as recoverable) for transient errors.
  2. Let the handler fan out: for each matching rule dispatch a `ApplyNotificationRuleMessage(ruleId, resourceType, resourceId, params)`. Retries then only repeat the failed rule. The failure transport shows which rule failed.
  Option 2 also makes the `PimcoreObjectStamp` from `EventListener/MessengerListener.php` more useful (one entry per rule in the failure list).

### I-02 — Do not let `AbstractNotificationRulePass` extend `RegisterRegistryTypePass`
- **Where:** `DependencyInjection/Compiler/AbstractNotificationRulePass.php:30-110`.
- **Idea:** the class overrides `process()` completely and only inherits the four constructor properties. Inheritance suggests the parent's behaviour applies, which it does not. Implement `CompilerPassInterface` directly with typed, readonly constructor properties (`string $registry`, `string $formRegistry`, `string $parameter`, `string $tag`, `string $type`, `string $interface` for TODO-2 **B-12**). The untyped promoted `protected $type` (`:37`) and the untyped parent arguments (`:33-36`) go away.

### I-03 — Decide whether the per-type registries and form registries are an API
- **Where:** `DependencyInjection/Compiler/AbstractNotificationRulePass.php:71-85,91,95`.
- **Idea:** the pass registers every service twice: once as `<notification-type>.<type>` in the main registry (used by `RuleApplier` and `RuleConditionsValidationProcessor`) and once as `<type>` in `coreshop.registry.notification_rule.{actions,conditions}.<notification-type>` (unused, private, removed by the container). Either remove the per-type registries, or make them public API (public alias, correct interface, documented in `docs/03_Development/09_Notification_Rules/03_Custom_Types.md`).

### I-04 — Detect duplicate `(notification-type, type)` registrations at compile time
- **Where:** `DependencyInjection/Compiler/AbstractNotificationRulePass.php:89-99`.
- **Idea:** two services with the same `type` and `notification-type` are only detected when the registry is instantiated at runtime (`ExistingServiceException` from `Component/Registry/ServiceRegistry.php:38-40`), i.e. in the admin form or in the worker. `$registeredTypes[$fqtn]` already exists: throw a `LogicException` naming both service ids when the key is already set.

### I-05 — Rewrite `sortAction` as one bulk update in a transaction
- **Where:** `Controller/NotificationRuleController.php:87-165`.
- **Idea:** fixes TODO-2 **B-03** and **B-13** together and halves the code. Load both rules with `findOr404()`, compute the target index once (`before` → target sort, `after` → target sort + 1, minus 1 when moving down), then inside `$this->manager->wrapInTransaction()` run one DQL `UPDATE … SET o.sort = o.sort - 1 WHERE o.sort > :from AND o.sort <= :target` (down) or `o.sort + 1 WHERE o.sort >= :target AND o.sort < :from` (up), and set the rule's sort to the target.
  Move the DQL into `NotificationRuleRepository::moveTo(NotificationRuleInterface $rule, int $target): void`, so the controller only validates input. The same broken sort implementation probably exists in other rule controllers (cart/product price rules); check them in the same change.

### I-06 — `getConfigAction`: simplify the loops and use the injected parameter bag
- **Where:** `Controller/NotificationRuleController.php:30-85`.
- **Idea:**
  - `$types = array_values(array_unique([...$actionTypes, ...$conditionTypes]));` replaces `:46-56` (and fixes the non-strict `in_array()`).
  - `$this->container->get('parameter_bag')` (`:62,70`) → `$this->parameterBag->has(...)`. `AdminController` already injects it (`ResourceBundle/Controller/AdminController.php:30-41`), and the service-locator lookup hides the dependency.
  - The `array_key_exists` + `array_merge` blocks (`:62-76`) are always the first and only write per type: `$actions[$type] = array_keys($this->getParameter($actionParameter));`.
  - Both `Request $request` parameters are unused in `getConfigAction`. Return type can be `JsonResponse` (that is what `ViewHandler::handle()` returns).

### I-07 — Restrict the rule `type` choice to types that have both conditions and actions, or document why only actions count
- **Where:** `Resources/config/services/form.yml:59-63` (`%coreshop.notification_rule.actions.types%`), `Form/Type/NotificationRuleTypeChoiceType.php:32-37`, compare `Controller/NotificationRuleController.php:46-56` (the admin UI shows the union of action and condition types).
- **Idea:** the admin UI offers every type that has a condition **or** an action, but the form only accepts types that have an action. Today both sets are equal, so nothing breaks. A project that registers only conditions for a new type gets "This value is not valid" without a hint. Use the same source (one `%coreshop.notification_rule.types%` parameter built by the pass) in both places. `array_values($this->types)` also produces integer labels (`0`, `1`, …): use `array_combine($types, $types)`.

## Persistence / config

### I-08 — ORM mapping: fix the join-column name, unify the join-table names, add an index
- **Where:** `Resources/config/doctrine/model/NotificationRule.orm.xml`.
- **Idea:**
  - `:29`: the inverse join column of `coreshop_notification_rule_conditions` is named `action_id`, but it references a **condition**. Rename to `condition_id` (needs a migration).
  - `:24` vs `:41`: `coreshop_notification_rule_conditions` (plural) vs `coreshop_notification_rule_action` (singular). Align when the migration above is done anyway.
  - Add `<indexes><index columns="type,active,sort"/></indexes>` for `findForType()` (TODO-2 **B-04**).
  - The unused `xmlns:gedmo` declaration is fine, but `<field name="name"/>` should state `length="255"` explicitly next to the validation constraint (TODO-2 **B-07**).

### I-09 — Serializer and validation config cleanup
- **Where:** `Resources/config/serializer/Model.NotificationRule.yml:3`, `Resources/config/validation/NotificationRule.yml`, `Resources/config/services/form.yml:1-6`.
- **Idea:**
  - `xml_root_name: product_price_rule` is copy-pasted from the product price rule. Use `notification_rule`.
  - `creationDate` / `modificationDate` are mapped but never exposed; expose them in `Detailed` if the admin should show them, otherwise fine.
  - `form.yml:3-4`: the parameters `coreshop.form.type.notification_rule_group.validation_groups` and `coreshop.form.type.notification_rule.rule.validation_groups` are not referenced anywhere. Remove them.
  - `Type: { type: integer }` on `sort` and `Type: { type: boolean }` on `active` are redundant with typed properties once **I-14** is done.

### I-10 — Replace `GenericEvent` with dedicated event classes
- **Where:** `Processor/EventedRuleProcessor.php:36-49`, `Events.php`.
- **Idea:** `final class PreApplyNotificationRulesEvent extends Event` with `public string $type`, `public ResourceInterface $subject`, `public array $params` (mutable on purpose, see TODO-2 **B-06**) and `PostDispatchNotificationRulesEvent` (readonly). Listeners then get autocompletion and type safety, and `GenericEvent`'s confusing "subject = type string" disappears. Keep the string constants in `Events` as the event names for BC, or switch to the class names as event names (Symfony convention) with the constants as aliases.

### I-11 — `Configuration.php`: modern tree-builder calls and the dead `options` node
- **Where:** `DependencyInjection/Configuration.php:55,82-101`, `DependencyInjection/CoreShopNotificationExtension.php:43-45`.
- **Idea:**
  - `->prototype('scalar')` → `->scalarPrototype()` (`:84,88,92,96`).
  - `scalarNode('permissions')->defaultValue(['notification'])` (`:98-101`) is a scalar node with an array default; it only works because it cannot be overwritten. Use `arrayNode('permissions')->scalarPrototype()->end()->defaultValue(['notification'])`.
  - `->variableNode('options')` (`:55`) is never read. Remove it or document it.
  - Wrong indentation at `:82` (5 spaces).
  - `array_key_exists('pimcore_admin', $configs)` in the extension is always `true` (`addDefaultsIfNotSet()`); drop the condition.
  - Same pattern as AddressBundle / CurrencyBundle / IndexBundle TODO-3; fix them together.

### I-12 — `services/messenger.yml`: inject `ManagerRegistry` by its own id, and use attributes for the handler
- **Where:** `Resources/config/services/messenger.yml:5-16`.
- **Idea:**
  - `'@Doctrine\Persistence\ConnectionRegistry'` is injected into a `ManagerRegistry $managerRegistry` parameter (`Component/Notification/Messenger/Handler/NotificationMessageHandler.php:36`). It works only because Pimcore aliases `ConnectionRegistry` to `@doctrine` (`six-it/pimcore-source/bundles/CoreBundle/config/aliases.yaml:9`). DoctrineBundle registers `Doctrine\Persistence\ManagerRegistry` (`doctrine-bundle/config/dbal.php:46`). Use that id.
  - **(dependent)** Put `#[AsMessageHandler(bus: 'coreshop.bus')]` on the handler and give `__invoke()` a `: void` return type; then the YAML tag can go.
  - `MessengerListener` can use `#[AsEventListener]` instead of `EventSubscriberInterface` + explicit tag.

### I-13 — `services.yml`: remove `shared: false` where it has no purpose
- **Where:** `Resources/config/services.yml:15-34`.
- **Idea:** `RulesProcessor` (`:31-34`), `RuleConditionsValidationProcessor` (`:15-22`) and `RuleValidationProcessor` (`:24-28`) are stateless. `shared: false` creates a new instance per injection and also breaks the expectation that the decorated `RulesProcessorInterface` (`:58-62`) is a single service. Remove it, or add a comment explaining which state requires it.

## Readability / PHP 8.4

### I-14 — (dependent) Type the model and repository API
- **Where:** `Component/Notification/Model/{NotificationRule,NotificationRuleInterface}.php`, `Component/Notification/Repository/NotificationRuleRepositoryInterface.php:31`, `Doctrine/ORM/NotificationRuleRepository.php:26`.
- **Idea:** `getType(): ?string`, `setType(?string $type): void`, `getSort(): ?int`, `setSort(int $sort): void`, `protected ?int $id = null; protected ?string $type = null; protected int $sort = 0;`. `findForType(string $type): array` with `@return list<NotificationRuleInterface>`. The docblocks that only repeat the types go away. Coordinate with RuleBundle (`RuleTrait`, `RuleInterface`) because the getters/setters there are untyped too.

### I-15 — Use readonly and `final` consistently
- **Where:** whole bundle.
- **Idea:**
  - `Attribute/*`: `final readonly class` with `public` promoted properties (or keep the getters for `Autoconfiguration::getProperties()`).
  - Promoted constructor properties that are never reassigned: `private readonly` in `EventedRuleProcessor`, `NotificationRuleEventListener`, `NotificationRuleTypeChoiceType`. **(dependent)** `NotificationMessage` can be `final readonly class` (it is serialized, readonly is supported by `PhpSerializer`).
  - Make `NotificationRuleActionChoiceType`, `NotificationRuleConditionChoiceType`, `NotificationRuleConditionCollectionType`, `NotificationRuleTypeChoiceType`, `MailActionConfigurationType`, `MessengerListener`, `NotificationRuleEventListener` `final` (the other form types already are). Leave `NotificationRuleController` and `NotificationRuleRepository` open (they are configurable via `classes.admin_controller` / `classes.repository`), but document that.
  - Add `#[\Override]` (PHP 8.3+) to `getBlockPrefix()`, `buildForm()`, `configureOptions()`, `getParent()`, `process()`, `build()`, `load()`.

### I-16 — Small syntax and wording fixes
- **Where / idea:**
  - `DependencyInjection/Compiler/NotificationRuleActionPass.php:25-26`, `NotificationRuleConditionPass.php:25-26`: `public function __construct(\n        ) {` → `public function __construct()`.
  - `AbstractNotificationRulePass.php:66`: the message says "Tagged Condition" for actions too and has a stray backtick (``notification-type``). Use `sprintf('Service "%s" tagged "%s" needs a "notification-type" attribute.', $id, $this->tag)`.
  - `EventListener/MessengerListener.php:36`: add `: void`.
  - `Processor/EventedRuleProcessor.php:34`: `$subject` is untyped while the interface has `ResourceInterface $subject`. Legal (contravariance), but use the same type.
  - `Controller/NotificationRuleController.php:109,136`: the comments say "move one direction up/down" and are inverted relative to the code (`-1` for "down"). They will go away with **I-05**.
  - `NotificationRuleController.php:89-104`: the inline `@var` blocks that redeclare `$repository` and `$rule` are needed only because `find()` is untyped; with **I-05** / `findOr404()` they can be removed.

### I-17 — Consistent form block prefixes
- **Where:** `Form/Type/NotificationRuleActionCollectionType.php:35` (`coreshop_notification_action_collection`), `Form/Type/NotificationRuleConditionChoiceType.php:27` (`coreshop_notification_rule_action_condition_choice`).
- **Idea:** all others follow `coreshop_notification_rule_<thing>`. Use `coreshop_notification_rule_action_collection` and `coreshop_notification_rule_condition_choice`. Check for Twig form themes referencing the old prefixes first (none in this repository).

### I-18 — (dependent) Clean up the notification component helpers this bundle wires
- **Where:** `Component/Notification/Rule/Condition/AbstractConditionChecker.php:29-31`, `Component/Notification/Rule/Action/MailActionProcessor.php:35-71`, `Component/Notification/Processor/RulesProcessor.php:34-55`, `CoreBundle/EventListener/NotificationRules/OrderCommentsListener.php:46`.
- **Idea:**
  - `AbstractConditionChecker`: `$subject instanceof NotificationRuleInterface` can never be true (`$subject` is the resource). The check was probably meant for `$rule`. Remove it or check `!$rule instanceof NotificationRuleInterface`, and fix the message (it talks about `$subject` being an array).
  - `MailActionProcessor`: `$configuration['mails']` and `['doNotSendToDesignatedRecipient']` are read without defaults (`OrderMailActionProcessor` defaults the second one). Use `?? []` / `?? false`. Early-return instead of the 4-level nesting. Log when no mail document exists for the language (today it silently sends nothing).
  - `RulesProcessor`: `get_class($subject)` → `$subject::class`; `$subject->getId()` is `?int` on many resources → assert non-null before creating the message, with a clear error.
  - `OrderCommentsListener` puts the whole Pimcore `Note` object into the message params, so it is serialized into the `messenger_messages` table. Pass the note id and load it in the action, like `order_id`. **(plausible)** risk of large payloads / unserialize issues after Pimcore upgrades.

## Packaging / tests

### I-19 — `composer.json` tooling and metadata
- **Where:** `composer.json:28,33-36,43-50`.
- **Idea:** `"coreshop/registry": "^4.1"` while all other CoreShop deps are `^4.0` (align). PHPStan `^1.10` → `^2.1` (PHPStan 1.x does not fully understand PHP 8.4 syntax). `repositories: [{type: path, url: ../../*/*}]` and `"minimum-stability": "dev"` belong to the monorepo, not to a split package. `branch-alias dev-master: 4.1-dev` is upstream versioning; align with the fork. Missing runtime deps are TODO-2 **B-14**.

### I-20 — Add tests; the bundle and `Component/Notification` have none
- **Where:** no `Tests/` in either; only Behat `src/CoreShop/Behat/Context/Domain/NotificationContext.php` touches notifications.
- **Idea, by priority:**
  1. Compiler pass: YAML tag, attribute tag (TODO-1 **C-01**), repeated attribute (TODO-2 **B-09**), missing `notification-type`, duplicate registration (**I-04**), action registry interface (TODO-2 **B-12**).
  2. `NotificationMessageHandler`: one failing rule does not re-run others (TODO-2 **B-01**); missing resource is unrecoverable (TODO-2 **B-05**); rules are applied in `sort` order (TODO-2 **B-04**).
  3. Controller functional tests: `sort` up/down/before/after with 4 rules (TODO-2 **B-03**, **B-13**), 403 without permission (TODO-1 **C-02**).
  4. `EventedRuleProcessor`: modified params from `PRE_APPLY` reach the inner processor (TODO-2 **B-06**).
