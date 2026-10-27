# NotificationBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this bundle, but they come from how it is used, and they should be fixed together with it.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items are roughly ordered by impact. Items marked **(plausible)** follow from the code, but were not reproduced against a running shop.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Rule processing (Messenger)

### B-01 — One failing rule makes Messenger retry the whole message, so every other matching rule is applied again (duplicate e-mails, up to 6×)
- **Where:** `Resources/config/pimcore/messenger.yml:4-10` (`max_retries: 5`, `delay: 300000`, `multiplier: 2`), `Resources/config/services/messenger.yml:5-12`. **(dependent)** `Component/Notification/Messenger/Handler/NotificationMessageHandler.php:58-72`, `Component/Notification/Processor/RuleApplier.php:32-41`.
- **Problem:**
  - One `NotificationMessage` carries one event (e.g. "order shipped"). The handler loads **all** active rules of that type and applies them one after the other. Each rule can send e-mails.
  - If any action throws (missing locale, missing store – see **B-02** –, a deleted mail document, an SMTP error, a template error), the exception leaves `__invoke()`. Messenger retries the **whole** message after 5 min, 10 min, 20 min, 40 min and 80 min. Every retry re-runs the rules that already succeeded. The customer receives the same order/shipment/payment e-mail up to 6 times, and the failing rule still fails.
  - The order of the rules decides which ones are duplicated and which ones never run (no `ORDER BY`, see **B-04**), so the behaviour also looks random.
- **Fix:**
  - Isolate the rules: either dispatch one message per (event, rule) pair from the handler (or from `RulesProcessor`), or catch and log per rule / per action in the handler and only rethrow when nothing was applied. Use `Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException` only for transient errors (SMTP), and `UnrecoverableMessageHandlingException` for configuration errors (**B-02**, **B-05**).
  - Add a test: two rules for the same type, the second action throws → the first action is executed exactly once.

### B-02 — (dependent) `mail` and `storeMail` actions are offered for rule types whose events never provide what they need, so these rules always fail
- **Where:** `CoreBundle/Resources/config/services/notification.yml:208-213,221-226` (tags `mail` for `invoice`/`shipment`, `storeMail` for `payment`/`invoice`/`shipment`). Producers: `CoreBundle/EventListener/NotificationRules/OrderDocumentWorkflowListener.php:43-48` (invoice/shipment: only `order_id`, `fromState`, `toState`, `transition`), `PaymentWorkflowListener.php:43-51` (payment: no `store_id`). Consumers: `Component/Notification/Rule/Action/MailActionProcessor.php:38-44` (needs `_locale`), `Component/Core/Notification/Rule/Action/Order/StoreMailActionProcessor.php:43-51` (needs a `StoreAwareInterface` subject or `store_id`).
- **Problem:**
  - Invoice / shipment rules with a `mail` action always throw `MailActionProcessor: Language is not set.` (no `_locale` key at all; this is not the `null` locale case of the Locale TODOs).
  - Payment / invoice / shipment rules with a `storeMail` action always throw `StoreMailActionProcessor: Store is not set.`: `PaymentInterface`, `OrderInvoiceInterface` and `OrderShipmentInterface` are not `StoreAwareInterface`, and no listener passes `store_id`.
  - The admin UI offers both actions for these types (the choices come from the tags), so the configuration looks valid. Each failing rule then triggers **B-01** for all other rules of the event.
- **Fix:** either pass `_locale` (from `$subject->getOrder()->getLocaleCode()`) and `store_id` (from the order) in `OrderDocumentWorkflowListener` / `PaymentWorkflowListener`, or resolve them in the action processors from `order_id`, like `OrderMailActionProcessor` does. Otherwise remove the tags for those notification types. Throw `UnrecoverableMessageHandlingException` for "cannot work with this configuration" instead of `\Exception`.

### B-03 — `sortAction` builds its range with `where()` twice; the second call replaces the first, so rules outside the dragged range are renumbered
- **Where:** `Controller/NotificationRuleController.php:118-120` and `:141-143`.
- **Problem:**
  - `Doctrine\Common\Collections\Criteria::where()` **replaces** the expression (`doctrine/collections/src/Criteria.php:114-119`). Only the `lte('sort', $toSort)` condition is applied; the `gte('sort', $fromSort)` lower bound is lost.
  - Drag **down** (from sort `a` to `b > a`): every rule with `sort <= b` gets `-1`, including all rules **above** the dragged one, which then collide with each other or become `0` / negative.
  - Drag **up** (from `a` to `b < a`): every rule with `sort <= a` gets `+1`, including the rules above the target.
  - After a few drags the `sort` column contains duplicates and gaps. The admin grid is sorted by `sort` (`Resources/public/pimcore/js/resource.js:17`), so the list order becomes unpredictable.
- **Fix:** use `andWhere()` for the second expression (or one `Criteria::expr()->andX(...)`). Better: replace the read-modify-write loop with one DQL `UPDATE … SET o.sort = o.sort ± 1 WHERE o.sort BETWEEN :from AND :to` in a transaction (TODO-3 **I-05**).

### B-04 — `findForType()` has no `ORDER BY`, so the admin sort order has no effect on the order in which rules run
- **Where:** `Doctrine/ORM/NotificationRuleRepository.php:26-36`. **(dependent)** consumer `Component/Notification/Messenger/Handler/NotificationMessageHandler.php:58-72`.
- **Problem:** the admin UI lets users drag rules into an order and persists `sort`, but the handler gets the rules in database order (usually primary key on MySQL/InnoDB, not guaranteed). When two rules send related mails, or one fails (**B-01**), the outcome depends on the insertion order, not on what the admin configured.
- **Fix:** `->orderBy('o.sort', 'ASC')->addOrderBy('o.id', 'ASC')`. Fix **B-03** and **B-05** first, otherwise the corrupted `sort` values decide the order. Add a composite index on `(type, active, sort)` (TODO-3 **I-08**).

### B-05 — (dependent) A deleted or missing subject is retried 5 times before it reaches the failure transport
- **Where:** `Component/Notification/Messenger/Handler/NotificationMessageHandler.php:45-56` (`throw new \Exception('Resource could not be loaded')`), `Resources/config/pimcore/messenger.yml:7-10`.
- **Problem:** if the order / payment / customer was deleted between dispatch and handling, it will never be loadable again, but the generic `\Exception` is retried with back-off for about 2.5 hours and then moved to `coreshop_notification_failed`, where it stays forever. It also produces 6 `alert` log entries via `MessengerBundle/EventListener/FailureListener.php`. The message does not say which class/id was missing.
- **Fix:** throw `UnrecoverableMessageHandlingException(sprintf('Resource %s#%d could not be loaded', $class, $id))`.

### B-06 — `EventedRuleProcessor`: `PRE_APPLY` listeners cannot change the parameters, and `POST_APPLY` fires before any rule has run
- **Where:** `Processor/EventedRuleProcessor.php:34-50`, `Events.php:26,31`.
- **Problem:**
  - The event is created with `['subject' => $subject, 'params' => $params]` as arguments, but after dispatching `PRE_APPLY` the processor passes the **original** `$type`, `$subject` and `$params` to the inner processor. Changes a listener makes via `$event->setArgument('params', …)` are silently ignored, so the only thing a pre-listener can do is stop propagation.
  - The inner `RulesProcessor` only dispatches a Messenger message; the rules run later in the worker. `POST_APPLY` ("post process rules") is therefore dispatched before any rule has been checked or applied. Listeners that expect "mails were sent" are wrong.
  - The `GenericEvent` subject is the **type string**, and the actual subject is in the `subject` argument, which is easy to get wrong (see TODO-3 **I-10**).
- **Fix:** read `$event->getArgument('params')` (and `subject`) back after `PRE_APPLY` and pass them on. Rename or document `POST_APPLY` as "after dispatch" and, if a real "after apply" hook is needed, dispatch it from the message handler.

## Admin / persistence

### B-07 — Rule names longer than 255 characters or empty names produce a database error (500) instead of a validation error
- **Where:** `Form/Type/NotificationRuleType.php:31` (`TextareaType` for `name`), `Resources/config/doctrine/model/NotificationRule.orm.xml:11` (`<field name="name"/>` = `VARCHAR(255) NOT NULL`), `Resources/config/validation/NotificationRule.yml:1-8` (no constraint on `name`).
- **Problem:** the base `RuleType` uses `TextType`; this bundle uses a multi-line textarea, which invites long names. There is neither `NotBlank` nor `Length(max: 255)`. On save, an empty name hits `NOT NULL` and a long one is either truncated or rejected (MySQL strict mode) with a DBAL exception and HTTP 500.
- **Fix:** `TextType` + `NotBlank` + `Length(max: 255)` in group `coreshop`.

### B-08 — New rules get `sort = count(all rules) + 1`, which collides with existing values after a delete
- **Where:** `EventListener/NotificationRuleEventListener.php:32-41`, registered in `Resources/config/services/listeners.yml:5-9`.
- **Problem:** with rules `1, 2, 3`, deleting `1` and creating a new rule gives `sort = 3` again (duplicate). It also hydrates every rule entity (with conditions and actions) only to count them.
- **Fix:** `SELECT COALESCE(MAX(o.sort), 0) + 1` through a dedicated repository method (e.g. `getNextSort(): int`).

### B-09 — The registration attributes are not repeatable, so a processor or checker cannot be registered for more than one notification type
- **Where:** `Attribute/AsNotificationRuleActionProcessor.php:21`, `Attribute/AsNotificationRuleConditionChecker.php:21` (`#[\Attribute(\Attribute::TARGET_CLASS)]`).
- **Problem:** in YAML, the same service is tagged once per notification type (e.g. `MailActionProcessor` for 6 types, `CoreBundle/Resources/config/services/notification.yml:208-213`). With the attribute, a second `#[As…]` on the class is a PHP `Error` ("Attribute … must not be repeated"). So the attribute cannot replace the YAML even after TODO-1 **C-01** is fixed.
- **Fix:** `#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]`. `registerAttributeForAutoconfiguration()` calls the configurator once per attribute instance, so the pass receives one tag per type.

### B-10 — Conditions and actions of other rule types are accepted on save
- **Where:** `Form/Type/NotificationRuleActionChoiceType.php`, `Form/Type/NotificationRuleConditionChoiceType.php` (choices = all fully-qualified types from `%coreshop.notification_rule.actions%` / `%…conditions%`, `Resources/config/services/form.yml:39-57`), `Form/Type/NotificationRuleType.php:30-36`.
- **Problem:** the choice lists contain every `<notification-type>.<type>` identifier, independent of the rule's `type`. The admin JS only shows matching ones, but a crafted or scripted save can attach e.g. `payment.paymentState` to an `order` rule. In the worker that checker gets the wrong subject class and throws or returns wrong results, which then triggers **B-01**.
- **Fix:** add a form-level constraint / `POST_SUBMIT` listener that rejects conditions and actions whose prefix is not `$rule->getType() . '.'`.

### B-11 — `AbstractNotificationRulePass` derives an empty `type` for services without a namespaced class (PHP 8.1+ deprecation)
- **Where:** `DependencyInjection/Compiler/AbstractNotificationRulePass.php:59-63`.
- **Problem:** `$definition->getClass()` can be `null` (definitions configured by a factory / parent) or a `%parameter%`; for non-namespaced classes `strrchr()` returns `false`. `strrchr(null, …)` is a deprecation since PHP 8.1, and `substr(false, 1)` returns `''`. The service is then registered as `order.` with an empty type, without an error. The same code is in `Component/Registry/RegisterRegistryTypePass.php:50-52`.
- **Fix:** resolve the class with `$container->getParameterBag()->resolveValue($definition->getClass())`, and throw a clear `InvalidArgumentException` when no `type` can be derived.

### B-12 — Per-notification-type action registries are created with the **condition** interface
- **Where:** `DependencyInjection/Compiler/AbstractNotificationRulePass.php:71-75`.
- **Problem:** the pass creates `coreshop.registry.notification_rule.actions.<type>` with `new Definition(ServiceRegistry::class, [ConditionCheckerInterface::class, …])` for actions too. `ServiceRegistry::register()` (`Component/Registry/ServiceRegistry.php:42-46`) throws `InvalidArgumentException` for every action processor, because they implement `NotificationRuleProcessorInterface`. Today nothing references these services, so they are private and removed before they are ever instantiated. The moment someone injects `@coreshop.registry.notification_rule.actions.order`, the service fails. **(plausible)** — not triggered by the built-in config.
- **Fix:** pass the registry interface into the pass (`NotificationRuleProcessorInterface::class` for actions, `ConditionCheckerInterface::class` for conditions) instead of hard-coding it, or remove the per-type registries if they are not meant to be used (TODO-3 **I-03**).

### B-13 — `sortAction` returns HTTP 500 for unknown ids and ignores `position: after` when dragging upwards
- **Where:** `Controller/NotificationRuleController.php:93-116,136-158`.
- **Problem:**
  - `$this->repository->find($rule)` / `find($toRule)` can return `null` (rule deleted in another tab, bad request). `$rule->getSort()` then throws `Error: Call to a member function getSort() on null` → 500.
  - `position` is only evaluated for the downward case (`:114-116`). ExtJS sends `before` or `after` (`Resources/public/pimcore/js/rule/panel.js:72-76`). Dropping **after** a rule that is above the dragged one puts the rule **before** it (off by one).
  - `rule`, `toRule` and `position` are not validated; a missing `toRule` id is `find(null)`.
- **Fix:** use `findOr404()` for both ids, validate `position` against `['before', 'after']`, and compute the target index once for both directions (TODO-3 **I-05**).

## Packaging

### B-14 — `composer.json` does not declare packages the bundle uses directly
- **Where:** `composer.json:26-32`.
- **Problem:** the bundle uses `symfony/messenger` (+ the `doctrine://` transport from `symfony/doctrine-messenger` in `Resources/config/pimcore/messenger.yml:5,13`), `symfony/form`, `symfony/event-dispatcher`, `symfony/http-foundation`, `doctrine/orm`, `doctrine/collections`, `coreshop/resource-bundle`, and the Pimcore admin controller base (`six-it/admin-ui-classic-bundle` via `ResourceController`), but declares none of them. It also has no `php` constraint (the root `composer.json:88` requires `~8.4.0`). Installed standalone or with `--prefer-lowest`, the bundle can resolve versions that do not support Symfony 7.4 / PHP 8.4, or miss the doctrine transport (`No transport supports the given Messenger DSN "doctrine://default…"`).
- **Fix:** add `"php": ">=8.4"`, `symfony/{messenger,doctrine-messenger,form,event-dispatcher,http-foundation}: ^7.4`, `doctrine/orm`, `doctrine/collections`, `coreshop/resource-bundle`. The dev-tooling part (PHPStan 1.x, `minimum-stability`) is TODO-3 **I-19**.
