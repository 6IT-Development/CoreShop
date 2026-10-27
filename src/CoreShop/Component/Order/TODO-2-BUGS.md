# Order Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs that are not critical by the definition in TODO-1: they break one feature, need an unusual action to trigger, are latent (no current caller, but public API), or are hardening without a known exploit path today.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this component. Items marked **(reproduced)** were checked with a PHP 8.4.27 script against the repo classes. Items marked **(verified)** were confirmed by reading the code paths. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(latent)** are not reachable with today's callers / configuration.

Items already tracked in other TODO files are not repeated; see the overlap list in TODO-1.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Distributors and calculators

### B-01 — (reproduced, dependent) `ProportionalIntegerDistributor` hands the rounding remainder to the first entries, including zero-weight entries, sometimes with the wrong sign
- **Where:** `Distributor/ProportionalIntegerDistributor.php:35-39`.
- **Problem:** the remainder is added to indexes `0..|missing|-1` regardless of weight. Reproduced: `distribute([0,3,3], 5)` → `[1,2,2]`; `distribute([0,1,1,1], 2)` → `[-1,1,1,1]`; `distribute([0,1000,1000], 3)` → `[1,1,1]`. In `CartRuleApplier` (`includeNonDiscountableItems`) and the shipping-tax split, a free line gets a cent of discount (or a +1 cent surcharge), tax gets booked against the wrong rule, and the item's discount can exceed its total.
- **Fix:** largest-remainder method: floor each share, hand the remainder to the entries with the largest fractional parts, never to zero-weight entries, keep the sign of `$amount`.

### B-02 — (reproduced, latent) `FloatDistributor` loses the sign of the fractional part and returns an extra element
- **Where:** `Distributor/FloatDistributor.php:35-52`.
- **Problem:** `distribute(-100, 1.5)` → `[-67, 33]` (sum −34 instead of −100; line 52 appends without `* $sign`). `distribute(7, 3.0)` → `[3,2,2,0]` (4 elements for 3 targets; the fractional slot is always appended). `%` on a float (`:41`) loses fractions and emits the PHP 8.1+ "implicit conversion" deprecation. No caller in the repo, but it is a public service.
- **Fix:** multiply the last element by `$sign`, append it only when the fractional target is > 0, work on `int` amounts. Add unit tests.

### B-03 — (reproduced, latent) `IntegerDistributor` truncates fractional amounts and overflows on large floats
- **Where:** `Distributor/IntegerDistributor.php:25-35`.
- **Problem:** the signature takes `float $floatAmount`, the math is integer only: `distribute(10.5, 2)` → `[5,5]` (0.5 lost, plus deprecation "Implicit conversion from float 10.5 to int loses precision"); `distribute(1e20, 3)` returns wrapped negative numbers. No caller in the repo.
- **Fix:** change the parameter to `int $amount` (or assert integral and in int range), use `intdiv()` and `%` on ints.

### B-04 — (verified, dependent) A missing price silently becomes 0, so the item is sold for free
- **Where:** `Calculator/PurchasableCalculator.php:37-45` (`NoPurchasablePriceFoundException` → `return 0`), `Calculator/PurchasablePriceCalculator.php:36-49` (no retail price → `$price = 0`, then the `$discountPrice < $price` check also ignores an existing discount price).
- **Problem:** a purchasable without a retail price for the current store / context (e.g. not yet priced in a newly added store) gets price 0. `CartItemsProcessor` uses it as is; no add-to-cart or cart validator checks for a missing price.
- **Fix:** let `NoPurchasablePriceFoundException` reach the cart layer, or flag the item and block checkout. At minimum use the discount price when no retail price exists.

### B-05 — (verified, latent) `CompositePurchasableCustomAttributesCalculator` drops the attributes of every calculator after the first
- **Where:** `Calculator/CompositePurchasableCustomAttributesCalculator.php:45`.
- **Problem:** `$customAttributes += $attributes` is a union by key. Calculators return lists (`PurchasableProductCustomAttributesCalculator` returns an `array_map` over a list), so a second calculator's entries at keys 0..n are discarded. Only one calculator is registered by default.
- **Fix:** `array_merge(...)`, or key by `getAttributeKey()` with a defined override order.

## Order editing and workflow

### B-06 — (verified, plausible impact) `OrderEditPossible` allows editing orders that already have invoices / shipments, or are authorized, partially paid or refunded
- **Where:** `OrderEditPossible.php:30-48`.
- **Problem:** for orders only `paid`, `cancelled` and `complete` block editing. The admin can remove an item or lower its quantity below what is already invoiced / shipped (invoice items then reference deleted order items; `ProcessableOrderItems` counts become wrong), or change the total of an `authorized` / `partially_paid` order after the payment amount is fixed. Carts and quotes in any state (including cancelled / complete quotes) return `true`. (Server-side enforcement is `OrderBundle/TODO-2-BUGS.md` **B-04**; this item is about the rule itself.)
- **Fix:** also return false when the invoice or shipment state is not `new` / `ready`, when the payment state is anything except `new` / `awaiting_payment` / `cancelled`, and when the quote state is `cancelled` / `complete`.

### B-07 — (verified, dependent) Order cancel does not cascade to `partially_shipped`, `partially_invoiced`, `partially_paid` or `paid` sub-states
- **Where:** `OrderBundle/Resources/config/pimcore/workflow/coreshop_order_shipment.yml:25-27`, `coreshop_order_invoice.yml:25-27`, `coreshop_order_payment.yml:35-37`, with `coreshop_order.yml:59-70` (cascades are "soft", via `can()`).
- **Problem:** `coreshop_order.cancel` is allowed from `confirmed`, but the cascaded sub-workflow `cancel` exists only from `new` / `ready` (payment: `awaiting_payment` / `authorized` / `partially_authorized`). Cancelling a partially shipped / invoiced / paid order silently leaves e.g. payment `partially_paid` and shipment `partially_shipped` on a `cancelled` order; no refund or follow-up state is triggered, and `isProcessable()` blocks further documents.
- **Fix:** guard `coreshop_order.cancel` while any sub-state is partial / completed, or add explicit cancel / refund transitions from those places.

## Payment

### B-08 — (verified) Payment description shows the wrong amount, unformatted
- **Where:** `Payment/OrderPaymentProvider.php:54`, `:65-71`.
- **Problem:** the payment is charged `getPaymentTotal()`, but the description (passed to Payum gateways via `CapturePaymentAction` / `AuthorizePaymentAction::setDescription`) prints `getTotal()`; they differ e.g. by the payment fee. The value is a raw `round()` float without currency ("12.5" instead of "12.50 EUR"). `%items%` counts order lines, not quantities.
- **Fix:** format `getPaymentTotal()` with `MoneyFormatter` in the order currency and request locale; document what `%items%` means or sum quantities.

### B-09 — (latent) `OrderPaymentProvider` fatals or emits deprecations on incomplete orders
- **Where:** `Payment/OrderPaymentProvider.php:46`, `:68`, `:74`.
- **Problem:** `str_replace(' ', '_', $order->getOrderNumber())` with a `null` number → PHP 8.1+ deprecation; `count($order->getItems())` with `null` → `TypeError`; `$payment->getCurrency()->getIsoCode()` without a currency → `Error`. Reachable only for orders not committed properly (the PayumBundle prepare action does not check, see overlap list in TODO-1).
- **Fix:** assert order number, currency and items exist; throw a domain exception.

## Models

### B-10 — (reproduced, latent) `AdjustableTrait::getNeutralAdjustmentsTotal(null)` returns the **non-neutral** total
- **Where:** `Model/AdjustableTrait.php:192-200` (compare `recalculateAdjustmentsTotal()` at `:217-222`).
- **Problem:** with `$type === null` it returns `getPimcoreAdjustmentTotalGross/Net()`, which is the sum of the non-neutral adjustments. Reproduced: non-neutral −1000 + neutral −500 → returns −1000 (should be −500). The only caller (`Component/Core/CartItem/Rule/Applier/CartItemRuleApplier.php:81-83`) passes a type; any caller adding this to `getTotal()` would count discounts twice.
- **Fix:** with `null`, iterate `getAdjustments()` and sum only neutral ones. Add a unit test.

### B-11 — (reproduced, plausible trigger) `Order::removeItem()` / `hasItem()` compare by `getId()`, so all unsaved items match each other
- **Where:** `Model/Order.php:59-89`.
- **Problem:** unsaved items have id `null` and `null === null`, so `removeItem($giftItem)` removes the first unsaved item, which can be a real product line; `hasItem()` is true for any unsaved item once the cart holds one. Affects transient carts (admin order creation preview, `OrderBundle/Controller/OrderCreationController.php:82-95`) and gift items created during `CartManager::persistCart()`; `Component/Core/Cart/Rule/Action/GiftProductActionProcessor.php:134-137` then removes the wrong line. Reproduced with `[saved#5, productX(new), gift(new)]`: `removeItem(gift)` removed productX.
- **Fix:** compare by identity (`$a === $item`) first; fall back to id only when both ids are non-null. Guard `getItems() ?? []`.

### B-12 — (verified, latent, dependent) OrderBundle's own `CoreShopOrderShipment` class definition declares `order` as a many-relation, so the generated class fatals
- **Where:** `OrderBundle/Resources/install/pimcore/classes/CoreShopOrderShipment.json` (`order` is `coreShopRelations`); `ResourceBundle/CoreExtension/CoreShopRelations.php:61-68` (`?array`); `Model/OrderDocumentInterface.php:32-34` (`getOrder(): ?OrderInterface`).
- **Problem:** without CoreBundle's override JSON (which correctly uses `coreShopRelation`), the generated `DataObject\CoreShopOrderShipment::getOrder(): ?array` is incompatible with the interface → fatal "Declaration must be compatible" on class load; every shipment feature breaks in a standalone OrderBundle install. Related to, but not listed in, `OrderBundle/TODO-2-BUGS.md` **B-45**.
- **Fix:** change the field to `coreShopRelation` as in `CoreBundle/Resources/install/pimcore/classes/CoreShopOrderBundle/CoreShopOrderShipment.json`; add it to the B-45 fix list.

### B-13 — (verified, latent) `ProposalPriceRuleTrait::getPriceRules()` returns `CartPriceRule` objects (or `null`), not the documented `PriceRuleItemInterface[]`
- **Where:** `Model/ProposalPriceRuleTrait.php:44-63`; contract in `Model/OrderInterface.php:206-209`, `Model/OrderItemInterface.php:163-166`.
- **Problem:** `$rules[] = $ruleItem->getCartPriceRule()` collects the rule (possibly `null`). Any consumer that follows the contract (`->getDiscount()`, `->getVoucherCode()`) fatals. No caller in `src/`; public API breakage.
- **Fix:** return the `$ruleItem`s; add a separate `getCartPriceRules()` if rules are needed. Make `setPriceRules()` reject non-Fieldcollection arguments instead of silently ignoring them.

### B-14 — (verified) `Adjustment::setAmount()` / `setNeutral()` recalculation depends on whether the item was loaded from the DB, and runs on clones
- **Where:** `Model/Adjustment.php:31-40`, `:47-69`, `:131-137`; `Component/Core/Order/Processor/CartCurrencyConversionProcessor.php:72-80`, `:100-107`.
- **Problem:** `getAdjustable()` uses the fieldcollection item's `object`, which Pimcore sets only on load, not on `add()` / `preSetData()`. A new adjustment never triggers recalculation, a loaded one does. The currency conversion processor `clone`s loaded base adjustments and calls `setAmount()` on the clone; the clone still points at the cart / item, so each call triggers a full **base** `recalculateAdjustmentsTotal()` → `recalculateSubtotal()` (lazy-loads all items) and rewrites base totals during the converted pass.
- **Fix:** do not recalculate implicitly from inside the fieldcollection item; let the adjustable own recalculation. At least reset `object` in `__clone()`.

### B-15 — (verified, latent) `CartPriceRuleVoucherCodeCustomer`: `getId(): int` on an unflushed entity, uninitialised `uses`, uses can go negative
- **Where:** `Model/CartPriceRuleVoucherCodeCustomer.php:33-45`, `:40`, `:72-75`.
- **Problem:** `$id` is `null` until flush, so `getId(): int` throws `TypeError` (dumping, logging, `ResourceInterface` consumers). `int $uses` is uninitialised after `createNew()`. `decrementUses()` has no lower bound, so the per-customer count can go below 0, which effectively raises `maxUsagePerUser`.
- **Fix:** `protected ?int $id = null` + `getId(): ?int`, `protected int $uses = 0`, `decrementUses()` with `max(0, …)`.

### B-16 — (verified, latent) `OrderInvoiceItem::getDocument()` / `OrderShipmentItem::getDocument()` dereference `null`
- **Where:** `Model/OrderInvoiceItem.php:26-39`, `Model/OrderShipmentItem.php:26-39`.
- **Problem:** do/while without null check; an item without parent (unsaved) → `$parent->getParent()` on null → `Error`. It also walks and loads the whole folder tree (items live in a sub-folder, `Transformer/OrderItemTo*ItemTransformer.php:60`).
- **Fix:** `while ($parent !== null)` with the check first; better, resolve the document via a stored reference / reverse relation.

## Voucher code generator and number generator

### B-17 — (reproduced) Voucher generator with hyphens bypasses the uniqueness check: codes are silently lost and later batches hit duplicate-key errors
- **Where:** `Generator/CartPriceRuleVoucherCodeGenerator.php:51-69` (hyphens inserted after `generateCode()`, stored under the hyphenated key at `:69`), `:94`, `:99-106` (`isUsedCode()` checks the un-hyphenated code against an array keyed by hyphenated codes and against the DB, which holds hyphenated codes); `Generator/CodeGeneratorChecker.php:57-61` → `OrderBundle/Doctrine/ORM/CartPriceRuleVoucherRepository.php:50-71` (`LENGTH(code) = length + prefix + suffix`, so hyphenated codes are never counted).
- **Problem:** reproduced (numeric, length 4, `hyphensOn` 2, amount 5000): 3956 codes returned (~1000 in-batch duplicates overwrite each other). After persisting, the checker still reports 5000 possible codes; a second batch of 10 contained 6 existing codes → unique index violation on `flush()` in `CartPriceRuleController::generateVoucherCodesAction` → 500, whole batch lost. The admin sees "success" with fewer codes in the first case. (Randomness is fine: `random_int()`.)
- **Fix:** build the final hyphenated code inside `generateCode()` before the uniqueness check and key / check by the final string; count by stored format in `countCodes()` (or `REPLACE(code,'-','')`); batch the DB existence check with one `IN (...)` query per chunk.

### B-18 — (verified) Voucher hyphenation splits UTF-8 prefixes / suffixes by bytes
- **Where:** `Generator/CartPriceRuleVoucherCodeGenerator.php:53-60`.
- **Problem:** `str_split()` is byte based; a prefix like `ÖSTER` with `hyphensOn` 1 or 3 cuts a multibyte character and stores invalid UTF-8 (DB error or an untypeable code). `strlen` / `LENGTH` in `countCodes()` also count bytes.
- **Fix:** `mb_str_split()`, or hyphenate only the random part; restrict prefix / suffix to `[A-Z0-9-]` in validation.

### B-19 — (verified code path, plausible scenario, dependent) Sequence numbers can repeat or go backwards in long-running processes; the sequence transaction is never rolled back
- **Where:** `NumberGenerator/SequenceNumberGenerator.php:32-35` (order, quote, invoice, shipment numbers: `OrderBundle/Resources/config/services/{order,order_invoice,order_shipment}.yml`). Root cause: `Component/Sequence/Generator/SequenceGenerator.php:35-58`, `Bundle/SequenceBundle/Doctrine/ORM/SequenceRepository.php`.
- **Problem:** `findForType()` runs `SELECT … FOR UPDATE`, but when the `Sequence` entity is already managed (second number of the same type in one process: CLI imports, bulk invoicing, workers), Doctrine returns the cached entity without refresh (`vendor/doctrine/orm/src/UnitOfWork.php:2959-2964`) → writes `stale + 1` → duplicate invoice numbers and the sequence moves backwards. `beginTransaction()` has no try / rollback; a failed `flush()` leaves the connection inside an open transaction, so later writes in the request are silently rolled back. First use of a type races on INSERT. Numbers are consumed before the document is saved (gaps; gapless invoice numbering is a legal requirement in some countries).
- **Fix:** `$em->refresh($seq, LockMode::PESSIMISTIC_WRITE)` or an atomic DBAL statement (`UPDATE … SET idx = LAST_INSERT_ID(idx + 1) WHERE name = ?` / `INSERT … ON DUPLICATE KEY UPDATE`); `wrapInTransaction()`; optionally draw the number in the same transaction as the document save.

## Transformers

### B-20 — (verified, dependent) Partial shipments get the weight of the whole order
- **Where:** `Component/Core/Order/Transformer/OrderToShipmentTransformer.php:32-35` (decorates `Transformer/OrderToShipmentTransformer.php`; `CoreBundle/Resources/config/services/order.yml:15-19`).
- **Problem:** `$document->setWeight($order->getWeight())` ignores `$itemsToTransform`; two shipments each get the full order weight (carrier data, labels, admin view). Item weight in the Core item transformer is correct.
- **Fix:** after the inner transform, set the shipment weight to the sum of its items' weights.

### B-21 — (verified) The invoice merges same-type adjustments under the first adjustment's label
- **Where:** `Transformer/OrderToInvoiceTransformer.php:169-180`.
- **Problem:** the loop runs over individual adjustments but computes the remaining amount per type. Rule A −10 and Rule B −5 (both `cart_price_rule`): the first iteration books −15 labelled "Rule A", the second sees processed = −15 and skips. It also relies on Pimcore's RuntimeCache returning the same `$document` instance from `getDocumentsNotInState()` (`vendor/six-it/pimcore-source/models/DataObject/AbstractObject.php:208`, `:725`); without the cache the type would be booked twice.
- **Fix:** iterate per adjustment (tracking processed amounts per adjustment) or per distinct type + label; track this run's amounts in memory instead of relying on the runtime cache.

### B-22 — (verified) `OrderToShipmentTransformer` does not validate item rows; both transformers save empty documents
- **Where:** `Transformer/OrderToShipmentTransformer.php:79-93` (compare `OrderToInvoiceTransformer.php:93-94`).
- **Problem:** a missing `quantity` / `orderItemId` key → "Undefined array key" (ErrorException in debug); in prod `(int) null = 0` saves a shipment line with quantity 0. In both transformers unknown ids are skipped silently; a document whose ids are all invalid is still saved with a freshly consumed number, and an invoice then collects all remaining adjustments (shipping, discounts) with no items.
- **Fix:** add the `Assert::keyExists` calls; throw on unknown / foreign items or an empty item list *before* generating the number.

## Checkout

### B-23 — (reproduced, dependent) The first checkout step is never validated as a "previous step" (off-by-one)
- **Where:** `Checkout/CheckoutManager.php:74-77` → `Component/Registry/PrioritizedServiceRegistry.php:158` (`for ($i = $prevIndex; $i > 0; --$i)`); consumer `FrontendBundle/Controller/CheckoutController.php:65-69`.
- **Problem:** index 0 is excluded. Reproduced: `address → []`, `shipping → [address]`, `summary → [payment, shipping, address]`; `customer` is never checked, so a visitor without a customer can open address / shipping / payment directly and their prepare / commit run on a cart without customer. (`doCheckoutAction` re-validates all steps, so ordering itself is still guarded.)
- **Fix:** loop `$i >= 0`; add a unit test for `getAllPreviousTo()`.

### B-24 — (verified; `getIndex` TypeError reproduced) `CheckoutManager::getStep()` throws for unknown / skipped steps although the interface and the controller expect `null`
- **Where:** `Checkout/CheckoutManager.php:44-52` (interface `?CheckoutStepInterface`, `Checkout/CheckoutManagerInterface.php:33`; registry `get()` throws `NonExistingServiceException`), `:64-67` (`getPreviousStep()` non-nullable, registry returns `null` for the first step), `:93-96` (`getCurrentStepIndex()`: registry `getIndex()` returns `false` into `int` → `TypeError`).
- **Problem:** `CheckoutController::processAction()` checks `!$step instanceof CheckoutStepInterface` and redirects — dead branch. A zero-total cart drops the optional `payment` step, so `/checkout/payment` (old tab, back button) returns 500 instead of a redirect; same for any unknown `stepIdentifier`.
- **Fix:** return `null` when `!$registry->has($id)`; make `getPreviousStep()` nullable; make `getCurrentStepIndex()` throw a clear exception (or return −1) instead of passing `false`.

## Cart price rules and vouchers

### B-25 — (verified, dependent) A rule with several discount actions reports only the last action's discount
- **Where:** `Component/Core/Cart/Rule/Applier/CartRuleApplier.php:228-229`, `Component/Core/CartItem/Rule/Applier/CartItemRuleApplier.php:174-175` (`setDiscount()` overwrites); `Cart/Rule/Action/CartItemActionProcessor.php:80-120`; `Cart/Rule/ProposalCartPriceRuleCalculator.php:57-75`.
- **Problem:** the calculator resets the `PriceRuleItem` discount to 0 and runs every action; each applier *sets* the discount. "10 € off + 10 % off" → adjustments and totals are right, but `PriceRuleItem::getDiscount()` holds only the last amount. That value is shown as the rule's discount and is used by `VoucherModifier` for credit booking (**C-01**) and by reports. The neutral cart-level adjustment in `CartItemActionProcessor` is wrong the same way.
- **Fix:** accumulate (`setDiscount($item->getDiscount($withTax) + $delta, $withTax)`) in both appliers.

### B-26 — (verified) `VoucherModifier` throws `TypeError` when the voucher code no longer exists
- **Where:** `Modifier/VoucherModifier.php:57-58`, `:80`, `:89` (increment); `:117-118`, `:142` (decrement).
- **Problem:** when `findByCode()` returns `null` (code deleted while the cart was open; only *used* codes are FK-protected, `OrderBundle/TODO-2-BUGS.md` **B-44**), the per-customer branch still calls `findUsesByCustomer($customer, null)` / `createWithInitialData($customer, null)` → `TypeError` under `strict_types`. This runs in the `after` callback of `create` / `cancel`: the order is already persisted, the request returns 500, remaining callbacks (history) are skipped, and other vouchers on the order are not counted.
- **Fix:** `continue` (and log) when `$voucherCode` is not a `CartPriceRuleVoucherCodeInterface`.

### B-27 — (plausible) `onlyOnePerCart` crashes on a price-rule item whose rule was deleted
- **Where:** `Cart/Rule/Condition/VoucherConditionChecker.php:84-87` (`$rule->getCartPriceRule()->getIsVoucherRule()`); `Cart/Rule/Condition/NotCombinableConditionChecker.php:45-47` guards the same case.
- **Problem:** deleting an unused cart rule leaves fieldcollection items with `getCartPriceRule() === null`. A voucher earlier in the list with `onlyOnePerCart` calls a method on `null` → `Error` → 500 on every recalculation of that cart.
- **Fix:** skip items with a `null` rule, as `NotCombinableConditionChecker` does.

### B-28 — (plausible, dependent) `CartPriceRuleVoucherProcessor` removes price-rule items by stale index
- **Where:** `Component/Core/Order/Processor/CartPriceRuleVoucherProcessor.php:53-60`.
- **Problem:** iterates a copy of `getItems()` with original indexes while `unProcess()`, `calculatePriceRule()` and `remove($index)` change the live collection; `Fieldcollection::remove()` uses `array_splice`, so indexes shift. Two orphaned items followed by a valid voucher: `remove(1)` removes the valid voucher.
- **Fix:** collect orphaned items first and remove them by identity (or iterate in reverse) before processing the rest.

## Context

### B-29 — (latent) `FixedCartContext` has the highest priority, is a shared service and is never reset
- **Where:** `Context/FixedCartContext.php:23-45`; `OrderBundle/Resources/config/services/context.yml:5-7` (priority −100, highest in the compiled composite: fixed −100, session −555, customer −777, store −999).
- **Problem:** nothing calls `setCart()` today. Once project code does (admin order creation, messenger handler, import), that cart wins over session and customer contexts for the rest of the process; under a worker runtime (FrankenPHP / RoadRunner workers, `messenger:consume`) every later request / message in that worker gets that cart → cart takeover across customers. `setCart()` takes a non-null argument, so it cannot be cleared.
- **Fix:** implement `ResetInterface` (`kernel.reset` tag), allow `setCart(null)` / add `clear()`, document it as request scoped.
