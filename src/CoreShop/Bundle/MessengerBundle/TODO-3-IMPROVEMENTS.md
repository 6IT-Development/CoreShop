# MessengerBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Code quality, readability, logic and modernization items. None of them is a functional bug on its own. Items marked **(dependent)** live outside this bundle.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Design

### I-01 — Split the runtime part from the classic-admin part
- **Where:** whole bundle. Runtime: `Resources/config/pimcore/config.yml:8-12` (`coreshop.bus`), `EventListener/FailureListener.php`, `Stamp/*`, `Messenger/*`, `Event/*`, `Exception/*`, compiler passes. Classic admin: `Controller/*`, `Resources/config/pimcore/{admin,routing}.yml`, `Resources/public/**`, `Menu/MessengerMenuBuilder.php`, `Resources/config/services/menu.yml`, `Migrations/Version20240421093216.php`, `Resources/translations/admin.*.yml`, the `CoreShopMenuBundle` dependency (`CoreShopMessengerBundle.php:36`), `six-it/admin-ui-classic-bundle` in `composer.json:28`.
- **Idea:**
  - **(dependent)** `NotificationBundle` depends on this bundle (`NotificationBundle/CoreShopNotificationBundle.php:52`) for `coreshop.bus` and `PimcoreObjectStamp` only. Today it also pulls in the classic admin UI, KnpMenu and the admin controllers.
  - Load the admin part only when `PimcoreAdminBundle` (classic) is registered (check `kernel.bundles` in the extension, like `services/menu.yml` already does for `CoreShopCoreBundle`), and register `CoreShopMenuBundle` only in that case. The `Messenger/*` repositories, retryer and rejecter are already UI-independent and can back a Pimcore Studio API later without changes.
  - Move `PimcoreObjectStamp` next to the code that adds it, or keep it here but make it independent of Pimcore (`ElementStamp(string $type, int $id)`, see **I-06**).

### I-02 — Use Symfony's failed-message logic instead of re-implementing it
- **Where:** `Messenger/FailedMessageRetryer.php`, `Messenger/FailedMessageRejecter.php`, `Messenger/FailureReceiversRepository.php`, `DependencyInjection/CompilerPass/FailureReceiverPass.php`.
- **Idea:** structural version of TODO-2 **B-09**, **B-10**. Symfony's `messenger:failed:retry` (`symfony/messenger/Command/FailedMessagesRetryCommand.php`) handles the envelope **in-process** with a `Worker` on a `SingleMessageReceiver`, which keeps all stamps, applies the retry strategy, dispatches the normal worker events and puts the message back into the failure transport when it fails again. Retrying in the admin request has the same "handle synchronously" problem, so the clean variant is: the retry button marks the message (e.g. moves it back to `getOriginalReceiverName()`'s sender with a fresh `RedeliveryStamp(0)`), and the existing worker handles it. That also makes the retry asynchronous and observable in the pending list.

### I-03 — Controllers: typed arguments, interfaces, proper responses
- **Where:** `Controller/ListMessagesController.php`, `Controller/MessageController.php`, `Resources/config/pimcore/routing.yml`.
- **Idea:**
  - Take the route parameter as an argument (`string $receiverName`) instead of `$request->attributes->get()` + dead `is_string()` (TODO-2 **B-08**).
  - Type-hint the interfaces. `MessageController` hints the concrete `FailedMessageRejecter` / `FailedMessageRetryer` (`:30,46`), so a project that decorates the `…Interface` alias is ignored.
  - Add return types (`JsonResponse`), and read `id` with `$request->request->getString('id')` or a `#[MapRequestPayload]` DTO.
  - `listReceiverMessageCountAction` and `listListableReceiversAction` (`:35-63`) are the same loop over different mappings; extract one private method.
  - Add `requirements` / `methods` to the read routes (`GET`), and `methods: DELETE|POST` stay as they are. Pimcore's CSRF listener covers the write routes already (`admin-ui-classic-bundle/src/EventListener/CsrfProtectionListener.php:45-70`).
  - Make both controllers `final` and wire them with `autowire: true` instead of `setContainer('@service_container')` (`Resources/config/services.yml`), which gives them the whole container.

### I-04 — Message details: escaped, bounded output and a clear event contract
- **Where:** `Messenger/MessageRepository.php:51-55`, `Messenger/FailedMessageRepository.php:55-61`, `Messenger/{MessageDetails,FailedMessageDetails}.php` (`setSerialized()`), `Event/*`.
- **Idea:**
  - `print_r()` of a message has no depth or size limit. A message that holds an entity or a Pimcore object (projects do this) prints the whole object graph, megabytes per row, up to 100 rows per request. Use `Symfony\Component\VarDumper\Cloner\VarCloner` with `setMaxItems()` / `setMaxString()` + `HtmlDumper` (escapes by itself, TODO-1 **C-01**), or the transport serializer's JSON for JSON-serialized transports.
  - Build the details lazily: the list needs `id`, `class`, `failedAt`, `error`; the body only when the admin clicks **Details** (new endpoint `…/show/{receiverName}/{id}`, `ListableReceiverInterface::find()`).
  - The events are dispatched with string names (`'coreshop.messenger.message_details'`, `'coreshop.messenger.failed_message_details'`). Dispatch them without a name (the event class is the name), or put the names in an `Events` class, and document in `docs/03_Bundles/Messenger_Bundle.md` that `setSerialized()` takes HTML that the listener must escape.

### I-05 — Listing: pagination, configurable limits, cheaper counts
- **Where:** `Controller/ListMessagesController.php:91,108` (hard-coded `100`), `:35-47` (counts), `Messenger/ReceiversRepository.php:37-64`, `Resources/public/pimcore/js/list.js:51-57`.
- **Idea:**
  - Only the oldest 100 messages are shown, and the grid does not say so. Show the total (`MessageCountAwareInterface`) and "showing 100 of N", or page with `limit` / `start` (Doctrine's `findAll()` only supports a limit, so paging beyond that needs a custom query or `find()` by id range).
  - `getReceiversMapping()` instantiates every receiver on every refresh (connects to every broker). Cache the counts for a few seconds, or only count the receivers shown in the chart.
  - Stop the auto-refresh timer while the tab is not visible (`document.visibilityState`, or the panel's `deactivate` event), and keep the selected receivers when the panel is reopened.

### I-06 — `FailureListener` cleanup
- **Where:** `EventListener/FailureListener.php`, `Stamp/PimcoreObjectStamp.php`.
- **Idea:** after TODO-1 **C-02** and TODO-2 **B-01** – **B-03**:
  - Make the filter (bus name / transport names) a bundle option, e.g. `core_shop_messenger.failure_log.transports: ['coreshop_*']` (TODO-2 **B-02**).
  - Use PSR-3 placeholders (`'Message {messageId} of class {messageClass} failed …'`) instead of `sprintf()` + `array_splice()` (`:81-91`), and `error` or `critical` instead of `alert` (Symfony itself logs the final failure as `critical`, `SendFailedMessageForRetryListener.php:79`).
  - Native types: `getSubscribedEvents(): array`, `onMessageFailed(…): void`, `$envelope->getMessage()::class` instead of `\get_class()`. Make the class `final` with a `private readonly` logger.
  - The early `if ($errorStamp instanceof ErrorDetailsStamp)` silently skips logging; `$event->getThrowable()` always exists, use it as the source of truth.
  - `PimcoreObjectStamp` only stores an id, so the log links everything as `object`. Store the element type too (`object` / `asset` / `document`), make it `final` with `readonly` properties. **(dependent)** `NotificationBundle/EventListener/MessengerListener.php:44-46` only adds it for `Concrete` subclasses; orders, payments, invoices and shipments are all `Concrete`, so that part is fine.

## Config / container

### I-07 — `Configuration`: remove unused nodes, fix the `permissions` node
- **Where:** `DependencyInjection/Configuration.php:40-48`, `:76-79`, `DependencyInjection/CoreShopMessengerExtension.php:42`.
- **Idea:**
  - `doctrine.table_name` / `doctrine.connection` are never read. Remove them, or implement what they were meant for and document it.
  - `permissions` is a `scalarNode` with an array default (`['messenger']`); configuring it is impossible (`cannotBeOverwritten()`), and nothing reads `coreshop.pimcore.admin.permissions`. The real permission comes from the migration (**I-14**). Remove it.
  - `editmode_js` / `editmode_css` are never used by this bundle; the generic Pimcore-resources section could be shared with the other CoreShop bundles (`PimcoreBundle` already has the extension helper).

### I-08 — Remove dead code
- **Where / idea:**
  - `Messenger/FailedMessageDetails.php:21,34-61`: `#[SerializedName]` attributes are never used, because `jsonSerialize()` wins (TODO-2 **B-07**). Remove them and the `symfony/serializer` import, or remove `JsonSerializable` and let the serializer use them; not both.
  - `Messenger/MessageRetriedByUserEvent.php`: never dispatched (TODO-2 **B-09**). Dispatch it or delete it.
  - `Stamp/RetriedByUserStamp.php`: added on retry, never read. Use it in `FailureListener` / the UI ("retried manually"), or drop it.
  - `Exception/FailureReceiverDoesNotExistException.php`: never thrown. `FailureReceiversRepository::getFailureReceiver()` should throw it instead of the locator's `ServiceNotFoundException` (TODO-2 **B-08**).
  - `Resources/config/services.yml:49-50`: `monolog.logger` tag on `FailedMessageRetryer`, which has no logger argument.
  - `Resources/public/pimcore/js/resource_standalone.js:17-21`: `coreshop.menu.coreshop.messenger` is never defined. The messenger item is part of the `coreshop.coreshop` menu (`Resources/config/services/menu.yml`, `MenuBundle/DependencyInjection/CompilerPass/MenuBuilderPass.php:80`), which `MenuBundle/Resources/public/pimcore/js/menu.js` already creates. The `pimcoreReady` listener is dead (and would throw if `coreshop.menu.coreshop` were undefined).
  - `Event/FailedMessageDetailsEvent.php:1-4`: missing licence header and blank line after `<?php`, unlike every other file.
  - `Exception/{FailureReceiverDoesNotExistException,ReceiverNotListableException}.php`: empty multi-line constructors with odd indentation; a plain `parent::__construct('…')` call in a one-line constructor, or a `const MESSAGE`, reads better.

### I-09 — Compiler passes: do not read positional arguments of Symfony services
- **Where:** `DependencyInjection/CompilerPass/ReceiverPass.php:37-46`, `DependencyInjection/CompilerPass/FailureReceiverPass.php:33-62`, `CoreShopMessengerBundle.php:43-44`.
- **Idea:**
  - `getArgument(4)` of `console.command.messenger_consume_messages` and `getArgument(0)` of `messenger.failure.send_failed_message_to_failure_transport_listener` are internal details of FrameworkBundle; a new constructor argument in a minor release silently changes what this bundle reads (that is how `ConsumeMessagesCommand` gained `$rateLimiterLocator` and `$signals`). Collect the receiver names from the `messenger.receiver` tags directly (alias = name, the same way `MessengerPass.php:302-319` does), and take the failure transports from `framework.messenger.transports.*.failure_transport` via the `messenger.receiver` tag attributes or a parameter that the extension sets.
  - Merge the two passes; both need the same receiver list.
  - Throw a clear `LogicException` (or log) when the expected services are missing, instead of quietly replacing everything with an empty `Container` (TODO-2 **B-13**).
  - The `@inheritdoc` docblock (`FailureReceiverPass.php:28-30`) is redundant.

### I-10 — Services: `final`, `readonly`, precise types
- **Where:** `Messenger/{ReceiversRepository,FailureReceiversRepository}.php` (not `final`), all `Messenger/*` constructors, `Messenger/FailureReceiversRepositoryInterface.php:25`, `Messenger/ReceiversRepositoryInterface.php:25-33`, `Resources/config/services.yml`.
- **Idea:** make all services `final` with `private readonly` promoted properties. Add the missing return type `getReceiversWithFailureReceivers(): array` and `@return list<string>`. Use `array<string, ReceiverInterface>` in the `ReceiversRepositoryInterface` docblocks (the keys are the receiver names). Turn `_defaults` to `autowire: true` and drop the hand-written argument lists where possible; keep the explicit `''` placeholders only where the passes replace them.

## Admin UI

### I-11 — `list.js`: confirmations, encoding, error handling
- **Where:** `Resources/public/pimcore/js/list.js`.
- **Idea:**
  - Delete has no confirmation (`:327-346`); a misclick removes a failed message for good. Use `Ext.MessageBox.confirm()`. Retry the same, at least for notification messages (they send e-mails).
  - Encode all values that come from messages: `renderer: Ext.util.Format.htmlEncode` for `class` / `error` columns, `htmlEncode` for the windows (TODO-1 **C-01**).
  - Add `failure` callbacks to all `Ext.Ajax.request` calls and to the stores (`listeners: {exception: …}`), see TODO-2 **B-04**, **B-08**.
  - Allow bulk retry / delete (checkbox selection model); one request per message is fine, but clicking 200 failed index messages one by one is not.
  - The second action icon in the failed grid uses `tooltip: t('coreshop_messenger_info')` for the error window (`:308-310`); use a separate `coreshop_messenger_error` tooltip.

## Persistence / packaging / tests / docs

### I-12 — Dev tooling in `composer.json`
- **Where:** `composer.json:32-36,42-49`.
- **Idea:** `phpstan/phpstan` `^1.10` and the 1.x extensions are outdated (PHPStan 2 is current and needed for PHP 8.4 syntax support in all cases). `minimum-stability: dev` + the `path` repository `../../*/*` only make sense inside the monorepo; they are ignored for consumers, but they make standalone CI resolve dev versions. Align with the root `composer.json`.

### I-13 — Add tests
- **Where:** no tests exist for this bundle (`tests/`, `src/CoreShop/Behat` have none).
- **Idea:**
  - Unit: `FailureListener` with Symfony's retry and failure-transport listeners in one `EventDispatcher` (TODO-2 **B-01**, TODO-1 **C-02**); `FailedMessageRepository` with an envelope without `RedeliveryStamp` (TODO-2 **B-06**); `FailedMessageRetryer` keeps the original transport (TODO-2 **B-09**); escaping (TODO-1 **C-01**).
  - Compiler passes against a real `FrameworkBundle` container with two transports and a failure transport, so a Symfony upgrade that moves the arguments (**I-09**) fails CI.
  - Functional: the controllers return 403 without `coreshop_permission_messenger`, 404 for unknown receivers, `success: false` for unknown ids (TODO-2 **B-04**, **B-08**).

### I-14 — Migration: description, `down()`, installer
- **Where:** `Migrations/Version20240421093216.php`.
- **Idea:** `getDescription()` returns `''`, and `down()` is empty. Add "Add coreshop_permission_messenger permission" and a `down()` that deletes the definition (or mark it irreversible with `$this->throwIrreversibleMigrationException()`). The migration uses Pimcore models (`Definition::getByKey()`, `save()`) instead of `$this->addSql()`, so it runs outside the migration's transaction; with `isTransactional(): false` that is explicit. The permission is part of the classic admin (**I-01**).

### I-15 — Documentation
- **Where:** `docs/03_Bundles/Messenger_Bundle.md`, `README.md`.
- **Idea:** the docs only show the Composer and kernel steps (with the old `AppKernel.php` path). Document: the `coreshop_permission_messenger` permission, that only listable transports (Doctrine) show messages, the failure-transport requirement for the "Failed Messages" tab, the `coreshop.bus`, the two details events (**I-04**), what the failure log contains and where it goes (TODO-1 **C-02**), and that listing a failure transport can remove undecodable messages (TODO-2 **B-05**). Fix the typo "It is build" in `README.md:9`.
