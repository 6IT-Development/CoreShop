# Inventory Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this component, but they come from how it is used, and they should be fixed together with it.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items are roughly ordered by impact. Items marked **(plausible)** follow from the code, but were not reproduced against a running shop.
Items already covered by the InventoryBundle TODOs are not repeated (see the overlap list in TODO-1).

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Availability check

### B-01 — Float rounding in `isStockSufficient()` rejects exact fractional quantities
- **Where:** `Checker/AvailabilityChecker.php:32` (`$quantity <= ($stockable->getOnHand() - $stockable->getOnHold())`). (dependent) The quantity is a float sum of `OrderItemInterface::getDefaultUnitQuantity(): ?float` built in `CoreBundle/Validator/Constraints/CartStockAvailabilityValidator.php:85-104` and `AddToCartAvailabilityValidator.php:64-67,77-96`.
- **Problem:** the available side is an `int` (`onHand`/`onHold` are `?int`), the requested side is a float sum. Sums of decimal unit quantities are not exact: three lines of `0.1` units give `0.30000000000000004`. With `onHand = 1` and fractional units this is harmless, but with unit conversions whose total should be an integer (e.g. `0.7 + 0.2 + 0.1` "boxes" = `1.0000000000000002`) and exactly that much stock left, the check returns `false` and the customer cannot buy the last unit. **(plausible)**
- **Fix:** compare with a tolerance or on a fixed scale (`round($quantity, 6) <= $available`), or, better, compute the quantity once in the shared calculator proposed in InventoryBundle **B-02** and pass an integer/decimal string to the checker.

### B-02 — The `null` counters are treated as `0` only by accident of PHP arithmetic
- **Where:** `Checker/AvailabilityChecker.php:32`. `Model/StockableInterface.php:27,31` declare `?int`.
- **Problem:**
  - `null - null` and `5 - null` evaluate to `0`/`5`, so the result is right today. But it depends on implicit `null` → `0` coercion in arithmetic. PHPStan (level ≥ 8 on 2.x) reports it, and a future implementation that returns `null` for "unknown" (e.g. a remote stock source that timed out) is silently read as "0 on hold", i.e. **more** stock than is real.
  - The contract never says what `null` means for either counter, or for `getIsTracked()` (see InventoryBundle **B-04**).
- **Fix:** write `($stockable->getOnHand() ?? 0) - ($stockable->getOnHold() ?? 0)` explicitly, and document in `StockableInterface` that `null` means `0`. If "unknown" must be representable, model it explicitly (e.g. return `false` from the checker when `getOnHand()` is `null` on a tracked item).

### B-03 — (dependent) The cart check reports only the first product that is out of stock
- **Where:** `CoreBundle/Validator/Constraints/CartStockAvailabilityValidator.php:52-82` (`break` on the first insufficient product; one violation built from `$insufficientProduct`).
- **Problem:** a cart with three products that all became unavailable shows one error. The customer removes it, submits again, gets the next error, and so on. Together with FrontendBundle **B-24** (only top-level form errors are flashed) the checkout looks stuck.
- **Fix:** collect all insufficient products and add one violation per product (with `atPath('items[n].quantity')`), and stop using the shared `$isStockSufficient` flag.

## Contract / data

### B-04 — `getInventoryName(): ?string` is nullable, and the Core implementation returns the name in the *current* locale only, so stock error messages can show an empty product name
- **Where:** `Model/StockableInterface.php:23`. (dependent) `Component/Core/Model/Product.php:29-32` (`return $this->getName();`, localized field, no fallback language argument). Used as `%stockable%` in `CoreBundle/Validator/Constraints/{AddToCartAvailability,CartStockAvailability,AddToCart{Minimum,Maximum}Quantity,Cart{Minimum,Maximum}Quantity}Validator.php`.
- **Problem:** for a product that has no name in the request locale (common with partially translated catalogs, or variants that inherit nothing in that language), the message renders as `"" is not available in that quantity` (or similar). The customer cannot tell which line is the problem. **(plausible)** — depends on the Pimcore localized-field fallback configuration of the installation.
- **Fix:** make the contract `getInventoryName(): string` and let implementations fall back (name → key/SKU → `#id`). In `Product::getInventoryName()`, use the fallback-aware getter (see `Component/Pimcore/DataObject/LocaleFallbackHelper.php`) or `getKey()`.

### B-05 — `isInStock()` is part of the contract, but nothing in CoreShop calls it, and its only implementation disagrees with the checker
- **Where:** `Model/StockableInterface.php:25`. (dependent) `Component/Core/Model/Product.php:34-37`.
- **Problem:** a repo-wide search (PHP, Twig, YAML, class definitions) finds no caller. Third-party code and index getters that use it get the wrong answer described in InventoryBundle **B-03** (ignores `onHold` and `isTracked`). Because it looks like the canonical API ("is in stock"), it is the method a project developer reaches for first.
- **Fix:** either make it a real alias of the checker semantics (`!tracked || available > 0`), or deprecate it in the interface (`@deprecated use AvailabilityCheckerInterface::isStockAvailable()`) and remove it in the next major. See TODO-3 **I-03**.

## Docs

### B-06 — (dependent) The user documentation describes a stock flow that the code does not implement
- **Where:** `docs/02_User_Documentation/08_Inventory/index.md`. Code: `Component/Core/Inventory/Operator/OrderInventoryOperator.php` (`hold()` only increments `onHold`; `sell()` decrements both `onHold` and `onHand`; `release()` only decrements `onHold`).
- **Problem:**
  - "On Hand: The current available quantity" — wrong. Available is `onHand - onHold` (`Checker/AvailabilityChecker.php:32`).
  - "After checkout completion, ordered items shift from On Hand to On Hold" — wrong. `onHand` is not changed on `hold()`.
  - "If an unpaid order is cancelled, the On Hold quantity returns to On Hand" — misleading. `onHold` is decreased; `onHand` never changed.
  - "During checkout, if a product becomes out of stock, it will be removed from the customer's cart" — **(plausible)** wrong. The only wiring found is the `CartStockAvailability` validation constraint (`CoreBundle/Resources/config/validation/Order.yml:3`), which adds a violation; no cart processor removes the item.
  - Merchants who follow the doc edit `onHand`/`onHold` by hand wrongly and unbalance the counters (InventoryBundle **B-07**, **C-02**).
- **Fix:** rewrite the section: `onHand` = physical stock, `onHold` = reserved by unpaid orders, available = `onHand − onHold`; list the four workflow steps (`create` → hold, `pay` → sell, `cancel` → release or give back). Update the "removed from cart" sentence to what actually happens.
