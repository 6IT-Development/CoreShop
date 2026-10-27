# Inventory Component – TODO 3: Improvements (code quality, readability, logic)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this component.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.
None of these items changes behaviour on its own, unless the item says so. Items that break BC are marked **[BC]**.
Items already covered by the InventoryBundle TODOs (I-02 setter return types, I-06 available-quantity API, I-07 `getIsTracked()` rename) are not repeated.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 idioms

### I-01 — `final readonly class AvailabilityChecker`
- `Checker/AvailabilityChecker.php:23`: the class has no state. Declare it `final readonly class` so later dependencies (e.g. a calculator, B-01) are immutable by default.

### I-02 — Quantity type on the checker contract **[BC for implementers]**
- `Checker/AvailabilityCheckerInterface.php:27`, `Checker/AvailabilityChecker.php:30`: `float $quantity`. Callers pass `int` (Twig default `1`, `isStockAvailable()`), float sums (validators), and nothing ever passes a string.
- Change to `int|float $quantity` to document both cases, and add a docblock that states the unit (the product's *default* unit, as produced by `getDefaultUnitQuantity()`) and that the value must be finite and `> 0` (InventoryBundle **B-05**).
- `isStockAvailable()` calls `isStockSufficient($stockable, 1)` with an `int` literal into a `float` parameter; with `int|float` that becomes explicit.

### I-03 — Split and clarify `StockableInterface` **[BC]**
- `Model/StockableInterface.php:21-38` mixes three concerns: identity for messages (`getInventoryName()`), a derived query (`isInStock()`, B-05) and raw storage (`onHand`/`onHold`/`isTracked` get/set).
- Next major: keep `StockableInterface` as the read side (`getInventoryName(): string`, `getOnHand(): int`, `getOnHold(): int`, `isTracked(): bool`) and move the setters into `StockableWritableInterface` used only by the reserver from TODO-1 **C-01**. That makes the read-modify-write pattern impossible for random callers.
- Add a short class docblock that defines each field (`onHand` = physical stock, `onHold` = reserved by unpaid orders, available = `onHand − onHold`, `isTracked = false` = unlimited). Right now that definition exists only in the (partly wrong) user docs (B-06).

### I-04 — Declare the semantics of the checker in the interface
- `Checker/AvailabilityCheckerInterface.php` has no docblocks. Add:
  - `isStockAvailable()`: "at least one default unit is available, or the stockable is not tracked".
  - `isStockSufficient()`: "`$quantity` default units are available, or not tracked; the quantity must already include what is in the cart".
  - Note that the result is advisory (TODO-1 **C-01**).
- (dependent) The CoreBundle validators and the Twig extension each re-derive "how much is already in the cart". Mention in the docblock which side is responsible.

## Logic / extensibility

### I-05 — Allow context-aware availability (store / warehouse) without replacing the whole checker
- The checker only receives the stockable. Multi-store shops that keep stock per store or warehouse have to replace the service (`core_shop_inventory.checker`) and re-implement the formula.
- Consider an optional context argument (`?StockContext $context = null`, or `array $context = []`) on both methods, or a `StockLevelProviderInterface` that the default checker asks for `onHand`/`onHold`. The default provider reads the stockable fields, so behaviour stays the same.

### I-06 — Name the quantity helper once and reuse it
- (dependent) `CartStockAvailabilityValidator::getExistingCartItemQuantityFromCart()` and `AddToCartAvailabilityValidator::getExistingCartItemQuantityFromCart()` are two different implementations with the same name (one includes the current line, one does not; one uses `CartItemResolver::equals()`, one only compares product id and class with `instanceof $product`).
- Move "total requested quantity of a stockable in an order" into one service next to the checker (e.g. `Inventory/Calculator/RequestedQuantityCalculatorInterface` in this component, Core implementation in `Component/Core`). This also gives InventoryBundle **B-02** a single place to fix rounding.

## Packaging, docs, tests

### I-07 — README and developer docs
- `README.md` is the generic CoreShop boilerplate (typo "It is build", underline lengths that don't match the titles, `http://www.coreshop.org` vs. the `https://www.coreshop.com` homepage). Add a short description of the three types, the meaning of `onHand`/`onHold`/`isTracked`, and a link to `docs/03_Development/18_Inventory/index.md`.
- `docs/03_Development/18_Inventory/index.md` only documents the Twig helpers. Add the PHP API (`AvailabilityCheckerInterface`, how to replace it via `core_shop_inventory.checker`, `StockableInterface` for custom purchasables), and correct the comment "at least 10 items available" → "at least 10 default units available, or not tracked".

### I-08 — `composer.json`
- No `"php": "^8.4"` (root: `~8.4.0`).
- `require` lists `coreshop/resource` and `six-it/pimcore-source` (`^2.0`; root uses `^2.1`), but the component uses neither: the three files have no `use` outside this namespace. Drop both, so the component is a real standalone library. (InventoryBundle **B-11** already mentions `coreshop/resource`; `six-it/pimcore-source` is not mentioned there.)
- `require-dev`: `phpstan/phpstan ^1.10`, `phpstan-doctrine ^1.3.40`, `phpstan-webmozart-assert ^1.2.4`. The root uses `phpstan/phpstan ^2.1`. Bump to `^2.1`, and drop `phpstan-doctrine` / `phpstan-webmozart-assert` (no Doctrine, no Webmozart in this component). Add `phpunit/phpunit` for I-09.
- `extra.branch-alias` is `dev-master: 4.1-dev`; the root maps `dev-next` and `dev-4.1`. Align with the root.
- `minimum-stability: dev` in a library package has no effect for consumers, but is misleading; remove it, or keep it in line with the other components in one sweep.
- Indentation of `repositories` / `extra` is inconsistent (2, 3 and 4 spaces).

### I-09 — Unit tests
- The component has no tests. `AvailabilityChecker` is a pure function and trivial to cover with a data provider:
  - not tracked (`false`, `null`) → always `true`;
  - tracked with `onHand`/`onHold` `null`, `0`, negative, `onHold > onHand`;
  - quantity `0`, negative, fractional, float-sum edge case (B-01), `INF`, `NAN`.
- Add the component to the PHPStan run at the root level used for the other components, at level 8+ (would flag B-02).
