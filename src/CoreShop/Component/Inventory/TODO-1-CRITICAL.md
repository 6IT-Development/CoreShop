# Inventory Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this component (`Checker/AvailabilityChecker{,Interface}.php`, `Model/StockableInterface.php`), `composer.json`, `README.md`, `LICENSE.md`. Also checked: the code, config and docs that depend on it:
`Bundle/InventoryBundle/*` (services, Twig extension, `InStock` constraint, admin renderer), `Component/Core/Model/{Product,ProductInterface}.php`, `Component/Core/Inventory/Operator/OrderInventoryOperator.php`, `CoreBundle/Resources/config/services/{inventory,validators}.yml`, `CoreBundle/Resources/config/validation/{AddToCart,Order}.yml`, `CoreBundle/Resources/config/pimcore/workflow.yml`, `CoreBundle/Validator/Constraints/{AddToCartAvailability,CartStockAvailability,AddToCart{Minimum,Maximum}Quantity,Cart{Minimum,Maximum}Quantity}Validator.php`, `FrontendBundle/Resources/views/{Category/_grid,Category/_list,Product/_preview,Product/detail}.html.twig`, `docs/02_User_Documentation/08_Inventory/index.md`, `docs/03_Development/18_Inventory/index.md`, root `composer.json`.

Reference for API checks: `../pimcore2teszt10211524/var/classes/DataObject/CoreShopProduct.php` (generated setters return `static`).

"Critical" here means: a security issue, money charged or stock stored wrongly, data loss, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** live outside this component, but they come from how it is used, and they should be fixed together with it.

**Symfony 7.4 / PHP 8.4 compatibility:** no blockers. `php -l` (PHP 8.4.27) passes on all 3 PHP files. There are no implicit nullable parameters, no removed functions, and the component uses no Symfony API at all. Packaging gaps (no `php` constraint, PHPStan 1.x, a needless `coreshop/resource` and `six-it/pimcore-source` requirement) are in TODO-3 **I-08**. Missing PHP 8.4 idioms are in TODO-3.

**Overlap with other TODOs:** the InventoryBundle TODOs (`Bundle/InventoryBundle/TODO-*.md`) already scanned this component as part of their scope. The items below are **not repeated here**. Fix them together with this file:
- InventoryBundle **C-01** (check-then-act stock reservation, lost `onHold`/`onHand` updates), **C-02** (`sell()` throws in the payment `pay` callback).
- InventoryBundle **B-02** (checker sums floats, operator rounds `ceil()` per line), **B-03** (three meanings of "in stock"; `Product::isInStock()` ignores `onHold`/`isTracked`), **B-04** (`isTracked = null` means unlimited stock), **B-05** (zero/negative/`NaN` quantities, `isStockAvailable()` hard-codes `1`), **B-07** (negative stock, `onHold > onHand`), **B-11** (`composer.json` dependencies, partially).
- InventoryBundle **I-02** (setter return types on `StockableInterface`), **I-06** (`getAvailableQuantity()` / `StockLevel`), **I-07** (rename `getIsTracked()`).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## C-01 — The component's contract only offers a *check*, never a *reservation*, so the fix for InventoryBundle C-01 (overselling) cannot be built on it

- **Where:**
  - `Checker/AvailabilityCheckerInterface.php:23-28`: only `isStockAvailable()` and `isStockSufficient()`. Both are pure reads of the in-memory object.
  - `Model/StockableInterface.php:27-37`: only plain getters/setters for `onHold`/`onHand`. Every caller has to do `setOnHold(getOnHold() + $q)`, i.e. a read-modify-write on a possibly stale object.
  - (dependent) `Component/Core/Inventory/Operator/OrderInventoryOperator.php:50-159`: the only writer. It never calls the checker again and has no lock.
  - (dependent) `CoreBundle/Validator/Constraints/{CartStockAvailability,AddToCartAvailability}Validator.php`: the only callers of the checker. They run at validation time, long before `hold()` in the `create` transition (`CoreBundle/Resources/config/pimcore/workflow.yml:4-9`).
- **Problem:**
  - The component is the place where the stock domain is defined, but it has no operation that says "reserve *n* units if, and only if, they are available". The two halves of that operation (check here, write in `Component/Core`) are in different packages and run in different requests. No caller can make them atomic without bypassing the component.
  - Result (details in InventoryBundle **C-01**/**C-02**): concurrent checkouts both pass `isStockSufficient()`, both `hold()`, `onHold` exceeds `onHand`, and the later `sell()` assertion fails after the customer has paid.
- **Fix direction:**
  - Add a write-side contract to this component, e.g. `Inventory/Operator/StockReserverInterface` with `reserve(StockableInterface $s, int|float $qty): void` (throws `InsufficientStockException`), `release()`, `commit()` (hold → sold) and `restock()`. The contract must state that `reserve()` is atomic (re-check + increment in one step).
  - Add `Inventory/Exception/InsufficientStockException` (carries stockable, requested and available quantities), so callers can show a proper checkout error instead of the Webmozart assertion messages.
  - Keep the storage-specific implementation (conditional `UPDATE … WHERE onHand - onHold >= :q` on the Pimcore object table, or a Symfony Lock around reload → check → write) in `Component/Core` / CoreBundle. Make `OrderInventoryOperator` delegate to it.
  - Document in `AvailabilityCheckerInterface` that its answer is advisory only (UI and validation), and must never be used as the guard for a write.
