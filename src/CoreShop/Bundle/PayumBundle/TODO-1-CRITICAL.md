# PayumBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (35 files: `CoreShopPayumBundle.php`, `CoreGatewayFactory.php`, `CoreGatewayFactoryBuilder.php`, `Action/**`, `Controller/`, `DependencyInjection/**`, `EventListener/`, `Exception/`, `Extension/`, `Factory/`, `Form/Type/`, `Request/`, `Storage/`), `composer.json`, all of `Resources/config/**` (`services.yml`, `services/{extension,factory,form}.yml`, `payum.yml`, `pimcore/config.yml`, `doctrine/model/*.orm.xml`) and `Resources/views/layout.html.twig`. Call paths were followed into `FrontendBundle` (static routes, `routing.yml`, `RegisterFrontendControllerPass`, `CheckoutController`, `OrderController`), `PayumPaymentBundle` (services, form, doctrine mappings), `OrderBundle` (workflows, `OrderStateResolver`, `OrderRepository`), `PaymentBundle` (payment workflow), `WorkflowBundle` (`StateMachineApplier`, `StateMachineManager`), `ResourceBundle` (`AbstractResourceBundle`, `PimcoreDaoRepository`), `Component/Order` (`Order`, `OrderItem`, `OrderPaymentProvider`) and `Component/Core` (`Order`, `CartPaymentProcessor`).

Reference for API checks: `vendor/` (`symfony/http-kernel` 7.4.21, `doctrine/dbal` 3.10.6, `doctrine/orm` 2.20.13, `payum/payum` 1.7.7, `payum/payum-bundle` 2.7.2, `php-http/guzzle7-adapter` 1.1.0, `php-http/message-factory` 1.1.0) and the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml`. Compared with the vendored `six-it/core-shop` copy, this tree changes `DependencyInjection/CoreShopPayumExtension.php` (no longer loads `payum.yml`) and `Resources/config/payum.yml` (`class:` keys removed). Both were reviewed with extra care (see TODO-3 **I-03**).

"Critical" here means: a security issue, money charged or stored wrongly, data loss or data written wrongly, or an error that takes down every request, a whole area, or the install or upgrade path once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by reading the vendored library source or the compiled container.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers. `php -l` (PHP 8.4.27) passes on every PHP file in the bundle, and no PHP 8.4 deprecation (implicit nullable parameters, etc.) applies. Every service definition resolves in the compiled container. Forward-compatibility and modernisation items are in TODO-3 (**I-01** to **I-03**, **I-13**).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Money charged wrongly

### C-01 — PayPal Express Checkout sends base-currency amounts with the shopper's currency code, and ignores the payment amount — (dependent)
- **Where:** `Action/Paypal/ExpressCheckout/ConvertPaymentAction.php:47-86`.
  - `:49` `PAYMENTREQUEST_0_CURRENCYCODE = $order->getCurrency()->getIsoCode()`: the **shopper's** currency.
  - `:50-51`, `:58`, `:66`, `:74`, `:82`: `getTotal()`, `getItemPrice()`, `getTotalTax()`, `getDiscount()`, `getShipping()`: all **base-currency** values (`Component/Order/Model/Order.php:131-134`). The converted values (`getConvertedTotal()`, `getConvertedItemPrice()`, …) are not used.
  - `$payment->getTotalAmount()` (the amount CoreShop actually wants to collect, set by `Component/Order/Payment/OrderPaymentProvider.php:54` from `getPaymentTotal()`) is never read.
- **Problem:**
  - A customer who switched to a non-base currency (`FrontendBundle/Controller/CurrencyController.php`) is charged the base-currency **number** in their currency. Example: base EUR, order total 100.00 EUR, shopper currency HUF → PayPal charges 100 HUF. With USD/CHF the error is smaller but still real money.
  - The PayPal amount is independent of the CoreShop payment amount. Partial payments, a payment total that differs from the order total, or a payment fee computed after `paymentTotal` (PaymentBundle TODO-1 **C-03**) are charged as the full order total, while CoreShop records `payment.totalAmount`. The order then looks paid or unpaid inconsistently with what was charged.
  - The same base/shopper currency mix exists for every other gateway through `OrderPaymentProvider` (tracked as Component/Currency TODO-1 **C-01**). This item is the PayPal converter's own copy of the bug, which stays even after that one is fixed.
- **Fix:**
  - Take the amount and currency from the payment: `AMT = $payment->getTotalAmount()` in `$payment->getCurrencyCode()`. Do not recompute it from the order.
  - Build the line items in the **same** currency as the payment (converted values when the payment is in the shopper currency, base values when it is in the base currency). Follow the decision taken for Component/Currency TODO-1 **C-01**.
  - If the line items cannot be reconciled with `AMT` (see TODO-2 **B-01**), send no line items rather than wrong ones.
  - Add a test: order in base EUR, shopper currency USD/HUF, check `AMT` and `CURRENCYCODE`.

### C-02 — The pay endpoints create a new payment and start a capture for orders that are already paid, authorized, cancelled, complete, or are not orders at all — (plausible)
- **Where:** `Controller/PaymentController.php:47-77` (`prepareCaptureAction`). Routes: `FrontendBundle/Resources/install/pimcore/staticroutes.yml:116-150` (`coreshop_order_revise_pay`, `coreshop_payment`, `coreshop_payment_token`).
- **Problem:**
  - The action loads the order by token (or by numeric id, see TODO-2 **B-13**) and immediately calls `OrderPaymentProvider::provideOrderPayment()` and creates a capture/authorize token. It checks nothing:
    - `saleState`: carts and quotes have a token from creation (`Component/Order/Factory/OrderFactory.php:46`), so a cart or quote can be "paid".
    - `orderState`: cancelled and complete orders can be paid again.
    - `paymentState` and existing payments: an order with a `completed`, `authorized` or `processing` payment gets another payment.
  - The only guard is in `FrontendBundle/Controller/OrderController.php:47-53` (`reviseAction`), and it checks only `STATE_COMPLETED` and only when the customer goes through the revise form. The pay URLs are plain GET links: the browser back button from the gateway, a second tab, a bookmarked `/shop/pay/{token}` link, or an e-mail link all start a second charge.
  - Customers get charged twice, or charged for a cancelled order. The second payment is captured at the gateway, then `OrderPaymentStateResolver` marks the order "paid" twice over, and a manual refund is needed.
- **Fix:**
  - Before creating a payment, require: `saleState === order`; `orderState` in `[new, confirmed]`; `paymentState` not in `[paid, authorized]`; and no payment of this order in `processing`, `authorized` or `completed` (`coreshop.repository.payment->findForPayable($order)`).
  - Otherwise redirect: to the thank-you page if the order is paid or authorized, to the revise page with a flash message if it is cancelled, or 404 for carts and quotes.
  - Reuse an existing `new` payment of the same provider instead of creating one more on every hit (TODO-2 **B-14**).
  - Add a functional test for each guard.

## Data written wrongly / lost

### C-03 — `TransactionListener` commits on error responses and never rolls back, and `LockingStorage` commits the request transaction early and leaves the connection in no-autocommit mode with an open transaction — (verified)
- **Where:**
  - `EventListener/TransactionListener.php:44-71`: `beginTransaction()` on `kernel.controller` for `PayumController` and `PaymentController`, `commit()` on `kernel.response`, nothing on `kernel.exception` / `kernel.terminate`.
  - `Storage/LockingStorage.php:28-46`: `setAutoCommit(false)` before `find(..., LockMode::PESSIMISTIC_WRITE)`.
  - Activated globally by `Resources/config/pimcore/config.yml:2` (`payum.storage.doctrine.orm.class`). The compiled container uses `LockingStorage` for `payum.security.token_storage` and `payum.storage.coreshop_component_core_model_payment`.
  - DBAL behaviour: `vendor/doctrine/dbal/src/Connection.php:560-577` (`setAutoCommit()` calls `commitAll()` when a transaction is active), `:1516-1529` (`commitAll()`), `:1482-1486` (after the outermost commit in no-autocommit mode DBAL **starts a new transaction**).
- **Problem:**
  - **Commit on error.** When a payment action throws (gateway error, workflow callback error, Pimcore save error), Symfony's `ErrorListener` turns the exception into an error response, and `kernel.response` fires for it. `onKernelResponse` then **commits** whatever was written before the error: half-applied workflow transitions, a confirmed order without a payment state, a payment row in the wrong state.
  - **Early commit.** The first `doFind()` for a payment inside the request calls `setAutoCommit(false)`. DBAL commits the listener's transaction right there. Everything written before that point (for example `PaymentController::prepareCaptureAction()` → `$storage->update($payment)`) is committed outside the intended unit of work.
  - **Lost writes after the response.** With autocommit off, the final `commit()` in `onKernelResponse` makes DBAL open a new transaction that nobody commits. Every write after `TransactionListener::onKernelResponse` (priority 0) is rolled back silently when the connection closes. That covers `kernel.response` listeners with negative priority (for example a DB session handler on the same connection) and all `kernel.terminate` work.
  - **State leaks across requests.** `autoCommit = false` stays on the shared `doctrine.dbal.default_connection` for the rest of the process. In long-running runtimes (FrankenPHP/RoadRunner worker mode, or any reused kernel), every later request runs inside a transaction that is never committed.
  - **Commit can throw after the money moved.** If a nested `rollBack()` happened (Doctrine `transactional()` in a callback), the connection is rollback-only and `commit()` throws `ConnectionException::commitFailedRollbackOnly()`. The gateway already captured the money, the customer sees a 500 and the payment state is lost.
  - The lock only works while the listener's transaction is active (`isTransactionActive()`). Notify handling from a console command, Messenger, or any controller other than the two listed runs without the lock (TODO-2 **B-15**).
- **Fix:**
  - Do not toggle autocommit. Take the lock inside the existing transaction: `find($class, $id, LockMode::PESSIMISTIC_WRITE)` already requires and uses an active transaction, nothing else is needed.
  - Replace the listener pair with a well-defined boundary:
    - roll back on `kernel.exception` (and in `kernel.response` when the response status is ≥ 500 or the request carries an exception attribute);
    - commit only for successful responses and only for the main request (`$event->isMainRequest()`);
    - guard every call with `isTransactionActive()`, and roll back on `kernel.terminate` if a transaction is still open.
  - Better: wrap the Payum execution in `Connection::transactional()` in the controllers (and in a Payum extension for notify paths), so console and Messenger paths get the same lock and atomicity.
  - Add a regression test: an exception after a write must leave the DB unchanged, and a write in `kernel.terminate` must persist.
