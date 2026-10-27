# MessengerBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this bundle, but they come from how it is used, and they should be fixed together with it.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items are roughly ordered by impact. Items marked **(plausible)** follow from the code, but were not reproduced against a running shop.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Failure logging (`FailureListener`)

### B-01 — `willRetry()` is always `false` when `FailureListener` runs, so every failed attempt is logged as `alert`, including the ones that are retried
- **Where:** `EventListener/FailureListener.php:40` (priority `110`), `:46-48`. Symfony: `symfony/messenger/EventListener/SendFailedMessageForRetryListener.php:63,113` (`setForRetry()` at priority `100`).
- **Problem:**
  - The retry flag is set by `SendFailedMessageForRetryListener`, which runs **after** this listener (110 > 100). `$event->willRetry()` is therefore never `true` here, and the early return is dead code.
  - Every attempt of a failing message on `coreshop.bus` writes an `alert` to the application log: with the notification retry strategy (`max_retries: 5`, `NotificationBundle/Resources/config/pimcore/messenger.yml:7-10`) that is 6 alerts per message, 5 of them for an error Messenger is still handling. Log-mail alerting on `alert` level (Pimcore `LogMailMaintenanceTask`) fires for each of them.
  - The `RedeliveryStamp` the listener reports is the one of the **previous** attempt, because the retry listener has not added the new one yet.
- **Fix:** register the listener between the retry listener and the failure-transport listener (priority `0`), together with TODO-1 **C-02**. Then `willRetry()` is correct and only the final failure is logged. Add a test that dispatches the event through a real `EventDispatcher` with both Symfony listeners registered.

### B-02 — `FailureListener` ignores CoreShop's own index and variant messages
- **Where:** `EventListener/FailureListener.php:55-59` (`getBusName() !== 'coreshop.bus'` → return). **(dependent)** `IndexBundle/Resources/config/services/listeners.yml:19,26` and `VariantBundle/Resources/config/services.yml:58` dispatch on `@messenger.default_bus`; their transports `coreshop_index` / `coreshop_variant` have their own failure transports (`IndexBundle/Resources/config/pimcore/messenger.yml:6`, `VariantBundle/Resources/config/pimcore/messenger.yml:6`).
- **Problem:**
  - Index and variant messages carry the `BusNameStamp` of the default bus, so their final failures are never written to the application log. Only notification messages (`coreshop.bus`) are logged. The Messenger panel lists all four failure transports, so the admin sees failed index messages that were never reported.
  - Messages **without** a `BusNameStamp` (sent directly to a transport, or by third-party code) are logged, although the filter is meant to limit logging to CoreShop's bus. The condition is inverted for that case.
- **Fix:** decide on the rule and make it explicit. Either filter by **transport** (`$event->getReceiverName()` in a configurable list, default: every `coreshop_*` transport) or by message namespace. Make the list a bundle config option instead of a hard-coded string (TODO-3 **I-06**).

### B-03 — The stack trace is written to a `VARCHAR(190)` column and cut off after about two lines
- **Where:** `EventListener/FailureListener.php:99` (`'source' => $errorStamp->getFlattenException()?->getTraceAsString()`). Pimcore: `ApplicationLogger.php:147-149` (a given `source` is used as is), `Handler/ApplicationLoggerDb.php:53`, `Installer.php:58` (`` `source` varchar(190) ``).
- **Problem:** `source` is meant for "class::method" of the log call. The trace is usually several kilobytes; Pimcore's connection runs with `sql_mode = ''`, so MySQL silently truncates it to 190 characters, and the application log shows a useless fragment. With a strict `sql_mode` the insert fails instead (TODO-1 **C-02**).
- **Fix:** do not pass `source`. Write the trace as a `fileObject` (`ApplicationLogger` stores it as a file and links it, `ApplicationLogger.php:115-127`), or as part of `message` (`TEXT`). With a Monolog channel (TODO-1 **C-02**), pass the `FlattenException` as `exception` in the context and let the handler format it.

## Admin endpoints

### B-04 — Retry and delete always answer `success: true`; errors are swallowed and the UI never shows them
- **Where:** `Controller/MessageController.php:36-43` (delete), `:52-59` (retry): `catch (\Exception $exception) { //Ignore }`. `Resources/public/pimcore/js/list.js:335-345,357-367` (no `failure` callback).
- **Problem:**
  - Message not found (already retried in another tab, wrong id, see **B-11**), receiver without failure transport, transport not listable, broker down, `dispatch()` failing because a synchronous handler throws (**B-09**), or `reject()` failing: in every case the admin gets the same "success" and the grid simply reloads. The admin cannot tell whether a retry happened.
  - An unknown `receiverName` makes `FailureReceiversRepository::getFailureReceiver()` throw `ServiceNotFoundException`, which is also swallowed.
  - `\Exception` does not catch `\Error` (e.g. a `TypeError` from **B-06**), so those still return a 500, and the JS has no failure handler for that either: the grid keeps its loading mask for ever (`grid.setLoading(false)` is only called in `success`).
- **Fix:** let the controllers return `success: false` with the message (HTTP 404 for "not found", 400 for an unknown receiver, 500 otherwise), log the exception, and add a `failure` callback in `list.js` that removes the loading mask and calls `pimcore.helpers.showNotification(t('error'), …, 'error')`.

### B-05 — Viewing the lists deletes messages that cannot be decoded, and one such message breaks the whole list
- **Where:** `Messenger/MessageRepository.php:44-50`, `Messenger/FailedMessageRepository.php:46-49` (`$receiver->all($limit)` without `try`), `Controller/ListMessagesController.php:91,108`, `Resources/public/pimcore/js/list.js:51-57` (auto refresh every 5/10/30 s). Symfony: `symfony/doctrine-messenger/Transport/DoctrineReceiver.php:108-119,148-159`.
- **Problem:**
  - For the Doctrine transport (all CoreShop transports), `all()` decodes each row, and when decoding fails (`MessageDecodingFailedException`: message class renamed or removed in a deploy, serializer changed, corrupt body) it **deletes the row** (`$this->connection->reject($data['id'])`) and rethrows.
  - The exception is not caught: the list request returns 500 and the grid stays empty. Each reload (or each auto-refresh tick) deletes the next undecodable message and fails again, until none are left.
  - For pending messages this only does early what the worker would do. For the failure transport, which no worker consumes, opening the panel is what deletes them – including messages the admin opened the panel to inspect, and that could still be retried after restoring the class.
- **(plausible):** the delete-on-decode-failure is Symfony's behaviour (`messenger:failed:show` has it too); the effect on the panel follows from the missing `try`, not reproduced.
- **Fix:** catch `MessageDecodingFailedException` / `TransportException` around the iteration and return what was decoded plus an error row ("1 message could not be decoded and was removed by the transport: …"), so the admin at least sees it. Log the removed body (`$exception->getMessage()` contains the class). Mention in the UI that listing a failure transport is not read-only.

### B-06 — `FailedMessageDetails` requires a string `failedAt`, but gets `null` for messages without `RedeliveryStamp`; one such message makes the failed list return 500
- **Where:** `Messenger/FailedMessageRepository.php:58` (`$lastRedeliveryStamp?->getRedeliveredAt()->format(…)` → `?string`), `Messenger/FailedMessageDetails.php:28` (`private string $failedAt`).
- **Problem:** passing `null` to a non-nullable `string` parameter is a `TypeError`, independent of `strict_types`. Symfony's failure listener adds `new RedeliveryStamp(0)` (`SendFailedMessageToFailureTransportListener.php:64`), so messages moved there by the worker have one. Messages put into the failure transport another way (`messenger:failed:retry` failing again on Symfony < 5.2 data, a custom `failure_transport` sender, messages copied between transports, tests) do not, and then the whole list fails (and **B-04**: retry/delete from the UI are impossible for every other message of that receiver). **(plausible)**
- **Fix:** make it `?string $failedAt` (and `getFailedAt(): ?string`), or fall back to the `SentToFailureTransportStamp` / `DelayStamp` / `''`. Same for `error`: `?string` in the constructor, but the repository already turns `null` into `''`.

### B-07 — The "Failed At" column is always empty, and it would drop the time if it were filled
- **Where:** `Messenger/FailedMessageDetails.php:46,69-78` (`#[SerializedName('failed_at')]`, but `jsonSerialize()` returns `failedAt`), `Resources/public/pimcore/js/list.js:254` (store field `failedAt`), `:276-279` (`dataIndex: 'failed_at'`, `formatter: 'date("m/d/Y")'`).
- **Problem:**
  - `AdminAbstractController` extends Symfony's `AbstractController`, so `$this->json()` uses the `serializer` service (`AbstractController.php:150-157`). `JsonSerializableNormalizer` (priority `-950`) runs before `ObjectNormalizer` (`-1000`) (`framework-bundle/Resources/config/serializer.php:105-107`), so `jsonSerialize()` decides the keys and the `SerializedName` attributes are never used. The response has `failedAt`, the column reads `failed_at`.
  - The value is `'Y-m-d H:i:s'`; `date("m/d/Y")` throws the time away (the important part for "when did it fail") and depends on the browser's non-ISO date parsing (`Invalid Date` in Safari for `2026-10-27 12:00:00`).
- **Fix:** use `dataIndex: 'failedAt'`, send an ISO 8601 / ATOM string, and format with `date("Y-m-d H:i:s")` or `pimcore.helpers` date rendering. Remove the unused `SerializedName` attributes (TODO-3 **I-08**).

### B-08 — An unknown, non-listable or unreachable receiver makes the endpoints return 500; one broken transport empties the whole chart
- **Where:** `Controller/ListMessagesController.php:35-63` (count / listable receivers), `:79-110` (lists). `Messenger/ReceiversRepository.php:37-73`, `Messenger/FailureReceiversRepository.php:45-48`.
- **Problem:**
  - `listMessagesAction` with a receiver that does not exist → `ReceiverDoesNotExistException`; with a non-listable one (AMQP, Redis, SQS, sync) → `ReceiverNotListableException`. `listFailedMessagesAction` with an unknown name → `ServiceNotFoundException` from the `ServiceLocator`. All three are uncaught `RuntimeException`s → HTTP 500 instead of 404 / 400.
  - `listReceiverMessageCountAction` instantiates **every** consumable receiver and calls `getMessageCount()` on each. A single receiver whose broker is down (AMQP host unreachable, wrong Redis DSN in one environment, a `TransportException`) fails the whole request, so the chart is empty even for the Doctrine transports that work. With auto-refresh (**B-05**) this repeats every few seconds, each time with a connection timeout.
  - The `is_string($receiverName)` checks (`:87-89,104-106`) are dead: the route requirement makes it a string, and an empty segment does not match the route at all.
- **Fix:** map the bundle's exceptions to 404/400 in the controllers. In the count / receivers endpoints, catch `\Throwable` per receiver and return `count: null, error: '…'` for that row.

## Retry

### B-09 — "Retry" re-dispatches the bare message through the current routing instead of returning it to its original transport
- **Where:** `Messenger/FailedMessageRetryer.php:58-65`, `Resources/config/services.yml` (`@messenger.routable_message_bus`).
- **Problem:**
  - The new envelope only keeps `BusNameStamp`; all other stamps are dropped, including `TransportNamesStamp`. The bus routes it by `framework.messenger.routing` **today**. A message that was dispatched to an explicit transport, or whose routing changed since, goes to a different transport, or – when its class has no routing – is handled **synchronously inside the admin HTTP request** (timeouts, admin request doing mailing / indexing work). If that synchronous handling throws, the exception is swallowed (**B-04**) and the admin sees "success".
  - Symfony's `SentToFailureTransportStamp::getOriginalReceiverName()` tells which transport the message came from; Symfony's own `messenger:failed:retry` uses it. The retryer ignores it.
  - `dispatch()` runs before `reject()`. If `reject()` fails (connection lost), the message is in the queue **and** still in the failure transport, so the next retry click sends it a second time (duplicate e-mails for notification messages).
  - `MessageRetriedByUserEvent` exists but is never dispatched, so nothing can react to a manual retry (TODO-3 **I-08**).
- **(plausible)** for the routing difference; CoreShop's own messages all have class routing, so the built-in ones go to the right transport today.
- **Fix:** send the envelope to the original transport's sender (`messenger.receiver_locator` / sender locator with `getOriginalReceiverName()`), keeping the original stamps minus the transport-specific ones (`withoutStampsOfType(NonSendableStampInterface::class)`, `ErrorDetailsStamp`, `RedeliveryStamp`, `SentToFailureTransportStamp`), plus `RetriedByUserStamp`. Fall back to the bus only when there is no original receiver. Dispatch `MessageRetriedByUserEvent`.

### B-10 — A failure transport shared by several transports shows all its messages under each of them
- **Where:** `Messenger/FailureReceiversRepository.php:32-48`, `Messenger/FailedMessageRepository.php:38-49`, `DependencyInjection/CompilerPass/FailureReceiverPass.php:50-62`.
- **Problem:** the receiver combo lists the **original** transports that have a failure transport, but `listFailedMessages()` lists every envelope of the failure transport, without looking at `SentToFailureTransportStamp::getOriginalReceiverName()`. With a global `framework.messenger.failure_transport` (common in projects), "coreshop_index", "coreshop_notification" and every project transport all show the same list. CoreShop's own transports each have a dedicated failure transport, so they are not affected. **(plausible)**
- **Fix:** filter by the original receiver name (and show it as a column), or list failure transports instead of original transports.

### B-11 — Message ids are cast to `int`
- **Where:** `Controller/MessageController.php:34,50` (`(int) $request->request->get('id')`), `Messenger/FailedMessageRejecterInterface.php:23`, `Messenger/FailedMessageRetryerInterface.php:23` (`int $id`).
- **Problem:** `ListableReceiverInterface::find(mixed $id)`. The Doctrine transport uses integers, but any other listable transport (e.g. a project transport with UUIDs or string ids) gets `0` or a truncated number: "not found", swallowed by **B-04**, and the admin thinks the retry worked. A missing `id` also becomes `0` instead of a 400. **(plausible)** for CoreShop's built-in Doctrine transports this works.
- **Fix:** accept `string|int` (`(string)` from the request, validated non-empty), and type the interfaces `string|int $id`.

## Container

### B-12 — The bundle cannot be installed without `CoreShopResourceBundle`: the logger service references the application-logger bundle, which the bundle does not register
- **Where:** `Resources/config/services.yml:7-12` (`@Pimcore\Bundle\ApplicationLoggerBundle\Handler\ApplicationLoggerDb`), `CoreShopMessengerBundle.php:33-37` (registers only `CoreShopPimcoreBundle` and `CoreShopMenuBundle`). **(dependent)** `ResourceBundle/CoreShopResourceBundle.php:74` (the only place that registers `PimcoreApplicationLoggerBundle`).
- **Problem:** `coreshop/messenger-bundle` is a separate Composer package (`composer.json:2`). Installed on its own (or with a CoreShop subset that does not contain `ResourceBundle`) and without the project enabling `PimcoreApplicationLoggerBundle`, the container fails to compile: `The service "coreshop.messenger.pimcore.messenger_logger" has a dependency on a non-existent service "Pimcore\Bundle\ApplicationLoggerBundle\Handler\ApplicationLoggerDb"`. This standalone installation is the one documented in `docs/03_Bundles/Messenger_Bundle.md`. **(plausible)** — not reproduced; the full CoreShop distribution contains `ResourceBundle`, and the Pimcore skeleton usually enables the application logger itself.
- **Fix:** switch to a Monolog channel (TODO-1 **C-02**), which removes the dependency. Otherwise register `PimcoreApplicationLoggerBundle` in `registerDependentBundles()`.

### B-13 — `ReceiverPass` replaces the receiver locator with a `Container`, which does not implement the constructor's `ServiceProviderInterface`
- **Where:** `DependencyInjection/CompilerPass/ReceiverPass.php:41-46`, `Messenger/ReceiversRepository.php:29` (`ServiceProviderInterface $receiverLocator`).
- **Problem:** when `console.command.messenger_consume_messages` does not exist, the pass injects `new Definition(Container::class)`. `Symfony\Component\DependencyInjection\Container` implements `ContainerInterface`, not `ServiceProviderInterface`, so instantiating `ReceiversRepository` (opening the panel) throws a `TypeError`. `FailureReceiverPass` does the same for `FailureReceiversRepository`, whose type (`Psr\Container\ContainerInterface`) does accept it. **(plausible)** — the consume command is only missing when `symfony/console` is not installed, which never happens with Pimcore.
- **Fix:** type the constructor `Psr\Container\ContainerInterface` (only `has()` / `get()` are used), or inject an empty `ServiceLocator` (`new Definition(ServiceLocator::class, [[]])`) in both passes.

## Packaging

### B-14 — `composer.json` does not declare packages the bundle uses directly
- **Where:** `composer.json:26-31`.
- **Problem:** the bundle uses `symfony/messenger` (all of `Messenger/*`, `Stamp/*`, `EventListener/*`), `symfony/event-dispatcher`, `symfony/http-foundation`, `symfony/http-kernel`, `symfony/dependency-injection`, `symfony/config`, `psr/log`, `doctrine/migrations` (`Migrations/Version20240421093216.php`) and the Pimcore application logger (**B-12**), but only declares `six-it/pimcore-source`, `six-it/admin-ui-classic-bundle`, `coreshop/pimcore-bundle` and `coreshop/menu-bundle`. It also has no `php` constraint (the root `composer.json` requires `~8.4.0`). Installed standalone or with `--prefer-lowest`, it can resolve versions without Symfony 7.4 / PHP 8.4 support. `symfony/serializer` is only imported for the unused `SerializedName` attribute (TODO-3 **I-08**).
- **Fix:** add `"php": ">=8.4"`, `symfony/{messenger,event-dispatcher,http-foundation,http-kernel,dependency-injection,config}: ^7.4`, `psr/log`, `doctrine/migrations`. The dev-tooling part (PHPStan 1.x, `minimum-stability`) is TODO-3 **I-12**.
