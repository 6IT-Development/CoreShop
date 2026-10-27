# StorageListBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional defects that are not "critical" by the definition in TODO-1 (limited impact, specific configuration or rare input needed).
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed in the compiled container or vendor source.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Controllers

### B-01 — Removing a list item is a state change on `GET` without CSRF token
- **Where:** `Controller/StorageListController.php:171-195` (`removeItemAction`); route `coreshop_wishlist_remove` in `FrontendBundle/Resources/install/pimcore/staticroutes.yml:278-283` has no method restriction.
- **Impact:** any third-party page can empty a logged-in visitor's wishlist with `<img src="https://shop/en/shop/wishlist/remove/123">` (item ids are sequential Pimcore object ids). Prefetching browsers / crawlers following links can also remove items.
- **Fix:** require `POST`/`DELETE` and a CSRF token (`$this->isCsrfTokenValid('remove-item-' . $id, $request->request->getString('_token'))`, or `#[IsCsrfTokenValid]` in Symfony 7.1+); restrict the static route to `POST` (dependent: FrontendBundle templates must send a form instead of a link).

### B-02 — `removeItemAction`: an item without a list crashes with `Error: Call to a member function getId() on null`
- **Where:** `Controller/StorageListController.php:183`: `$storageListItem->getStorageList()->getId()`; `StorageListItemInterface::getStorageList()` is `?StorageListInterface` (`Component/StorageList/Model/StorageListItemInterface.php:40`).
- **Impact:** orphaned items (list deleted by expiration task / admin, item left over) → 500 instead of redirect.
- **Also:** `:187` the success flash is added **before** the removal; `:192` `$request->attributes->set('product', …)` is dead code (nothing reads it after the redirect); `find()` with a non-numeric/array `item` parameter is not validated.
- **Fix:** `if ($storageListItem->getStorageList()?->getId() !== $storageList->getId())`; flash after `persist()`; drop the dead line; cast/validate the id (`(int)` + `> 0`).

### B-03 — `addItemAction` persists the context list instead of the list from the submitted DTO — (plausible)
- **Where:** `Controller/StorageListController.php:119-122`: `addToList($addToStorageList->getStorageList(), …)` but `$this->manager->persist($storageList)` (the context list from `:90`).
- **Impact:** an `add_type` form that maps `storageList` (choose target list — the multi-list pattern used for carts) adds the item to list B but saves list A → item lost / never saved. The default `AddToWishlistType` only maps `storageListItem`, so the default wishlist is not affected today.
- **Fix:** `$this->manager->persist($addToStorageList->getStorageList());`.

### B-04 — `addItemAction` accepts any object the product repository returns — (plausible) (dependent)
- **Where:** `Controller/StorageListController.php:89,92`: only `instanceof ResourceInterface` is checked.
- **Impact:** Pimcore `getById()` also returns **unpublished** objects; a visitor can add unpublished / not-yet-released products (or products not assigned to the current store) to the wishlist by id and see their name, image and price in the summary.
- **Fix:** check `$product instanceof Concrete && $product->isPublished()` (or a `StorageListProductInterface` / store-availability check), return 404 otherwise. Validate `product` as positive int before `find()`.

### B-05 — `summaryAction` problems — (verified by reading)
- **Where:** `Controller/StorageListController.php:197-262`.
- **Problems:**
  - `:233` flash text `coreshop.ui.cart_updated` is shown for **every** list (wishlist update says "cart updated"). Use `sprintf('coreshop.ui.%s_updated', $this->identifier)` with fallback.
  - `:246` `$form->getErrors()` is not deep → child errors (e.g. invalid item quantity) are never shown; the user only sees a redirect without message. Use `getErrors(true, true)` like `:138`.
  - `:242-251` errors are only flashed if the session is exactly `Session`; any other `FlashBagAwareSessionInterface` falls through and renders without a 422 status (the form is passed as `createView()`, so `AbstractController::render()` cannot set 422 — `vendor/symfony/framework-bundle/Controller/AbstractController.php:477-478`). Use `$this->addFlash()`.
  - `:202-215` a shared list (`identifier` set) is shown without re-checking `listCanBeShared()` and without store check: a token from store A opens in store B with B's currency/locale assumptions.
  - `:229` `in_array($request->getMethod(), [...])` non-strict; `handleRequest()` already only submits on the form's method.

### B-06 — Expiration and Core contexts are silently skipped in some setups
- **Where:** `DependencyInjection/CoreShopStorageListExtension.php:225-347`.
- **Problems:**
  - The whole `expiration` block (`:317-346`) is **inside** `if (interface_exists(CustomerAwareInterface::class) && interface_exists(StoreAwareInterface::class))`. Without the Customer/Store components `expiration.enabled: true` is ignored without any notice.
  - `:226` `class_implements()` on `resource.interface` relies on `%coreshop.interface.<x>%` having been resolved by `MergeExtensionConfigurationPass` — this only works because `WishlistBundle`/`OrderBundle` extensions are loaded first. A different bundle order → the unresolved string → `false` → session/customer/store contexts, blamer and logout listener are not registered at all, and the list silently becomes a "new empty list on every request" list.
- **Fix:** move the expiration block out of the `interface_exists` condition; validate `resource.interface` (TODO-1 **C-05**) and throw if it cannot be loaded.

### B-07 — Multi-list controller: crash on missing customer, weak ownership check, list deletion bypasses the manager
- **Where:** `Controller/StorageMultiListController.php:108-175` (`listStorageListAction`).
- **Problems:**
  - `:137-144` `$this->contextProvider->getCurrentContext()['customer']->getId()`: `customer` is `null` for an authenticated user without customer (`ShopperContext::getContext()`, `Component/Core/Context/ShopperContext.php:144`) and the key does not exist at all with the default `ContextProvider` (returns `[]`) → `Error`/warning → 500.
  - The ownership check only runs for `CustomerAwareInterface` lists and ignores the store; it relies on the `ChoiceType` choices being restricted (`StorageListChoiceType`). Compare customer **and** store, and throw `AccessDeniedException` if `customer` is null.
  - `:153-155` `$list->delete()` is called directly on the Pimcore object: no manager/`remove()`, no events, no transaction, and the session pointer (`coreshop.cart.<store>`) still points to the deleted id (the session context then just throws `StorageListNotFoundException` without cleaning up, `Component/StorageList/Core/Context/SessionAndStoreBasedStorageListContext.php`). Lists that are not `Concrete` silently are **not** deleted, but the user is redirected as if they were.
  - `:157-159,165-167` redirect to the `Referer` header (see TODO-1 **C-01**). Use a configured route.
  - `:122` uses `$this->container->get('form.factory')` although `$this->formFactory` is injected.
- **Fix:** guard `customer` with `?->`, centralise access checks in a voter (`STORAGE_LIST_EDIT` with the list as subject), add `remove()` to `StorageListManagerInterface` (dependent: Component) and call `$this->storage->removeForContext()` when the active list is deleted.

### B-08 — `createNamedStorageListAction`: no validation for `name` — (plausible)
- **Where:** `Controller/StorageMultiListController.php:68-75`; `Form/Type/CreatedNamedStorageListType.php:29` (`TextType` without constraints).
- **Impact:** an empty field submits `null` → `StorageListFactory::createNewNamed(string $name)` under `strict_types` → `TypeError` → 500. Very long / HTML names are stored unchecked (Pimcore input field limit 190 chars → DB error on save).
- **Fix:** `'constraints' => [new NotBlank(), new Length(max: 190)]`, `'empty_data' => ''`; read with `$form->get('name')->getData()`.

### B-09 — Permission attribute names are inconsistent between actions
- **Where:**
  - `Controller/StorageMultiListController.php:66` → `CORESHOP_STORAGE_LIST_CREATED_ORDER` (upper case).
  - `:111` → `'CORESHOP_STORAGE_LIST_MULTI_LIST_' . $this->identifier` → `CORESHOP_STORAGE_LIST_MULTI_LIST_order` (**lower case**, no `strtoupper`).
  - `Controller/StorageListController.php:74-75,173-174,217-218` → `CORESHOP_WISHLIST*`.
- **Impact:** a project voter written for `CORESHOP_STORAGE_LIST_MULTI_LIST_ORDER` never matches. Today invisible because `CoreBundle/Security/AccessVoter.php` grants everything (dependent) — which also means all `denyAccessUnlessGranted()` calls in this bundle are no-ops unless a project adds a denying voter.
- **Fix:** build all attributes with one helper (`strtoupper`), document them, and expose them as constants.

## Event listeners / session

### B-10 — Blamer sets the customer on the list but never saves it; for `order` it duplicates `CartBlamerListener` — (verified in container)
- **Where:** `Core/EventListener/StorageListBlamerListener.php:60-73`; registration `DependencyInjection/CoreShopStorageListExtension.php:309-314` (services `coreshop.storage_list.blamer.order` and `.wishlist` exist in the compiled container).
- **Impact:**
  - Wishlist: the guest wishlist gets `customer` only in memory. If nothing else saves it during this request, the link is lost; after logout (`SessionStoreStorageListLogoutSubscriber`) the guest list is unreachable → the customer's wishlist "disappears" after login/logout.
  - Order: `CoreBundle/EventListener/CartBlamerListener.php` already blames **and persists** the cart (incl. addresses + cart processing). The storage-list blamer runs as a second listener on the same event and resolves the cart context a second time.
- **Fix:** inject the list's `StorageListManagerInterface` and `persist()` when the list has an id; skip registration of the generic blamer for lists that have their own (config flag `context.blamer: true|false`).

### B-11 — Two response subscribers per list, session started for anonymous visitors, `_stateless` ignored — (plausible)
- **Where:**
  - `EventListener/SessionSubscriber.php:45-80` registered for every list with `session.enabled` (`CoreShopStorageListExtension.php:111-120`).
  - `Core/EventListener/SessionStoreStorageListSubscriber.php:47-85` additionally registered for Core lists (`:281-287`).
- **Problems:**
  - For `order` and `wishlist` both run: `SessionSubscriber` writes `coreshop.cart` / `coreshop.wishlist` (no store suffix) which **no context reads** for Core lists (only `SessionBasedListContext` does, and that one is registered only when `!$contextsRegistered`, `:349`). Pure overhead.
  - On **every** main frontend response every list context is resolved (`getStorageList()`), i.e. DB lookups for cart + wishlist on every page, plus `SessionAndStoreBasedStorageListContext` → `Session::has()` which **starts the session** → `Set-Cookie` + `Cache-Control: private` on every anonymous page → Pimcore full-page cache and reverse proxies stop caching.
  - `SessionSubscriber` does not check `$request->attributes->get('_stateless')` (the store variant does, `:68`) → `UnexpectedSessionUsageException` in debug / log noise on stateless routes.
  - Neither checks `$event->getResponse()->isSuccessful()`/redirect; error pages also resolve and write lists.
- **Fix:** register `SessionSubscriber` only for non-Core lists; only write the session if a session was already started (`$request->hasPreviousSession()` or `$session->isStarted()`); add the `_stateless` check; consider writing the session where the list is persisted (manager) instead of on every response.

### B-12 — Logout listener only clears the current store's key — (plausible)
- **Where:** `Core/EventListener/SessionStoreStorageListLogoutSubscriber.php:34-63`.
- **Impact:** with `security.logout.invalidate_session: false` (common for keeping the cart), wishlists of other stores (`coreshop.wishlist.<otherStoreId>`) still point to the customer's list → the next anonymous visitor in the same browser sees the previous customer's wishlist in those stores (privacy issue on shared devices). Resolving the context during logout can also hit `CustomerAndStoreBasedStorageListContext` with a half-logged-out token.
- **Fix:** remove all keys with prefix `"{$this->sessionKeyName}."` (iterate `$session->all()`), no context resolution needed. `:46` re-assigns `$request` (dead line).

### B-13 — Generic `StorageListContextInterface` is aliased to the **wishlist** context — (verified in container)
- **Where:** `DependencyInjection/CoreShopStorageListExtension.php:365-369` passes `StorageListContextInterface::class` as `serviceId` for every list that uses the default interface; `Component/Registry/PrioritizedCompositeServicePass.php` `addAliasForCompositeIfServiceDoesNotExist()` then aliases it to the first such composite.
- **Evidence:** compiled container line ~57065: `<service id="CoreShop\Component\StorageList\Context\StorageListContextInterface" alias="coreshop.context.storage_list.wishlist" public="true"/>`.
- **Impact:** any service/controller that autowires the generic `StorageListContextInterface` silently gets the wishlist; with a second list using the default interface, which one wins depends on config order.
- **Fix:** only pass the interface to `RegisterStorageListPass` when `!$isDefaultContextInterface` (use a per-list id otherwise); do not alias the generic interface implicitly.

### B-14 — `SimpleStorageListStorage` (default storage for non-Core lists) ignores the list and the context — (dependent)
- **Where:** registered by `DependencyInjection/CoreShopStorageListExtension.php:222-223` for every list; implementation `Component/StorageList/Storage/SimpleStorageListStorage.php` uses the hard-coded key `'coreshop.cart'` and ignores `$context`.
- **Impact:** each list gets its own instance, so there is no cross-talk between lists, but it is request-scoped memory only: the multi-list controller's `setForContext($context, $list)` for non-Core lists keeps the choice only in memory → choosing a list has no effect on the next request.
- **Fix:** (Component) key by context; (bundle) for non-Core lists with `multi_list.enabled` register a session-backed storage or reject the configuration.
