# OrderBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (~10k lines, 137 files: `CoreShopOrderBundle.php`, `Events.php`, `Attribute/`, `Collector/`, `Command/`, `Controller/`, `CoreExtension/`, `DependencyInjection/`, `Doctrine/`, `DTO/`, `Event/`, `EventListener/`, `Expiration/`, `Factory/`, `Form/`, `Manager/`, `Pimcore/`, `Renderer/`, `StateResolver/`, `StorageList/`, `Twig/`, `Validator/`), `composer.json`, all of `Resources/config/**` (`services.yml`, all 20 `services/*.yml`, `pimcore/{config,admin,routing,workflow}.yml`, all 9 `pimcore/workflow/*.yml`, `serializer/*.yml`, `doctrine/model/*.orm.xml`, `validation/*.yml`), `Resources/install/**` (class definitions, field collections, `grid-config.yml`), and the admin JS under `Resources/public/pimcore/js/` (request-parameter contracts only). Call paths were followed into `Component/Order`, `Component/Core`, `ResourceBundle`, `WorkflowBundle`, `StorageListBundle`, `CoreBundle`, `PaymentBundle` and Pimcore / admin-ui-classic.

Reference for API checks: `vendor/` (`symfony/*` 7.4.x, `doctrine/orm` 2.20, `doctrine/dbal` 3.x, `jms/serializer-bundle` 5.x, `twig/twig` 3.x, `nesbot/carbon` 3.15) and the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml`. Compared with the vendored `six-it/core-shop` copy, this tree changes `Controller/OrderInvoiceController.php`, `Controller/OrderShipmentController.php`, `Pimcore/Repository/OrderRepository.php`, `Resources/config/services/cart.yml`, `Resources/install/pimcore/classes/CoreShopOrder.json` (+ `lastActivatedAt`) and adds `StorageList/LastActivatedCartStorage.php`. These changes were reviewed with extra care.

"Critical" here means: a security issue, money charged or stored wrongly, data loss or data written wrongly, or an error that takes down every request, a whole area, or the install or upgrade path once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(reproduced)** were confirmed with a script against the vendored libraries.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers. `php -l` (PHP 8.4.27) passes on every PHP file in the bundle. Every service definition was checked against its class constructor and every referenced service id resolves, and the default configuration compiles. Forward-compatibility and deprecation items are in TODO-3 (**I-01** to **I-07**). The one configuration path that does break the container (`expiration.enabled: false`) is TODO-2 **B-19**.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security / admin authorization

### C-01 — Order admin endpoints have no effective permission check (missing authorization + IDOR) — (dependent)
- **Where:**
  - Root cause: `ResourceBundle/Controller/PimcoreController.php:45-68`. `isGrantedOr403()` checks only when `getPermission()` is non-empty, and the base returns `''`. In this bundle only `Controller/OrderCreationController.php:332-335` overrides it.
  - No check at all: `Controller/OrderController.php:70-176` (`getStatesAction`, `updateOrderStateAction`), `OrderPaymentController.php:43-141`, `OrderInvoiceController.php:50-197`, `OrderShipmentController.php:50-193`, `OrderCommentController.php:36-100`, `CustomerCreationController.php:40-116`, `AddressCreationController.php:37-92`.
  - No-op `isGrantedOr403()` calls: `OrderController.php:180,206,233,329`, `OrderEditController.php:52,97,165`.
  - `OrderCreationController.php:58`: the check is commented out in `getCustomerDetailsAction`, and `:73-104` (`salePreviewAction`) has none.
  - CoreBundle's `OrderController` / `OrderCreationController` subclasses do not override `getPermission()` either.
  - Permissions declared at `DependencyInjection/Configuration.php:428-442` (`order_list`, `order_detail`, `order_create`, `quote_*`, `cart_list`, `cart_detail`, `cart_create`) are otherwise used only to hide menu entries in `CoreBundle/Menu/MainMenuBuilder.php`. `cart_detail` is not used anywhere.
- **Problem:** any authenticated Pimcore backend user can do the following by numeric id, without any CoreShop permission and without object-workspace rights:
  - read any order (customer PII, addresses, payment details, mail notes) and any customer;
  - cancel or confirm orders and apply payment, invoice and shipment transitions;
  - add payments and complete or refund them;
  - create invoices and shipments;
  - edit order items and delete notes;
  - create customers and addresses.

  CoreBundle TODO-1 **C-02** covers the same class of gap but does not list these controllers, and the `PimcoreController` root cause is not tracked anywhere.
- **Fix:**
  - Give every action a required permission, through an override of `getPermission()`, a per-action `checkPermission()` or `#[IsGranted]`:
    - read actions: `order_detail` / `quote_detail` / `cart_detail`, chosen from the order's `saleState`;
    - state, payment, invoice, shipment, edit and comment actions: `order_detail`;
    - creation, customer and address actions: `order_create`.
  - Call it first in every action. Also check `$order->isAllowed('view'|'save')` (Pimcore object ACL).
  - Fix the wrong exception class at the same time (TODO-2 **B-11**). Remove `cart_detail` or enforce it.

### C-02 — Voucher-code actions skip the configured `cart_price_rule` permission
- **Where:** `Controller/CartPriceRuleController.php:38-224`: all custom actions (get `:77`, create `:104`, generate `:134`, export `:158`, delete `:205`). Routes are at `Resources/config/pimcore/routing.yml:1-46`, and the permission is configured at `DependencyInjection/Configuration.php:119`.
- **Problem:** the inherited CRUD actions call `isGrantedOr403()`, but none of the custom voucher actions does. Any back-office user can generate or create discount voucher codes for any rule, including a 100 % rule, export all codes (GET is allowed), and delete codes.
- **Fix:** call `$this->isGrantedOr403()` first in every custom action, and add a 403 functional test.

### C-03 — State-changing admin routes accept GET, so Pimcore's CSRF check is skipped
- **Where:**
  - `Resources/config/pimcore/routing.yml`: `:61` `update_order_state`, `:87` `payment_add`, `:93` `payment_update_state`, `:112` `invoice_update_state`, `:131` `comments_add`, `:137` `comments_delete`, `:156` `shipment_update_state`, `:200` `order_remove`, plus invoice/shipment create, order-creation create, all `order-edit/*` routes and customer/address create. Only `coreshop_admin_order_update` (`:73-78`) declares `methods:`.
  - Controller actions with no method check of their own: `OrderController.php:147-176`, `OrderPaymentController.php:43-141`, `OrderInvoiceController.php:156-175`, `OrderShipmentController.php:153-171`, `OrderCommentController.php:61-100`, `OrderEditController.php:158-195`.
  - Vendor: `admin-ui-classic-bundle/src/EventListener/CsrfProtectionListener.php:45-70` returns early for GET/HEAD (`isMethodCacheable()`). `ResourceBundle/Controller/AdminController.php:48-63` (`getParameterFromRequest()`) reads attributes, then the query string, then the body.
- **Problem:** these actions change state on any HTTP method and take their parameters from the query string. If a logged-in admin opens a third-party page containing `<img src="…/admin/coreshop/order-payment/update-payment-state?id=42&transition=complete">`, payment 42 is completed, and that cascades into order `pay`, inventory `sell` and `confirm`. The same trick can cancel orders (which also voids payments and releases inventory), add payments, delete notes and remove order items. The session cookie is `SameSite=Lax`, which is still sent on a top-level GET, and the ids are sequential. CoreBundle TODO-1 **C-03** covers this pattern for configuration only.
- **Fix:**
  - Add `methods: [POST]` to every write route, and `methods: [GET]` (or `[GET, POST]`) to the read routes.
  - Read input only from the request body, or use `#[MapRequestPayload]`.
  - All bundled admin JS already sends POST: `Ext.Ajax` with `params` defaults to POST, and the other calls set `method: 'post'`. The restriction does not break the UI.

### C-04 — Stored XSS in order comments
- **Where:** `Controller/OrderCommentController.php:63-79` (`setDescription(nl2br($comment))`) and `:49-55` (returns `text` and `userName` raw); JS `Resources/public/pimcore/js/order/detail/blocks/comments.js` `addCommentToList` (`html: comment.text`, and `userName` concatenated into `title`).
- **Problem:** the comment is stored with only `nl2br()` and rendered as HTML. An `<img onerror>` payload runs in the browser of every admin who opens the order and can read the Pimcore CSRF token, so a low-privileged backend user can escalate to admin. With `submitAsEmail`, the payload plausibly also reaches the customer mail **(plausible)**.
- **Fix:** escape on output (`Ext.util.Format.htmlEncode()`, then turn newlines into `<br>`), or apply `htmlspecialchars()` before `nl2br()` on input. Encode `userName` too. Plan a cleanup of already stored notes.

### C-05 — The comment delete endpoint deletes any Pimcore note by id
- **Where:** `Controller/OrderCommentController.php:87-100`.
- **Problem:** it calls `$note->getDao()->delete()`, which is an `@internal` API, without checking the note type, the order or ownership. A caller can delete workflow state history, backend-update notes, email notes, and notes on assets or documents, which destroys the order audit trail. It also bypasses `NoteServiceInterface::deleteNote()` and its event (`Component/Pimcore/DataObject/NoteService.php:147-165`). It answers `success: true` even for a GET without a CSRF token (C-03).
- **Fix:** require the order id, and check `type === Notes::NOTE_ORDER_COMMENT`, `cid === $orderId` and `ctype === 'object'`. Delete through `NoteServiceInterface::deleteNote()`. Restrict the route to POST.

## Money / order data

### C-06 — Admin invoice and shipment creation accept any items and any quantities — (dependent)
- **Where:**
  - Controllers: `Controller/OrderInvoiceController.php:97-154`, `OrderShipmentController.php:97-151`.
  - Forms: `Form/Type/OrderInvoiceCreationItemsType.php:28-32` and `OrderShipmentCreationItemsType.php:28-32` (`orderItemId` and `quantity` are plain `NumberType`s with no constraints); `OrderInvoiceCreationType.php:30-42`, `OrderShipmentCreationType.php:31-44`.
  - Transformers (dependent): `Component/Order/Transformer/OrderToInvoiceTransformer.php:92-109`, `OrderToShipmentTransformer.php:~78-92`, `OrderItemToInvoiceItemTransformer.php:64-70` call `orderItemRepository->find($item['orderItemId'])` and use `(int) $quantity`.
- **Problem:** the processable check (`ProcessableInterface::getProcessableItems()`) runs only in the `get*AbleItems` actions that feed the UI. The create actions never check any of the following:
  - that the order is processable;
  - that `orderItemId` belongs to this order, so items of other orders can be put on this order's document;
  - that `0 < qty <= remaining`, so posting 50 for an item ordered twice, or -3, creates an invoice at `itemPrice * qty`.

  The `(int)` cast also truncates fractions: a 2.5 kg line becomes 2 and can never be fully invoiced, and 0.5 becomes an empty line. The result is negative invoice totals, over-invoicing and over-shipping, and the invoice and shipping state resolvers then work from these wrong quantities.
- **Fix:**
  - Require `isProcessable($order)` in the create actions.
  - Item form: `orderItemId` as `IntegerType` with `NotBlank` and `Positive`; `quantity` with `NotBlank` and `Positive`.
  - Pass `order` as a form option and add a `Callback` constraint that rejects unknown item ids and quantities above `getProcessableItems($order)[$id]['quantity']`.
  - In the transformers, assert that the item belongs to the order and that `qty > 0`, and drop the silent `(int)` cast.

### C-07 — Manual admin payments are stored in a different unit from `paymentTotal`, so one payment can mark the order fully paid — (dependent)
- **Where:** `Controller/OrderPaymentController.php:69` (`amount * decimal_factor`) and `:93` (compared with `getTotal()`); `Controller/OrderController.php:633-639` (hard-coded `factor: 100`). Dependent: `StateResolver/OrderPaymentStateResolver.php:56-111` compares against `getPaymentTotal()`, and `Component/Core/Order/Processor/CartPaymentProcessor.php:46` always computes `paymentTotal` in ×100 units.
- **Problem:** with `money_decimal_precision: 4` (factor 10 000), an admin payment of "1.00" is stored as `10000`. That equals the `paymentTotal` of a 100.00 order, so the resolver applies **pay** and the order counts as fully paid, which cascades to inventory and order confirmation. The overpayment guard also compares against `getTotal()`, not `getPaymentTotal()`, and these differ by the payment fee (PaymentBundle TODO-1 **C-03**). The unit split itself is tracked as PaymentBundle TODO-2 **B-05**, which does not list this controller.
- **Fix:** store the amount in the `paymentTotal` unit chosen under PaymentBundle B-05, compare against `getPaymentTotal()`, and use a `MoneyType` form with the store currency. The detail view should send the real factor, not `100`.

### C-08 — The default maintenance task cancels authorized, partially paid, partially refunded and already-shipped orders after 20 days
- **Where:**
  - `Pimcore/Repository/OrderRepository.php:197-224`: the condition at `:202` is `saleState = order AND orderState IN (new, confirmed, initialized) AND paymentState <> 'paid'`.
  - `Expiration/OrderExpiration.php:37-58` and `Expiration/OrderAndCartExpiration.php:31-35`.
  - `Resources/config/pimcore/config.yml:61-72` (`expiration.enabled: true`, `order.days: 20`).
  - Registered as a `pimcore.maintenance.task` in `StorageListBundle/DependencyInjection/CoreShopStorageListExtension.php:317-345`.
  - Cascades: `Resources/config/pimcore/workflow/coreshop_order.yml:47-74` and `CoreBundle/Resources/config/pimcore/workflow.yml:10-13` (`cancel_inventory`).
- **Problem:** `OrderStateResolver` confirms an order as soon as its payment is authorized, partially authorized or partially paid, and a partial refund leaves the order confirmed. Every `pimcore:maintenance` run therefore silently cancels these orders once they are older than 20 days. The only trace is a note.
  - **Authorized orders** (capture on shipment, B2B lead times): the cascade cancels the payment, which voids the authorization.
  - **Partially paid orders:** `coreshop_order_payment.cancel` is not allowed from `partially_paid` (`coreshop_order_payment.yml:35-37`), so the soft apply skips the payment. The order ends up cancelled while the money stays captured.
  - **Partially refunded orders.**
  - **Pay-later orders** (`new` + `awaiting_payment`) that are already shipped or invoiced.

  In all cases the stock hold is released and voucher usage is decremented. InventoryBundle TODO-2 **B-01** (inventory cancel logic) makes this worse. "Expire abandoned orders" plausibly meant unpaid orders only.
- **Fix:**
  - Expire only `orderState = new` orders with `paymentState IN (new, awaiting_payment)` and shipping and invoice states `new`/`ready`. Never select `confirmed`, and drop `initialized` (TODO-2 **B-17**).
  - Skip any order that has a payment in `processing`, `authorized` or `completed`.
  - Consider making it opt-in (`order.days: 0`). Page the listing and log each cancelled order id.
  - Add an UPGRADE note: shops running the default today have probably lost orders this way.

### C-09 — The wkhtmltopdf command line is built by string concatenation (shell injection sink) — (dependent)
- **Where:** `Renderer/Pdf/WkHtmlToPdf.php:160-197` (`Process::fromShellCommandline()`); `Renderer/OrderDocumentPdfRenderer.php:55-68` (`['options' => [$event->getOptions()]]`); `CoreBundle/EventListener/Order/OrderDocumentWkhtmlPrintOptionsListener.php:35`. This is the default path, because `use_pimcore_pdf_rendering` defaults to `false` (`DependencyInjection/Configuration.php:79`).
- **Problem:** the store settings `system.invoice.wkhtml` / `system.shipment.wkhtml` are pasted unescaped into a shell command line, and so are the temp file, cache dir and binary paths. Whoever can save that setting gets shell execution (`; curl … | sh`), and `--enable-local-file-access`, `--allow` or `--post-file` can be used to read local files into the PDF. Paths containing spaces also break rendering. The root cause (no permission check on the configuration endpoint) is tracked as ConfigurationBundle TODO-1 **C-01**, which asks for the sink fix to be tracked here.
- **Fix:**
  - Use `new Process([$binary, ...$args, $in, $out])` with an argv array.
  - Tokenize the option string against an allowlist (margins, page size, orientation, dpi, zoom, header/footer spacing, …) and reject `--enable-local-file-access`, `--allow`, `--run-script`, `--post`, `--post-file`, `--cookie-jar` and similar.
  - Pass the xvfb `--server-args` as one argv element.
  - Consider defaulting `use_pimcore_pdf_rendering` to `true` when web-to-print is installed (TODO-3 **I-07**).
