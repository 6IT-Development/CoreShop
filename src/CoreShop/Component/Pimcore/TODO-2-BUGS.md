# Pimcore Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md) (everything except `DataObject/`). These are functional bugs that are limited to an edge case, a specific configuration, a non-default PDF processor, or a code path that CoreShop itself does not use yet.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Prefixes and markers as in TODO-1; PimcoreBundle items are referenced as PimcoreBundle **C-/B-/I-nn**.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Batch processing

### PB-01 — `BatchListing` pages with `LIMIT/OFFSET` but without `ORDER BY`: rows can be skipped or processed twice; the caller's limit and offset are overwritten — (verified by reading) (plausible: effect) (new) (dependent)
- **Where:**
  - `BatchProcessing/BatchListing.php:40` (`setLimit($batchSize)` in the constructor), `:96-107` (`setOffset($this->loop * $this->batchSize)` + `$dao->load()` per batch).
  - **(verified)** `vendor/six-it/pimcore-source/lib/Model/Listing/AbstractListing.php:37-39`: `$order` / `$orderKey` default to `[]`; `lib/Model/Listing/Dao/AbstractDao.php:33-54` adds no `ORDER BY` then. Without an order, MySQL / MariaDB do not guarantee the same row order between two `LIMIT … OFFSET …` queries.
  - **(dependent)** only caller: `CoreBundle/Migrations/Version20231004120900.php:34-55` (sets every order with `saleState = 'order'` and its items to immutable, 50 per batch, and saves them while iterating).
- **Impact:** on installs that run this migration (upgrades from < 4.0), some orders can be skipped (stay mutable → can be recalculated later with current prices / rules) and others saved twice. In general, any listing whose condition is affected by the work done in the loop (e.g. "all orders where `immutable` is not set") skips every second batch, because the offset grows while the result set shrinks.
- **Fix:**
  - If the caller did not set an order, set a stable one: `if ($list->getOrderKey() === []) { $list->setOrderKey('id')->setOrder('ASC'); }` (`oo_id` / `id` depending on listing type).
  - Prefer keyset pagination (`id > :lastId ORDER BY id LIMIT n`) or the id-list approach of `DataObjectBatchListing` (load all ids first); both are immune to rows changing during the loop.
  - Do not overwrite a limit the caller set; respect it as the total maximum (or throw).
  - Document in the class docblock that the loop body must not change rows in a way that affects the listing condition (as long as offset paging is used).

### PB-02 — Both batch listings keep every loaded object in Pimcore's runtime cache: a full reindex of a large catalogue grows memory until it hits `memory_limit` — (verified by reading) (plausible: size) (new) (dependent)
- **Where:**
  - `BatchProcessing/DataObjectBatchListing.php:133` (`DataObject::getById()`), `BatchProcessing/BatchListing.php:106` (`$dao->load()`). **(verified)** `vendor/six-it/pimcore-source/models/DataObject/AbstractObject.php:208-215,725`: every loaded object is stored in `RuntimeCache`.
  - **(dependent)** `IndexBundle/Command/IndexCommand.php:172-183` never calls `\Pimcore::collectGarbage()`; `CoreBundle/Migrations/Version20231004120900.php` neither.
- **Impact:** `coreshop:index` on 100 000+ products (with their localized fields, relations, variants) runs out of memory; the batch size of 10 does not help, because nothing is freed between batches. The index is left half-updated (same effect as TODO-1 **PC-03**).
- **Fix:** give the batch listings an optional "after batch" hook or a constructor flag `collectGarbage: true` that calls `\Pimcore::collectGarbage()` (or `RuntimeCache::clear()` while keeping the listing itself) before loading the next batch. Document that objects of an old batch must not be used after the next batch is loaded.

---

## Expression language

### PB-03 — `PimcoreLanguageProvider`: compiler closures require `int`, but Symfony passes compiled code strings → `compile()` fails for every argument that is not a number literal; the evaluators fail for non-numeric strings — (reproduced) (new)
- **Where:** `ExpressionLanguage/PimcoreLanguageProvider.php:32,38,44` (`function (int $arg)`), evaluators `:34,40,46` (`static function (array $variables, int $value)`).
- **Reproduced** (PHP 8.4.27, `symfony/expression-language` 7.4):
  - `compile('object(5)')` → `\Pimcore\Model\DataObject::getById(5)` (works only because `"5"` is a numeric string).
  - `compile('object(id)', ['id'])`, `compile('object("5")')`, `compile('asset(1+1)')` → `TypeError: …{closure}(): Argument #1 ($arg) must be of type int, string given, called in …/Node/FunctionNode.php on line 40`.
  - `evaluate('object(x)', ['x' => 'abc'])` → `TypeError`; `evaluate` with `x = null` (missing value in an index column) → `TypeError` instead of `null`.
- **Impact:** any code that caches compiled expressions (or a future switch from `evaluate()` to `compile()`) breaks on the first expression with a variable argument; at runtime one product with an empty id field aborts the indexing run with a `TypeError`.
- **Fix:** compilers take `string $arg` and return `sprintf('\\Pimcore\\Model\\DataObject::getById((int) %s)', $arg)`; evaluators take `mixed $value` and return `is_numeric($value) ? DataObject::getById((int) $value) : null`. Add tests for `compile()` and `evaluate()` (TODO-3 **PI-14**).

---

## Mail

### PB-04 — `Mail::addRecipients()`: only `;` separates addresses, one bad address aborts the whole mail, and array entries without a name or with string keys cause `TypeError` / warnings — (verified by reading) (new) (dependent)
- **Where:** `Mail.php:23-50`. **(verified)** `vendor/six-it/pimcore-source/lib/Mail.php:852-872` (`addTo()` → `formatAddress()` → `new Address($address, $name)`), `vendor/symfony/mime/Address.php:42-60` (trims, then throws `RfcComplianceException` for anything that is not one addr-spec).
  - `:33,40` `explode(';', …)`: `"a@shop.com, b@shop.com"` (comma, the usual separator in admin fields) becomes **one** string → `RfcComplianceException`.
  - `:31` `[$recipient[0], $recipient[1]]`: an entry `['a@shop.com']` → "Undefined array key 1" warning + `addTo('a@shop.com', null)` → `TypeError` (`null` is neither `Address` nor `string`); an entry `['email' => …, 'name' => …]` → undefined key `0`.
  - An `Address` object in the array → `explode()` `TypeError`.
  - No de-duplication; `"Name <a@shop.com>"` strings work only by accident of `Address::create()` not being used.
- **(dependent)** callers: `PimcoreBundle/Mail/MailProcessor.php:48` (all CoreShop mails), `CoreBundle/Order/OrderMailProcessor.php:119` (order confirmation, customer address), `Component/Notification/Rule/Action/MailActionProcessor.php:67` (notification rule recipients typed in the admin).
- **Impact:** a notification rule with `"shop@x.com, sales@x.com"` or a stored customer e-mail with a typo throws before `PRE_MAIL_SEND`; the mail is not sent to **any** recipient and the exception propagates into the state-machine / notification listener that triggered it.
- **Fix:**
  - Split on `[;,]` with `preg_split('/[;,]/', $s, flags: PREG_SPLIT_NO_EMPTY)`, `trim()` each part, and use `Address::create()` so `"Name <mail>"` is supported explicitly.
  - Accept `Address`, `string`, `[address, name?]` and `['email' => …, 'name' => …]`; reject anything else with a clear `\InvalidArgumentException`.
  - Validate each address separately; skip (and log) invalid ones instead of aborting the whole mail, unless no valid recipient remains.
  - De-duplicate by lower-cased address. Return `static` (TODO-3 **PI-04**).

---

## Migration helpers

### PB-05 — `SharedTranslation::cleanup()` never does anything: `cleanup()` exists only on the listing **DAO** — (reproduced) (latent) (new)
- **Where:** `Migration/SharedTranslation.php:35-43` (`if (method_exists($list, 'cleanup')) { $list->cleanup(); }`).
- **Reproduced:** `method_exists(Pimcore\Model\Translation\Listing::class, 'cleanup')` → `false`; `method_exists(Pimcore\Model\Translation\Listing\Dao::class, 'cleanup')` → `true` (**verified** `vendor/six-it/pimcore-source/models/Translation/Listing/Dao.php:146`). `AbstractModel::__call()` would forward the call to the DAO, but `method_exists()` does not see it.
- **Latent:** no caller in the repository; project migrations that use it silently skip the cleanup.
- **Fix:** call `$list->getDao()->cleanup()` (it is not `@internal`), or drop the method. Note that the DAO cleanup deletes **every** key of the domain whose texts are all empty, not only keys added by CoreShop; document that. Also: `add()` (`:25-33`) has no return type and never reports whether the key existed; see TODO-3 **PI-09**.

---

## Slugs

### PB-06 — `DataObjectSlugGenerator` looks up the stored slug with `ownertype => 'object'` and a hard-coded field name: for localized slug fields nothing is ever found; the branch it guards would drop the slug — (verified by reading) (latent: data loss) (new)
- **Where:**
  - `Slug/DataObjectSlugGenerator.php:73,76`: `retrieveSlugData(['fieldname' => 'slug', 'ownertype' => 'object', 'position' => $language, …])`.
  - **(verified)** Pimcore stores slugs of a field inside localized fields with `ownertype = 'localizedfield'` and `position = <language>` (`vendor/six-it/pimcore-source/models/DataObject/ClassDefinition/Data/UrlSlug.php:273-291`). CoreShop's `slug` field is inside `localizedfields` in `CoreShopProduct.json` / `CoreShopCategory.json` (`CoreBundle/Resources/install/pimcore/classes/CoreShopProductBundle/CoreShopProduct.json:72,135`).
  - `:80-86`:
    ```php
    if ($dbSlug && $dbSlug !== $existingSlug->getSlug()) {
        $existingSlug->setPreviousSlug($dbSlug);   // slug is NOT added to $actualSlugs
    } elseif (…)
    ```
- **Problem:**
  - `$dbSlug` is always `null` for CoreShop classes, so the "editor changed the slug in the backend" case (commit `f13fe38a9` "make slug overwritable") is never detected and `previousSlug` is never set. The old URL is not kept as previous slug.
  - The guarded branch sets `previousSlug` but forgets `$actualSlugs[] = $existingSlug;`. With a non-localized `slug` field (possible in project classes), or once the owner type is fixed, every manual slug change **removes** that slug on save (the slug is in neither list) → same effect as TODO-1 **PC-02**.
  - `fieldname` is hard-coded to `'slug'`; a class whose urlSlug field has another name is never matched.
- **Fix:**
  - Add `$actualSlugs[] = $existingSlug;` in the first branch.
  - Use the owner type and position that Pimcore uses: `ownertype => 'localizedfield'` (with `ownername` of the localized field container) for localized fields, `'object'` with `position => ''` otherwise. Determine it from the class definition (`$sluggable->getClass()->getFieldDefinition('localizedfields')?->getFieldDefinition($fieldName)`).
  - Make the field name configurable (constant / constructor argument) instead of `'slug'`.
  - Avoid `@internal` `retrieveSlugData()` if possible: `UrlSlug::resolveSlug()` or the field definition's `load()` give the same data.

### PB-07 — Slugs are frozen after the first save: objects created in the admin get a key-based slug that never follows the name — (verified by reading) (plausible: workflow) (new) (dependent)
- **Where:** `Slug/DataObjectSlugGenerator.php:64-87` (an existing, non-empty slug that differs from the generated one is kept: last `else` branch), `Slug/SluggableSlugger.php:33-39` (falls back to the object key when the name is empty), `PimcoreBundle/EventListener/SluggableListener.php:38-39` (`PRE_ADD` included).
- **Problem:** the admin "add object" dialog saves the object with only a key → `PRE_ADD` → slug `/en/<key>` (e.g. `/en/new-product-1`). When the editor then enters the name and saves, the generated slug `/en/<name>` differs from the stored one and is ignored (because of **PB-06**, `$dbSlug` is `null`, so the "edited by user" detection cannot distinguish "never edited" from "edited"). Products created through the admin therefore keep URLs based on the technical key until someone edits the slug by hand.
- **Fix:** decide the rule and implement it explicitly, e.g.:
  - Keep a slug only if it was edited by a user (track this with the fixed `$dbSlug` check from **PB-06** or a "slug locked" flag); otherwise regenerate it and set `previousSlug` to the old one, so the old URL still redirects.
  - Do not generate slugs on `PRE_ADD` when the name is empty (skip instead of falling back to the key), so the first real save creates the slug.

### PB-08 — `DataObjectSiteSlugGenerator` loops forever when the slug does not change between attempts — (verified by reading) (plausible: trigger) (new)
- **Where:** `Slug/DataObjectSiteSlugGenerator.php:41-53` (`while (true)`), `:58-64`. The suffix is only respected if the slugger and every `SlugGenerationEvent` listener keep it.
- **Problem:** a listener that sets a fixed slug (`$event->setSlug('/de/sale')`, e.g. for landing pages), strips trailing digits, or a custom `SluggableSluggerInterface` that ignores `$suffix`, returns the same, already-taken slug on every iteration → endless loop with one DB query per round. In CLI (imports, messenger workers) `max_execution_time` is `0`, so the process hangs forever and blocks the worker.
- **Fix:** limit the attempts (`for ($i = 1; $i <= 100; ++$i)`) and throw `SlugNotPossibleException` with object id, locale and site afterwards; also break if the new slug equals the previous attempt. Pass the attempt number to the event as `$suffix` (the parameter is called `$prefix` in `dispatchSlugEvent()`, see TODO-3 **PI-05**).

### PB-09 — Persisted printables: `$params` are used inconsistently, renders for different locales share one property, concurrent renders collide — (verified by reading) (latent) (new)
- **Where:** `Print/PersistedPrintablePdfRenderer.php:46-88`, `Print/PropertyPersistedPrintableTrait.php:27-45`.
- **Problem:**
  - `:69` `getPersistedPath()` is called **without** `$params`, `:70` `getPersistedName($params)` with them; an implementation that puts the locale or document type into the path gets the default path.
  - `PropertyPersistedPrintableTrait` ignores `$params` and stores one `rendered_asset` property. With `params['locale']` (set by `OrderBundle/Renderer/PimcoreOrderDocumentPdfRenderer.php:47`) a PDF rendered in German is returned for an English request and vice versa.
  - `getRenderedPrintable()` (`:34`) returns `getProperty('rendered_asset')` as `?Asset`; a property of another type (manually changed in the admin, or an asset that was replaced by a folder) → `TypeError`.
  - `:72-82` delete-then-create is not atomic: two parallel requests (customer download + mail attachment) both render, the second `save()` fails with "key already exists" or deletes the first one's asset after the property was set.
  - `:86-88` `$printable->save()` saves the whole object (new version, `PRE/POST_UPDATE` events, slug generation, index update, workflow listeners) only to store a property, and changes the printable's modification date (see TODO-1 **PC-04**).
  - `:42-44` in debug mode nothing is persisted, so the persisted path is never exercised in development.
- **Fix:** pass `$params` to every interface method; key the stored property by a "variant" string returned by the printable (`getPersistedName($params)` is a good candidate); use a lock (`symfony/lock`) around render + store; save the property without a full object save (`$printable->save(['versionNote' => …])` is still a full save — use `Element\Service::saveElementProperties()` / `Property` DAO or a dedicated table); check `instanceof Asset` in the trait. Optionally allow persisting in debug via a flag.

### PB-10 — `PimcorePrintablePdfRenderer` works correctly only with the Gotenberg processor: header/footer are passed as file paths and caller margins are overwritten — (verified by reading) (dependent)
- **Where:** `Print/PimcorePrintablePdfRenderer.php:55-74`.
  - `:65-69` `array_merge($params['printerOptions'] ?? [], ['headerTemplate' => <file path>, 'footerTemplate' => <file path>, 'marginTop' => 1])`: fixed values come **last**, so a theme's `printerOptions.marginTop` is ignored.
  - **(verified)** `vendor/six-it/web-to-print-bundle/src/Processor/Gotenberg.php` reads `headerTemplate` / `footerTemplate` as file paths (`Stream::path()`); `Processor/Chromium.php:95-130` passes `$params` straight to `$page->pdf()`, where `headerTemplate` must be **HTML**, not a path, and `displayHeaderFooter` is not set; it also uses `$params ?: $this->getDefaultOptions()`, so the defaults (format, margins) are dropped as soon as any option is passed. `Processor/PdfReactor.php` ignores both keys.
  - `:36` `new Request($params)`: the sub-request has no host, scheme, session or base URL and carries objects (`order`, `document`) in the query bag; relative asset / CSS URLs in the templates cannot be resolved by a processor that loads them from the HTML.
  - **(dependent)** `OrderBundle/Renderer/PimcoreOrderDocumentPdfRenderer.php:38-40` notes "Gotenberg Supported for now, Chromium Headless not properly tested".
- **Impact:** with Chromium the PDF shows the temp file path (or nothing) as header/footer and uses Chrome's default paper size; with PdfReactor there are no header/footer at all.
- **Fix:** pass `marginTop` as a default (`array_merge(['marginTop' => 1], $printerOptions, [...templates])`); detect the processor (`Processor::getInstance()`) and pass HTML strings (`headerTemplate` content + `displayHeaderFooter: true`) for Chromium, merged with `getDefaultOptions()`; build the sub-request from the current main request (`Request::create()` with scheme/host of the current site, or `RequestStack::getMainRequest()->duplicate()`), and put objects in attributes only, not in the query bag.

### PB-11 — `PimcorePrintablePdfRenderer` is always registered, although `six-it/web-to-print-bundle` is only "suggested" → `Error: Class "Pimcore\Bundle\WebToPrintBundle\Processor" not found` at render time — (verified by reading) (dependent)
- **Where:** `Print/PimcorePrintablePdfRenderer.php:21,71`, `composer.json:31-33` (`suggest`), **(dependent)** `PimcoreBundle/Resources/config/services/print.yaml:5-14` (unconditional alias + decorator).
- **Impact:** installs without web-to-print (or with the bundle installed but not enabled / not configured) get a fatal `Error` (not an exception that callers handle) when an order document PDF is requested; the container builds fine, so the problem shows up only in production use.
- **Fix:** register the service only if `class_exists(Processor::class)` (compiler pass or `when@` / `ContainerBuilder::willBeAvailable()` in the extension) and alias `PrintablePdfRendererInterface` to a renderer that throws a clear `\LogicException('Install and enable six-it/web-to-print-bundle …')` otherwise. Inside the class, throw that exception if the class is missing.

### PB-12 — `getSlug()` may return `null`, but `SluggableLinkGenerator` and `DataObjectSlugGenerator` iterate it directly — (verified by reading) (new)
- **Where:** `Slug/SluggableInterface.php:32` (`?array`), `Slug/SluggableLinkGenerator.php:49,58`, `Slug/DataObjectSlugGenerator.php:46-49,64`.
- **Problem:** an object without slug data for the locale (language added after the products were created, objects imported with a raw SQL / DataHub write, a new object at `PRE_ADD`) returns `null` → `foreach() argument must be of type array|object, null given` warning. In `kernel.debug` Symfony's error handler turns it into an `ErrorException`, which `Component/Pimcore/DataObject/CompositeLinkGenerator.php:44-54` does **not** catch (it only catches `LinkGenerationNotPossibleException`), so every page that links to such a product (category list, cart, checkout summary) fails in dev; in prod it fills the log and then falls back.
- **Fix:** `foreach ($object->getSlug($locale) ?? [] as …)` in both places; in the link generator throw `LinkGenerationNotPossibleException` early when the list is empty, so the composite generator falls back cleanly.

### PB-13 — `pimcore_object_path()` throws `TypeError` / `InvalidArgumentException` instead of returning a fallback: one deleted product breaks cart, checkout summary and wishlist pages — (verified by reading) (plausible: data) (dependent)
- **Where:** `Twig/Extension/DataObjectRoutingExtension.php:34-43` (`objectPath(Concrete $object, …)`; `:38-40` throws when the class has no link generator). **(dependent)** templates call it without a null check: `FrontendBundle/Resources/views/Checkout/steps/summary/_item.html.twig:3`, `Cart/Summary/_item.html.twig:4`, `Cart/_widget.html.twig:19,28`, `Wishlist/summary.html.twig:42`, `Quote/show.html.twig:29` (only `Common/order_detail.html.twig:62` guards with `productIsAvailable`).
- **Problem:** `item.product` is `null` for a product that was deleted after it was put into a cart / wishlist / quote → Twig calls `objectPath(null)` → `TypeError` → HTTP 500 for that customer's cart page until the item is removed (which needs the page that is broken). A class without link generator, or `LinkGenerationNotPossibleException` from the generator, also ends in 500.
- **Fix:** accept `?object $object` and return `null` (or `'#'`) for `null`; catch `LinkGenerationNotPossibleException` and return `null`; return type `?string`. Templates should still guard, but the function must not take down the page.

### PB-14 — `SluggableSlugger` throws inside `PRE_ADD` / `PRE_UPDATE` for objects without name, key and id, aborting the save with "name is empty" — (verified by reading) (latent) (new) (dependent)
- **Where:** `Slug/SluggableSlugger.php:33-43`. For a new object `getId()` is `null` → fallback `''`; the key is used only for `KeyableSluggableInterface`. **(dependent)** `PimcoreBundle/EventListener/SluggableListener.php:44-51` does not catch `SlugNotPossibleException`.
- **Latent:** CoreShop's product and category implement `KeyableSluggableInterface`, so the key always saves them; a project class that implements only `SluggableInterface` cannot be created in the admin until a name is entered for **every** valid language (the exception comes from the first language without a name).
- **Fix:** always fall back to `getKey()` (it is part of `SluggableInterface` anyway, TODO-3 **PI-05**), then id; in the listener / generator skip the language (log at debug level) instead of aborting the save. Put object id, class and locale into the exception message.
