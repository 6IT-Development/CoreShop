# InventoryBundle – TODO 3: Improvements (code quality, readability, logic)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this bundle.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.
None of these items changes behaviour on its own, unless the item says so. Items that break BC are marked **[BC]**.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 idioms

### I-01 — `readonly` promoted properties and `final` everywhere
- `Pimcore/Renderer/StockOnHandRenderer.php:26-30`: the class isn't `final`, and `private Environment $twig` isn't `readonly`.
- `Twig/InventoryExtension.php:28-30`, `Validator/Constraints/InStockValidator.php:31-37`: make the promoted properties `private readonly`. In `InStockValidator`, `$accessor` can also be `readonly`, or be injected (I-04).
- (dependent) `Component/Core/Inventory/Operator/OrderInventoryOperator.php:30-33`: `private readonly ObjectManager`.
- Services without state can be `final readonly class` (`AvailabilityChecker`, `InventoryExtension`, `StockOnHandRenderer`).

### I-02 — Type the remaining signatures **[BC for implementers]**
- `Component/Inventory/Model/StockableInterface.php:29,33,37`: setters have no return type. Add `: static` (fluent), or `: void` to match the Pimcore-generated setters. Check what `Pimcore\Model\DataObject\CoreShopProduct` generates (`: static`) and use the same.
- `InStockValidator::validate($value, …)` → `validate(mixed $value, Constraint $constraint): void`.
- `InventoryExtension::isStockSufficient(…, float $quantity = 1)` → `float|int $quantity = 1` (documents intent; no coercion surprise), or `mixed` with validation (B-09).
- `AvailabilityCheckerInterface::isStockSufficient(…, float $quantity)`: same note.
- `InStock::getTargets(): string` → `string|array` as declared by the parent. This is cosmetic, but it allows `[self::CLASS_CONSTRAINT]` later.

### I-03 — Modern constraint definition for `InStock`
Completes B-06:
- `#[\Attribute(\Attribute::TARGET_CLASS)]`, typed properties (`public string $message = '…'`, `public string $stockablePath = 'stockable'`, `public string $quantityPath = 'quantity'`), a constructor with named arguments and `#[HasNamedArguments]`.
- Replace the `validatedBy()` alias (`'coreshop_in_stock'`) with the default (`InStockValidator::class`), and drop the `alias:` from the tag in `Resources/config/services.yml:12`. Keep the alias for one minor version if third-party code uses it.
- Message placeholders: switch from `%stockable%` to Symfony's `{{ stockable }}` convention, together with the translation files (I-10). This needs the same change in CoreBundle's `AddToCartAvailability`/`CartStockAvailability`, which share the message key.
- The constraint isn't referenced anywhere in CoreShop: CoreBundle uses its own `AddToCartAvailability`/`CartStockAvailability`. Decide to either keep it as public API (document it in the README) or deprecate it with `trigger_deprecation()`.

### I-04 — `InStockValidator` readability and Symfony conventions
- Inject `PropertyAccessorInterface` (the `property_accessor` service, which is cached) instead of calling `PropertyAccess::createPropertyAccessor()` in the constructor.
- Merge the two early returns (`null === $stockable` and `!instanceof`) into one `if (!$stockable instanceof StockableInterface) { return; }`. The null check is redundant.
- Use `$this->context->buildViolation($constraint->message)->setParameter('{{ stockable }}', …)->addViolation()` instead of `addViolation($msg, $params)`. This allows `->atPath($constraint->quantityPath)`, so the error attaches to the quantity field instead of the root form.
- Replace `/** @var InStock $constraint */` + `Assert` with `if (!$constraint instanceof InStock) { throw new UnexpectedTypeException(...); }` (B-08).

## Single source of truth for availability

### I-05 — (dependent) Deduplicate `OrderInventoryOperator`
- **Where:** `Component/Core/Inventory/Operator/OrderInventoryOperator.php:50-159`.
- The four public methods repeat the same loop: `getItems` → `instanceof StockableInterface` → `getIsTracked` → `(int) ceil(getDefaultUnitQuantity())` (computed up to 4 times per line) → `persist` → `flush`.
- Extract a private generator `trackedItems(OrderInterface): iterable<array{StockableInterface, int}>` that aggregates quantities per product (also fixes the per-line check in **C-02**), plus one `apply(callable)` that handles transaction and flush.
- Replace Webmozart `Assert` (developer assertions) with a domain exception such as `InsufficientStockException` that carries product and quantities. The exception can be caught and shown in the UI, which matters for **C-01**/**C-02**.

### I-06 — Centralise the "available quantity" formula
- `onHand - onHold` (with `null` → `0`) is spelled out in `AvailabilityChecker`, implied differently in `Product::isInStock()` (B-03), and missing in the admin template.
- Add `AvailabilityCheckerInterface::getAvailableQuantity(StockableInterface): int|float` (or a small `StockLevel` value object: `onHand`, `onHold`, `available`, `tracked`). Use it in the checker, `Product::isInStock()`, the Twig extension (new `coreshop_inventory_available_quantity()` function) and `stock_text.html.twig`.
- In `AvailabilityChecker::isStockSufficient()`, write `($stockable->getOnHand() ?? 0) - ($stockable->getOnHold() ?? 0)` explicitly instead of relying on `null - null === 0`. It reads better and passes PHPStan at a higher level.

### I-07 — Consider renaming the tri-state flag **[BC]**
- `getIsTracked(): ?bool` / `setIsTracked()` reads awkwardly, and Twig resolves `stockable.isTracked` through `getIsTracked()` only by its getter-guessing rules.
- In the next major: add `isTracked(): bool` (null resolved by the configured default, see B-04) and deprecate `getIsTracked()`.

## Symfony 7.4 DI and bundle structure

### I-08 — Services: private by default, autowiring, and no duplicate definitions
- **Where:** `Resources/config/services.yml`.
- `_defaults: public: true` makes every service public. Only these need to be public:
  - `StockOnHandRenderer`: Pimcore resolves `renderingClass: "@…StockOnHandRenderer"` (`CoreShopProduct.json`) through `$container->get()`.
  - The `AvailabilityCheckerInterface` alias, kept public for BC (set in the extension).
- Use `_defaults: { autowire: true, autoconfigure: true, public: false }`. That makes the explicit `arguments:` and the `twig.extension` tag unnecessary.
- Remove the alias at `:5`, which the extension overrides (B-10).
- Indentation in the file is inconsistent (2, 3 and 4 spaces under `arguments:`/`tags:`).

### I-09 — Simplify the extension and the bundle class
- `DependencyInjection/CoreShopInventoryExtension.php:28` extends `AbstractModelExtension` but registers no resources, models or stack. Extending `Symfony\Component\DependencyInjection\Extension\Extension` (or `AbstractPimcoreExtension` if Pimcore-specific helpers are needed later) removes the hidden dependency on ResourceBundle and PimcoreBundle (B-11).
- Optionally move to `AbstractBundle` (`configure()` + `loadExtension()`) and drop `Configuration.php` / the extension class. That is the Symfony ≥ 6.1 recommended structure for a bundle this small. Keep the config root `core_shop_inventory`.
- `Configuration.php:31`: the `/** @var ArrayNodeDefinition $rootNode */` docblock is only there for PHPStan. With Symfony 7.4 stubs, `getRootNode()` is fine without it.
- `CoreShopInventoryBundle` isn't registered as dependent on `CoreShopResourceBundle`, unlike the other CoreShop bundles (`AbstractResourceBundle` / `DependentBundleInterface`). Either declare the dependency, or remove it as described above.

### I-10 — Twig extension: lazy runtime
- `Twig/InventoryExtension.php` builds the checker (and its whole dependency chain, if a custom checker is configured) as soon as Twig loads extensions, on every request.
- Split it into `InventoryExtension` (function definitions only) and `InventoryRuntime implements RuntimeExtensionInterface` (`[InventoryRuntime::class, 'isStockAvailable']`). Twig then creates the checker only when a template calls the function.
- `#[AsTwigFunction]` (Twig ≥ 3.21) would be cleaner, but the root `composer.json` pins `twig/twig >=3.9.0 <3.14.0`. Revisit after the pin is lifted.

## Translations, template, docs, tests

### I-11 — Translation files
- `Resources/translations/validators.{de,en,it}.yml` contain `coreshop.cart_item.quantity_below_min_limit` / `quantity_above_max_limit`, which have nothing to do with inventory. They are used by CoreBundle's `{AddToCart,Cart}{Minimum,Maximum}Quantity` constraints (`CoreBundle/Resources/config/validation/{AddToCart,Order}.yml`). Move them to CoreBundle (or OrderBundle).
- `messages.it.yml` is missing, although `validators.it.yml` exists. Other locales that CoreBundle ships (`admin.*.yml` in many languages) have no inventory messages at all.
- `validators.de.yml` and `validators.en.yml` have no trailing newline.
- If the admin label is rendered for admin users, consider using the `admin` translation domain, so the label follows the admin user's language and not the request locale.

### I-12 — Admin stock label template
- `Resources/views/pimcore/stock_text.html.twig`: the colour is always `alert-success` when tracked (B-07). Show "available = onHand − onHold" (I-06), and pick success, warning or danger based on that value.
- `stockable.onHand ?: 0` → `stockable.onHand ?? 0` (intent is null-coalescing; `?:` also swallows `0`, which is harmless here but misleading).
- Indent the `{% if %}` blocks. Add a final newline.

### I-13 — README documentation
- `README.md` is the generic CoreShop boilerplate (with typos: "It is build", wrong underline lengths, and an `http://www.coreshop.org` link next to the `coreshop.com` homepage). It doesn't document anything this bundle provides:
  - the config option `core_shop_inventory.checker`;
  - the Twig functions `coreshop_inventory_is_available` / `coreshop_inventory_is_sufficient`;
  - the `InStock` constraint and its options;
  - the `StockOnHandRenderer` for Pimcore class layouts;
  - how `onHand`/`onHold`/`isTracked` interact with the order workflow (`hold`/`sell`/`release`/`giveBack`).

### I-14 — Tests
- There are no unit tests for `AvailabilityChecker`, `InStockValidator`, `InventoryExtension` or `OrderInventoryOperator`. Behat only has a few stock steps (`Behat/Context/Setup/ProductContext.php` `setOnHold`).
- Add PHPUnit tests covering: null/negative/fractional quantities (B-05), the `null` tracking flag (B-04), the cancel-after-partial-refund paths (B-01), the rounding mismatch (B-02), and duplicate lines of the same product (C-02).
- Add a concurrency test (two kernels or processes reserving the last unit) once **C-01** is fixed.
