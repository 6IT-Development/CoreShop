# Pimcore Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: all 30 PHP files of `src/CoreShop/Component/Pimcore` **except `DataObject/`** (scanned separately): `Mail.php`, `BatchProcessing/{BatchListing,DataObjectBatchListing}.php`, `Document/DocumentTagFactoryInterface.php`, `Event/SlugGenerationEvent.php`, `Exception/*.php` (5), `ExpressionLanguage/{PHPFunctionsProvider,PimcoreLanguageProvider}.php`, `Mail/MailProcessorInterface.php`, `Migration/SharedTranslation.php`, `Print/*.php` (6), `Slug/*.php` (9), `Twig/Extension/DataObjectRoutingExtension.php`, plus `composer.json` and `README.md`.
Dependent config and callers that were followed: `Bundle/PimcoreBundle/Resources/config/{services.yml,services/print.yaml,services/link_generator.yaml,services/pimcore.yml}`, `Bundle/PimcoreBundle/{Mail/MailProcessor.php,EventListener/SluggableListener.php,Loader/DependencyInjectionImplementationLoader.php}`, `Bundle/CoreBundle/{Resources/config/services/routing.yml,Pimcore/LinkGenerator/*,Migrations/Version20231004120900.php,Order/OrderMailProcessor.php,Resources/install/pimcore/classes/CoreShopProductBundle/CoreShop{Product,Category}.json}`, `Bundle/IndexBundle/Command/IndexCommand.php`, `Bundle/OrderBundle/{Renderer/PimcoreOrderDocumentPdfRenderer.php,Resources/config/services/pdf.yml}`, `Bundle/FrontendBundle/Resources/views/**` (`pimcore_object_path` calls), `Component/Notification/Rule/Action/MailActionProcessor.php`, `Component/Product/Model/{Product,Category,ProductInterface,CategoryInterface}.php`, `Component/Pimcore/DataObject/{CompositeLinkGenerator,InheritanceHelper}.php` (read only, not reviewed), and in `vendor/`: `six-it/pimcore-source` (`lib/Mail.php`, `lib/File.php`, `lib/Model/Listing/**`, `models/Asset.php`, `models/Translation{,/Listing,/Listing/Dao}.php`, `models/DataObject/{AbstractObject,Concrete}.php`, `models/DataObject/{Data,ClassDefinition/Data}/UrlSlug.php`, `bundles/CoreBundle/config/pimcore/flysystem.yaml`), `six-it/web-to-print-bundle/src/Processor{,/Gotenberg,/Chromium,/PdfReactor}.php`, `symfony/mime/{Email,Address}.php`, `symfony/expression-language/Node/FunctionNode.php`, `twig/twig` 3.30.0.

The PimcoreBundle has already been scanned (`Bundle/PimcoreBundle/TODO-*.md`, numbers `C-`, `B-`, `I-`). Where a root cause lives in this component it is listed here again with the **component-side** part of the fix and a cross-reference; when an item is fixed, close it in both files. Items that are new in this scan are marked **(new)**.

"Critical" here means: a security issue, data loss or data written wrongly (slugs, search index, order documents), or an error that takes down a whole area once it is triggered, or a blocker for Symfony 7.4 / PHP 8.4.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4, six-it/pimcore-source 2.x, six-it/web-to-print-bundle, PHP 8.4.27). Component items use the prefixes `PC-` (critical), `PB-` (bugs) and `PI-` (improvements).
Markers: **(dependent)** = root cause or part of the fix is outside this component; **(reproduced)** = run with PHP 8.4.27 against this component's classes and the vendored libraries; **(verified)** = confirmed by reading the vendored library source or config; **(plausible)** = follows from the code, but the trigger depends on data, configuration or concurrency; **(latent)** = no class shipped with CoreShop reaches the code path today, a project implementation does.

**No Symfony 7.4 / PHP 8.4 language blocker was found.** All 30 PHP files pass `php -l` on 8.4.27; the dev-container deprecation log (`../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainerDeprecations.log`) has no entry for these classes; there are no implicitly nullable parameters, no dynamic properties and no removed PHP functions. `Twig\Extension\AbstractExtension::getFunctions()` (Twig 3.30) still has no native return type, so the untyped override in `Twig/Extension/DataObjectRoutingExtension.php:27` does not break. Modernisation (native types, readonly / asymmetric visibility, PHP 8.4 `array_find()`, `new` without parentheses, Twig attributes) is in TODO-3.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security

### PC-01 — `PHPFunctionsProvider` exposes `array_filter` with a callable-string argument: every expression of the shared expression language can run shell commands — (reproduced in bundle scan) (PimcoreBundle TODO-1 **C-03**) (dependent)
- **Where:**
  - `ExpressionLanguage/PHPFunctionsProvider.php:57` `ExpressionFunction::fromPHP('array_filter')`. The second parameter accepts any callable string (`"system"`, `"exec"`, `"passthru"`, `"unlink"` …).
  - `ExpressionLanguage/PimcoreLanguageProvider.php:32-48`: `object(id)`, `asset(id)`, `document(id)` load **any** element by id, without workspace permissions.
  - **(dependent)** `PimcoreBundle/Resources/config/services.yml:38-54` puts both providers into `coreshop.expression_language`, together with `Symfony\Component\DependencyInjection\ExpressionLanguageProvider`. Consumers: `Component/Index/Interpreter/ExpressionInterpreter.php` (expression typed in the admin index editor, evaluated with `container` as a variable), `ResourceBundle` `UniqueEntityValidator`.
- **Reproduced (bundle scan):** `$el->evaluate('array_filter(["echo PWNED"], "system")')` prints `PWNED`.
- **Impact:** any admin user who can edit an index column (or any other place where these expressions are entered) gets remote code execution on the server. With `object()/asset()/document()` the same user can read any element, including ones outside their workspaces.
- **Fix (component side):**
  - Remove `array_filter` from the list, or register a wrapper without the callback parameter: `new ExpressionFunction('array_filter', static fn (string $a) => sprintf('array_filter(%s)', $a), static fn (array $vars, array $a) => array_filter($a))`.
  - Add a unit test that iterates `getFunctions()` and fails if a function has a `callable` parameter (`ReflectionFunction` → `ReflectionParameter::getType()` / name `callback`), so a later addition (`array_map`, `usort`, `call_user_func` …) cannot bring the hole back.
  - Document in the class docblock that every function added here is reachable from admin-entered expressions.
  - Bundle side (C-03): remove the DI expression provider and the `container` variable.

---

## Slugs – data written wrongly

### PC-02 — `DataObjectSlugGenerator` deletes every site-specific slug that the generator does not produce again: manual per-site slugs are lost on every save — (verified by reading) (new)
- **Where:** `Slug/DataObjectSlugGenerator.php:42-99`.
  - `:51-59` adds a site slug to `$newSlugs` only if the generated site slug differs from the fallback slug (`if ($siteSlug === $fallbackSlug) continue;`). For a normal object (same name on every site, no collision) the generated slug is the same for every site, so `$newSlugs` holds only the fallback slug (site 0).
  - `:61-97` loops over `$newSlugs` only and copies matching `$existingSlugs` into `$actualSlugs`. An existing slug whose site id is not in `$newSlugs` is never visited.
  - `:99` `$sluggable->setSlug($actualSlugs, $language)` replaces the whole list → the site-specific slugs are removed.
  - Trigger: `PimcoreBundle/EventListener/SluggableListener.php:36-51` runs on every `PRE_UPDATE` / `PRE_ADD` of a `SluggableInterface` object (`CoreShopProduct`, `CoreShopCategory`).
- **Impact:** in a multi-site shop an editor enters a site-specific slug for a product or category (urlSlug field, "site" column), saves, and the slug is gone; the site falls back to the default slug. Any URL already published for that site (sitemaps, ads, external links) returns 404 after the next save, also after unrelated edits or imports. The same happens to site slugs that were generated earlier because of a collision once the collision is gone.
- **Fix:**
  - Start from the existing slugs and only add / replace, never drop: build `$actualSlugs` keyed by site id from `$existingSlugs`, then merge in the generated ones (`$actualSlugs[$siteId] ??= $newSlug`) and finally `array_values()`.
  - If dropping is really wanted (e.g. a site was deleted), do it explicitly: remove only slugs whose site id is not `0` and not in `$sites`.
  - Add a test: object with fallback slug + manual slug for site 5 → save → both slugs still there.

---

## Batch processing – incomplete results

### PC-03 — `DataObjectBatchListing` stops the whole iteration at the first id that no longer loads: a full reindex silently skips the rest of the class — (verified by reading) (plausible: trigger) (new) (dependent)
- **Where:**
  - `BatchProcessing/DataObjectBatchListing.php:118` loads all ids once (`loadIdList()`); `:133` later loads each object with `DataObject::getById($itemId)`, which returns `null` for an object that was deleted in the meantime (or cannot be loaded, e.g. class removed) — **(verified)** `vendor/six-it/pimcore-source/models/DataObject/AbstractObject.php` `getById(): ?static`.
  - `:67-69` `valid()` is `isset($this->items[$this->index])`; `isset()` on a `null` entry is `false` → `foreach` ends.
  - **(dependent)** `IndexBundle/Command/IndexCommand.php:141-187` (`coreshop:index`, 10 objects per batch) and `Behat/Context/Setup/IndexContext.php:227`. The command then prints "Processed N Objects" and "Done" and returns `0`.
- **Impact:** a reindex on a live shop runs for minutes to hours; if any product of the id list is deleted (or moved to the recycle bin) during that time, every product after it in that class is **not** re-indexed. The index keeps stale prices / stock / visibility for those products, and the command reports success, so nobody notices. `count()` (`:81-98`, separate `getCount()` query) also does not match the number of objects returned.
- **Fix:**
  - Skip `null` results instead of storing them: `if (($object = DataObject::getById($id)) instanceof DataObject) { $items[] = $object; }` and keep loading the next batch while a batch comes back empty but ids remain.
  - Better: replace the hand-written `Iterator` by a generator (`IteratorAggregate::getIterator(): \Generator`) that walks `array_chunk($ids, $batchSize)` and `continue`s on `null` (TODO-3 **PI-02**).
  - Make `count()` return `count($this->ids)` once the ids are loaded, so progress and real work match.

---

## Persisted printables (order documents) – stale and public PDFs

### PC-04 — `PersistedPrintablePdfRenderer` never re-renders: the "outdated" check has no effect, a stored PDF is returned forever — (verified by reading) (latent) (new)
- **Where:** `Print/PersistedPrintablePdfRenderer.php:46-65`:
  ```php
  if ($renderedAsset instanceof Asset) {
      if (method_exists($printable, 'getModificationDate')) {
          if ($renderedAsset->getCreationDate() >= $printable->getModificationDate()) {
              $data = $renderedAsset->getData(); if ($data !== false) return $data;
          }
      }
      $data = $renderedAsset->getData();          // runs for outdated assets too
      if ($data !== false) return $data;
  }
  ```
  Both branches return the stored asset; the code after `:65` (re-render and overwrite) is reached only when the asset is missing or unreadable.
- **Latent:** the decorator is active for every `PrintablePdfRendererInterface` call (`PimcorePrintablePdfRenderer` → `PersistedPrintablePdfRenderer`, `PimcoreBundle/Resources/config/services/print.yaml:10-14`), but the persisted branch only runs for printables that implement `PersistedPrintableInterface` (e.g. with `PropertyPersistedPrintableTrait`). No CoreShop model does today; projects that make `OrderInvoice` / `OrderShipment` persisted do.
- **Impact:** once a PDF has been stored, changes to the document (address correction, re-numbered invoice, changed template, corrected tax line) never reach the PDF that is downloaded or attached to mails. For invoices this means a legally relevant document that differs from the data in the shop.
- **Fix:**
  - Remove the second unconditional `return` (`:60-64`) so that an outdated asset falls through to re-rendering.
  - Replace `method_exists($printable, 'getModificationDate')` by an interface check (`ElementInterface` or a small `ModificationDateAwareInterface`).
  - Note for the fix: `$printable->save()` at `:86-88` itself sets a new `modificationDate` on the printable (Pimcore sets it on every save). Today the asset is created in the same second, so `>=` holds, but a slow save makes the next request see the PDF as outdated. Store the rendered asset without touching the printable's modification date (property save via `Element\Service`, or compare against a stored hash / version id instead of timestamps) — see TODO-2 **PB-09**.
  - `Asset::getData()` returns `bool|string` (**verified** `vendor/six-it/pimcore-source/models/Asset.php:1117`); keep the `false` check but log when an existing asset cannot be read.

### PC-05 — Persisted PDFs are written as normal Pimcore assets, which are publicly downloadable by default — (verified config) (latent) (new) (dependent)
- **Where:** `Print/PersistedPrintablePdfRenderer.php:69-82` creates an `Asset\Document` under `$printable->getPersistedPath()` named `getPersistedName($params).pdf`. **(verified)** `vendor/six-it/pimcore-source/bundles/CoreBundle/config/pimcore/flysystem.yaml:11-17`: `pimcore.asset.storage` defaults to `%kernel.project_dir%/public/var/assets` with `directory_visibility: public`, i.e. every asset is served by the web server without authentication.
- **Latent:** same as **PC-04** (needs a project class implementing `PersistedPrintableInterface`). Also check `OrderBundle/Renderer/AssetOrderDocumentPdfRenderer.php`, which stores order PDFs as assets in the same way (OrderBundle, not part of this scan).
- **Impact:** invoices and delivery notes contain names, addresses, ordered items and amounts. Names derived from document numbers (`invoice-10023.pdf`) are easy to guess, so anyone can download other customers' documents (GDPR breach).
- **Fix (component side):**
  - Document on `PersistedPrintableInterface::getPersistedPath()` that the path must be inside a protected asset folder, and check it in the renderer: refuse (or log a critical warning) if the path is not below a configured, protected root.
  - Add a renderer option (constructor argument, wired from bundle config) for the root folder, defaulting to a folder that the project protects (Pimcore "protected" asset path pattern / separate private flysystem storage), and use a non-guessable file name (`bin2hex(random_bytes(16))`) — the asset is found through the `rendered_asset` property anyway.
  - Bundle / project side: protect the folder (web-server rule or Pimcore asset protection) — add this to the docs.
