# Order Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, PHP 8.4 modernisation and forward-compatibility items. None of them is a functional bug on its own.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have part of the fix outside this component. Items marked **(verified)** were confirmed against the code, the vendored sources or the compiled container. Items marked **(latent)** describe a contract problem with no failing caller today.

Items already tracked in other TODO files are not repeated; see the overlap list in TODO-1 (notably `OrderBundle/TODO-3-IMPROVEMENTS.md` **I-03**, **I-06**, **I-17**, **I-18**, **I-25**, **I-28**, **I-33**, **I-35**, **I-37** and `Component/Customer/TODO-3-IMPROVEMENTS.md` **I-03** for the Laminas `PriorityQueue` → tagged-iterator pattern).

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and packaging

### I-01 — (verified) `composer.json` does not match real usage or the PHP 8.4 / Symfony 7.4 target
- **Where:** `composer.json:25-46`.
- **Problem / Fix:**
  - No `"php"` constraint, although the root `composer.json:88` declares `"php": "~8.4.0"` (and e.g. `Component/Payment` does too). Add it (align with the monorepo policy; `^8.4` would not block PHP 8.5).
  - `require-dev` pins PHPStan 1.x (`phpstan/phpstan ^1.10`, `phpstan-doctrine ^1.3.40`, `phpstan-webmozart-assert ^1.2.4`) while the root uses `^2.1` / `^2.0`. Upgrade; add `phpunit/phpunit ^11` for the tests in **I-21**.
  - Directly used but undeclared (only transitive today): `symfony/http-foundation` (`Event/CheckoutEvent.php:22`, `Checkout/*`), `symfony/event-dispatcher` (`GenericEvent` in `Event/CheckoutEvent.php`, `Transformer/TransformerEventDispatcher.php:22`), `symfony/event-dispatcher-contracts` (`Transformer/TransformerEventDispatcher.php:23`), `symfony/translation-contracts` (`Payment/OrderPaymentProvider.php:27`), `psr/container` (`Checkout/DefaultCheckoutManagerFactory.php:23`), `doctrine/orm` (`Repository/CartPriceRuleVoucherRepositoryInterface.php:24`, `Modifier/VoucherModifier.php:28`), `doctrine/collections`, `webmozart/assert` (20 uses), `nesbot/carbon` (11 uses), `coreshop/locale` (`Model/OrderInterface.php:26`). Declare them with `^7.4` for Symfony packages.
  - A component depends on a **bundle** (`coreshop/workflow-bundle`) only for `StateMachineManagerInterface` in `Transformer/OrderToOrderDocumentTransformerWorkflowApplier.php:21`. Move that applier to OrderBundle, or depend on `symfony/workflow` directly.
  - `"minimum-stability": "dev"` and the path `repositories` entry only matter inside the monorepo; remove them from the split package.

### I-02 — (verified) Typed constants, consistent `IDENTIFIER`, final event classes, and backed enums for States / Transitions
- **Where:** all root `*States.php`, `*Transitions.php`, `CartEvents.php`, `CheckoutEvents.php`, `Notes.php`.
- **Problem:** every constant matches the configured workflows exactly (`coreshop_order`, `_payment`, `_shipment`, `_invoice`, `_sales_type`, `coreshop_quote`, `coreshop_shipment`, `coreshop_invoice` checked). But `IDENTIFIER` exists on `InvoiceStates`, `ShipmentStates` and all `*Transitions`, and is missing on `OrderStates`, `OrderPaymentStates`, `OrderShipmentStates`, `OrderInvoiceStates`, `OrderSaleStates`, `QuoteStates`. Constants are untyped; `CartEvents` / `CheckoutEvents` are not `final`.
- **Fix:** `public const string …` (PHP 8.3+), add `IDENTIFIER` everywhere, make the event-name classes `final`. Longer term: backed enums (keeping the constants as BC aliases), plus a test that compares the constants with the workflow YAML.

### I-03 — (verified) Dead constants and leftover signatures
- **Where:** `Notes.php` (`NOTE_PAYMENT`, `ORDER_HISTORY_STATE_LOG`, `NOTE_UPDATE_ORDER`, `NOTE_UPDATE_ORDER_ITEM`: no usages); `Manager/CartManagerInterface.php:25` (commented-out `/*, array $params = []*/`); `Committer/QuoteCommitterInterface.php:25` (method named `commitOrder`).
- **Fix:** mark the unused constants `@deprecated`, remove the commented parameter, add `commitQuote()` with a BC alias.

### I-04 — (verified) PHP 8.4 / Symfony 7.4 service style across the component
- **Where:** `Processor/CompositeCartProcessor.php:26-31` (empty multi-line constructor, mutable `addProcessor()`, Laminas `PriorityQueue`); composite calculators (`protected` mutable properties, `@var` docblocks inside loops); services in `Cart/`, `CartItem/`, `Checkout/`, `Context/`, `Modifier/`, `Transformer/`, `Factory/`, `Payment/`.
- **Problem / Fix:**
  - Promoted dependencies should be `readonly`; most services are not `final` (`CartPriceRuleProcessor`, `CartPriceRuleUnProcessor`, `CartPriceRuleValidationProcessor`, `ProposalCartPriceRuleCalculator`, `CheckoutManager`, `CartModifier`, transformers). Make them `final` / `readonly` where they are not extension points, and add `#[\Override]` on implemented methods.
  - Replace the Laminas `PriorityQueue` / `addX()` registration with `#[AutowireIterator]` / tagged iterators (same pattern as other components' TODOs).
  - `Context/CartNotFoundException::__construct($message = null)` → `?string $message = null`; `Cart/CartContextResolver.php:28` redundant `?: null`.
  - `TransformerEventDispatcherInterface` methods lack `: void`, `getEvent()` lacks a return type.
  - `Model/OrderInvoiceInterface.php:48` `getConvertedTotal()` lacks a return type; add native return types on trait / interface methods (`void`, `array`, `bool`).

### I-05 — (verified) The component hard-codes Bundle controller FQCNs
- **Where:** `Model/OrderInvoice.php:35-48`, `Model/OrderShipment.php:31-44` (`getPrint*Controller()`).
- **Fix:** move the controller reference to configuration (bundle parameter / document renderer config) so the component no longer knows about bundle classes.

## Models

### I-06 — (verified) Adjustment recalculation helpers: wrong names, duplicate work, O(n²) bulk removes
- **Where:** `Model/AdjustableTrait.php:130-150`, `:230-244`; `Model/ConvertedAdjustableTrait.php:130-150`, `:210-224`.
- **Problem:** `removeAdjustment()` calls `recalculateAdjustmentsTotal()` and then `addToAdjustmentsTotal()` (should be "subtract"), which recalculates twice more and runs `recalculateAfterAdjustmentChange()` 3×; for `Order` each run re-sums all items. `subtractFrom*` is dead; `?: 0` on an `int` return is dead. `removeAdjustments()` / `removeAdjustmentsRecursively()` recalculate after every single removal.
- **Fix:** recalculate exactly once per public mutation (and once at the end of a bulk remove); delete the `addTo*` / `subtractFrom*` helpers. See also TODO-2 **B-14**.

### I-07 — (verified, latent, dependent) Inconsistent gross / net for the payment provider fee
- **Where:** `Model/Order.php:171-174` (`getConvertedPaymentProviderFee()` = net), `Component/Core/Model/Order.php:45` (`getPaymentProviderFee()` = gross); consumers `CoreBundle/Controller/OrderController.php:69-74`, `CoreBundle/Controller/OrderCreationController.php:121-125` show both in one row.
- **Problem:** harmless only because `Component/Core/Order/Processor/CartPaymentProcessor.php:69-76` stores gross == net; a taxed fee would display mismatched values.
- **Fix:** add `bool $withTax = true` to both getters and use it consistently.

### I-08 — (verified) `ProposalCartPriceRuleItem` does not implement its registered interface
- **Where:** `Model/ProposalCartPriceRuleItem.php:21`; registered as resource interface in `OrderBundle/DependencyInjection/Configuration.php:340`.
- **Fix:** `implements ProposalCartPriceRuleItemInterface`.

### I-09 — (verified) Null-safety of `getItems()` in `Order`
- **Where:** `Model/Order.php:59-89`, `:301-316`.
- **Problem:** `removeItem()`, `hasItem()`, `recalculateSubtotal()` `foreach` over a `?array` (only a warning today); `hasItems()` / `count()` already guard.
- **Fix:** `?? []` everywhere (together with TODO-2 **B-11**).

### I-10 — (verified) Defaults and typing of the Doctrine voucher / rule models
- **Where:** `Model/CartPriceRuleVoucherCode.php:36-76`, `Model/CartPriceRule.php:40-73`, `Model/CartPriceRuleVoucherGenerator.php`, `Model/CartPriceRuleTranslation.php`.
- **Problem:** `$used` / `$uses` default to `null` although the DB columns are NOT NULL, so every creator must remember `setUsed(false)` / `setUses(0)`. Everything is untyped / docblock-only; stray formatting in `CartPriceRule::__construct(\n)`.
- **Fix:** defaults `false` / `0`; typed properties with native parameter / return types (nullable where the column is); add `addCreditUsed()` / `subtractCreditUsed()` (see TODO-1 **C-01**).

### I-11 — (verified) Small model cleanups
- **Where / Fix:**
  - `Model/OrderInvoice.php:220-223`: dead `getShippingNet()` stub (no field in any class definition, always throws) → remove.
  - `Model/AttributesAwareTrait.php`: `hasAttributes()` checks a single attribute → rename to `hasAttribute()` (keep a deprecated alias).

## Calculators, distributors, processing

### I-12 — (verified) Composite purchasable calculators compute every candidate and keep the last one
- **Where:** `Calculator/CompositePurchasable{Price,RetailPrice,DiscountPrice,WholesalePrice}Calculator.php:39-45`; registry order `Component/Registry/PriorityMap.php` (ascending, highest priority runs last and wins).
- **Problem:** for every product line the default `PurchasablePriceCalculator` (priority 10) runs fully (calling the composite retail, discount-price and discount calculators, which run the product calculators), then `PurchasableProductPriceCalculator` (priority 20) overwrites the result. `CartItemsProcessor` calls this 5–6× per item. "Last wins" is undocumented and the opposite of `CompositeCartProcessor` (highest first). An exception in a lower-priority calculator aborts the call even if a higher one would succeed.
- **Fix:** iterate highest → lowest and return the first result that does not throw; document the priority semantics.

### I-13 — (verified) `ProcessableOrderItems` cleanups
- **Where:** `Processable/ProcessableOrderItems.php:36-58`, `:65-83`, `:95`.
- **Problem:** `foreach` over nullable `getItems()` (order and document); items with a `null` id collide under key `""`; `isProcessable()` ignores the sale state (carts and quotes count as processable); stored state uses the order-level `OrderStates` while the cancelled state is injected as a string; `getProcessableItems()` reloads all documents on every call and `isProcessable()` calls it twice.
- **Fix:** `?? []`, check `getSaleState() === OrderSaleStates::STATE_ORDER`, memoise per order within a request.

### I-14 — (verified) `OrderPaymentProvider` cleanups
- **Where:** `Payment/OrderPaymentProvider.php:41`, `:57`.
- **Fix:** inject the token generator instead of instantiating it; use a clock (`ClockInterface`) instead of `new \DateTime()`; `readonly` promoted properties.

## Cart, cart rules, checkout

### I-15 — (verified) `CartModifier`: required resolver, ownership guard, delete order
- **Where:** `Cart/CartModifier.php:36`, `:45-66`, `:71`.
- **Problem:** `?StorageListItemResolverInterface $cartItemResolver = null` is dereferenced unconditionally in `resolveItem()` (always wired, but `new CartModifier($q, $d)` would fatal). `removeFromList()` deletes `$item` without checking it belongs to `$storageList` (both callers check it themselves: `FrontendBundle/Controller/CartController.php:324`, `OrderBundle/Controller/OrderEditController.php:183`). The item is deleted before the cart is persisted, so a failed persist leaves the cart pointing at a deleted child.
- **Fix:** non-nullable resolver; assert ownership (and refuse immutable lists); defer `delete()` to the manager's persist.

### I-16 — (verified) `CartItemActionProcessor`: one repository query per item per call, and 0-amount adjustments
- **Where:** `Cart/Rule/Action/CartItemActionProcessor.php:51`, `:112-120`, `:128`.
- **Problem:** `findByCode()` runs inside the item loop in both `applyRule()` and `unApplyRule()` on every recalculation; a 0-amount cart adjustment is added even when no item matched.
- **Fix:** look the voucher up once before the loop; add the adjustment only when `$overallResult` is true.

### I-17 — (verified) `VoucherConditionChecker` robustness
- **Where:** `Cart/Rule/Condition/VoucherConditionChecker.php:47-49`, `:72`.
- **Problem:** `maxUsagePerCode` / `onlyOnePerCart` read without `??` (legacy or hand-edited config → warning); the per-customer lookup uses the cart-supplied `$voucher` instead of the freshly loaded `$storedCode`.
- **Fix:** defaults, `(int)` normalisation (see `OrderBundle/TODO-2-BUGS.md` **B-32**), use `$storedCode`.

### I-18 — (verified) `TimeSpanConditionChecker` simplification and end-of-day semantics
- **Where:** `Cart/Rule/Condition/TimeSpanConditionChecker.php:30-45`.
- **Problem:** builds two Carbon objects only to call `getTimestamp()`. A date without time in the admin (`timespan.js`) means 00:00, so "until 31.12." ends at the start of that day.
- **Fix:** compare `time() * 1000` with the stored ints (or use a clock); document or implement end-of-day semantics for `dateTo`.

### I-19 — (verified) Small cart / checkout cleanups
- **Where / Fix:**
  - `Modifier/ImmutabilityModifier.php`: saves every item with versioning → wrap in `VersionHelper::useVersioning(..., false)` as `OrderCommitter` does.
  - `Modifier/VoucherModifier.php`: `flush()` on the whole default EntityManager → flush only within the commit transaction (ties into TODO-1 **C-09**).
  - Dead `getApplicableAmount($amount, $amount)` in the Core `DiscountPercent` / `SurchargePercent` cart action processors **(dependent)**.
  - `Event/CheckoutEvent.php:42`, `:108-111`: `getResponse(): Response` with a `?Response = null` property → `TypeError` if called without `hasResponse()` (all current callers guard) → return `?Response` **(latent)**; the type constants could become an enum.

## Factories, generators, repositories, transformers, exceptions

### I-20 — (verified) Transformers: constructor types, repeated saves, N× queries, wrong state constant
- **Where:** `Transformer/OrderToInvoiceTransformer.php:44`, `:57-61`, `:80-85`, `:113-115`, `:133-135`, `:176`, `:214`; `Transformer/OrderToShipmentTransformer.php:40`.
- **Problem / Fix:**
  - `PimcoreRepositoryInterface $orderItemRepository` → `OrderItemRepositoryInterface`.
  - Redundant `@var` + `Assert::isInstanceOf($order, OrderInterface::class)` pairs.
  - The comment "save the order twice" refers to the document; an invoice is saved three times, plus once more by the marking store → save once at the end.
  - `getProcessedAdjustmentValue()` reloads every invoice of the order 4× per order adjustment → load the non-cancelled documents once.
  - `:214` uses `OrderInvoiceStates::STATE_CANCELLED` (order-level) where `InvoiceStates::STATE_CANCELLED` (document) is meant; both are `'cancelled'`, so it works by coincidence.
  - `0 !== $adjustmentValueToProcessGross` (`:176`) skips a remaining net-only difference → check both gross and net.

### I-21 — (verified) Missing unit tests for money math, generators, checkout and voucher bookkeeping
- **Where:** there are no tests / specs for `Distributor/*`, the composite calculators, `ProcessableOrderItems`, `OrderEditPossible`, `AdjustableTrait`, `ConvertedAdjustableTrait`, `ProposalPriceRuleTrait`, `Order` item handling, `Checkout/*`, `Modifier/*`, `Generator/*`, `NumberGenerator/*`, the invoice / shipment transformers. Behat features under `features/domain/cart/rules` never place an order with a voucher, never stack two vouchers, never use a non-base currency or fractional quantities.
- **Fix:** add PHPUnit tests: distributor sum preservation, sign handling, zero total, empty input, zero-weight entries, mixed signs, fractional targets; `VoucherModifier` increment / decrement / credit accumulation / deleted code; `PrioritizedServiceRegistry::getAllPreviousTo()`; `CheckoutManager::getStep()` on an unknown id; `CartItemQuantityModifier` rounding; generator with hyphens and UTF-8 prefixes; invoice totals vs order totals. Add the behat scenarios listed above (covers TODO-1 **C-01**…**C-08**).

### I-22 — (verified) Voucher generator and checker cleanups
- **Where:** `Generator/CartPriceRuleVoucherCodeGenerator.php:54-58`, `:77`, `:105`; `Generator/CodeGeneratorLetterResolver.php:25-32`; `Generator/CodeGeneratorChecker.php:52-55`; `OrderBundle/Doctrine/ORM/CartPriceRuleVoucherRepository.php:60-65` **(dependent)**.
- **Problem / Fix:**
  - `str_split()` never returns `false` in PHP 8 → the check and its psalm suppression are dead.
  - `Assert::nullOrRange` on a non-null int → `Assert::range`.
  - `findOneBy(['code' => …])` → existing `findByCode()`.
  - The letter set includes ambiguous `0/O` and `1/I` → offer a non-ambiguous alphabet; `range(chr(65), chr(90))` → `range('A', 'Z')`.
  - `Assert::allNotNull` runs after the values were already read → move before.
  - `countCodes()` does not escape `%` / `_` in prefix / suffix for `LIKE` → over-counts and may reject feasible batches.
  - Format constants → backed enum.

### I-23 — (verified) Repository interfaces leak Doctrine and use weak types; dead methods
- **Where:** `Repository/CartPriceRuleVoucherRepositoryInterface.php:28` (returns `Doctrine\ORM\Tools\Pagination\Paginator`), `Repository/CartPriceRuleRepositoryInterface.php:31` (`findVoucherRules()` without `@return`), `Repository/OrderRepositoryInterface.php:30-46` (bare `array` returns), `Repository/CartItemRepositoryInterface.php`.
- **Problem / Fix:** `findCartByCustomer()` / `findByCustomer()` have no callers; `CartItemRepositoryInterface::findCartItemsByProductId()` has no callers and its implementation duplicates `OrderItemRepositoryInterface::findOrderItemsByProductId()` → deprecate one. Return an iterable / component-level paginator; add `@return list<…>` generics.

### I-24 — (verified) Exceptions carry too little context; untyped constructors; duplicated classes
- **Where:** `Exception/FailedCodeGenerationException.php:25-37`; `Exception/NoPurchasable{,Discount,Retail,Wholesale}PriceFoundException.php:23-28`.
- **Fix:** `FailedCodeGenerationException` should also report format, prefix, suffix and the possible amount (already computed by the checker); type `$calculatorClass` as `string`; accept `?\Throwable $previous` instead of `?\Exception`; give the four `NoPurchasable*` classes a common base exception and include the purchasable id in the message.

### I-25 — (verified) Factory modernisation
- **Where:** `Factory/OrderFactory.php:34-54`, `Factory/OrderItemFactory.php:41-67`, `Factory/AdjustmentFactory.php:26-45`, `Factory/CartPriceRuleVoucherCodeCustomerFactory.php:30-51`.
- **Problem / Fix:** `OrderFactory` type-hints the concrete `UniqueTokenGenerator` (CSPRNG-based, fine) → type-hint an interface; `readonly` promoted properties; return types on `createNew()`; `uniqid()` keys in `OrderItemFactory` → `bin2hex(random_bytes(…))` / UUID; `createWithCart()` and `createWithPurchasable()` bodies are duplicated → extract. (The interface-check-after-instantiation issue is `OrderBundle/TODO-3-IMPROVEMENTS.md` **I-28**.)
