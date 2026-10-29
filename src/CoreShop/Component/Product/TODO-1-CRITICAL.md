# Product Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: the 42 PHP files in `Calculator/` (11), `Exception/` (3), `Repository/` (7) and `Rule/` (21: `Action/*`, `Calculator/ProductPriceRuleCalculator.php`, `Condition/*`, `Fetcher/*`) of `src/CoreShop/Component/Product`, and its `composer.json`. `Model/` is **not** part of this scan. Dependent config and callers that were followed: `Bundle/ProductBundle/Resources/config/{services.yml,services/rules.yml,services/price-rules.yml,services/specific-price-rules.yml}`, `Bundle/ProductBundle/DependencyInjection/CoreShopProductExtension.php`, `Bundle/ProductBundle/Doctrine/ORM/{PriceRuleRepository,ProductSpecificPriceRuleRepository}.php`, `Bundle/ProductBundle/Form/Type/Rule/Action/{PriceConfigurationType,DiscountAmountConfigurationType,DiscountPercentConfigurationType}.php`, `Bundle/ProductBundle/Resources/public/pimcore/js/product/pricerule/conditions/timespan.js`, `Bundle/CoreBundle/Resources/config/services/{product.yml,rules.yml}`, `Component/Core/Product/Calculator/{StoreProductPriceCalculator,StoreProductUnitDefinitionPriceCalculator}.php`, `Component/Core/Order/Calculator/PurchasableProduct*Calculator.php`, `Component/Core/Product/TaxedProductPriceCalculator.php`, `Component/Core/Product/Rule/Condition/{QuantityConditionChecker,NotCombinableWithCartPriceRuleVoucherConditionChecker,CategoriesConditionChecker,ProductsConditionChecker}.php`, `Component/Core/Cart/Rule/Applier/CartRuleApplier.php`, `Component/Order/Calculator/{PurchasableCalculator,PurchasablePriceCalculator}.php`, `Component/Registry/{PrioritizedServiceRegistry,PriorityMap,ServiceRegistry}.php`, `Component/Rule/Condition/{RuleValidationProcessor,RuleConditionsValidationProcessor}.php`, `Component/Currency/Converter/CurrencyConverterInterface.php`, `Bundle/FrontendBundle/Controller/CartController.php`, `Bundle/OrderBundle/Controller/{OrderController,OrderCreationController}.php` and the Behat features `features/domain/product/{price_rules,specific_price_rules}/*.feature`.

The bundle-side wiring has already been scanned in `src/CoreShop/Bundle/ProductBundle` (its TODO files use `C-`, `B-`, `I-` numbers). Several root causes in other components' TODO files point into this component; they are listed here with the **component-side** part of the fix and a cross-reference, so either list can be worked from. When an item is fixed, close it in both files. Items that are new in this scan are marked **(new)**.

"Critical" here means: money charged or displayed wrongly on a common path, an error that takes down a whole area (product pages, listings, cart, checkout) once it is triggered, or a blocker for Symfony 7.4 / PHP 8.4.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4, six-it/pimcore-source 2.x, nesbot/carbon 3.15.0, webmozart/assert, PHP 8.4.27); its copy of `six-it/core-shop/src/CoreShop/Component/{Product,Registry}` is byte-identical to this branch, so runs against it are runs against this code. Component items use the prefixes `CC-` (critical), `CB-` (bugs) and `CI-` (improvements).
Markers: **(dependent)** = root cause or part of the fix is outside this component; **(reproduced)** = run with PHP 8.4.27 against this component's classes; **(verified)** = confirmed by reading the code and config; **(plausible)** = follows from the code, but the trigger depends on data, configuration or deployment.

**No Symfony 7.4 / PHP 8.4 language blocker was found.** All 42 PHP files pass `php -l` on 8.4.27 and load through the vendored autoloader with `E_ALL`: no implicitly nullable parameters (`?\Exception $previousException = null` is explicit), no dynamic properties, no removed functions, `RequestStack::getMainRequest()` instead of the removed `getMasterRequest()`. Carbon 3 is used only for `createFromTimestamp()` / `now()` / `getTimestamp()`, whose behaviour did not change for absolute timestamps. Modernisation (native types, `readonly`, `#[\Override]`, `RoundingMode`, Symfony Clock, `ResetInterface`) is in TODO-3.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Rule fetching – wrong rules for the current shopper

### CC-01 — `MemoryCachedValidRuleFetcher` caches the valid rules per product id only, ignoring the context, and is never reset: one shopper's price rules are applied to another shopper — (reproduced: cache behaviour) (plausible: trigger) (new)
- **Where:** `Rule/Fetcher/MemoryCachedValidRuleFetcher.php:35-48`:
  ```php
  if ($this->requestStack->getMainRequest() instanceof Request) {
      if (isset($this->checkedProducts[$product->getId()])) {
          return $this->checkedProducts[$product->getId()];
      }
  }
  ```
  Wired as decorator of **both** rule fetchers that feed the price calculators: `Bundle/ProductBundle/Resources/config/services/price-rules.yml:126-131` (global product price rules) and `services/specific-price-rules.yml:114-119` (product-specific rules). Neither service has a `kernel.reset` tag, and the class does not implement `ResetInterface`.
- **Why the context matters:** every product price rule condition reads the `$context` / `$params` array, not the product: `customers`, `customerGroups`, `guest`, `countries`, `zones`, `stores`, `currencies`, `timespan`, `quantity` (reads `$params['cart']`, `Component/Core/Product/Rule/Condition/QuantityConditionChecker.php`), `not_combinable_with_cart_price_voucher_rule` (reads the vouchers of `$params['cart']`), `categories` / `products` (read `$params['store']`). The rule list for product 7 is a function of *(product, store, currency, country, customer, cart)*, but the cache key is `7`.
- **Reproduced:** inner fetcher returns `['B2B-RULE']` for a B2B customer group and `[]` otherwise. Guest call first, then B2B call in the same request → both return `[]`. The B2B customer gets the guest price.
- **Impact:**
  - **Long-running PHP (FrankenPHP worker mode, RoadRunner, Swoole — first-class deployment targets for Symfony 7.4 via `symfony/runtime`):** the array lives for the whole worker. The first visitor who triggers a price for product 7 decides the price rules for every later visitor of that worker: customer-group prices, country / zone prices, currency prices and timespan rules leak between customers. The cart processor uses the same fetchers (`Component/Core/Order/Processor/CartItemsProcessor` → `PurchasableProduct*Calculator` → `ProductPriceCalculator` → `ProductPriceRuleCalculator`), so the wrong price is not only shown but **charged**. Rules that are deactivated or edited in the admin stay in effect until the worker restarts (only `getMainRequest()` is checked, which is `null` between requests, but the array is not cleared).
  - **PHP-FPM, one request:** wrong when the context changes between two lookups of the same product in one request. Paths that do this: a cart quantity change or voucher submission that is processed twice in one request (`Bundle/FrontendBundle/Controller/CartController.php:131-145` processes and persists the cart, then dispatches `coreshop.cart.update` and persists again — listeners that change the cart between both passes see stale `quantity` / `not_combinable` results); custom code that renders prices for several stores / currencies / customers in one request (feeds, price comparisons, admin previews); a Twig page that calls `coreshop_product_price` with a modified context. **(plausible)**
  - Same defect class as ProductQuantityPriceRulesBundle TODO-2 **B-12** (the quantity-price-rule fetcher). There it is tracked as a bug; here it hits the base price path of every product, so it is listed as critical.
- **Fix (component side):**
  - Key the cache by product **and** a context fingerprint built from the values the conditions read: store id, currency id, country id, zone ids, customer id, customer-group ids, guest flag, cart id + cart modification marker (or skip caching when a `cart` is in the context), and the current minute for timespan rules. Keep it in a `\WeakMap<ProductInterface, array<string, list<RuleInterface>>>` so unsaved products and garbage-collected objects are handled (see TODO-2 **CB-06**).
  - Implement `Symfony\Contracts\Service\ResetInterface` and clear the map in `reset()`; tag the two decorators with `kernel.reset` (`method: reset`) in `Bundle/ProductBundle/Resources/config/services/{price-rules,specific-price-rules}.yml` **(dependent)**.
  - Better: cache the expensive part only — the result of `ProductPriceRuleRepositoryInterface::findActive()` (identical for all products and contexts) — and always evaluate the conditions. See TODO-3 **CI-03**.
  - Add a regression test: two contexts that differ only in customer group, same product, one fetcher instance → two different rule lists.

---

## Price arithmetic – wrong amounts charged

### CC-02 — Product discounts are not capped: the discounts of several rules, several actions and both calculators are added up on the same base price, and the unit price becomes negative — (reproduced) (Order TODO-1 **C-04**)
- **Where:**
  - `Calculator/ProductPriceCalculator.php:46-48`: `$price -= $this->getDiscount($product, $context, $price);` — no lower bound.
  - `Calculator/CompositeDiscountCalculator.php:38-40`: sums the global price-rule calculator (priority 20) and the specific-price-rule calculator (priority 30), each computed on the **same** `$price`.
  - `Rule/Calculator/ProductPriceRuleCalculator.php:143-157`: sums every discount action of every valid rule, again on the same `$price`.
  - `Rule/Action/DiscountAmountActionProcessor.php:34-53`: returns the converted amount without looking at `$price`.
  - `Rule/Action/DiscountPercentActionProcessor.php:26-31`: each action is limited to 0–100 % by the form (`Bundle/ProductBundle/Form/Type/Rule/Action/DiscountPercentConfigurationType.php:41-47`), but nothing limits the sum.
- **Reproduced:**
  - Retail 5.00, global rule 60 % + product-specific rule 60 % → `getPrice($product, $ctx, true) === -100` (−1.00).
  - One rule with two 60 % actions → `getDiscount(…, 500) === 600`.
  - "10.00 off" (`discountAmount`) on a 5.00 product → discount 1000, price −5.00 (the setup of Order TODO-1 **C-04**).
- **Impact:** the negative unit price flows through `Component/Core/Order/Calculator/PurchasableProductPriceCalculator.php` into the cart item, the subtotal and the order total. The order is under-charged; with several items the negative line silently reduces what the customer pays for other products. A category-wide "x off" rule plus a cheap accessory in that category is enough. The Behat feature `features/domain/product/price_rules/stop_propagation.feature` shows that adding discounts of several rules is intended, so the case is reachable with normal configuration.
- **Fix (component side):**
  - `ProductPriceCalculator::getPrice()`: `$price = max(0, $price - $discount)`.
  - Cap at the source as well, so `getDiscount()` callers (`Component/Core/Product/TaxedProductPriceCalculator.php:65-66`, the cart item processor, the displayed "you save" amount) never see a discount larger than the price: `CompositeDiscountCalculator` returns `min($price, $sum)`; `ProductPriceRuleCalculator::getDiscount()` returns `min($price, $discount)`; `DiscountAmountActionProcessor` returns `min($price, $converted)`.
  - Decide and document whether several percentage discounts add up (current behaviour, `10 % + 20 % = 30 %`) or compound (`1 − 0.9 × 0.8 = 28 %`); if they compound, pass the already-discounted price to the next action. Either way the cap above is needed.
  - Pin with unit tests (TODO-3 **CI-12**). Fix together with `Component/Order/Calculator/PurchasablePriceCalculator.php:53-55` **(dependent)**.

---

## Robustness – one bad rule takes down every price

### CC-03 — A valid rule whose action type is not registered (or whose currency was deleted, or whose configuration has the wrong type) throws on every price calculation; the composites only catch the "no price found" exceptions, so product pages, listings, the cart and checkout return HTTP 500 — (reproduced) (new for the action type; Currency TODO-1 **C-02** for the currency part)
- **Where:**
  - `Rule/Calculator/ProductPriceRuleCalculator.php:63`, `:104`, `:145`, `:176`: `$this->actionServiceRegistry->get($action->getType())` — no `has()` check. `Component/Registry/ServiceRegistry::get()` throws `NonExistingServiceException`.
  - `Rule/Action/{PriceActionProcessor:63-65,DiscountPriceActionProcessor:63-65,DiscountAmountActionProcessor:48-50}.php`: `Assert::isInstanceOf($currency, CurrencyInterface::class)` after `find($configuration['currency'])` → `Webmozart\Assert\InvalidArgumentException`.
  - `Rule/Action/{DiscountPriceActionProcessor:67,DiscountAmountActionProcessor:52}.php`: the configured value is passed uncast to `CurrencyConverterInterface::convert(int $value, …)` under `strict_types` → `TypeError` (TODO-2 **CB-09**).
  - Callers that would have to contain the failure catch only `NoRetailPriceFoundException` / `NoDiscountPriceFoundException`: `Calculator/CompositeRetailPriceCalculator.php:41-44`, `CompositeDiscountPriceCalculator.php:41-44`, `ProductPriceCalculator.php:55-70`; `CompositeDiscountCalculator.php:38-40` and `CompositeProductCustomAttributesCalculator.php:40-46` catch nothing.
- **Reproduced:**
  - Active rule with an action of type `removedAction` → `NonExistingServiceException: Action service "removedAction" does not exist, available action services: "discountPercent", …` from `getDiscount()`. The message also lists every registered action type.
  - `price` / `discountPrice` action with a currency id that no longer exists → `InvalidArgumentException: Expected an instance of …CurrencyInterface. Got: NULL`.
- **When the type disappears:** a bundle or a project action processor is removed or disabled, a service tag `type` is renamed, a database / version restore brings back an old type, or an action is stored through another rule type's registry. The `type` column is free text. Nothing checks it on load.
- **Impact:** a *global* product price rule is evaluated for **every** product in every context. One such rule → every product detail page, every category listing with prices, every cart recalculation and the checkout fail with HTTP 500, until someone finds and edits the rule (whose admin form may also fail to render the unknown action). Same failure class as Rule TODO-1 **C-01** (unknown *condition* type), which does not cover action types.
- **Fix (component side):**
  - In `ProductPriceRuleCalculator`, resolve the processor through one private method: `if (!$this->actionServiceRegistry->has($type)) { log critical (rule id, action id, type); skip the action; }`. Inject an optional `Psr\Log\LoggerInterface` (component stays framework-free).
  - Fail **closed** for a broken action of a valid rule: skip that rule's price / discount actions instead of half-applying it, and log it.
  - In the action processors, turn "currency not found" and malformed configuration into the processor's own "not applicable" exception (`NoRetailPriceFoundException` / `NoDiscountPriceFoundException`) or, for discount actions, a `0` discount plus a log entry — never an `Assert` / `TypeError` that escapes. See TODO-2 **CB-09** for normalising the configuration.
  - Provide a health check that lists active rules with unknown action types (natural place: `coreshop:rules:check-availability`, see Rule TODO-1 **C-01**) **(dependent)**.
  - Add a regression test: register no processor for an action type used by an active rule, then calculate a price → price without that rule, one log entry, no exception.

---

## Overlap with other TODOs (not repeated here)

- Order TODO-2 **B-04** / Core TODO-2 **B-05**: a product without a resolvable price is sold at 0. The component part (`ProductPriceCalculator` turns "no price" into `0`, `NoPriceFoundException` is never thrown) is TODO-2 **CB-04** here.
- Currency TODO-1 **C-02**: deleting a currency that a product price rule references. The component part is in **CC-03** above.
- ProductBundle TODO-1 **C-03**: autoconfiguration tags any `ProductPriceCalculatorInterface` implementation (for example a decorator, or `MemoryCachedProductPriceCalculator` once wired) as all four calculator types → endless recursion. The component part (the facade interface extends the four strategy interfaces) is TODO-3 **CI-09**.
- ProductBundle TODO-2 **B-05**: `services.yml:59` aliases the non-existent `ProductCustomAttributeCalculatorInterface` (singular); the real `ProductCustomAttributesCalculatorInterface` has no autowiring alias.
- ProductBundle TODO-2 **B-26**: `findActive()` loads every active rule with lazy conditions and actions per price calculation (N+1).
- ProductBundle TODO-2 **B-33**: `TimespanConfigurationType` has no constraints. The checker side is TODO-2 **CB-08**.
