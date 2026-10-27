# MessengerBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (`CoreShopMessengerBundle.php`, `Controller/{ListMessagesController,MessageController}.php`, `DependencyInjection/{Configuration,CoreShopMessengerExtension}.php`, `DependencyInjection/CompilerPass/{ReceiverPass,FailureReceiverPass}.php`, `Event/*`, `EventListener/FailureListener.php`, `Exception/*`, `Menu/MessengerMenuBuilder.php`, `Messenger/*`, `Migrations/Version20240421093216.php`, `Stamp/*`), `composer.json`, all of `Resources/config/**` (`services.yml`, `services/menu.yml`, `pimcore/{config,admin,routing}.yml`), `Resources/public/pimcore/js/{list,resource,resource_standalone}.js` and `Resources/translations/*`. Also checked: the code and config that depend on it:
`NotificationBundle/EventListener/MessengerListener.php`, `NotificationBundle/Resources/config/{services.yml,services/messenger.yml,pimcore/messenger.yml}`, `IndexBundle/Resources/config/pimcore/messenger.yml`, `VariantBundle/Resources/config/pimcore/messenger.yml`, `CoreBundle/EventListener/NotificationRules/*.php` (only which values end up in `NotificationMessage` params), `CoreBundle/Menu/MainMenuBuilder.php:408`, `MenuBundle/**` (menu naming for the standalone JS), `ResourceBundle/CoreShopResourceBundle.php:74`, `PimcoreBundle/DependencyInjection/Extension/AbstractPimcoreExtension.php`.

Reference for API checks: `../pimcore2teszt10211524/vendor/` (`symfony/*` v7.4.21, incl. `symfony/messenger`, `symfony/doctrine-messenger`, `symfony/framework-bundle`; `six-it/pimcore-source` incl. `bundles/ApplicationLoggerBundle`; `six-it/admin-ui-classic-bundle`). The vendored `six-it/core-shop` copy of this bundle differs from the working tree only in `Resources/public/pimcore/js/list.js` (auto-refresh) and `Resources/translations/admin.{de,en}.yml`; the PHP is identical.

"Critical" here means: a security issue, money charged or stored wrongly, data loss or data written wrongly, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** live outside this bundle, but they come from how it is used, and they should be fixed together with it. Items marked **(plausible)** follow from the code, but were not reproduced against a running shop.

**Symfony 7.4 / PHP 8.4 compatibility:** no blockers. `php -l` (PHP 8.4.27) passes on all 32 PHP files. There are no implicit nullable parameters, no dynamic properties and no removed functions or Symfony APIs. `WorkerMessageFailedEvent::willRetry()`, `ErrorDetailsStamp::getFlattenException()`, `RedeliveryStamp::getRedeliveredAt()`, `ListableReceiverInterface::{all,find}()`, `MessageCountAwareInterface`, `AbstractWorkerMessageEvent` and `ServiceProviderInterface` exist with the signatures used. The two compiler passes read positional arguments of Symfony services; for 7.4 they are still correct (`console.command.messenger_consume_messages` argument 4 = consumable receiver names, `messenger.failure.send_failed_message_to_failure_transport_listener` argument 0 = failure transports by transport name; `symfony/framework-bundle/Resources/config/{console,messenger}.php`, `symfony/messenger/DependencyInjection/MessengerPass.php:346`, `FrameworkExtension.php:2775-2777`). They break silently when Symfony reorders them (TODO-3 **I-09**).

**Overlap with other TODOs:** `NotificationBundle/TODO-2-BUGS.md` **B-05** mentions the 6 `alert` log entries per failed notification; the cause (listener priority) is TODO-2 **B-01** here. Nothing else overlaps.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security

### C-01 — Stored XSS in the admin: message contents and exception messages are rendered as raw HTML, and storefront visitors control parts of them
- **Where:** `Messenger/MessageRepository.php:54`, `Messenger/FailedMessageRepository.php:59-60` (`'<pre>' . print_r($envelope->getMessage(), true) . '</pre>'`, exception message as-is), `Resources/public/pimcore/js/list.js:304` (`html: record.data.serialized`, failed), `:323` (`html: record.data.error`), `:474` (`html: record.data.serialized`, pending). **(dependent)** `CoreBundle/EventListener/NotificationRules/CustomerListener.php:147-151`, `OrderCommentsListener.php:46-49`, `OrderWorkflowListener.php:51-52` (put `firstname`, `email`, `comment` into the `NotificationMessage` params).
- **Problem:**
  - `print_r()` output is not escaped on the server, and the ExtJS windows set it as `html`. The same is true for the exception message of a failed message.
  - The notification messages contain values that a storefront visitor chooses: the customer's first name and e-mail from registration, order comments. A visitor who registers with a first name like `<img src=x onerror=…>` creates a `NotificationMessage` in `coreshop_notification`. When an admin opens **Messenger → Pending Messages → Details** (or the message ends up in `coreshop_notification_failed`, where it stays until someone deletes it), the script runs in the admin session with that user's permissions (create admin users, change prices, export customers).
  - The **Error** column of the failed grid (`list.js:281-283`, no `renderer`) is also raw HTML: ExtJS grid columns do not encode values by default, so a payload in the exception message runs as soon as the admin selects the receiver, without opening any window.
  - Exception messages very often quote input too (`Invalid e-mail "…"`, Twig errors with template content, SQL errors with values), so the **Error** window has the same problem independently of the message class.
  - Every user with `coreshop_permission_messenger` is exposed; the permission only limits who sees the payload, not who can plant it.
- **(plausible):** not run in a browser. The data path (`CustomerListener` → `RulesProcessor` → Doctrine transport → `all()` → `print_r` → `html:`) is all in the code; whether the storefront's registration form accepts `<` / `>` in `firstname` depends on the project's validation, which CoreShop does not restrict by default.
- **Fix:**
  - Escape on the server: `htmlspecialchars(print_r(…, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` for `serialized` and `htmlspecialchars()` for `error`. Better: return plain data and let the client render text (`Ext.util.Format.htmlEncode()` in `list.js`, or a `textarea` / `displayfield` with `htmlEncode: true`). Keep the `<pre>` wrapper on the client side.
  - Listeners of `coreshop.messenger.(failed_)message_details` can call `setSerialized()` with their own HTML; document that the value is HTML and must be escaped, or rename to `setSerializedHtml()` and escape the default (TODO-3 **I-04**).
  - Add a test with a message containing `<script>` and assert the JSON response contains `&lt;script&gt;`.

## Worker / message handling

### C-02 — `FailureListener` runs before the retry listener and does not catch logger errors; when the log write fails, the failed message is neither retried, nor moved to the failure transport, nor rejected, and the worker dies
- **Where:** `EventListener/FailureListener.php:40` (priority `110`), `:93-100` (`$this->logger->alert(…)`), `Resources/config/services.yml:7-18` (logger = Pimcore `ApplicationLogger` with the `ApplicationLoggerDb` writer). Symfony: `symfony/messenger/Worker.php:230-238`, `EventListener/SendFailedMessageForRetryListener.php:113` (priority `100`), `EventListener/SendFailedMessageToFailureTransportListener.php:98` (priority `-100`). **(dependent)** `ResourceBundle/CoreShopResourceBundle.php:74` (registers `PimcoreApplicationLoggerBundle`, but nothing installs it).
- **Problem:**
  - `Worker` dispatches `WorkerMessageFailedEvent` and then rejects the message (`Worker.php:232-237`). The retry (priority 100) and the move to the failure transport (priority -100) are listeners of the same event. An exception from a listener with a higher priority stops the dispatch and leaves `Worker::handleMessage()`: no retry is scheduled, nothing is written to `coreshop_*_failed`, `reject()` is not called and `messenger:consume` exits with the exception.
  - `FailureListener` (priority 110) writes synchronously with `ApplicationLoggerDb` (`Doctrine\DBAL\Connection::insert('application_logs', …)`, `six-it/pimcore-source/bundles/ApplicationLoggerBundle/src/Handler/ApplicationLoggerDb.php:42-54`) and does not catch anything. It throws when:
    - the `application_logs` table does not exist. `PimcoreApplicationLoggerBundle` is an installable bundle (`Installer.php:49`). CoreShop registers it from `ResourceBundle`, but neither CoreShop's installer nor this bundle installs it.
    - the database connection is gone. That is one of the most common reasons for a handler to fail in the first place ("MySQL server has gone away" after a long-running worker), and the logger uses the same Pimcore default connection.
    - the project removed Pimcore's `SET sql_mode = ''` init command (`six-it/pimcore-source/bundles/CoreBundle/config/pimcore/default.yaml:148`) and the 190-character `source` column overflows (TODO-2 **B-03**).
  - With the Doctrine transport, the message keeps its `delivered_at` and is delivered again after `redeliver_timeout` (1 h by default) with the same `RedeliveryStamp` count. If the handler and the log write fail again, it loops for ever. The retry strategy and the failure transport never apply, and every failure on `coreshop.bus` (all notification rules) restarts the worker.
- **(plausible):** the dispatch order and the missing `reject()` are confirmed in the Symfony 7.4 code above; the end-to-end loop was not reproduced against a running worker.
- **Fix:**
  - Wrap the whole listener body in `try { … } catch (\Throwable) {}` (a failing log must never change message handling), and move it below the retry listener: priority `0` (or anything between `100` and `-100`). That also fixes TODO-2 **B-01**.
  - Log through a Monolog channel (`monolog.logger` tag, channel `coreshop_messenger`) instead of hard-wiring `ApplicationLogger` + `ApplicationLoggerDb` in `services.yml`. Projects that want the entries in the Pimcore application log can route that channel to Pimcore's `ApplicationLoggerDb` handler in their Monolog config, and Monolog handler errors stay outside the event dispatch.
  - Add a test: a logger that throws + a failing handler → the message is still sent for retry / to the failure transport.

---

No other critical items were found. The candidates that were checked and rated as bugs instead:
- The admin list permanently deletes messages that cannot be decoded (TODO-2 **B-05**): data is removed, but only messages the worker would also reject, and only after a deploy that removed or renamed a message class.
- Retry and delete always report success (TODO-2 **B-04**): wrong feedback, but no data is changed wrongly.
- The bundle cannot be used without `CoreShopResourceBundle` (TODO-2 **B-12**): container compilation fails, but only for the standalone installation (`docs/03_Bundles/Messenger_Bundle.md`) in a project that has not enabled `PimcoreApplicationLoggerBundle` itself.
