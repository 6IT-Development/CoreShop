# Currency Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: all PHP in this component (`Context/*`, `Converter/*`, `Display/*`, `Formatter/*`, `Model/*`, `Repository/*`), `composer.json`, `README.md`, and the code and config that depend on or build on it:
`CurrencyBundle/Resources/config/{services.yml,services/twig.yml,doctrine/model/*.orm.xml}`, `CurrencyBundle/Doctrine/ORM/{Currency,ExchangeRate}Repository.php`, `CoreBundle/Doctrine/ORM/CurrencyRepository.php`, `CoreBundle/Resources/config/{services/context.yml,services/profiler.yml,doctrine/model/QuantityRange.orm.xml}`, `CoreBundle/Storage/CookieStorage.php`, `CoreBundle/Collector/CurrencyCollector.php`, `Component/Core/Context/Currency/{StoreAware,StorageBased,CountryAware}CurrencyContext.php`, `Component/Core/Currency/CurrencyStorage{,Interface}.php`, `Component/Core/Context/{ShopperContext,StoreBasedCartContext}.php`, `Component/Core/Model/{Currency,Country}{,Interface}.php`, `Component/Store/Model/Store.php`, `Component/Core/Order/Processor/CartCurrencyConversionProcessor.php`, `Component/Order/Payment/OrderPaymentProvider.php`, `PayumBundle/Action/{Capture,Authorize}PaymentAction.php`, `OrderBundle/Controller/OrderPaymentController.php`, `MoneyBundle/{Formatter/MoneyFormatter,Twig/FormatMoneyExtension}.php`, the rule action processors and condition checkers that take a currency (`Component/Product/Rule/Action/*`, `Component/Core/{Cart,CartItem,Payment,Shipping,Product}/Rule/*`, `Component/Core/ProductQuantityPriceRules/Rule/Action/*`), `Component/Product/Calculator/Composite*PriceCalculator.php`, `Component/StorageList/Core/Provider/CoreContextProvider.php`, and `Behat/Service/ConfigurationBasedDisplayFractionProvider.php`.

"Critical" here means: a security issue, money charged or stored wrongly, data loss, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers in this component. All 20 PHP files pass `php -l` on PHP 8.4.27. There are no implicit nullable parameters and no removed functions. The only Symfony API used is `RequestStack::getMainRequest()` (still current in 7.4). Gaps are in packaging (undeclared `symfony/http-foundation` and `doctrine/orm`, PHPStan 1.x) and in missing PHP 8.4 / Symfony 7.4 idioms: see TODO-3.

**Overlap with CurrencyBundle TODOs:** the bundle TODOs (`CurrencyBundle/TODO-*.md`) already scanned much of this component. These apply to component files and are **not repeated here**. Fix them together with the items below:
- bundle **C-02** (reverse-pair duplicate → `NonUniqueResultException` in `Converter/CurrencyConverter.php:72`),
- bundle **C-03** (duplicate / `NULL` `isoCode` → `getByCode()` and converter crash),
- bundle **C-05** (zero rate → `DivisionByZeroError` in `Converter/CurrencyConverter.php:51`),
- bundle **B-01** (missing rate silently returns the unconverted amount), **B-02** (converter cache), **B-03** (`CachedCurrencyContext` not resettable), **B-16** (`DefaultDisplayFractionProvider` hard-codes `2`), **B-18** (decorators autoconfigured into the composite).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## C-01 — (dependent) The frontend payment is created with the **base-currency amount** but labelled with the **shopper's currency**, so customers paying in a non-base currency are charged the wrong amount

- **Where:**
  - `Component/Order/Payment/OrderPaymentProvider.php:54` `setTotalAmount($order->getPaymentTotal())`, and `:58` `setCurrency($order->getCurrency())`, `:74` `setCurrencyCode($payment->getCurrency()->getIsoCode())`.
  - `PayumBundle/Action/CapturePaymentAction.php:57-58` and `AuthorizePaymentAction.php:55`: pass `getTotalAmount()` together with `getCurrency()->getIsoCode()` to the gateway.
  - `Component/Core/Order/Processor/CartCurrencyConversionProcessor.php:39-41`: `paymentTotal` is in the store **base** currency (`setBaseCurrency($store->getCurrency())`); the amount in the shopper currency is `convertedPaymentTotal`.
  - For comparison, the admin path `OrderBundle/Controller/OrderPaymentController.php:112` pairs the amount with `$order->getBaseCurrency()`, which is consistent.
- **Problem:** this component defines the "base vs. converted" contract (`CurrencyConverterInterface`, `CurrencyContextInterface`), but nothing ties an amount to its currency. In a store with base EUR where the shopper switched to JPY (`FrontendBundle CurrencyController::switchAction`, `CoreContextProvider.php:46-49`), a cart of 100.00 EUR has `paymentTotal = 10000` and `currency = JPY`. The gateway is asked for "10 000 JPY" (about 60 EUR) instead of about 16 000 JPY. With base JPY and shopper EUR it is the other way round: the customer is overcharged by about 160×. Every gateway that goes through `Convert`/`PayumPayment` is affected. Single-currency stores are not affected, which is likely why it was not noticed.
- **Fix direction:**
  - Decide once which currency the payment uses. Either charge the shopper currency (`setTotalAmount($order->getConvertedPaymentTotal())` + `setCurrency($order->getCurrency())`) or the base currency (`getPaymentTotal()` + `getBaseCurrency()`), and do the same in the frontend and admin paths.
  - Prevent it structurally: carry amounts as `Model/Money` (amount + currency) through the payment API instead of separate `int` and currency setters (see TODO-3 **I-08**).
  - **Verify** against the payment-state and "is order fully paid" logic (it compares payment totals with order totals in one currency) before switching.
  - Add a Behat/functional scenario: base EUR, shopper USD with rate 1.1, check that the gateway receives the converted amount and `USD`.

## C-02 — (dependent) Deleting a currency leaves dangling ids in price rules, quantity ranges and carts; every price calculation that touches them then throws

- **Where:**
  - Rule actions look the currency up by the id stored in the rule configuration and then assert it exists (`Assert::isInstanceOf($currency, CurrencyInterface::class)`): `Component/Product/Rule/Action/{PriceActionProcessor:65,DiscountPriceActionProcessor:65,DiscountAmountActionProcessor:50}.php`, `Component/Core/Cart/Rule/Action/{DiscountAmountActionProcessor:79,SurchargeAmountActionProcessor:71}.php`, `Component/Core/CartItem/Rule/Action/DiscountAmountActionProcessor.php:79`, `Component/Core/Payment/Rule/Action/{PriceActionProcessor,AdditionAmountActionProcessor,DiscountAmountActionProcessor}.php:50`, `Component/Core/Shipping/Rule/Action/{PriceActionProcessor,AdditionAmountActionProcessor,DiscountAmountActionProcessor}.php:51`.
  - Quantity price ranges: `CoreBundle/Resources/config/doctrine/model/QuantityRange.orm.xml:8-10` (`currencyId … on-delete="SET NULL"`), then `Component/Core/ProductQuantityPriceRules/Rule/Action/{FixedAction,AmountIncreaseAction,AmountDecreaseAction}.php:41` assert `$range->getCurrency()` is a currency.
  - Carts and orders store the currency id in a Pimcore `coreShopCurrency` field (no FK). `CartCurrencyConversionProcessor.php:142` then calls `$cart->getCurrency()->getIsoCode()` on `null`.
  - The callers only catch the "no price" exceptions: `Component/Product/Calculator/CompositeRetailPriceCalculator.php:43`, `CompositeDiscountPriceCalculator.php:43`, `ProductPriceCalculator.php:57,67`. `Webmozart\Assert\InvalidArgumentException` and `Error` escape.
  - Nothing prevents the delete: the generic resource `delete` route for `coreshop.currency` has no pre-remove check (only the country cascade, bundle **C-04**).
- **Problem:** an admin deletes a currency they think is unused (for example "CHF" after closing a Swiss store). Every product price rule, cart rule, shipping or payment rule with an amount in CHF, and every quantity range in CHF, now throws on evaluation. Product rules are evaluated for every product price, so every product detail page, category listing and cart that matches those rules returns 500. Open carts in CHF fail on every recalculation. The only fix is to edit each rule in the admin (whose forms may also fail to load the missing choice) or to restore the currency row with the same id.
- **Fix direction:**
  - Add a currency "in use" check before delete (rule configurations, quantity ranges, stores, countries, carts/orders, `MoneyCurrency` fields) and refuse with a clear message, or soft-delete currencies (`active` flag) instead of removing rows. Fix this together with bundle **C-04**.
  - Make the processors defensive: when the configured currency cannot be found, log it and throw the processor's "not applicable" exception (`NoRetailPriceFoundException`, etc.), so the rule is skipped instead of taking the page down.
  - Store the currency **ISO code** next to (or instead of) the id in rule configurations, so a re-created currency matches again.
  - Add a regression test: delete a currency referenced by a product price rule, then render that product.
