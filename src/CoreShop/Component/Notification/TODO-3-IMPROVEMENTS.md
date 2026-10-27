# Notification Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Code quality, readability, logic and modernization items. None of them is a functional bug on its own. Items marked **(dependent)** live outside this component.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.

Already tracked in `NotificationBundle/TODO-3-IMPROVEMENTS.md`, **not repeated here**: **I-01** (one message per rule), **I-12** (`ManagerRegistry` service id, `#[AsMessageHandler]`, `: void` on `__invoke()`), **I-14** (typed model / repository API), **I-15** (`NotificationMessage` as `final readonly class`), **I-18** (`AbstractConditionChecker` dead `instanceof`, `MailActionProcessor` defaults / nesting, `get_class()` → `::class`), **I-20** (tests). The items below complement them.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Design / API

### I-01 — Remove or implement `RulesAsyncProcessorInterface`; the names "processor" / "apply" no longer describe what happens
- **Where:** `Processor/RulesAsyncProcessorInterface.php`, `Processor/RulesProcessorInterface.php`, `Processor/RulesProcessor.php`.
- **Idea:**
  - `RulesAsyncProcessorInterface::applyRulesAsync()` has no implementation and no consumer in the repository. It is a leftover from the time `RulesProcessor` was synchronous.
  - Today `RulesProcessorInterface::applyRules()` **is** the async path: it only dispatches a message, nothing is "applied". That misleads callers (and listeners of `EventedRuleProcessor`'s `POST_APPLY`, NotificationBundle B-06).
  - Option: keep `RulesProcessorInterface` as the public entry point, document it as "dispatch notification event", mark `RulesAsyncProcessorInterface` `@deprecated` and remove it in the next major. If a synchronous path is needed (tests, CLI), add `NotificationDispatcherInterface::dispatch()` + an explicit `SyncRulesProcessor` that calls the handler directly.

### I-02 — Replace `NotificationConditionCheckerInterface` with a typed, consistent signature
- **Where:** `Rule/Condition/NotificationConditionCheckerInterface.php:23-26`, `Rule/Condition/AbstractConditionChecker.php:25-38`. **(dependent)** all 8 checkers in `Component/Core/Notification/Rule/Condition/**`.
- **Idea:**
  - `isNotificationRuleValid($subject, array $params, array $configuration)` swaps the order of `$configuration` and `$params` compared to `ConditionCheckerInterface::isValid(…, array $configuration, array $params)`. Easy to mix up in custom checkers.
  - The rule is not passed, so a checker cannot log or decide anything based on it.
  - `$subject` is untyped although `isValid()` guarantees `ResourceInterface`.
  - The `['params' => …]` wrapping (`AbstractConditionChecker.php:33-37`, `NotificationMessageHandler.php:69`) is an internal detail that every custom checker that does not extend the abstract class must know.
  - Suggestion: `isNotificationRuleValid(ResourceInterface $subject, NotificationRuleInterface $rule, array $configuration, array $params): bool` in a new interface (or with a BC layer in `AbstractConditionChecker` that detects the old method via reflection and triggers a deprecation). Fix the misleading exception message (`$subject needs to be an array …`) together with NotificationBundle I-18.

### I-03 — Type `NotificationRuleProcessorInterface` and `RuleApplierInterface`
- **Where:** `Rule/Action/NotificationRuleProcessorInterface.php:25`, `Processor/RuleApplierInterface.php:25-30`, `Processor/RuleApplier.php:32`, `Rule/Action/MailActionProcessor.php:33`.
- **Idea:** `apply($subject, …)` / `applyRule(…, $subject, …)` are untyped (`@param mixed`), but the handler only ever passes a `ResourceInterface`. Type them as `ResourceInterface $subject`. Implementations may keep the untyped parameter (contravariance), so this is BC for implementers; only callers that pass non-resources break, and there are none. The `@param mixed $subject` docblock with odd alignment (`RuleApplierInterface.php:28`) goes away.
  Consider returning a small result (`bool $applied` or an enum `Applied|Skipped|Failed`) instead of `void`, so the handler can log what happened per rule (needed for TODO-2 **B-03**, **B-09** and NotificationBundle I-01).

### I-04 — Extract resource loading from `NotificationMessageHandler` into a dedicated service
- **Where:** `Messenger/Handler/NotificationMessageHandler.php:45-56`.
- **Idea:**
  - Introduce `NotificationSubjectLoaderInterface::load(string $class, int|string $id): ?ResourceInterface` with a Pimcore and a Doctrine implementation (or a chain). The handler then only orchestrates, the loader can be reused to resolve parameter references (TODO-2 **B-05**), and projects can plug in other storage.
  - For Pimcore use `$class::getById($id)` instead of `Concrete::getById($id)`: it checks the class (`typeMatch()`), so a message can never be applied to an object of another class.
  - `is_subclass_of($class, Concrete::class)` → `is_a($class, Concrete::class, true)` (also true for the class itself, and reads as intended).
  - The `/** @psalm-var class-string $className */` re-declaration (`:49-50`) and the copy into `$className` are redundant; `getResourceType()` is already annotated `@psalm-return class-string`.

### I-05 — Make the parameter contract explicit
- **Where:** `Processor/RulesProcessorInterface.php:25`, `Messenger/Handler/NotificationMessageHandler.php:60-63`, `Rule/Action/MailActionProcessor.php:38-64`. **(dependent)** `CoreBundle/EventListener/NotificationRules/*.php`, `Component/Core/Notification/**`.
- **Idea:**
  - The keys that have a meaning are spread over many classes: `_locale`, `recipient`, `order_id`, `store_id`, `type`, `transition`, `workflow`, `backend_updated`, `doNotSendToDesignatedRecipient`, plus the injected `subject` / `resource` / `object` (the same object three times, "BC") and `rule` (generic `mail`) vs `mailRule` (`OrderMailActionProcessor`) for the same thing.
  - Add `@param array{_locale?: string, recipient?: string|list<string>, order_id?: int, store_id?: int, …} $params` (PHPStan array shape) on `RulesProcessorInterface`, and a class with string constants (`NotificationParams::LOCALE = '_locale'`, …) used by producers and consumers.
  - Unify `rule` / `mailRule` (pass both for BC, deprecate one). `MailActionProcessor.php:64` sets `$params['object']` again although the handler already did (`NotificationMessageHandler.php:63`); remove it.
  - Longer term: a `NotificationContext` value object (`type`, `subject`, `locale`, `recipients`, `extra`) instead of an untyped array.

### I-06 — Use specific exception classes and Messenger's unrecoverable marker
- **Where:** `Messenger/Handler/NotificationMessageHandler.php:55`, `Rule/Action/MailActionProcessor.php:43`, `Rule/Condition/AbstractConditionChecker.php:30,34`. **(dependent)** `Component/Core/Notification/Rule/Action/Order/*.php` (`\Exception('… is not set.')`).
- **Idea:** add `Exception/NotificationExceptionInterface`, `SubjectNotFoundException`, `MissingParameterException` (implements `Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface`, so it is not retried; NotificationBundle B-05, TODO-2 **B-08**). Messages must contain rule id, notification type, subject class and id. Generic `\Exception` makes the admin failed-message list useless for diagnosis.

### I-07 — Generalize the "BC" parameter conversion in `RulesProcessor`
- **Where:** `Processor/RulesProcessor.php:34-46`.
- **Idea:** the two blocks are identical apart from the key. Until TODO-2 **B-05** is done, at least replace them with a loop over `['order' => 'order_id', 'store' => 'store_id']`. `isset($params['order']) && $params['order'] instanceof …` → `($params['order'] ?? null) instanceof ResourceInterface`. Replace `//BC` with a comment that says **what** is kept compatible and since when.

### I-08 — Do not depend on the concrete `MailActionProcessor` class
- **Where:** `Rule/Action/MailActionProcessor.php:26`. **(dependent)** `Component/Core/Notification/Rule/Action/Order/StoreMailActionProcessor.php:32` (`protected MailActionProcessor $mailActionProcessor`), `StoreOrderMailActionProcessor.php:30` (`OrderMailActionProcessor`).
- **Idea:** the store variants type-hint the concrete classes, so a project that decorates or replaces the `mail` action service is bypassed for `storeMail`. Inject `NotificationRuleProcessorInterface` (named argument / `#[Autowire(service: …)]`), then make `MailActionProcessor` `final` (see **I-10**).

## Readability / PHP 8.4

### I-09 — Model cleanup
- **Where:** `Model/NotificationRuleInterface.php:25-45`, `Model/NotificationRule.php:23-68`.
- **Idea:** (complements NotificationBundle I-14)
  - `getId(): ?int` is already declared by `RuleInterface`; remove the duplicate.
  - `@psalm-suppress MissingConstructor` (`NotificationRule.php:23-25`) is obsolete: `RuleTrait` has a constructor that initializes the collections. With typed properties and defaults (`?int $id = null`, `?string $type = null`, `int $sort = 0`) the suppression is not needed at all.
  - Docblocks that only repeat the types (`@var int`, `@return string`, `@param int $sort`) go away with native types.
  - `RuleTrait::__construct(\n        ) {` (`Component/Rule/Model/RuleTrait.php`) has the same empty-parameter-list formatting glitch as the NotificationBundle compiler passes (NotificationBundle I-16).

### I-10 — `final`, `readonly`, `#[\Override]`, visibility
- **Where:** whole component.
- **Idea:**
  - `NotificationMessageHandler`, `RuleApplier`, `RulesProcessor`: `final` + `private readonly` promoted properties. The handler currently uses `protected` for all four dependencies (`:32-37`) without any subclass in the repository; `MailActionProcessor` uses `protected` too (`:28-30`).
  - `RuleApplier` / `RulesProcessor` already use `private` — make them `readonly`.
  - Add `#[\Override]` (PHP 8.3+) to `applyRule()`, `applyRules()`, `apply()`, `isValid()`, `getId()`, `getType()`, … so signature drift in `RuleInterface` / `ConditionCheckerInterface` is caught.
  - `MailActionProcessor` can be `final` after **I-08**.

### I-11 — Small syntax / readability fixes
- **Where / idea:**
  - `Messenger/Handler/NotificationMessageHandler.php:65-67`: the `/** @var NotificationRuleInterface $rule */` block above `foreach` becomes redundant once `findForType()` returns `list<NotificationRuleInterface>` (NotificationBundle I-14).
  - `Repository/NotificationRuleRepositoryInterface.php:26-31`: `findForType($type)` with `@param string` → `findForType(string $type): array` (`@return list<NotificationRuleInterface>`).
  - `Rule/Action/MailActionProcessor.php:35-44`: `$language = $params['_locale'] ?? null;` replaces the `null` initialisation + `array_key_exists()`. `:52-58`: `$recipient = (array) ($params['recipient'] ?? [])` (after validation, TODO-2 **B-09**).
  - `Rule/Action/MailActionProcessor.php:66-68`: arrow function `fn () => $this->mailProcessor->sendMail(…)` instead of the closure with a long `use (…)` list. Use the return value of `sendMail()` (`false` when a `PRE_MAIL_SEND` listener cancelled it) for logging.
  - `//BC` comments without a space (`RulesProcessor.php:34,41`, `NotificationMessageHandler.php:60`) — PSR-12 / php-cs-fixer `single_line_comment_spacing`.

## Documentation / packaging / tests

### I-12 — Fix and extend the documentation
- **Where:** `README.md`, **(dependent)** `docs/03_Development/09_Notification_Rules/04_Triggering.md:4-24`, `01_Custom_Actions.md`, `02_Custom_Conditions.md`.
- **Idea:**
  - `04_Triggering.md` names the service `@coreshop.notification_rule.processor`, which does not exist (the id is `CoreShop\Component\Notification\Processor\RulesProcessorInterface`). The example uses `$event->getProposal()` (removed API) and `$order` that is not defined in the snippet.
  - Document that rules are processed **asynchronously** by `messenger:consume coreshop_notification` (a missing worker means no mails at all), the parameter contract (**I-05**), that objects in `params` are serialized (TODO-1 **C-01**, TODO-2 **B-05**), and how failed notifications are retried from the admin.
  - `README.md` is the generic CoreShop text; describe the component (message, handler, processors, extension points).

### I-13 — `composer.json` tooling and metadata
- **Where:** `composer.json:33-50`.
- **Idea:** PHPStan `^1.10` → `^2.1` (PHPStan 1.x does not fully understand PHP 8.4 syntax), `phpstan-doctrine` / `phpstan-webmozart-assert` accordingly. `repositories: [{type: path, url: ../../*/*}]` and `"minimum-stability": "dev"` belong to the monorepo, not to a split package. `branch-alias dev-master: 4.1-dev` is upstream versioning; align with the fork. Missing runtime deps are TODO-2 **B-11**. Same as NotificationBundle I-19.

### I-14 — Component-level unit tests
- **Where:** no `Tests/` directory. NotificationBundle I-20 lists bundle and integration tests; these are the pure unit tests for this component:
  1. `RulesProcessor`: `order` / `store` → ids, other params unchanged, sensitive params not serialized (TODO-1 **C-01**), string / `null` id handling (TODO-2 **B-06**).
  2. `NotificationMessageHandler`: Pimcore vs Doctrine subject, unknown class (TODO-2 **B-08**), unknown condition / action type (TODO-2 **B-03**), `subject` / `resource` / `object` injected.
  3. `MailActionProcessor`: missing `_locale`, language without document (TODO-2 **B-04**), string / int / `null` id, missing recipient (TODO-2 **B-09**), inheritance flag restored after an exception (TODO-2 **B-01**).
  4. `AbstractConditionChecker`: missing `params` key, delegation with the right argument order (**I-02**).
  5. `NotificationMessage`: round trip through `PhpSerializer` (catches accidental non-serializable params early).
