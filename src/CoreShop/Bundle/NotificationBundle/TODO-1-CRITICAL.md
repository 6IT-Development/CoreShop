# NotificationBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (`Attribute/*`, `Controller/NotificationRuleController.php`, `CoreShopNotificationBundle.php`, `DependencyInjection/{Configuration,CoreShopNotificationExtension}.php`, `DependencyInjection/Compiler/*`, `Doctrine/ORM/NotificationRuleRepository.php`, `EventListener/*`, `Events.php`, `Form/Type/**`, `Processor/EventedRuleProcessor.php`), `composer.json`, and all of `Resources/config/**` (`services*.yml`, `pimcore/{config,admin,messenger,routing}.yml`, `doctrine/model/NotificationRule.orm.xml`, `serializer/Model.NotificationRule.yml`, `validation/NotificationRule.yml`). Also checked: the code and config that depend on it:
`Component/Notification/**` (model, message, handler, processors, `MailActionProcessor`, `AbstractConditionChecker`), `Component/Registry/{Autoconfiguration,RegisterRegistryTypePass,ServiceRegistry}.php`, `RuleBundle/Form/Type/**`, `RuleBundle/Doctrine/ORM/RuleRepository.php`, `Component/Rule/{Model/RuleTrait,Condition/RuleConditionsValidationProcessor,Condition/RuleValidationProcessor}.php`, `ResourceBundle/Controller/{AdminController,ResourceController}.php`, `ResourceBundle/Routing/ResourceLoader.php`, `MessengerBundle/{Stamp/PimcoreObjectStamp,EventListener/FailureListener}.php`, `CoreBundle/Resources/config/services/notification.yml`, `CoreBundle/EventListener/NotificationRules/*.php`, `Component/Core/Notification/Rule/Action/Order/{OrderMail,StoreMail}ActionProcessor.php`, `Resources/public/pimcore/js/rule/{panel,item}.js` (only the request contract of the `sort` / `getConfig` endpoints), `docs/03_Development/09_Notification_Rules/03_Custom_Types.md`.

Reference for API checks: `../pimcore2teszt10211524/vendor/` (`symfony/*` v7.4.21, `doctrine/orm` 2.20.13, `doctrine/collections` 2.6.0, `doctrine/doctrine-bundle` 2.19.1, `six-it/pimcore-source`). The vendored `six-it/core-shop` copy of this bundle is identical to the working tree (`diff -rq` is empty).

"Critical" here means: a security issue, money charged or stored wrongly, data loss or data written wrongly, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** live outside this bundle, but they come from how it is used, and they should be fixed together with it.

**Symfony 7.4 / PHP 8.4 compatibility:** no blockers. `php -l` (PHP 8.4.27) passes on all 22 PHP files of the bundle and the 14 of `Component/Notification`. There are no implicit nullable parameters, no dynamic properties and no removed functions or Symfony APIs. `Container::underscore()`, `registerAttributeForAutoconfiguration()`, `GenericEvent`, `WorkerMessageReceivedEvent::addStamps()`, `Criteria::where()` and `Document::getById(int|string)` exist with the signatures used. The `@Doctrine\Persistence\ConnectionRegistry` argument in `services/messenger.yml` only resolves because Pimcore defines that alias (`six-it/pimcore-source/bundles/CoreBundle/config/aliases.yaml:9` → `@doctrine`); see TODO-3 **I-12**. The only deprecation path is `strrchr(null, …)` in the compiler pass (TODO-2 **B-11**).

**Overlap with other TODOs:** there are no TODOs yet for `Component/Notification`, `RuleBundle` or `MessengerBundle`. Findings there that come from this bundle's usage are listed here as **(dependent)**. Locale-related items already covered by `Component/Locale/TODO-2-BUGS.md` (missing / `null` `_locale` in notification params, `OrderMailActionProcessor` "Language is not set") are not repeated; TODO-2 **B-02** is a different cause (the listener does not pass the key at all).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Container / registration

### C-01 — Attribute-based registration (`#[AsNotificationRuleActionProcessor]`, `#[AsNotificationRuleConditionChecker]`) breaks container compilation for the whole application
- **Where:** `Attribute/AsNotificationRuleActionProcessor.php:21-45`, `Attribute/AsNotificationRuleConditionChecker.php:21-45`, `DependencyInjection/CoreShopNotificationExtension.php:49-63`, `DependencyInjection/Compiler/AbstractNotificationRulePass.php:61-67,94-97`. **(dependent)** `Component/Registry/Autoconfiguration.php:57-72`.
- **Problem:**
  - `Autoconfiguration::getProperties()` builds the tag attributes from the attribute's getters: `getType()` → `type`, `getNotificationType()` → `notificationType`, `getFormType()` → `formType` (camelCase).
  - The compiler pass reads the dashed keys that the YAML tags use: `notification-type` (`:65`) and `form-type` (`:94`). `notificationType` is never found, so the pass throws `InvalidArgumentException: Tagged Condition `…` needs to have `notification-type`` attribute.` (`:66`).
  - The exception is thrown from `ContainerBuilder::compile()`. Once **one** service in the project uses either attribute, `cache:clear`, every console command and every HTTP request fail. The attributes are public API (they are registered with `autoconfigureWithAttributes: true`), so using them as documented takes the shop down.
  - Even if `notificationType` were matched, `formType` would still be ignored silently (no config form in the admin UI).
- **Reproduced:** a minimal `ContainerBuilder` with the bundle's registries, `Autoconfiguration::registerForAutoConfiguration(…, AsNotificationRuleActionProcessor::class, true)`, the `NotificationRuleActionPass`, and one autoconfigured class with `#[AsNotificationRuleActionProcessor(type: 'custom', notificationType: 'order', formType: 'X')]`. `compile()` throws the exception above. The resulting tag was `['type' => 'custom', 'notificationType' => 'order', 'formType' => 'X']`.
- **Fix:**
  - Make the pass accept both spellings (`$tag['notification-type'] ?? $tag['notificationType'] ?? null`, same for `form-type` / `formType`), or normalise the keys in the attribute configurator. Do **not** only change `Autoconfiguration::getProperties()` to emit dashed keys: `RegisterRegistryTypePass` (`Component/Registry/RegisterRegistryTypePass.php:58`) and every other CoreShop attribute share it, and they read `form-type` as well. That means `formType` is silently dropped for **every** CoreShop `#[As…]` attribute. Fix it centrally and check the other passes in the same change.
  - Throw a clear error when `notificationType` is `null` (it is optional in the attribute constructor, but mandatory for the pass), and make the attributes repeatable (TODO-2 **B-09**).
  - Add a compiler-pass test with an attributed service (TODO-3 **I-20**).

## Security

### C-02 — `sortAction` and `getConfigAction` skip the permission check; any Pimcore backend user can reorder notification rules
- **Where:** `Controller/NotificationRuleController.php:30-85` (`getConfigAction`), `:87-165` (`sortAction`). Routes: `Resources/config/pimcore/routing.yml:6-15`. Compare `ResourceBundle/Controller/ResourceController.php:59-78` (`isGrantedOr403()`), which `get`, `save`, `add`, `clone`, `delete` and `folderConfiguration` call.
- **Problem:**
  - The resource is configured with `permission: notification` (`DependencyInjection/Configuration.php:56`), so the generic actions require `coreshop_permission_notification`. The two custom actions do not call `$this->isGrantedOr403()`.
  - Any logged-in backend user (e.g. an editor without CoreShop permissions) can `POST /admin/coreshop/…/sort` and rewrite the `sort` column of every rule (combined with TODO-2 **B-03**, the values become garbage), and read the full list of notification types, condition and action identifiers through `get-config`.
  - Impact is limited: the routes are behind the Pimcore admin firewall and its CSRF token, and `sort` does not influence execution today (TODO-2 **B-04**). But it is a missing authorization check on a write endpoint, and it stops being harmless the moment B-04 is fixed.
  - **(dependent)** `ResourceController::listAction()` (`ResourceBundle/Controller/ResourceController.php:80-89`) also has no permission check, so the full rule list (names, types, sort) is readable by every backend user. That is a ResourceBundle issue for all resources; it is listed here so it is fixed with this bundle.
- **Fix:** call `$this->isGrantedOr403();` as the first statement of both actions (and of `listAction` in ResourceBundle). Add a functional test that a user without `coreshop_permission_notification` gets 403 on `sort`.

---

No other critical items were found. The candidates that were checked and rated as bugs instead:
- Duplicate customer e-mails on Messenger retries (TODO-2 **B-01**): wrong behaviour that customers see, but no data loss, no money and no outage.
- `mail` / `storeMail` actions that can never succeed for some rule types (TODO-2 **B-02**): the rule fails in the worker only; the shop keeps running.
- Corrupted `sort` values after drag & drop (TODO-2 **B-03**): data is written wrongly, but `sort` is display-only today (TODO-2 **B-04**). Re-rate B-03 as critical if B-04 is fixed first.
