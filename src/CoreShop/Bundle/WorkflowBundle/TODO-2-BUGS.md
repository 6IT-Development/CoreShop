# WorkflowBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs. They are wrong behaviour but not critical by the definition there: they break a feature, an admin view, a documented extension point or a non-default configuration, not the shop as a whole.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (`symfony/workflow` v7.4.20). Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(reproduced)** were confirmed with a PHP 8.4.27 script against the bundle classes and the vendored Symfony classes. Items marked **(plausible)** follow from the code but were not reproduced against a running shop.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Public API that crashes on documented arguments

### B-01 — `StateMachineApplier::apply()` accepts `null` for `$transition` and `$workflowName`, but both values crash — (reproduced)
- **Where:** `Applier/StateMachineApplierInterface.php:26`, `Applier/StateMachineApplier.php:30-39`. Vendor: `symfony/workflow/Workflow.php:112` (`can(object $subject, string $transitionName)`), `symfony/workflow/Registry.php` (`get()` throws when more than one workflow matches).
- **Problem:**
  - `$transition = null` is passed straight to `Workflow::can()` / `apply()`, which require `string` → `TypeError: Workflow::can(): Argument #2 ($transitionName) must be of type string, null given` (reproduced).
  - `$workflowName = null` makes `Registry::get()` match by subject class only. `Order` is supported by **six** workflows (`coreshop_order`, `_payment`, `_shipment`, `_invoice`, `_sales_type`, `coreshop_quote`, see `OrderBundle/Resources/config/pimcore/workflow/*.yml`), so it always throws `InvalidArgumentException: Too many workflows ...`. The default value can never work for the main subject type.
  - All current callers pass both values, so nothing breaks today; the signature invites the crash.
- **Fix:** make both parameters required strings (`apply(object $subject, string $workflowName, string $transition, bool $soft = true)`). This is a BC break for the interface; list it in `UPGRADE.md`. See TODO-3 **I-06** for the return value.

### B-02 — `CascadeTransition::apply()` with the default `$transition` passes a `Transition` object where a name is expected — (reproduced)
- **Where:** `Callback/CascadeTransition.php:31-52` (`$transition = $event->getTransition();` at `:37`, then `can($object, $transition)` at `:45` and `apply($object, $transition)` at `:50`).
- **Problem:**
  - `Event::getTransition()` returns `?Transition`, not the name. With strict types the call fails with `TypeError: Workflow::can(): Argument #2 ($transitionName) must be of type string, Symfony\Component\Workflow\Transition given` (reproduced). "Cascade the same transition" is the main use case the default is meant for, and it never worked.
  - `$objects = null` (a repository that returns `null` instead of an empty list) becomes `[null]` and crashes in `Registry::get()`.
  - CoreShop's own configs (`OrderBundle/Resources/config/pimcore/workflow/coreshop_order.yml:39-71` and others) always pass the transition and the workflow name, so the shop itself is not affected.
- **Fix:** `$transition ??= $event->getTransition()?->getName();` and `$workflowName ??= $event->getWorkflowName();`; return early when the transition is still `null`. Skip `null` objects. Type the parameters (TODO-3 **I-06**).

### B-03 — `HistoryLogger::log()` crashes when `$message` is `null`, which its signature allows — (reproduced)
- **Where:** `History/HistoryLogger.php:34-62` (`strip_tags($message)` at `:42`), `History/HistoryLoggerInterface.php:25-30`. Callers: `OrderBundle/Expiration/OrderExpiration.php:53-56`, `OrderBundle/Controller/OrderController.php:169`.
- **Problem:**
  - The file has `declare(strict_types=1)`, so `strip_tags(null)` is a `TypeError`, not only a PHP 8.1+ deprecation (reproduced). A call with only a description (`log($order, description: '...')`) always fails.
  - When `$description` is given, the prefixed (and maybe translated) message is computed, set, and then overwritten at `:57-59`, so the message is silently thrown away. `$description` itself is not passed through `strip_tags()`, unlike `$message`.
- **Fix:** make `$message` a required `string`, or treat `null` as `''` and skip the prefix. Decide whether the description replaces or extends the message and document it; apply the same sanitising to both.

---

## Callback configuration (`core_shop_workflow.state_machine.*.callbacks`)

### B-04 — `guard` callbacks are accepted by the configuration but never executed — (reproduced)
- **Where:** `DependencyInjection/Configuration.php:150` (`addSubCallbackSection($callbacks, 'guard')`), `EventListener/WorkflowListener.php:35-41` (subscribes only to `workflow.enter` and `workflow.completed`; only the `before` and `after` keys are read).
- **Problem:** a project can configure `callbacks.guard.<name>` and the container builds without error, but nothing ever calls it. A guard that is meant to block a transition (for example "no shipment before payment") is silently ignored, and the transition is allowed. The docs (`docs/03_Development/06_Order/16_State_Management/02_Create_Callbacks.md`) describe only `before` and `after`, so users find `guard` only from the config tree.
- **Fix:** either subscribe to `workflow.guard` and call the callback with the `GuardEvent` (the callback blocks via `$event->setBlocked(true, ...)` or by returning `false`), or remove `guard` from `Configuration` so that such config fails at build time. Document the result.

### B-05 — Callback entries are not validated; mistakes crash at transition time (checkout, admin) instead of at container build — (reproduced)
- **Where:** `DependencyInjection/Configuration.php:157-175` (`on` and `do` are `variableNode`s and not required, `priority` is a `scalarNode`), `EventListener/WorkflowListener.php:77-139`.
- **Problem (all reproduced with `Processor::processConfiguration()` and `WorkflowListener::applyTransition()`):**
  - `on: create` (a string, the natural YAML for one transition) passes the config, then `in_array($transitionName, 'create')` → `TypeError: in_array(): Argument #2 ($haystack) must be of type array, string given`.
  - A missing `on` gives an "Undefined array key" warning and the same `TypeError` on `null`.
  - `do: 'App\Listener::method'` (a string callable) passes the config, then `call(Event $event, array $callable)` → `TypeError`.
  - `priority: 'abc'` is accepted; the sort then compares strings with integers.
  - Because the listener reacts to every transition of the workflow, one wrong entry breaks the whole workflow (for example `coreshop_order` `create` during checkout), not only the misconfigured callback.
- **Fix:** in `Configuration`: `on` → `arrayNode` with `beforeNormalization()->castToArray()`, `scalarPrototype()`, `isRequired()`, `requiresAtLeastOneElement()`; `do` → accept `[service, method]` or `'service::method'` and normalise to a two-element list, `isRequired()`; `priority` → `integerNode()->defaultValue(0)`. Optionally validate in a compiler pass that each `on` value is a transition of that state machine.

### B-06 — Callbacks on private services fail at runtime, although the docs show a plain application service — (plausible) (dependent: docs)
- **Where:** `EventListener/WorkflowListener.php:98-103` (`$this->container->get($serviceId)`), `Resources/config/services.yml:24-29` (injects `@service_container`). The same applies to `service('...')` inside `args` expressions (`:108-119`, evaluated by `vendor/symfony/dependency-injection/ExpressionLanguageProvider.php:41` with the same container). Docs: `docs/03_Development/06_Order/16_State_Management/02_Create_Callbacks.md:17-20` (`do: ['@App\CoreShop\EventListener\SpecialListener', 'doSomething']`).
- **Problem:** `service_container->get()` only returns **public** services. In a Symfony 7.4 app with the default `_defaults: { autowire: true, autoconfigure: true }` and no `public: true`, `App\CoreShop\EventListener\SpecialListener` is private and usually removed as unused, so the first matching transition throws `ServiceNotFoundException` ("... has been removed or inlined when the container was compiled"). CoreShop's own callbacks work only because every referenced service is public (`_defaults: public: true` in many CoreShop `services.yml` files).
- **Fix:** collect all `@service` references from the callback config in a compiler pass and inject a `ServiceLocator` with exactly those services (TODO-3 **I-05**). This also makes unknown service ids fail at build time. Until then, document that callback services must be `public: true`.

---

## Marking stores

### B-07 — Both persistent marking stores drop the `$context` argument — (verified against the vendor code)
- **Where:** `MarkingStore/PimcorePersistentMarkingStore.php:38-45` and `MarkingStore/OrmPersistentMarkingStore.php:38-45` (`$this->originMarkingStore->setMarking($subject, $marking);`). Vendor: `symfony/workflow/MarkingStore/MethodMarkingStore.php:78` (`setMarking(object $subject, Marking $marking, array $context = [])`, passes `$context` to the setter).
- **Problem:** the context given to `Workflow::apply($subject, $transition, $context)` never reaches `MethodMarkingStore`, so model setters that take a context (`setOrderState($state, array $context)`) always receive `[]`. Context added by listeners through `TransitionEvent::setContext()` is lost as well.
- **Fix:** forward the context: `$this->originMarkingStore->setMarking($subject, $marking, $context);`.

### B-08 — `PimcorePersistentMarkingStore` saves the whole object inside the workflow, including from read-only calls — (plausible)
- **Where:** `MarkingStore/PimcorePersistentMarkingStore.php:38-45`. Vendor: `symfony/workflow/Workflow.php:73-93` (`getMarking()` calls `setMarking()` when the marking is empty and the definition has an initial place).
- **Problem:**
  - `getMarking()`, `can()`, `getEnabledTransitions()` and `buildTransitionBlockerList()` all call `getMarking()`. For a workflow with `initial_marking` configured and an object whose state field is still empty, a **read** stores the initial place and calls `$subject->save()`. On an object without key or parent this throws; on a draft object it creates a version and fires all Pimcore save events. CoreShop's own workflows have no `initial_marking` (the compiled `Definition` has `[]` initial places), so they are not affected today, but any project that adds one is.
  - On a normal transition the object is saved between `enter` and `entered`, before the `completed` callbacks run. Every cascading callback (`CascadeTransition` on the same order to `coreshop_order_payment`, `coreshop_order_shipment`, ...) causes another full save of the same order, with versioning and save events each time.
- **Fix:** do not persist from the initial-marking path. Symfony calls `setMarking($subject, $marking)` there **without** context (`Workflow.php:87`), so the context cannot be used to detect it; instead read `$this->originMarkingStore->getMarking($subject)` first and skip the save when it was empty. Consider collecting changed subjects and saving them once in a `workflow.completed` listener at the lowest priority, with versioning settings decided in one place.

### B-09 — `OrmPersistentMarkingStore` crashes for unmanaged classes and flushes the whole unit of work — (plausible)
- **Where:** `MarkingStore/OrmPersistentMarkingStore.php:25-45`.
- **Problem:**
  - `getManagerForClass($subject::class)` returns `null` for a class that is not mapped (or for a proxy class name that the registry does not resolve), and `->persist()` on `null` is an `Error`.
  - `flush()` without arguments writes **every** pending change of that entity manager, not only the subject, in the middle of a workflow transition.
  - The constructor type is the concrete `Doctrine\Bundle\DoctrineBundle\Registry`, and `doctrine/doctrine-bundle` is not required by this bundle (`composer.json`). The class is not used anywhere in CoreShop.
- **Fix:** type against `Doctrine\Persistence\ManagerRegistry`, throw a clear exception when no manager is found, and decide whether the store should flush at all (Symfony's own pattern is to let the caller flush). Or deprecate and remove the class (TODO-3 **I-14**).

---

## History notes

### B-10 — State history notes are translated once, in the locale of the request that triggered the transition — (plausible)
- **Where:** `History/StateHistoryLogger.php:46-60`, `History/HistoryLogger.php:44-55`. Triggered from frontend requests, for example `coreshop_order` `create` during checkout (`OrderBundle/Resources/config/pimcore/workflow/coreshop_order.yml:75-78`) and `coreshop_payment` transitions from payment gateway callbacks (`coreshop_payment.yml:12`).
- **Problem:** the note description is built with `translator->trans(..., 'admin')` at write time and stored as plain text. During checkout the translator locale is the **customer's** frontend locale, so the admin later sees notes in the customer's language (or as raw `coreshop_workflow_state_...` keys when the admin domain has no entry for that locale). Notes written by cron/CLI use the default locale. The text cannot be re-translated later.
- **Fix:** store the structured data (`workflow`, `transition`, `from`, `to`) in the note data — `workflow` and `transition` are already stored at `:62-63` — and build the translated description when the notes are rendered in the admin (`OrderController`). If the stored description must stay for BC, translate it with an explicit admin locale.

### B-11 — The state history note shows the first `from` place of the transition, not the place the subject actually left — (reproduced) (plausible for CoreShop's own workflows)
- **Where:** `History/StateHistoryLogger.php:43-44,72-80` (`reset($transition->getFroms())`, `reset($transition->getTos())`).
- **Problem:** for a transition with several `from` places (`new Transition('cancel', ['a', 'b'], 'c')`) the note always says "changed from a", even when the subject was in `b`. For `type: state_machine`, `FrameworkBundle` splits multi-`from` transitions into one `Transition` per place (checked in the compiled container: `coreshop_order` `cancel` exists twice), so CoreShop's own workflows log correctly. Workflows of `type: workflow`, programmatic definitions, and multi-`to` transitions log wrong data. `getFrom()`/`getTo()` return `false` for an empty list, which becomes a `..._` key.
- **Fix:** take the places from the event: for `CompletedEvent` use the transition's froms intersected with the previous marking (pass it via context or log in a `leave` listener), or log all places (`implode(', ', ...)`). Add return types to `getFrom()`/`getTo()` and handle the empty case.

### B-12 — `StateHistoryLogger` swallows every exception without logging it — (plausible)
- **Where:** `History/StateHistoryLogger.php:65-69` (`catch (\Exception) {}`).
- **Problem:** not breaking the workflow is right, but a failing note (database error, missing permissions, a broken `NoteService`) disappears without a trace, so missing history in the admin cannot be diagnosed.
- **Fix:** inject a `LoggerInterface` and log the exception at `warning` with object id, workflow and transition.

### B-13 — `HistoryRepository` does not filter notes by element type — (plausible)
- **Where:** `History/HistoryRepository.php:31-40` (`type = ?` and `cid = ?`, no `ctype`).
- **Problem:** Pimcore notes are stored for objects, assets and documents in the same table. An asset or document with the same id and a note of type `history_state_change` (written by a project, or by mistake through `HistoryLogger` for another element) appears in the order's history. The listing also has no limit.
- **Fix:** add `ctype = 'object'` (or `$object->getType()` via `Service::getElementType()`), and an optional limit / pagination.

---

## Behaviour around callbacks

### B-14 — An exception in an `after` callback leaves a half-applied transition chain — (plausible) (dependent)
- **Where:** `EventListener/WorkflowListener.php:60-96` (callbacks run on `workflow.completed`, after the marking is already persisted by `PimcorePersistentMarkingStore`). Example chain: `OrderBundle/Resources/config/pimcore/workflow/coreshop_order.yml:37-78` and `CoreBundle/Resources/config/pimcore/workflow.yml:1-30` (`create` → `request_payment`, `increment_voucher_usages`, `hold_inventory`, history); started from `Component/Core/Order/Committer/OrderCommitter.php` (`commitOrder()` ends with `apply(create)`).
- **Problem:** the new state is already saved when the `after` callbacks run. If one of them throws, the remaining callbacks are skipped and the exception reaches the caller (checkout). Example: order saved as `new`, but the voucher usage not incremented and the inventory not held, while the customer sees an error page and may order again. There is no transaction around the chain, and the order of the callbacks (by priority, then config order) decides what was done.
- **Fix:** decide per callback whether it is essential. Options: wrap commit + transition in a DB transaction (Pimcore `Db::get()->transactional()`), or catch and log exceptions of non-essential callbacks (history, notifications) in the listener, or move essential side effects into the same unit of work as the state change. Document the chosen semantics for project callbacks.

### B-15 — `WorkflowStateInfoManager::parseTransitions()` crashes on non-string transitions or an empty marking — (plausible)
- **Where:** `StateManager/WorkflowStateInfoManager.php:53-67`. Caller: `OrderBundle/Controller/OrderController.php:483,547,579,726`.
- **Problem:**
  - Listeners of `coreshop.workflow.valid_transitions` can put anything into `WorkflowTransitionEvent` (`addAllowedTransitions(array)` is untyped). A non-string value leads to a `TypeError` in `Workflow::can()`, and the whole order detail page fails to load.
  - For a subject whose state field is empty and whose workflow has no initial place (all CoreShop workflows, see B-08), `can()` throws `LogicException: The Marking is empty and there is no initial place`. Imported or half-created orders then break the admin detail view instead of showing "no transitions".
- **Fix:** type the event's list as `list<string>` (TODO-3 **I-09**), and catch `LogicException` per workflow in `parseTransitions()` and return `[]`.
