# InventoryBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this bundle (mostly in `Component/Inventory` and `Component/Core`), but they come from how the stock model, the availability checker or the bundle's services are defined, wired or used. Fix them together with this bundle.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.
Items are roughly ordered by impact.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Stock bookkeeping

### B-01 — (dependent) `OrderInventoryOperator::cancel()` decides "sold or only held" from the current payment state, which is wrong for `partially_refunded` and for `refunded` reached from `partially_paid`
- **Where:** `Component/Core/Inventory/Operator/OrderInventoryOperator.php:35-48`. Transitions: `OrderBundle/Resources/config/pimcore/workflow/coreshop_order_payment.yml` (`partially_refund`/`refund` from `[paid, partially_paid, partially_refunded]`). `sell()` only runs on `pay` (`CoreBundle/Resources/config/pimcore/workflow.yml:14-19`).
- **Problem:** `cancel()` calls `giveBack()` only for `paid`/`refunded`. Otherwise it calls `release()`.
  - `paid` → `partially_refunded` → order cancelled: `sell()` already ran, but `release()` runs. It subtracts the quantity from `onHold` again. That either steals other orders' reservations or hits the assertion in `release()` and blocks the cancel (**C-02**).
  - `partially_paid` → `refunded` → order cancelled: `sell()` never ran (no `pay`), but `giveBack()` runs. Phantom units are added to `onHand`, and the original hold stays in `onHold` forever.
- **Fix:** don't derive the inventory state from the payment state. Store the inventory state on the order (e.g. `inventoryState: held|sold|released|returned`, or the reserved and sold quantities per item) and branch on that. Make every operation idempotent: a second `cancel` must not release twice.

### B-02 — (dependent) The checker and the operator round quantities differently (float sum vs. per-line `ceil`)
- **Where:** `Component/Inventory/Checker/AvailabilityChecker.php:30-33` (float). `CoreBundle/Validator/Constraints/CartStockAvailabilityValidator.php` and `AddToCartAvailabilityValidator.php` sum `getDefaultUnitQuantity()` as float. `Component/Core/Inventory/Operator/OrderInventoryOperator.php:64,86,95,103-104,126,133,154` use `(int) ceil($orderItem->getDefaultUnitQuantity())` per line.
- **Problem:** two lines of the same product with 0.5 units each pass the check with `onHand - onHold = 1`, but `hold()` reserves `ceil(0.5) + ceil(0.5) = 2`. `onHold` goes above `onHand`, and `sell()` fails on the second line (**C-02**). With unit definitions whose conversion factor is fractional, every order drifts the counters.
- **Fix:** pick one rule and use it everywhere: sum per product first, then round once. Better: move it into a single service, e.g. `StockQuantityCalculator`, used by checker, validators and operator. Decide whether `onHand`/`onHold` stay `int` (the class definition has `integer: true`) or become decimal for fractional units.

### B-03 — Three different meanings of "in stock / available"
- **Where:**
  - `Component/Inventory/Checker/AvailabilityChecker.php:32`: `!isTracked || qty <= onHand - onHold`.
  - `Component/Core/Model/Product.php:34-37` `isInStock()`: `0 < onHand`. It ignores `onHold` and `isTracked`, so an untracked product with `onHand = null` is "out of stock". This is the implementation of the contract method `Component/Inventory/Model/StockableInterface.php:25`.
  - `Resources/views/pimcore/stock_text.html.twig:3`: labels raw `onHand` as "Available Stock on Hand" (`Resources/translations/messages.en.yml:4`, de `:4` "Verfügbare Lagerbestände"). Available stock is actually `onHand - onHold`.
- **Problem:** any code or index that uses `isInStock()` (it is a public contract method, and Pimcore index getters or custom templates can call it) disagrees with the cart validation. Admins read a "available" number that ignores reservations.
- **Fix:** define availability once (see I-06). Make `Product::isInStock()` delegate to the same formula (`!tracked || onHand - onHold > 0`). Show both `onHand` and `onHand - onHold` in the admin label with the correct wording.

### B-04 — (dependent) `isTracked = null` (the default for every new product) means "unlimited stock", and no reservations are made
- **Where:** `Component/Inventory/Checker/AvailabilityChecker.php:32` (`!$stockable->getIsTracked()`). `OrderInventoryOperator.php` skips untracked items in all four methods. `CoreShopProduct.json` field `isTracked` is a `booleanSelect` with an "empty" option and no default value.
- **Problem:** a product created without explicitly setting the tri-state select is always available and never reserves stock, even if the merchant filled `onHand`. Nothing in the admin shows that `onHand` is being ignored (the admin renderer only shows "Stock not tracked"). Toggling the flag later unbalances the counters (**C-02**).
- **Fix:** give `isTracked` an explicit default (`defaultValue` in the class definition, or treat `null` as a separate, explicit config choice `core_shop_inventory.track_by_default`). Log or warn in admin when `onHand` is set on an untracked product.

### B-05 — Quantity edge cases in the checker: zero, negative, `NaN`/`INF`, and "available" means "at least 1 unit"
- **Where:** `Component/Inventory/Checker/AvailabilityChecker.php:25-33`. `Twig/InventoryExtension.php:36-37,41-44`.
- **Problem:**
  - `isStockSufficient($s, 0)`, `(-5)` and `(-INF)` return `true`. `NaN` returns `false`. No caller validates the quantity sign before calling the checker.
  - `isStockAvailable()` hard-codes `1`. A product sold in fractional units (`0.5` m left) shows "out of stock" in every frontend listing (`FrontendBundle/Resources/views/{Category/_grid,Category/_list,Product/_preview,Product/detail}.html.twig`), but can still be bought. A product with a minimum order quantity above the remaining stock shows "in stock" but cannot be bought.
  - Negative `onHand` (allowed, see B-07) together with `onHold = null` gives negative availability. That case is handled by accident.
- **Fix:** reject non-finite or non-positive quantities (`InvalidArgumentException`, or `false`). Let `isStockAvailable()` take the product's minimum purchasable quantity into account (a `Component/Core` decorator of the checker, since `Component/Inventory` does not know units).

### B-06 — `InStock` constraint uses the Symfony 7.4-deprecated options-array construction (removed in 8.0) and is unusable as an attribute
- **Where:** `Validator/Constraints/InStock.php:23-48`.
- **Problem:** the class has no constructor, untyped public properties (`$message`, `$stockablePath`, `$quantityPath`) and no `#[HasNamedArguments]`. YAML/XML mapping (`- InStock: { message: … }`) and `new InStock(['message' => …])` go through `Constraint::__construct(array $options)`. That path triggers a deprecation in Symfony 7.4 ("Passing an array of options to configure the … constraint is deprecated, use named arguments instead") and is removed in 8.0. Without `#[\Attribute]` the constraint cannot be used as `#[InStock]` on PHP 8.4 classes.
- **Fix:** add `#[\Attribute(\Attribute::TARGET_CLASS)]`, a constructor with typed named arguments plus `#[HasNamedArguments]` (`?string $message = null, ?string $stockablePath = null, ?string $quantityPath = null, ?array $groups = null, mixed $payload = null`), and typed properties. See also I-03.

### B-07 — (dependent) Stock fields accept negative values, and `onHold` can exceed `onHand`
- **Where:** `CoreBundle/Resources/install/pimcore/classes/CoreShopProductBundle/CoreShopProduct.json` fields `onHand` / `onHold` (`unsigned: false`, `minValue: null`). `onHand` is editable. `StockableInterface` setters accept any `?int`.
- **Problem:** an admin can enter `-3` in `onHand`. Combined with **C-01**/**B-01**/**B-02**, `onHold` regularly exceeds `onHand`. The checker never reports anything, and the admin label (`stock_text.html.twig`) shows a green `alert-success` box for any value, including negative or zero stock.
- **Fix:** set `minValue: 0` on `onHand`, or explicitly support backorders (then document negative `onHand`). Show `alert-danger`/`alert-warning` in `stock_text.html.twig` when available stock is `<= 0`. Add an integrity check (command or admin report) for `onHold > onHand` and `onHold < 0`.

## Bundle code

### B-08 — `InStockValidator` crashes instead of validating on unexpected input
- **Where:** `Validator/Constraints/InStockValidator.php:39-63`.
- **Problem:**
  - `$this->accessor->getValue($value, …)` throws `UnexpectedTypeException` when `$value` is `null` or a scalar, and `NoSuchPropertyException` when the configured path doesn't exist. Validators should return early on `null` and throw `UnexpectedValueException` for wrong types, so Symfony can report the error properly.
  - The file declares `strict_types=1`. A quantity read through a path that returns a numeric string (form data, DTOs with `string` properties) causes a `TypeError` when passed to `isStockSufficient(…, float $quantity)`. `int` is fine.
  - `Assert::isInstanceOf($constraint, InStock::class)` throws a Webmozart exception instead of the Symfony convention `UnexpectedTypeException`.
- **Fix:** `if (null === $value) return;`, guard with `$this->accessor->isReadable()`, cast or validate the quantity (`is_numeric` → `(float)`, else return or add a violation), and use `UnexpectedTypeException` for the constraint check.

### B-09 — Twig functions throw a `TypeError` for non-stockable or `null` products
- **Where:** `Twig/InventoryExtension.php:36-37,41`. Callers: `FrontendBundle/Resources/views/Category/_grid.html.twig:35`, `Category/_list.html.twig:39`, `Product/_preview.html.twig:22`, `Product/detail.html.twig:61`.
- **Problem:** both functions require `StockableInterface`. Category grids render every purchasable returned by the listing. A custom purchasable that doesn't implement `StockableInterface`, or a `null` (deleted variant, broken relation), turns the whole category page into a 500. CoreBundle's validators already handle this case gracefully (`if (!$product instanceof StockableInterface) continue;`).
- **Fix:** accept `mixed`/`?object` and return `true` (not tracked → available) for non-stockables, matching the validators' behaviour.

### B-10 — `core_shop_inventory.checker` is not validated; a wrong value breaks the container or fails at runtime
- **Where:** `DependencyInjection/Configuration.php:37`, `DependencyInjection/CoreShopInventoryExtension.php:37-40`, `Resources/config/services.yml:5`.
- **Problem:** the configured string is used as an alias target unchanged. A typo or a class name that isn't registered as a service fails container compilation with a generic "non-existent service" error that doesn't mention this option. A service that doesn't implement `AvailabilityCheckerInterface` only fails later, with a `TypeError` in whatever service gets it first. The alias in `services.yml:5` is always overwritten by the extension. It is dead config, and it can mislead people into thinking that editing the YAML changes the checker.
- **Fix:** remove the YAML alias. Validate in a compiler pass that the target exists and `is_subclass_of(…, AvailabilityCheckerInterface::class)`, then throw an `InvalidConfigurationException` that names `core_shop_inventory.checker`. Add `->info()` to the node.

### B-11 — `composer.json` (bundle and component) does not declare what the code uses
- **Where:** `composer.json:26-35`, `Component/Inventory/composer.json`.
- **Problem:**
  - No `"php": "^8.4"` (root requires `~8.4.0`).
  - The bundle uses, but doesn't require: `symfony/validator`, `symfony/property-access`, `symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel` (all `^7.4`), `twig/twig`, `pimcore/pimcore` (`DynamicTextLabelInterface`, `Concrete`), `webmozart/assert`, and `coreshop/pimcore-bundle` (the base class of `AbstractModelExtension`).
  - `Component/Inventory` requires `coreshop/resource` but uses nothing from it.
  - `require-dev` pins PHPStan 1.x (`phpstan/phpstan ^1.10` and extensions), which doesn't support the PHP 8.4 syntax level that PHPStan 2.x does.
- **Fix:** declare the real dependencies with `^7.4` constraints, drop the unused one, and bump PHPStan to `^2.1` with the matching extensions.

### B-12 — (dependent) Inventory operator error messages call a method that isn't on the contract
- **Where:** `Component/Core/Inventory/Operator/OrderInventoryOperator.php:90,99,130` use `$product->getName()`.
- **Problem:** `$product` is only known to be `StockableInterface`, which has `getInventoryName()`, not `getName()`. For a stockable that isn't a Core `Product`, building the error message itself throws `Error: Call to undefined method`, and the real cause is hidden.
- **Fix:** use `getInventoryName()`.
