# StorageList Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: all 53 PHP files in `src/CoreShop/Component/StorageList` (root services, `Context/*`, `Core/{Context,Provider,Repository,Storage}/*`, `DTO/*`, `Expiration/*`, `Factory/*`, `Maintenance/ExpireTask.php`, `Manager/*`, `Model/*`, `Provider/*`, `Repository/*`, `Resolver/*`, `Storage/*`), its `composer.json` and `README.md`. Dependent config and callers that were followed: `Bundle/StorageListBundle/{Resources/config/services.yml,DependencyInjection/{Configuration,CoreShopStorageListExtension}.php}`, `Bundle/WishlistBundle/{Resources/config/pimcore/config.yml,Resources/config/services.yml,Resources/config/services/commands.yml,Command/WishlistExpireCommand.php,Pimcore/Repository/WishlistRepository.php}`, `Bundle/CoreBundle/{Pimcore/Repository/WishlistRepository.php,Resources/config/pimcore/coreshop/coreshop_wishlist.yml}`, `Bundle/OrderBundle/{Resources/config/pimcore/config.yml,Expiration/*,StorageList/LastActivatedCartStorage.php,Manager/CartManager.php}`, `Component/Wishlist/{Model/Wishlist,Model/WishlistItem,Wishlist/WishlistModifier}.php`, `Component/Order/{Context/CartNotFoundException,Cart/CartResolver,Model/OrderItem}.php`, `Component/Resource/{Service/FolderCreationService,TokenGenerator/UniqueTokenGenerator}.php`, `Component/Pimcore/DataObject/VersionHelper.php`, `vendor/six-it/pimcore-source/{lib/Model/Listing/AbstractListing.php,lib/Maintenance/Executor.php,lib/Http/RequestHelper.php}`.

The bundle-side wiring was already scanned in `src/CoreShop/Bundle/StorageListBundle` (its TODO files use `C-`, `B-`, `I-` numbers). Where an item here has a bundle counterpart it is cross-referenced; close it in both files when fixed.

"Critical" here means: customer data deleted or shown to the wrong person, or an error that takes down a whole area (wishlist, cart) or the container build once it is triggered, or a blocker for Symfony 7.4 / PHP 8.4.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4, six-it/pimcore-source 2.x, PHP 8.4.27). Component items use the prefixes `CC-` (critical), `CB-` (bugs) and `CI-` (improvements).
Markers: **(dependent)** = root cause or part of the fix is outside this component; **(reproduced)** = run with PHP 8.4.27 against this component's classes; **(verified)** = confirmed by reading the code / vendored library source; **(plausible)** = follows from the code, but the trigger depends on data, configuration or deployment.

**No Symfony 7.4 / PHP 8.4 language blocker was found.** All 53 PHP files pass `php -l` on 8.4.27 with `error_reporting=-1`; no implicitly nullable parameters, no dynamic properties, no removed functions. Note: the test project's vendored copy (`vendor/six-it/core-shop/src/CoreShop/Component/StorageList`) differs from this repo in one line (`Factory/StorageListFactory.php:49`, token length `10` there vs `32` here) — keep that in mind when reproducing against the test project. Modernisation items are in TODO-3.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Expiration – customer data deleted

### CC-01 — `coreshop:wishlist:expire --anonymous` deletes the wishlists of registered customers; `--user` is ignored — (verified) (dependent)
- **Where:**
  - `Repository/CustomerExpiryRepositoryTrait.php:36-48` builds the customer filter only from `$params['anonymous']` and `$params['customer']`, joined with `OR`.
  - `Bundle/WishlistBundle/Command/WishlistExpireCommand.php:65-81`: starts from the **configured** params (`%coreshop.storage_list.expiration.wishlist.params%`, `services/commands.yml:9`) and only **adds** flags; `--user` writes `$params['user']` (`:76`), a key the trait never reads.
  - Config `Bundle/WishlistBundle/Resources/config/pimcore/config.yml:35-38` sets `anonymous: true` **and** `customer: true`.
  - Repository in use: `Bundle/CoreBundle/Pimcore/Repository/WishlistRepository.php:30` (`use CustomerExpiryRepositoryTrait`, overrides the WishlistBundle version), wired by `coreshop_wishlist.yml:9`.
- **Effect:** the SQL condition is always `modificationDate < ? AND (customer__id IS NULL OR customer__id IS NOT NULL)`. An operator who runs `coreshop:wishlist:expire --anonymous --days=1` to clean up guest lists deletes **every** wishlist (guest and registered customer) older than one day. `--user` / `-u` has no effect at all. The option descriptions ("Delete only anonymous wishlists") promise the opposite.
- **Fix (component side):**
  - Replace the two booleans with one explicit scope (`enum ExpirationScope { Anonymous, Customer, All }`, TODO-3 **CI-10**); `findExpiredStorageLists()` takes the scope, not an untyped array.
  - Until then: treat `anonymous` and `customer` as **exclusive** filters in the trait; when both are true, document it as "all" explicitly; reject unknown keys (`user`) with an `\InvalidArgumentException`.
- **Fix (dependent):** the command must **replace** the scope, not merge into the configured params (`--anonymous` ⇒ `['anonymous' => true, 'customer' => false]`); rename `user` → `customer`; add a `--dry-run` that prints the count.

### CC-02 — Wishlist expiry runs after 14 days, not the configured 20, and includes registered customers' wishlists in the daily maintenance job — (verified) (dependent)
- **Where:** `Bundle/WishlistBundle/Resources/config/pimcore/config.yml:33-38` puts `days: 20` **inside** `params`; `Bundle/StorageListBundle/DependencyInjection/Configuration.php:155` defaults `expiration.days` to `14`; `CoreShopStorageListExtension.php:332-333` passes `expiration.days` (14) to `Maintenance/ExpireTask.php:28` and `params` (incl. the unused `days`) to the trait, which never reads `params['days']` (`Repository/CustomerExpiryRepositoryTrait.php:27-58`).
- **Effect:** the Pimcore maintenance job (`coreshop_wishlist_storage_list_expiration`) deletes every wishlist — guest **and** registered customer (CC-01) — whose `modificationDate` is older than **14** days. Viewing a wishlist does not touch `modificationDate`, so a logged-in customer's curated wishlist that they only look at disappears after two weeks. Project configs that copy the bundled file believe they configured 20 days.
- **Fix (component side):** make the expiration input typed (`int $days`, scope enum) so a `days` key in `params` is impossible / rejected (`Webmozart\Assert::allOneOf(array_keys($params), [...])`).
- **Fix (dependent):** move `days: 20` one level up in `WishlistBundle/.../config.yml`; decide whether customer wishlists should expire by default at all (`customer: false` by default is the safer choice); validate `params` keys in `Configuration.php` (StorageListBundle TODO-3 **I-02**).

## Wrong person's data

### CC-03 — Contexts cache the resolved list in a shared service property: in long-running runtimes one visitor's list is served to the next visitor — (plausible)
- **Where:**
  - `Core/Context/StoreBasedStorageListContext.php:30,40-42,63` (`private ?StorageListInterface $storageList` — decorates the **factory** context, `Bundle/StorageListBundle/DependencyInjection/CoreShopStorageListExtension.php:246-261`, enabled for `wishlist` via `enable_default_store_based_decorator: true`).
  - `Context/SessionBasedListContext.php:27,39-41,60-65` (registered for every non-Core list with session enabled, extension `:349-362`).
  - Neither class implements `Symfony\Contracts\Service\ResetInterface` nor carries a `kernel.reset` tag (grep over component + bundle: none).
- **Effect:** with PHP-FPM the container dies after every request, so the cache is request-scoped. With a worker runtime (FrankenPHP worker mode, RoadRunner, Swoole, `symfony/runtime` long-running) or in a Messenger consumer / long CLI loop the services survive:
  - Request 1 (guest A, no list yet): the factory context creates a new wishlist, `StoreBasedStorageListContext` caches the **object**; A adds a product, the manager saves it (same object now has an id and A's items).
  - Request 2 (guest B, no session list): the session context finds nothing, the factory fallback returns the **cached object of A** → B sees A's wishlist and writes into it; after B logs in, the blamer assigns A's list to customer B.
  - `SessionBasedListContext` has the same problem for non-Core lists (first visitor's session list returned to everyone).
- **Fix:**
  - Remove the property caches (resolution is cheap compared to the risk), or implement `ResetInterface` (`reset(): void { $this->storageList = null; }`) and tag both definitions with `kernel.reset` (`['method' => 'reset']`) in the extension (dependent: StorageListBundle).
  - Never cache the result of the **factory** fallback: it is a brand-new, mutable object per visitor.
  - Add a functional test that boots the kernel once and handles two requests with different sessions.

## Container build

### CC-04 — The default `services.manager` (`SessionStorageManager::class`) is not a service: any list that omits `services.manager` breaks the container build — (verified) (dependent)
- **Where:** `Bundle/StorageListBundle/DependencyInjection/Configuration.php:70` (`->scalarNode('manager')->defaultValue(SessionStorageManager::class)`); used as `new Reference($list['services']['manager'])` in `CoreShopStorageListExtension.php:151,192,212` (the `StorageListsManager::addList()` call at `:210-215` is unconditional). `Bundle/StorageListBundle/Resources/config/services.yml` registers `StorageListPimcoreModelManager` but **not** `SessionStorageManager`, and the class cannot be autowired anyway (`SessionStorageManager.php:27-31`: `string $name`, generic `FactoryInterface`).
- **Effect:** a project that adds its own list (e.g. `compare`) and relies on the documented default gets `ServiceNotFoundException: … non-existent service "CoreShop\Component\StorageList\SessionStorageManager"` at container compile. `wishlist` and `order` are only safe because their configs set `manager` explicitly.
- **Additionally**, even if it were registered, `SessionStorageManager::persist()` (`:50-53`) stores the **whole list object** (a Pimcore `Concrete` with relations) in the session: serialisation of Pimcore objects, stale data after admin changes, and session sizes in the hundreds of KB.
- **Fix (component side):** deprecate / remove `SessionStorageManager` (TODO-3 **CI-03**) or turn it into an id-only storage on top of `StorageListStorageInterface`.
- **Fix (dependent):** change the default to `StorageListPimcoreModelManager::class` (registered), or make `services.manager` `isRequired()`; add a kernel test with a minimal custom list config.
