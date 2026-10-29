# StorageList Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs with a limited blast radius (one action, one configuration, one edge case) or latent bugs in public API that the shipped lists do not hit today.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Prefixes and markers as in TODO-1; StorageListBundle items are referenced as StorageListBundle **C-/B-/I-nn**.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Expiration

### CB-01 — `CustomerExpiryRepositoryTrait` produces invalid SQL `AND ()` when neither `anonymous` nor `customer` is set — (verified)
- **Where:** `Repository/CustomerExpiryRepositoryTrait.php:34-48`:
  ```php
  $sql = implode($bind, $conditions);
  $sql .= ' AND (' . implode($groupBind, $groupCondition) . ') ';
  ```
- **Trigger:** any list that uses the trait with the default `expiration.params: []` (`Bundle/StorageListBundle/DependencyInjection/Configuration.php:156`), or the wishlist command when a project config sets both flags to `false`.
- **Effect:** `modificationDate < ? AND ()` → SQL syntax error. In the maintenance job the exception is swallowed and only logged (`vendor/six-it/pimcore-source/lib/Maintenance/Executor.php:65-70`), so lists silently **never** expire.
- **Also:** `isset($params['x']) && true === $params['x']` silently ignores `'1'`, `1`, `"true"` from YAML/env; `customer__id` hard-codes the Pimcore relation column naming of the shipped class.
- **Fix:** only append the group when it is non-empty; replace the array with a typed scope (TODO-1 **CC-01**, TODO-3 **CI-10**); add a unit test for the generated condition.

### CB-02 — `StorageListExpiration::expire()` loads all expired lists at once and one failing `delete()` aborts the rest — (plausible)
- **Where:** `Expiration/StorageListExpiration.php:30-41`; `Repository/CustomerExpiryRepositoryTrait.php:50-58` (`getObjects()` without limit).
- **Effect:** a shop with tens of thousands of abandoned guest wishlists hydrates all Pimcore objects (plus relations) in one go → memory limit in the maintenance process. The first exception from `delete()` (locked object, dependency, DB error) stops the loop; the remaining lists stay and the same failure repeats on every run. Nothing is logged about how many lists were removed.
- **Also:** `$days <= 0` returns silently — the default `ExpireTask` `$days = 0` (`Maintenance/ExpireTask.php:28`) makes a misconfigured task a no-op without any notice.
- **Fix:** iterate in batches (`setLimit()`/`setOffset()` or id cursor, `\Pimcore::collectGarbage()` between batches); catch + log per list and continue; return/log the count; inject a `LoggerInterface`; log a warning for `$days <= 0`.

## Manager

### CB-03 — `StorageListPimcoreModelManager::persist()` renames and re-parents **every** item on **every** persist and saves items twice — (verified)
- **Where:** `Manager/StorageListPimcoreModelManager.php:64-83`.
  - `:65-72` runs for all items, not only new ones: `setParent(createFolderForResource(...))` and `setKey(uniqid((string) ($index + 1), true))`.
  - `:81-83` saves all items a second time.
  - `:40-43` creates/loads the `storage-list/Y/m/d` folder on every call, even for existing lists that already have a parent.
- **Effect:** adding one product to a wishlist with 30 items performs ≥ 61 object saves, renames 30 objects (new keys → new full paths → path updates in `objects`, dependency table, search backend / data-hub indexes, admin tree open in another tab shows stale paths). Item keys are never stable, so any reference by path breaks after the next change. Saves happen with versioning disabled, so the churn is invisible in the history.
- **Fix:** only set parent + key when `$item->getId() === null` (or the parent is not below the list); save each item once; resolve the date folder only when the list has no id. See TODO-3 **CI-09** for the full rewrite.

### CB-04 — `persist()` of a new list is not atomic and can leave versioning disabled globally — (plausible) (dependent)
- **Where:** `Manager/StorageListPimcoreModelManager.php:45-86`; `Component/Pimcore/DataObject/VersionHelper.php` (`useVersioning()` restores `Version::$disabled` without `try/finally`).
- **Effect:**
  - New list: `setItems([])` → `save()` (list exists, empty) → items are saved one by one → `setItems($tempItems)` → `save()`. Any exception in between leaves an **empty** list in the DB and the already-saved items orphaned in its folder; the session already points to the empty list on the next response.
  - Any exception inside the closure skips the restore in `VersionHelper` → `Version::disable()` stays active for the rest of the request (or worker lifetime, see TODO-1 **CC-03**) → later admin saves in the same process create no versions.
- **Fix:** wrap in a DB transaction (`Pimcore\Db::get()->transactional(...)`); `try/finally` in `VersionHelper::useVersioning()` (dependent: Pimcore component); throw a specific exception type instead of `\Exception` (`:37`).

### CB-05 — Items removed through `SimpleStorageListModifier` / `SessionStorageListModifier` are only unlinked, never deleted — (verified)
- **Where:** `SimpleStorageListModifier.php:41-44`, `SessionStorageListModifier.php:39-44`, `Model/StorageList.php:57-74`. Compare `Component/Wishlist/Wishlist/WishlistModifier.php` (calls `$item->delete()`).
- **Effect:** any list that uses the default modifier (`services.modifier` defaults to `StorageListModifierInterface` → `SimpleStorageListModifier`, `Bundle/StorageListBundle/Resources/config/services.yml:17-18`) accumulates orphaned item objects below `/…/storage-list/…` forever; expiration deletes the list folder only if items are children of the list (true only for items saved through `StorageListPimcoreModelManager`).
- **Fix:** let the manager handle deletion (`StorageListManagerInterface::remove(StorageListItemInterface)` or delete items that are no longer in `getItems()` during `persist()`), so modifiers stay persistence-agnostic.

## Contexts / session storage

### CB-06 — `SessionAndStoreBasedStorageListContext`: a deleted list id is never removed from the session, and the list owner is not checked — (verified) (StorageListBundle **B-07**, **B-12**)
- **Where:** `Core/Context/SessionAndStoreBasedStorageListContext.php:49-70`.
  - `:57-60`: when the repository returns `null` (list deleted by expiration, admin or `StorageMultiListController` delete), the context throws **before** `removeForContext()` (`:67`) → the stale id stays in the session and costs one DB lookup per context resolution for the rest of the session.
  - The list's customer is never compared with the current customer. A session that still points to customer A's list (logout without session invalidation in another store, StorageListBundle **B-12**) serves A's list to the next anonymous visitor.
  - `:46` calls `$this->storeContext->getStore()` a second time **outside** the `try` (`:39-43`); use `$store`.
- **Fix:** call `removeForContext()` for every "not found / not valid" path; when the list is `CustomerAwareInterface` and has a customer, require the current customer (inject `CustomerContextInterface` or pass it via the context array) to match, otherwise drop the key and throw.

### CB-07 — `SessionStorageListStorage` mixes main-request and current-request sessions and throws when no session exists — (verified)
- **Where:** `Core/Storage/SessionStorageListStorage.php`.
  - `:89-92` `hasSession()` checks `getMainRequest()?->hasSession()`, but `:84-87` `getSession()` uses `RequestStack::getSession()`, which reads the **current** request (`vendor/symfony/http-foundation/RequestStack.php`) → in a sub-request without session (ESI fragment, `render(controller())` with a new request, stateless API) → `SessionNotFoundException`.
  - `:79-82` `removeForContext()` has no `hasSession()` guard at all (called by `SessionStoreStorageListLogoutSubscriber`, `StorageMultiListController`, CB-06) → `SessionNotFoundException` in CLI / stateless contexts.
  - `:72-74` `setForContext()` silently does nothing for unsaved lists; callers (multi-list controller "select list") report success.
  - `:56` `is_int()` rejects ids that were stored as strings by older code / other session handlers → the list is "lost" without cleanup.
- **Fix:** use one source (`$request = $this->requestStack->getMainRequest(); $request?->hasSession() ? $request->getSession() : null`) everywhere; guard `removeForContext()`; throw (or return `bool`) in `setForContext()` for unsaved lists; accept numeric strings (`filter_var(..., FILTER_VALIDATE_INT)`) and remove invalid values.

### CB-08 — `SessionBasedListContext` crashes without a session and ignores the store — (verified)
- **Where:** `Context/SessionBasedListContext.php:43-57`.
  - `:49` `$request->getSession()` throws `SessionNotFoundException` for a main request without session (stateless firewall, API routes, `_stateless: true`) instead of falling back to `$this->inner`.
  - `:51,56` the session key has no store suffix: in a multi-store setup a non-Core list created in store A is returned in store B.
  - `:59-60` a stale id (list deleted) is never removed from the session (same as CB-06).
  - Result caching: TODO-1 **CC-03**.
- **Fix:** `if (!$request?->hasSession()) { return $this->inner->getStorageList(); }`; remove stale ids; document that the class is store-agnostic or add a key strategy.

### CB-09 — `CustomerAndStoreBasedStorageListContext` throws the Order component's `CartNotFoundException` for every list type — (verified)
- **Where:** `Core/Context/CustomerAndStoreBasedStorageListContext.php:23,45-50`.
- **Effect:** for the **wishlist** (and any custom list) the log / exception says "CustomerAndStoreBasedCartContext can only be applied in coreshop_login_check route." It only works because `CartNotFoundException` happens to extend `StorageListNotFoundException` (`Component/Order/Context/CartNotFoundException.php:23`). The component gets a hard runtime dependency on `coreshop/order` (not declared, TODO-3 **CI-01**).
- **Also:** the route name `coreshop_login_check` is hard-coded (projects with their own login route never restore the customer's list when `restore_customer_list_only_on_login: true`); in CLI / without a main request the restriction is silently skipped.
- **Fix:** throw `StorageListNotFoundException`; make the route name(s) a constructor argument (config `context.restore_routes`); skip (throw not-found) when there is no main request and the restriction is enabled.

### CB-10 — `StoreBasedStorageListContext` rejects lists that are not `CustomerAwareInterface` and says "wishlist" for every list — (verified)
- **Where:** `Core/Context/StoreBasedStorageListContext.php:49-61`.
- **Effect:** the decorator only needs `StoreAwareInterface`, but `:53-55` also requires `CustomerAwareInterface` → a store-scoped but customer-less list (e.g. a shared "compare" list) makes the **factory fallback** throw → the composite context throws `StorageListNotFoundException` → controllers 500. The message at `:60` ("prepare the wishlist") is wrong for the cart and custom lists. Exceptions without message (`:50`, `:54`) give no hint which interface is missing.
- **Fix:** require `StoreAwareInterface` only (the provider already checks `CustomerAwareInterface` itself, `Core/Provider/CoreContextProvider.php:51`); generic message with the list class.

### CB-11 — `CoreContextProvider` catches a missing store but not a missing currency — (plausible)
- **Where:** `Core/Provider/CoreContextProvider.php:38-49`; `Component/Core/Context/ShopperContext.php:66-69` (`getCurrency()` delegates and throws `CurrencyNotFoundException`).
- **Effect:** a store without a valid currency for the current country (misconfigured store, geo-IP country not in any zone) → `CurrencyNotFoundException` escapes from the factory fallback → not a `StorageListNotFoundException` → `CompositeStorageListContext` (`Context/CompositeStorageListContext.php:45-51`) does not catch it → the wishlist/cart 500s on **every** page via the response subscribers (StorageListBundle **B-11**).
- **Fix:** use `$this->shopperContext->hasCurrency()` before `getCurrency()` (same pattern as `hasCustomer()` at `:51`); remove the empty `catch` at `:42-43` in favour of `hasStore()`.

### CB-12 — `StorageListFactoryContext` overwrites the high-entropy key from `StorageListFactory` with a low-entropy `uniqid()` — (plausible)
- **Where:** `Context/StorageListFactoryContext.php:39-42` vs. `Factory/StorageListFactory.php:42-45`. For the wishlist the factory passed in is the decorated `StorageListFactory` (`Bundle/WishlistBundle/Resources/config/services.yml:24-30`), so the key is set twice.
- **Effect:** `uniqid()` without `more_entropy` is only the hex microtime; two PHP workers / servers creating a list in the same microsecond produce the same key in the same `storage-list/Y/m/d` folder → Pimcore "Duplicate full path" exception on the second save → add-to-wishlist fails for that visitor. `StorageListItemFactory.php:46` has the same pattern (overwritten later by the manager, CB-03).
- **Also:** `StorageListFactory::createNew()` uses the prefix `'wishlist'` for **every** list type (`:43`).
- **Fix:** set the key in exactly one place (the factory) using `Symfony\Component\Uid\Uuid::v7()` / `bin2hex(random_bytes(8))`; prefix with the list name or nothing.

## Models / modifiers (public API, base classes)

### CB-13 — Abstract `Model\StorageListItem` cannot be used as-is: uninitialised properties and nullability contradicting the interface — (reproduced)
- **Where:** `Model/StorageListItem.php:25-71`.
- **Reproduced (PHP 8.4.27, anonymous subclass):**
  - `getId()` on a new item → `Error: Typed property …::$product must not be accessed before initialization` (`:27`, `:40`); same for `equals()` (`:35`) — so `SimpleStorageListModifier::addToList()` crashes on the first item without product.
  - `setQuantity(null)` → `TypeError: Cannot assign null to property …::$quantity of type float` although the interface declares `?float` (`StorageListItemInterface.php:38`).
  - `equals()` with a product-less argument → `$this->getProduct() instanceof $product` with `$product === null` → `Error: Class name must be a valid object or a string`.
  - `getProduct(): ResourceInterface` (non-null) vs. interface `?ResourceInterface`; `getStorageList(): StorageListInterface` on an uninitialised property → `Error` instead of `null` (interface `?StorageListInterface`).
  - `getId()` returns the **product** id, not the item id → `StorageList::removeItem()` / `hasItem()` (CB-14) compare product ids for these items, but object ids for Pimcore items.
- **Note:** no class in the repo extends it (Wishlist/Order items extend `AbstractPimcoreModel`), so the shipped lists are not affected — but it is public API of a library package.
- **Fix:** `protected ?ResourceInterface $product = null; protected ?StorageListInterface $storageList = null; protected ?float $quantity = 0.0;`, match the interface return types, compare products with `$product !== null && $this->product?->getId() === $product->getId() && $this->product::class === $product::class`; add a real `$id`. Or delete the class (TODO-3 **CI-08**).

### CB-14 — `StorageList::removeItem()` / `hasItem()` treat all unsaved items as equal (`null === null`) — (reproduced) (dependent)
- **Where:** `Model/StorageList.php:57-91`; same code copied to `Component/Wishlist/Model/Wishlist.php:54-84` **(dependent)**.
- **Reproduced:** list with two unsaved items `a`, `b` (`getId()` → `null`); `removeItem($b)` removes **`a`**.
- **Effect:** `hasItem()` of any unsaved item returns `true` as soon as the list contains one unsaved item; removing an unsaved item removes the first unsaved item instead. Happens with the default `SimpleStorageListModifier` flow before the first `persist()`, and in code that builds lists in memory (imports, tests, Behat `CartContext`).
- **Fix:** compare by identity first (`$arrayItem === $item`), then by id only when both ids are non-null; use PHP 8.4 `array_find_key()` (TODO-3 **CI-12**). `removeItem()` does not call `Assert` on the array elements; `getItems()` may be `null` in Pimcore implementations (`foreach (null)` warning) — use `$this->getItems() ?? []`.

### CB-15 — Quantity cannot be set to `0` and negative / float rounding is silently ignored — (reproduced)
- **Where:** `StorageListItemQuantityModifier.php:25-33`; `SimpleStorageListModifier.php:48-56`.
- **Reproduced:** item with quantity `3.0`, `modify($item, 0)` → quantity stays `3`, no error.
- **Effect:** this class is the default `coreshop.cart_item.quantity_modifier` of OrderBundle (`Bundle/OrderBundle/Resources/config/services.yml:35-36`, consumed by `Form/DataMapper/CartItemQuantityDataMapper.php`) when CoreBundle's own `Component/Core/Order/Modifier/CartItemQuantityModifier.php` (which has an explicit `allowZeroQuantity` switch, `:47`) is not used; callers that "set quantity to 0" get a silent no-op; a negative value from a crafted request is silently dropped instead of rejected. `$currentQuantity === $targetQuantity` compares floats exactly (`0.1 + 0.2 !== 0.3`). `SimpleStorageListModifier::resolveItem()` adds `getQuantity()` values that may be `null` (interface `?float`).
- **Fix:** throw `\InvalidArgumentException` for `< 0`, delegate `0` to the modifier's `removeFromList()` (or document "0 is ignored" in the interface); compare with a tolerance or use decimals (TODO-3 **CI-17**); `($item->getQuantity() ?? 0.0) + ($storageListItem->getQuantity() ?? 0.0)`.

### CB-16 — `WishlistItem::getStorageList()` declares a non-nullable return but returns a nullable relation — (verified) (dependent) (StorageListBundle **B-02**)
- **Where:** `Component/Wishlist/Model/WishlistItem.php:29-32` (`: StorageListInterface` returning `getWishlist(): ?WishlistInterface`, `:44`); interface `Model/StorageListItemInterface.php:40` allows `null`.
- **Effect:** an orphaned wishlist item (list deleted / relation removed in admin) → `TypeError` instead of `null` → the bundle's null-safe fix for **B-02** (`getStorageList()?->getId()`) still crashes.
- **Fix (dependent):** return `?StorageListInterface` in `WishlistItem`; component side: document the nullability in the interface (already correct) and add a contract test.

## Small services

### CB-17 — `StorageListsManager` getters crash with a PHP warning + `TypeError` for unknown names; duplicate names are listed twice — (reproduced)
- **Where:** `StorageListsManager.php:33-63`.
- **Reproduced:** `getManager('nope')` → `Warning: Undefined array key "nope"` + `TypeError: … Return value must be of type StorageListManagerInterface, null returned`. `addList()` twice with the same name appends it twice to `$lists` (`:39`) but overwrites the maps.
- **Fix:** `hasList(string $name): bool`; throw a dedicated `StorageListNotRegisteredException` naming the known lists; key `$lists` by name (`$this->lists[$name] = $name`, `array_keys()` in `getLists()`).

### CB-18 — `AddToStorageListFactory` crashes with `TypeError` for an unknown class and re-validates on every call — (reproduced)
- **Where:** `Factory/AddToStorageListFactory.php:35-46`.
- **Reproduced:** class `Nope\Cls` → `Warning: class_implements(): Class Nope\Cls does not exist` + `TypeError: in_array(): Argument #2 ($haystack) must be of type array, false given` instead of the intended `\InvalidArgumentException`.
- **Fix:** validate once in the constructor with `is_a($class, AddToStorageListInterface::class, true)`; type the property `class-string<AddToStorageListInterface>`; rename `$addToWishlistClass` → `$addToStorageListClass` (the factory is generic).

### CB-19 — `PimcoreListResolver::getStorageLists()` throws for guests and depends on a broken repository query for customers — (plausible) (dependent)
- **Where:** `Core/Context/PimcoreListResolver.php:35-47`; `Bundle/CoreBundle/Pimcore/Repository/WishlistRepository.php:36` **(dependent)**.
- **Problems:**
  - Guests: `[$this->storageListContext->getStorageList()]` can throw `StorageListNotFoundException` (undocumented in `Resolver/StorageListResolverInterface.php:25-28`) and returns an **unsaved** list for a guest who has none — the multi-list choice (`StorageListChoiceType`) then offers a list without id.
  - `$context['store']` / `$context['customer']` are only checked for existence, not type → a wrong value passed by a caller gives a `TypeError` deep in the repository.
  - Customers (wishlist with `multi_list.enabled`): `WishlistRepository::findNamedStorageLists()` orders by `o_creationDate`; the `o_` column prefix no longer exists in `six-it/pimcore-source` 2.x (no occurrence in `vendor/six-it/pimcore-source/{models,lib}`), so `setOrderKey()` quotes it and MySQL fails with "Unknown column" → the multi-list page 500s for every logged-in customer. The sibling method `findLatestByStoreAndCustomer()` (`:44`) already uses `creationDate`.
- **Fix:** catch `StorageListNotFoundException` → `[]`, filter out lists without id; `Assert::isInstanceOf($context['store'], StoreInterface::class)` (and customer); dependent: `setOrderKey('creationDate')` in CoreBundle `WishlistRepository`.

### CB-20 — `SimpleStorageListStorage` ignores the context and uses the hard-coded key `coreshop.cart` — (verified) (StorageListBundle **B-14**)
- **Where:** `Storage/SimpleStorageListStorage.php:25-57`.
- **Effect:** `hasForContext()` / `getForContext()` return the same list for every store / customer context; `:29-33` `isset()` followed by `array_key_exists()` is redundant (the second check can never be false). For a non-Core list with multi-list enabled, the "selected list" is forgotten on the next request (request-scoped memory).
- **Fix:** key by a context hash (`store` id, `customer` id) and the list name passed in the constructor; document it as "in-memory only, for tests / CLI".
