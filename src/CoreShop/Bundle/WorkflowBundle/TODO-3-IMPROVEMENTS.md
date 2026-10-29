# WorkflowBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are improvements to code quality, readability, logic, performance, PHP 8.4 modernisation and forward compatibility (Symfony 8). None of them is a functional bug on its own.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle.

**BC note:** `StateMachineManagerInterface`, `StateMachineApplierInterface`, `HistoryLoggerInterface`, `StateHistoryLoggerInterface`, `HistoryRepositoryInterface` and `WorkflowStateInfoManagerInterface` are used by `OrderBundle`, `CoreBundle`, `PaymentBundle`, `PayumBundle`, `Component/Core`, `Component/Order`, Behat contexts and projects. The callback config format (`core_shop_workflow.state_machine.*.callbacks`) is public and documented in `docs/03_Development/06_Order/16_State_Management/`. Every signature or config change below must be listed in `UPGRADE.md`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 / Symfony 7.4 compatibility and forward compatibility

### I-01 — `composer.json` does not describe what the bundle needs
- **Where:** `composer.json:32-44`.
- **Fix:**
  - Add `"php": "~8.4.0"` (root `composer.json:88`).
  - Add the packages that are used directly: `symfony/framework-bundle` (`framework.workflows`, `workflow.registry`), `symfony/dependency-injection`, `symfony/http-kernel` (`Bundle`), `symfony/event-dispatcher` (`EventSubscriberInterface`), `symfony/event-dispatcher-contracts` / `symfony/translation-contracts`, `symfony/expression-language` (`WorkflowListener` builds an `ExpressionLanguage`; without the package the first callback with `args` fails with a class-not-found error). All `^7.4`.
  - `coreshop/registry` is not used by any file of this bundle (`Symfony\Component\Workflow\Registry` is a different class). Remove it.
  - `MarkingStore/OrmPersistentMarkingStore.php` needs `doctrine/persistence` (after TODO-2 **B-09**) — add it, or remove the class (**I-14**).
  - `require-dev`: align with the root (`phpstan/phpstan ^2.1`, matching `phpstan-doctrine` 2.x). Remove `phpstan/phpstan-webmozart-assert`; the bundle does not use `Webmozart\Assert`.
  - Fix the indentation of `"repositories"` (`:50-55`).

### I-02 — Move the bundle to `AbstractBundle`
- **Where:** `CoreShopWorkflowBundle.php:24-29` (empty `build()`), `DependencyInjection/CoreShopWorkflowExtension.php`, `DependencyInjection/Configuration.php`.
- **Fix:** extend `Symfony\Component\HttpKernel\Bundle\AbstractBundle` and implement `configure(DefinitionConfigurator)`, `prependExtension()` (the fix of TODO-1 **C-01**) and `loadExtension()`. Keep the alias `core_shop_workflow` (`$extensionAlias`). If the classic structure stays, at least remove the empty `build()` and implement `PrependExtensionInterface`.

### I-03 — Use PHP 8.2–8.4 language features
- **Where / Fix:**
  - Stateless services as `final readonly class`: `Manager/StateMachineManager.php`, `Applier/StateMachineApplier.php`, `History/HistoryRepository.php`, `History/StateHistoryLogger.php`, `StateManager/WorkflowStateInfoManager.php`; `History/HistoryLogger.php`, `Callback/CascadeTransition.php`, `EventListener/WorkflowListener.php` and the two marking stores as `final` (+ `readonly` where possible). Check first that no project extends the non-final ones.
  - Typed class constants (PHP 8.3): `Notes::HISTORY_STATE_LOG` → `public const string HISTORY_STATE_LOG = ...`. Add `public const string HISTORY_NOTE_TITLE = 'coreshop_history_change';` and use it in both loggers (`HistoryLogger.php:48`, `StateHistoryLogger.php:50`).
  - `array_find()` (PHP 8.4) in `Manager/StateMachineManager.php:37-59`: `array_find($workflow->getEnabledTransitions($subject), static fn (Transition $t): bool => in_array($fromState, $t->getFroms(), true))?->getName()`. The two methods then become one line each, and the `/** @var Transition $transition */` hints go away.
  - Asymmetric visibility (PHP 8.4) in `Event/WorkflowTransitionEvent.php`: `public private(set) array $allowedTransitions` with the adders as the only writers, or simply promoted `private` properties (**I-09**).
  - Static closures where `$this` is not needed: `Configuration.php:64,99,112`, `WorkflowListener.php:110` (uses `$this->container`, so only after **I-05**).

### I-04 — Native types and precise PHPDoc on the public API — (dependent: implementers and callers)
- **Where / Fix:**
  - `Applier/StateMachineApplierInterface.php:26`: `apply(object $subject, string $workflowName, string $transition, bool $soft = true)` (TODO-2 **B-01**); remove the empty `@param mixed` docblock.
  - `Callback/CascadeTransition.php:31`: `apply(iterable|object|null $objects, Event $event, ?string $transition = null, ?string $workflowName = null, bool $soft = true): void`.
  - `StateManager/WorkflowStateInfoManagerInterface.php:33`: add the `array` return type to `parseTransitions()` (the implementation already has it), add `getTransitionInfo()` to the interface (it is public in the implementation only), and document the shapes: `@return array{label: string, state: string, color: string}` / `list<array{label: string, transition: string, color: string}>`.
  - `History/HistoryRepositoryInterface.php`, `WorkflowStateInfoManagerInterface.php`: `@return list<Note>`.
  - `MarkingStore/*.php`: `getMarking(object $subject)` (`OrmPersistentMarkingStore.php:33`, `PimcorePersistentMarkingStore.php:33` are untyped).
  - `History/StateHistoryLogger.php:72-80`: return types for `getFrom()`/`getTo()` (or remove them, TODO-2 **B-11**).
  - `EventListener/WorkflowListener.php`: shape of a normalised callback as a `@phpstan-type` (`array{enabled: bool, on: list<string>, do: array{0: string|object, 1: string}, priority: int, args: list<mixed>}`) and use it for `$callbackConfig`.

---

## Callback listener

### I-05 — `WorkflowListener`: replace the service container with a service locator, and do the work at build time
- **Where:** `EventListener/WorkflowListener.php`, `Resources/config/services.yml:24-29`.
- **Fix:**
  - Inject a `ServiceLocator` that contains only the services referenced by `@...` in `do` (built by a compiler pass from `coreshop.state_machine.callbacks`) instead of `@service_container`. This fixes TODO-2 **B-06**, lets callback services be private, and makes unknown service ids fail at container build time. For `service('...')` inside `args` expressions, either add those ids to the same locator (parse the expressions at build time) or document that they must be public.
  - Create one `ExpressionLanguage` per listener (or inject the `expression_language`-style service with a cache pool) instead of `new ExpressionLanguage()` on every callback call (`:108`). Today every expression is parsed again for every transition; the parser cache lives in the instance.
  - Sort the callbacks once (in the extension or a compiler pass) instead of `uasort()` on every event (`:128-139`). Use `<=>` if the sort stays.
  - Index callbacks by workflow and transition at build time (`[$workflow][$event][$transition] => list<callback>`), so the listener does an array lookup instead of looping and filtering with `in_array()` for every transition of every workflow in the app.
  - Optionally register per-workflow events (`workflow.<name>.completed.<transition>`) instead of the global `workflow.enter` / `workflow.completed`, so the listener is not called for Pimcore's or other bundles' workflows.
  - `applyTransition()` and `call()` are public only for internal use; make them `private`. `setCallbacksPriority()` does not set anything; rename it to `sortByPriority()`.
  - `onTransitionEnter()` and `onTransitionCompleted()` are copies with a different key; merge them into one private method.
  - `in_array(..., true)` (`:84`).
  - Use `#[AsEventListener]` attributes or keep the subscriber, but drop the `kernel.event_subscriber` tag if autoconfiguration is enabled.

### I-06 — `StateMachineApplier::apply()` should report whether it applied the transition — (dependent)
- **Where:** `Applier/StateMachineApplier.php:30-39`, `Callback/CascadeTransition.php:42-51`. Caller that needs it: `OrderBundle/Expiration/OrderExpiration.php:45-58` (`OrderBundle/TODO-2-BUGS.md` **B-17**).
- **Fix:** return `bool` (`true` when applied, `false` when the soft mode skipped it). `CascadeTransition` should reuse the applier instead of duplicating the soft check.

### I-07 — Document and reconsider the callback semantics
- **Where:** `EventListener/WorkflowListener.php:35-41,128-139`, docs `02_Create_Callbacks.md`.
- **Fix:**
  - Priorities run **ascending** (`-10` before `0`). Symfony event priorities run descending. The docs say "fire action early!" for `-10`, so the behaviour is intended, but it is easy to get wrong; state it explicitly in the docs and in the config `info()`.
  - `before` callbacks run on `workflow.enter`, after `leave` and `transition` listeners have already run. If "before" is meant as "can still cancel cleanly", `workflow.transition` (or `leave`) is the better hook. Document what a thrown exception does and does not roll back.
  - `enabled: false` is the only way to disable a CoreShop callback in a project; mention it in the docs next to the callback names.

---

## Configuration

### I-08 — Simplify and harden `Configuration` and the extension
- **Where:** `DependencyInjection/Configuration.php`, `DependencyInjection/CoreShopWorkflowExtension.php:28-74`.
- **Fix:**
  - Remove the duplicated `callbacks` block (`CoreShopWorkflowExtension.php:53-59`).
  - `is_array($configs['state_machine'])` (`:40`) is always true after `processConfiguration()`; `isset($stateMachineConfig['places'])` / `['transitions']` are always true (both are `isRequired()`). Remove the checks. Rename `$configs` (after processing it is one config) to `$config`.
  - Use `scalarPrototype()` instead of `prototype('scalar')` (`:57,104,117,132,139,169`), `castToArray()` instead of the custom closures (`:97-101,110-114`), and add `info()` texts to `on`, `do`, `args`, `priority`, `enabled`, `place_colors`, `transition_colors`.
  - `addCallBackSection()` and `getConfigTreeBuilder()` call `->end()` chains whose results are thrown away (`:46,154`); the nesting in `addSubCallbackSection()` (`:159-174`) is hard to follow. Return the builders explicitly or use one fluent chain.
  - `transitions` uses `useAttributeAsKey('transition')` (`:83`) but the normaliser fills `name` (`:70-75`). Use `name` like `FrameworkBundle`.
  - `args` only accepts scalars (`prototype('scalar')`, `:169`). Use `variablePrototype()` if arrays or `null` should be allowed as literal arguments.
  - Validate `place_colors` / `transition_colors` (keys must be places/transitions of that machine, values a CSS colour) and move the hard-coded fallbacks `#f6f1de` / `#999999` (`StateManager/WorkflowStateInfoManager.php:44,73`) into the config defaults.
  - `places` and `transitions` are required, so colours and callbacks cannot be attached to a workflow that is defined directly under `framework.workflows`. Make them optional and only prepend to `framework` when they are set.
  - Optionally accept `type`, `supports`, `marking_store`, `initial_marking` and `metadata` in `core_shop_workflow` and pass them through, so a CoreShop state machine is defined in one place instead of two (`OrderBundle/Resources/config/pimcore/workflow/*.yml` currently split each machine into a `framework:` and a `core_shop_workflow:` block). **(dependent)**

### I-09 — `WorkflowTransitionEvent`: tidy the class and name the event
- **Where:** `Event/WorkflowTransitionEvent.php`, `StateManager/WorkflowStateInfoManager.php:55-56`.
- **Fix:** promote both constructor properties as `private` (a `final` class with `protected` properties is contradictory); type the list as `list<string>`; make `addAllowedTransitions()` skip duplicates (`array_values(array_unique(...))`); add a `removeAllowedTransition(string)`. Add a constant for the event name (`'coreshop.workflow.valid_transitions'` is a magic string) in a small `WorkflowEvents` class and use it in the dispatcher call and in listeners.

### I-10 — `services.yml`: private by default, interfaces instead of concrete classes
- **Where:** `Resources/config/services.yml`. **(dependent)** `CoreBundle/Resources/config/services/order.yml:97,106`, `OrderBundle/Expiration/OrderExpiration.php:32` and the Behat contexts (`Behat/Context/Setup/Order*Context.php`) inject the concrete `StateMachineApplier`.
- **Fix:**
  - Remove `_defaults: public: true`; keep `public: true` only where a service is fetched from the container at runtime (callback targets until **I-05**, `CascadeTransition`, `StateHistoryLoggerInterface`). `OrderController` uses a service subscriber, so it does not need public services. The extra `public: true` on `CascadeTransition` (`:19`) is redundant today.
  - Inject `StateMachineManagerInterface` instead of the concrete `StateMachineManager` (`:16,21,54`). Make the callers in other bundles type against `StateMachineApplierInterface`.
  - Use one translator id: `@translator` (`:55`) vs `@Pimcore\Translation\Translator` (`:35,42`). Prefer `TranslatorInterface` via `@translator`.
  - Consider `autowire: true` with explicit `bind` for the string parameters (`$noteIdentifier`, `$stateColors`, `$callbackConfig`).

---

## History

### I-11 — Merge the two loggers' common code and store structured data
- **Where:** `History/HistoryLogger.php`, `History/StateHistoryLogger.php`, `History/HistoryRepository.php`.
- **Fix:** both loggers create a note with the same type and title and store it through `NoteService`; extract a small private helper or a shared base. Store `from`, `to`, `transition`, `workflow` as note data (part of the fix for TODO-2 **B-10**) so that the admin can render and filter the history without parsing the description. Give `HistoryRepository` a limit/offset and filter by `ctype` (TODO-2 **B-13**).

### I-12 — Reduce saves per transition chain — (dependent)
- **Where:** `MarkingStore/PimcorePersistentMarkingStore.php:38-45`; the cascades in `OrderBundle/Resources/config/pimcore/workflow/coreshop_order.yml:37-78`.
- **Fix:** a single `create` on an order cascades into several transitions on the same order (`request_payment`, `cancel` on payment/shipment/invoice state), and each one saves the whole Pimcore object with versioning and save events. Collect the touched subjects and save them once at the end of the outermost transition, or disable versioning for the intermediate saves. Measure first; this mainly affects checkout and cancellation latency.

---

## Readability / clean-up

### I-13 — `WorkflowStateInfoManager`: remove duplicated key building
- **Where:** `StateManager/WorkflowStateInfoManager.php:39-80`.
- **Fix:** `getStateInfo()` and `getTransitionInfo()` build the translation key the same way (`$forFrontend ? 'coreshop.ui.workflow.<kind>.' : 'coreshop_workflow_<kind>_'` + separator). Extract `private function translationKey(string $kind, string $workflowName, string $value, bool $forFrontend): string` and a `private function translate(...)`. Use `match` or named variables instead of nested ternaries in the `trans()` calls.

### I-14 — Remove or justify unused files and classes
- **Where / Fix:**
  - `MarkingStore/OrmPersistentMarkingStore.php`: not used anywhere in CoreShop, and buggy (TODO-2 **B-09**). Deprecate and remove, or fix and cover with a test.
  - `Resources/config/pimcore/routing.yml` is an empty file. Remove it.
  - `Notes.php` only holds one constant; with **I-03** it can become the home of all note constants, or the constant can move to `HistoryLoggerInterface`.
  - `README.md`: "It is build from" → "It is built from"; it links to `coreshop.org` over `http://` and to the upstream issue tracker. Update to the fork's reality (as in the other bundles).

### I-15 — Add tests for the bundle
- **Where:** the bundle has no tests.
- **Fix:** unit tests for: `Configuration` normalisation (string `on`, string `do`, integer priority — TODO-2 **B-05**), `WorkflowListener` (ordering, `enabled: false`, expression args, unknown service), `StateMachineApplier` soft/hard mode, `CascadeTransition` default transition (TODO-2 **B-02**), `StateHistoryLogger` note content, both marking stores forwarding the context (TODO-2 **B-07**). A kernel test for the extension/prepend order (TODO-1 **C-01**).
