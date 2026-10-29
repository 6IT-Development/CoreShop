# VariantBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
The scope and markers are the same as in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md): **(dependent)** means the root cause or part of the fix is outside this bundle, **(reproduced)** means it was run with PHP 8.4.27 against the vendored libraries, and **(plausible)** means the trigger depends on data or configuration.
Paths are relative to the bundle root unless noted otherwise. `vendor/` means `../pimcore2teszt10211524/vendor/`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Symfony 7.4 / PHP 8.4

### B-01 — Both custom constraints trigger Symfony 7.3/7.4 deprecations when the YAML mapping is loaded. They will stop working on Symfony 8 — (reproduced)
- **Where:** `Validator/Constraints/ValidAttributes.php`, `Validator/Constraints/ValidAttributesType.php`, `Resources/config/validation/{Attribute,Product}.yaml`. Loader: `vendor/symfony/validator/Mapping/Loader/AbstractLoader.php` `newConstraint()`, `vendor/symfony/validator/Constraint.php:122`.
- **Problem:** Loading the two YAML files with `YamlFileLoader` on Symfony 7.4.21 emits, for each constraint:
  - `Since symfony/validator 7.3: Using constraints not supporting named arguments is deprecated. Try adding the HasNamedArguments attribute to …`
  - `Since symfony/validator 7.4: Support for evaluating options in the base Constraint class is deprecated. Initialize properties in the constructor of … instead.`
  - The options array (`message`, `groups`) is still applied on 7.4, so validation works today. On Symfony 8 the options array path is removed, so `message` and `groups` would be ignored or the constructor would fail.
  - `Attribute.yaml:3` uses a leading backslash (`\CoreShop\Bundle\…\ValidAttributesType`). It works, but the class name in the deprecation message and in the loader's named-arguments cache key then differs from the real FQCN.
- **Fix:** give both constraints a constructor with `#[HasNamedArguments]`: `public function __construct(public string $message = '…', ?array $groups = null, mixed $payload = null) { parent::__construct(null, $groups, $payload); }`. Drop the leading backslash in `Attribute.yaml`. See TODO-3 **I-03**.

---

## Variant generator

### B-02 — The "variant already exists" check uses a LIKE pattern that matches the wrong IDs. Variants are silently skipped, or the check never matches at all
- **Where:** `Service/VariantGeneratorService.php:53-65`. Query-column format: `vendor/six-it/pimcore-source/models/DataObject/ClassDefinition/Data/ManyToManyRelation.php:247-268` (`getDataForQueryResource()` → `,object|12,object|30,`). `ResourceBundle/CoreExtension/CoreShopRelations.php` extends it. `CoreShopProduct.json` maps `attributes` as `coreShopRelations`.
- **Problem:**
  - `attributes LIKE "%object|1%"` also matches `object|12`, `object|100`, `object|1999`… If a variant with the attributes (12, 3) already exists, generating (1, 3) is **silently skipped** as a duplicate. With realistic ID ranges this happens often.
  - The documented setup (`docs/03_Bundles/Variant_Bundle.md`: "attributes: ManyToManyObjectRelation") stores `,12,30,` in the query column, without the `object|` prefix. There the check **never** matches, so every run tries to create every variant again. Then one of two things happens:
    - The key already exists (same attribute keys), `save()` throws "duplicate full path", and the message is retried 3 times and then moved to the failed transport (B-04).
    - The key differs (for example after an attribute key was renamed), and a real **duplicate variant** is created.
  - The column is the inherited query column (`allowInherit: true` on the product class). It only works because the parent product normally has no attributes.
- **Fix:** match on exact delimiters with bound parameters (also needed for TODO-1 **C-02**): `attributes LIKE ?` with `'%,object|' . (int) $id . ',%'`. Better: load the existing variants once (`$product->getVariants()`, which also covers unpublished variants) and compare sorted ID sets in PHP: `sort($ids) === sort(array_map(fn ($a) => $a->getId(), $variant->getAttributes() ?? []))`. This works for every relation type and needs no SQL.

### B-03 — The controller accepts any `attributes` payload. A missing group turns the request into a silent no-op, an unbounded selection explodes in memory and in the queue, and invalid IDs create incomplete variants
- **Where:** `Controller/VariantController.php:93-124`, `Service/VariantGeneratorService.php:49-51` and `:72-76`, `:100-115` (`generateCombinations`)
- **Problem:**
  - **No coverage check:** `generateVariant()` returns `null` when `count(allowedAttributeGroups) !== count($attributeIds)`. If the request does not contain a value for every allowed group (crafted request, stale admin tab after the allowed groups were changed, or a group with no attributes), every message is a no-op. The user still gets "Variants will be generated in background" and nothing happens.
  - **No membership check:** IDs are not checked against the product's allowed groups. A payload with two values of the same group, or an attribute from a foreign group, passes the count check. The variant is then saved with an invalid attribute set. Publishing it fails `ValidAttributes` later.
  - **Filter after the count check:** IDs that do not resolve to an `AttributeInterface` are dropped by `array_filter()` **after** the count check (`:72-76`). The variant is created with fewer attributes than groups, and its key and name are built from the remaining attributes only.
  - **Unbounded cartesian product:** `generateCombinations()` builds all combinations in memory, and the controller dispatches one message per combination synchronously inside the request. 4 groups × 30 values = 810 000 messages, each an `INSERT` into `messenger_messages`. That means a request timeout with a half-filled queue. 6 × 20 values = 64 M arrays, which exhausts the PHP memory limit. Nothing limits it, neither in the UI nor on the server.
- **Fix:**
  - Validate in the controller (or a DTO, TODO-3 **I-07**): `attributes` must be a map `groupId => list<int>`. Its keys must equal the product's allowed group IDs. Every ID must be an attribute whose group is the key.
  - Reject the request with a JSON 4xx otherwise.
  - Compute the number of combinations as the product of the group sizes before generating them, and reject anything above a configurable limit (for example `core_shop_variant.generator.max_combinations: 500`).
  - Dispatch one message per request that carries the grouped selection and lets the handler iterate (TODO-3 **I-09**), or use `DispatchAfterCurrentBusStamp` / batches.
  - In `generateVariant()`, resolve the attributes **first**, then compare the count of resolved attributes with the allowed groups.

### B-04 — Key collisions and over-long keys make the worker throw. The message is retried for 35 minutes and then lands in the failed queue, with no feedback to the user
- **Where:** `Service/VariantGeneratorService.php:78-80`, `:90`, `:95`; `Resources/config/pimcore/messenger.yml:6-10`
- **Problem:**
  - The key is `implode(' - ', attribute keys)`. It is not unique when the product already has a child with that key, for example:
    - a manually created variant with the same attributes in another order,
    - a variant whose attributes were changed later,
    - two attribute objects with the same key in different groups that are combined in a different group order.
  - `save()` then throws "Duplicate full path". The retry strategy repeats the same failure 3 times (5 + 10 + 20 min), then moves the message to `coreshop_variant_failed`.
  - The key is not passed through `Element\Service::getValidKey()` and not truncated. Many groups or long attribute keys can exceed 255 characters, and the save fails.
  - The name (`sprintf('%s %s', $product->getName($language), $name)`) starts with a space when the product has no name in that language. Attribute names that are `null` produce double spaces.
- **Fix:** build the key with `DataObject\Service::getValidKey()` and make it unique with `DataObject\Service::getUniqueKey($variant)` after `setParent()`. Treat exceptions as unrecoverable (`UnrecoverableMessageHandlingException`) for validation or duplicate errors, so they are not retried. Build names with `implode(' ', array_filter([...]))`.

### B-05 — Every save of a published variant saves the **parent**, which is expensive, creates versions, and can fail the variant save
- **Where:** `EventListener/MainVariantListener.php:60-78`, `:39-58`
- **Problem:**
  - `postUpdate` calls `$product->save()` for every published variant update. On the parent this does the following:
    - It runs `preUpdate`, which loads **all** variants with their attributes and groups (`findMainVariant()`).
    - It writes a full new parent version.
    - It runs every other product listener: index update, price-rule cache, and so on.
    - Because the class has `allowInherit: true`, Pimcore's inheritance helper rewrites the query rows of **all** variants.
  - Publishing or importing N variants therefore costs O(N²) and creates N parent versions.
  - The parent save happens in `POST_UPDATE` of the variant. If the parent cannot be saved (for example a mandatory field is empty on the parent, or a validation listener rejects it), the exception reaches the admin as a failed **variant** save, even though the variant is already committed.
  - The parent's `modificationDate` changes on every variant save. An admin who has the parent open gets the "modified by another user" warning or overwrites the change.
  - The parent is saved even when the main variant does not change.
- **Fix:**
  - Compute the new main variant in the variant's listener and only touch the parent when the value changes.
  - Persist only the relation, without a full save, or use `$parent->save(['isAutoSave' => …])` / `saveVersion(false, false)` as appropriate. Alternatively, collect affected parent IDs and recompute them once on `kernel.terminate` / `console.terminate`, or through a message.
  - Wrap the parent update in try/catch and log failures instead of failing the variant save.
  - Also see TODO-1 **C-03** (unpublish and delete must also trigger a recompute) and TODO-3 **I-12**.

### B-06 — All three listeners also run on "save draft" and on **auto-save** (`saveVersionOnly`)
- **Where:** `EventListener/MainVariantListener.php`, `EventListener/ProductListener.php`, `EventListener/AttributeListener.php`. Pimcore dispatches `PRE_UPDATE` and `POST_UPDATE` with the arguments `saveVersionOnly: true` / `isAutoSave` from `Concrete::saveVersion()` (`vendor/six-it/pimcore-source/models/DataObject/Concrete.php`, `saveVersion()`).
- **Problem:**
  - `MainVariantListener::postUpdate` saves the **parent for real** each time a published variant's draft is saved, including every auto-save tick of an open variant. The draft itself is not published, but the parent is re-saved (B-05 costs) on every tick.
  - `MainVariantListener::preUpdate` on a parent auto-save loads all variants every tick.
  - `ProductListener` and `AttributeListener` validate drafts, so an incomplete draft cannot be saved: the admin gets a validation error on "Save draft" or a failed auto-save. `ProductListener` also writes generated names into the draft.
- **Fix:** at the top of each handler: `if ($event->hasArgument('saveVersionOnly') && $event->getArgument('saveVersionOnly')) { return; }`. Also skip on `isAutoSave`.

### B-07 — **(dependent)** `ProductVariantTrait::getVariantParent()` loops forever, and `findMainVariant()` throws, on unexpected data. Both are called from this bundle on hot paths — (plausible)
- **Where:** `Component/Variant/Model/ProductVariantTrait.php` `getVariantParent()` and `findMainVariant()`. Callers in this bundle: `Controller/VariantController.php:54,111`, `EventListener/MainVariantListener.php:51,76`, `EventListener/ProductListener.php:79`, `Validator/Constraints/ValidAttributesValidator.php:40`.
- **Problem:**
  - `getVariantParent()`: `while ($parent->getType() === 'variant') { if ($parent->getParent() instanceof self) { $parent = $parent->getParent(); } }`. When a variant's parent is **not** an instance of the trait's class (a variant moved under a folder or under another class through the API or an import), the loop never advances. A web request hangs until `max_execution_time`. A **messenger worker or CLI import hangs forever**, because the CLI has no time limit.
  - `findMainVariant()`:
    - It calls `usort($a->getAttributes(), …)`. If `getAttributes()` returns `null` for a variant (the interface allows it), this is a `TypeError`.
    - It calls `$attribute->getAttributeGroup()->getSorting()`. If an attribute has no group (B-09 leaves stale or missing groups), this is an `Error`.
    - Either way the **parent product can no longer be saved** (it runs in `MainVariantListener::preUpdate`).
- **Fix (in `Component/Variant`):** in `getVariantParent()`, break out of the loop (or throw a clear exception) when the parent is not `self`. In `findMainVariant()`, use `?? []` for the attributes and `?->getSorting() ?? 0` for the group. In this bundle, catch `\Throwable` around `findMainVariant()` in the listener and log it.

### B-08 — `getAttributesAction()` returns a 500 for products without allowed groups, and all controller errors are 500s that the admin UI does not show
- **Where:** `Controller/VariantController.php:57-77`, `:43-51`, `:96-108`; `Resources/public/pimcore/js/resource.js:37-56`, `:72-85`
- **Problem:**
  - `getAllowedAttributeGroups()` returns `?array`. `array_map(…, null)` throws a `TypeError`. This happens when the allowed groups were removed and the tab still shows the button: the JS only checks the data loaded when the tab was opened.
  - Bad input throws `\InvalidArgumentException`, which gives a 500 response and an error log entry, instead of a JSON 400.
  - The `Ext.Ajax.request` call has no `failure` handler, and the tree store has no `exception` listener. On any error the window stays open with no message. The `if (!store)` check in the `load` handler can never be true.
- **Fix:** `$product->getAllowedAttributeGroups() ?? []`. Throw `BadRequestHttpException` or return `$this->json(['success' => false, 'message' => …], 400)`. Add `failure` handlers in the JS that show `res.message`.

---

## Validation and listeners

### B-09 — `AttributeListener` keeps a stale attribute group, and the definition of "attribute of a group" differs between the classes
- **Where:** `EventListener/AttributeListener.php:58-68`, `Component/Variant/Model/AttributeGroup.php` `getAttributes()` (direct children only), `Validator/Constraints/ValidAttributesTypeValidator.php:55-70` (all descendants), class JSONs (`attributeGroup` is `noteditable: false`)
- **Problem:**
  - The listener only **sets** the group when an ancestor group is found. If an attribute is moved out of a group (to a folder or another tree), the old group stays. `NotBlank` passes, and the attribute still counts for the old group in `ValidAttributesValidator` and the frontend.
  - The listener walks **all ancestors**, so an attribute in `Group/Subfolder/Attr` gets the group. However, `AttributeGroup::getAttributes()` only returns **direct** children, so such attributes never show up in the generator tree (`getAttributesAction`). `ValidAttributesTypeValidator` checks **all** descendants, including those of a nested group.
  - The `attributeGroup` field is editable in the admin, but the listener silently overwrites a manual choice whenever the attribute sits below a group.
- **Fix:**
  - Set the group to `null` when no ancestor group is found, so `NotBlank` reports it.
  - Use one rule everywhere: either direct children only, which then needs a validation that rejects deeper nesting, or descendants, with `getAttributes()` using a listing on `path LIKE ?`.
  - Make `attributeGroup` `noteditable: true` in the three class JSONs (TODO-3 **I-13**).

### B-10 — `ValidAttributesValidator` lets attributes without a group through, and both it and the Twig helper compare Pimcore objects by identity
- **Where:** `Validator/Constraints/ValidAttributesValidator.php:49-64`, `Twig/Extension/VariantExtension.php:63-76`
- **Problem:**
  - An attribute without a group is skipped (`continue`). Because the count check passed before, a variant with `[Red (Color), X (no group)]` and two allowed groups (Color, Size) is **valid** even though Size is missing.
  - `in_array($group, $allowedAttributeGroups, true)` and `$attribute->getAttributeGroup() !== $attributeGroup->getGroup()` rely on Pimcore returning the **same PHP instance** for the same ID. That holds only while both come from the runtime cache. It fails for objects loaded with `['force' => true]`, objects that were cloned, after `RuntimeCache::clear()` (for example the messenger listener in a worker or long-running import), or for versions or previews. Then a valid variant is rejected, or the frontend preselects nothing. — (plausible)
  - `getVariantParent()->getAllowedAttributeGroups() ?? []`: when the parent has no allowed groups, every published variant with attributes fails with the generic message, which says nothing about the missing allowed groups.
- **Fix:** treat a missing group as a violation. Compare by ID: `in_array($group->getId(), array_map(fn ($g) => $g->getId(), $allowed), true)`, and `$attribute->getAttributeGroup()?->getId() !== $attributeGroup->getGroup()->getId()`. Use separate messages for "missing group", "duplicate group" and "group not allowed".

---

## Messenger

### B-11 — `CreateVariantMessageHandler` can fail **after** the variant was created, which triggers a retry, and reports nothing on failure
- **Where:** `Messenger/Handler/CreateVariantMessageHandler.php:48-66`; `vendor/six-it/pimcore-source/models/Notification/Service/NotificationService.php:51-85`
- **Problem:**
  - `sendToUser()` throws `UnexpectedValueException` when the recipient user no longer exists (for example deleted between dispatch and handling). The variant is already saved. The message is retried, the duplicate check (B-02) skips it, and no notification is sent. Pimcore's `sendToUser()` also starts a transaction and does not roll it back when it throws, which leaves the worker's connection inside an open transaction **(dependent)**.
  - Failures (TODO-1 **C-02**, B-03, B-04) never reach the user. The admin only saw "will be generated in background". Successful generations produce one notification **per variant**, so 200 variants produce 200 notifications.
  - `sendToUser($userId, 0, …)`: sender ID `0` is looked up with `User::getById(0)`, which is a wasted query that returns `null`.
- **Fix:** wrap the notification in try/catch and log failures. Send one summary notification per generation run (requires the batch message, TODO-3 **I-09**). Send a failure notification from a `WorkerMessageFailedEvent` listener or from the handler's catch block. Pass the product as `$element`, so the notification links to it.

### B-12 — No user feedback while messages sit in the failed transport, and the failed transport is not documented
- **Where:** `Resources/config/pimcore/messenger.yml`, `docs/03_Bundles/Variant_Bundle.md` ("Variant Generator → Installation")
- **Problem:** Failed messages end up in `coreshop_variant_failed` with `max_retries: 0`. The docs only mention `messenger:consume coreshop_variant`. There is no hint to run `messenger:failed:show --transport=coreshop_variant_failed`, and nothing in the admin. Without a running consumer, nothing happens at all, and the admin is still told the variants "will be generated".
- **Fix:** document the failed transport and the consumer in the docs. Optionally show a hint in the response when no consumer has picked up messages (for example by checking the queue length), or offer a synchronous mode for small selections (`core_shop_variant.generator.sync_threshold`).

---

## Twig

### B-13 — `serializeIndex()` passes the groups array as the **context**, so the groups are ignored — (reproduced)
- **Where:** `Twig/Extension/VariantExtension.php:90-93`
- **Problem:** `$this->serializer->normalize($index, 'json', $groups)` passes `['coreshop']` as the context array. The normalizer sees `[0 => 'coreshop']` and no `groups` key, so it serialises **all** readable properties. This was reproduced with `ObjectNormalizer` on 7.4: the context `['coreshop']` returns unfiltered data. Today `ResolvedIndex` only has `url` and `attributes`, so the output happens to be the same. Any property added later to `ResolvedIndex`, or a caller that passes custom groups, silently leaks or ignores them.
- **Fix:** `normalize($index, 'json', ['groups' => $groups])`.

---

## Configuration and packaging

### B-14 — `redirect_to_main_variant` is a `scalarNode`, so string values such as `'false'` turn into `true`
- **Where:** `DependencyInjection/Configuration.php:45-49`, `DependencyInjection/CoreShopVariantExtension.php:44`, `Pimcore/VariantLinkGenerator.php:29`
- **Problem:** Any scalar is accepted. A value from an env var, or a quoted YAML string such as `'false'` or `'no'`, is passed as a string to the `bool $redirectToMainVariant` constructor argument. The dumped container is not `strict_types`, so the value is coerced: `'false'` → `true`. Values such as `[]` or `'foo'` are not rejected either.
- **Fix:** `->booleanNode('redirect_to_main_variant')->defaultTrue()->end()`. Use `%env(bool:…)%` when it comes from the environment.

### B-15 — `composer.json` does not declare what the bundle uses, and its constraints are out of sync with the root
- **Where:** `composer.json:26-34`; the same in `Component/Variant/composer.json`
- **Problem:**
  - Missing `php: ~8.4.0` (root: `~8.4.0`).
  - Used but not declared:
    - `symfony/messenger` and `symfony/doctrine-messenger` (the transport is `doctrine://default`)
    - `symfony/validator`, `symfony/serializer`, `symfony/translation-contracts`, `symfony/http-foundation`, `symfony/http-kernel`, `symfony/config`, `symfony/dependency-injection`
    - `twig/twig`
    - `webmozart/assert`
    - `six-it/pimcore-source` (`DataObject`, `Tool`, `NotificationService`, `LinkGeneratorInterface`)
    - the admin UI bundle (`AdminAbstractController` through `ResourceBundle\Controller\AdminController`)
    - `coreshop/pimcore` (`InheritanceHelper`, `CompositeLinkGenerator`; the service it decorates must exist, otherwise the container fails to compile).
  - `require-dev` uses `phpstan/phpstan ^1.10`, `phpstan-doctrine ^1.3.40` and `phpstan-webmozart-assert ^1.2.4`, while the root uses `^2.x` of all three. When the bundle is analysed standalone, phpstan 1.x does not fully support PHP 8.4 syntax.
  - `branch-alias dev-master: 4.1-dev` while the requirements are `^4.0`. This is consistent, but check it against the other bundles on this branch.
- **Fix:** align the constraints with the root `composer.json`, and add the missing `require` entries.
