# Notification Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this component, but they come from how it is used, and they should be fixed together with it.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items are roughly ordered by impact. Items marked **(plausible)** follow from the code, but were not reproduced against a running shop.

Already tracked elsewhere, and **not repeated here**: `NotificationBundle/TODO-2-BUGS.md` **B-01** (whole message retried → duplicate mails), **B-02** (`mail` / `storeMail` for types without `_locale` / `store_id`), **B-04** (no `ORDER BY` in `findForType()`), **B-05** (missing resource → generic `\Exception`, retried 5×); `Component/Locale/TODO-2-BUGS.md` (`null` `_locale`). Fix those together with the items here that mention them.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Worker state (long-running `messenger:consume`)

### B-01 — (dependent) An exception while sending a mail leaves Pimcore's "inherited values" switch on for every later message in the same worker
- **Where:** `Rule/Action/MailActionProcessor.php:66-68` (`InheritanceHelper::useInheritedValues(fn () => $this->mailProcessor->sendMail(…))`). Cause: `Component/Pimcore/DataObject/InheritanceHelper.php:29-38` (backup → set → `$function()` → restore, **no `try` / `finally`**).
- **Problem:**
  - If `sendMail()` throws (SMTP down, Twig error in the mail document, missing template variable, `PRE_MAIL_SEND` listener error), the line that restores `AbstractObject::setGetInheritedValues($backup)` is never reached.
  - In a request this does not matter much. In `messenger:consume` it is process-global state: the worker reads **inherited** values for every following message, until it is restarted. That includes the handlers of other transports consumed by the same worker (e.g. `coreshop_index`, `IndexBundle/Resources/config/pimcore/messenger.yml`), whose results then depend on whether a notification mail failed before.
  - Notification mails fail regularly (NotificationBundle B-01 / B-02), so this is not a rare path.
- **(plausible):** not reproduced; the missing `finally` is in the code, and Pimcore's flag is a static property (`DataObject\AbstractObject::$getInheritedValues`).
- **Fix:** wrap the call in `try { return $function(); } finally { setGetInheritedValues($backup); }` in `InheritanceHelper` (the same pattern exists in `VersionHelper` and other `use*()` helpers of `Component/Pimcore`; check them in the same change). Add a test that throws inside the closure and asserts the flag is restored.

### B-02 — (dependent) `storeMail`: the store theme is not restored after an exception, and never reset when the worker had no theme before, so later mails of another store are rendered with the wrong theme
- **Where:** `Component/Core/Notification/Rule/Action/Order/StoreMailActionProcessor.php:55-61` → `Rule/Action/MailActionProcessor.php:33`. Cause: `ThemeBundle/Service/ThemeHelper.php:32-48`.
- **Problem:**
  - `useTheme()` sets the store's theme, calls the closure, and restores the previous theme only `if ($backupTheme)` and only when the closure did not throw.
  - In the worker there is no request, so `$backupTheme` is usually `null`. After the first `storeMail` the theme of store A stays active. The next message for store B with a theme that `findOneByName()` does not find (`$store->getTemplate()` empty / renamed) is rendered with **store A's** templates (logo, footer, legal texts, links).
  - The same happens after any exception inside the closure (B-01 path).
- **(plausible):** not reproduced in a multi-store setup.
- **Fix:** `try/finally` in `ThemeHelper::useTheme()`, and restore `null` too (a `SettableThemeContext::setTheme(null)` or a `reset()`), not only a non-null backup. Better: register the theme context for `kernel.reset` / `services_resetter` so the worker resets it between messages (`symfony/messenger/EventListener/ResetServicesListener.php` resets tagged services on `WorkerRunningEvent` unless `--no-reset` is used).

## Rule processing

### B-03 — A stored rule that references an unknown condition or action type makes every message of that notification type fail
- **Where:** `Processor/RuleApplier.php:34-39` (`$this->actionServiceRegistry->get($action->getType())`), `Messenger/Handler/NotificationMessageHandler.php:68-72` → `Component/Rule/Condition/RuleConditionsValidationProcessor.php:61` (`$this->ruleRegistry->get($condition->getType())`). Registry: `Component/Registry/ServiceRegistry.php` (`get()` throws `NonExistingServiceException`).
- **Problem:**
  - Condition and action types are stored as strings in the rule configuration. When a project removes or renames a custom action / condition, or a bundle that registered one is disabled, the existing rules still reference it.
  - `ServiceRegistry::get()` throws, the exception leaves the handler, and the **whole** message is retried and finally moved to the failure transport. All other rules of that type (order confirmation, …) are blocked for as long as the broken rule is active, and the rules that ran before it are re-run on every retry (NotificationBundle B-01).
  - The error message does not name the rule id, so the admin cannot find the broken rule from the failed-message list.
  - In `RuleApplier` the `instanceof NotificationRuleProcessorInterface` check (`:37`) silently skips processors of the wrong type. The registry already guarantees the interface, so the check is dead, and the real failure case (unknown type) is not handled.
- **Fix:** check `has()` before `get()`. For an unknown type: log a warning with rule id, rule name and type, and skip the rule (conditions: treat the rule as not valid; actions: skip the action). Throw `UnrecoverableMessageHandlingException` only if nothing else can run. Validate the types on save as well (NotificationBundle B-10).

### B-04 — Mail document ids are passed to `Document::getById()` as strings (Pimcore 11 deprecation on every mail, `TypeError` for `null`), and an unconfigured language silently sends nothing
- **Where:** `Rule/Action/MailActionProcessor.php:46-48`, `:63`. **(dependent)** `Component/Core/Notification/Rule/Action/Order/OrderMailActionProcessor.php` (same code for `orderMail`), `NotificationBundle/Form/Type/Rule/Action/MailActionConfigurationType.php:28-31` (`CollectionType` with the default `TextType` entries), `NotificationBundle/Resources/public/pimcore/js/rule/actions/mail.js:30,89` (sends `elementHref.data.id`, initial value `''`), `CoreBundle/Fixtures/Data/Application/NotificationRulesFixture.php:70-78` (writes `null` when the document is missing). Pimcore: `six-it/pimcore-source/models/Document.php:187-199`.
- **Problem:**
  - The form stores every configured document id as a **string** (`TextType`). `Document::getById(int|string $id)` triggers `trigger_deprecation('pimcore/pimcore', '11.0', 'Passing id as string … is deprecated')` for each mail sent. Once Pimcore drops the `string` branch, every mail action fails with a `TypeError`.
  - A language that was left empty in the admin is stored as `''`: `getById('')` → `0` → `null` → no mail, **no log**. The customer simply never gets the mail, and nothing in the logs tells the admin why.
  - A `null` value (written by the fixture when a document is missing, or by an import / API that bypasses the form) is a `TypeError` under `declare(strict_types=1)`, which then triggers NotificationBundle B-01 for the other rules.
- **Fix:** `$id = filter_var($mails[$language] ?? null, FILTER_VALIDATE_INT)`; if it is not a positive int, log a warning (rule id, language) and return. Use `IntegerType` entries (or a model transformer) in `MailActionConfigurationType` so the configuration is stored as `int`. Log when `getById()` returns something that is not a `Document\Email` (deleted / wrong document type).

### B-05 — `order` / `store` parameters are replaced by ids in `RulesProcessor`, but nothing turns them back into objects; templates and custom actions that read `order` / `store` get nothing
- **Where:** `Processor/RulesProcessor.php:34-46` (the "BC" blocks unset `order` / `store`), `Messenger/Handler/NotificationMessageHandler.php:60-63` (only `subject` / `resource` / `object` are restored). Consumers: every mail document that uses `{{ order }}` / `{{ store }}`, custom `NotificationRuleProcessorInterface` / condition checkers that read `$params['order']`. **(dependent)** `Component/Core/Notification/Rule/Action/Order/StoreOrderMailActionProcessor.php:43` (reads `$params['store']`, see **B-07**), `OrderMailActionProcessor.php` (reloads `order_id` itself).
- **Problem:**
  - The comments say "BC", but the result is the opposite: code written for the synchronous processor that passes `'order' => $order` (documented in `docs/03_Development/09_Notification_Rules/04_Triggering.md`) silently loses the parameter. The mail template renders empty values instead of the order number / store name, and no error is raised.
  - Only `OrderMailActionProcessor` and `StoreMailActionProcessor` know about `order_id` / `store_id`; the generic `mail` action and all custom actions do not.
  - Any **other** object in `$params` (customer, payment, Pimcore `Note`, …) is serialized in full instead (NotificationBundle I-18), so the behaviour depends on the key name.
- **(plausible):** depends on the project's mail templates; the built-in templates were not checked.
- **Fix:** make the reference handling symmetric: in `RulesProcessor` replace **every** `ResourceInterface` / Pimcore `ElementInterface` value by a small reference (`['__ref' => class, 'id' => id]`), and resolve the references in `NotificationMessageHandler` before the conditions run (with the same loader as the subject, TODO-3 **I-04**). Then `order`, `store`, `customer`, `comment` keep working, and the message stays small. Keep `order_id` / `store_id` as additional keys for BC.

### B-06 — `RulesProcessor` passes `ResourceInterface::getId(): int|string|null` into `int $resourceId`; a string or `null` id throws a `TypeError` in the storefront request
- **Where:** `Processor/RulesProcessor.php:48-55`, `Messenger/NotificationMessage.php:26`. Interface: `Component/Resource/Model/ResourceInterface.php:27` (`getId(): int|string|null`).
- **Problem:**
  - Both files use `declare(strict_types=1)`. A resource with a string id (UUID / ULID Doctrine entities, which `ResourceInterface` explicitly allows) or a not-yet-persisted subject (`null`) throws `TypeError: NotificationMessage::__construct(): Argument #3 ($resourceId) must be of type int`.
  - `applyRules()` is called from event listeners **inside** the user's request (registration, password reset, checkout workflow transitions, admin order save). The `TypeError` is not caught there, so the user's action fails with a 500 after the data was already saved (e.g. the order is created, but the customer sees an error page and may order again).
  - Built-in subjects (Pimcore objects, `Payment` entity) have integer ids, so only custom notification types are affected today. NotificationBundle I-18 mentions asserting non-null; the `string` case and the request impact are new here.
- **Fix:** type `NotificationMessage::$resourceId` as `int|string`, assert `null !== $subject->getId()` in `RulesProcessor` with a clear `\LogicException` (subject must be persisted). Decide whether a failure to **dispatch** may break the request; if not, catch and log in `RulesProcessor` / `EventedRuleProcessor`.

### B-07 — (dependent) `StoreOrderMailActionProcessor` checks `store_id` but loads `$params['store']`
- **Where:** `Component/Core/Notification/Rule/Action/Order/StoreOrderMailActionProcessor.php:42-44`. Compare `StoreMailActionProcessor.php:46-48` (correct: `find($params['store_id'])`).
- **Problem:** `isset($params['store_id'])` → `$this->storeRepository->find($params['store'])`. `store` was unset by `RulesProcessor` (**B-05**), so this is an "Undefined array key" warning and `find(null)`, and the action throws `StoreOrderMailActionProcessor: Store is not set.` whenever the subject is not `StoreAwareInterface` (payment / invoice / shipment rules once NotificationBundle B-02 passes `store_id`).
- **Fix:** `find($params['store_id'])`. Better: resolve the store from the order (`order_id`) like `OrderMailActionProcessor` resolves the order.

### B-08 — The handler crashes with a `ReflectionException` (and is retried 5×) when the stored resource class no longer exists, and it stores Doctrine proxy class names
- **Where:** `Messenger/Handler/NotificationMessageHandler.php:45-52`, `Processor/RulesProcessor.php:51` (`get_class($subject)`). Doctrine: `doctrine/persistence/src/Persistence/AbstractManagerRegistry.php:173-200` (3.4.5: `new ReflectionClass($class)` without a guard).
- **Problem:**
  - Messages can wait for hours (retries) or forever (failure transport, admin "retry" button). If the class was renamed, a Pimcore class was deleted, or a Doctrine entity was moved in between, `is_subclass_of()` returns `false` and `getManagerForClass()` throws `ReflectionException: Class "…" does not exist`. That is not caught, so the message is retried 5 times with back-off and then fails again from the admin.
  - `get_class($subject)` returns `Proxies\__CG__\…` when the subject is a lazy Doctrine proxy (e.g. a `Payment` reached through an association). It works today only because `AbstractManagerRegistry` and the metadata factory unwrap proxy names, and only as long as the proxy class file exists in the worker's cache directory (deploy with a new cache dir while messages are pending → autoload failure).
- **Fix:** store the real class (`Doctrine\Common\Util\ClassUtils::getRealClass()` / `$em->getClassMetadata($subject::class)->getName()`; with ORM 3.4+ native lazy objects `$subject::class` is already the real class). In the handler, check `class_exists()` first and throw `UnrecoverableMessageHandlingException` with class and id (together with NotificationBundle B-05).

### B-09 — `MailActionProcessor` silently ignores a missing or invalid `recipient` and sends the customer mail only to the document's default recipients
- **Where:** `Rule/Action/MailActionProcessor.php:49-59`. **(dependent)** `PimcoreBundle/Mail/MailProcessor.php` (`addRecipients([])` → only the document's "To"), `CoreBundle/EventListener/NotificationRules/PaymentWorkflowListener.php`, `OrderDocumentWorkflowListener.php` (no `recipient` key), `CustomerListener.php:131-138`, `OrderWorkflowListener.php` (`getEmail()` can be `null`).
- **Problem:**
  - `doNotSendToDesignatedRecipient = false` means "send to the customer". When `recipient` is missing, `null`, an empty string, or not a valid e-mail, the action falls back to `[]` without any log. The mail then goes only to the "To" of the e-mail document, which is often the shop owner or a catch-all. Customer-specific content (order details, the password-reset link from TODO-1 **C-01**) is delivered to that address instead, and the customer gets nothing.
  - If the document has no "To" either, Symfony Mailer throws `LogicException: An email must have a "To", "Cc", or "Bcc" header`, which triggers NotificationBundle B-01.
  - Empty strings are accepted as a recipient (`is_string('')`).
- **Fix:** when `doNotSendToDesignatedRecipient` is `false`, require a non-empty recipient list (`array_filter()` + `filter_var(…, FILTER_VALIDATE_EMAIL)`); if it is empty, log a warning with rule id and subject and skip the action (or throw `UnrecoverableMessageHandlingException`). Document the `recipient` parameter (TODO-3 **I-12**).

## Model / packaging

### B-10 — `NotificationRule::$sort` defaults to `null`, but the column is `NOT NULL`; rules created outside the admin controller fail on flush
- **Where:** `Model/NotificationRule.php:40-43`, `:60-68`. **(dependent)** `NotificationBundle/Resources/config/doctrine/model/NotificationRule.orm.xml:14` (`<field name="sort" type="integer"/>`, not nullable), `NotificationBundle/EventListener/NotificationRuleEventListener.php` (sets `sort` only on `coreshop.notification_rule.pre_create`, i.e. only through the admin `ResourceController`).
- **Problem:** a rule created via the factory (`coreshop.factory.notification_rule`), a migration, a custom import or an API persists `sort = NULL` and fails with `NotNullConstraintViolationException`. The fixture works only because it calls `setSort()` itself (`CoreBundle/Fixtures/Data/Application/NotificationRulesFixture.php:85`). `type` is nullable in the mapping (`:12`), so a rule without type is saved but can never match.
- **Fix:** `protected int $sort = 0;` (and `?string $type = null` with validation `NotBlank`), typed setters (NotificationBundle I-14). Or make the column `nullable="true"` and order `NULLS LAST`; the default value is simpler.

### B-11 — `composer.json` does not declare packages the component uses directly
- **Where:** `composer.json:26-32`.
- **Problem:** the component uses `Symfony\Component\Messenger\MessageBusInterface` (`Processor/RulesProcessor.php:23`), `Doctrine\Persistence\ManagerRegistry` (`Messenger/Handler/NotificationMessageHandler.php:27`) and Doctrine collections (via `RuleTrait`), but declares none of them, and has no `php` constraint (the root `composer.json` requires `~8.4.0`). Installed standalone or with `--prefer-lowest`, the component can resolve versions that do not support Symfony 7.4 / PHP 8.4, or none at all.
- **Fix:** add `"php": ">=8.4"`, `"symfony/messenger": "^7.4"`, `"doctrine/persistence": "^3.4"`. The dev-tooling part is TODO-3 **I-13**. Same as NotificationBundle B-14; fix together.
