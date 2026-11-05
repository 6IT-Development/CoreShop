# FrontendBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this bundle, but they come from how this bundle's controllers, routes or config use them, and they should be fixed together with it.
Paths are relative to the bundle root unless noted otherwise. Items are roughly ordered by impact within each section.

Background for the security items: `CoreBundle/Security/AccessVoter.php` grants **every** `CORESHOP_*` attribute to everyone (`voteOnAttribute()` returns `true`). So the `denyAccessUnlessGranted('CORESHOP_…')` calls in the controllers are only extension hooks, not access control. The real protection is the `getCustomer()` / ownership checks in each action.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security

### B-01 — `CartController::addItemAction()` renders any Twig template whose name is in the request (`?template=`)
- **Status:** fixed — both render calls go through the new `CartController::getAddToCartTemplate()`. It reads `template` only from `$request->attributes` (internal `render(controller(…, {template: …}))` sub-requests or route defaults), never from the query string or body, and accepts only names ending in `.twig` without `..` or `\`. Anything else falls back to the configured `Product/_addToCart.html` template. The bundle's own templates use `render(url('coreshop_partial_cart_add'))` and never pass `template`, so they are unaffected.
- **Where:** `Controller/CartController.php:217-224` (the not-granted GET branch) and `:304-310`: `$this->render($this->getParameterFromRequest($request, 'template', …), …)`. Routes `coreshop_cart_add` and `coreshop_partial_cart_add` in `Resources/install/pimcore/staticroutes.yml`.
- **Problem:** `getParameterFromRequest()` reads attributes, then the **query string**, then the body. Anyone can therefore have the shop render an arbitrary registered template (`@PimcoreAdmin/…`, `@CoreShopCore/…`, other bundles' mail or admin templates) with `form`/`product` = `null`. Twig's loader blocks path traversal, but this is still a server-side template-selection primitive. It discloses which templates exist, and what they print with empty context. It also reaches templates that run sub-requests (`render(controller())`). And it causes 500s that can be used to poison caches. The parameter is only meant for the `render(controller(..., {template: ...}))` partial call.
- **Fix direction:** read `template` only from `$request->attributes` (that is, only from internal sub-requests), and check it against an allowlist (for example, it must start with the configured `view_prefix` and end with `.twig`). Or drop the parameter and let projects override the controller or template instead.

### B-02 — Open redirect through the `_redirect` parameter
- **Status:** fixed (with AddressBundle C-04) — every `_redirect` value goes through `SafeRedirectTrait::getSafeRedirectTarget()` (ResourceBundle), used by `FrontendController` and `StorageListController`. Only local paths and same-host http(s) URLs are followed, otherwise the fallback route is used.
- **Where:** `Controller/CartController.php:229`, `:252` (`$this->redirect($redirect)` at `:240`, `:279`, `:295`); `Controller/CustomerController.php:197-199`; `Controller/RegisterController.php:54`, `:65`. Templates generate the parameter themselves (`Security/_login-form.html.twig:48` passes `target`, and `Checkout/steps/address_customer.html.twig:49,69,102`).
- **Problem:** the value comes from query or body and goes straight into `AbstractController::redirect()`, with no host check. `https://shop.example/en/shop/register?_redirect=https://evil.example/login` sends a freshly registered customer to a phishing page. The `_target_path` / `_failure_path` of the login form are **not** affected: Symfony's `HttpUtils` gets a domain regexp from `AddSessionDomainConstraintPass`. Only these hand-written redirects are.
- **Fix direction:** add a helper in `FrontendController`, `redirectToLocal(string $target, string $fallbackRoute)`, that accepts only relative paths (`str_starts_with($t, '/') && !str_starts_with($t, '//')` and no `\`), or same-host absolute URLs. Use it at every `_redirect` site. Cover it with a test.

### B-03 — State-changing actions work with plain GET requests and have no CSRF protection
- **Status:** fixed — the controllers enforce it, so installs whose static routes were created before this change are protected too:
  - `removeItemAction()`, `removePriceRuleAction()` and `CustomerController::addressDeleteAction()` require `POST` and a valid `_token` (intentions `coreshop_cart_remove`, `coreshop_cart_remove_price_rule`, `coreshop_customer_address_delete`), checked by the new `FrontendController::isCsrfTokenValidInRequest()` (query first, then body). The templates now use forms: a standalone form in `Cart/_widget` and `Customer/addresses`, and `formaction`/`formmethod="post"` buttons in `Cart/Summary/_item` and `_rules` (those rows are inside the cart form, and HTML forms cannot nest). The `data-test-*` attributes moved to the buttons.
  - `doCheckoutAction()` and `createQuoteAction()` are reached by the summary step's redirect, so they stay GET. `SummaryCheckoutStep::getResponse()` (CoreBundle) now adds a `_token` for the target route name (new optional `CsrfTokenManagerInterface` argument, wired as `@?security.csrf.token_manager`), and both actions reject a missing or invalid token with a flash and a redirect to the cart.
  - `switchAction()` requires `POST` (405 otherwise), and the widget posts a small form. No CSRF token on purpose: the widget is on every page, and a token would start a session for every anonymous visitor. With `SameSite=Lax` session cookies, a cross-site POST carries no session anyway, and the impact is a preference change.
  - `staticroutes.yml` gives `coreshop_cart_remove`, `coreshop_cart_remove_price_rule`, `coreshop_customer_address_delete` and `coreshop_switch_currency` `methods: [POST]` for new installs. Existing installs keep their routes (no migration). That is safe because of the controller checks, but older themes that still link to these routes with GET stop working and have to switch to forms.
  - New translation `coreshop.ui.error.invalid_csrf_token` (en/de/it). `coreshop_partial_cart_add` is left as is: its GET only renders the form, and the POST goes through the Symfony form's own CSRF token.
- **Where:** `Resources/install/pimcore/staticroutes.yml`. These routes have no `methods`: `coreshop_checkout_do`, `coreshop_cart_remove`, `coreshop_cart_remove_price_rule`, `coreshop_cart_create_quote`, `coreshop_customer_address_delete`, `coreshop_switch_currency`, and `coreshop_partial_cart_add` (only `coreshop_cart_add` is POST-only). Controllers: `CheckoutController::doCheckoutAction()` `:155-277`, `CartController::removeItemAction()` `:313-338`, `::removePriceRuleAction()` `:340-360`, `::createQuoteAction()` `:85-94`, `CustomerController::addressDeleteAction()` `:210-240`, `CurrencyController::switchAction()` `:44-69`.
- **Problem:** a third-party page with `<img src="https://shop/en/shop/checkout-do">` turns the victim's valid cart into an order (`TRANSITION_ORDER`). For a zero-total cart (for example, a 100 % voucher) it also confirms the order and marks it paid (`:242-257`). With the same trick an attacker can delete the victim's addresses (`/shop/customer/addresses/delete/<id>`, ids are sequential), empty their cart, remove vouchers, or turn the cart into a quote. None of these actions checks a CSRF token.
- **Fix direction:** restrict these routes to `POST` (or `DELETE`) in `staticroutes.yml`. Turn the template links into small forms that carry `csrf_token('<intention>')`, and check it with `$this->isCsrfTokenValid()`. Symfony 7.2+ also offers the `#[IsCsrfTokenValid]` attribute. For existing installations, add a migration or an installer note, because static routes are only installed once.

### B-04 — `RegisterController::getCustomer()` always returns `null`, so logged-in customers can open the register page and register a second account
- **Status:** fixed — `CustomerContextInterface` is now in `RegisterController::getSubscribedServices()`, so the "already registered → profile" guard works again. `getCustomer()` catches only `CustomerNotFoundException` (other customer-context errors are no longer swallowed) and returns `null` for a non-Core customer. No functional test was added (the repository has no controller test setup).
- **Where:** `Controller/RegisterController.php:46-50` and `:183-196`; `getSubscribedServices()` at `:169-181` does **not** list `CustomerContextInterface`, and neither does `FrontendController::getSubscribedServices()` (`FrontendController.php:43-53`).
- **Problem:** `$this->container->get(CustomerContextInterface::class)` throws `ServiceNotFoundException`, which is an `\Exception`. The `catch (\Exception) {}` swallows it, so the "already registered → go to profile" guard never fires. A logged-in user can submit the registration form, and the `CustomerManager` then persists (and logs in) a different customer in the middle of the session, while the current cart is still attached to the old one. The broad catch also hides every real error from the customer context.
- **Fix direction:** add `CustomerContextInterface::class` to the subscribed services (or use the parent's `ShopperContextInterface::hasCustomer()` / `getCustomer()` like `CustomerController` does). Narrow the catch to `CustomerNotFoundException`. Add a functional test.

### B-05 — `OrderController::reviseAction()`: `?paymentId=` loads any payment (IDOR), and the loop variable then overwrites it
- **Status:** fixed — `paymentId` is read with `getInt()`, and the payment is used only if it is a Core payment whose `getOrder()?->getId()` matches the revised order, so another customer's payment note or state can no longer be shown. The "already paid" loop uses its own `$orderPayment` variable, so the requested payment is no longer overwritten by the order's last payment.
- **Where:** `Controller/OrderController.php:41-54`; template `Resources/views/Order/revise.html.twig:13-20`; the redirect source is (dependent) `PayumBundle/Action/ResolveNextRouteAction.php:57`, which adds `paymentId`.
- **Problem:**
  - `$this->getPaymentRepository()->find($request->query->get('paymentId'))` accepts any payment id. Nothing checks that it belongs to `$order`. The template then shows `payment.details.coreshop_payment_note` and the state of **another customer's** payment (payment ids are sequential).
  - `foreach (… findForPayable($order) as $payment)` reuses the variable `$payment`. After the loop, `$payment` is the order's last payment, whatever `paymentId` said. The intended "your payment X failed / was cancelled" message therefore shows the wrong payment. It only shows the requested one when the order has no payments, and that is exactly the IDOR case.
- **Fix direction:** rename the loop variable. Accept `paymentId` only if `$paymentObject->getOrder()?->getId() === $order->getId()`. Read it with `$request->query->getInt('paymentId')`.

### B-06 — `OrderController::reviseAction()` lets you change the payment provider and pay again on orders that are cancelled, complete or not yet ordered
- **Status:** fixed — `reviseAction()` returns 404 for an unknown or empty token. It then requires `isOrderPayable()`: `saleState === order`, `orderState` not `cancelled`/`complete`, and `paymentState` in `awaiting_payment`/`partially_paid`. Otherwise it flashes the new `coreshop.ui.error.order_not_payable` (en/de/it) and redirects home. (Dependent) `PayumBundle\Controller\PaymentController::prepareCaptureAction()` applies the same check and returns 404, so `coreshop_order_revise_pay` and `coreshop_payment_token` cannot start a payment for carts, quotes, or cancelled, completed or already paid orders. The normal checkout is unaffected, because the order's `create` transition moves the payment state to `awaiting_payment` before the redirect to payment.
- **Where:** `Controller/OrderController.php:56-75`; `Order/revise.html.twig:13` only *hides* the UI unless `order.paymentState in ['awaiting_payment']`.
- **Problem:** the server does no state check. A POST with the order token saves a new `paymentProvider` on a cancelled or fully paid order (the "already paid" check looks only for `STATE_COMPLETED` payments, not at the order/payment state machine). It then redirects to `coreshop_order_revise_pay`, which creates a new payment. The token also matches carts and quotes (`sale_state` is not checked).
- **Fix direction:** require `saleState === order`, `orderState` not in `[cancelled, complete]` and `paymentState` in `[awaiting_payment, partially_paid?]`, otherwise return 404 or redirect. Mirror the check in `PaymentController::prepareCaptureAction()` (dependent).

### B-07 — `CheckoutController::thankYouAction()` exposes any order, cart or quote by token, and counts a conversion on every reload
- **Status:** fixed — the thank-you page redirects home unless the token belongs to a placed order (`saleState === order`), and it ignores non-string tokens. `doCheckoutAction()` stores the order token in the session (`coreshop.checkout.track_complete_token`). `thankYouAction()` calls `trackCheckoutComplete()` only when that marker matches, and removes it, so reloads, bookmarks and mail links do not track again. The response is `private, no-store` with `X-Robots-Tag: noindex, nofollow`. An ownership check is not added (the 32-character token is still the access key), so a shared link still opens the page.
- **Where:** `Controller/CheckoutController.php:279-298`.
- **Problem:**
  - `findByToken()` does not check `saleState`, so a cart or quote token renders the thank-you page too. Carts and quotes have tokens, and shared storage lists expose their tokens.
  - `trackCheckoutComplete($order)` runs on every GET (refresh, back button, bookmark, mail link), which duplicates purchase events in GA, Matomo and other trackers.
  - The page shows personal data (addresses, items) to anyone who has the URL, but it sends no `Cache-Control: private, no-store` and no `X-Robots-Tag: noindex`.
- **Fix direction:** require `saleState === order`. Track only once (a flag on the order, or a one-time session marker set in `doCheckoutAction()`). Set private/no-store and noindex headers. Optionally require that the current customer owns the order, or that the session holds the token.

### B-08 — (dependent) The deprecated `coreshop_payment` route (`/shop/pay/{order id}`) is shadowed and dead. If its priority is ever raised, it becomes an order-id IDOR
- **Status:** fixed — `coreshop_payment` is removed from `staticroutes.yml`. `PaymentController::prepareCaptureAction()` (PayumBundle) no longer falls back to looking up the order by id: it accepts only a non-empty `token` attribute and returns 404 otherwise. Installs that still have the old static route get a 404 from it, which is what they already got, because `coreshop_payment_token` shadowed it. They can delete the route in the Pimcore admin.
- **Where:** `Resources/install/pimcore/staticroutes.yml` (`coreshop_payment`, priority 2, `([0-9]+)`, and `coreshop_payment_token`, priority 3, `(.*)`); `PayumBundle/Controller/PaymentController.php:47-77` (`findOneBy(['id' => …])` without an ownership check); `PayumBundle/Action/{,Offline/}ResolveNextRouteAction.php` redirect to `coreshop_checkout_thank_you` / `coreshop_order_revise` **with the order token**.
- **Problem:** Pimcore matches static routes by descending priority, so `/en/shop/pay/123` always hits `coreshop_payment_token` with token `"123"`, and the result is a 404. The deprecated route is unreachable, which breaks projects that still link to it. If someone "fixes" this by raising its priority, any order id becomes a capture start, and the redirect after capture reveals the order token. That token then opens the thank-you and revise pages of someone else's order.
- **Fix direction:** remove `coreshop_payment` from `staticroutes.yml` now, together with the `order` fallback in `PaymentController::prepareCaptureAction()`. Do not "repair" it.

### B-09 — Password reset is broken when `core_shop_customer.login_identifier` is `username`
- **Status:** fixed — `passwordResetRequestAction()` reads `$passwordResetData[$resetIdentifier]`, the field `RequestResetPasswordType` actually creates (`email` or `username`). It looks the user up only for a string value, so username installs no longer hit `Undefined array key "email"` and a `TypeError`. The template already renders either field. No functional test was added (the repository has no controller test setup).
- **Where:** `Controller/RegisterController.php:76-87`: the form is built with `reset_identifier = %coreshop.customer.security.login_identifier%`, but the code reads `$passwordResetData['email']`. (dependent) `UserBundle/Form/Type/RequestResetPasswordType.php` names the field after the identifier (`$builder->add($identifier, …)`).
- **Problem:** with `login_identifier: username`, the form data has key `username`. The code gets `Undefined array key "email"`, passes `null` to `findByLoginIdentifier(string $value)`, gets a `TypeError`, and returns a 500. No customer can reset a password.
- **Fix direction:** use `$passwordResetData[$resetIdentifier]`. Add a functional test for both identifier modes.

## Broken features and runtime errors

### B-10 — The cart's shipment calculator can never render: single-colon controller reference plus unregistered services
- **Status:** fixed — `Cart/Summary/_items.html.twig` references `CartController::shipmentCalculationAction` (two colons), so Symfony 7.4 resolves it. `CartController::getSubscribedServices()` now includes `coreshop.factory.address`, `TaxedShippingCalculatorInterface` and `CarriersResolverInterface`. The carriers are sorted with `usort(… <=> …)`, so the comparator returns an int and the list is reindexed. No functional test was added (the repository has no controller test setup).
- **Where:** `Resources/views/Cart/Summary/_items.html.twig:49-51`: `render(controller('…\\CartController:shipmentCalculationAction'))` (one colon). `Controller/CartController.php:167-213` uses `coreshop.factory.address`, `TaxedShippingCalculatorInterface::class` and `CarriersResolverInterface::class`, none of which are in `CartController::getSubscribedServices()` (`:407-430`).
- **Problem:**
  - Symfony 7.4 `ControllerResolver::createController()` only splits on `::`. `Class:method` is treated as a service or class name, which gives `InvalidArgumentException: Controller "…CartController:shipmentCalculationAction" does neither exist as service nor as class`.
  - In `kernel.debug` the **whole cart summary page returns a 500** whenever `cart.shipping(false) is null`, which is the normal state before checkout. In prod, `FragmentHandler` sets `ignore_errors`, so the widget silently disappears and only a log entry is written.
  - Fixing the notation alone then fails with `ServiceNotFoundException` for the three services listed above.
  - `uasort($availableCarriers, fn ($a, $b) => $a['price'] > $b['price'])` (`:203-205`) returns a bool. PHP 8 emits `Deprecated: usort(): Returning bool from comparison function is deprecated` and gives unstable order for equal prices. `'isFreeShipping' => $price === 0` is fine only as long as `getPrice()` keeps returning `int`.
- **Fix direction:** use `::` (or a `ControllerReference`). Subscribe the three services. Use `usort(…, fn ($a, $b) => $a['price'] <=> $b['price'])`. Add a functional test that renders the cart summary with no shipping set.

### B-11 — Checkout events get a malformed argument array (`'step_identifier', $stepIdentifier`)
- **Status:** fixed — both `CHECKOUT_STEP_PRE` and `CHECKOUT_STEP_POST` events get `'step_identifier' => $stepIdentifier`, so `getArgument('step_identifier')` works. The numeric keys `0`/`1` are not kept: nothing in the repository reads them.
- **Where:** `Controller/CheckoutController.php:79` and `:131`: `['step' => $step, 'step_identifier', $stepIdentifier]`.
- **Problem:** a comma where `=>` was intended. Listeners receive `[step => …, 0 => 'step_identifier', 1 => '<id>']`, so `$event->getArgument('step_identifier')` throws `InvalidArgumentException`, and `hasArgument()` is always false.
- **Fix direction:** `'step_identifier' => $stepIdentifier`. Keep the numeric keys for one minor release if BC matters, otherwise just fix it.

### B-12 — `CheckoutEvents::CHECKOUT_DO_POST` is dispatched twice for zero-total orders
- **Status:** fixed — the dispatch inside the zero-total branch is removed. `CHECKOUT_DO_POST` is now dispatched once for every order, after the branch, and a stopped event or its response is handled the same way for free and paid orders.
- **Where:** `Controller/CheckoutController.php:242-257` (dispatch inside the `0 === $order->getTotal()` branch) and `:259-261` (an unconditional second dispatch).
- **Problem:** listeners on `CHECKOUT_DO_POST` (confirmation mails, tracking, ERP export) run twice for free orders. The return value of the first dispatch (stopped or response) is also ignored.
- **Fix direction:** remove the dispatch inside the branch, or skip the second one for this case. Treat the event the same way on both paths.

### B-13 — `CheckoutController::doCheckoutAction()`: no `can()` before `TRANSITION_ORDER`, events built from the context cart after it became an order, inconsistent handling of a stopped `CHECKOUT_PAYMENT_PRE`
- **Status:** fixed — `doCheckoutAction()` checks `$workflow->can($order, TRANSITION_ORDER)` before applying it; if the cart cannot become an order (for example a quote, or a double submit that already changed it) it flashes the new `coreshop.ui.error.cart_not_orderable` (en/de/it) and redirects to the cart summary. `CHECKOUT_DO_PRE`, `CHECKOUT_PAYMENT_PRE` and `CHECKOUT_DO_POST` get `$order` instead of asking the cart context again. Every stopped checkout event (the two step events included) now goes through the new `getStoppedEventResponse()`: flash, then the event response or a redirect to `coreshop_index`. A stopped `CHECKOUT_PAYMENT_PRE` without a response therefore no longer continues to payment. A non-stopped event that sets a response is still honoured.
- **Where:** `Controller/CheckoutController.php:218-222`, `:224-238`, `:254`, `:259`.
- **Problem:**
  - `$workflow->apply($order, TRANSITION_ORDER)` without `$workflow->can()` throws `NotEnabledTransitionException` (500) when the cart is in a sale state other than `cart`/`order` (for example `quote`). A double-click race can also cause it.
  - After the transition, `new CheckoutEvent($this->getCart(), …)` asks the cart context again. Depending on the context implementation, that is the old object (now an order) or a new empty cart. Use `$order` explicitly.
  - When `CHECKOUT_PAYMENT_PRE` is stopped without a response, the code continues to payment, whereas every other stopped event redirects to `coreshop_index`. The `if ($event->hasResponse())` at `:236` duplicates `:231`.
- **Fix direction:** guard the transition with `can()` (flash and redirect to the cart otherwise). Pass `$order` to every event after the transition. Handle "stopped" the same way for all four events (extract a helper, see TODO-3 I-17).

### B-14 — Editing an address gives it a new Pimcore key every time
- **Status:** fixed — `addressAction()` sets a key only when `$eventType === 'add'`, so an edited address keeps its key, path and version history. The key stays `uniqid()`: `CustomerTransformHelper::moveAddressToNewAddressStack()` already passes it through `getSaveKeyForMoving()`, which appends `-1`, `-2`, … when the path exists, so collisions are resolved there. `symfony/uid` is not a dependency of the bundle, so `Uuid::v7()` was not used.
- **Where:** `Controller/CustomerController.php:187-192`: `$address->setPublished(true); $address->setKey(uniqid());` runs for `add` **and** `update`.
- **Problem:** every edit renames the existing object. That breaks path-based references, makes the admin tree and version history confusing, and changes the object's full path in search indices. `uniqid()` is also not collision-safe under concurrency.
- **Fix direction:** set the key only when `$eventType === 'add'`, and use `Pimcore\Model\Element\Service::getUniqueKey()` or a UUID (`Symfony\Component\Uid\Uuid::v7()`).

### B-15 — Category listing: `perPage`, `page` and `sort` are not validated (resource exhaustion and 500s)
- **Status:** fixed — `CategoryController::detail()` clamps `page` to `>= 1` (non-numeric → 1). `perPage` goes through the new `resolvePerPage()`, which accepts only a value from the configured `system.category.{list,grid}.per_page` list (compared as ints) and otherwise uses the configured default (or 10). `type` is limited to `list`/`grid`. A non-string `sort` falls back to the default sort key in both listing modes, so `parseSorting()` no longer gets an array. The general typed-read rewrite of `getParameterFromRequest()` stays with B-16 / TODO-3 I-06.
- **Where:** `Controller/CategoryController.php:120-126` and `:161-162`, `:191-198`, `:269-296`.
- **Problem:**
  - `perPage` is never checked against `$allowedPerPage` (`system.category.*.per_page`). `?perPage=100000` loads and hydrates every product in the category, so a single request can tie up a PHP worker and the database. This is cheap to repeat, so it works as a DoS.
  - A negative `page` or `perPage` makes Knp Paginator throw (`Invalid page number` / `Invalid item per page number`), which returns a 500 instead of a 404 or a clamped value.
  - `?sort[]=x` makes `getParameterFromRequest()` return an array, and `parseSorting(string $sortString)` then throws a `TypeError` (500).
- **Fix direction:** clamp `page >= 1`. Accept `perPage` only if it is `in_array($perPage, $allowedPerPage, true)`, otherwise use the default. Read the values with `$request->query->getInt()` / `getString()` (see TODO-3 I-06).

### B-16 — Array-valued request parameters crash many actions (`TypeError` → 500)
- **Status:** fixed — `FrontendController` has two typed helpers on top of `getParameterFromRequest()`: `getStringParameterFromRequest()` (string, int or float as string, anything else gives the default `''`) and `getIdParameterFromRequest()` (positive int or `null`). Every string sink uses the first (currency code, voucher code, order/thank-you/newsletter/reset tokens, checkout step identifier, category identifier), and every `find($id)` sink uses the second (product, cart item, order, quote, address), skipping the repository call for an empty or invalid value. `?token[]=1`, `?code[]=x`, a missing `currencyCode` and similar now take the action's normal not-found path instead of a `TypeError`. `getParameterFromRequest()` itself is unchanged, because sub-requests pass objects through it (`activeCategory`); its replacement stays with TODO-3 I-06.
- **Where:** `Controller/FrontendController.php:60-75` returns `$request->query->all()[$key]` / `$request->request->all()[$key]` unfiltered (arrays included). Sinks with `string` parameters: `CartController.php:118` and `:345-348` (`findByCode(string)`); `CurrencyController.php:48-49` (`getByCode`); `CheckoutController.php:281-287` (`findByToken(string)`); `CustomerController.php:317-327` (`findByNewsletterToken`); `RegisterController.php:124-132` (`findByResetTokenSecure(string)`); `CategoryController.php:161` (see B-15).
- **Problem:** `?token[]=1`, `?code[]=x` and similar each produce a 500 and an error-log entry. That makes error monitoring noisy and gives attackers a cheap way to fill logs. A missing `currencyCode` / `code` gives `null` and the same `TypeError`.
- **Fix direction:** replace the helper with typed reads: `$request->attributes->getString()`, `$request->query->getString()` (Symfony 6.3+ throws `BadRequestHttpException` for non-scalars), or `#[MapQueryParameter]`. See TODO-3 I-06.

### B-17 — Currency switch accepts any currency, including ones that are inactive or not assigned to the current store
- **Status:** fixed — `switchAction()` looks the code up in `findActiveForStore($store)`, the same list the widget shows (currencies of active countries assigned to the current store), and returns 404 for anything else, so an inactive or foreign currency is never stored in the currency storage or on the cart. The redirect goes through `getSafeRedirectTarget()` with the `Referer` header, falling back to `coreshop_index`, so it only follows local paths or same-host URLs.
- **Where:** `Controller/CurrencyController.php:44-69`. The widget (`:35-42`) lists `findActiveForStore()`, but `switchAction()` uses `getByCode()`.
- **Problem:** `/en/shop/switch-currency/XYZ` with any existing currency code stores it in the currency storage and on the cart. Prices are then calculated in a currency the store does not sell in, possibly without an exchange rate (0 or exception in the money converter), and the cart is persisted that way. A missing code gives a `TypeError` (B-16). The redirect target is the `Referer` header, so the user lands wherever the referring page was, including other hosts.
- **Fix direction:** look the currency up in `findActiveForStore($store)` and return 404 otherwise. Redirect to a local URL only (reuse the B-02 helper).

### B-18 — Search shows products from other stores (`stores LIKE '%<id>%'`), and the user's text is not escaped for `LIKE`
- **Status:** fixed — the store condition is `stores LIKE '%,<id>,%'`, the same comma-delimited format `CoreBundle`'s `ProductRepository`/`CategoryRepository` already use, so store 1 no longer matches 10, 11 or 21. The search text is escaped with `addcslashes($text, '%_\\')`, so `%` and `_` match literally (MySQL's default `\\` escape). `Form/Type/SearchType.php` adds `NotBlank` and `Length(min: 2, max: 100)`; an empty or too short search fails validation and redirects home, as an unsubmitted form already did. `page` is read with `getIdParameterFromRequest()` (default 1). Moving to the index or full-text search is not part of this fix.
- **Where:** `Controller/SearchController.php:52-67`.
- **Problem:**
  - `'%' . $storeId . '%'` for store `1` also matches `10`, `11`, `21`, `100`, …, so products that belong only to other stores appear in the results. The `stores` field is a `coreShopStoreMultiselect`, stored comma-delimited in the query table. **Verify the exact format (`,1,10,`) before fixing.**
  - `%` and `_` in the search text act as wildcards. An empty search (the form has no constraints) matches every active product, so the endpoint is easy to use for scraping or as a load generator.
- **Fix direction:** use `stores LIKE ?` with `'%,' . $id . ',%'` (or the repository's existing store condition). Escape `%`, `_` and `\` in the text, or move to the index or full-text search. Add `NotBlank` + `Length(min: 2, max: 100)` to `Form/Type/SearchType.php`.

### B-19 — `validateProduct()` / `validateCategory()` fail on objects without stores
- **Status:** fixed — `validateProduct()` and `validateCategory()` read `getStores() ?? []`, cast the ids and the current store id to `int`, and compare strictly. A product or category without stores now gets the `NotFoundHttpException` (404) instead of a `TypeError`, and a string id such as `'1'` still matches store 1.
- **Where:** `Controller/ProductController.php:86` (`in_array(…, $product->getStores())`); `Controller/CategoryController.php:262` (`array_values($category->getStores())`).
- **Problem:** `getStores()` returns `?array` for objects that were never assigned a store (common after imports), so these lines throw a `TypeError` (500) instead of returning a 404. `in_array` is also non-strict (`'1abc' == 1` was true before PHP 8; it is still loose for strings).
- **Fix direction:** `in_array($storeId, $product->getStores() ?? [], true)` (keep the int/string types consistent). Throw `NotFoundHttpException` on failure.

### B-20 — `view_bundle` (deprecated) is silently ignored, and its fallback would produce `@@CoreShopFrontend`
- **Status:** fixed — `view_prefix` and `view_bundle` have no default (`defaultNull()`). The extension's new `resolveViewPrefix()` uses `view_prefix` if set, otherwise the deprecated `view_bundle` with exactly one leading `@` (`AppShop` and `@AppShop` both give `@AppShop`), otherwise `@CoreShopFrontend`. A project that sets only `view_bundle` gets its own templates again. `setDeprecated()` names the package `coreshop/frontend-bundle`, and the deprecation is only reported when `view_bundle` is actually set.
- **Where:** `DependencyInjection/Configuration.php:49-51`; `DependencyInjection/CoreShopFrontendExtension.php:46-50`.
- **Problem:**
  - `view_prefix` has `defaultValue('@CoreShopFrontend')`, so `isset($configs['view_prefix'])` is always true. A project that still sets only `view_bundle: '@AppShop'` (as 4.0 documented) gets the bundle templates instead of its own, with no error.
  - The `else` branch adds `'@'` to a value that already starts with `@`.
  - `setDeprecated('coreshop_frontend', '4.1', …)`: the first argument must be the Composer package name (`coreshop/frontend-bundle`).
- **Fix direction:** give `view_prefix` no default (`->defaultNull()`). In the extension, use `view_prefix ?? view_bundle` and do not add a second `@`. Fix the package name in `setDeprecated()`.

### B-21 — `RegisterFrontendControllerPass`: custom controller services lose argument resolution and get registered twice
- **Status:** fixed — `Configuration::DEFAULT_CONTROLLERS` is an explicit `key => default class` map. It builds the `controllers` config section, and the pass uses it as the service id the routes reference, so the `ucfirst()` naming and the `payment` special case are gone. An unknown key throws a `LogicException`. When the configured class is already a service, the pass no longer copies its `Definition` under a second id: it tags that definition with `controller.service_arguments` and `container.service_subscriber` (once each), makes it public and aliases the default id to it, so there is one instance and action arguments such as `StateMachineManagerInterface $machineManager` resolve. The other branch is unchanged apart from the shared tagging.
- **Where:** `DependencyInjection/CompilerPass/RegisterFrontendControllerPass.php:45-53`.
- **Problem:**
  - When the configured class is already a service (the normal case for an autowired `App\Controller\…`), the **same `Definition` object** is registered under a second id. That gives two separate instances, and later changes to one definition affect the other.
  - `controller.service_arguments` is not added. If the app service is not autoconfigured as a controller, actions with service arguments (`CartController::createQuoteAction(Request, StateMachineManagerInterface $machineManager)`, `:85`) fail with "Could not resolve argument $machineManager".
  - No alias is created in this branch, unlike the other one (`:62-64`).
  - Service names are derived with `ucfirst($key)`, and a hard-coded special case handles `payment`. Any new key in `Configuration::addControllerSection()` that does not match a class name breaks silently.
- **Fix direction:** in the custom-service branch, `setAlias($serviceName, $controllerClass)->setPublic(true)` and add the `controller.service_arguments` tag to the custom definition instead of duplicating it. Store an explicit `key => default service id` map instead of building class names. See TODO-3 I-19.

### B-22 — `CoreShopFrontendBundle::getComposerVersion()` can return `null` from a `string` method
- **Status:** fixed — both `InstalledVersions::getPrettyVersion()` calls in `getComposerVersion()` fall back to `''` with `?? ''`, so a path or replaced install without a pretty version no longer throws a `TypeError` in the bundle list or in `getVersion()`.
- **Where:** `CoreShopFrontendBundle.php:65-80`.
- **Problem:** `InstalledVersions::getPrettyVersion()` returns `?string`. It is `null` for some path or replaced installs, and then the method throws a `TypeError`. That shows up in Pimcore's bundle-list admin view, and in `getVersion()` (`:56-63`), which concatenates the result.
- **Fix direction:** `return InstalledVersions::getPrettyVersion(...) ?? '';` (in both branches).

### B-23 — `coreshop:frontend:install --templatePath` handling is broken in several ways
- **Status:** fixed — `--templatePath` is `VALUE_REQUIRED` (default `templates`); an empty value returns `Command::INVALID`. The path is resolved with `Path::makeAbsolute($option, $projectDir)`, so an absolute path is used as is. The new `TemplatesInstaller::resolveViewPrefix()` derives the view prefix from the path relative to `%twig.default_path%` (`templates` → `coreshop`, `templates/shop` → `shop/coreshop`) and refuses a path outside it. `TemplatesInstaller` rewrites the `@CoreShopFrontend/` references and `TemplateConfiguratorInstaller` writes `view_prefix` with that prefix; both get `%twig.default_path%` as an optional constructor argument (fallback `<project>/templates`). An existing `coreshop_frontend.yaml` is only parsed to check for `view_prefix` (`PARSE_CUSTOM_TAGS`, an empty file counts as no config). If the key is missing, one line is inserted below `core_shop_frontend:` with the file's indentation, or the section is appended, so comments, tags and formatting stay; an inline `{ … }` section is refused with a message to add it manually. The command catches installer errors and returns `Command::FAILURE` with the message, or `Command::SUCCESS` with a list of the template directory, view prefix and asset path.
- **Where:** `Command/InstallFrontendCommand.php:49`, `:58-66`; `Installer/TemplateConfiguratorInstaller.php:28-50`.
- **Problem:**
  - `InputOption::VALUE_OPTIONAL` with a default: `--templatePath` given without a value yields `null`, so `$rootPath . '/'` installs into `<project>/coreshop`.
  - An absolute path is still prefixed with the project root (`/project//abs/path`).
  - `TemplateConfiguratorInstaller` always writes `view_prefix: 'coreshop'`. That only resolves when `templatePath` is Twig's default path (`templates`). With any other path the generated config points at templates that do not exist.
  - When the config file exists, `Yaml::parse()` and then `Yaml::dump()` rewrites the whole file and drops comments, `!php/const` tags and formatting. An empty file makes `Yaml::parse` return `null`.
  - The command always returns `0`, even if an installer throws an exception that gets caught higher up, and it reports nothing it did.
- **Fix direction:** use `VALUE_REQUIRED`, resolve with `Path::makeAbsolute($opt, $rootPath)` (`symfony/filesystem`), and derive `view_prefix` from the path relative to the Twig default path (or refuse a path outside it). Only add the key when it is missing, by appending or with `Yaml::parse` + a targeted write. Return `Command::SUCCESS` / `FAILURE` and list what was installed or skipped.

### B-24 — Cart summary: only top-level form errors are flashed, and the cart is persisted twice
- **Status:** fixed — `summaryAction()` flashes `$form->getErrors(true, true)`, so item-level errors from the child forms (quantity limits, stock, minimum order) reach the user. The `persistCart()` call after a successful voucher is removed; the cart is persisted once, after the `coreshop.cart.update` event, so price rules and processors run once and only one Pimcore version is created.
- **Where:** `Controller/CartController.php:150-158` (`$form->getErrors()` without `deep: true`), `:131-145` (`persistCart()` at `:132` and again at `:145`).
- **Problem:** item-level validation errors (quantity limits, stock, minimum order) belong to child forms. They are discarded, the user is redirected back with no message, and nothing changes. With a valid voucher, the cart is recalculated and saved twice, which doubles the price-rule and processor work and creates a second Pimcore version.
- **Fix direction:** `$form->getErrors(true, true)` (as `addItemAction()` already does at `:282`). Persist once after the event dispatch.

### B-25 — Small robustness bugs
- `CartController.php:324`: `$cartItem->getOrder()->getId()`. `getOrder()` is nullable (an orphaned item gives a fatal error). Use `?->`.
- `CustomerController.php:89`: calls `getCustomer()` again instead of using `$customer`. Harmless today, but it runs the context twice.
- `CustomerController.php:318`, `:344-347`: `newsletterUser` is always `null` (dead variable passed to the template).
- `LocaleSwitcherExtension.php:76-88`: for sluggable objects it takes `getSlug($language)[0]` regardless of site. In multi-site setups the link can point to another site's slug. Pick the slug whose `getSiteId()` matches the current site (or `null`).
- `LocaleSwitcherExtension.php:63-65`: `$site->getRootDocument()` can be `null`, which gives a fatal error inside the `try`. The `catch (\Exception)` does not catch `\Error`.
- `LocaleSwitcherExtension.php:92-93`: `$route` can be `null` (a sub-request without `_route`), so `Staticroute::getByName(null)` gives a `TypeError`.
- `SecurityController.php:40`: `renderLayout` from the query string is `'false'` (truthy) or `'0'`. Use `$request->attributes->getBoolean()`.
- `CheckoutController.php:365-368`: `getPayum()` reads `'payum'`, which is not subscribed. Calling it always throws, and it is dead code (see TODO-3 I-07).

### B-26 — Missing or broken translations
- **Where:** `Resources/translations/messages.{en,de,it}.yaml`.
- **Problem:** `it` lacks 26 keys that `en` has (for example `coreshop.ui.brand`, `coreshop.ui.cart.name`, `coreshop.ui.carts`, `coreshop.ui.copy`, `coreshop.ui.inc_tax`, `coreshop.ui.excl_tax`, `coreshop.ui.invoice_number`, `coreshop.ui.no_address_found`, …). It still has the old keys `coreshop.cart_price_rule_voucher_generator.length.{min,max}` where `en` uses `.range`. `en` contains `coreshop.ui.voucher.apply_co`, which looks like a truncated key, and `de` does not have it.
- **Fix direction:** sync the keys, check whether `apply_co` is used in templates (rename or remove it), and add a CI check (`bin/console debug:translation --only-missing it`, or a small key-diff script).

### B-27 — `composer.json` does not declare what the code uses
- **Where:** `composer.json:26-34`.
- **Problem:** the split package uses classes and services from packages it does not require. Some may arrive transitively through `coreshop/core-bundle`, but that is not guaranteed:
  - `coreshop/payum-bundle` (`Configuration.php:34`, `PaymentController` routes) and `payum/core` (`CheckoutController.php:38`)
  - `knplabs/knp-components` (`PaginatorInterface`) and `nesbot/carbon` (`RegisterController.php:21`)
  - `pimcore/static-routes-bundle` (`LocaleSwitcherExtension.php:23`)
  - `symfony/{framework-bundle,console,filesystem,finder,yaml,form,security-http}`, `twig/twig`
  - the test bundle (C-01)
  
  There is no `php` constraint, and `phpstan/phpstan ^1.10` is outdated (PHPStan 2.x is current).
- **Fix direction:** add explicit requires with `^7.4` for the Symfony packages and `"php": "^8.4"` (or the monorepo minimum), and update the require-dev tooling. Run `composer-require-checker` on the split.
