# Core Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all 222 PHP files of this component (`Cart`, `CartItem`, `Configuration`, `Context`, `Currency`, `Customer`, `Index`, `Inventory`, `Locale`, `Model`, `Notification`, `Order`, `Payment`, `Portlet`, `Product`, `ProductQuantityPriceRules`, `Provider`, `Report`, `Repository`, `Rule`, `Shipping`, `Taxation`, `Tracking`, `Translation`, `Wishlist`, `Events.php`), `composer.json`, `README.md`. Also checked: the config that wires or maps these classes. That is `CoreBundle/Resources/config/{services.yml,services/*.yml,doctrine/model/*.orm.xml,serializer/Model.*.yml,validation/*.yml,pimcore/{workflow.yml,coreshop/*.yml}}`, `CoreBundle/Resources/install/pimcore/classes/**` and `CustomerBundle/Resources/config/services.yml`. It also covers the CoreBundle repository implementations (`CoreBundle/Doctrine/ORM/*`, `CoreBundle/Pimcore/Repository/*`) and the direct consumers in `Component/{Order,ProductQuantityPriceRules,Currency,Customer}` and `Bundle/{CoreBundle,FrontendBundle,OrderBundle,TrackingBundle}`.

Reference for API checks: `vendor/` = `../pimcore2teszt10211524/vendor/` (`symfony/*` 7.4.x, `doctrine/orm` 2.20.13, `doctrine/dbal` 3.10.6, `payum/payum` 1.7.7, Pimcore via `six-it/pimcore-source`) and the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml` (+ `...Deprecations.log`). The vendored `six-it/core-shop` copy of this component is identical to the working tree (`diff -rq` is empty).

"Critical" here means: a security issue, money charged or stored wrongly, data loss or data written wrongly, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have their root cause or part of the fix outside this component. Items marked **(reproduced)** were checked with a PHP 8.4.27 script against the vendored classes. Items marked **(traced)** were followed through the code but not run end to end.

**Symfony 7.4 / PHP 8.4 compatibility:** no blockers. `php -l` (PHP 8.4.27) passes on all 222 files, and every class, interface and trait loads under `E_ALL` / `DebugClassLoader` without deprecations. There are no implicitly nullable parameters, no dynamic properties and no removed Symfony 7.4 APIs. The container deprecation log has no entries for `CoreShop\Component\Core\*`. All service definitions resolve, and their arguments match the constructors (the one exception, `CartPriceRuleVoucherProcessor`, is already tracked as CoreBundle **B-36**). Forward-compatibility items (native types, `readonly`, `#[\Override]`, `ReflectionProperty::setAccessible()` deprecated in PHP 8.5, DBAL 4 custom types, `eraseCredentials()`) are in TODO-3.

**Overlap with other TODOs:** the following critical items whose code lives (partly) in this component are already tracked elsewhere and are **not repeated**:
- `Bundle/CoreBundle/TODO-1-CRITICAL.md` **C-05** (plaintext password in the session; `User::eraseCredentials()`), **C-08** / **C-09** (product store values data loss through inheritance and `set<Field>ForStore()`).
- `Bundle/PaymentBundle/TODO-1-CRITICAL.md` **C-03** (`CartPaymentProcessor` sets `paymentTotal` before the fee adjustment), **C-04** (`NULL` rule label crashes `CartPaymentProcessor:66`).
- `Bundle/CurrencyBundle/TODO-1-CRITICAL.md` **C-04** (`Currency.countries` cascade-all / orphan-removal: deleting a currency deletes its countries).
- `Component/Currency/TODO-1-CRITICAL.md` **C-01** (`paymentTotal` in the base currency), **C-02** (a deleted currency makes every `*/Rule/Action/*Amount*` and quantity-range action throw).
- `Bundle/InventoryBundle/TODO-1-CRITICAL.md` **C-01** / `Component/Inventory/TODO-1-CRITICAL.md` **C-01** (non-atomic stock updates in `Inventory/Operator/OrderInventoryOperator.php`), **C-02** (`sell()` assertion inside the `pay` callback).
- `Component/Customer/TODO-1-CRITICAL.md` **C-01** (a guest adopts a customer, so group-based rules apply).
- `Bundle/OrderBundle/TODO-1-CRITICAL.md` **C-07** (manual payments vs. `paymentTotal` units).
- `Bundle/LocaleBundle/TODO-1-CRITICAL.md` **C-01** (`Translation/TranslatableEntityPimcoreLocaleAssigner` duplicate and not wired).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Cart price rules: money discounted wrongly

### C-01 — Credit vouchers can be spent again and again: `creditUsed` is overwritten instead of added to, and it is stored in the wrong currency
- **Where:** `Cart/Rule/Action/VoucherCreditActionProcessor.php:57-62`, `:81-84`. **(dependent)** `Component/Order/Modifier/VoucherModifier.php:61-63` (`increment()`), `:123-125` (`decrement()`).
- **Problem:**
  - The processor computes the remaining credit as `creditAvailable - creditUsed`. When an order is committed, `VoucherModifier::increment()` runs `setCreditUsed(-1 * $item->getDiscount(true))`. That **replaces** the used amount with this order's discount.
  - Example: a credit of 100 is used for 60 (`creditUsed = 60`), then for 40 (`creditUsed = 40`, but it should be 100). 60 is available again, and this repeats without end.
  - `decrement()` subtracts from the overwritten value, so cancelling an order corrupts the value further.
  - `getDiscount(true)` is in the **cart** currency, but `creditUsed` and `creditAvailable` are in the voucher's `creditCurrency`. The processor converts credit to the cart currency (`:58-62`), but nothing converts back when the amount is stored.
- **Fix:** in `increment()`, add to the used amount: `creditUsed + convert(discount, cartCurrency → creditCurrency)`. Mirror this in `decrement()`. Do both in one locked or atomic update, because concurrent orders race on the same row. Add a regression test with two consecutive orders.

### C-02 — Amount-based cart and item actions take `min()` of two amounts in different currencies, then convert the result as if it were in the rule currency
- **Where:** `Cart/Rule/Action/DiscountAmountActionProcessor.php:64-91`, `CartItem/Rule/Action/DiscountAmountActionProcessor.php:64-91`, `Cart/Rule/Action/VoucherCreditActionProcessor.php:57-62`, `:81-84`.
- **Problem:** `getApplicableAmount($cartAmount, $ruleAmount)` takes `min()` of the cart or item amount (cart currency) and the rule amount (rule currency, or the voucher's `creditCurrency`). It then converts the result from the **rule** currency to the cart currency. When the cart amount is the smaller one, a cart-currency amount is converted as if it were in the rule currency.
  - Example: the cart is 50.00 CHF and the rule is 100 EUR. Then `min = 5000`, and converting EUR→CHF gives **53.76 CHF of discount on a 50 CHF cart**. With the opposite rate, the customer gets less than the cart value. The arithmetic is **(reproduced)**.
  - The cart currency is always the store's base currency (`Context/StoreBasedCartContext.php:58`). So this hits every multi-store or multi-currency setup where the rule or credit currency differs from the store currency.
- **Fix:** convert the rule amount or credit into the cart currency **first**, and only then take `min()` with the cart amount. Use the same order in all three processors, and remove the `getApplicableAmount()` indirection.

### C-03 — Stacked cart rules can discount more than the cart is worth, so the order total becomes negative
- **Where:** `Cart/Rule/Applier/CartRuleApplier.php:83-91`, `:219-225`; `Cart/Rule/Action/DiscountPercentActionProcessor.php:57-64` (compare `Cart/Rule/Action/DiscountAmountActionProcessor.php:66-74`).
- **Problem:** the applier caps a discount at `Σ item->getTotal($withTax)`. Cart-level discounts are stored on the items only as **neutral** adjustments, so item totals never shrink. Each rule can therefore discount up to 100 % of the items again. `DiscountPercentActionProcessor` bases its discount on `getSubtotal()`, which ignores earlier cart-rule discounts.
  - Each percent rule is validated to be ≤ 100 % on its own (`OrderBundle/Form/Type/Rule/Action/DiscountPercentConfigurationType.php:45`). But two combinable rules (for example 60 % + 50 %, or an 80-unit amount rule followed by a 50 % rule) take the cart total below zero.
  - The negative total is stored on the order and passed to payment.
- **Fix:** in `apply()`, cap the discount at what is left: `Σ item totals + cart->getAdjustmentsTotal(CART_PRICE_RULE, $withTax)` (that adjustment total is negative), and never below 0. Alternatively, also base the percent discount on the remaining amount. As a backstop, add a cart-level guard that clamps the total to ≥ 0.

### C-04 — (reproduced) Gross-mode cart discounts write tax lines that do not match the totals; with mixed rates, item adjustments get net/gross pairs that cannot exist
- **Where:** `Cart/Rule/Applier/CartRuleApplier.php:84`, `:97`, `:114-133`, `:162-208`, `:219-225`.
- **Problem:** the gross discount is first split by **net** item totals (`:84`, `:97`), and the net total is computed from that split. Then the gross total is split again by **gross** totals, and the net total by **net** totals (`:170-171`). Taxes are collected per item from the second gross split (`:192-198`). With mixed tax rates, the net/gross pair of each item's adjustment no longer matches its rate, and the sum of the collected taxes differs from `gross − net` of the cart adjustment.
  - Reproduced with the real `ProportionalIntegerDistributor`: a 100.00 gross discount on two items of 100.00 net each.
    - 7 % / 19 %: the cart adjustment tax is 11.25, but the item tax lines are reduced by 11.50.
    - 0 % / 27 %: the cart adjustment tax is 10.63, but the tax lines are 11.89. The 0 % item gets adjustment gross 44.05 and net 44.69, which means **negative tax**.
  - `Order/Processor/CartTaxProcessor.php` merges these item taxes, so the tax breakdown on carts, orders, invoices and tax reports is wrong whenever rates are mixed.
- **Fix:** split once: by gross totals when `$withTax` is set, by net otherwise. Derive each item's other amount from its own tax calculator, and collect taxes from those same per-item amounts. Then sum the per-item values for the cart adjustment and the `PriceRuleItem`. Put any rounding remainder on one item, and keep net, gross and tax consistent for it. This also removes the dead "missing cents" code (TODO-3 **I-05**).

---

## Shipping: money charged wrongly

### C-05 — (reproduced) The `cartItems` shipping-tax strategy taxes each share at the wrong rule when two cart items have the same tax rule
- **Where:** `Shipping/Taxation/TaxCalculationStrategyCartItems.php:101-106` (`$totalAmount` is keyed by tax-rule id, but `$taxRules[]` gets one entry per **item**), `:69` (`array_values($totalAmount)`), `:121-126` (`$taxRuleGroup[$i]` is indexed with the position in `$distributedAmount`).
- **Problem:** after `array_values()`, index `i` of the distributed amounts is the i-th **distinct** rule, but `$taxRuleGroup[$i]` is the rule of the i-th **item**. In any cart where two items share a rule, all later shares move onto the wrong rule.
  - Reproduced: items A (20 %, 10.00), B (20 %, 10.00) and C (5 %, 80.00), with 10.00 shipping. The distributed shares are `[200, 800]`, and the rules used are `[20 %, 20 %, …]`. The 8.00 share that belongs to the 5 % rule is taxed at 20 %, so shipping tax is 2.00 instead of 0.80.
  - In a net store, the customer pays 1.20 too much. In a gross store, the net shipping value and the stored per-rate tax lines are wrong.
  - This affects every multi-item cart in a mixed-rate shop that uses the `cartItems` strategy, and it flows into orders, invoices and tax reports.
- **Fix:** keep one rule per key (`$taxRules[$taxRule->getId()] = $taxRule`) and iterate `array_values($taxRules)` in the same order as `array_values($totalAmount)`. Add a unit test with two items that share a rule plus one item with a different rule. The zero-total crash in the same method is TODO-2 **B-09**.

### C-06 — (reproduced) The shipping "discount amount" action is not capped, so shipping becomes negative and lowers the order total
- **Where:** `Shipping/Rule/Action/DiscountAmountActionProcessor.php:38-54` (returns `-amount` and ignores `$price`). **(dependent)** `Shipping/Calculator/CarrierShippingRulePriceCalculator.php:61` (`return $price + $modifications;`), `Component/Shipping/Processor/ShippingRuleActionProcessor.php` (`getModification()` sums without a lower bound), `Shipping/Calculator/TaxedCarrierPriceRuleCalculator.php:63-94` (passes the negative value on).
- **Problem:** a rule with price 4.90 and a "discount 10.00" action (or a discount action with no price action) gives a shipping price of −5.10 (reproduced: `490 + -1000 = -510`). `Order/Processor/CartShippingProcessor.php` adds this as a SHIPPING adjustment, so the order total drops 5.10 more than the shipping cost. Customers are undercharged, and negative shipping tax lines are stored. The cart equivalent (`Cart/Rule/Action/DiscountAmountActionProcessor.php:90`) caps with `min()`. The shipping one does not.
- **Fix:** in `DiscountAmountActionProcessor`, return `-min($converted, max(0, $price))`. Also clamp the final result in `CarrierShippingRulePriceCalculator` with `max(0, $price + $modifications)`, so combined modifications (several discounts, or a percentage discount over 100 %) can never go below zero.

---

## Product prices: money charged wrongly

### C-07 — (reproduced) The item quantity factor is applied twice for quantity-price ranges on items with a unit, and not at all for `fixed` ranges without a unit
- **Where:** `Order/Processor/CartItemsProcessor.php:83-85` (divides `$itemPrice` by `itemQuantityFactor`) and `:87-96` (passes that already divided price as `$originalPrice` to `detectQuantityPrice()`). `ProductQuantityPriceRules/Calculator/UnitVolumeCalculator.php:66-72` (unit path: divides again, with an `(int)` cast), `:49-55` (non-unit path: delegates to the inner `VolumeCalculator`, which never divides), `:77-83` (`calculateForRange()`, no division). **(dependent)** `CoreBundle/Twig/ProductQuantityPriceRuleRangesPriceExtension.php`.
- **Problem:** `CartItemsProcessor.php:72-73` sets `context['unitDefinition']` for every item that has a unit, which selects the `UnitVolumeCalculator` path.
  - Unit path with a `percentageDecrease`, `percentageIncrease`, `amountDecrease` or `amountIncrease` range: the action runs on the per-piece price, and the calculator divides by the factor **again**. Package price 1000, factor 10, 10 % decrease gives **9 instead of 90**, so the customer pays one tenth of the price (reproduced: `with unitDefinition: 9 (expected 90)`, `without unitDefinition: 90`). The stored order keeps this price.
  - Non-unit path with a `fixed` range: the fixed range price (per package) is never divided, so the customer pays factor × the correct price.
  - `(int)` truncates instead of rounding.
  - The Twig range price (via `calculateForRange()`) never applies the factor, so the price shown differs from the price charged.
- **Fix:** apply the factor in exactly one place. Either `CartItemsProcessor` passes the **undivided** price into quantity-price detection and divides once afterwards, or the calculator divides, but then only for absolute (`fixed`) results and the same way in both paths. Use `round()`, not `(int)`. Make `calculateForRange()` and the Twig extension follow the same rule. Add a unit test for each action type, with and without a unit, for factor > 1. Related: TODO-2 **B-04** (the factor is not applied to the retail price, discount price or discount).

---

## Data written wrongly / data loss

### C-08 — (reproduced) A `company_only` customer who edits a shared company address moves it out of the company's address book
- **Where:** `Customer/Address/AddressAssignmentManager.php:100-101`, `:123`; `Customer/CustomerTransformHelper.php:124-150` (`removeOldRelations = true`). **(dependent)** `FrontendBundle/Controller/CustomerController.php:171-192`, `CoreBundle/Form/Extension/AddressTypeExtension.php:33-35`.
- **Problem:**
  - For access type `company_only`, `getAddressAffiliationTypesForCustomer()` returns `null`. The form therefore has no `addressAffiliation` field, and the controller passes `$affiliation = null`.
  - `checkAddressAffiliationPermissionForCustomer()` allows the edit, because the company owns the address.
  - `allocateAddressByAffiliation()` then turns `null` into `own` whenever a company exists. `moveAddressToNewAddressStack($address, $customer)` re-parents the company address into the customer's folder, removes it from the company and from **every other customer** that references it (`removeAddressRelations()`), and adds it to the customer's own list.
  - A `company_only` customer can neither see (`Customer/Allocator/CustomerAddressAllocator.php` returns only company addresses) nor edit (`:85-87`) their own addresses. After one save, the shared address disappears for the whole company, including the editor.
  - A new address added by a `company_only` customer ends up in the same place and is never visible to them.
  - Reproduced: permission `true`, affiliation types `NULL`, `moveAddressToNewAddressStack` → holder = `CUSTOMER`.
- **Fix:** derive the default affiliation from the access type. `company_only` uses `company`. `own_and_company` without an explicit choice keeps the detected affiliation (`detectAddressAffiliationForCustomer()`). Reject any affiliation the access type does not allow, and do not move an existing address when its affiliation did not change. Related: TODO-2 **B-36**.

### C-09 — (traced) "Solidify unit definitions" replaces each variant's own store values (price, tax rule) with the parent's
- **Where:** `Product/Cloner/ProductUnitDefinitionsCloner.php:38-40`, `:70`. **(dependent)** `CoreBundle/Controller/ProductVariantUnitSolidifierController.php:101-130` (`clone($variant, $object, false)` followed by `$variant->save()`), `CoreBundle/CoreExtension/StoreValues.php:410+` (store values missing from the list are dropped on save).
- **Problem:** the `$resetExistingData = false` guard checks only `hasUnitDefinitions()`. A variant that has its own prices but no own unit definitions gets `setStoreValues(<copy of the parent's store values>)`. Saving the variant then deletes its own `ProductStoreValues` rows, so the variant's price, tax rule and other per-store data are silently replaced by the parent's. This is triggered from the admin, cannot be undone, and affects every variant of the product.
- **Fix:** when the variant already has store values, keep them. Only add the missing `ProductUnitDefinitionPrice` rows to the matching existing store value, and copy store values only for stores where the variant has none. Alternatively, reject the operation when variants have their own store values. In both cases, report in the response which variants were skipped.
