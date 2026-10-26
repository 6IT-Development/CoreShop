# InventoryBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: all PHP in this bundle (`CoreShopInventoryBundle.php`, `DependencyInjection/*`, `Pimcore/Renderer/StockOnHandRenderer.php`, `Twig/InventoryExtension.php`, `Validator/Constraints/InStock{,Validator}.php`), `Resources/config/services.yml`, `Resources/translations/*`, `Resources/views/pimcore/stock_text.html.twig`, `composer.json`, `README.md`, the `Component/Inventory` component it wraps (`Checker/AvailabilityChecker{,Interface}.php`, `Model/StockableInterface.php`, `composer.json`), and the code and config that depend on it:
`Component/Core/Inventory/Operator/OrderInventoryOperator{,Interface}.php`, `Component/Core/Model/Product.php`, `CoreBundle/Resources/config/services/{inventory,validators}.yml`, `CoreBundle/Resources/config/pimcore/workflow.yml`, `OrderBundle/Resources/config/pimcore/workflow/coreshop_order_payment.yml`, `CoreBundle/Validator/Constraints/{CartStockAvailability,AddToCartAvailability}Validator.php`, `CoreBundle/Resources/config/validation/{AddToCart,Order}.yml`, `CoreBundle/Resources/install/pimcore/classes/CoreShopProductBundle/CoreShopProduct.json` (fields `onHand`, `onHold`, `isTracked`, stock renderer layout), `FrontendBundle/Resources/views/{Category/_grid,Category/_list,Product/_preview,Product/detail}.html.twig`.

"Critical" here means: a security issue, money charged or stored wrongly, data loss, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers. All PHP files of the bundle and the component pass `php -l` on PHP 8.4.27. There are no implicit nullable parameters and no removed APIs. The one Symfony 7.4 deprecation is the options-array construction of the `InStock` constraint (it breaks in 8.0), see **B-06**. Packaging gaps (no `php` constraint, undeclared Symfony/Twig/Pimcore dependencies, PHPStan 1.x) are in **B-11**. Missing PHP 8.4 idioms are in TODO-3.

**Overlap with other TODOs:** none. No other TODO file covers inventory, stock or `AvailabilityChecker`. FrontendBundle **B-24** (`Bundle/FrontendBundle/TODO-2-BUGS.md`: the cart summary only flashes top-level form errors) hides the stock violations produced here. Fix them together.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## C-01 — (dependent) Stock reservation is a non-atomic check-then-act and a read-modify-write: concurrent checkouts oversell and lose `onHold` / `onHand` updates

- **Where:**
  - Check: `Component/Inventory/Checker/AvailabilityChecker.php:32-35` (`onHand - onHold >= quantity`), called from `CoreBundle/Validator/Constraints/CartStockAvailabilityValidator.php` (cart/checkout validation) and `AddToCartAvailabilityValidator.php`.
  - Act: `Component/Core/Inventory/Operator/OrderInventoryOperator.php` `hold()` (`:50-69`), `sell()` (`:71-109`), `release()` (`:111-138`), `giveBack()` (`:140-159`). They are triggered from the state machine callbacks in `CoreBundle/Resources/config/pimcore/workflow.yml:4-19` (`create` → `hold`, `cancel` → `cancel`, payment `pay` → `sell`).
- **Problem:**
  1. The availability check runs while the cart is validated. The reservation (`hold()`) runs later, in the `create` transition callback of another code path. `hold()` never checks availability again. Two customers who check out the last unit at the same time both pass validation, and both orders are created and held: `onHold` ends up greater than `onHand`.
  2. Every operator method reads the counter from the in-memory Pimcore object, adds or subtracts, and saves the **whole object** through `coreshop.manager.product` (`persist` + `flush`). There is no row lock, no version check and no atomic `UPDATE … SET onHold = onHold + ?`. Two concurrent requests that loaded the product with the same `onHold = 5` both write `6`: one reservation is lost. The same race applies to `sell()` (`onHand`) and to an admin who saves the product while orders are being placed.
  3. Each save also writes a full Pimcore version of the product and fires all product save events and listeners (index update, cache clear) inside the order transition.
- **Impact:** overselling, and stock counters drift for good with no audit trail. Lost `onHold` increments make later checks too optimistic, which compounds the oversell. If `onHand` falls below the quantity being sold, the payment callback then throws (see **C-02**).
- **Fix direction:**
  - Re-check and reserve in one atomic step inside `hold()`. Either use a conditional SQL update on the object store table (`UPDATE … SET onHold = onHold + :q WHERE oo_id = :id AND (onHand - onHold) >= :q`, then check the affected rows and reload the object), or use a pessimistic lock (`SELECT … FOR UPDATE` / Symfony Lock keyed by product id) around reload → check → write.
  - Fail the `create` transition cleanly (domain exception → checkout error) when the reservation fails. Do not silently over-reserve.
  - Write only the counter fields, not a full object save. If you keep the object save, save inside a `Pimcore\Model\Version::disable()` … `enable()` scope so stock movements don't produce product versions.
  - Wrap all items of one order in a single transaction, so a failure on item 3 does not leave items 1–2 reserved.

## C-02 — (dependent) `sell()` throws inside the payment `pay` callback: the PSP has already captured the money, but the order payment transition fails

- **Where:** `Component/Core/Inventory/Operator/OrderInventoryOperator.php` `sell()` `:85-101` (two `Assert::greaterThanEq()` calls on `onHold - qty` and `onHand - qty`). It runs as the `after` callback of `coreshop_order_payment` → `pay` (`CoreBundle/Resources/config/pimcore/workflow.yml:14-19`). `release()` has the same assertion (`:125-132`) and runs on `cancel`.
- **Problem:** `Webmozart\Assert\InvalidArgumentException` is thrown after the payment provider has confirmed the payment, while the order's payment state machine is being applied. Reproducible triggers that need no bug elsewhere:
  - an oversell from **C-01** (`onHand < qty`);
  - `isTracked` was `false`/`null` when the order was created (so no hold), then switched to `true` before payment (`onHold - qty < 0`);
  - an admin lowered `onHand` by hand (the field is editable, `CoreShopProduct.json` `onHand`: `noteditable: false`, no `minValue`);
  - fractional quantities rounded differently by the checker and the operator (**B-02**);
  - two lines of the same product in one order, so the per-line assertion passes for the first line and fails for the second.
  `flush()` only runs after the loop, but the products of the earlier loop iterations were already changed in memory. Any later `flush()` of the same manager in the request persists that partial state.
- **Impact:** a customer is charged, but the order stays `awaiting_payment`/`authorized`, or the notify/return request ends with a 500. Depending on the payment bundle, this also means repeated notify retries and the order never ships. On `cancel`, the same assertion in `release()` makes the order impossible to cancel.
- **Fix direction:**
  - Never throw from the `pay` callback for a stock condition. Selling already-paid goods must always succeed: clamp at zero, or allow negative `onHand` (backorder), and record or log the discrepancy (event, admin notification).
  - Base `sell()`/`release()` on what was actually reserved for this order (store the reserved quantities per order item at `hold()`), not on the product's current tracking flag. Then toggling `isTracked` cannot unbalance the counters.
  - Aggregate quantities per product before checking (one check per product, not per line).
