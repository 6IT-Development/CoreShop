# StorageList Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items. None of them is a functional bug on its own.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Prefixes and markers as in TODO-1; StorageListBundle items are referenced as StorageListBundle **C-/B-/I-nn**.

**Already fine for Symfony 7.4 / PHP 8.4:** every file has `declare(strict_types=1)`; no implicitly nullable parameters; most services already use constructor property promotion; `UniqueTokenGenerator` (used by `StorageListFactory`) is based on `random_int()` (CSPRNG).

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Package / architecture

### CI-01 — `composer.json` does not describe what the code needs (StorageListBundle **I-01**)
- **Where:** `composer.json:26-34`.
- **Fix:**
  - Add `"php": "^8.4"`.
  - Undeclared direct dependencies of the generic part: `symfony/http-foundation` (`RequestStack`, sessions), `laminas/laminas-stdlib` (`PriorityQueue`, `Context/CompositeStorageListContext.php:22`), `webmozart/assert`, `nesbot/carbon` (`Repository/CustomerExpiryRepositoryTrait.php:21`), `coreshop/pimcore` (`VersionHelper`, `Manager/StorageListPimcoreModelManager.php:21`). `six-it/pimcore-source` is declared but `Pimcore\Maintenance\TaskInterface`, `Pimcore\Http\RequestHelper`, `Pimcore\Model\DataObject\Listing` should be listed as the reason.
  - `Core/**` uses `coreshop/core` (`ShopperContextInterface`, Core `StoreInterface`), `coreshop/customer`, `coreshop/store`, `coreshop/currency` and `coreshop/order` (`CartNotFoundException`) — none declared. Either declare them in `suggest` and document that `Core/**` is optional, or move `Core/**` to `Component/Core` (CI-02).
  - `require-dev` PHPStan 1.x → PHPStan 2.x (PHP 8.4 rules) together with the other components; `minimum-stability` / `repositories` belong to the monorepo root.

### CI-02 — Separate the generic component from the Core integration
- **Where:** `Core/{Context,Provider,Repository,Storage}/*`; generic classes that already depend on Pimcore: `Manager/StorageListPimcoreModelManager.php`, `Context/StorageListFactoryContext.php:39-42`, `Factory/*Factory.php` (`AbstractPimcoreModel`), `Maintenance/ExpireTask.php`, `Repository/CustomerExpiryRepositoryTrait.php`.
- **Problem:** the "generic" namespace imports Store/Customer/Core/Order classes, so the component cannot be installed alone; the Pimcore specifics are spread over generic factories via `instanceof AbstractPimcoreModel`.
- **Fix:** keep interfaces + pure PHP in `StorageList\`; put Pimcore glue in `StorageList\Pimcore\` (factory decorator that sets key/published, manager, expiry trait, maintenance task) and Store/Customer glue in `Component/Core/StorageList\` (dependent). Replace `CartNotFoundException` with `StorageListNotFoundException` (TODO-2 **CB-09**).

### CI-03 — Remove duplicated, dead and legacy classes
- **Duplicates:**
  - Two `StorageListManagerInterface`s: `StorageListManagerInterface.php` (used by everyone) and `Manager/StorageListManagerInterface.php` (not implemented or referenced anywhere). Delete the `Manager\` one; `Manager/StorageListPimcoreModelManager.php:25` imports the root one from a sibling namespace, which is confusing.
  - `StorageListItemResolver` and `StorageListItemModelEqualsResolver` are byte-for-byte identical (`equals()` → `$itemA->equals($itemB)`); keep one.
  - `Context/StorageListFactoryContext.php:32-45` duplicates `Factory/StorageListFactory.php:35-53` (key + published), see TODO-2 **CB-12**.
- **Unused in the repository (public API → deprecate first):** `SessionStorageManager` (only as broken config default, TODO-1 **CC-04**), `SessionStorageListModifier`, `StorageListsManager` (filled by the bundle, never read — StorageListBundle **I-08**), `Model/StorageList`, `Model/StorageListItem` (TODO-2 **CB-13**, **CB-14**), `Repository/PimcoreStorageListRepositoryInterface` is only an alias of two interfaces.
- **Fix:** mark with `@deprecated` + `trigger_deprecation('coreshop/storage-list', '4.x', …)` in the constructor (PHP 8.4 `#[\Deprecated]` is not allowed on classes), remove in the next major.

## Types and PHP 8.4 language features

### CI-04 — Native types on every interface and method
- **Where / fix:**
  - `Model/StorageListInterface.php:27-35`: `setItems(?array $items)` without return type; `addItem($item)`, `removeItem($item)`, `hasItem($item)` untyped → `StorageListItemInterface $item` (BC: implementations may widen, so the interface can be narrowed only in a major; add `@param` now). `getItems(): ?array` → `array` (never `null` in the base class; Pimcore returns `[]` for empty relations).
  - `Model/StorageListItemInterface.php:23-25,34`: `@method setProduct($product)` and the commented-out `//    public function setProduct($product);` → real method `setProduct(?ResourceInterface $product): void` (all implementations have it).
  - Setters without return type (`setQuantity`, `setStorageList`, `NameableStorageListInterface::setName`, `TokenAwareStorageListInterface::setToken`) → `: void` (or `: static`), in the next major.
  - `Factory/StorageListFactoryInterface.php:25` `createNewNamed(string $name)` and `Factory/StorageListFactory.php:35`, `Factory/StorageListItemFactory.php:33` `createNew()` without return type → `StorageListInterface` / `StorageListItemInterface` with `@template` generics.
  - `Context/StorageListNotFoundException.php:23-26`: `$message = null` untyped and `?\Exception` → `?string $message = null, ?\Throwable $previous = null`.
  - `StorageListManagerInterface.php:25` remove the commented-out `/*, array $params = []*/`.
  - `Repository/CustomerExpiryRepositoryTrait.php:61-64` `abstract public function getList();` with `@return Listing` → native `: Listing`.
  - Arrays: add PHPStan array shapes (`array{store: StoreInterface, customer?: CustomerInterface|null, currency?: CurrencyInterface}`) for every `array $context` and `list<StorageListInterface>` for repository returns.

### CI-05 — `readonly`, `final`, and asymmetric visibility for services and DTOs
- **Fix:**
  - Stateless services → `final readonly class` (PHP 8.2+): `StorageListFactoryContext`, `StorageListExpiration`, `ExpireTask`, `StorageListPimcoreModelManager`, `CoreContextProvider`, `SessionAndStoreBasedStorageListContext`, `CustomerAndStoreBasedStorageListContext`. Extensible ones (`PimcoreListResolver` — extended by `CartResolver`/`WishlistResolver`, `SessionStorageListStorage`, `StorageListFactory`) → `readonly` promoted properties without `final`.
  - `DTO/AddToStorageList.php`: the setters are needed for Symfony Form property mapping, so PHP 8.4 asymmetric visibility (`public private(set)`) does not fit here; keep the setters and mark the class `final` (or document that projects may extend it via `coreshop.storage_list.add_to_list_class`).
  - `Context/CompositeStorageListContext.php:31` `protected PriorityQueue $contexts` → `private readonly`.
  - `SimpleStorageListModifier.php:26-34` builds its collaborators with `new` in the constructor → inject `StorageListItemQuantityModifierInterface` and `StorageListItemResolverInterface` (with `new` in initializers as defaults: `private readonly StorageListItemResolverInterface $resolver = new StorageListItemResolver()`); properties typed with the **concrete** classes today.
  - Odd empty constructors `public function __construct(\n        ) {` in `CompositeStorageListContext.php:33-36`, `SimpleStorageListModifier.php:30-34`, `Model/StorageList.php:27-30` → property initialisers (`private array $items = [];`) and drop the constructor.

### CI-06 — A typed value object instead of `array $context`
- **Where:** `Core/Storage/SessionStorageListStorage.php:94-104`, `Core/Context/PimcoreListResolver.php:35-61`, `Core/Context/SessionAndStoreBasedStorageListContext.php:45-47`, `Provider/ContextProviderInterface.php:27`, `Storage/StorageListStorageInterface.php`, `Resolver/StorageListResolverInterface.php`.
- **Problem:** every consumer re-validates `store` / `customer` with `Assert::keyExists()` or not at all, and uses inline `@var` casts.
- **Fix:** `final readonly class StorageListContext { public function __construct(public StoreInterface $store, public ?CustomerInterface $customer = null, public ?CurrencyInterface $currency = null) {} }` (+ `fromArray()` for BC); the storage key strategy becomes `StorageListContext::sessionKey(string $prefix)`.

### CI-07 — Exceptions
- **Fix:**
  - `Manager/StorageListPimcoreModelManager.php:37` `throw new \Exception(...)` → `\InvalidArgumentException` / own `UnsupportedStorageListException` (catching `\Exception` is too broad).
  - Exceptions without message (`Core/Context/SessionAndStoreBasedStorageListContext.php:59`, `Core/Context/StoreBasedStorageListContext.php:50,54`) → say which interface is missing on which class.
  - Use named arguments when constructing (`new StorageListNotFoundException(previous: $e)`) and keep `$exception->getMessage()` as `previous` context only.

### CI-08 — Models: decide on the abstract base classes
- **Where:** `Model/StorageList.php`, `Model/StorageListItem.php`.
- **Fix (if kept):** fix TODO-2 **CB-13** / **CB-14**; `hasItems()` → `[] !== $this->items`; `count()` already correct; typed `list<StorageListItemInterface>` items; remove the redundant `$arrayItem = $iValue;` (`:64`, `:83`); `addItem()` / `removeItem()` share `Assert` → one private guard. PHP 8.4 property hooks can replace getter/setter pairs in `StorageListItem` (`public float $quantity { set => max(0.0, $value); }`) once the interface no longer requires methods.
- **Fix (if dropped):** see CI-03.

## Manager / expiration

### CI-09 — Rewrite `StorageListPimcoreModelManager::persist()` for clarity and cost (TODO-2 **CB-03**, **CB-04**)
- **Where:** `Manager/StorageListPimcoreModelManager.php:34-87`.
- **Fix:**
  - Split into `persistNewList()`, `persistItem()`; only new items get parent/key/published; deterministic item key (`sprintf('%s-%d', $productId, $i)` or UUID) instead of `uniqid((string) ((int) $index + 1), true)` (the `(int)` cast of an already-int index is noise).
  - Inject a PSR-20 `ClockInterface` (`symfony/clock`) instead of `date('Y/m/d')` for testable folder names.
  - Remove `@psalm-suppress DocblockTypeContradiction` (`:51-54`) by typing the `getParent()` check properly (`instanceof AbstractObject`).
  - Replace the inline `@var AbstractPimcoreModel` loops (`:61-63`, `:78-80`) with `Assert::allIsInstanceOf()`.
  - Transaction + `try/finally` around versioning (TODO-2 **CB-04**).
  - Add `remove(StorageListInterface)` to the manager contract (StorageListBundle **B-07**), so controllers do not call `delete()` directly.

### CI-10 — Expiration API: typed criteria instead of `int $days, array $params`
- **Where:** `Expiration/StorageListExpirationInterface.php:23`, `Repository/ExpireAbleStorageListRepositoryInterface.php:25`, `Repository/CustomerExpiryRepositoryTrait.php`, `Maintenance/ExpireTask.php`.
- **Fix:** `enum ExpirationScope: string { case Anonymous = 'anonymous'; case Customer = 'customer'; case All = 'all'; }` + `final readonly class ExpirationCriteria(int $days, ExpirationScope $scope, ?\DateTimeImmutable $now = null)`; keep the array signature as a BC adapter that maps and validates keys (TODO-1 **CC-01**, **CC-02**, TODO-2 **CB-01**). `Carbon::now()->subDays()` → injected clock. `ExpireAbleStorageListRepositoryInterface` → `ExpirableStorageListRepositoryInterface` (spelling) in the next major. `findExpiredStorageLists()` should return an `iterable` (generator over batches, TODO-2 **CB-02**).

## Contexts / storage

### CI-11 — Context resolution readability
- **Fix:**
  - `Context/CompositeStorageListContext.php`: replace `Laminas\Stdlib\PriorityQueue` with a Symfony tagged iterator (`#[AutowireIterator(tag, defaultPriorityMethod: …)]`) built by the bundle, or with a plain sorted array — removes the laminas dependency and the `@psalm-var` / `@var PriorityQueue|StorageListContextInterface[]` union docblock (`:26-30`).
  - `Context/SessionBasedListContext.php:53-63` two nested if/else branches → early returns.
  - `Core/Context/SessionAndStoreBasedStorageListContext.php:53-70`: `@var StorageListInterface|null` docblock + `instanceof StoreAwareInterface` + separate `null` check → one guard clause; reuse `$store` (TODO-2 **CB-06**).
  - Document the resolution order and priorities (session −555 > customer/store −777 > factory −999, set in the bundle) in `StorageListContextInterface`.
  - `Core/Context/PimcoreListResolver.php` is a resolver, not a context → move to `Core/Resolver/` (keep a `class_alias` for BC).
  - Stateful contexts implement `ResetInterface` (TODO-1 **CC-03**).

### CI-12 — Use PHP 8.4 array functions for item lookups
- **Where:** `Model/StorageList.php:63-90`, `SimpleStorageListModifier.php:48-59`, `Component/Wishlist/Model/Wishlist.php:54-84` **(dependent)**, `Component/Wishlist/Wishlist/WishlistModifier.php` **(dependent)**.
- **Fix:** `array_find($items, fn (StorageListItemInterface $i) => $this->resolver->equals($i, $new))`, `array_find_key()` for removal, `array_any()` for `hasItem()` — shorter and makes the identity-vs-id comparison of TODO-2 **CB-14** explicit in one place.

### CI-13 — Session storage key handling
- **Where:** `Core/Storage/SessionStorageListStorage.php:94-104`, `Context/SessionBasedListContext.php:51`, `Storage/SimpleStorageListStorage.php`.
- **Fix:** one `SessionKeyStrategy` (prefix + store id, optional customer id) shared by storage, logout listener (StorageListBundle **B-12**: "remove all keys with prefix") and the non-Core session context; `sprintf('%s.%s', …)` → string interpolation; type the store as `Store\Model\StoreInterface` (only `getId()` is needed) instead of `Core\Model\StoreInterface` (`:21`).

## Smaller items

### CI-14 — Token generation
- **Where:** `Factory/StorageListFactory.php:47-50`.
- **Fix:** inject the generator (testability) or use `bin2hex(random_bytes(16))` / `Symfony\Component\Uid\Uuid::v4()`; the token is a share secret, so make the length a named constant and check uniqueness via `ShareableStorageListRepositoryInterface::findByToken()` on collision. Align the vendored test-project copy (10 chars, see TODO-1 header).

### CI-15 — Docblocks and comments
- **Fix:**
  - Inline `/** @var X $y */` casts before method calls (`Context/StorageListFactoryContext.php:34-36`, `Core/Context/StoreBasedStorageListContext.php:44-46`, `Factory/StorageListFactory.php:37-39,57-59`, `Factory/StorageListItemFactory.php:40-42`, `Core/Storage/SessionStorageListStorage.php:98-100`, `Model/StorageList.php:44-46`) → `assert()` / `Assert::isInstanceOf()` so the claim is checked.
  - `Factory/AddToStorageListFactory.php:27-29` `@psalm-param class-string` → native property doc `class-string<AddToStorageListInterface>`.
  - `Provider/ContextProvider.php:27` `//Nothing to provide here` → class docblock explaining it is the no-op default for non-Core lists.
  - Add a class docblock to every interface describing its role (contexts vs. storage vs. manager vs. modifier is not obvious from names).

### CI-16 — Naming
- **Fix:** `SessionBasedListContext` → `SessionBasedStorageListContext` (consistent with the others); `$storageListItemFinder` (`SimpleStorageListModifier.php:28`) holds a resolver → `$itemResolver`; `$addToWishlistClass` → `$addToStorageListClass`; `$iValue` / `$arrayItem` in `Model/StorageList.php` → `$existing`; `ExpireAbleStorageListRepositoryInterface` (CI-10).

### CI-17 — Quantity as decimal, not float
- **Where:** `StorageListItemQuantityModifierInterface.php:25`, `Model/StorageListItemInterface.php:36-38`, `Model/StorageListItem.php:25`.
- **Fix:** document the precision (cart quantities are rounded by the Core modifier to the unit precision); compare with a tolerance or use integer "smallest unit" / `BcMath\Number` (PHP 8.4) in the next major (TODO-2 **CB-15**).

### CI-18 — Tests and documentation
- **Fix:**
  - The component has **no tests**. Add PHPUnit tests for: `CompositeStorageListContext` ordering/fallback, `SessionAndStoreBasedStorageListContext` (stale id, wrong store, wrong customer), `SessionStorageListStorage` without session, `CustomerExpiryRepositoryTrait` condition builder (all flag combinations), `StorageListPimcoreModelManager` (keys stable across two persists), `StorageList` add/remove/has with unsaved items, quantity modifier edge cases, `StorageListsManager` unknown names, `AddToStorageListFactory` invalid class.
  - `README.md` is the generic CoreShop template: document the building blocks (context → storage → manager → modifier → resolver), the context resolution order, the expiration params and how to add a custom list.
