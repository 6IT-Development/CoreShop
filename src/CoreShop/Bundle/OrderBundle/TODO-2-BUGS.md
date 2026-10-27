# OrderBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs. They are wrong behaviour but not critical by the definition there: they break a feature, an admin action, a report or a non-default configuration, not the shop as a whole.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(reproduced)** were confirmed with a script against the vendored libraries.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Admin order controllers

### B-01 — Order-creation totals: wrong discount tax, and a bogus payment-fee row that appears twice with CoreBundle
- **Where:** `Controller/OrderCreationController.php:269-278`; `CoreBundle/Controller/OrderCreationController.php:108-140` (`array_splice` at index 6); JS `Resources/public/pimcore/js/order/create/step/totals.js:17-33`.
- **Problem:**
  - `discount_tax` is computed as `-1 * gross - net` where it should be `-(gross - net)`. With gross −120 and net −100 the UI shows 220 instead of 20.
  - The base `payment_provider_fee` row uses the subtotal as its value, and the opposite sign for the converted value.
  - CoreBundle inserts the real fee row at index 6, which pushes the bogus row to index 10, so the grid shows two fee rows, one of them equal to the subtotal.
- **Fix:** correct the formula, remove the base fee row, and key the summary entries by `key` instead of by position.

### B-02 — `getStatesAction` fills `tos` with the from-places
- **Where:** `Controller/OrderController.php:134-138`, which calls `$transition->getFroms()` where it should call `getTos()`.
- **Fix:** use `getTos()`, and `array_unique()` both lists.

### B-03 — `OrderController::updateAction` saves without checking that the form is valid
- **Where:** `Controller/OrderController.php:248-310`.
- **Problem:** after `handleRequest()` it goes straight to `getData()` and `persistCart()` and reports success. Invalid input, including the 0 or negative quantities from B-30, is persisted. `OrderEditController.php:71-76` does check validity.
- **Fix:** require `isSubmitted() && isValid()` and return the form errors.

### B-04 — "Order editable" is never enforced on the server
- **Where:** `Controller/OrderController.php:225-325`; `Controller/OrderEditController.php:45-195`. The rule is `Component/Order/OrderEditPossible.php:30-56`, and it is only used to set a UI flag (`OrderController.php:498`; JS `order/detail/panel.js:141,183,192`).
- **Problem:** paid, complete or cancelled orders (and quotes or carts) can be changed by a direct POST. The `order-edit/*` routes have no bundled JS caller, so only the UI enforces the rule.
- **Fix:** inject `OrderEditPossibleInterface` and reject the request unless `isOrderEditable()` is true.

### B-05 — Manual payments accept zero or negative amounts and cancelled or complete orders, and the overpayment guard is not race-safe
- **Where:** `Controller/OrderPaymentController.php:63-141`; the UI-only rule is at `OrderController.php:502-505`.
- **Problem:**
  - A negative amount passes the guard and creates a negative payment.
  - The order-state rule exists only in the UI.
  - Two concurrent requests can both pass the overpayment check.
  - A non-numeric amount raises `TypeError: Unsupported operand types`, so the request fails with a 500. This was verified on PHP 8.4.
- **Fix:** add form validation (`> 0`, numeric), a server-side order-state check, and a lock or transaction around the check and the insert. See also TODO-1 **C-07**.

### B-06 — State transitions are applied before the risky step, without a transaction
- **Where:** `Controller/OrderInvoiceController.php:126-150`, `OrderShipmentController.php:123-147`, `OrderCreationController.php:144-165`.
- **Problem:**
  - **Invoice and shipment.** `request_invoice` / `request_shipment` is applied before the transformer runs. The transformer saves the document before its items (`Component/Order/Transformer/OrderToInvoiceTransformer.php:83-115`), so a failure leaves the order in `ready` with a half-built document. The raw exception message is returned to the UI.
  - **Sale creation.** `saleCreationAction` calls `persistCart()` before `can()`. When the transition is not allowed it throws `HttpException(500)` and leaves an orphan cart behind.
- **Fix:** validate first, wrap the steps in `Db::get()->transactional()`, and log the error while returning a generic message.

### B-07 — The admin UI switches language after an order is saved
- **Where:** `Controller/OrderController.php:319` calls `getDetails()` without the locale; `:547-555` and `:579-588` call `parseTransitions()` without the locale.
- **Problem:** after a save, labels come back in the default locale. Invoice and shipment transition labels always use the default locale.
- **Fix:** pass the admin user's language in both places.

### B-08 — State-history times are shown in UTC on a 12-hour clock without AM/PM
- **Where:** `Controller/OrderController.php:687-699`.
- **Problem:** Carbon 3's `createFromTimestamp()` returns UTC (`vendor/nesbot/carbon/src/Carbon/Traits/Timestamp.php:30-37`). The format `h:mm` is a 12-hour clock without a meridiem. The avatar URL `/admin/user/get-image` is hard-coded.
- **Fix:** pass the default timezone and use `HH:mm`. Generate the avatar URL from the route name.

### B-09 — `findOrderAction` can return an unrelated order
- **Where:** `Controller/OrderController.php:331-341`.
- **Problem:** the condition `orderNumber = ? OR id = ?` lets MySQL cast `'2024-0001'` to `2024`, so another order can match. There is no ordering, so `$orders[0]` is arbitrary.
- **Fix:** match the order number first. Use the id only when `ctype_digit()` holds, and bind it as an int.

### B-10 — Missing or invalid request parameters cause a `TypeError` / 500 instead of a JSON error
- **Where:**
  - `OrderController.php:153,162`: a null transition is passed to `Workflow::can()` under `strict_types`.
  - `OrderController.php:157`: throws a bare `\Exception('invalid order')`.
  - `OrderController.php:185-188`: an unknown `saleType` raises `ParameterNotFoundException`.
  - Null transition: `OrderPaymentController.php:46,54`, `OrderInvoiceController.php:160,168`, `OrderShipmentController.php:156,164`.
  - `OrderCommentController.php:39-42`: `getObjectNotes(null)`.
  - `OrderCommentController.php:47`: `User::getById(null)` **(plausible)**.
  - `OrderCommentController.php:63,77`: `nl2br(null)` raises a `TypeError` (verified). The `catch (\Exception)` does not catch it.
  - `OrderCommentController.php:79-81`: `storeNote()` returns `?Note`, which is not checked.
  - `OrderEditController.php:110-111` (unchecked nested keys) and `:185` (`getOrder()` can be null).
  - `OrderCreationController.php:183-185`: `getStore()->getCurrency()` is called before the null check.
- **Fix:** validate the inputs, whitelist `saleType` against `order`/`quote`/`cart`, add null checks, and return JSON errors or 404. Request DTOs with `#[MapRequestPayload]` / `#[MapQueryParameter]` would cover most of these.

### B-11 — A permission denial returns 500 instead of 403 — (dependent)
- **Where:** `ResourceBundle/Controller/PimcoreController.php:27,61` uses `Symfony\Component\Finder\Exception\AccessDeniedException`. Affected: `OrderCreationController::saleCreationAction`, and every check added for TODO-1 **C-01**.
- **Problem:** the Finder exception extends `\UnexpectedValueException`, so a denied user gets a 500 instead of a 403. `ResourceController` uses the Security exception correctly. Also, the comment at `OrderCreationController.php:114` ("TODO: fails with must not be accessed before initialization") looks stale: `setTokenResolver` is wired in `ResourceBundle/Resources/config/services.yml:60-67` and is inherited through `PimcoreDriver.php:79-99`.
- **Fix:** throw `Symfony\Component\Security\Core\Exception\AccessDeniedException` (or `AccessDeniedHttpException`) and handle a null user. Remove the stale TODO.

### B-12 — Voucher-code grid: delete is not scoped to the rule, and the grid's remote filter and sort are ignored
- **Where:** `Controller/CartPriceRuleController.php:205-224` and `:77-102`; JS `Resources/public/pimcore/js/cart/pricerules/item.js:145-170`.
- **Problem:** delete does not check that the code belongs to the rule, and it checks the concrete class instead of the interface. The list action ignores the grid's `filter` and `sort` parameters, so filtering in the UI has no effect.
- **Fix:** scope deletion to the rule, check against `CartPriceRuleVoucherCodeInterface`, and implement or disable remote filter and sort.

### B-13 — `exportVoucherCodesAction` writes the CSV with `header()`/`echo`/`die()`
- **Where:** `Controller/CartPriceRuleController.php:158-203`.
- **Problem:**
  - It bypasses the kernel: no response listeners, no session save, no profiler, and a `void` return.
  - Values are not quoted, so codes with commas or quotes break the rows, and nothing neutralises formula injection (`=`, `+`, `-`, `@`).
  - The rule name goes unescaped into `Content-Disposition`.
  - It calls `ini_set()` and exports only one page.
- **Fix:** return a `StreamedResponse` that writes with `fputcsv()`, sanitise the cells, and build the header with `HeaderUtils::makeDisposition()`.

### B-14 — Admin customer and address creation are not atomic
- **Where:** `Controller/CustomerCreationController.php:63-102`, `Controller/AddressCreationController.php:60-78`.
- **Problem:** the customer, address and second customer saves run without a transaction, so a failure leaves half-created data. `mb_substr(null)` is possible when the last name is optional **(plausible)**. Admin-created customers land in the "guest" folder (related to Customer Component TODO-1 **C-01**).
- **Fix:** wrap the saves in a transaction, and default the last name with `?? ''`.

### B-15 — Order detail can crash on a deleted payment provider or a null document state — (plausible)
- **Where:** `Controller/OrderController.php:741` (`getPaymentProvider()->getIdentifier()`); `:561,594,747` pass a nullable state to `getStateInfo()` without a fallback.
- **Fix:** use `?->`, and `?? *_STATE_NEW` fallbacks.

## Carts, storage list and expiration

### B-16 — Login restores the last explicitly selected cart instead of the last used one (local change, regression vs. upstream)
- **Where:** `Pimcore/Repository/OrderRepository.php:102-118`; `StorageList/LastActivatedCartStorage.php:57-86`. Callers: `StorageListBundle/Controller/StorageMultiListController.php:81,163` set the timestamp. `StorageListBundle/Core/EventListener/SessionStoreStorageListSubscriber.php:83` passes only the store and does not set it. `StorageListBundle/Core/EventListener/StorageListBlamerListener.php:60-73` assigns a guest cart on login or registration without calling `setForContext()`.
- **Problem:** only the multi-cart "create" and "select" actions stamp `lastActivatedAt`. Implicitly created carts, guest carts assigned at login and admin-created carts keep `NULL`, which MySQL sorts last under `DESC`. Example: a customer once picked cart B in the multi-cart screen, then used guest cart C (assigned at login) for weeks. The next login on another device restores B. Upstream ordered by `creationDate` and would have returned C. **(plausible)**, traced through the code.
- **Fix:** stamp `lastActivatedAt` whenever a cart becomes the current cart (in the blamer, and in the session subscriber when the session cart id changes), or order by `COALESCE(lastActivatedAt, creationDate)`. Add `setLimit(1)` (TODO-3 **I-18**). The missing-column case is already tracked as CoreBundle TODO-1 **C-10** / TODO-2 **B-66**.

### B-17 — Order expiration writes a history note on every run for orders it cannot cancel
- **Where:** `Expiration/OrderExpiration.php:45-58`; `WorkflowBundle/Applier/StateMachineApplier.php:30-38` (the soft apply returns silently); `Pimcore/Repository/OrderRepository.php:202`.
- **Problem:** `initialized` orders are selected, but `cancel` is only allowed from `new` and `confirmed`. Every maintenance run skips them but still writes "Automatic Expiration Order Cancellation", so each stuck order gets one new note per cron run, indefinitely. The listing is unbounded.
- **Fix:** write the note only when `can()` is true or the transition was applied, drop `initialized` from the condition (see TODO-1 **C-08**), and page the listing.

### B-18 — Cart expiration: active carts deleted, `--user` does the opposite, and some inputs crash
- **Where:** `Pimcore/Repository/OrderRepository.php:131-168`; `Expiration/CartExpiration.php:37`; `Expiration/OrderAndCartExpiration.php:31-35`; `Command/CartExpireCommand.php:47-76`.
- **Problem:**
  - Age is measured by `creationDate`. When customer carts are enabled, a cart created long ago but edited yesterday is deleted.
  - The CLI's `--user` sets `params['user']`, but `CartExpiration` reads `params['customer']`. With the default config, `--user` deletes only **anonymous** carts.
  - `--anonymous` does not reset `customer`, so with `customer: true` in config it also deletes customer carts.
  - If both flags are false, the SQL ends in `AND ()`, a syntax error. The `$groupBind` code is dead.
  - `params` is a `variableNode`, so a partial project override passes `null` for `anonymous`, `customer`, `order.days` or `cart.days`. These go to `bool`/`int` parameters, a `TypeError` under `strict_types`, and the maintenance task fails.
  - The shortcut `'days'` cannot be used: `-days` is parsed as `-d -a -y -s`.
- **Fix:** measure age by `modificationDate` (or `COALESCE(lastActivatedAt, modificationDate)`), have `--user` set `customer=true, anonymous=false` and `--anonymous` the reverse, add `??` defaults, return early when no group is selected, drop or rename the `days` shortcut, and delete in batches.

### B-19 — Disabling expiration (`expiration.enabled: false`) breaks the container build
- **Where:** `Resources/config/services/commands.yml:8,15` use `%coreshop.storage_list.expiration.order.params%`. That parameter is only set inside `if ($list['expiration']['enabled'])` in `StorageListBundle/DependencyInjection/CoreShopStorageListExtension.php:317-345`.
- **Problem:** the obvious way out of TODO-1 **C-08** makes compilation fail with `ParameterNotFoundException`, and the application no longer boots.
- **Fix:** always set `.params` and `.days` (defaults `[]` and `0`) in the StorageList extension, or make the command arguments not depend on them.

### B-20 — `CartManager::persistCart()` has no lock and no transaction — (plausible)
- **Where:** `Manager/CartManager.php:49-116`.
- **Problem:** one persist is 2N+2 separate saves. Concurrent add-to-cart requests (double-click, two tabs, AJAX) overwrite each other's `items` relation, so items vanish from the cart and stay behind as orphan child objects. If the processor throws, items are left with stale totals. The missing `finally` in `VersionHelper` is already tracked as Notification Component TODO-2 **B-01**.
- **Fix:** take a Symfony Lock per cart around modify+persist, and wrap the saves in a transaction.

## State resolvers and workflows

### B-21 — `partially_shipped` / `partially_invoiced` is never reached once every item is on some document
- **Where:** `StateResolver/OrderShippingStateResolver.php:52-54,85-93`; `StateResolver/OrderInvoiceStateResolver.php:52-54,85-93`; `Component/Order/Processable/ProcessableOrderItems.php`.
- **Problem:** the partial branch requires `!isFullyProcessed()`, which means that some items are not yet on any document. With two shipments that together cover all items and only one of them shipped, the order stays `ready` until it jumps to `shipped`. Invoices behave the same way. Verified by tracing the code.
- **Fix:** treat the order as partial when `doneCount > 0 && (doneCount < notCancelledCount || !isFullyProcessed())`.

### B-22 — The shipping and invoice resolvers apply transitions without `can()`
- **Where:** `StateResolver/OrderShippingStateResolver.php:31,35,48-54`; `StateResolver/OrderInvoiceStateResolver.php:31,35,48-54`.
- **Problem:** the order is moved to `ready` (`request_shipment` / `request_invoice`) only in `OrderShipmentController.php:126` and `OrderInvoiceController.php:129`. A shipment or invoice created in code (ERP/API import through the transformer) leaves the order at `new`. The later `ship` / `complete` then throws `NotEnabledTransitionException` inside the document's after-callback, after the document marking has already been saved, so the order state is never updated. The payment and order resolvers do guard with `can()`. **(dependent)** on document creation outside the controllers.
- **Fix:** guard both calls with `can()`, and apply `request_*` first if needed. Better, move `request_*` into the transformer so every creation path runs it. Also resolve again on `coreshop_invoice.cancel` and `coreshop_shipment.cancel`.

### B-23 — Payment resolver: refunds are evaluated in isolation and return early
- **Where:** `StateResolver/OrderPaymentStateResolver.php:54-70`.
- **Problem:**
  - Order of 100, paid by payment A (100); a duplicate charge B (100) is refunded. Refunded 100 ≥ `paymentTotal` 100, so the order is marked **refunded** although A's 100 is still captured.
  - Split payment: A (60) and B (40) paid, B refunded, then C (40) paid. The resolver keeps returning `partially_refund`, so the order never returns to `paid`, is never confirmed or completed, and `sell_inventory` never runs.

  Both verified by tracing the code.
- **Fix:** compute `net = completed − refunded` and decide between `refund`, `partially_refund` and `pay` from that. Keep the currency consistent with Currency TODO-1 **C-01**.

### B-24 — A fully paid order can get stuck in `partially_authorized` — (plausible)
- **Where:** `Resources/config/pimcore/workflow/coreshop_order_payment.yml:38-40` (`pay.from: [awaiting_payment, partially_paid, authorized]`); `StateResolver/OrderPaymentStateResolver.php:29-34,62-64`.
- **Problem:** if a first payment is partially authorized and a second payment completes the full total, the resolver picks `pay`, but `can()` is false from `partially_authorized`. The transition is skipped silently. The order never becomes paid or complete, inventory is never sold, and TODO-1 **C-08** later cancels it.
- **Fix:** add `partially_authorized` to `pay.from` (and check `authorize`/`partially_pay` for the same gap). Add resolver tests for every reachable state.

## Admin grid, PDF and Twig

### B-25 — The `PriceFormatter` grid operator labels base-currency amounts with the shopper's currency
- **Where:** `Pimcore/GridColumnConfig/Operator/PriceFormatter.php:53-61`.
- **Problem:** `OrderInterface` extends `CurrencyAwareInterface`, so the first branch always uses `getCurrency()` (the shopper currency) and the `elseif` branch is dead. But `total`, `subtotal` and `paymentTotal` are stored in the base currency (`Component/Core/Order/Processor/CartCurrencyConversionProcessor.php:39-41`), so multi-currency stores show the wrong currency symbol. `format(int)` also receives the value unchecked, which is a `TypeError` for `null` or strings.
- **Fix:** use `getCurrency()` for `converted*` fields and `getBaseCurrency()` for the rest (or add an operator option), and skip non-int values.

### B-26 — The `OrderState` grid operator crashes on unexpected input
- **Where:** `Pimcore/GridColumnConfig/Operator/OrderState.php:36,41,56,79`.
- **Problem:**
  - `$config->highlightLabel` can be missing (the JS `getCopyNode()` does not set it), which gives a warning and then a `TypeError` on the `bool` property **(plausible)**.
  - `$result->def->name` is a fatal error when the child has no `def`.
  - A `null` state value is passed to `getStateInfo(string)`.
  - The translated label goes into HTML unescaped.
  - The constructor's `$context = null` default conflicts with the parent's `array $context = []`. `PriceFormatter` has the same issue.
- **Fix:** use `(bool) ($config->highlightLabel ?? false)`, null-safe access, `htmlspecialchars()` on the label, and `array $context = []`.

### B-27 — `AssetOrderDocumentPdfRenderer` re-renders needlessly and races with itself — (plausible)
- **Where:** `Renderer/AssetOrderDocumentPdfRenderer.php:40-77`.
- **Problem:**
  - It saves the asset, then the document. If the document save lands in a later second, the document looks newer than the asset, so the PDF is deleted and recreated on the next request.
  - Two concurrent renders both delete and recreate the asset at the same path, and the second fails with a duplicate-path error.
  - A render triggered by a GET also saves the document.
- **Fix:** compare against a stored content hash or render timestamp, update the asset in place, and take a Symfony Lock per document.

### B-28 — `OrderStateExtension` never shows the shipping state to customers
- **Where:** `Twig/OrderStateExtension.php:35-46`; used in `FrontendBundle/Resources/views/Customer/orders.html.twig:41`.
- **Problem:** the shipping fallback runs only when the order is `new` and the payment is `paid`. A paid order is always `confirmed` (`OrderStateResolver`), so that branch never runs, and customers see "Confirmed" instead of the shipping status.
- **Fix:** treat `new` and `confirmed` as in progress, and show the payment label, then the shipping label.

### B-29 — (reproduced) `WkHtmlToPdf::replaceUrls()` uses a character class instead of a look-ahead
- **Where:** `Renderer/Pdf/WkHtmlToPdf.php` (`replaceUrls()`, pattern `[^(http|mailto|...)]`), plus the `srcset` handling in the same method.
- **Problem:** `[^(http|…)]` excludes single characters, not schemes. A relative URL that starts with any of those letters (e.g. `images/logo.png`, `media/…`) is not rewritten, so the image is missing from the PDF. `/../../etc/passwd` becomes a raw filesystem path. The `srcset` handling prefixes the host dir even onto absolute URLs. Reproduced with a PHP script.
- **Fix:** use a negative look-ahead such as `(?!https?:|mailto:|data:|file:)`, resolve with `realpath()`, and require the result to be under `public/`. Skip absolute URLs in `srcset`.

## Forms, data mappers and validation

### B-30 — (reproduced) The quantity data mappers turn invalid or missing input into quantity 0, and admin order edit saves it
- **Where:**
  - `Form/DataMapper/BackendCartItemQuantityDataMapper.php:43-59` and `CartItemQuantityDataMapper.php:42-58`: `modify($viewData, (float) $form->getData())` runs unconditionally.
  - `Form/Type/OrderItemType.php:39-45` (`IntegerType`) and `OrderType.php:28-35`.
  - **(dependent)** `Component/Core/Order/Modifier/CartItemQuantityModifier.php:45-48`. With `allowZeroQuantity: true` (`CoreBundle/Resources/config/services/order.yml:79-82`) it also accepts negative values.
  - `Controller/OrderController.php:242-300` never calls `isValid()` (B-03).
- **Problem:** Symfony's default data mapper skips children that are not submitted, not synchronized, disabled or unmapped; these mappers do not, and `(float) null` is `0`.
  - `{"quantity":"2.5"}` calls `modify(0)`, because `IntegerType` cannot parse "2.5" and the form is not synchronized.
  - `{}` calls `modify(0)`.
  - `{"quantity":"-4"}` calls `modify(-4)`, and the form is valid.

  In admin order edit (`allow_zero_quantity`), a decimal or omitted quantity sets the line to 0, and negative quantities are stored as entered, so the order is persisted with wrong totals.
- **Fix:**
  - Map quantity only when the child is submitted, synchronized, enabled, mapped and `is_numeric`.
  - Use `NumberType`/`QuantityType` instead of `IntegerType`, and add `GreaterThanOrEqual(0)` (backend) or `Positive` (frontend).
  - Have the modifier reject values below 0 even when zero is allowed.

### B-31 — `validation/CartItem.yml` targets a class that no longer exists, so order items have no quantity constraints
- **Where:** `Resources/config/validation/CartItem.yml:1-6` targets `CoreShop\Component\Order\Model\CartItem`, which was removed in the 3.0 refactoring. The `Valid` cascades come from `validation/Order.yml:5-7`, `Form/Type/AddToCartType.php:30-32` and `AddMultipleToCartType.php:31-35`.
- **Problem:** `NotBlank`, `numeric` and `GreaterThan(0)` on `quantity` never apply. Symfony skips mappings for missing classes silently. In the cart, 0 or -1 is "valid" and then silently ignored by the modifier, with no error message for the user. In admin creation and edit, negative quantities pass (B-30).
- **Fix:** retarget the mapping to `CoreShop\Component\Order\Model\OrderItem` and rename the file. Admin edit allows 0, so give it its own group (for example `coreshop_backend_edit`) with `GreaterThanOrEqual(0)`. Add a test that the metadata exists.

### B-32 — (reproduced) The voucher condition rejects every voucher when "max usage per user" is 0
- **Where:** `Form/Type/Rule/Condition/VoucherConfigurationType.php:37-42` (`NumberType`); **(dependent)** `Component/Order/Cart/Rule/Condition/VoucherConditionChecker.php:48,65,75` (`$maxUsagePerUser !== 0 && $uses >= $maxUsagePerUser`).
- **Problem:** `NumberType` always yields a float, so "0" becomes `0.0`. The rule configuration is stored in a serialized Doctrine `array` column, so the float survives. `0.0 !== 0` is true and `uses >= 0.0` always holds, so the voucher is invalid for every customer. `maxUsagePerCode` uses a loose `!=` and works.
- **Fix:** use `IntegerType` with `PositiveOrZero` for both fields. In the checker, compare `(int) $max > 0` so existing stored configurations keep working.

### B-33 — Choosing a unit in add-to-cart or in the cart computes `defaultUnitQuantity` with the old unit — (dependent)
- **Where:** `Form/DataMapper/CartItemQuantityDataMapper.php:44-58`: the comment says "First map all the other fields, then map quantity", but the code calls `modify()` first. The `unitDefinition` field comes from `CoreBundle/Form/Extension/CartItemTypeExtension.php`. Also `Component/Core/Order/Modifier/CartItemQuantityModifier.php:43-66`, including its early return when the quantity is unchanged.
- **Problem:** a new add-to-cart item has no unit yet, so `modify()` sets `defaultUnitQuantity = quantity`, and only afterwards is `unitDefinition` (for example box = 12) mapped. Two boxes give `defaultUnitQuantity` 2 instead of 24. Changing only the unit in the cart hits the early return, so `defaultUnitQuantity` is never updated. The stock and min/max validators and `OrderInventoryOperator` all use `defaultUnitQuantity`, so stock is under-checked and under-reserved by the conversion factor. Traced through the code, not tested end to end.
- **Fix:** map the other fields first, then the quantity. In the modifier, always recompute `defaultUnitQuantity`.

### B-34 — `CartPriceRuleValidator` stops early, treats deleted voucher codes as valid, and can throw a `TypeError`
- **Where:** `Validator/Constraints/CartPriceRuleValidator.php:54-79`.
- **Problem:**
  - `:55-57` and `:61-64` `return` where they should `continue`. One non-`PriceRuleItem` entry, or one item whose rule was deleted, skips validation of all remaining voucher rules.
  - `:70`: `findByCode(string)` receives a `?string` voucher code, which is a `TypeError` under `strict_types` **(plausible)** for admin- or API-created items.
  - `:72`: `if ($voucherCode && …)` means a deleted voucher code counts as valid.
  - The message is hard-coded English.
- **Fix:** use `continue`, add a violation when the rule or voucher code is missing or unknown, and use a translation key for the message.

### B-35 — Cart price rules and voucher codes have no validation metadata, so empty or too-long input ends in 500s
- **Where:** `Form/Type/CartPriceRuleType.php:32-44` (no constraints; `priority` is a `NumberType` feeding `setPriority(int)`); `VoucherType.php:28-33`; `VoucherGeneratorType.php:31-46`; `Resources/config/validation/CartPriceRuleVoucherGenerator.yml:6-13`. There is no `validation/CartPriceRule.yml` or `CartPriceRuleVoucherCode.yml` (compare ShippingRule.yml and ProductPriceRule.yml). ORM mappings: `name` and `priority` are NOT NULL; `code` is NOT NULL, length 190, unique.
- **Problem:**
  - An empty name hits the NOT NULL column and returns a 500.
  - An empty priority calls `setPriority(null)`, which `PropertyPathAccessor` does not catch, so another 500 **(plausible)** (same mechanism as PaymentBundle TODO-2 **B-10**).
  - An empty voucher code, a duplicate one, or one longer than 190 characters is a database error and a 500.
  - The generator's `prefix` and `suffix` have no `Length`, so generated codes can exceed 190 characters and the whole batch flush fails.
  - `cartPriceRule` has no `NotNull`, so orphan codes can be generated.
  - `amount` has only `Range(min: 1)`. A huge amount builds every entity in memory with one query per code, which exhausts memory or the request time.
- **Fix:**
  - Add `CartPriceRule.yml`: `name` with `NotBlank` and `Length`, `priority` with `NotNull` and integer.
  - Add `CartPriceRuleVoucherCode.yml`: `code` with `NotBlank` and `Length(max: 190)`, a `UniqueEntity` on `code`, `cartPriceRule` with `NotNull`.
  - Generator: `cartPriceRule` `NotNull`, a maximum `amount`, `Length` on prefix and suffix, and a total-length check.
  - Make `priority` an `IntegerType` with `empty_data`.

### B-36 — Admin cart creation and edit: custom price and discount are unbounded, use a hard-coded ×100, and `data => 0` wipes stored values
- **Where:** `Form/Type/CartCreationCartItemType.php:42-78`, used by `CartCreationType` and, through `EditCartItemsCollectionType`, by `EditCartType` (`Controller/OrderEditController.php:63`). **(dependent)** `Component/Core/Order/Processor/CartItemsProcessor.php:107-118`. JS `Resources/public/pimcore/js/order/create/step/products.js:57,69`.
- **Problem:**
  - A discount of 150 % gives a negative item price, and a negative custom price is used as entered.
  - `customItemPrice` is raw minor units (`IntegerType`). The JS converts with a hard-coded `*100` / `/100` instead of the configured `decimal_factor`, so with a factor of 10 000 prices are off by 100x.
  - `'data' => 0` overrides the model value and the `PRE_SUBMIT` listener forces 0, so an edit-items request without these fields resets custom price and discount to 0 **(plausible)**; no bundled JS calls that route today.
  - `product` and `store`/`currency`/`customer` can be blank **(plausible)**, which crashes later.
- **Fix:** replace `data` with `empty_data: '0'` and remove or limit the listener. Give discount `Range(0, 100)` and price `PositiveOrZero`, or better a `MoneyType`, and drop the JS `*100`. Add `NotNull`/`NotBlank` on `product`, `store`, `currency` and `customer`.

### B-37 — (reproduced) `AddMultipleToCartType` with `allow_add: true` crashes on any unexpected entry key
- **Where:** `Form/Type/AddMultipleToCartType.php:29-37`; `DTO/AddToCart.php:25-29`; consumer `Controller/OrderEditController.php:108-147`.
- **Problem:** a submitted key that was not pre-built makes the collection create a new entry with `new AddToCart()`, which fails with `ArgumentCountError: Too few arguments to function AddToCart::__construct(), 0 passed`, a 500. The nested `csrf_protection: true` has no effect on embedded forms.
- **Fix:** set `allow_add: false`, or give the entry an `empty_data` closure that uses `AddToCartFactoryInterface`. Remove the nested `csrf_protection` option.

### B-38 — Attribute-based registration is incomplete — (dependent, partly already tracked)
- **Where:** `Attribute/AsCartPriceRule{ActionProcessor,ConditionChecker}.php:35`; `Attribute/AsPurchasable*Calculator.php`; `DependencyInjection/CoreShopOrderExtension.php:118-181`; `Component/Registry/Autoconfiguration.php`.
- **Problem:**
  - `formType` is emitted as `formType`, but `RegisterRegistryTypePass` reads `form-type`, so an attributed cart rule element has no config form and saving it in the admin fails. Already tracked as PaymentBundle TODO-2 **B-03** and NotificationBundle TODO-1 **C-01**. The global autoconfigure flag not reaching `core_shop_order` is CoreBundle TODO-2 **B-37**.
  - New: all six purchasable calculator registries are prioritized, and the composite calculator is last-wins. The attributes have no `priority` argument, so an attributed calculator always gets 1000 and cannot be ordered relative to the built-in `product` (20) and `default` (10) calculators.
  - There are no attributes or autoconfiguration for cart-item price rule conditions and actions (`CartItemPriceRuleActionProcessorInterface`, `OrderItemActionConditionCheckerInterface`).
- **Fix:** add `priority` to the six purchasable attributes, add `AsCartItemPriceRule*` attributes plus autoconfiguration, and apply the central `form-type` fix.

### B-39 — Cart rule amount actions: the currency transformer cannot transform the stored id — (already tracked)
- **Where:** `Form/Type/Rule/Action/DiscountAmountConfigurationType.php:68-83`, `SurchargeAmountConfigurationType.php:61-76`.
- **Problem:** both closures map currency to id, so the stored int becomes null on the way back, and the processor then fails `Assert::isInstanceOf`. Already tracked as CoreBundle TODO-2 **B-32**, but these two OrderBundle copies are not listed there.
- **Fix:** add both files to CoreBundle B-32 and use the shared transformer proposed there.

### B-40 — Form block-prefix collisions
- **Where:** `Form/Type/CartItemType.php:57-60` (frontend) and `CartCreationCartItemType.php:91-94` (admin) both use `coreshop_cart_item`. The condition-choice prefixes are misnamed `…_action_condition_choice` (`CartPriceRuleConditionChoiceType.php:27`, `CartItemPriceRuleConditionChoiceType.php:27`).
- **Problem:** form theme blocks bleed between the storefront form and the admin form. This is the same class of issue as CoreBundle TODO-2 **B-35** and CustomerBundle TODO-2 **B-15**.
- **Fix:** rename them to `coreshop_cart_creation_cart_item`, `coreshop_cart_price_rule_condition_choice` and the cart-item equivalent, with an UPGRADE note.

### B-41 — Voucher generator validation messages: wrong translation domain, and two texts swapped — (plausible)
- **Where:** `Resources/config/validation/CartPriceRuleVoucherGenerator.yml:13-17`; `Validator/Constraints/VoucherAmount.php:25`. The keys exist only in `FrontendBundle/Resources/translations/messages.en.yaml:232-236`.
- **Problem:** the keys are not in the `validators` domain, so the admin shows raw keys. The `amount.not_blank` and `amount.min` texts are swapped.
- **Fix:** move the keys into OrderBundle's `validators` translations and swap the two texts.

## Configuration, serializer and Doctrine

### B-42 — (reproduced) The `Adjustment` serializer emits `label` and `typeIdentifier` as booleans
- **Where:** `Resources/config/serializer/Model.Adjustment.yml:30-39` (`type: boolean`); `:3` has a copy-pasted `xml_root_name: cart_price_rule`.
- **Problem:** JMS turns every label and type identifier into `true` (reproduced with the vendored JMS). The wrong values appear in `adjustments` / `convertedAdjustments` of `OrderController::getDetails()` (`toArray($order)`), which `SALE_DETAIL_PREPARE` listeners and API consumers receive.
- **Fix:** use `type: string` and `xml_root_name: adjustment`.

### B-43 — Typo in the order serializer: `convertedSshippingNet`
- **Where:** `Resources/config/serializer/Model.Order.yml:169`.
- **Problem:** consumers that look for `convertedShippingNet` (next to `convertedShippingGross`) get nothing.
- **Fix:** rename it to `convertedShippingNet` and mention it in UPGRADE.

### B-44 — Deleting a used voucher code, or its price rule, fails with a foreign-key error — (plausible)
- **Where:** `Resources/config/doctrine/model/CartPriceRuleVoucherCodeCustomer.orm.xml:22-25` (`voucherCodeId` has no `on-delete` and there is no inverse cascade); `CartPriceRule.orm.xml:29-33`; `Controller/CartPriceRuleController.php:205-224`.
- **Problem:** once `VoucherModifier::increment()` has written a per-customer usage row, `deleteVoucherCode` and deleting the rule (which cascades to its codes) fail with a foreign-key violation, an HTTP 500. `cartPriceRuleId` has no `on-delete` either.
- **Fix:** add `on-delete="CASCADE"` (with a migration), or map the inverse one-to-many with `orphan-removal`.

### B-45 — OrderBundle cannot be installed or booted without CoreBundle — (dependent)
- **Where:**
  - `Component/Order/Model/OrderInterface.php:191-193` (`getComment()`/`setComment()`) and `Resources/install/pimcore/classes/CoreShopOrder.json`, which has no `comment` field.
  - `DependencyInjection/Configuration.php:202-208`: `pimcore.order.path` has no `addDefaultsIfNotSet()`.
  - `Resources/config/services/cart.yml:34` references `coreshop.cart_item.quantity_modifier.backend`, which is defined only in `CoreBundle/Resources/config/services/order.yml:79`.
  - `Resources/config/serializer/Model.Order.yml:35-43,88-92,158-167`.
  - `Resources/config/pimcore/config.yml:44-58`.
- **Problem:**
  - Without CoreBundle's class JSON, the generated `DataObject\CoreShopOrder` lacks `getComment()`/`setComment()` and is effectively abstract, so installation fails fatally. Checked with a reflection script.
  - The `orders`/`quotes`/`carts` folder defaults are never applied, so `coreshop.folder.order|cart|quote` are undefined and `OrderController.php:188` throws. Verified with the Config processor.
  - The container does not compile, because the backend quantity modifier service is missing.
  - The serializer calls `getPaymentProvider`, `getPaymentSettings`, `getShipping`, `getShippingTax` and `getComment`, which `Component\Order\Model\Order` does not have.
  - `config.yml` hard-codes FrontendBundle routes and templates.
- **Fix:** add `comment` to the OrderBundle class JSON, add `addDefaultsIfNotSet()` to `path`, define or alias the backend quantity modifier in OrderBundle, and move the Core-only serializer properties and frontend config to CoreBundle. Or declare the CoreBundle dependency explicitly in `composer.json` and the README.

### B-46 — `CartCollector::reset()` leaves the profiler collector in a broken state — (plausible)
- **Where:** `Collector/CartCollector.php:56-59` (`reset()` sets `data = []`), the typed getters at `:25-38`, and `Resources/views/Collector/cart.html.twig`.
- **Problem:** in long-running workers and with the functional-test client, the next admin request (or a request that throws before `locale` is set) leaves keys missing, and rendering the toolbar then fails with "Undefined array key" or a `TypeError`. The collector also stores the whole Order object graph in the profile.
- **Fix:** have `reset()` restore the constructor defaults, and store a scalar summary (or use `cloneVar()`).
