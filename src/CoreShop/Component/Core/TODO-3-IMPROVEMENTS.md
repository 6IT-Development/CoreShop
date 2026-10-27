# Core Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, PHP 8.4 modernisation and forward-compatibility items (PHP 8.5, Doctrine DBAL 4 / ORM 3). None of them is a functional bug on its own; where one hides a latent bug, it says so.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have part of the fix outside this component.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Package, layering, forward compatibility

### I-01 — `composer.json` does not match what the component uses; the component imports bundles
- **Where:** `composer.json`; bundle imports in `Notification/Rule/Action/Order/StoreMailActionProcessor.php:21` (`CoreShop\Bundle\ThemeBundle\Service\ThemeHelperInterface`), `Cart/Rule/Action/GiftProductActionProcessor.php:21` (`CoreShop\Bundle\ResourceBundle`), `Order/Committer/{Order,Quote}Committer.php:22` (`CoreShop\Bundle\WorkflowBundle\Applier\StateMachineApplierInterface`).
- **Problem / Fix:**
  - The ThemeBundle and ResourceBundle classes are not required by this package or by any package it requires, so a standalone `coreshop/core` install cannot autoload them. WorkflowBundle is only reachable through `coreshop/order`. Move these interfaces into components, or require the bundles explicitly.
  - Used directly but only installed transitively: `coreshop/rule`, `coreshop/locale`, `coreshop/pimcore`, `coreshop/registry`, `coreshop/storage-list`, `webmozart/assert`, `doctrine/collections`, `doctrine/orm`, `doctrine/persistence`, `symfony/{http-foundation,security-core,event-dispatcher,property-access,options-resolver}`, `nesbot/carbon`. Declare them.
  - Declared but unused: `coreshop/payum-bundle` (only `coreshop/payum-payment` is used). Drop it.
  - There is no `php` constraint. Add `"php": "^8.4"`, matching the root `~8.4.0`.
  - Out of sync with the root: `six-it/pimcore-source ^2.0` (root `^2.1`); `phpstan/phpstan ^1.10`, `phpstan-doctrine ^1.3.40`, `phpstan-webmozart-assert ^1.2.4` (root `^2.x`; PHPStan 1.x is EOL and does not handle PHP 8.4 syntax). `branch-alias` `dev-master: 4.1-dev` does not match the branch that is actually split.
  - `"description": "CoreShop Core Component (Glue)"` is fine. `README.md` is boilerplate ("It is build", mixed `coreshop.org`/`coreshop.com` links, wrong heading underline length). Add a short overview of what the glue component contains, and link `https://docs.coreshop.com`.

### I-02 — PHP 8.4 idioms across all services
- **Where:** every service class in the component (processors, appliers, condition checkers, action processors, calculators, providers, contexts, extractors, committers, cloners, Index extensions).
- **Problem:**
  - Promoted constructor dependencies are not `readonly`.
  - Many classes that only get decorated are not `final`.
  - `final` classes use `protected` properties (`Order/Processor/{CartCurrencyConversionProcessor,CartTextProcessor,CartPaymentProcessor}`, `Customer/CustomerTransformHelper`).
  - No `#[\Override]` on interface implementations.
  - Inline FQCNs are used instead of imports (`\CoreShop\Component\Core\Model\OrderInterface` / `OrderItemInterface` in `Cart/Rule/Condition/{Carriers,Stores}ConditionChecker.php:33-35`, `Shipping/Taxation/TaxCalculationStrategyCartItems.php:83`, `Model/Payment.php`, `Order/Committer/*`).
  - The `/** @var X */` + `Assert::isInstanceOf()` pairs are redundant.
  - `-1 * $x` is used instead of `-$x`.
  - Class constants are untyped, and in some classes they are declared before the trait `use` (`Payment/Rule/Condition/ProductsConditionChecker.php`, `Shipping/Rule/Condition/ProductsConditionChecker.php`).
  - Some parameters and returns are untyped: `Cart/Rule/Applier/CartRuleApplier.php:241`, `CartItem/Rule/Applier/CartItemRuleApplier.php:65-66`, `Cart/Rule/Action/DiscountPercentActionProcessor.php:57` and `CartItem/…:63` (`$withTax = false`), `Configuration/ConfigurationService.php:45` (`$returnObject`), `Order/Processor/CartItemProcessor.php` private methods, `Product/Cloner/UnitMatcher{,Interface}.php:25-26`, `Product/Cloner/ProductQuantityPriceRulesCloner.php:73-77`, `Tracking/Extractor/*` (`supports($object)`, `updateMetadata($object, $data = [])`).
- **Fix:** use `private readonly` promoted properties, or `final readonly class` for stateless services. Add `#[\Override]` and typed constants (`public const string …`), import classes, and replace the docblock + assert pairs with one assertion. Add the native types, with BC notes for the interfaces.

### I-03 — Condition checkers: strict id comparison, null-safe chains and less repeated work
- **Where:** all `*/Rule/Condition/*ConditionChecker.php` (cart, cart item, product, payment, shipping), `Notification/Rule/Condition/*`.
- **Problem:**
  - `in_array()` is loose. Rule configuration stores ids as strings, so this only works because of PHP's numeric-string comparison.
  - Getters are called again after each `instanceof` check (`$cart->getInvoiceAddress()->getCountry()->getZone()` three times).
  - The cart-item and product checkers rebuild the category or variant id list (a DB or cache hit) for **every item**.
  - `Payment/Rule/Condition/ProductsConditionChecker.php` and `CartItem/Rule/Condition/ProductsConditionChecker.php` compute the id list before the cheap `OrderInterface` / gift checks.
- **Fix:** use `in_array($id, array_map('intval', $configuration['…'] ?? []), true)` and null-safe chains (see TODO-2 **B-16**). Move cheap guards to the top. Memoise the id list per rule within the request, and use `array_any()` for the inner loops.

### I-04 — Replace the trait-constructor alias pattern (`__traitConstruct`)
- **Where:** `Rule/Condition/CategoriesConditionCheckerTrait.php:26-32`, `Rule/Condition/ProductVariantsCheckerTrait.php:26-32`; users `{Cart,CartItem,Product,Payment,Shipping}/Rule/Condition/{Categories,Products}ConditionChecker.php`.
- **Fix:** keep only the helper methods in the traits and declare the repository as a promoted `private readonly` property in each using class. Better, inject a small `CategoryIdResolver` / `VariantIdResolver` service, which also gives one place for the memoisation from **I-03** and the cache tag from TODO-2 **B-19**.

### I-05 — Remove the dead "add missing cents" blocks in both appliers
- **Where:** `Cart/Rule/Applier/CartRuleApplier.php:99-160`, `CartItem/Rule/Applier/CartItemRuleApplier.php:125-136`.
- **Problem:** `$discountFloat` always equals the amount added to `$totalDiscountNet` (with tax) or `$totalDiscountGross` (without tax). After rounding, the two are equal, so the `if` can never be true. The variable and the comment suggest a rounding correction that does not exist.
- **Fix:** remove `$totalDiscountFloat` / `$discountFloat` and the block, or replace them with the per-item calculation proposed in TODO-1 **C-04**.

### I-06 — In the appliers, the `?? new Fieldcollection()` taxes fallback is never written back
- **Where:** `Cart/Rule/Applier/CartRuleApplier.php:188-208`, `CartItem/Rule/Applier/CartItemRuleApplier.php:142-162`.
- **Problem:** when an item has no taxes collection, a new one is created and filled, but `setTaxes()` is never called, so the tax reduction is lost. This is latent today, because `CartItemProcessor` always sets the taxes.
- **Fix:** call `$item->setTaxes($taxItems)`, or assert that the collection is not null.

### I-07 — Appliers: move per-item work out of the loops, and type against the distributor interface
- **Where:** `Cart/Rule/Applier/CartRuleApplier.php:36`, `:104-147`, `:173-209`; `CartItem/Rule/Applier/CartItemRuleApplier.php:101-105`.
- **Problem:** the tax calculator is resolved **twice** per item, and `defaultAddressProvider->getAddress($cart)` builds a new address object every time. Index handling is mixed (`$i++` in one loop, `$index` in the other). The constructor asks for the concrete `ProportionalIntegerDistributor`, although the service is wired with `ProportionalIntegerDistributorInterface`, so overriding the alias gives a `TypeError`.
- **Fix:** resolve the address once per call and the calculator once per item, keyed by item index. Type the constructor against the interface.

### I-08 — Simplify the percent, amount and free-shipping action processors
- **Where:** `Cart/Rule/Action/{DiscountPercent,SurchargePercent,SurchargeAmount,FreeShipping}ActionProcessor.php`, `CartItem/Rule/Action/DiscountPercentActionProcessor.php:63-75`, `Cart/Rule/Applier/CartRuleApplierInterface.php`.
- **Problem / Fix:**
  - `getApplicableAmount($amount, $amount)` is a no-op. Drop it.
  - Discount-percent uses the store's gross mode, but surcharge-percent always uses net. Use one base for both.
  - The surcharge processor names its method `getDiscount()`, `applySurcharge(int $discount)` calls its argument a discount, and `apply()` takes a `bool $positive` flag. Rename to `$amount` / `getSurcharge()`, and replace the flag with two private methods.
  - `FreeShippingActionProcessor.php:40`, `:48` uses its hard-coded label as the duplicate-detection key. Use a class constant for the key, and the rule name as the label.

### I-09 — Deduplicate `GiftProductActionProcessor`
- **Where:** `Cart/Rule/Action/GiftProductActionProcessor.php:45-69` vs `:107-131`, `:140-146`, `:85-87`.
- **Fix:** extract `findGiftItems(OrderInterface, string $key): list<OrderItemInterface>`. Replace the separate `getId() === null` / `=== 0` checks with `if (!$cartItem->getId())`. Use `?->` for the unit definition once, instead of repeating the call. Do this together with TODO-2 **B-21**.

### I-10 — `StoreAwareCountryContext::isCountryAvailable()` builds an ISO list on every call
- **Where:** `Context/Country/StoreAwareCountryContext.php:53-58`; also `Context/ShopperContext.php:143`, `:146` (`getStore()` called twice).
- **Fix:** `array_any($store->getCountries()->toArray(), fn ($c) => $c->getId() === $country->getId())`. Comparing ids avoids depending on ISO codes being unique. This matches Currency **I-03** / **I-07**.

---

## Order processors and committers

### I-11 — `CartItemProcessor`: dead code, duplicated branches, repeated tax work and N+1 lookups
- **Where:** `Order/Processor/CartItemProcessor.php:69-126`, `:155-190`, `:192-227`.
- **Problem:**
  - `collectTaxes()` is never called.
  - The gross and net branches differ only in the direction of the calculation.
  - `getTaxesAmount*()` and `getTaxesAmount*AsArray()` are called separately for the same amount; the first is `array_sum()` of the second.
  - `collectItemTaxes()` calls `taxRateRepository->find()` for every rate, on every item, on every recalculation, although the calculator already holds the `TaxRateInterface` objects (`getTaxRates()`).
- **Fix:** compute the array once and derive the total with `array_sum()`. Build the tax items from `$taxCalculator->getTaxRates()`. Remove `collectTaxes()`, extract the gross/net split into one helper, and add `: array` return types.

### I-12 — `CartItemsProcessor`: per-item context leaks into gift items, and the price is computed five times
- **Where:** `Order/Processor/CartItemsProcessor.php:57-78`, `:80-103`.
- **Problem:** gift items are processed before `unitDefinition` and `cartItem` are reset in the context, so they get the previous item's values. These reach the tax calculator factory through `$context` (latent). Each normal item triggers `getPrice()` twice, plus `getRetailPrice()`, `getDiscountPrice()`, `getDiscount()` and `getCustomAttributes()`, and each of these runs the full price-rule evaluation.
- **Fix:** build the per-item context at the top of the loop, before the gift branch. Consider a single calculator call that returns all price parts. Do this together with TODO-1 **C-07** and TODO-2 **B-04**.

### I-13 — `CartTextProcessor::findVariantMain()` is not guaranteed to terminate
- **Where:** `Order/Processor/CartTextProcessor.php:68-78`.
- **Problem:** if a variant's parent is `null` or of another class, `$master` never changes, and the `while` loop never ends. Pimcore normally prevents this, but a parent deleted or moved during a long request, or a custom data model, hangs the request until `max_execution_time`.
- **Fix:** `while ($master->getType() === VARIANT && ($parent = $master->getParent()) instanceof $master) { $master = $parent; }`, or add a depth limit.

### I-14 — `CartPaymentProcessor`: concrete translator and translation on every run
- **Where:** `Order/Processor/CartPaymentProcessor.php:32-40`, `:66-72`.
- **Fix:** type against `Symfony\Contracts\Translation\TranslatorInterface` instead of `Pimcore\Translation\Translator`, and write `$label = $validRule->getLabel(...) ?: $this->translator->trans(...)`. The null-label crash is PaymentBundle **C-04**.

### I-15 — `CartPriceRuleVoucherProcessor`: a non-voucher rule with a voucher code is un-processed and then re-applied straight away
- **Where:** `Order/Processor/CartPriceRuleVoucherProcessor.php:74-86`.
- **Problem:** after `unProcess()` for "non-voucher rule but voucher code set", execution continues to `isValidCartRule()` / `calculatePriceRule()` with the same voucher code. So the item that should be dropped is applied again. The intent is not documented.
- **Fix:** `continue` after the un-process, or document why the rule is re-applied. Fix this together with TODO-2 **B-02** and CoreBundle **B-36** (the extra 5th argument).

### I-16 — Order and quote committers are near-duplicates and use two clocks
- **Where:** `Order/Committer/OrderCommitter.php`, `Order/Committer/QuoteCommitter.php` (unused `protected string $orderFolderPath` at `:38`).
- **Problem:** about 90 % of the code is shared. `date('Y/m/d')` (folder) and `Carbon::now()` (order date) are two clocks that can fall on different days around midnight, and neither can be controlled in tests.
- **Fix:** extract an abstract committer (folder, number, address cloning) with hooks for the states and the transition. Inject `Psr\Clock\ClockInterface` (Symfony Clock) and derive both values from it. Remove the unused property. TODO-2 **B-23** / **B-24** should be fixed in the same base class.

### I-17 — `CartItemQuantityModifier` modernisation
- **Where:** `Order/Modifier/CartItemQuantityModifier.php:92-93`, `:109-115`.
- **Fix:** remove `str_replace(',', '.', (string) $float)`. Float-to-string conversion is locale-independent since PHP 8.0, so it does nothing. Use `round($q, $scale, \RoundingMode::HalfAwayFromZero)` (PHP 8.4), and type `getPrecision(): ?int` on the interface.

### I-18 — `SaleNumberGenerator` null-safety
- **Where:** `Order/NumberGenerator/SaleNumberGenerator.php:46`, `:52`.
- **Fix:** `$model->getOrder()?->getStore()` (`getOrder()` is nullable). Cast the prefix and suffix values from `getForStore()` (which return `mixed`) to string, or use the typed accessor from Configuration **I-04**.

### I-19 — `PurchasableProductCustomAttributesCalculator` naming and typing
- **Where:** `Order/Calculator/PurchasableProductCustomAttributesCalculator.php:32`, `:45`.
- **Fix:** rename `$productPriceCalculator` to `$productCustomAttributesCalculator`. The closure parameter is typed to the concrete `ProductAttribute`, so introduce a small `ProductAttributeInterface`, or document that custom calculators must return that class. The service id typo is CoreBundle **I-14**.

---

## Shipping, notification, tracking

### I-20 — `CheapestDefaultCarrierResolver`: compute each price once, and use a proper comparator
- **Where:** `Shipping/Resolver/CheapestDefaultCarrierResolver.php:42-59`.
- **Problem:** the comparator calls `resolveCartContext()` and `getPrice()` twice per comparison, so there are O(n log n) price calculations including rule checks. It never returns 0, so carriers with the same price end up in an arbitrary order. The `OrderInterface` check runs after `resolveCarriers()` has already queried the database.
- **Fix:** check the type first, resolve the context once, build `$prices[$key]`, then `uasort` with `<=>` and the carrier id as a tie-breaker. The same pattern exists in CoreBundle **I-19**.

### I-21 — Notification checkers: return real `bool`s, reuse the property accessor, require `workflow`
- **Where:** `Notification/Rule/Condition/Order/BackendCreatedChecker.php:35` (`getBackendCreated(): ?bool` returned from `: bool`), `BackendUpdateChecker.php:34`, `SimpleStateChecker.php:41` (new `PropertyAccessor` on every check), `StateTransitionChecker.php:39-43` (any workflow is accepted when `workflow` is missing).
- **Fix:** `=== true` comparisons. Inject `PropertyAccessorInterface`. Pass `workflow` from every producer (`OrderDocumentWorkflowListener` does not) and require it here, or document that it is optional.

### I-22 — Tracking extractors: check the type before use, and use the order number as transaction id
- **Where:** `Tracking/Extractor/OrderItemExtractor.php:40` vs `:49` (`$object` is used before the `instanceof` check), `Tracking/Extractor/OrderExtractor.php` (transaction id).
- **Fix:** move the `instanceof` check first. Send `getOrderNumber()` as the transaction id instead of the internal Pimcore id, which reveals sequential ids and order volume. Typing is in **I-02**, and amounts and currency are TODO-2 **B-29** / **B-30**.

---

## Product, prices, customer, index

### I-23 — Quantity-price actions: check `base_currency` and share one base class
- **Where:** `ProductQuantityPriceRules/Rule/Action/{AmountDecreaseAction,AmountIncreaseAction,FixedAction}.php:40-44`.
- **Problem:** the three classes are identical except for the final arithmetic. `$context['base_currency']` is read without a check, so a missing key gives a warning and then `getIsoCode() on null`. `PurchasableWholesalePriceCalculator` asserts this key properly.
- **Fix:** add `Assert::keyExists()` and `Assert::isInstanceOf()`. Extract an abstract `AbstractCurrencyAmountAction` with a `convertAmount()` helper.

### I-24 — `TaxedProductPriceCalculator`: resolve the tax calculator once, and keep the displayed discount consistent
- **Where:** `Product/TaxedProductPriceCalculator.php:38-97`; `Provider/ContextBasedDefaultTaxAddressProvider.php:42`.
- **Problem:** each of the four methods builds a new tax address (a new Pimcore `CoreShopAddress` object) and resolves the calculator again, and a product listing calls them several times per product. The discount is taxed on its own (`applyTaxes(discount)`), so the displayed `retail − discount` can differ from `price` by 1 cent.
- **Fix:** memoise the default address (or just the country) per context, and resolve the calculator once. Compute the gross discount as `gross(price without discount) − gross(price with discount)`.

### I-25 — `CustomerTransformHelper`: side effects and leftover state
- **Where:** `Customer/CustomerTransformHelper.php:126-131` (the address folder is created before the `AddressesAwareInterface` check), `:238-248` (`setOmitMandatoryCheck(true)` is never reset), `:187-193` (`DataObject::getById()` per dependency).
- **Problem:** a holder that is not addresses-aware still gets an empty folder. The customer, company and address objects keep `omitMandatoryCheck = true` for the rest of the request, so any later save in that request skips mandatory validation.
- **Fix:** check the holder type first. Restore the previous `omitMandatoryCheck` value in `try/finally`.

### I-26 — Cloners: reflection-based setters and ranges whose unit has no match
- **Where:** `Product/Cloner/ProductUnitDefinitionsCloner.php:91-94` (`ReflectionProperty::setAccessible(true)`), `Product/Cloner/ProductQuantityPriceRulesCloner.php:106-112`.
- **Problem:** `setAccessible()` has been a no-op since PHP 8.1 and is deprecated in PHP 8.5. Resetting `id` through reflection duplicates `createDataCopy()` in `CoreBundle/CoreExtension/StoreValues.php`. When the variant has no unit with the same name, `continue` leaves the cloned range pointing at the **parent's** unit definition. That range can never match on the variant (`UnitVolumeCalculator::locate()` compares ids), and nothing warns about it.
- **Fix:** drop `setAccessible()`, and give `ProductUnitDefinitionPrice` a `__clone()` that resets `id`. Collect unmatched ranges and either drop them or report them to the solidifier controller (together with TODO-1 **C-09**).

### I-27 — Index extensions: duplicated code and `@` suppression
- **Where:** `Index/Extensions/ProductClassExtension.php:54-85`, `Index/Extensions/OpenSearchProductClassExtension.php:54-90`.
- **Fix:** extract a shared helper that returns `[categoryIds, parentCategoryIds (unique), storeIds (int)]`. Remove `@implode()` and the psalm suppression (`getStores()` is `?array`), and cast with `array_map('intval', …)`. See TODO-2 **B-34**.

### I-28 — `AddressAssignmentManager` / `CustomerAddressAllocator`: small correctness and readability points
- **Where:** `Customer/Address/AddressAssignmentManager.php:51-53`, `:72-74` (`getId() === 0`, but unsaved Pimcore objects have `null`), `:55-58` (returns `own` for any address of a customer without a company, even one the customer does not own); `Customer/Allocator/CustomerAddressAllocator.php:49` (generic `\Exception` from a Twig filter).
- **Fix:** add one `isNew()` helper (`null === getId()`). Return `null` when the customer does not own the address. Treat unknown access types as `own_only` with a logged warning, using a `match`. The enum is Customer **I-10**.

---

## Models and repositories

### I-29 — Native types on the Core models and interfaces
- **Where:** `Model/{Carrier,Country,Currency,Store,QuantityRange,TaxRule,ProductUnitDefinitionPrice}{,Interface}.php`, `Model/ProductStoreValuesInterface.php` (`setPrice()`, `getProductUnitDefinitions()`), `Model/ProductInterface.php` (`getStoreValuesOfType()` has no return type).
- **Problem:** properties, parameters and returns are untyped or docblock-only. `Store::setUseGrossPrice($useGrossPrice)` is untyped. `Country::__toString()` has no `: string`. Some docblocks are wrong: `QuantityRange::$pseudoPrice` says `int|null`, but the mapping is `NOT NULL` and the setter takes `int`, and `Country::getCurrency()` is documented as non-null but is nullable.
- **Fix:** add native types (`?CurrencyInterface`, `bool`, `int`, `Collection<int, CountryInterface>`, `: void` on setters), typed properties with defaults, and `#[\Override]` on overrides (`Category`/`Product` SEO getters, `OrderItem::equals()`, `User::getRoles()`). Fix the wrong docblocks. Ship this as one BC step with Currency **B-10**, Address and Customer typing items, and TODO-2 **B-38**.

### I-30 — Remove redundant model code
- **Where / Fix:**
  - `Model/ProductQuantityPriceRule.php:30-41` redeclares and re-initialises `$ranges`, which the parent constructor already does. The class body can be empty.
  - `Model/User.php:26-29` is identical to the parent (see TODO-2 **B-40**). Delete it.
  - `Model/Order.php:40-43`: `$withTax ? f(..., true) : f(..., false)` becomes `return $this->getAdjustmentsTotal(AdjustmentInterface::SHIPPING, $withTax);`.
  - `Model/{Order,OrderShipment}.php` `use CarrierAwareTrait`: the generated Pimcore class always overrides it, so it is dead and declares a second `$carrier` property. Drop it, or use the `ImplementedByPimcoreException` pattern.
  - `Carrier`, `Country`, `Currency`, `ProductStoreValues`, `ProductQuantityPriceRule` have empty multi-line `__construct(\n) {`. Write `__construct()`.
  - `Carrier::__toString()` / `Country::__toString()`: `sprintf('%s', $x)` becomes `(string) ($x ?? '')`. The explicit `\Stringable` on `Carrier` and `ProductStoreValues` is redundant and inconsistent with `Country`.

### I-31 — `OrderItemInterface::getObjectId()` / `getMainObjectId()` are typed as `float`
- **Where:** `Model/OrderItemInterface.php:53-59`; class definition `CoreShopOrderItem.json` (`objectId`, `mainObjectId`: `numeric`, `integer=false`); serializer `CoreBundle/Resources/config/serializer/Model.OrderItem.yml` (declares them as `integer`).
- **Problem:** Pimcore object ids are integers. `===` comparisons with `getId()` (`int`) fail without warning, and the serializer metadata contradicts the getter.
- **Fix:** set `integer: true` on the fields (with a migration), and change the interface to `?int`. **(dependent)**

### I-32 — Repository interfaces: dead or awkward API
- **Where / Fix:**
  - `Repository/ProductVariantRepositoryInterface.php:32`: the return type `array|Listing` is wider than the only implementation, which returns `array`, and nothing in `src/` calls the method. Narrow it to `list<int>`, or deprecate it.
  - `CarrierRepositoryInterface::findForStore()`, `CountryRepositoryInterface::findForStore()` (wrong query, CoreBundle **B-49**), `CategoryRepositoryInterface::findChildCategoriesForStore()`, and `ProductStoreValuesRepositoryInterface::findForProduct()` / `findForProductAndStore()` have no callers in `src/`. Add tests, or mark them `#[\Deprecated]` (PHP 8.4).
  - `Wishlist/Repository/WishlistRepositoryInterface.php:30` returns the Wishlist component's `WishlistInterface`, not the Core one. Narrow it.
  - Add `@return list<…>` generics to all `findFor*()` methods.

### I-33 — `Translation/TranslatableEntityPimcoreLocaleAssigner` and `Events.php` are unused duplicates
- **Where:** `Translation/TranslatableEntityPimcoreLocaleAssigner.php`, `Events.php`.
- **Fix:** neither is referenced or wired in `src/`. `Events::SUPPORTS_PAYMENT_PROVIDER` duplicates `PayumPaymentBundle/Events.php:23`. Decide in one place: wire the assigner or delete it (LocaleBundle **C-01** / **I-19**), and deprecate one of the two `Events` classes (PayumPaymentBundle **I-04**).

### I-34 — Portlet / Report interfaces: document the `InputBag` semantics and add a shared base
- **Where:** `Portlet/{PortletInterface,ExportPortletInterface}.php`, `Report/{ReportInterface,ExportReportInterface}.php`.
- **Fix:** callers pass `$request->query` (an `InputBag`, whose `get()` rejects non-scalars in Symfony 7). Document it, and have implementations read with `getString()` / `getInt()` (CoreBundle **I-35** / **I-37**). Make `ExportReportInterface extends ReportInterface` (the same for portlets), and add `@return list<array<string, mixed>>` shapes.

### I-35 — Forward compatibility outside this component that its mappings depend on
- **Where:** **(dependent)** `CoreBundle/Resources/config/doctrine/model/{Country,Store}.orm.xml` (`<cascade-merge/>`, removed in ORM 3, CoreBundle **I-07**); `Component/Resource/Doctrine/Type/*` (`bigintInteger`, `pimcoreObject`: `getName()`, `requiresSQLCommentHint()`, `int` binding types are all removed or changed in DBAL 4); `ResourceBundle/Pimcore/PimcoreDaoRepository` (`find*()` will need native return types in doctrine/persistence 4); `Component/User/Model/User::eraseCredentials()` (deprecated since Symfony 7.3, CoreBundle **C-05**).
- **Fix:** plan these together with a DBAL 4 / ORM 3 upgrade. Nothing in this component needs to change for Symfony 7.4 itself.

---

## Already tracked elsewhere (not repeated)
- `Component/Payment/TODO-3-IMPROVEMENTS.md` **I-03**, **I-07**, **I-08**; `Bundle/PaymentBundle/TODO-3-IMPROVEMENTS.md` **I-01** / **I-13** (payment rule evaluated twice per recalculation).
- `Component/Currency/TODO-3-IMPROVEMENTS.md` **I-03**, **I-07**, **I-10**, **I-15**.
- `Component/Customer/TODO-3-IMPROVEMENTS.md` **I-04**, **I-05**, **I-09**, **I-10** (`UserTypeChecker`, `TokenBasedCustomerContext`, `ShopperContext`, roles, address access enum).
- `Component/Configuration/TODO-3-IMPROVEMENTS.md` **I-04**, **I-12**; `Bundle/ConfigurationBundle/TODO-3-IMPROVEMENTS.md` (Core `ConfigurationService` section).
- `Bundle/LocaleBundle/TODO-3-IMPROVEMENTS.md` **I-12**, **I-19**.
- `Bundle/InventoryBundle/TODO-3-IMPROVEMENTS.md` **I-01**, **I-05** (`OrderInventoryOperator` deduplication, `readonly` manager).
- `Bundle/CoreBundle/TODO-3-IMPROVEMENTS.md` **I-07** (ORM mappings), **I-14**, **I-19**, **I-23**, **I-26**, **I-30**, **I-35**, **I-37**, **I-42** (serializer metadata), **I-43** (repository SQL).
- `Component/PayumPayment/TODO-3-IMPROVEMENTS.md` (empty `PaymentProvider` constructor); `Bundle/PayumPaymentBundle/TODO-3-IMPROVEMENTS.md` **I-04**.
- `Component/Address/TODO-3-IMPROVEMENTS.md` **CI-03** (`CountriesAwareTrait` constructor; see TODO-2 **B-37**).
