# Store Component – TODO 3: Improvements (code quality, readability, logic, PHP 8.4 / Symfony 7.4 idioms)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
None of these are defects on their own. They make the component cleaner, safer, easier to test, or more idiomatic for the target stack.
Path conventions as in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Component items use the prefixes `CC-` (critical), `CB-` (bugs) and `CI-` (improvements). StoreBundle TODO-3 **I-15** lists a short version of some of these; this file is the detailed, component-side version.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 language features

### CI-01 — Native types and a clear contract on all interfaces
- **Where:** `Model/StoreInterface.php:29-67`, `Model/StoresAwareInterface.php:25-37`, `Model/StoreAwareInterface.php:25`, `Context/RequestBased/RequestResolverInterface.php:27-32`, `Context/SiteBasedResolverInterface.php:24`.
- Missing / weak types:
  - `StoreInterface`: `getName(): ?string`, `setName(string $name): void`, `getTemplate(): ?string`, `setTemplate(string $template): void`, `getIsDefault(): bool` (+ `isDefault(): bool` alias), `setIsDefault(bool $isDefault): void`, `getSiteId(): ?int` / `setSiteId(?int $siteId): void` (after StoreBundle **B-07**: `null` = "no site").
  - `StoresAwareInterface`: `getStores(): Collection` (`@return Collection<int, StoreInterface>`), `hasStore(StoreInterface $store): bool`, `addStore(): void`, `removeStore(): void`; add `hasStores(): bool` (exists in the trait, missing in the interface).
  - `StoreAwareInterface::setStore(?StoreInterface $store): static` (see CB-09 — **not** `void`).
  - `RequestResolverInterface::findStore(Request $request): StoreInterface` (throw-only) or keep `?StoreInterface` and document `null` as "not responsible" (CB-02). Remove the contradictory `@return StoreInterface`.
- These are BC breaks for implementers (Core `StoreInterface`/`Store` extend them; Pimcore generated classes implement `StoreAwareInterface`) → same release as the implementations, note in `UPGRADE.md`, regenerate the Pimcore classes.

### CI-02 — Typed properties, `final`, `readonly`, `#[\Override]`, constructor promotion
- `Model/Store.php:33-56`: typed properties (`protected ?int $id = null; protected ?string $name = null; protected string $template = 'standard'; protected bool $isDefault = false; protected ?int $siteId = 0`), typed accessors (CB-08), `setId(?int $id): static`. Remove the docblocks that only repeat the type. Doctrine 2.x/3.x maps typed properties without changes. Keep `\Stringable` (already declared) and consider `#[\Override]` on `__toString()`.
- `Context/*`, `Context/RequestBased/*`: every promoted constructor property → `private readonly` (`CachedStoreContext::$requestBasedStoreContext`, `StoreContext`, `SiteBasedResolver`, all three request resolvers). `SiteBasedResolver` → `final` (it is the only non-final service class; decorate via `SiteBasedResolverInterface`).
- Add `#[\Override]` to every interface method implementation (`getStore()`, `findStore()`, `resolveSiteWithDefaultForStore()`), so a renamed interface method fails loudly.
- Remove the empty, oddly formatted constructor parameter lists (`Context/CompositeStoreContext.php:33-36`, `Context/RequestBased/CompositeRequestResolver.php:30-33`, `Model/StoresAwareTrait.php:31-34`: `public function __construct(\n        ) {`) — initialise the property at declaration (`private array $storeContexts = [];`, see CI-05).
- `StoreContext.php:54`: `$masterRequest` → `$mainRequest` (Symfony renamed the concept in 5.3).

### CI-03 — Model traits: typed, constructor-free, generic doc types
- `Model/StoresAwareTrait.php`: `protected Collection $stores;` (`@var Collection<int, StoreInterface>`), no constructor (CB-06), typed methods (`hasStores(): bool`, `addStore(StoreInterface $store): void`, …). Remove the `@psalm-suppress InvalidArgument` at `:62-64` — it is only needed because the property type is the union `Collection|ArrayCollection`.
- `addStore()` / `removeStore()` check `hasStore()` first; `ArrayCollection::removeElement()` already returns `false` when the element is missing, so `removeStore()` can call it directly.
- `Model/StoreAwareTrait.php`: see CB-09 (type only after removing it from the Pimcore models).

### CI-04 — Exception class
- `Context/StoreNotFoundException.php`: `final` (or keep open but add named constructors), message + `?\Throwable $previous` (CB-07), named constructors such as `forRequest(Request $request)`, `forSite(int $siteId)`, `noDefaultStore()` so logs say why no store was found.
- `StoreContext.php:36-68`: the `\UnexpectedValueException` is thrown only to be converted into `StoreNotFoundException` two frames up; throw `StoreNotFoundException::...()` directly and drop the `try/catch` and both private helpers (`getMainRequest()` → `$this->requestStack->getMainRequest() ?? throw StoreNotFoundException::noRequest()`).

## Symfony 7.4 idioms

### CI-05 — Replace Laminas `PriorityQueue` with Symfony's tagged iterators
- **Where:** `Context/CompositeStoreContext.php:23-42`, `Context/RequestBased/CompositeRequestResolver.php:26-38`; compiler passes `StoreBundle/DependencyInjection/Compiler/{CompositeStoreContextPass,CompositeRequestResolverPass}.php` (+ `Component/Registry/PrioritizedCompositeServicePass`).
- `PriorityQueue::getIterator()` clones the inner `SplPriorityQueue` on every `getStore()` / `findStore()` call (`vendor/laminas/laminas-stdlib/src/PriorityQueue.php:208-212`), and the component depends on `laminas/laminas-stdlib` only for this.
- Symfony 7.4: `public function __construct(#[AutowireIterator('coreshop.context.store')] private readonly iterable $storeContexts)` (priority from the tag / `#[AsTaggedItem(priority: …)]` / `getDefaultPriority()`), or keep `addContext()` but store into a plain array sorted once (`usort` on insert, or `krsort` by priority on first use). The bundle attributes `#[AsStoreContext]` / `#[AsRequestBasedResolverStoreContext]` can become `#[AutoconfigureTag]` on the interfaces (StoreBundle **I-04**, **B-04**).
- Then drop `laminas/laminas-stdlib` from `composer.json` (CI-10).

### CI-06 — `ResetInterface` and decorator attributes
- `CachedStoreContext`, `FixedStoreContext`: implement `Symfony\Contracts\Service\ResetInterface` (CB-01). With autoconfiguration they get `kernel.reset` automatically.
- `CachedStoreContext`: the decoration can be declared in code with `#[AsDecorator(decorates: StoreContext::class)]` + `#[AutowireDecorated]` instead of YAML (`StoreBundle/Resources/config/services.yml:23-26`). Optional, only if the bundle moves to attribute-based service configuration.

## Logic / readability

### CI-07 — Unify site detection in the two admin resolvers
- **Where:** `Context/RequestBased/PimcoreAdminSiteBasedRequestResolver.php:56-75` (manual walk up the parents, one `Site::getByRootId()` lookup per level, wrapped in `try/catch`), `Context/RequestBased/PimcoreAreaBrickRenderResolver.php:44` (`Frontend::getSiteForDocument()`).
- `Pimcore\Tool\Frontend::getSiteForDocument()` (`vendor/six-it/pimcore-source/lib/Tool/Frontend.php:54-94`) uses a cached path → site mapping ordered longest-root-first, so it returns the innermost site in one lookup, handles nested sites the same way the parent walk does, and needs no `try/catch`. Use it in both resolvers; the loop, `Site` import and CB-03's swallowing disappear.
- Extract the common "document → site → store or throw" part into one private method / small helper class, so both resolvers only differ in how they find the document.

### CI-08 — Request parameter handling and route constants
- `PimcoreAdminSiteBasedRequestResolver.php:44-47`: `$request->request->get('id')` + `(int)` cast → `$request->request->getInt('id')` (what Pimcore's `saveAction()` itself uses); same for `PimcoreAreaBrickRenderResolver.php:38-41` (`getInt('documentId')`). Avoids `BadRequestException` on array input and the truthiness check on a string.
- The hard-coded route names `'pimcore_admin_document_page_save'` and `'pimcore_admin_document_page_areabrick-render-index-editmode'` → `private const` (or one shared constant class) with a comment pointing to `PageController`; consider matching the snippet/email save routes too (`pimcore_admin_document_snippet_save`, `…_email_save`), which currently fall back to the default store.
- `PimcoreAdminSiteBasedRequestResolver.php:50`: uses the `@internal` `Document\Service::getNearestDocumentByPath()` behind `@psalm-suppress InternalMethod` (CB-05). Prefer the `contentDocument` request attribute / `DocumentResolver`.
- `PimcoreAreaBrickRenderResolver.php:35-58`: three nested `if`s → guard clauses (`if (... !== ROUTE) { throw ...; }`); `if ($document)` → `instanceof Document`.

### CI-09 — Small simplifications
- `Context/SiteBasedResolver.php:31-48`: `return $this->storeRepository->findOneBySite(...) ?? $this->storeRepository->findStandard();` (null-safe: `$site !== null ? … : null`). The method name `resolveSiteWithDefaultForStore()` reads backwards ("resolve store for site, with default") → consider `resolveStoreForSite(?Site $site): ?StoreInterface` with a deprecated alias.
- `Context/RequestBased/SiteBasedRequestResolver.php:36-42`: `return $this->siteBasedResolver->... ?? throw new StoreNotFoundException();`. Same in `PimcoreAreaBrickRenderResolver.php:46-52` and `PimcoreAdminSiteBasedRequestResolver.php:62-68`.
- `Context/RequestBased/CachedStoreContext.php:36-46`: after CB-01 the method is `return $this->cachedStore ??= $this->requestBasedStoreContext->getStore();` plus miss caching.
- `Context/RequestBased/CompositeRequestResolver.php:46`: comment `//Ignore and continue` → `// not responsible, try the next resolver`, and make it consistent with `CompositeStoreContext.php:49` (`continue;`).
- `Model/Store.php:58-61`: `__toString()` prints `" ()"` for a new store (reproduced) → `sprintf('%s (%s)', $this->name ?? '', $this->id ?? 'new')`.
- Static Pimcore calls (`Site::isSiteRequest()`, `Site::getCurrentSite()`, `Document::getById()`, `Frontend::getSiteForDocument()`) make the resolvers impossible to unit-test → inject a small `SiteProviderInterface` / `DocumentProviderInterface` (StoreBundle **I-14** already has an unused `PimcoreSiteHelper` that could become it).

### CI-10 — `composer.json` does not describe what the code needs
- **Where:** `composer.json:26-57`.
- Missing direct requirements: `"php": "~8.4.0"` (root has `~8.4.0`), `symfony/http-foundation` (`Request`, `RequestStack`), `doctrine/collections` (`StoresAwareTrait`, `StoresAwareInterface`), `doctrine/orm` (`QueryBuilder` in `StoreRepositoryInterface`; today only transitively via `coreshop/resource`). After CI-05: drop `laminas/laminas-stdlib`.
- `require-dev`: `phpstan/phpstan ^1.10`, `phpstan-doctrine ^1.3.40`, `phpstan-webmozart-assert ^1.2.4` conflict with the root (`^2.1` / `^2.0`) → align to phpstan 2.x (+ `phpstan/phpstan-symfony ^2.0`).
- `"minimum-stability": "dev"` + `"repositories"` path entry are monorepo-only; check that the split package still installs stable dependencies. `extra.branch-alias` `4.1-dev` — verify against the current branch version.
- Odd indentation (`"extra"` at `:50`, repositories block) → normalise with `composer normalize`.

### CI-11 — Repository interface depends on the ORM
- **Where:** `Repository/StoreRepositoryInterface.php:20,27`.
- `createListQueryBuilder(): QueryBuilder` ties the component contract to Doctrine ORM. It is only used by admin listings. Move it to the bundle repository (or a bundle-level interface) and keep the component interface persistence-agnostic (`findOneBySite()`, `findStandard()`, plus a documented uniqueness guarantee, CC-01).

### CI-12 — Tests
- The component has no tests (only indirect Behat coverage via `Behat/Context/Setup/StoreContext.php` → `FixedStoreContext`). Add PHPUnit tests for:
  - `CompositeStoreContext` / `CompositeRequestResolver`: priority order, equal priorities (insertion order), `StoreNotFoundException` fall-through, `null` handling (CB-02).
  - `CachedStoreContext`: caches hit and miss, `reset()` (CB-01).
  - `StoreContext`: no main request, resolver returns `null`.
  - `SiteBasedResolver`: site with store, site without store → default, no site → default, no default → `null` / fallback (CC-02).
  - Admin resolvers with a mocked site/document provider (after CI-09): nested sites, site without store (CB-03), query-parameter gate (CB-04).
  - `StoresAwareTrait` (add/remove/has, no constructor, CB-06) and `StoreNotFoundException` constructor (CB-07).
- Add the component to the root PHPStan config at level max once CI-01–CI-03 are done.
