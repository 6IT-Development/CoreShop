# OrderBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items (Symfony 8, Doctrine ORM 3, DBAL 4). None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as sub-bullets.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(reproduced)** were confirmed with a script against the vendored libraries.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — (reproduced) Custom constraints use the deprecated options-array constructor
- **Where:** `Validator/Constraints/CartPriceRule.php`, `Validator/Constraints/VoucherAmount.php`; YAML usage in `Resources/config/validation/{Order,CartPriceRuleVoucherGenerator}.yml`.
- **Problem:** loading the mappings triggers *"Since symfony/validator 7.3: Using constraints not supporting named arguments is deprecated"* and *"Since symfony/validator 7.4: Support for evaluating options in the base Constraint class is deprecated"*. Both break in Symfony 8. This is the same pattern as CoreBundle TODO-3 **I-01**.
- **Fix:** add `#[\Attribute(\Attribute::TARGET_CLASS)]` and a `#[HasNamedArguments]` constructor (`public string $message = '…'`, `?array $groups = null`, `mixed $payload = null`), call `parent::__construct(null, $groups, $payload)`, and drop the string aliases. Add `#[\Override]` to `getTargets()`.

### I-02 — Forms: Symfony 8 forward compatibility
- **Where:** `Form/Type/CartListType.php` (`configureOptions()` has no `: void`), the rule configuration types' `buildForm(…, array $options = [])`, `Form/Type/OrderItemType.php` and `CartCreationCartItemType.php` (`->add(...)->setDataMapper(...)` chain).
- **Problem:**
  - The missing `: void` return type becomes a fatal error in Symfony 8, where `FormTypeInterface` declares native return types.
  - The `$options = []` defaults do not match the interface.
  - The `->add()->setDataMapper()` chain reads as if the mapper belonged to the child.
- **Fix:** add native return types and remove the defaults. Put `setDataMapper()` in its own statement. Mark the types `final`, and use `#[\Override]` and `private readonly` dependencies.

### I-03 — Controllers: modernise request handling and dependency access
- **Where:** all of `Controller/`.
- **Problem / Fix:**
  - **Request input.** `getParameterFromRequest()` (ResourceBundle) copies the attributes → query → body lookup of `Request::get()`, which Symfony 7.4 deprecates. Read from the specific bag, or use `#[MapQueryParameter]` / `#[MapRequestPayload]` DTOs. This also fixes most of TODO-2 **B-10** and supports TODO-1 **C-03**.
  - **Dependencies.** Replace 30+ `$this->container->get()` locator getters with constructor or action injection, using `private readonly` promoted properties.
  - **Class modifiers and attributes.** Mark controllers that are not extended `final`. Add `#[\Override]` to overridden methods and use `#[IsGranted]` for permissions (TODO-1 **C-01**).
  - **Constants.** Use the workflow `IDENTIFIER` constants instead of literals such as `'coreshop_payment'` and `'coreshop_order'`.
  - **Duplicated helpers.** `getCurrency()`, `getStore()` and `getDataForObject()` exist in both `OrderController` and `OrderCreationController`; move them to a trait or service.
  - **Smaller items.**
    - Rewrite `parsePaymentDetailLine()` with `match`.
    - Index workflows by name once.
    - Pass `true` (strict) to `in_array()`.
    - Use `ClockInterface` instead of `Carbon::now()`.
    - Reuse the payment-number generator instead of duplicating `OrderPaymentProvider.php:41-47`.

### I-04 — Commands: use `#[AsCommand]` and typed, readonly dependencies
- **Where:** `Command/CartExpireCommand.php`, `Command/OrderExpireCommand.php`, `Resources/config/services/commands.yml`.
- **Problem:** the commands use `setName()` plus the `command:` tag attribute, return int literals, and type-hint the concrete `OrderExpiration`/`CartExpiration`. `Expiration/OrderExpiration.php:31` type-hints the concrete `StateMachineApplier` but is wired with the interface, so a decorator would cause a `TypeError`.
- **Fix:** use `#[AsCommand(name: …, description: …)]`, `private readonly` promoted interface-typed properties, and `Command::SUCCESS`/`FAILURE`. Same advice as CoreBundle TODO-3.

### I-05 — Service definitions: reduce `public: true`, and use attributes for Twig
- **Where:** `Resources/config/services.yml`, `services/*.yml`, `Twig/*.php`.
- **Problem / Fix:**
  - `_defaults: public: true` is set everywhere. Only services fetched through `\Pimcore::getContainer()`, controller sub-requests or workflow `service()` expressions need to be public. Same advice as AddressBundle and CurrencyBundle TODO-3.
  - Use the Twig 3.21+ `#[AsTwigFilter]` / `#[AsTwigFunction]` attributes instead of `getFilters()` / `getFunctions()`.
  - Add a comment explaining why `CoreExtension/CartPriceRule` uses a static container lookup.
  - `shared: false` on the expiration services serves no purpose.

### I-06 — Doctrine mapping: ORM 3 readiness
- **Where:** `Resources/config/doctrine/model/*.orm.xml`.
- **Problem:** `cartPriceRuleId` has no `nullable`/`on-delete` setting (see TODO-2 **B-44**). The `mapped-superclass` with a one-to-many association relies on CoreShop turning the model into an entity at load time, which ORM 3 may reject **(plausible; ORM 2.20 has no such check)**. No deprecated XML mapping constructs were found.
- **Fix:** add explicit `nullable`/`on-delete` attributes, and verify the mapped-superclass association against ORM 3 in a test run.

### I-07 — PDF rendering: deprecation handling and the deprecated wkhtml path
- **Where:** `DependencyInjection/CoreShopOrderExtension.php:82-89`; `Renderer/OrderDocumentPdfRenderer.php:47-53`; `Renderer/Pdf/WkHtmlToPdf.php`; `composer.json`.
- **Problem / Fix:**
  - **Deprecation noise.** With the default `use_pimcore_pdf_rendering: false`, the deprecation fires on every container build, and its message is padded with whitespace. Put the message on one line, document the migration, and consider defaulting to `true` when the web-to-print bundle is installed.
  - **Swapped arguments.** The `trigger_deprecation()` arguments in `OrderDocumentPdfRenderer` are swapped, so the message names the Pimcore renderer as the deprecated one.
  - **Unguarded config.** `$config['options']` is read without `isset`.
  - **Temp files.** They are named with `uniqid()`. Use `tempnam()` and delete them in a `finally`.
  - **Undeclared dependency.** `Net_URL2` (`pear/net_url2`) is used but not declared in `composer.json`.

## Controllers

### I-08 — Wrong service types in `getSubscribedServices()`, and concrete return types
- **Where:**
  - `Controller/OrderInvoiceController.php:237`: the order repository is typed `ProcessableInterface`.
  - `OrderInvoiceController.php:240`: the invoice processable is typed `NoteServiceInterface`.
  - `OrderInvoiceController.php:229-232`: the getter returns the concrete `StateMachineManager`.
  - `Controller/OrderCreationController.php:341`: `AddressFormatter` is typed `TokenStorageUserResolver`.
  - `Controller/OrderPaymentController.php:148-151,163-166` and `OrderShipmentController.php:225-228`: concrete `EntityManager` and `StateMachineManager`.
- **Problem:** this works only because the `Autowire` id wins over the declared type. A decorated service would cause a `TypeError`.
- **Fix:** declare the real interfaces, or inject the services directly (I-03).

### I-09 — `parent::getSubscribedServices() + [list]` silently drops entries
- **Where:** `Controller/OrderCreationController.php:337-343`; consumer `CoreBundle/Controller/OrderCreationController.php:142-151`.
- **Problem:** this class appends list entries with integer keys 0 and 1. The CoreBundle subclass also uses `+`, so its entries 0 and 1 are dropped, and they only survive because entries 3 and 4 duplicate them. CoreBundle TODO-2 **B-64** noticed the duplicates but not the cause.
- **Fix:** use `array_merge()` in both places, or string keys.

### I-10 — Dead or stale controller code
- `OrderController::prepareAddress()` (`:347-384`) has no caller, and it would also raise a `TypeError` on `method_exists(null)`.
- `OrderCreationController`: the `$addressFormatter` property and `setAddressFormatter()` (`:52,191,326-330`). The setter ignores its argument and is never called.
- `OrderCreationController.php:58,114`: a commented-out check and a stale TODO (see TODO-2 **B-11**).
- `OrderPaymentController.php:111`: `method_exists($payment, 'setCurrency')` is always true.
- Several actions have unused `Request` parameters.
- Routes with no bundled JS caller: `coreshop_admin_order_find` and all `order-edit/*` routes. Document them as API or remove them.

### I-11 — Route definitions
- **Where:** `Resources/config/pimcore/routing.yml:49-217`; `Controller/OrderEditController.php:66`.
- **Fix:**
  - Add `requirements: {id: '\d+'}` to `order/update/{id}` and to the other id routes.
  - `MethodNotAllowedHttpException(['post'])` puts lowercase `post` in the `Allow` header. Use `['POST']`, and check the method before loading the order. Use `$request->isMethod()` consistently.
  - Consider PHP route attributes next to the controllers, with the method restrictions from TODO-1 **C-03**.

### I-12 — Render actions swallow exceptions without logging, and two messages are copy-pasted
- **Where:** `Controller/OrderInvoiceController.php:163,183-196`, `OrderShipmentController.php:179-192`.
- **Problem:** the local change against upstream correctly removed the stack trace from the response, but nothing is logged now. Only `\Exception` is caught. Two messages are copy-pasted from the other controller: `'invalid shipment'` in the invoice controller (`:163`) and `'Invoice with Id'` in the shipment controller (`:192`).
- **Fix:** inject a `LoggerInterface` and log the exception, catch `\Throwable`, build the header with `HeaderUtils::makeDisposition()`, and fix both messages.

### I-13 — Inconsistent JSON error contract — (plausible)
- **Where:** `ResourceBundle/Form/Helper/ErrorSerializer.php:32` returns an array, which is passed as `message` in the invoice, shipment, creation, edit, customer and address controllers. Raw exception messages are returned at `OrderInvoiceController.php:68,149`, `OrderShipmentController.php:68,146` and `OrderCommentController.php:83`.
- **Problem:** the JS calls `t(res.message)` or `Ext.Msg.alert(res.message)`, so array messages are displayed badly, and raw exception texts can leak internal details.
- **Fix:** always return a string `message`, plus an optional `errors` array. Log exceptions and return a generic message.

### I-14 — `OrderDocumentPrintController` cleanup
- **Where:** `Controller/OrderDocumentPrintController.php:27-64`, `Resources/config/services/controller.yml`.
- **Problem:** four near-identical actions with unused `Request` parameters. It extends `FrontendController`, which brings document resolution it does not need. It is only used through sub-requests from `Renderer/OrderDocumentPdfRenderer.php:68-72`.
- **Fix:** make it `final`, extend `AbstractController`, and use one private render helper. Keep the service public.

### I-15 — `OrderController::updateAction` structure
- **Where:** `Controller/OrderController.php:258-317`.
- **Problem:**
  - Item notes are stored before `persistCart()`, so a failed persist leaves notes for changes that never happened.
  - A by-reference closure capture is used only to pass data out of the closure.
  - `instanceof $orderItem` (an instance check against an object) is hard to read.
  - Quantities are compared with `!==`, which can misfire on floats.
- **Fix:** collect the changes first, then persist the order and store the notes together in one transaction (see TODO-2 **B-03**).

## Domain services, repositories and renderers

### I-16 — `LastActivatedCartStorage` hardening (local change)
- **Where:** `StorageList/LastActivatedCartStorage.php`.
- **Problem / Fix:**
  - Return early when the cart has no id, as the inner storage does. Otherwise `save()` creates a parentless object.
  - The "create" path saves the cart twice.
  - A full `save()` just to write one timestamp is heavy. A direct `UPDATE` (or `saveScalar`-style update) is cheaper. If `save()` stays, the `Version::disable()` / `finally` pair is correct.
  - The docblock credits the context with the ordering, but the repository does it (`OrderRepository.php:102-118`).
  - See TODO-2 **B-16** for the functional gap.

### I-17 — State resolvers: repeated loading, concrete types, constants
- **Where:** `StateResolver/*.php`; `Component/Order/Processable/ProcessableOrderItems.php`.
- **Problem / Fix:**
  - `findForPayable()` runs 5 times per resolve, and the document lists are loaded several times. Load once and group.
  - The resolvers type-hint final concrete classes (`StateMachineManager`, `StateMachineApplier`) while the service files inject interfaces, so projects cannot decorate them.
  - The literal `'coreshop_order'` is used instead of the identifier constant.
  - The order-level `OrderShipmentStates::STATE_CANCELLED` is used for document states. It has the same value today, but it is the wrong constant.
  - `ProcessableOrderItems` compares float quantity sums with `<`, so fractional units may never count as fully processed. Compare with an epsilon, or in integer base units.

### I-18 — Repository query hygiene
- **Where:** `Pimcore/Repository/OrderRepository.php`, `CartItemRepository.php`, `AbstractOrderDocumentRepository.php`.
- **Problem / Fix:**
  - `findLatestCartByStoreAndCustomer()` loads all of the customer's carts to return one. Add `setLimit(1)`.
  - `findByCartId()` / `findByToken()` run a COUNT query and then a load. Use `setLimit(1)` and `current()`.
  - The expiry listings are unbounded. Page them.
  - `findLatestByStoreAndCustomer()` ignores `$name`, the same defect as CoreBundle TODO-2 **B-40**.
  - The `$groupBind` code is dead (TODO-2 **B-18**).
  - No SQL injection was found: every query binds its parameters.

### I-19 — `CartManager::persistCart()` churn
- **Where:** `Manager/CartManager.php:49-116`.
- **Problem:**
  - Every persist renames every item (`uniqid()` key) and saves it twice.
  - The date folder is resolved or created even for carts that are already saved.
  - A `func_get_arg()` hack replaces a real parameter.
  - The renaming pattern matches FrontendBundle TODO-2 **B-14**.
- **Fix:** give items a stable key, save each item once, resolve the folder only for new carts, and add a real optional parameter (with a deprecation for the old signature). Combine this with the lock from TODO-2 **B-20**.

### I-20 — Dead code and leftovers in listeners, events and Twig
- `EventListener/SessionCartSubscriber.php` is never registered, and the `coreshop.session.cart` parameter is unused.
- `Expiration/OrderExpirationInterface.php` says "removed with 3.1.0" and has no users. Remove it.
- Event classes (`Event/*.php`): promote `$data` and make it readonly. A `final` class should not have `protected` members.
- `EventListener/Grid/CartFilterListener`: `isset()` is also true for `"0"`, and there is no class check.
- `Renderer/PimcoreOrderDocumentPdfRenderer`: `getOrder()?->` is used inconsistently.
- `Renderer/AssetOrderDocumentPdfRenderer`: `'dev'` is compared where `%kernel.debug%` is used elsewhere.
- `Twig/ImageToBase64Extension`: a versioned `asset()` URL or a CDN URL breaks the path, and any path or stream wrapper is accepted. Strip the query string, `realpath()` the path, and require it to be under `public/`.

## Forms, validators, DTOs and attributes

### I-21 — Validators: proper exceptions and typing
- **Where:** `Validator/Constraints/*Validator.php`.
- **Fix:** replace `Assert::isInstanceOf` with `UnexpectedTypeException` / `UnexpectedValueException`, use `mixed $value`, `#[\Override]` and `private readonly` dependencies, and mark the validators `final`.

### I-22 — Merge the two quantity data mappers
- **Where:** `Form/DataMapper/CartItemQuantityDataMapper.php`, `BackendCartItemQuantityDataMapper.php`.
- **Problem:** they differ only in `ArrayObject` vs `ArrayCollection`, and the comment contradicts the code.
- **Fix:** merge them into one `final` class with a constructor flag. Fix TODO-2 **B-30** and **B-33** there, and correct the comment.

### I-23 — `QuantityType` duplicates `NumberType`
- **Where:** `Form/Type/QuantityType.php:31-71`.
- **Problem:**
  - The `CallbackTransformer` runs in the wrong direction and has no effect: `NumberType` already parses "1,5" as 1.5 under the `de` locale (reproduced).
  - The `html5` option and `buildView()` repeat what `NumberType` already does.
  - The `attr` default is replaced wholesale when a caller passes its own `attr`.
- **Fix:** remove the duplicated code and add an `attr` normalizer that merges defaults.

### I-24 — Dead form code and configuration
- `CartCreationType`: an empty `PRE_SET_DATA` listener, and the unused `sales_state` option.
- `CartPriceRuleChoiceType`: an unused `'active'` option.
- `OrderInvoiceCreationType` / `OrderShipmentCreationType`: the unused `id` field.
- `Timespan`, `Voucher` and `NotCombinable` config types: unused `$validationGroups`.
- Unused parameters: `coreshop.form.type.cart_price_rule.rule.validation_groups`, and the `promotion_coupon` validation groups (`services/cart-price-rules.yml:5,8`).
- `services/forms.yml:11-12,83-87`: misnamed validation-group parameters for `CartItemActionConfigurationType`.
- Already tracked as PaymentBundle TODO-3 **I-11**: hard-coded groups in `NestedConfigurationType` (fix in RuleBundle's `AbstractNestedConfigurationType`), and `Type(numeric)` after a `NumberType`/`MoneyType`, which can never fail.

### I-25 — Rule configuration types: use proper types and ranges
- **Where:** `Form/Type/Rule/Condition/*`, `Form/Type/Rule/Action/*`, `Form/Type/CartPriceRuleType.php`.
- **Fix:**
  - Timespan: `IntegerType`, plus a `from <= to` check. Also fix the "Mabye" typo.
  - Amount: `PositiveOrZero`, plus a `min <= max` check (see PaymentBundle TODO-2 **B-04**).
  - NotCombinable: set `entry_type: IntegerType`.
  - `priority`: use `IntegerType`.
  - Decide whether a surcharge above 100 % is allowed, and validate accordingly.

### I-26 — `CartPriceRuleTranslationType` extends the wrong parent
- **Where:** `Form/Type/CartPriceRuleTranslationType.php`.
- **Problem:** it extends `RuleType` but replaces `buildForm()` entirely.
- **Fix:** extend `AbstractResourceType`, following the PaymentBundle TODO-3 **I-11** pattern.

### I-27 — Hard-coded data classes for voucher forms
- **Where:** `Resources/config/services/cart-price-rules.yml:21-33` (`VoucherType`, `VoucherGeneratorType`).
- **Problem:** literal class names bypass resource model overrides.
- **Fix:** use `%coreshop.model.cart_price_rule_voucher_code.class%` and the matching generator parameter.

### I-28 — Factories and DTOs
- **Where:** `Factory/*.php`, `DTO/*.php`.
- **Problem:**
  - The factories check the interface only after instantiating the class.
  - `AddMultipleToCart` does not type its items.
  - `AddMultipleToCartInterface` lacks `setItems()`/`addItem()`, although the form relies on them.
- **Fix:** validate the class in the constructor with `is_a(…, true)` and use `instanceof`. Type the items as `list<AddToCartInterface>`, add the missing methods to the interface, and use `readonly` promoted properties where possible.

### I-29 — Attribute classes: style and options
- **Where:** `Attribute/*.php`.
- **Fix:** use `public readonly` promoted properties, add `priority` where the registry is prioritized (TODO-2 **B-38**), and consider `\Attribute::IS_REPEATABLE`.

### I-30 — Admin helper forms
- `VoucherGeneratorType`: the `format` choices are a list, so their labels render as 0, 1, 2. Use a label => value map.
- `CartPriceRuleChoiceType`: calls `findAll()` on every build, is unsorted, and lists non-voucher rules too. Add sorting and a `voucher_only` option.

## Dependency injection and configuration

### I-31 — `services.yml` is loaded twice
- **Where:** `DependencyInjection/CoreShopOrderExtension.php:64` and `:113`.
- **Problem:** harmless today, but wasteful. A second load would also silently undo any definition change made between the two calls.
- **Fix:** remove the second `load()`.

### I-32 — Configuration tree cleanups (partly already tracked)
- **Where:** `DependencyInjection/Configuration.php`.
- **Problem / Fix:**
  - `autoconfigure_with_attributes` is a `scalarNode` (`:78`); it should be a `booleanNode`. Already tracked as AddressBundle TODO-2 **B-14** and CustomerBundle TODO-2 **B-10**.
  - The `graphql.enabled` flags have no effect. Already tracked as AddressBundle TODO-2 **B-15**.
  - There are commented TODOs in `cart_price_rule_voucher_code`.
  - `permissions` is a `scalarNode` with an array default. Make it an `arrayNode` with a scalar prototype.
  - `pimcore.order.path` needs `addDefaultsIfNotSet()` (TODO-2 **B-45**).

### I-33 — Registry naming and type hints
- **Where:** `DependencyInjection/Compiler/PurchasableDiscountPriceCalculatorsPass.php:31`; `Resources/config/services/purchasable.yml:72,80`.
- **Problem / Fix:**
  - The pass uses the parameter `…discount_price_calculator` (singular) while its siblings are plural. Align the name and keep the old one as a deprecated alias.
  - The `type_hint` is misspelled `purchaseable…`. Fix the spelling and keep the old value as an alias.

### I-34 — `RegisterWorkflowValidatorPass` is dead and could not work
- **Where:** `DependencyInjection/Compiler/RegisterWorkflowValidatorPass.php`, registered in `CoreShopOrderBundle.php:65`.
- **Problem:** no service has the tag. No manager in the code base has `addValidator($ref, $type, $priority)`, so tagging a service would fail at runtime. It is a CoreShop 2 leftover.
- **Fix:** remove the pass, or deprecate it first.

### I-35 — Dead or unused configuration
- **Serializer files for classes that do not exist:** `Resources/config/serializer/Model.AbstractProposal.yml`, `Model.Quote.yml`, `Model.QuoteItem.yml`.
- **Unmapped properties:** `Model.CartPriceRule.yml:22` exposes `autoRuleNotCombinable`, which is not a property. `CartPriceRule::$usagePerVoucherCode` is neither mapped nor used.
- **Commented admin entries:** `Resources/config/pimcore/admin.yml` has commented `sale/*` entries pointing to files that do not exist.
- **Wrong workflow event:** `Resources/config/pimcore/workflow/coreshop_quote.yml:35` lists `confirm` in `on`, which is not a quote transition.
- **Stale validation file:** `validation/CartItem.yml` is handled in TODO-2 **B-31**.

### I-36 — Missing translations (light pass)
- **Admin transition labels** `coreshop_workflow_transition_*`:
  - `coreshop_order_confirm`;
  - every transition of `order_payment`, `order_invoice`, `order_shipment` and `order_sales_type`;
  - the quote transitions `create`, `cancel` and `complete`.
- **Workflow name:** `coreshop_workflow_name_coreshop_quote`.
- **Frontend labels** used by `OrderStateExtension`: `coreshop.ui.workflow.state.coreshop_order_invoice.{ready,partially_invoiced}`.
- **Fix:** add the keys to `Resources/translations/admin.*.yml` and `messages.*.yml`, and add a test that compares the workflow definitions with the translation keys.

### I-37 — Serializer metadata cleanups
- **Where:** `Resources/config/serializer/*.yml`.
- **Problem / Fix:**
  - `Model.Order.yml:5-9` exposes `token` in the `List` group. That is the bearer token for the customer revise and pay flow. Today only the admin uses the `List` group, but move the token to `Detailed` (or a dedicated group).
  - The voucher code serializer does not expose the credit-code fields.
  - The invoice and shipment item serializers emit the same value as both `_itemName` and `name`.
  - The old "JMS > 3.0" TODO comment can go.
  - See TODO-2 **B-42** and **B-43** for the wrong types and the typo.
