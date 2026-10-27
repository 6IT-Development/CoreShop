# PimcoreBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope and references: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs: wrong results, crashes in one path, wrong events, wrong wiring. Security issues with full impact are in TODO-1.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code against the vendored libraries or by reading the vendored library source or the compiled container. Items marked **(latent)** are not reachable today but break as soon as someone uses the feature.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Dynamic dropdown (`Controller/Admin/DynamicDropdownController.php`, `CoreExtension/DynamicDropdown*`)

### B-01 — Localized getters are never called with the language, so labels are always in the fallback language — (verified by reading)
- **Where:** `:172-195`. `parseTree()` only recurses and returns `$definition` unchanged; nothing ever adds a key. `isUsingI18n()` therefore always returns `false`, and `:155` always calls `$child->$source()` without `$currentLang`.
- **Impact:** for a localized `methodName` the dropdown shows the default/fallback language instead of the admin user's language (`current_language` sent by the JS is ignored).
- **Fix:** look the field up directly: `$classDefinition->getFieldDefinition($field)` returns `null` for localized fields, `$classDefinition->getFieldDefinition('localizedfields')?->getFieldDefinition($field)` finds them. Derive `$field` from `methodName` (`lcfirst(substr($method, 3))`). Delete `parseTree()`.
- Related: `:142` `ClassDefinition::getByName($className)` returns `null` for unknown names, and `isUsingI18n(ClassDefinition …)` then throws a `TypeError` (HTTP 500 instead of an error JSON).

### B-02 — Sorting does not sort — (verified)
- **Where:** `:68-83`. The comparator returns `0` when `a < b` and `1` otherwise; it never returns `-1`.
- **Impact:** output order is only partly sorted. Run with PHP 8.4 on 20 shuffled ints: result `1,3,2,4,5,6,7,8,9,10,11,12,14,13,15,16,18,17,19,20`.
- **Fix:** `usort($options, static fn (array $a, array $b): int => $field === 'key' ? strnatcasecmp((string) $a['key'], (string) $b['key']) : $a['value'] <=> $b['value']);` with `$field` computed once outside the callback.

### B-03 — Folder names disappear from recursive labels and real leading `-`/space characters are stripped — (verified by reading)
- **Where:**
  - `:150`: `$child->getProperty('Taglabel') !== ''`. `getProperty()` returns `null` when the property is missing (`vendor/six-it/pimcore-source/models/Element/AbstractElement.php:329-341`), so `null !== ''` is true and `$key = null`. The folder key is only used when the property exists and is an empty string.
  - `:159`: `ltrim($path . $this->separator . $key, $this->separator)` treats `' - '` as a character list, so a label such as `-10% Sale` or ` Foo` loses its leading characters.
- **Fix:** `$key = $child->getProperty('Taglabel') ?: $child->getKey();` and build the label from an array of segments: `implode(self::SEPARATOR, [...$segments, $key])`.

### B-04 — Unpublished objects are never listed, so `published` is always `true` and `onlyPublished` does nothing — (verified by reading) (dependent: JS)
- **Where:**
  - `:140`: `$folder->getChildren()` uses the default `$includingUnpublished = false` (`vendor/six-it/pimcore-source/models/DataObject/AbstractObject.php:327-334`).
  - `:160` reports `published` that can only be `true`.
  - `CoreExtension/DynamicDropdownTrait.php:51-54` stores `onlyPublished` but the server never reads it.
  - `Resources/public/pimcore/js/coreExtension/tags/coreShopDynamicDropdown.js:159-165`: the `beforeselect` veto is registered when `onlyPublished` is **false** (inverted).
- **Impact:** an object that is already related but now unpublished is not in the store, so the combo shows the raw id. The option "only published" has no effect in either direction.
- **Fix:** `getChildren([…], includingUnpublished: !$onlyPublished)` with `onlyPublished` read from the field definition (TODO-1 **C-01**); fix the inverted JS condition.

### B-05 — Missing or bad input ends in a PHP `Error` (HTTP 500) instead of an error response — (verified by reading)
- **Where:**
  - `:155`: empty `methodName` → `$child->{''}()` → `Error: Method name must be a string`; unknown method → `Error: Call to undefined method`.
  - `:107`: unknown `className` → `Factory::build()` fails on a non-existent class.
  - `:133`: `throw new \InvalidArgumentException()` without a message.
  - `:41-66`: `$parentFolderPath = sprintf('/%s', …)` is never empty, so the `else` branch with the "folder … is not valid" message is dead; an unknown folder silently returns `success: true` with no options.
- **Fix:** validate up front and return `['success' => false, 'message' => …]` with HTTP 400/404; most of this goes away with the field-definition lookup from TODO-1 **C-01**.

### B-06 — Option and method lists ignore object workspace permissions — (verified by reading)
- **Where:** `:35-91`, `:93-124`, `:126-170`. No `checkPermission('objects')`, no `$folder->isAllowed('list')` / `$child->isAllowed('view')`.
- **Impact:** an admin user restricted by workspaces can read labels and ids of every object in any folder through this endpoint.
- **Fix:** part of TODO-1 **C-01**: check the folder and filter children with `isAllowed('view')` (or use a listing with the user's workspace condition, as Pimcore's tree does).

### B-07 — `DynamicDropdownMultiple::getDataFromEditmode()` and `DynamicDropdown::getDataFromEditmode()` accept ids of any class until save validation, and load each one separately — (plausible)
- **Where:** `CoreExtension/DynamicDropdownMultiple.php:38-56`, `CoreExtension/DynamicDropdown.php:38-45`.
- **Problem:**
  - Any object id is turned into an object; the class restriction only applies later in `checkValidity()` (parent, via `getClasses()`), and not at all for unpublished-save / autosave paths that skip validation.
  - `DynamicDropdown` uses `Element\Service::getElementById('object', $data)`; a numeric string from the request is fine, but `is_numeric('1e3')` / `'0x1'`-style input is accepted.
  - `DynamicDropdownMultiple` runs one `DataObject::getById()` per id.
- **Fix:** cast to `int`, filter `instanceof '\\Pimcore\\Model\\DataObject\\' . ucfirst($this->getClassName())`, and load multiple ids with one listing (`$list->setCondition('id IN (?)', [$ids])`), keeping the submitted order.

## Serialized data (`CoreExtension/SerializedData.php`)

### B-08 — Empty values change to `null` and strings are unserialized twice — (verified by reading)
- **Where:**
  - `:72-75`: `(is_string($data) ? unserialize($data) : $data) ?: null` turns `[]`, `0`, `'0'`, `''` and `false` into `null`, so a stored empty array comes back as `null` after save/load.
  - `:111-121`: `getDataForGrid()` and `getVersionPreview()` receive the **already unserialized** value from the object getter, but call `getDataFromResource()` again. For a value that is itself a string, that string is unserialized a second time: a normal string gives `false` plus an `E_WARNING` (in debug mode an `ErrorException`), and a string that looks like a serialized payload is turned into objects (see TODO-1 **C-02**).
  - `:82-85`: `getDataFromEditmode()` has the same double-handling if the editor ever sends the value it got from `getDataForEditmode()`.
- **Fix:** `getDataFromResource()`: `null === $data ? null : unserialize($data, ['allowed_classes' => false])`, keeping `false` only for the real error case (`$data !== serialize(false)`). `getDataForGrid()` / `getVersionPreview()` must work on the PHP value directly.

### B-09 — Grid and edit mode get raw PHP values (objects, nested arrays) — (plausible)
- **Where:** `:77-80` (`getDataForEditmode` returns `$data`), `:111-114` (`getDataForGrid`).
- **Impact:** objects stored in `paymentSettings` go through the admin serializer as-is: either serialization errors in the object editor / grid, or every internal property of the stored object (gateway details, tokens) is sent to every admin who opens the order object.
- **Fix:** return a normalised array/JSON string (or `null`) for edit mode and grid; never the raw object.

### B-10 — `mandatory` is never enforced and `isEmpty()` treats `[]` as filled — (verified by reading)
- **Where:** `:102-104` overrides `checkValidity()` with an empty body (the parent at `vendor/six-it/pimcore-source/models/DataObject/ClassDefinition/Data.php:114` does the mandatory check); `:106-109` `isEmpty()` only checks `null`.
- **Fix:** call `parent::checkValidity()` (or check `!$omitMandatoryCheck && $this->getMandatory() && $this->isEmpty($data)`), and make `isEmpty()` return `true` for `[]` and `''` too.

## Grid actions and filters

### B-11 — `applyGridAction` has no permission check, does not validate `ids`, and turns a bad payload into HTTP 500 — (verified by reading) (latent: no grid action ships with CoreShop)
- **Where:** `Controller/Admin/GridController.php:88-119`.
- **Problem:**
  - No `checkPermission()` and no per-object `isAllowed()`: any admin user can run any registered grid action on any object id.
  - `json_decode($requestedIds)` returns `null` for missing/invalid JSON or an `stdClass` for `{…}`; `GridActionInterface::apply(array $processIds)` then throws a `TypeError`, which `catch (\Exception)` does not catch.
  - `$e->getMessage()` of any exception is sent to the client (can contain SQL or paths).
  - `getGridFiltersAction` / `getGridActionsAction` (`:45`, `:72`) call `$user->getLanguage()` on a possibly `null` user (`getAdminUser()` returns `?User`).
- **Fix:** `$ids = json_decode($request->request->getString('ids'), true, flags: JSON_THROW_ON_ERROR)`, check `array_is_list($ids)` and cast to `int`; check a permission (new `coreshop_grid_action` or the action's own `isGranted`) and `DataObject::getById($id)?->isAllowed('save')`; catch `\Throwable`, log it, return a generic message.

### B-12 — `ObjectListFilterListener` throws a `TypeError` for a non-string `coreshop_filter` — (verified by reading)
- **Where:** `EventListener/Grid/ObjectListFilterListener.php:37-47`. `context` is decoded from the request by Pimcore's object list action; `{"coreshop_filter": []}` reaches `ServiceRegistryInterface::has(string)`.
- **Fix:** `if (!is_string($filter = $context['coreshop_filter'] ?? null) || !$this->filterServiceRegistry->has($filter)) { return; }`.

## Mail

### B-13 — `coreshop.mail.post_send` is dispatched even when a listener cancelled the mail, so the order gets an "email sent" note for a mail that was never sent — (verified)
- **Where:** `Mail/MailProcessor.php:64-71`. `POST_MAIL_SEND` is dispatched after the `if ($mailEvent->getShouldSendMail())` block, not inside it.
- **Impact:** `CoreBundle/EventListener/Order/OrderMailNoteEventListener.php` (`onOrderMailSent`, wired in `CoreBundle/Resources/config/services/listeners.yml:30-34`) adds an order note for every `post_send`, so the order history shows mails that were suppressed.
- **Fix:** dispatch `POST_MAIL_SEND` only when the mail was sent (or add a `wasSent` flag to the event and let the listener check it).
- Related: `:40-44` reads `$attachment['body']`, `['name']`, `['content-type']` without checks (warning + `null` body); non-array attachments are dropped silently.

## Bundle metadata and DI wiring

### B-14 — `CoreShopPimcoreBundle::getVersion()` throws a `TypeError`, which crashes `pimcore:bundle:list --details` — (verified)
- **Where:** `CoreShopPimcoreBundle.php:53-68`. `InstalledVersions::isInstalled('coreshop/core-shop')` is `false` in the fork (the package is `six-it/core-shop`); `isInstalled('coreshop/pimcore-bundle')` is `true` because it is `replace`d, but `getPrettyVersion()` then returns `null`, which violates the `: string` return type.
- **Reproduced:** `(new CoreShopPimcoreBundle())->getVersion()` → `TypeError: …getComposerVersion(): Return value must be of type string, null returned`; `bin/console pimcore:bundle:list --details` aborts with the same error.
- **Fix:** check `six-it/core-shop` first, fall back with `?? ''`. The same method is very likely copied into the other CoreShop bundles (dependent); fix them together (see TODO-3 **I-14**).

### B-15 — `RegisterTypeHintRegistriesPass` registers prioritized registries as `ServiceRegistryInterface` / `ServiceRegistry` autowiring aliases — (verified container) (latent)
- **Where:** `DependencyInjection/Compiler/RegisterTypeHintRegistriesPass.php:41-73`. The check accepts `PrioritizedServiceRegistryInterface`, but the aliases are always for `ServiceRegistryInterface` and `ServiceRegistry`.
- **Evidence:** compiled container lines ~56731-56733: `CoreShop\Component\Registry\ServiceRegistryInterface $linkGeneratorsRegistry` and `CoreShop\Component\Registry\ServiceRegistry $linkGeneratorsRegistry` → `coreshop.registry.link_generators`, which is a `PrioritizedServiceRegistry` (`final`, does not implement `ServiceRegistryInterface`). The same applies to every other prioritized registry tagged `coreshop.registry`.
- **Impact:** autowiring a parameter named that way injects the wrong type → `TypeError` when the service is built.
- **Fix:** register the alias for the interface the class actually implements (`PrioritizedServiceRegistryInterface` / `PrioritizedServiceRegistry` for prioritized ones).
- Related: `:39` `class_implements($definition->getClass())` fails for a definition with no class or a `%parameter%` class (`class_implements(null)` → `TypeError`; unresolved parameter → `false` → `in_array(…, false)` → `TypeError`). Use `$container->getReflectionClass($definition->getClass(), false)`.

### B-16 — `autoconfigure_with_attributes` accepts any scalar, but the consumer needs a `bool` — (verified by reading)
- **Where:** `DependencyInjection/Configuration.php:34` (`scalarNode(...)->defaultFalse()`), passed at `DependencyInjection/CoreShopPimcoreExtension.php:60,68` into `Autoconfiguration::registerForAutoConfiguration(..., bool $autoconfigureWithAttributes)` under `strict_types=1`.
- **Impact:** `core_shop_pimcore: { autoconfigure_with_attributes: 1 }` or `"true"` (env var) breaks the container build with a `TypeError`.
- **Fix:** `->booleanNode('autoconfigure_with_attributes')->defaultFalse()->end()`.

## DataHub (`services/data_hub.yml`, `DataHub/**`) — not testable here, `pimcore/data-hub` is not installed

### B-17 — `coreShopRelations` (multiple) uses the single-relation mutation generator and has no query generator — (plausible) (dependent)
- **Where:** `Resources/config/services/data_hub.yml:23-28` maps `typegenerator_dataobjectmutationdatatype_coreShopRelations` to `DataObjectMutationFieldConfigGenerator\ManyToOneRelation`. `coreShopRelations` is a many-relation type (`ResourceBundle/Resources/config/pimcore/config.yml:27`). No query generator is registered for it (only `coreShopRelation`, `:9-14`).
- **Fix:** use `ManyToManyRelation` (with `ElementDescriptorInputType`, like `:39-47`) for the mutation and `Multihref` for the query. Also check that `Href` / `Multihref` still exist in the data-hub version that supports Pimcore 11 (`ManyToOneRelation` / `ManyToManyRelation` in newer versions).

### B-18 — `SerializedDataType` breaks on non-scalar literals and returns raw PHP objects — (plausible)
- **Where:** `DataHub/Type/SerializedDataType.php:27-40`. `parseLiteral()` reads `$valueNode->value`, which `ObjectValueNode` / `ListValueNode` / `NullValueNode` do not have; `serialize()` returns objects as-is, which webonyx cannot output.
- **Fix:** `serialize()`: return arrays/scalars only (normalise objects or return `null`); `parseLiteral()`: use `AST::valueFromASTUntyped($valueNode, $variables)`.

## Commands and listeners

### B-19 — `coreshop:app:migrate` ignores the caller's `--no-interaction` and leaves the shared `Application` changed — (verified by reading)
- **Where:** `Command/AppMigrateCommand.php:41-64`, `Command/AppMigrationGenerateCommand.php:36-59`.
- **Problem:**
  - The nested `ArrayInput` is interactive by default, so `bin/console coreshop:app:migrate -n` on a TTY still asks the Doctrine confirmation question. `--dry-run`, `--allow-no-migration`, `--env` etc. are not forwarded either.
  - `setCatchExceptions(false)` / `setAutoExit(false)` are changed on the running `Application` and only `autoExit` is restored, and only on failure.
  - `$this->getApplication()` can be `null` (command run outside an application, e.g. in tests) → `Error`.
  - `--prefix` only exists because `Pimcore\Console\Application::addCommand()` adds it (`vendor/six-it/pimcore-source/lib/Console/Application.php:95-147`), and it sets a prefix on the shared `FilteredMigrationsRepository` that stays for later commands in the same process.
  - Failure throws a bare `\Exception` with the exit code instead of returning `Command::FAILURE`.
- **Fix:** see TODO-3 **I-04**: `$input = new ArrayInput([...]); $input->setInteractive($originalInput->isInteractive());`, run with `$this->getApplication()?->find('doctrine:migrations:migrate')->run($input, $output)` and return its exit code.

### B-20 — `SluggableListener` also runs for version-only saves (drafts / autosave) — (verified event, plausible effect)
- **Where:** `EventListener/SluggableListener.php:34-51`. `Concrete::saveVersion()` dispatches `DataObjectEvents::PRE_UPDATE` with `saveVersionOnly => true` (`vendor/six-it/pimcore-source/models/DataObject/Concrete.php:245-248`).
- **Impact:** slugs are generated (and uniqueness checked against published data) for every autosave/draft; generated slugs land in the draft version and can differ from the published ones.
- **Fix:** `if ($event->hasArgument('saveVersionOnly') && $event->getArgument('saveVersionOnly')) { return; }` (and the same for `isAutoSave`).
