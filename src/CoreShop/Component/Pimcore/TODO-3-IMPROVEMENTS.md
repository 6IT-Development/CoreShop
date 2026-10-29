# Pimcore Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md) (everything except `DataObject/`). Code quality, readability, PHP 8.4 / Symfony 7.4 modernisation and small logic cleanups. None of these change behaviour in a way users notice, unless a TODO-1 / TODO-2 item is referenced.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Prefixes and markers as in TODO-1; PimcoreBundle items are referenced as PimcoreBundle **C-/B-/I-nn**.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Packaging

### PI-01 — `composer.json` does not describe what the code needs
- **Where:** `composer.json:26-37`, `:44-59`.
- **Fix:**
  - Add `"php": "^8.4"`.
  - Add the libraries used directly by the files in scope: `symfony/expression-language` (`ExpressionLanguage/*`), `symfony/string` (`Slug/SluggableSlugger.php`), `symfony/http-foundation` + `symfony/http-kernel` (`Print/PimcorePrintablePdfRenderer.php`, `Slug/SluggableLinkGenerator.php`), `symfony/event-dispatcher-contracts` (`Event/`, `Slug/DataObjectSiteSlugGenerator.php`), `symfony/mime` (`Mail.php`), `twig/twig` (`Twig/`), `webmozart/assert` (`Print/PropertyPersistedPrintableTrait.php`). Today they arrive only through `six-it/pimcore-source`.
  - `six-it/admin-ui-classic-bundle` and `laminas/laminas-stdlib` are used only in `DataObject/` (`GridConfigInstaller`, `CompositeLinkGenerator`); keep them, but re-check when `DataObject/` is scanned.
  - `require-dev`: PHPStan 1.x (`^1.10`, `:35-37`) is EOL and does not understand PHP 8.4 syntax (property hooks, asymmetric visibility, `new` without parentheses); bump to PHPStan 2 with the rest of the monorepo.
  - `minimum-stability: dev` and the `path` repository (`:44-51`) only make sense in the monorepo root. Fix the indentation of `"extra"` (`:52`, 3 spaces).
  - Keep `suggest: six-it/web-to-print-bundle`, but see TODO-2 **PB-11** (guard the service).

---

## Batch processing

### PI-02 — Replace the two hand-written iterators by one generator-based class
- **Where:** `BatchProcessing/BatchListing.php`, `BatchProcessing/DataObjectBatchListing.php` (~70 % duplicated code: `next()`, `key()`, `valid()`, `rewind()`, `count()`).
- **Fix:**
  - `final class BatchListing implements \IteratorAggregate, \Countable` with `public function getIterator(): \Generator { foreach (array_chunk($this->ids(), $this->batchSize) as $chunk) { … yield $id => $object; } }`. This removes `$index`, `$loop`, `$currentLoopLoaded`, `$items` and fixes TODO-1 **PC-03** / TODO-2 **PB-01** by construction.
  - Constructor: `public function __construct(private readonly AbstractListing $list, private readonly int $batchSize = 100)` with `Assert::greaterThan($batchSize, 0)` (today `0` makes `BatchListing` return the same rows forever, because the offset stays `0`, and makes `DataObjectBatchListing` yield nothing).
  - Keys: today `key()` is 1-based (`BatchListing.php:60-63`, `DataObjectBatchListing.php:62-65`); use the object id (more useful in progress output) or a 0-based position, and document it.
  - `count()`: `if (!$this->total)` re-queries every time the count is `0`; use `?int $total = null` and `??=`. Replace the `method_exists($dao, …)` checks (`:81-83`, `:100-102` / `:84-89`, `:106-113`) by `instanceof` on the listing type (`DataObject\Listing`, `Asset\Listing`, `Document\Listing` all have the methods).
  - Optional garbage collection after each batch (TODO-2 **PB-02**).
  - Remove the `@psalm-suppress InternalMethod` comments (`DataObjectBatchListing.php:93,117`); the project uses PHPStan.

---

## Expression language

### PI-03 — Expression providers: types, static closures, documented function list
- **Where:** `ExpressionLanguage/PHPFunctionsProvider.php:24-59`, `ExpressionLanguage/PimcoreLanguageProvider.php:27-50`.
- **Fix:**
  - Mark both `final` and add `#[\Override]` to `getFunctions()`; return type `array` → `@return list<ExpressionFunction>`.
  - `PimcoreLanguageProvider`: one private factory for the three nearly identical entries (`self::elementFunction('object', DataObject::class)`), `static fn` for the compilers too (today only the evaluators are `static`), parameter types as in TODO-2 **PB-03**.
  - `PHPFunctionsProvider`: `doubleval` is an alias of `floatval` (both are listed, `:47-48`); keep one. Remove `array_filter` or replace it with a callback-free wrapper (TODO-1 **PC-01**). Keep the list as a `private const FUNCTIONS = [...]` and build it with `array_map(ExpressionFunction::fromPHP(...), self::FUNCTIONS)` (first-class callable syntax).
  - Document the available functions (README or docs) — today users must read the class.

---

## Mail

### PI-04 — `Mail` and `MailProcessorInterface`: typed, smaller, named correctly
- **Where:** `Mail.php:21-50`, `Mail/MailProcessorInterface.php:25`.
- **Fix:**
  - `Mail::addRecipients(…): static` (no return type today; return `$this` for chaining), one private `normalizeRecipients(array|string|Address|null): list<Address>` instead of the duplicated `explode` loop (`:33-37` and `:40-43`), and the behaviour from TODO-2 **PB-04**. Mark the class `final` if no project extends it (it is used as a type in `PimcoreBundle/Event/MailEvent.php` and `CoreBundle/EventListener/Order/OrderMailNoteEventListener.php`).
  - `MailProcessorInterface::sendMail(Email $emailDocument, ?object $subject = null, array|string|null $recipients = null, array $attachments = [], array $params = []): bool`. `$subject` is the event subject (order, customer …), not the mail subject: rename it to `$context` (PimcoreBundle TODO-3 **I-13**). Add array shapes: `@param list<array{body: string, name: string, content-type: string}> $attachments`.

---

## Slugs

### PI-05 — Slug classes: PHP 8.4 types and idioms, redundant interface, readable generator
- **Where:** `Event/SlugGenerationEvent.php`, `Slug/*.php`.
- **Fix:**
  - `SlugGenerationEvent` (`:23-65`): `final`; constructor-promoted `public readonly` properties for sluggable / suffix / site / locale and `public private(set) string $slug` (PHP 8.4 asymmetric visibility) instead of five getters + one setter (keep the getters for BC, `@deprecated`).
  - `DataObjectSiteSlugGenerator::dispatchSlugEvent()` (`:58-64`): no return type; its third parameter is called `$prefix` but is the suffix. Rename and add `: string`.
  - `KeyableSluggableInterface` (`:21-27`) only declares `getKey()`, which `SluggableInterface::getKey(): ?string` (`:27`) already has. `SluggableSlugger.php:35` therefore makes a difference that does not exist for any real object; always use `getKey()` and deprecate `KeyableSluggableInterface` (TODO-2 **PB-14**).
  - `SluggableInterface::setSlug()` (`:37`) has no return type; add `: void` (or `static`) and `@param list<UrlSlug>|null`.
  - `SluggableSlugger` (`:31-59`): two almost identical `sprintf` blocks; build the name once: `$slug = $this->slugger->slug($name, '-', $locale)->lower()->toString(); return '/' . $locale . '/' . $slug . ($suffix !== null ? '-' . $suffix : '');` (`->lower()` instead of `strtolower()`, which is byte-based).
  - `DataObjectSlugGenerator::generateSlugs()` (`:34-101`, 4 nesting levels): split into `generateForLanguage()`, `mergeWithExisting()`, `findStoredSlug()`; use PHP 8.4 `array_find($existingSlugs, fn (UrlSlug $s) => $s->getSiteId() === $newSlug->getSiteId())` instead of the `$found` flag loop; `new Site\Listing()->getSites()` (PHP 8.4, no extra parentheses) instead of reusing `$sites` for two types (`:36-37`).
  - Performance: `generateSlugsForSite()` is called once per language **and** per site on every save, each with at least one `resolveSlug()` query (`DataObjectSiteSlugGenerator.php:43`). Load the site list once per request (or inject a site repository), skip sites when the generated slug cannot differ (no listener changed it), and skip the whole generation when neither the name nor the slugs changed (`$object->isFieldDirty()`).
  - `SluggableLinkGenerator::generate()` (`:35-76`): `getMainRequest()` is called three times; use `$request = $this->requestStack->getMainRequest()` once, `$siteId = $site?->getId() ?? 0`, and `array_find()` for the site / fallback lookup. Document the supported `$params` keys (`_locale`, `site`) as an array shape.
  - Remove the `@psalm-suppress InternalMethod` comments (`DataObjectSiteSlugGenerator.php:42`, `DataObjectSlugGenerator.php:72,75`) and avoid the `@internal` Pimcore APIs they hide (`UrlSlug::resolveSlug()`, `Concrete::retrieveSlugData()`), or wrap them in one adapter class so a Pimcore upgrade only touches one place.
  - **(dependent)** `DataObject/InheritanceHelper::useInheritedValues()` used at `DataObjectSlugGenerator.php:46-49` restores the inheritance flag without `try/finally` (Notification TODO-2 cross-reference); fix it when `DataObject/` is scanned.

---

## Print

### PI-06 — Print classes: interfaces instead of `method_exists()`, `final readonly`, clearer contracts
- **Where:** `Print/*.php`.
- **Fix:**
  - `PersistedPrintablePdfRenderer` / `PimcorePrintablePdfRenderer`: `final`, `private readonly` constructor properties, `#[\Override]`.
  - `PersistedPrintablePdfRenderer.php:37-39` uses a stray `/** @var PrintableInterface&PersistedPrintableInterface $printable */` comment as a statement; let `PersistedPrintableInterface` extend `PrintableInterface` (it is only meaningful for printables) and drop the comment.
  - `:50` / `:86` `method_exists($printable, 'getModificationDate' | 'save')`: require `ElementInterface` (or a dedicated interface) instead of duck typing.
  - Split `renderPrintable()` into `findValidPersisted()`, `render()`, `persist()` — easier to read and to test, and the natural place for TODO-1 **PC-04** / **PC-05** and TODO-2 **PB-09**.
  - `PimcorePrintablePdfRenderer.php:62-63,72` `?: ''` after `getContent()` handles `false`; make it explicit (`(string) $response->getContent()`) and check the sub-request status code — today a 500 in the header/footer controller produces a PDF with the error page inside.
  - `PrintableInterface` / `PrintablePdfRendererInterface`: document the `$params` keys used by CoreShop (`locale`, `printerOptions`, `printable`) as an array shape; `getPrint*Controller()` returns a controller reference string — say so in the docblock.
  - `PropertyPersistedPrintableTrait`: the `/** @var ElementInterface $this */` + `Assert::isInstanceOf($this, …)` pair is repeated; move it into a private `element(): ElementInterface` helper, and name the property key as a constant (`RENDERED_ASSET_PROPERTY = 'rendered_asset'`).
  - Remove the `@psalm-suppress InternalMethod` comments (`PimcorePrintablePdfRenderer.php:52-60`); `File::getLocalTempFilePath()` already deletes the files at shutdown (**verified** `vendor/six-it/pimcore-source/lib/File.php:78-93`), but say so in a comment, because it looks like a temp-file leak.

---

## Twig, exceptions, migration helper, small interfaces

### PI-07 — `DataObjectRoutingExtension`: attribute-based Twig function, types, delegation
- **Where:** `Twig/Extension/DataObjectRoutingExtension.php:25-44`.
- **Fix:**
  - Twig 3.30 + Symfony 7.4 support `#[AsTwigFunction('pimcore_object_path')]` on a plain service method; this removes `AbstractExtension`, `getFunctions()` and the `twig.extension` tag in `PimcoreBundle/Resources/config/services.yml:94-96`. If the class stays an extension: `final`, `getFunctions(): array`, `objectPath(): ?string`.
  - Behaviour from TODO-2 **PB-13** (null object, missing generator).
  - The function does the same as Pimcore's `pimcore_object_link` / `$object->getClass()->getLinkGenerator()` pattern; document why CoreShop keeps its own name (BC for themes).

### PI-08 — Exceptions: common marker interface and meaningful base classes
- **Where:** `Exception/*.php` (5 classes, all `final … extends \Exception`).
- **Fix:** add `CoreShop\Component\Pimcore\Exception\ExceptionInterface extends \Throwable` and implement it in all five, so callers can catch "anything from this component"; extend `\RuntimeException` (`LinkGenerationNotPossibleException`, `SlugNotPossibleException`) or `\LogicException` (`ClassDefinition*Exception`) instead of the bare `\Exception`; add named constructors with context (`SlugNotPossibleException::forObject(SluggableInterface $s, string $locale)`), see TODO-2 **PB-14**. `ClassDefinition*Exception` are only used in `DataObject/`; re-check there.

### PI-09 — `SharedTranslation`: return type, intent, named arguments
- **Where:** `Migration/SharedTranslation.php:21-44`.
- **Fix:** `final`, `add(): void` (or `: bool` "was created"), `Translation::getByKey($key, create: true)` with named argument instead of the positional `Translation::DOMAIN_DEFAULT, true`; a docblock that the helper is for Doctrine migrations only (`@internal`), and the cleanup fix from TODO-2 **PB-05**. Consider `$translation->addTranslation()` for several languages at once (`add(string $key, array<string, string> $values)`), which saves one DB write per language.

### PI-10 — Small interface cleanups
- **Where / Fix:**
  - `Document/DocumentTagFactoryInterface.php:25`: `@param array<string, mixed> $params`; it is consumed by `PimcoreBundle/Loader/DependencyInjectionImplementationLoader.php` — document what `$type` is (editable type name).
  - `Print/PersistedPrintableInterface.php:25-31`, `Slug/*Interface.php`: add `@param array<string, mixed>` shapes and one-line docblocks for each method (none have any).
  - `Slug/DataObjectSiteSlugGeneratorInterface::generateSlugsForSite()` returns **one** slug; rename to `generateSlugForSite()` (deprecate the old name).

---

## Wiring (dependent config)

### PI-11 — Service definitions for this component in PimcoreBundle (dependent)
- **Where:** `PimcoreBundle/Resources/config/services.yml:43-96`, `PimcoreBundle/Resources/config/services/print.yaml:1-14`.
- **Fix:**
  - Every service lists its constructor arguments by hand; with `_defaults: { autowire: true, autoconfigure: true }` most `arguments:` blocks and the `twig.extension` / `kernel.event_subscriber` tags disappear (keep explicit `@event_dispatcher` / `@fragment.renderer.inline` where autowiring would pick a different service).
  - `print.yaml` sets `public: true` for all services; only the interface alias needs to be public (fetched from `\Pimcore::getContainer()` in legacy code). Register `PimcorePrintablePdfRenderer` conditionally (TODO-2 **PB-11**).
  - `SluggableLinkGenerator` is also passed as a constructor argument to `coreshop.object.link_generator.{product,category}` (`CoreBundle/Resources/config/services/routing.yml:5-21`), whose constructor does not take it (CoreBundle TODO-2); decide which link generator owns slug lookup.
  - PimcoreBundle TODO-3 **I-07** covers the general service-config cleanup; do both together.

---

## Docs and tests

### PI-12 — README is boilerplate only
- **Where:** `README.md`.
- **Fix:** describe what the component offers (batch listings, slug generation and link generation, printable PDF rendering, mail helper, expression functions) and their contracts: slug format `/<locale>/<slug>[-n]`, site fallback rules, the `SlugGenerationEvent`, the `PersistedPrintableInterface` storage location (TODO-1 **PC-05**), and the security note for expression functions (TODO-1 **PC-01**). Use `https://` for the coreshop.org link and fix "It is build from" → "It is built from".

### PI-13 — Static analysis annotations
- **Where:** `@psalm-suppress` in `BatchProcessing/DataObjectBatchListing.php:93,117`, `Print/PimcorePrintablePdfRenderer.php:52-60`, `Slug/DataObjectSiteSlugGenerator.php:42`, `Slug/DataObjectSlugGenerator.php:72,75`.
- **Fix:** Psalm is not used in the monorepo; remove them or replace them by `@phpstan-ignore` with an identifier and a reason. Run PHPStan 2 at level 8+ on the component once PI-01 is done.

### PI-14 — No unit tests
- **Where:** the component has no `Tests/` directory; the cases below were reproduced or traced manually for this scan.
- **Fix:** add PHPUnit tests (most need no kernel):
  - `PHPFunctionsProvider`: no function with a callable parameter (TODO-1 **PC-01**); `PimcoreLanguageProvider`: `compile()` with variables, `evaluate()` with `null` / non-numeric input (TODO-2 **PB-03**).
  - `Mail::addRecipients()`: `;` and `,` separators, `"Name <mail>"`, `[mail]`, `[mail, name]`, keyed arrays, `Address`, one invalid address among valid ones, duplicates (TODO-2 **PB-04**).
  - `SluggableSlugger`: suffix, locale prefix, fallback to key / id, empty name (TODO-2 **PB-14**).
  - `DataObjectSlugGenerator` with fake `SluggableInterface` + stub site generator: keeps manual site slugs (TODO-1 **PC-02**), keeps edited slug (TODO-2 **PB-06**), `null` slug list (TODO-2 **PB-12**); `DataObjectSiteSlugGenerator`: listener returning a fixed slug terminates (TODO-2 **PB-08**).
  - Batch listings (kernel test or listing stub): deleted id in the middle (TODO-1 **PC-03**), stable order (TODO-2 **PB-01**), `count()` equals number of yielded objects.
  - `PersistedPrintablePdfRenderer` with a fake inner renderer: re-renders when outdated (TODO-1 **PC-04**), uses `$params` consistently (TODO-2 **PB-09**).

---

# `DataObject/` scan (added 2026-10-29)

Scope and markers: see the `DataObject/` section of [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md).

### PI-15 — One scoped-state helper instead of five copies; remove the unused / confusing ones
- **Where:** `DataObject/{InheritanceHelper,LocaleFallbackHelper,LocalizedFallbackHelper,UnpublishedHelper,VersionHelper}.php`.
- **Fix:**
  - One private generic implementation (`@template T`, `@param \Closure(): T $function`, `@return T`) with `try/finally` (TODO-2 **PB-15**); the public static methods stay as thin wrappers for BC. Accept `callable` instead of `\Closure` (first-class callables `$this->save(...)` work with both, but `callable` also takes `[$obj, 'm']`).
  - `LocalizedFallbackHelper::useFallback()` duplicates `LocaleFallbackHelper::useFallbackValues()` with the **opposite default** (`false` vs `true`) and has no caller; deprecate it.
  - `UnpublishedHelper::hideUnpublished(…, bool $hide = false)` is named "hide" but defaults to *showing* unpublished objects; no caller in the repository. Rename (`withUnpublished()` / `withoutUnpublished()`) or deprecate. It calls `Concrete::getHideUnpublished()`, which lives on `AbstractObject` (works through inheritance; call it there).
  - `VersionHelper`: use `Version::isEnabled()` instead of reading the public static `Version::$disabled` (`:35`); add native return types (`mixed`) where only docblocks exist (`UnpublishedHelper.php:30-32`, `VersionHelper.php:31-33`).
  - Mark the helper classes `final` with a private constructor (static-only).

### PI-16 — Definition updates: readable traversal, types, testability
- **Where:** `DataObject/AbstractDefinitionUpdate.php`, `ClassUpdate.php`, `BrickDefinitionUpdate.php`, `FieldCollectionDefinitionUpdate.php`, `ClassUpdateInterface.php`.
- **Fix:**
  - Empty constructor with odd formatting (`AbstractDefinitionUpdate.php:34-36`, `CompositeLinkGenerator.php:34-37`); remove it and the `parent::__construct()` calls.
  - `ClassUpdate::$classDefinition` is `?ClassDefinition` (`:26`) although it is always set; make it `private readonly ClassDefinition`. Same `readonly` for the brick / field-collection definitions.
  - `findField()` (`:217-253`): replace the self-referencing closure (`use (&$traverseFunction)`) by a private recursive method `walk(array &$node, …): bool` that returns "found"; this also fixes TODO-2 **PB-16** and makes `hasField()` easy to implement on the tree (TODO-2 **PB-19**).
  - The eight `insert*/replace*/remove*` methods repeat the same `array_splice` closures; keep two private helpers (`insertRelative(string $name, bool $layout, int $offset, array $def)`, `mutate(string $name, bool $layout, callable $fn)`).
  - `protected string $childrenPath = 'children'` never changes → `private const CHILDREN = 'children'`.
  - `setProperty(string $property, $value)` → `mixed $value` in interface and class; `#[\Override]` on implementations; array shapes for `$jsonFieldDefinition` (`array{name: string, fieldtype: string, …}`).
  - Add `hasChanges(): bool` (`getOriginalJsonDefinition() !== getJsonDefinition()`) so callers can skip no-op saves (ClassDefinitionPatchBundle TODO-2 **B-04**), and a factory interface for tests (ClassDefinitionPatchBundle TODO-3 **I-13**).
  - Remove `@psalm-suppress` (`BrickDefinitionUpdate.php:41`, `FieldCollectionDefinitionUpdate.php:41`) once TODO-2 **PB-20** is fixed.
  - `DefinitionFieldReNamerInterface` is `@experimental` and has no implementation in the repository; implement it (rename column + definition + query table) or remove it.

### PI-17 — `ClassInstaller`: same behaviour for classes, bricks and field collections; public Pimcore APIs
- **Where:** `DataObject/ClassInstaller.php`, `ClassInstallerInterface.php`.
- **Fix:**
  - One `bool $update` parameter on all three methods (TODO-1 **PC-07**), named consistently (`$updateClass` today only on `createClass`).
  - `ClassDefinition::getByName($className)` instead of `new ClassDefinition()` + `getDao()->getIdByName()` (`:48-60`); `getByKey()` of bricks / field collections returns `null` in Pimcore 11 — check whether the `NotFoundException` catches (`:30`, `:101`) are still needed.
  - The brick re-save loop (`:77-91`, three nesting levels, `!empty()` checks, loose `==`) → `foreach ((new Listing())->load() as $brick) { if (in_array($class->getId(), array_column($brick->getClassDefinitions(), 'classname'), true)) { $brick->save(); } }` with a comment explaining **why** (regenerates brick getters for the class).
  - `final`, `#[\Override]`; return the import result (`bool`) or throw when Pimcore reports failure — today the result of `import*FromJson()` is ignored.

### PI-18 — `DataLoader`: injectable service with an explicit field list
- **Where:** `DataObject/DataLoader.php`, `DataLoaderInterface.php`; **(dependent)** `OrderBundle/Controller/{OrderController.php:776-785,OrderCreationController.php:315-324}` (`new DataLoader()`).
- **Fix:** register it as a service (it is not in `PimcoreBundle/Resources/config/services.yml`) and inject it; signature `getDataForObject(Concrete $object, ?array $fields = null, int $depth = 1): array` (TODO-1 **PC-06**, TODO-2 **PB-28**); move the `Money` special case into MoneyBundle (a decorator or a tagged "field normaliser") so the component no longer references a bundle class; `@return array<string, mixed>`.

### PI-19 — `NoteService`: typed events, constants, simpler retry
- **Where:** `DataObject/NoteService.php`, `NoteServiceInterface.php`.
- **Fix:**
  - `final`, `private readonly` dependencies; logger injected via the `monolog.logger` tag (PimcoreBundle TODO-3 **I-07**).
  - Event names as constants (`NoteEvents::postAdd(string $type)`), and typed event classes instead of `GenericEvent` (keep dispatching the old names for BC).
  - The retry loop (`:94-137`) is 40 lines for "retry 5× on `RetryableException`"; extract a small `retryOnDeadlock(callable $fn, int $max = 5)` helper (or use `Pimcore\Db::get()->transactional()` with a retry wrapper). Remove the dead `method_exists($note, 'delete')` (TODO-2 **PB-24**).
  - `@return list<Note>` for `getObjectNotes()`; `storeNote(): Note` in the interface (TODO-2 **PB-25**); remove the `@psalm-suppress` comments (`:76`, `:85`) — `Log\Listing` and `Note::addData()` are `@internal` in Pimcore, so wrap them in one place.

### PI-20 — `ObjectCloner` / `ObjectService`: no reflection, clear contracts
- **Where:** `DataObject/ObjectCloner.php:32-36`, `ObjectService.php`, interfaces.
- **Fix:**
  - Replace the reflection block by `$newObject->setId(null)` (**verified** public `AbstractElement::setId(?int $id)`, `models/Element/AbstractElement.php:209`); `ReflectionProperty::setAccessible()` is a no-op since PHP 8.1 and deprecated in PHP 8.5.
  - Fix the interface docblock (TODO-2 **PB-26**) and consider `cloneObject(…, bool $save = false)` to match what it says.
  - `ObjectService::createFolderByPath()`: the `@var Folder` / `@psalm-return` comments (`:27-36`) hide that `Service::createFolderByPath()` returns `Concrete|Folder`; add `Assert::isInstanceOf($folder, Folder::class)`.
  - `final` for `ObjectService`, `#[\Override]` everywhere.

### PI-21 — Link generators: Symfony slugger, tagged iterator instead of laminas `PriorityQueue`
- **Where:** `DataObject/AbstractSluggableLinkGenerator.php`, `CompositeLinkGenerator.php`; **(dependent)** `PimcoreBundle/DependencyInjection/Compiler/LinkGeneratorPass.php`.
- **Fix:**
  - `slugify(?string $string): string` (untyped parameter today, `:25`) using `AsciiSlugger` (TODO-2 **PB-29**); as a trait or injected `SluggerInterface` rather than an abstract base class whose only purpose is one helper.
  - `CompositeLinkGenerator`: `final`, constructor `public function __construct(#[AutowireIterator('coreshop.link_generator', defaultPriorityMethod: …)] private readonly iterable $generators)` replaces `addContext()` + `LinkGeneratorPass` + `Laminas\Stdlib\PriorityQueue` (the component then no longer needs `laminas/laminas-stdlib`, TODO-3 **PI-01**). Keep `addContext()` deprecated for BC.
  - The `@var PriorityQueue|LinkGeneratorInterface[]` docblock (`:27-31`) is wrong (it is never an array).

### PI-22 — Grid interfaces and `GridConfigInstaller`
- **Where:** `DataObject/Grid/{GridActionInterface,GridFilterInterface}.php`, `GridConfigInstaller.php`.
- **Fix:**
  - `GridActionInterface::apply(array $processIds): string` → `@param list<int> $processIds`, document that the string is a user-facing (translated) message; `supports(string $type)` vs `GridFilterInterface::supports(string $listType)` — use one name.
  - `GridFilterInterface::filter()` returns the listing it got; make it `void` (mutates) or document that a new listing may be returned.
  - `GridConfigInstaller`: seven `@psalm-suppress InternalMethod` lines (`:27-56`) because `Pimcore\Bundle\AdminBundle\Model\GridConfig` is `@internal`; keep the dependency in one class and say so in the docblock; `final`; behaviour from TODO-2 **PB-22**.

### PI-23 — `composer.json` additions found in `DataObject/`
- **Where:** `composer.json:26-30`.
- **Fix:** in addition to TODO-3 **PI-01**: `doctrine/dbal` (`RetryableException`, `NoteService.php:21`), `psr/log`, `symfony/event-dispatcher` (`GenericEvent`), `symfony/dependency-injection` if PI-21 uses `#[AutowireIterator]`. `six-it/admin-ui-classic-bundle` is needed only by `GridConfigInstaller` and `laminas/laminas-stdlib` only by `CompositeLinkGenerator` (both removable with PI-21 / an optional admin integration). `DataLoader` references `CoreShop\Bundle\MoneyBundle` (PI-18).

### PI-24 — Tests for `DataObject/`
- **Where:** extends TODO-3 **PI-14**.
- **Fix:**
  - `AbstractDefinitionUpdate` with a test subclass (no DB needed, as used for this scan): before first child (TODO-2 **PB-18**), remove + insert in one instance (**PB-17**), duplicate layout names (**PB-16**), localized-field `hasField()` (**PB-19**), nameless layout elements, idempotency (apply twice → same JSON).
  - Helpers: closure throws → flag restored (**PB-15**).
  - `DataLoader`: password fields never present, depth limit, shared objects serialised once (TODO-1 **PC-06**, **PB-28**).
  - `CompositeLinkGenerator`: fallback order and exception types (**PB-30**); `slugify()` with Cyrillic / German input (**PB-29**).
  - `ClassInstaller` (kernel test): existing field collection with an extra field is not touched without `update` (TODO-1 **PC-07**).
