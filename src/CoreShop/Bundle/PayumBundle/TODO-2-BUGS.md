# PayumBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs that are not critical by the TODO-1 definition: wrong behaviour in some flows, crashes on edge cases, configuration that is silently ignored.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by reading the vendored library source or the compiled container.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## PayPal Express Checkout converter

### B-01 — The PayPal line items do not add up to `ITEMAMT` / `AMT`, so PayPal rejects the payment (error 10413) — (plausible)
- **Where:** `Action/Paypal/ExpressCheckout/ConvertPaymentAction.php:50-84`, `:97-100`.
- **Problem:**
  - `ITEMAMT` is set to the **grand total** (`:51`). Tax, discount and shipping are then sent as extra line items (`:64-84`) instead of `PAYMENTREQUEST_0_TAXAMT` / `SHIPPINGAMT` / `SHIPDISCAMT`. PayPal requires `ITEMAMT == Σ L_AMTn × L_QTYn` and `AMT == ITEMAMT + SHIPPINGAMT + TAXAMT + …`.
  - Adjustments other than cart price rules and shipping are missing. The payment-provider fee (`Component/Core/Model/Order.php:45-48`, `AdjustmentInterface::PAYMENT`) is part of the total but has no line item. Every order with a payment fee fails.
  - Unit prices are rounded per unit (`getItemPrice(false)` → `formatPrice()`), then multiplied by quantity at PayPal. The sum differs from the order totals by rounding cents on multi-quantity items.
  - Item-level price rules create **neutral** `CART_PRICE_RULE` adjustments (`Component/Order/Cart/Rule/Action/CartItemActionProcessor.php:111-119`, last argument `true`) that are already part of the item price. `getDiscount()` includes them, so the "Discount" line counts them a second time. Verify the sign as well: the line is sent as is, so a positive value adds to the total.
  - `L_PAYMENTREQUEST_0_QTYn` is `OrderItem::getQuantity(): ?float` (`Component/Order/Model/OrderItem.php:409`). PayPal accepts integer quantities only. Fractional or `null` quantities are rejected.
  - `formatPrice()` returns a `float`. PayPal requires a string with exactly the currency's decimals: 2 for most currencies, 0 for `HUF`, `JPY`, `TWD`. A shop with `decimal_precision` 4, or any order in a zero-decimal currency, sends an invalid amount.
- **Fix:**
  - Send `ITEMAMT` = Σ of the item lines, and put shipping, tax, discount and fees in their dedicated fields (or as lines, consistently).
  - Compute every line from totals (`getTotal()` per item, quantity 1 when the quantity is fractional or when `unit × qty` does not match), then put any rounding remainder on a final line, or drop the lines and send only `AMT`.
  - Format amounts with `number_format($value, $currencyDecimals, '.', '')` using a per-currency decimals map.
  - Unit tests: multi-quantity rounding, payment fee, item-level rule, zero-decimal currency.

### B-02 — PayPal ship-to address is filled from the **invoice** address
- **Where:** `Action/Paypal/ExpressCheckout/ConvertPaymentAction.php:102-131` (`prepareAddressData()`, `$order->getInvoiceAddress()` → `PAYMENTREQUEST_0_SHIPTO*`).
- **Problem:** customers with a separate shipping address see (and approve) the invoice address as ship-to at PayPal. PayPal seller protection covers only shipments to the address shown at PayPal, so the merchant either ships to the wrong address or loses protection. Also `LOCALECODE` is set from the invoice **country**, which is not a language (a customer in Austria with an English UI gets a German PayPal page).
- **Fix:** use `$order->getShippingAddress()` for `SHIPTO*`, fall back to the invoice address only if no shipping address exists, and set `NOSHIPPING=1` for orders without a shippable item. Derive `LOCALECODE` from `$order->getLocaleCode()` (for example `de_AT`). Truncate fields to PayPal's limits (`SHIPTONAME` 128, `SHIPTOSTREET` 100, `SHIPTOCITY` 40, `SHIPTOZIP` 20).

## Generic Payum actions

### B-03 — The `Convert` result **replaces** the payment details, so payment settings from checkout are lost
- **Where:** `Action/AuthorizePaymentAction.php:50-51` and `Action/CapturePaymentAction.php:52-53` (`$payment->setDetails($convert->getResult())`). The fallback branch (`:53-60` / `:54-62`) passes the old details through `PayumPayment::setDetails()`, the first branch does not.
- **Problem:** `Component/Order/Payment/OrderPaymentProvider.php:61-63` stores the order's payment settings (for example the selected bank or issuer from the checkout payment step) in `payment.details`. When a CoreShop-specific converter handles `Convert` (`Action/Offline/ConvertPaymentAction.php`, `Action/Paypal/ExpressCheckout/ConvertPaymentAction.php`, or any project converter written the same way), its result overwrites these details. The settings never reach the gateway.
- **Fix:** merge instead of replace: `$payment->setDetails(array_replace($payment->getDetails() ?? [], (array) $convert->getResult()))`, or pass the existing details into the converters and let them merge. Do the same in both actions (see TODO-3 **I-04**).

### B-04 — `afterCaptureAction` returns 500 when no next route is resolved or the payment cannot be loaded
- **Where:** `Controller/PaymentController.php:79-95`, `Request/ResolveNextRoute.php:25` (`private string $routeName;` with no default), `Action/ResolveNextRouteAction.php:39-59`, `Action/Offline/ResolveNextRouteAction.php:38-44`.
- **Problem:**
  - Both resolvers set the route only when `$payment->getOrder() instanceof CoreShop\Component\Core\Model\OrderInterface`. Otherwise `getRouteName()` reads an uninitialised typed property and throws `Error: must not be accessed before initialization` (500). The same happens with a project action that supports `ResolveNextRoute` but returns early.
  - If the payment behind the token was deleted (or the token points to a non-payment model), `$status->getFirstModel()` is `null` (Payum's `Generic::setFirstModel()` skips tokens and identities). `ConfirmOrder` / `ResolveNextRoute` are then not supported and `RequestNotSupportedException` gives a 500.
  - The status result is never read. The action confirms and resolves even when the status is `unknown`.
  - `ResolveNextRouteAction.php:40-42` sets `_locale` and then overwrites the parameters again in both branches (the first call is dead).
- **Fix:** give `ResolveNextRoute` a fallback (`?string $routeName = null` plus a default route such as `coreshop_index`, or throw a clear domain exception that the controller turns into a redirect with a flash message). Check that `getFirstModel()` is a `PaymentInterface` before executing `ConfirmOrder`, and 404 otherwise.

### B-05 — A payment provider without gateway config (or an order without provider) crashes the pay endpoint
- **Where:** `Controller/PaymentController.php:142-167` (`provideTokenBasedOnPayment()`).
- **Problem:** `$payment->getPaymentProvider()` and `->getGatewayConfig()` are only annotated as non-null. A provider whose gateway config was deleted, an order whose provider was deleted, or an admin-created order without provider produce `Error: Call to a member function getConfig() on null` (500) instead of a message to the customer.
- **Fix:** check both, and redirect to the revise page with a flash message (or throw a `NotFoundHttpException`) when either is missing. Use `$gatewayConfig->getConfig()['use_authorize'] ?? false` once instead of reading the config twice.

## Payment state handling

### B-06 — Gateway states without a matching workflow transition are dropped silently — (dependent)
- **Where:** `Extension/UpdatePaymentStateExtension.php:87-100`. Workflow: `PaymentBundle/Resources/config/pimcore/workflow.yml:19-37`.
- **Problem:** `getTransitionToState()` returns `null` when no enabled transition leads to the gateway state, and the extension then does nothing and logs nothing. Real cases:
  - `failed` → `completed` (gateway retried, or a late success after a timeout) and `cancelled` → `completed` / `authorized` (the order expired and cancelled the payment, then the customer finished paying): the money is captured but CoreShop shows the payment as failed/cancelled and the order as unpaid.
  - `authorized` → `refunded` (voided authorization reported as refunded), `processing` → `cancelled` is covered but `authorized` → `failed` is not.
  - Also, `fail` and `cancel` do not trigger `OrderPaymentStateResolver` (`OrderBundle/Resources/config/pimcore/workflow/coreshop_payment.yml:6-8`), so the order payment state is not recalculated after a failure.
- **Fix:** log a warning (PSR logger) with payment id, current and gateway state whenever no transition exists, and dispatch an event so shops can react. Decide in the workflow (PaymentBundle) whether late success from `failed` / `cancelled` is allowed. Add `fail` and `cancel` to the resolver callback.

### B-07 — `GetStatus` maps Payum states to the wrong CoreShop states
- **Where:** `Request/GetStatus.php:53-61`, `:83-91`, `:133-141`.
- **Problem:**
  - `markPayedout()` / `isPayedout()` map to `STATE_REFUNDED`. A payout (money **sent** to a customer or seller) is not a refund. A `Payout` request then moves the payment to `refunded`, and `isRefunded()` and `isPayedout()` can no longer be told apart.
  - `markExpired()` and `markFailed()` both write `STATE_FAILED`, so `isExpired()` is `true` for every failed payment and vice versa. The same holds for `markSuspended()` / `markPending()` (both `processing`). Actions that branch on `isExpired()` or `isSuspended()` (several Payum gateways do) take the wrong branch.
- **Fix:** store the Payum status separately (for example a private `$payumStatus` set in every `mark*()`), answer the `is*()` methods from it, and keep `getValue()` returning the CoreShop state. Decide explicitly how payout maps (probably `unknown`, so `UpdatePaymentStateExtension` ignores it).

### B-08 — Offline `StatusAction` ignores refunded and paid-out payments, and uses loose comparisons
- **Where:** `Action/Offline/StatusAction.php:29-67`.
- **Problem:**
  - Payum Offline sets `Constants::STATUS_REFUNDED` and `STATUS_PAYEDOUT` (`vendor/payum/payum/src/Payum/Offline/Action/RefundAction.php`, `PayoutAction.php`). Both fall through to `markUnknown()`, so a `Refund` request on an offline payment never moves it to `refunded`.
  - `false == $model[FIELD_STATUS]` treats `'0'`, `0`, `''` and `null` the same. All other comparisons are `==` as well.
  - `STATUS_PENDING` → `markNew()` differs from `GetStatus::markPending()` (→ `processing`) used everywhere else, so an offline "pending" payment stays `new` and can be paid again (TODO-1 **C-02**).
- **Fix:** handle all six Offline constants with a strict `match`. Decide whether offline pending is `new` or `processing` and document it.

## Gateway configuration and DI

### B-09 — The PayPal / Sofort gateway form listeners do nothing
- **Where:** `Form/Type/PaypalGatewayConfigurationType.php:58-62`, `Form/Type/SofortGatewayConfigurationType.php:40-44`.
- **Problem:** the `PRE_SET_DATA` listener writes `payum.http_client` into a local copy of `$event->getData()` and never calls `$event->setData()`. The intended `@coreshop.payum.http_client` is never set. The comment in `Resources/config/services.yml:13-14` ("TODO") is stale too, because the service exists in `PayumPaymentBundle/Resources/config/services/payment.yml:18-20`.
- **Fix:** decide whether the gateways need the CoreShop HTTP client. If yes, call `$event->setData($data)` (and handle `null` data for new configs). If not, remove both listeners and the stale comment. Note that `Payum\Core\HttpClientInterface` / `Payum\Core\Bridge\Guzzle\HttpClientFactory` are deprecated in Payum 1.7 in favour of HTTPlug/PSR-18.

### B-10 — The gateway-config validation group is defined in two bundles with different values — (dependent)
- **Where:** `Resources/config/services/form.yml:1-2` (`coreshop.form.type.gateway_config.validation_groups: [coreshop]`), `PayumPaymentBundle/Resources/config/services/form.yml:1-2` (`[fotobuch]`). Consumed by `PayumPaymentBundle\Form\Type\GatewayConfigType`.
- **Problem:** the value depends on extension load order. Today `[coreshop]` wins (compiled container). If the order changes (bundle priorities, a project bundle), `[fotobuch]` wins and every `NotBlank(groups: ['coreshop'])` in the PayPal / Sofort forms is skipped: empty API credentials are saved and payments fail at runtime. `fotobuch` looks like a project-specific leftover. `coreshop.form_registry.payum_gateway_config` is also defined in both bundles (`services.yml:10-11` here, `PayumPaymentBundle/Resources/config/services/payment.yml:15-16`).
- **Fix:** define the parameter and the registry service only in `PayumPaymentBundle`, with `[coreshop]`, and remove both from this bundle.

### B-11 — `core_shop_payum.template.*` is ignored, and its defaults are invalid
- **Where:** `DependencyInjection/Configuration.php:36-42`, `DependencyInjection/CoreShopPayumExtension.php:36-37`.
- **Problem:**
  - The extension writes the **container parameters** `payum.template.layout` / `payum.template.obtain_credit_card`. Payum does not read container parameters: it reads the **gateway config keys** with the same names, which `PayumExtension::loadCoreGateway()` sets to its own defaults (`vendor/payum/payum-bundle/DependencyInjection/PayumExtension.php:118-133`). The compiled container confirms this: the parameter is `@CoreShopPayum/:layout.html.twig`, but `payum.builder` gets `@PayumCore\layout.html.twig`. Configuring `core_shop_payum.template` has no effect. **(verified)**
  - The defaults are broken anyway: `@CoreShopPayum/:layout.html.twig` uses the Symfony 3 `Bundle:dir:file` colon syntax, and `@CoreShopPayum/Action/obtainCreditCard.html.twig` does not exist. If the values were ever used, every gateway that renders a template would fail with "Unable to find template".
- **Fix:** either pass the values to Payum through `prepend()` on the `payum` extension config (gateway config keys) with valid defaults (`@CoreShopPayum/layout.html.twig`), or remove the config tree, the parameters and `Resources/views/layout.html.twig` (see TODO-3 **I-03**).

## Payment entry points

### B-12 — Failed payments are redirected to the revise page with a `paymentId` that the revise page loads without an ownership check — (dependent)
- **Where:** `Action/ResolveNextRouteAction.php:57-58` (`coreshop_order_revise` + `paymentId`). Consumer: `FrontendBundle/Controller/OrderController.php:40-45`.
- **Problem:** `reviseAction` loads `paymentId` from the query with `find()` and passes it to the template, without checking that it belongs to the order identified by the token. Anyone with one valid order token can display other payments by id (number, amount, state, provider, details depending on the template).
- **Fix (FrontendBundle):** load the payment only from `findForPayable($order)` and match the id there. In this bundle, keep passing `paymentId` but document that the consumer must scope it.

### B-13 — The deprecated `coreshop_payment` route looks orders up by numeric id — (plausible, security hardening)
- **Where:** `Controller/PaymentController.php:49-60` (`'id'` / `order` attribute branch), `FrontendBundle/Resources/install/pimcore/staticroutes.yml:137-143`.
- **Problem:** if this route is reachable (today `coreshop_payment_token`, priority 3, `(.*)` shadows `/shop/pay/123`), anyone can start a payment for any order by enumerating ids. The PayPal converter also sends the customer's e-mail and address to the gateway session, where the caller sees them. `findOneBy([...], true)` passes a second argument that `PimcoreDaoRepository::findOneBy(array $criteria)` ignores.
- **Fix:** remove the id branch and the deprecated route. Look orders up with `OrderRepository::findByToken()` only (it also requires exactly one match, `OrderBundle/Pimcore/Repository/OrderRepository.php:87-100`), and reject empty tokens.

### B-14 — Every hit on a pay URL creates a new payment row
- **Where:** `Controller/PaymentController.php:69-72` → `Component/Order/Payment/OrderPaymentProvider.php:39-77` (always `createNew()`).
- **Problem:** reloading the pay URL, the back button from the gateway, or a double click each create one more `new` payment (with its own number). They stay `new` forever (or are cancelled when the order is cancelled), clutter the admin payment list and the payment-state calculation, and make reconciliation with the gateway harder.
- **Fix:** reuse the latest `new` payment of the order with the same provider and amount, or cancel the previous `new` payments when a new one is created. Combine with the guards from TODO-1 **C-02**.

### B-15 — The payment row lock is not taken outside the two HTTP controllers
- **Where:** `Storage/LockingStorage.php:35-38` (lock only when `isTransactionActive()`), `EventListener/TransactionListener.php:48-58` (transaction only for array callables whose object is a `PayumController` / `PaymentController`).
- **Problem:** notify or status sync from a console command, a Messenger handler, a custom notify controller, or an invokable controller (`__invoke`, not an array callable) runs without a transaction and therefore without the lock. Two concurrent notifications (webhook + customer return) can both read `processing` and both apply `complete`, which runs the order-payment callbacks twice. A replacement `payment` controller configured through `core_shop_frontend.controllers.payment` that does not extend `PaymentController` loses the transaction as well.
- **Fix:** move transaction + lock into the Payum layer (an extension with `onPreExecute` / `onPostExecute`, or `Connection::transactional()` around `Gateway::execute()`), see TODO-1 **C-03**. If the listener stays, also handle invokable controllers and check `isMainRequest()`.
