# Core Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are wrong behaviours, crashes in limited areas, and contract violations that do not meet the "critical" bar.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this component. Items marked **(reproduced)** were checked with a PHP 8.4.27 script. Items marked **(plausible)** follow from the code, but the trigger depends on data or custom code.

**Overlap with other TODOs:** see the list at the end of this file. Those items are **not repeated** here.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Cart price rules and cart processors

### B-01 — A rule with several actions keeps only the last action's discount on its `PriceRuleItem`
- **Where:** `Cart/Rule/Applier/CartRuleApplier.php:228-229`, `CartItem/Rule/Applier/CartItemRuleApplier.php:174-175`. Caller: `Component/Order/Cart/Rule/ProposalCartPriceRuleCalculator.php:59-75`.
- **Problem:** the proposal calculator resets the item's discount to 0 and then runs **every** action of the rule against the same `PriceRuleItem`. Each `apply()` calls `setDiscount()` with only its own amount, so it overwrites the previous one. The cart has one adjustment per action, but the price rule item only shows the last action. That item is shown in cart and order summaries and used by `VoucherModifier` for credit and in refunds.
- **Fix:** accumulate: `setDiscount($item->getDiscount(false) + $signedNet, false)`, and the same for gross.

### B-02 — (reproduced) `CartPriceRuleVoucherProcessor` removes price-rule items by a stale index, so it removes the wrong entry and orphans stay
- **Where:** `Order/Processor/CartPriceRuleVoucherProcessor.php:53-62`, plus the `unProcess()` calls at `:65`, `:75` and `:85`.
- **Problem:** the loop iterates over a snapshot (`$priceRuleItems->getItems()`) and calls `$priceRuleItems->remove($index)`. Pimcore's `Fieldcollection::remove()` uses `array_splice()`, which re-indexes. `unProcess()` → `Order::removePriceRule()` splices the same collection inside the loop. After the first removal, every later index points at a different item.
  - With items `[orphan, orphan, validVoucher]`, the processor removes the first orphan and the **voucher**, and keeps the second orphan. Reproduced against the real `Pimcore\Model\DataObject\Fieldcollection`.
  - The voucher is re-added later in the same loop. The orphan (`getCartPriceRule() === null`) stays in the cart and in the order, and it makes `OrderBundle` `CartPriceRuleValidator` stop validating (OrderBundle **B-34**).
- **Fix:** collect the items to remove first, and remove them by identity after the loop (`$cart->removePriceRule($item)`), or rebuild the collection with `setItems(array_values(array_filter(...)))`.

### B-03 — Custom order-item attributes are never updated once they exist
- **Where:** `Order/Processor/CartItemsProcessor.php:132-162`. **(dependent)** `Component/Order/Model/AttributesAwareTrait.php:36-75` (`hasAttributes()` matches by key, so `addAttribute()` is a no-op when the key exists).
- **Problem:** if a `ProductCustomAttributesCalculator` returns a new value for an existing key (for example one that depends on price, quantity or context), `addAttribute()` is skipped and the removal loop keeps the old attribute. The item keeps the value from the first calculation forever, and that value is written into the order.
- **Fix:** update the value of existing attributes (`setAttributeValue()`) or replace them, then remove the keys that are no longer returned. Iterate over a copy while removing.

### B-04 — `itemQuantityFactor` is applied to the item price only, not to the retail price, the discount price or the discount
- **Where:** `Order/Processor/CartItemsProcessor.php:83-85` vs. `:99-102`.
- **Problem:** `itemPrice` is per single item, but `itemRetailPrice`, `itemDiscountPrice` and `itemDiscount` are still per pack, and `getDiscount()` is computed from the undivided `$itemPriceWithoutDiscount`. The stored retail price is `factor` × the selling price, and the discount does not match. Strike-through prices, "you save" amounts, invoices and reports are wrong for these products.
- **Fix:** apply the same division to all four values (in one helper), or compute them from the divided base price. Do this together with TODO-1 **C-07**.

### B-05 — (dependent, plausible) A product without a resolvable price is added and sold at 0
- **Where:** `Order/Processor/CartItemsProcessor.php:80`, `:99-101`. **(dependent)** `Component/Order/Calculator/PurchasableCalculator.php` catches `NoPurchasable*PriceFoundException` and returns `0`.
- **Problem:** if no price calculator finds a price (for example a product assigned to a store without store values), the item is priced 0 without any error. Nothing in the add-to-cart or checkout validation rejects zero-price items, so the item can be ordered for free.
- **Fix:** treat "no price found" as not purchasable: remove the item or mark the cart invalid in `CartItemsProcessor` or an add-to-cart/checkout validator, instead of using 0.

### B-06 — Quantity-range calculation treats a price of 0 as "no price" only for items with a unit
- **Where:** `ProductQuantityPriceRules/Calculator/UnitVolumeCalculator.php:66-68`; compare `Component/ProductQuantityPriceRules/Calculator/VolumeCalculator.php` `calculateForQuantity()`.
- **Problem:** with a unit, a range that gives 0 (a 100 % decrease, or `fixed 0` for a free tier) throws `NoPriceFoundException`, and the cart silently falls back to the normal price. Without a unit, the same range returns 0, so the same rule behaves differently depending on whether the line has a unit.
- **Fix:** decide whether 0 is allowed, then handle it the same way in both paths and document it.

### B-07 — The product `QuantityConditionChecker` looks only at the first cart line of the product and compares quantities in the line's own unit
- **Where:** `Product/Rule/Condition/QuantityConditionChecker.php:42-50`.
- **Problem:** it returns the result for the first line whose product matches. Other lines with the same product (another unit definition, or a separate line added by an extension) are ignored. It compares `getQuantity()` (in the line's unit) rather than `getDefaultUnitQuantity()`, so "min 10" is met by 1 box of 12 only if the rule author thinks in boxes.
- **Fix:** sum over all lines of the product, using `getDefaultUnitQuantity()` (or `getQuantity()`; choose one and document it), then compare against min and max.

### B-08 — Quantity rounding can set a quantity of 0 although zero quantities are not allowed
- **Where:** `Order/Modifier/CartItemQuantityModifier.php:47` (checks the raw target) vs. `:51`, `:92-97` (rounds afterwards).
- **Problem:** with `allowZeroQuantity = false` and a unit precision of 2, a target of `0.004` passes the `0 >= $targetQuantity` check, is rounded to `0.0`, and is set on the item. The "unchanged" early return also compares the unrounded value, so a target that rounds to the current quantity still fires the PRE/POST update events.
- **Fix:** round first, then apply the zero/negative check and the "unchanged" comparison to the rounded value.

---

## Shipping and taxation

### B-09 — (reproduced) The `cartItems` shipping-tax strategy throws `DivisionByZeroError` when every taxable item has a total of 0
- **Where:** `Shipping/Taxation/TaxCalculationStrategyCartItems.php:65-69`. **(dependent)** `Component/Order/Distributor/ProportionalIntegerDistributor.php:29-33` (`/ $total`).
- **Problem:** the guard `!$totalAmount` checks only that the array is empty, not that the sum is 0. Free products, gift-only carts and 100 % item discounts call `distribute([0], $shippingAmount)`, which divides by zero. This happens during cart processing, so the cart and checkout pages return a 500.
- **Fix:** when `array_sum($totalAmount) <= 0`, fall back to an even split or the carrier's own tax rule, or return `[]`. Make the distributor handle a zero total too.

### B-10 — Shipping tax is skipped silently for carrier or tax-rule-group models that are not the concrete Core classes
- **Where:** `Order/Processor/CartTaxProcessor.php:93-97` (`instanceof Carrier`), `Shipping/Taxation/TaxCalculationStrategyTaxRule.php:30`, `:66` (`instanceof TaxRuleGroup`).
- **Problem:** `getTaxCalculationStrategy()` is declared on `CarrierInterface`, and models can be replaced through the resource configuration. A project carrier or tax-rule-group model that implements the interface without extending the concrete class gets **no shipping VAT**, and `shippingTaxRate` is not updated. An unknown strategy name (`registry->has()` false) is skipped without any message too.
- **Fix:** check against `CarrierInterface` / `TaxRuleGroupInterface`. Log or throw when the configured strategy is not registered.

### B-11 — `TaxCalculatorFactory` ignores the `active` flags, and its result depends on undefined row order
- **Where:** `Taxation/TaxCalculatorFactory.php:39-64`. **(dependent)** `CoreBundle/Doctrine/ORM/TaxRuleRepository.php` (`findForCountryAndState()` has no `ORDER BY` and no `active` filter).
- **Problem:**
  - `TaxRate` and `TaxRuleGroup` both have an `active` column that the admin UI can switch off (`TaxationBundle/.../tax/item.js:94`). Nothing checks it, so a deactivated rate or group is still charged.
  - The factory takes the combine behaviour from the first returned row and stops at the first `DISABLE_METHOD` row. Rows come back in arbitrary order, and country-specific rules are not sorted before wildcard rules (`country IS NULL`). So the "this tax only" behaviour, and whether a wildcard rate is added to a specific one, depend on database order.
- **Fix:** filter out inactive rates and groups (or, if `active` is meant to be UI-only, document that and remove it). Order deterministically: most specific first (state, then country, then wildcard), then by id.

### B-12 — `CachedTaxCalculatorFactory` keeps stale tax rates and grows without limit in long-running processes
- **Where:** `Taxation/CachedTaxCalculatorFactory.php:30-63`; wiring `CoreBundle/Resources/config/services/taxation.yml:13-16` (no `kernel.reset`).
- **Problem:** the cache is never reset. Its key includes every `ResourceInterface` in the context (cart, customer, store and so on), so Messenger and FrankenPHP/RoadRunner workers add one entry per cart. The cached calculators hold `TaxRate` entities, so a rate changed in the admin is not used until the worker restarts.
- **Fix:** implement `ResetInterface` and clear `$this->cache` in `reset()`. Build the key only from inputs that affect the result (rule-group id, country, state).

### B-13 — The product page computes tax from the cart's invoice address, but the cart computes it from the shipping address
- **Where:** `Provider/ContextBasedDefaultTaxAddressProvider.php:35-39`; compare `Order/Processor/CartTaxProcessor.php:83-86`, `Order/Processor/CartItemProcessor.php:63`, `Cart/Rule/Applier/CartRuleApplier.php:122`, `:183`, `CartItem/Rule/Applier/CartItemRuleApplier.php:103` (all use `getShippingAddress() ?: AddressProviderInterface`).
- **Problem:** `ShopperContext::getContext()` always includes `cart`, so the product price Twig functions (`TaxedProductPriceCalculator`, `ProductTaxExtension`, the quantity-range price extension) tax by the cart's **invoice** address. The cart taxes by the **shipping** address. When the two countries have different VAT rules (EU OSS, or a cart with only one address set), the price shown differs from the price charged.
- **Fix:** use the same rule as the cart processors (shipping address first, then the `AddressProviderInterface` fallback), ideally by having this provider call that same service.

### B-14 — The cart context always uses the store's base country and ignores the customer's address
- **Where:** `Cart/CartContextResolver.php:37-45`.
- **Problem:** `$context['country'] = $store->getBaseCountry()` is set for every cart. Product price rules evaluated inside the cart (`Product/Rule/Condition/{Countries,Zones}ConditionChecker` read `$params['country']`) therefore always see the base country. On the product page, `ShopperContext::getContext()` passes the visitor's country. A country- or zone-specific product price rule shows one price on the product page and charges another in the cart. If `baseCountry` is `null` (the column is nullable), `country` is `null`.
- **Fix:** use the shipping address country (falling back to the invoice address, then to the shopper or base country), matching **B-13**. Do not overwrite a `country` that the inner resolver already set.

### B-15 — Address providers may return `null`, but every consumer passes the result to a non-nullable parameter
- **Where:** `Provider/DefaultTaxAddressProviderInterface.php:25`, `Provider/AddressProviderInterface.php:26` (`?AddressInterface`); `Product/TaxedProductPriceCalculator.php:89-96` → `ProductTaxCalculatorFactoryInterface::getTaxCalculator(…, AddressInterface $address, …)`; `Provider/StoreBasedAddressProvider.php:38-54` returns `null` when the cart store is not a Core `StoreInterface`; consumers `Order/Processor/CartItemProcessor.php:63`, `Cart/Rule/Applier/CartRuleApplier.php:122`, `CartItem/Rule/Applier/CartItemRuleApplier.php:103`.
- **Problem:** the contract allows `null`, but every consumer throws a `TypeError` when it gets one. The default `ContextBasedDefaultTaxAddressProvider` never returns `null`; it dereferences instead (`$context['store']->getBaseCountry()` with `store => null` is an `Error`, and the base country may be `null`). A project provider that implements the interface correctly with `null` breaks every price.
- **Fix:** return a non-null `AddressInterface` and throw a documented exception, or make `getTaxCalculator()` accept `null` (no tax). Check that `store` is a `StoreInterface` before using it, and throw `CountryNotFoundException` when no country can be found.

---

## Rule conditions (cart, product, payment, shipping, notification)

### B-16 — Rule conditions dereference nullable zone, country, store, carrier, items or order without a check
- **Where:**
  - Payment: `Payment/Rule/Condition/ZonesConditionChecker.php:50-52`, `CountriesConditionChecker.php:43-45`, `StoresConditionChecker.php:37`, `CategoriesConditionChecker.php:54-56` (null store passed into the trait), `ProductsConditionChecker.php:54-58`, `CarrierConditionChecker.php:37`.
  - Shipping: `Shipping/Rule/Condition/ZonesConditionChecker.php:41-43`, `CountriesConditionChecker.php:34-36`, `StoresConditionChecker.php:39`, `CustomerGroupsConditionChecker.php:45` (`getCustomerGroups(): ?array` in `foreach`), `ProductsConditionChecker.php:62-64`, `Shipping/Taxation/TaxCalculationStrategyCartItems.php:82` (`getItems(): ?array`).
  - Notification: `Notification/Rule/Condition/StoresChecker.php:37` (`OrderDocumentInterface::getOrder(): ?OrderInterface`).
- **Problem:** `Country::getZone()` is nullable (`Country.orm.xml`: `zoneId` `on-delete="SET NULL"`). If a customer's country has no zone (for example because the zone was deleted) and any active payment or shipping rule has a zones condition, `$zone->getId()` throws on `null`.
  - For payment rules, this happens in `CartPaymentProcessor` (`findValidPaymentProviderRule()`), so every cart recalculation for that customer returns a 500.
  - For shipping rules, carrier validation and price calculation fail, so checkout breaks for the whole country.
  - The same pattern applies to an address without a country, an order without a store, a customer without groups, and an orphaned invoice or shipment (the notification message fails and is retried).
- **Fix:** return `false` (the condition is not met) when the value is `null`. Use null-safe chains: `$zone = $address?->getCountry()?->getZone(); return $zone instanceof ZoneInterface && …`, and `$subject->getOrder()?->getStore()`. The currencies, customers and customer-groups checkers are already tracked (see Overlap).

### B-17 — Rule processors read configuration keys without a guard, which gives a warning and then a `TypeError` under `strict_types`
- **Where:**
  - `?:` on keys that may be missing: `Cart/Rule/Condition/{Categories,Products}ConditionChecker.php:51/:46`, `CartItem/Rule/Condition/{Categories,Products}ConditionChecker.php:51/:50`, `Product/Rule/Condition/{Categories,Products}ConditionChecker.php:59/:57`, `Payment/Rule/Condition/{Categories,Products}ConditionChecker.php`, `Shipping/Rule/Condition/{Categories,Products}ConditionChecker.php:58` (`recursive`, `include_variants`).
  - `['gross']` passed to `bool $withTax`: `Cart/Rule/Action/DiscountAmountActionProcessor.php:51`, `:69-73`, `SurchargeAmountActionProcessor.php:50`, `CartItem/Rule/Action/DiscountAmountActionProcessor.php:51`, `:69-73`.
  - `Notification/Rule/Action/Order/OrderMailActionProcessor.php:58`, `:66` (`mails`, `sendInvoices`, `sendShipments` → `bool` parameter).
  - Lists passed to `in_array()`: `Payment/Rule/Condition/CarrierConditionChecker.php:37`, `Shipping/Rule/Condition/*`, `Notification/Rule/Condition/{StateTransitionChecker:46,SimpleStateChecker:43,CommentChecker:32,CarriersChecker:31}`.
  - `$store->getUseGrossPrice()` without a null or type check: `Cart/Rule/Action/DiscountPercentActionProcessor.php:39-41`, `CartItem/Rule/Action/DiscountPercentActionProcessor.php:42-44`.
- **Problem:** `?:` does not suppress "Undefined array key". Rules saved before a checkbox existed, or imported or edited by hand, raise a warning, which Symfony's debug error handler turns into an exception. In production, the missing value becomes `null`, which is a `TypeError` for `bool` parameters and for `in_array()`. A cart without a store crashes the percent processors.
- **Fix:** use `(bool) ($configuration['x'] ?? false)` and `$configuration['list'] ?? []`. Assert the Core `StoreInterface`, as `Cart/CartContextResolver.php` does. Strict `in_array()` is TODO-3 **I-03**.

### B-18 — Cart, payment and shipping "categories" conditions count gift items; the "products" conditions do not
- **Where:** `Cart/Rule/Condition/CategoriesConditionChecker.php:54-70` vs `Cart/Rule/Condition/ProductsConditionChecker.php:50-53`; `Payment/Rule/Condition/CategoriesConditionChecker.php:59-76` vs `ProductsConditionChecker.php:50-63`; `Shipping/Rule/Condition/CategoriesConditionChecker.php:49-51`, `:61-78` vs `ProductsConditionChecker.php:51-53`, `:65-67`.
- **Problem:** a free gift added by rule A can make rule B's category condition true (for example "10 % off when the cart contains category X", where the gift is in X), or unlock or block a shipping or payment rule. The products checkers skip gift items for exactly this reason. The shipping categories checker also needs a Core `OrderInterface`, while the products checker accepts any `StoreAwareInterface`.
- **Fix:** skip `getIsGiftItem()` items in all categories checkers, and use the same subject type check as the products checkers.

### B-19 — The variant-id cache of "products" conditions with "include variants" is never invalidated: the listener compares `getId()` with `'products'`, and the cart checker uses a different tag
- **Where:** `Cart/Rule/Condition/ProductsConditionChecker.php:47` (`cs_rule_%s`) vs `CartItem/Rule/Condition/ProductsConditionChecker.php:51` (`cs_rule_variant_%s`). **(dependent)** `CoreBundle/EventListener/RecursiveVariantCacheInvalidationEventListener.php:50` (`if ($condition->getId() !== 'products') continue;`), `:73`.
- **Problem:** `Condition::getId()` returns `?int` (`Component/Rule/Model/Condition.php:50`), so the comparison is always true and the loop always `continue`s. **No** tag is ever cleared, for any rule type (verified in the code). Even with that fixed, the cart checker tags with `cs_rule_<id>`, but the listener clears `cs_rule_variant_<id>`. After an admin changes the product list of a rule, the old set of variants stays in effect until the Pimcore cache expires. CoreBundle **B-23** and **I-26** cannot take effect until this is fixed.
- **Fix:** compare `$condition->getType()` in the listener. Use one tag scheme for all checkers (for example `cs_<ruletype>_rule_variant_<id>`) and clear exactly that.

### B-20 — `StoresChecker` never matches `user` notification rules
- **Where:** `Notification/Rule/Condition/StoresChecker.php:30-50`; wiring `CoreBundle/Resources/config/services/notification.yml:191` (`notification-type: user`).
- **Problem:** the subject of `user` rules is a `CustomerInterface`, which is not `StoreAwareInterface`, `OrderDocumentInterface` or `PaymentInterface`. `$store` therefore stays `null`, and the condition is always `false`. `CustomerListener::prepareCustomerParameters()` passes `store_id` in `$params`, but the checker ignores `$params`. A "registration mail for store X only" rule never fires, and nothing reports an error.
- **Fix:** fall back to `$params['store_id']` and compare it directly.

---

## Gift items, appliers

### B-21 — `GiftProductActionProcessor` deletes and re-creates the gift item on every recalculation, can remove the wrong item, and cannot remove a gift whose product is gone
- **Where:** `Cart/Rule/Action/GiftProductActionProcessor.php:43-103`, `:105-134`, `:136-149`. **(dependent)** `Component/Order/Model/Order.php:59-74` (`removeItem()` matches by `getId()`).
- **Problem:**
  - On every recalculation, the existing gift item is removed and **deleted** (`$cartItem->delete()`), and a new Pimcore object is created. Each cart save produces a new id and new versions, and anything that refers to the old item is lost.
  - `unApplyRule()` returns `false` without removing anything when the gift product no longer exists or is no longer purchasable (`:107-111`). The zero-priced gift item then stays in the cart.
  - `Order::removeItem()` compares ids. For unsaved items (`null === null`), it removes the **first** unsaved item, which may be another rule's gift (plausible).
- **Fix:** reuse an existing gift item with the same key and product, and only update it. Remove gift items by adjustment key, regardless of the product lookup. Compare object identity in `removeItem()` when ids are `null`.

### B-22 — `CartItemRuleApplier::applySurcharge()` caps surcharges at the item total
- **Where:** `CartItem/Rule/Applier/CartItemRuleApplier.php:52-59`, `:71-86`.
- **Problem:** unlike `CartRuleApplier` (`:89-91`, which caps only when `!$positive`), the item applier always applies `min($discount, item total)`. A surcharge larger than the item value is cut silently. No core action calls it today, but the interface exposes it for custom actions.
- **Fix:** apply the cap only to discounts (`!$positive`).

---

## Order commit, weights, shipments

### B-23 — Committing a quote (or an order) without addresses crashes after the cart has already been saved with its new sale state; there is no transaction
- **Where:** `Order/Committer/QuoteCommitter.php:68-93`, `Order/Committer/OrderCommitter.php:74-99`. Trigger: `FrontendBundle/Controller/CartController.php:85-93` (`createQuoteAction`, which needs no address step).
- **Problem:** both committers call `persistCart()` (with the new sale state, number and key) **before** they clone the addresses. A logged-in customer without a default address can create a quote from the cart page. `getInvoiceAddress()` is then `null`, and `createFolderForResource(null, …)` / `cloneObject(null, …)` throws a `TypeError`. The cart has already been saved as a quote with a quote number but without addresses. The user gets a 500, and the cart disappears from the cart context. For orders, any exception after the first `persistCart()` leaves a half-committed order (number used, state `initialized`, the customer's original addresses still linked).
- **Fix:** validate the addresses before the first `persistCart()` (for quotes, fall back to the invoice address or skip cloning). Wrap the whole commit in a DB transaction and persist once at the end.

### B-24 — (plausible) Two concurrent checkout submits can commit the same cart twice
- **Where:** `Order/Committer/OrderCommitter.php:51-112`, triggered by the `commit_order` workflow callback (`CoreBundle/Resources/config/pimcore/workflow.yml:24-27`).
- **Problem:** the only guard is the workflow marking (`from: [cart]`), read from the object in memory. Two parallel requests (a double click, a retry) both see `cart`, so both run `commitOrder()`. Each generates its own order number: one number is lost, and the second request overwrites the key and number of the first. Each also clones its own addresses, which leaves orphan address copies.
- **Fix:** take a Symfony Lock per cart id around the `order` transition, reload the sale state inside the lock, and return early from `commitOrder()` when the order already has an order number or a non-cart sale state. Related: FrontendBundle **B-13**, OrderBundle **B-20**.

### B-25 — Every shipment gets the weight of the whole order, also partial shipments
- **Where:** `Order/Transformer/OrderToShipmentTransformer.php:38-40`.
- **Problem:** the decorator sets `$document->setWeight($order->getWeight())` before the inner transformer adds only the items in `$itemsToTransform`. A partial shipment, or the second shipment of an order, gets the full order weight, and that value goes to carriers and labels.
- **Fix:** after the inner transform, set the weight to the sum of the shipment items' weights.

### B-26 — Cart and shipment weights ignore the unit conversion rate
- **Where:** `Order/Processor/CartShippingProcessor.php:67-71` (`getQuantity() * getWeight()`), `Order/Transformer/OrderItemToShipmentItemTransformer.php:41-43` (`getItemWeight() * $quantity`).
- **Problem:** the product weight is per default unit, but `quantity` is in the item's selected unit. Two "box of 12" items count as 2 × weight instead of 24 × weight. Stock uses `defaultUnitQuantity` (`Inventory/Operator/OrderInventoryOperator.php`), so weight and stock disagree. Weight-based carrier prices undercharge, and shipment weights are wrong.
- **Fix:** use `getDefaultUnitQuantity()` (or quantity × `unitDefinition->getConversionRate()`) for cart weights and for shipment item weights.

---

## Contexts and long-running processes

### B-27 — `StoreAwareCountryContext` breaks the `CountryContextInterface` contract
- **Where:** `Context/Country/StoreAwareCountryContext.php:35-51`.
- **Problem:** `getStore()` is called outside the `try`, so a `StoreNotFoundException` escapes. `ShopperContext::hasCountry()` (`Context/ShopperContext.php:103-112`) only catches `CountryNotFoundException`, so it throws instead of returning `false`. The two fallbacks return `$store->getBaseCountry()`, which is `null` for a store without a base country (nullable column). That is a `TypeError` against the `CountryInterface` return type. This is the same pattern as Currency **B-01**, but for countries.
- **Fix:** wrap the store lookup and rethrow as `CountryNotFoundException` (with `previous`). Throw `CountryNotFoundException` when the base country is `null`.

### B-28 — `StoreBasedCartContext` keeps the new cart in a shared service that is never reset
- **Where:** `Context/StoreBasedCartContext.php:32`, `:45-79` (wired as the `-999` fallback of the cart composite, `CoreBundle/Resources/config/services/context.yml:38-42`).
- **Problem:** the fresh cart, already holding the customer and their default addresses, stays in `$this->cart` for the lifetime of the container. In Messenger workers, long-running commands and worker runtimes (FrankenPHP, RoadRunner), the next visitor without a cart gets the same object, including the previous customer and their addresses. Related to Customer **B-04** and CoreBundle **I-23**, but not covered by them.
- **Fix:** implement `ResetInterface` (`$this->cart = null`) and tag the service `kernel.reset`, or drop the memoisation.

---

## Tracking

### B-29 — (reproduced) Tracking sends order totals rounded to whole currency units
- **Where:** `Tracking/Extractor/OrderExtractor.php:63-66` (`parseAmount(): int`, `(int) round(round($amount / $factor, $precision), 0)`).
- **Problem:** reproduced: 1999 → `20`, 12345 → `123`, 49 → `0`. `total`, `subtotal`, `totalTax`, `shipping` and `discount` are sent to GA4, UA, GTM and Matomo as whole units. Revenue reporting is wrong for every order, and small values become 0. Item prices (`OrderItemExtractor`, `ProductExtractor`) are not rounded, so order totals and item sums no longer match.
- **Fix:** return `float`: `round($amount / $this->decimalFactor, $this->decimalPrecision)`.

### B-30 — Tracking reports base-currency amounts labelled with the shopper's currency
- **Where:** `Tracking/Extractor/OrderExtractor.php:51-57`, `OrderItemExtractor.php:58-60`, `ProductExtractor.php:58-62`.
- **Problem:** `getTotal()`, `getSubtotal()`, `getTotalTax()`, `getAdjustmentsTotal()`, `getItemPrice()` and `TaxedProductPriceCalculator::getPrice()` return base-currency amounts. `currency` is set to `$order->getCurrency()` or `$shopperContext->getCurrency()`, which is the shopper's currency. In a multi-currency store, a CHF order worth 100 EUR is reported as "100 CHF". `getCurrency()` can also be `null` (`:57`, `:60` call `getIsoCode()` directly).
- **Fix:** either use the `getConverted*()` getters together with `getCurrency()`, or report the base amounts with `getBaseCurrency()`. Make the same choice in all three extractors, and use null-safe access.

### B-31 — `OrderExtractor` sends the order total as `affiliation`
- **Where:** `Tracking/Extractor/OrderExtractor.php:51`. **(dependent)** `TrackingBundle/Tracker/Google/*` (`'affiliation' => $actionData['affiliation'] ?: ''`).
- **Problem:** `affiliation` is meant to be a store or partner name. It is filled with the rounded total, so analytics shows numbers as the affiliation, and a total of 0 becomes `''`.
- **Fix:** use the store name (`$object->getStore()?->getName()`), or leave the key out.

---

## Inventory, index, customer

### B-32 — `OrderInventoryOperator::cancel()` is not on the interface that the workflow calls
- **Where:** `Inventory/Operator/OrderInventoryOperator.php:35-48`, `Inventory/Operator/OrderInventoryOperatorInterface.php`; `CoreBundle/Resources/config/pimcore/workflow.yml:12` (`['@…\OrderInventoryOperatorInterface', 'cancel']`).
- **Problem:** the `cancel_inventory` callback targets the **interface** service id but calls a method that only the concrete class has. If a project points the alias to its own implementation, or decorates it, cancelling an order fails with "undefined method", and the cancel transition fails.
- **Fix:** add `cancel(OrderInterface $order): void` to the interface (with a BC note), or point the callback at the concrete class.

### B-33 — Inventory updates flush the shared Pimcore `ObjectManager`, and a failed flush leaves its buffers in place
- **Where:** `Inventory/Operator/OrderInventoryOperator.php:68`, `:108`, `:137`, `:158`; wiring `CoreBundle/Resources/config/services/inventory.yml:8` (`coreshop.manager.product`). **(dependent)** `ResourceBundle/Pimcore/ObjectManager.php` `flush()`.
- **Problem:** `coreshop.manager.product` is a single shared buffer for all Pimcore resources. `flush()` here also saves or deletes anything that other code persisted earlier in the same request. The flush is not transactional and clears its buffers only after every save succeeds. If one `save()` throws, some products keep their new counters and others do not, and the stale buffer is saved again by the next unrelated `flush()`. Every stock change also creates a full Pimcore version of the product.
- **Fix:** save the products directly inside a DB transaction (or use the atomic `UPDATE` from InventoryBundle **C-01**), without versioning.

### B-34 — (reproduced) MySQL index: `categoryIds`, `parentCategoryIds` and `stores` are `VARCHAR(255)`, and `parentCategoryIds` repeats ids
- **Where:** `Index/Extensions/ProductClassExtension.php:43-45` (`FIELD_TYPE_STRING`, so the default length is 255; no `IndexSystemColumnTypeConfigExtension` is registered), `:64-71`, `:82` (the whole `getHierarchy()` is appended for every category, without de-duplication). Same collection logic: `Index/Extensions/OpenSearchProductClassExtension.php`.
- **Problem:** a product in 8 sibling categories of a 5-level tree with 6-digit ids gives a `parentCategoryIds` string 281 characters long with only 12 distinct ids (reproduced). With MySQL strict mode, indexing that product fails with "Data too long". Without strict mode the value is truncated, and category filters (`FrontendBundle/Controller/CategoryController.php:148-151`) stop finding the product.
- **Fix:** de-duplicate `parentCategoryIds` (`array_unique`, in both extensions). Declare the three columns as `TEXT`, or ship an `IndexSystemColumnTypeConfigExtension` with a large enough `length`.

### B-35 — Moving a customer to a new company: a missing name crashes, and a failed move leaves an orphan company behind
- **Where:** `Customer/CustomerTransformHelper.php:91`, `:95`, `:102-108`. **(dependent)** `CoreBundle/Controller/CustomerTransformerController.php:209-235`.
- **Problem:** `newCompanyName` is read from the request without validation. If it is missing, `File::getValidFilename($company->getName())` (`string` parameter) throws a `TypeError`. That is an `Error`, so the controller's `catch (\Exception)` misses it and the admin gets a 500. An empty string gives an empty key. The company is saved (`forceSave($company)`) **before** the customer is moved, so if the move fails, an empty company stays behind.
- **Fix:** validate `companyData.name` (required, non-empty string) in the options resolver so the error surfaces as a `ValidationException`. Wrap company creation and the move in one DB transaction, or delete the company when the move fails.

### B-36 — `company_only` / `own_and_company` customers without a company see their own addresses but cannot edit or delete them
- **Where:** `Customer/Address/AddressAssignmentManager.php:84-91` vs `Customer/Allocator/CustomerAddressAllocator.php:35-39`.
- **Problem:** when the company was removed but the access type stayed (`Component/Customer/TODO-2-BUGS.md`, line 82), the allocator falls back to the customer's own addresses, so they are listed. The permission check returns `false` for `company_only`, and for `own_and_company` it requires `hasAddress()`, so the listed addresses cannot be edited or deleted. The user is redirected without any message.
- **Fix:** use the same fallback in `checkAddressAffiliationPermissionForCustomer()` (no company means `own_only`). Better, have both use one resolved "effective access type". Do this together with TODO-1 **C-08**.

---

## Models

### B-37 — (reproduced) `Store::$configurations` is never initialised, so every configuration method on a new `Store` fails
- **Where:** `Model/Store.php:45` (property), `:52-81`. The class has no constructor of its own; `CountriesAwareTrait::__construct()` initialises only `$countries`.
- **Problem:** on a `Store` created with `new` (resource factory, fixtures, Behat `StoreContext`, tests), `hasConfigurations()` throws `Call to a member function isEmpty() on null`, and `addConfiguration()` / `hasConfiguration()` throw on `contains()`. Only stores loaded through Doctrine work.
- **Fix:** add a constructor that calls the trait constructor (aliased, as `Carrier` and `Country` do) and sets `$this->configurations = new ArrayCollection()`.

### B-38 — (plausible) A carrier's tax rule and a store's base country cannot be cleared: the setters reject `null`
- **Where:** `Model/CarrierInterface.php:32`, `Model/Carrier.php:50` (`setTaxRule(TaxRuleGroupInterface $taxRule)`); `Model/StoreInterface.php:42`, `Model/Store.php:88` (`setBaseCountry(CountryInterface $baseCountry)`). Config: `Carrier.orm.xml` (`taxRuleGroupId` `on-delete="SET NULL"`), `Store.orm.xml` (`baseCountryId` `nullable="true"`), `validation/Store.yml` (`NotBlank`). **(dependent)** `ResourceBundle/.../resource/comboBox.js` (an emptied combo submits `null`).
- **Problem:** "no tax rule" is a supported carrier state (the DB sets it on delete, and `Shipping/Taxation/TaxCalculationStrategyTaxRule.php:64-68` handles it). But an admin who empties the combo and saves triggers `PropertyAccessor::setValue($carrier, 'taxRule', null)`, which throws `InvalidTypeException`, so the save fails with a 500. For the store, the `NotBlank` message is never shown, and the admin gets a 500 too. The getters are documented as non-null, which hides the `null` case.
- **Fix:** make both setters nullable (`?TaxRuleGroupInterface`, `?CountryInterface`, `: void`) and add `?…` return types to the getters.

### B-39 — (reproduced) `Payment::setCurrency(null)` crashes
- **Where:** `Model/Payment.php:55-59`.
- **Problem:** the method calls `$currency->getIsoCode()` unconditionally, although `CurrencyAwareInterface` allows `null`. Callers pass `$order->getCurrency()` / `$order->getBaseCurrency()` (`Order/Payment/OrderPaymentProvider.php:58`, `OrderBundle/Controller/OrderPaymentController.php:112`), which are nullable Pimcore fields. The typing side is Currency **B-10**.
- **Fix:** `$this->currency = $currency; $this->currencyCode = $currency?->getIsoCode();`.

### B-40 — `User::getUserIdentifier()` returns a nullable value from a `string` method
- **Where:** `Model/User.php:26-29` (`return $this->getLoginIdentifier();`, which is `?string`). Same code in `Component/User/Model/User.php:76-79`.
- **Problem:** under `strict_types`, a user without a login identifier (an incomplete import, or an empty field in the admin) throws a `TypeError` as soon as Symfony builds or refreshes the token, or the profiler or a log processor reads it. Symfony 7.4 also requires a **non-empty** identifier.
- **Fix:** delete the Core override and fix the base: `return $this->getLoginIdentifier() ?: throw new \LogicException(...)`, or fall back to a stable value.

### B-41 — (plausible) `Category::getMetaDescription()` does not fall back when the Pimcore meta description is an empty string
- **Where:** `Model/Category.php:30-41`; compare `getMetaTitle()` and `Model/Product.php:44-47`, which use `?:`.
- **Problem:** the method checks `null !== …`, but a cleared localized textarea is stored as `''`. The category then renders an empty `<meta name="description">` and an empty `og:description`, instead of falling back to the stripped `description`.
- **Fix:** `return $this->getPimcoreMetaDescription($language) ?: (($d = $this->getDescription($language)) ? strip_tags($d) : null);`.

### B-42 — (reproduced) The Country ↔ Currency link is not kept in sync when a country changes currency
- **Where:** `Model/Country.php:50-59`, `Model/Currency.php:50-64`.
- **Problem:** `Country::setCurrency($new)` adds the country to the new currency's collection, but does not remove it from the old one. Reproduced: after `setCurrency($a)` and then `setCurrency($b)`, `$a->hasCountry($country) === true`. Because `Currency.countries` is mapped with `cascade-all` / `orphan-removal` (CurrencyBundle **C-04**), deleting the old currency later in the same request also affects the country that was just moved.
- **Fix:** when the currency changes, call `$this->currency?->removeCountry($this)` before assigning, with a recursion guard (`Currency::removeCountry()` calls `setCurrency(null)`).

### B-43 — (plausible) `OrderItem::equals()` merges cart lines with and without a unit definition
- **Where:** `Model/OrderItem.php:58-79`.
- **Problem:** when the product matches and only one side has a unit definition, the method returns the parent result (`true`). Adding a product "per box" to a cart that already has a line for it without a unit increases that line's quantity instead of creating a new line. The quantity is then read in the wrong unit, which gives the wrong price per unit and the wrong `defaultUnitQuantity`.
- **Fix:** treat "has a unit" and "has no unit" as different lines, and compare unsaved unit definitions by identity.

### B-44 — `Order::hasShippableItems()` iterates a value the interface allows to be `null`
- **Where:** `Model/Order.php:55-68` (`OrderInterface::getItems(): ?array`).
- **Problem:** a custom order or a getter override that returns `null` causes `foreach() argument must be of type array|object` (a 500 in debug mode) during shipping and checkout processing.
- **Fix:** `return array_any($this->getItems() ?? [], static fn (OrderItemInterface $i) => $i->getDigitalProduct() !== true);` with a `bool` return type.

### B-45 — The `ProductStoreValues` contract allows a `null` field name that the mapping rejects
- **Where:** `Model/ProductStoreValuesInterface.php:30-32` (`getFieldName(): ?string`, `setFieldName(string)`), `Model/ProductStoreValues.php:36`, `:64-72` (`setFieldName(?string)`); `CoreBundle/Resources/config/doctrine/model/ProductStoreValues.orm.xml` (`fieldName` is `NOT NULL` and part of the unique key).
- **Problem:** the model accepts `null`, but flushing it fails with a `NOT NULL` violation. The interface and the implementation also disagree about whether `null` is allowed. Only custom code and imports are affected today.
- **Fix:** make the field required (`string`), or set it in the constructor.

### B-46 — (reproduced) `ProductStoreValues::__toString()` fails for an entity without a store
- **Where:** `Model/ProductStoreValues.php:124-127`.
- **Problem:** `$this->getStore()->getId()` on a new or detached entity throws on `null`. `__toString()` is called implicitly by Pimcore version previews, log contexts, the dump/profiler and form choice labels. The nullable mapping is CoreBundle **I-07**.
- **Fix:** `sprintf('Price: %d (Store: %s)', $this->price, $this->store?->getId() ?? '-')`.

---

## Already tracked elsewhere (not repeated)
- `Bundle/PaymentBundle/TODO-2-BUGS.md` **B-05** (`paymentTotal` ×100 vs `decimal_factor`, `Order/Processor/CartPaymentProcessor.php:46`), **B-07** (payment fee adjustment has gross = net).
- `Component/Payment/TODO-2-BUGS.md` **B-04** (`StoreBasedPaymentProviderResolver` ignores `$subject`).
- `Component/Currency/TODO-2-BUGS.md` **B-01** (`StoreAwareCurrencyContext`), **B-02** (`CountryAwareCurrencyContext`), **B-03** (`Currency/CurrencyStorage.php`), **B-06** (line-by-line conversion rounding), **B-07** (`CartCurrencyConversionProcessor`), **B-10** (`CurrencyAwareInterface` typing), **B-13** (all `CurrenciesConditionChecker`s).
- `Component/Customer/TODO-2-BUGS.md` **B-01** (`{Customers,CustomerGroups}ConditionChecker` in cart, payment, shipping, product), **B-02** (`GuestConditionChecker`), **B-04** (session-bound customer context).
- `Component/Configuration/TODO-2-BUGS.md` **B-01** (`removeForStore()` wipes the global value, which affects `Order/NumberGenerator/SaleNumberGenerator.php`), **B-08**; `Bundle/ConfigurationBundle/TODO-2-BUGS.md` **B-07** (`Configuration/ConfigurationService::setForStore()`).
- `Component/Locale/TODO-2-BUGS.md` **B-01**, **B-03** (`StoreBasedCartContext:59` overwrites the cart locale); `Bundle/LocaleBundle/TODO-2-BUGS.md` **B-01** (`Locale/Context/PimcoreAdminLocaleContext` lower-cases codes).
- `Component/Notification/TODO-2-BUGS.md` **B-02**, **B-04**, **B-05**, **B-07** (`Notification/Rule/Action/*MailActionProcessor`); `Bundle/NotificationBundle/TODO-2-BUGS.md` **B-02**.
- `Bundle/InventoryBundle/TODO-2-BUGS.md` **B-01** (`OrderInventoryOperator::cancel()` decision logic), **B-02**, **B-12**; `Component/Inventory/TODO-2-BUGS.md` **B-04**, **B-05** (`Product::isInStock()` / `getInventoryName()`).
- `Bundle/OrderBundle/TODO-2-BUGS.md` **B-20** (`persistCart()` without a lock), **B-30**, **B-33** (`CartItemQuantityModifier`), **B-34**, **B-36** (custom item price unbounded, `CartItemsProcessor:107-118`).
- `Bundle/CoreBundle/TODO-2-BUGS.md` **B-21** (each inventory save recalculates all carts), **B-23**, **B-28**, **B-36** (`CartPriceRuleVoucherProcessor` gets 5 arguments, the constructor takes 4), **B-39**–**B-43**, **B-44**–**B-47**, **B-49**, **B-50**, **B-51** (repository implementations, store values, payment `orderId`).
- `Bundle/FrontendBundle/TODO-2-BUGS.md` **B-13** (no `can()` before `TRANSITION_ORDER`).
- `Bundle/PayumBundle/TODO-2-BUGS.md` **B-01** (payment fee missing from PayPal line items).
- `Component/Address/TODO-2-BUGS.md` **CB-04** (`AddressesAwareTrait` / `hasAddress()` with unsaved addresses).
