# PimcoreBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (33 files: `CoreShopPimcoreBundle.php`, `Events.php`, `Attribute/`, `Command/`, `Controller/Admin/`, `CoreExtension/`, `DataHub/**`, `DependencyInjection/**`, `Event/`, `EventListener/**`, `Loader/`, `Mail/`), `composer.json`, and all of `Resources/config/**` (`services.yml`, `services/{grid,dynamic_dropdown,pimcore,link_generator,print,data_hub}.y(a)ml`, `pimcore/{config,admin,routing}.yml`). Call paths were followed into `Component/Pimcore` (`Mail`, `ExpressionLanguage/PHPFunctionsProvider`, `DataObject/CompositeLinkGenerator`, `DataObject/Grid/*Interface`, `Slug/*`), `Component/Registry` (`Autoconfiguration`, `RegisterSimpleRegistryTypePass`, `PrioritizedCompositeServicePass`, `PrioritizedServiceRegistry`), `Component/Index/Interpreter/ExpressionInterpreter`, `ResourceBundle` (`validators.yml`, `pimcore/config.yml`), `IndexBundle/Resources/config/services.yml`, `CoreBundle` (`OrderMailNoteEventListener`, `listeners.yml`, `CoreShopOrder.json`), `OrderBundle` (`CoreShopOrderItemAttribute.json`) and the bundle's admin JS (`Resources/public/pimcore/js/**`, only to see what requests are sent).

Reference for API checks: `vendor/` (`symfony/*` 7.4.21, `six-it/pimcore-source` 2.1.2, `six-it/admin-ui-classic-bundle` 2.1.0, `doctrine/migrations` 3.9.8, `laminas/laminas-stdlib`) and the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml` (+ its `…Deprecations.log`). `pimcore/data-hub` is **not** installed in the test project, so `services/data_hub.yml` and `DataHub/**` were checked against the code only. This bundle is byte-identical to the vendored `six-it/core-shop` copy (`diff -r` is empty).

"Critical" here means: a security issue, data loss or data written wrongly, or an error that takes down every request, a whole area, or the install or upgrade path once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code against the vendored libraries or by reading the vendored library source or the compiled container.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers. `php -l` (PHP 8.4.27) passes on every PHP file in the bundle, every service definition resolves in the compiled container, and the container deprecation log has no entry for this bundle. There are no implicitly nullable parameters (PHP 8.4 deprecation). Forward-compatibility and modernisation items are in TODO-3 (**I-01** to **I-06**).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security

### C-01 — The dynamic-dropdown options endpoint calls any method named in the request on every object of a folder, over GET (no CSRF check) and without a permission check — (verified code path, plausible exploit)
- **Where:**
  - `Controller/Admin/DynamicDropdownController.php:129` takes `$source = (string) $request->query->get('methodName')` straight from the request.
  - `Controller/Admin/DynamicDropdownController.php:155` runs `$child->$source($currentLang)` / `$child->$source()` on every child of the requested folder that is an instance of the requested class. Nothing checks that `$source` is a getter, is in the class definition, or matches the field's configured `methodName`.
  - `Controller/Admin/DynamicDropdownController.php:130-139` takes `className` from the request too, and `:37-54` takes `folderName` from the request (any folder).
  - `Resources/config/pimcore/routing.yml:22-34`: both dynamic-dropdown routes accept `GET`.
  - `vendor/six-it/admin-ui-classic-bundle/src/EventListener/CsrfProtectionListener.php` returns early for `$request->isMethodCacheable()`, so **GET requests to admin routes are never CSRF-checked** (verified).
  - The controller has no `checkPermission()` / `isAllowed()` call; the only guard is "logged in to the admin" (firewall).
- **Impact:**
  - Any admin user, even one with no object permissions, can call `delete`, `save`, `unpublish`-style zero-argument methods on every object of a class in any folder, e.g. `GET /admin/coreshop/dynamic-dropdown/options?folderName=/&className=CoreShopProduct&methodName=delete&recursive=true`.
  - Because it is a GET without a CSRF token, the same URL works as a cross-site request against a logged-in admin (link, redirect, top-level navigation; session cookie `SameSite=Lax` still sends cookies on top-level GET).
  - Read side: every label from every object of any class in any folder is returned, ignoring workspace permissions (see TODO-2 **B-06**).
- **Fix:**
  - Stop taking `methodName`, `className` and `folderName` from the request. Send the class id and field name (`classId`, `fieldName`, plus object id for context) and read `folderName`, `className`, `methodName`, `recursive`, `sortBy`, `onlyPublished` from the field definition (`ClassDefinition::getById($classId)->getFieldDefinition($fieldName)`, also inside localized fields / bricks / field collections).
  - If request-supplied names must stay for the class editor preview, accept only `methodName` values that start with `get`, exist on the class, take no required parameters, and belong to a data field of the class definition (same list that `methodsAction` returns, see TODO-3 **I-12**).
  - Restrict both routes to `GET` but keep them read-only, check `$this->checkPermission('objects')` and `$folder->isAllowed('list')` per folder/child.
  - Same request-trust problem in `methodsAction` (`:93-124`): it only lists method names, so it is not dangerous by itself, but it lists everything starting with `get` (including `getDao`, `getClass`, `getChildren`, …).

### C-02 — `coreShopSerializedData` runs `unserialize()` on data posted by the admin editor (PHP object injection) — (verified code path, plausible exploit) (dependent)
- **Where:**
  - `CoreExtension/SerializedData.php:82-85`: `getDataFromEditmode()` calls `getDataFromResource()`.
  - `CoreExtension/SerializedData.php:72-75`: `getDataFromResource()` calls `unserialize($data)` for every string, without `allowed_classes`.
  - `vendor/six-it/admin-ui-classic-bundle/src/Controller/Admin/DataObject/DataObjectController.php:598-626` (`applyChanges`) calls `$fd->getDataFromEditmode($value, $object)` for **every** key in the posted `data` JSON that matches a field definition. `noteditable` is not checked server side.
  - The field type is used for `CoreShopOrder.paymentSettings` (`CoreBundle/Resources/install/pimcore/classes/CoreShopOrderBundle/CoreShopOrder.json:3155-3157`) and `CoreShopOrderItemAttribute.attributeValue` (`OrderBundle/Resources/install/pimcore/fieldcollections/CoreShopOrderItemAttribute.json:65`).
  - The JS tag (`Resources/public/pimcore/js/coreExtension/tags/coreShopSerializedData.js`) has no `getValue()`, so the normal UI does not send the field. A hand-made save request does.
- **Impact:** any admin user who may save an order object (or any object with an order-item-attribute field collection) can post `{"paymentSettings": "O:…"}` and get arbitrary objects instantiated. The vendor tree ships the usual gadget chains (Monolog, Guzzle, Symfony, Doctrine), which typically lead to file write or code execution. The same `unserialize()` also runs on strings read back from grid/version code paths (TODO-2 **B-08**).
- **Fix:**
  - `getDataFromEditmode()`: the field is read-only in the UI; ignore editor input and return the current value (`$object?->getObjectVar($this->getName())`, or the value passed in `$params['owner']` for field collections), or throw if input is sent.
  - `getDataFromResource()`: `unserialize($data, ['allowed_classes' => false])`, or an explicit allow-list if objects really are stored (check what Payum writes into `paymentSettings`; usually arrays/scalars).
  - Longer term store JSON (`json` column) instead of PHP `serialize()` (TODO-3 **I-10**). Needs a data migration for existing `paymentSettings` / `attributeValue` rows.

### C-03 — The shared expression language can run shell commands (`array_filter(…, "system")`) and hands out the service container — (verified) (dependent)
- **Where:**
  - `Resources/config/services.yml:38-54` builds `coreshop.expression_language` with `PHPFunctionsProvider` and `Symfony\Component\DependencyInjection\ExpressionLanguageProvider`; `DependencyInjection/Compiler/ExpressionLanguageServicePass.php:27-35` registers all tagged providers.
  - `Component/Pimcore/ExpressionLanguage/PHPFunctionsProvider.php` exposes `array_filter` via `ExpressionFunction::fromPHP()`. The callback parameter accepts any callable string.
  - Run against the vendored libraries: `$el->evaluate('array_filter(["echo PWNED"], "system")')` prints `PWNED` (verified).
  - `Component/Index/Interpreter/ExpressionInterpreter.php` evaluates the expression stored in the index column configuration (written in the admin index editor) with `container => service_container`. `ResourceBundle/Validator/Constraints/UniqueEntityValidator` (via `ResourceBundle/Resources/config/services/validators.yml:5-8`) gets the container too. With the container as a variable, `container.get("…")` returns any public service even without the DI provider; the DI provider adds `service()` / `parameter()`.
- **Impact:** any admin user who can edit an index (or any other place where expressions are entered) gets remote code execution on the server and access to every public service and every container parameter (DB credentials, secrets).
- **Fix:**
  - Remove `array_filter` from `PHPFunctionsProvider` or register a wrapper that has no callback argument (`fn (array $a) => array_filter($a)`); review the rest of the list for callable-string parameters (none other found).
  - Remove `coreshop.expression_language.provider.symfony_container` from the shared expression language (it only makes sense with a trusted, compiled expression). If some expression needs a service, expose that one service as a variable.
  - In `ExpressionInterpreter` / `UniqueEntityValidator` stop passing `container` as a variable (dependent).
  - Document that expressions are code and gate the index editor behind a dedicated permission.

### C-04 — `coreShopSerializedData` version preview prints raw `serialize()` output into a `|raw` Twig block (stored XSS in the admin) — (verified sink, plausible source)
- **Where:**
  - `CoreExtension/SerializedData.php:116-121`: `getVersionPreview()` returns `serialize($data)` unescaped for arrays.
  - `vendor/six-it/admin-ui-classic-bundle/templates/admin/data_object/data_object/preview_version.html.twig:54,84,163` and `templates/searchadmin/search/quicksearch/object.html.twig:23,94,125` render `getVersionPreview()` with `| raw`. Pimcore's own data types escape in `getVersionPreview()`; this one does not.
- **Impact:** any string inside `paymentSettings` (payment details returned by the gateway or entered in the checkout payment step) or an order-item attribute value that contains HTML runs as script in the browser of the admin who opens the order version preview or the quick search. The script can read the CSRF token from the page and act as that admin.
- **Fix:** return escaped, readable output, e.g. `htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR), ENT_QUOTES)` wrapped in `<pre>`, and `--` for non-array values. Same for `getDataForGrid()` (TODO-2 **B-09**) if it is ever shown as HTML.
