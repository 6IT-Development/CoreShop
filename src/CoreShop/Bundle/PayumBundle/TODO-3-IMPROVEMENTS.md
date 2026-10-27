# PayumBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items (Symfony 8, Doctrine ORM 3, DBAL 4, Payum 2). None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as sub-bullets.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(verified)** were confirmed by reading the vendored library source or the compiled container.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — Native types and `#[\Override]` throughout
- **Where:** every class in `Action/**`, `Factory/**`, `Request/**`, `Extension/`, `Exception/ReplyToSymfonyResponseConverter.php`, `Storage/LockingStorage.php`, `Controller/PaymentController.php`.
- **Problem / Fix:**
  - `execute($request)` / `supports($request)`: declare `mixed $request` (allowed by contravariance against Payum's untyped `ActionInterface`), and drop the `@inheritdoc` / `@param Capture $request` docblocks. Use an `assert($request instanceof Capture)` after `assertSupports()` so static analysis knows the type.
  - `ReplyToSymfonyResponseConverter::convert()`: add `: Response` (covariant with the untyped parent).
  - `LockingStorage::doFind($id)`: add `mixed $id` and `: ?object`.
  - Factory interfaces: `createNewWithModel(mixed $model)` instead of the `@param mixed` docblock.
  - `PaymentController`: the protected getters (`getOrderPaymentProvider()`, `getOrderRepository()`, factory getters) have no return type; add the interfaces as return types.
  - Add `#[\Override]` to every implemented interface / parent method (PHP 8.3+). It catches signature drift on the next Payum upgrade.
  - **Do not** add a type to `Request/GetStatus::$status`: the parent `BaseGetStatus::$status` is untyped, and PHP forbids adding a type to an inherited untyped property. Keep the docblock, drop `@psalm-suppress` once Psalm is gone (see I-13).

### I-02 — Constructor promotion with `readonly`, `final`, and interface type hints
- **Where:**
  - `Action/ConfirmOrderAction.php:29-32` and `Action/Offline/ConfirmOrderAction.php:30-33` type-hint the concrete `StateMachineApplier`, while `services.yml:35,52` inject `StateMachineApplierInterface`.
  - `Extension/UpdatePaymentStateExtension.php:33-36` type-hints the concrete `StateMachineManager`, while `services/extension.yml:7` injects `StateMachineManagerInterface`.
  - `CoreGatewayFactoryBuilder.php:26-34` duplicates the parent's private `$container` property.
  - `EventListener/TransactionListener.php:31-33` (`protected Connection`), `PaymentController` (not `final`).
- **Problem:** it works only because the interface aliases point at the concrete classes. A project that decorates `StateMachineApplierInterface` or `StateMachineManagerInterface` gets a `TypeError` when the container builds these services.
- **Fix:** type-hint the interfaces. Use `private readonly` promoted properties everywhere. Mark `TransactionListener` `final`. Keep `PaymentController` non-final (projects extend it through `core_shop_frontend.controllers.payment`), but make its helpers `private` where they are not meant as extension points.

### I-03 — Remove dead code and dead configuration — (verified)
- **Where / Problem:**
  - `CoreGatewayFactory.php`: never instantiated anywhere. Its `createConfig()` would also discard the caller's `$config` (`parent::createConfig([...])` with a fixed array).
  - `CoreGatewayFactoryBuilder.php` + `services.yml:74-77`: `build()` is byte-for-byte the parent's. Decorating `payum.core_gateway_factory_builder` changes nothing.
  - `Resources/config/payum.yml`: no longer loaded since commit `df97de500` (the extension stopped loading it, and Payum 2.7 registers its controllers itself in `vendor/payum/payum-bundle/Resources/config/controller.php`). The file still exists and also differs from the vendored copy.
  - `Resources/config/doctrine/model/{GatewayConfig,PaymentSecurityToken}.orm.xml`: map classes `CoreShop\Bundle\PayumBundle\Model\*` that do not exist. They are never loaded, because the mapping pass from `AbstractResourceBundle` is only enabled when the driver parameter is set, and this extension never sets it. The compiled container only registers `PayumPaymentBundle`'s mapping directory. The token mapping also uses `type="object"`, which is removed in DBAL 4 (also tracked in ConfigurationBundle TODO-2).
  - `CoreShopPayumBundle::getModelNamespace()` / `getSupportedDrivers()`: only exist for the dead mappings above.
  - `Exception/ReplyException.php`: never thrown anywhere in `src/`. `Exception/ReplyToSymfonyResponseConverter.php` and `DependencyInjection/Compiler/PayumReplyToSymfonyPass.php` exist only to unwrap it.
  - `Resources/views/layout.html.twig`: never used (see TODO-2 **B-11**).
  - `services.yml:44-48`: `Action\Offline\ResolveNextRouteAction` gets `@coreshop.repository.order`, but the class has no constructor.
  - `services.yml:13-14`: stale `TODO` for `coreshop.payum.http_client` (the service exists in `PayumPaymentBundle`).
- **Fix:** delete these files and definitions (after checking that no project subclasses `CoreGatewayFactory` / `ReplyException`). If `ReplyException` is kept as an API, document who should throw it. Drop `php-http/guzzle7-adapter` and `php-http/message-factory` from `composer.json` if nothing else uses them (see I-13).

## Readability and structure

### I-04 — Merge the duplicated Authorize / Capture actions
- **Where:** `Action/AuthorizePaymentAction.php:41-71` and `Action/CapturePaymentAction.php:41-74` are identical except for the request class.
- **Fix:** one abstract `AbstractPaymentDetailsAction` (or a trait) with a `convertIfNew()` helper and a `supportedRequestClass()` hook. Inside, use `$payment->getCurrencyCode()` (already set by `OrderPaymentProvider`) instead of `$payment->getCurrency()->getIsoCode()`, which crashes when the currency is `null`. Fix TODO-2 **B-03** in the shared code.

### I-05 — Make Payum action ordering explicit
- **Where:** `services.yml:21-31`.
- **Problem:** `CapturePaymentAction`, `AuthorizePaymentAction` and `ExecuteSameRequestWithPaymentDetailsAction` all support `Capture` / `Authorize` with a CoreShop payment. The right one wins only because it is declared earlier in the YAML file, so the tag order puts it first in the gateway. Reordering the file (or a project adding an `all: true` action) silently changes the behaviour.
- **Fix:** narrow `ExecuteSameRequestWithPaymentDetailsAction::supports()` to exclude `Capture` / `Authorize`, or use `prepend: true` on the two specific actions. Add a comment explaining the ordering.

### I-06 — `PaymentController`: inject services, and split the lookup from the payment flow
- **Where:** `Controller/PaymentController.php:41-140`.
- **Fix:**
  - Use `findByToken()` (TODO-2 **B-13**) instead of building the criteria from request attributes.
  - Put the guard logic from TODO-1 **C-02** in its own service (`OrderPayabilityChecker`), so the frontend revise action and the pay action use the same rules.
  - Read `use_authorize` from the config once, and document it as a gateway-config option (no admin UI sets it today).
  - Type `$status` as `GetStatusInterface` (the docblock says `Generic`).
  - The service-subscriber pattern is needed for `RegisterFrontendControllerPass` (custom controllers reuse the definition). Keep it, but give each locator getter a return type (I-01).

### I-07 — `UpdatePaymentStateExtension`: readability
- **Where:** `Extension/UpdatePaymentStateExtension.php:46-92`.
- **Fix:**
  - Replace the class-name string match `stripos($class, 'NotifyNullAction')` with `instanceof NotifyNullAction`.
  - Remove the odd `@var int @psalm-type int` docblock on `count()`.
  - Use `!$x instanceof Y` instead of `false === $x instanceof Y`.
  - Explain the "previous stack size" rule in a short comment (only the outermost request, or the one directly under `NotifyNullAction`).
  - Inject a `LoggerInterface` for TODO-2 **B-06**.

### I-08 — PayPal converter and Offline status action: small clean-ups
- **Where:** `Action/Paypal/ExpressCheckout/ConvertPaymentAction.php`, `Action/Offline/StatusAction.php`.
- **Fix:**
  - Build line items with a small `addLine(array &$details, int &$i, string $name, int $amount, int $qty)` helper instead of four copies of the same block.
  - Truncate `L_PAYMENTREQUEST_0_NAMEn` to PayPal's 127-character limit and remove control characters.
  - Translate the "Tax Total" / "Discount" / "Shipping Total" labels (they appear on the customer's PayPal page) using the order locale.
  - `StatusAction`: one `match` on the status (see TODO-2 **B-08**).

### I-09 — Requests: make "not resolved" explicit
- **Where:** `Request/ResolveNextRoute.php`, `Request/ResolveNextRouteInterface.php`, `Request/ConfirmOrderInterface.php`.
- **Fix:** `?string $routeName = null` with `getRouteName(): ?string` (or a `hasRouteName()` method), see TODO-2 **B-04**. `ConfirmOrderInterface` is an empty marker: either add `getFirstModel()` to it or document why it is empty.

## Configuration

### I-10 — Services: private by default, autowire/autoconfigure
- **Where:** `Resources/config/services.yml`, `services/{extension,factory,form}.yml` (`_defaults: public: true` everywhere).
- **Fix:** make the actions, extensions, factories and form types private with `autowire: true` / `autoconfigure: true`. Only the controller needs to be public, and that is done in `RegisterFrontendControllerPass`. Keep the explicit `payum.action` tags (Payum has no attribute for them). The factory interface aliases can use the short `alias:` syntax.

### I-11 — Extension and config tree
- **Where:** `DependencyInjection/CoreShopPayumExtension.php:28-38`, `DependencyInjection/Configuration.php`.
- **Fix:** rename `$configs` (the processed config) to `$config`. Either remove the config tree (TODO-2 **B-11**) or implement `PrependExtensionInterface` to pass the template keys to the `payum` extension. `AbstractModelExtension` is not needed once the dead mappings are gone (I-03): extend Symfony's `Extension` (or use `AbstractBundle::loadExtension()`).

### I-12 — `Resources/config/pimcore/config.yml` changes Payum's storage class globally
- **Where:** `Resources/config/pimcore/config.yml:2` (`payum.storage.doctrine.orm.class`).
- **Problem:** replacing a parameter of another bundle works only as long as Payum keeps that parameter (`vendor/payum/payum-bundle/Resources/config/storage/doctrine.orm.php:10-12`). It also applies to every Payum storage, including the token storage, where the lock is never needed.
- **Fix:** after TODO-1 **C-03**, either decorate only `payum.storage.coreshop_component_core_model_payment`, or move the lock into a Payum extension and drop the custom storage class.

## Dependencies, docs and tests

### I-13 — `composer.json` does not match what the code uses
- **Where:** `composer.json`.
- **Fix:**
  - Fix the description ("CoreShop - Payment Bundle" → "CoreShop - Payum Bundle").
  - Add `"php": "^8.4"`.
  - Require what the code imports directly: `coreshop/workflow-bundle` (`StateMachineApplier`, `StateMachineManager`), `coreshop/resource-bundle`, `doctrine/dbal`, `doctrine/orm`, `symfony/framework-bundle`, `symfony/http-kernel`, `symfony/event-dispatcher`, `symfony/form`, `symfony/validator`, `symfony/dependency-injection`, `symfony/config`.
  - Remove what the bundle does not use: `symfony/notifier`, `symfony/serializer`, and (after I-03) `php-http/guzzle7-adapter` and `php-http/message-factory` (abandoned).
  - Raise `payum/payum-bundle` to `^2.7` (the first line with Symfony 7 support that matches the vendored version).
  - `require-dev`: `phpstan/phpstan` `^1` → `^2` together with the extensions, as in the other bundles.

### I-14 — PayPal Express Checkout (NVP) is a legacy API
- **Where:** `Action/Paypal/ExpressCheckout/ConvertPaymentAction.php`, `Form/Type/PaypalGatewayConfigurationType.php`, `services.yml:60-66`.
- **Problem:** PayPal's NVP/SOAP Express Checkout is closed to new merchants, and username/password/signature credentials are a legacy model. TODO-2 **B-01** / **B-02** and TODO-1 **C-01** all live in this converter.
- **Fix:** plan a move to PayPal Checkout (Orders v2 REST, client id/secret) through a maintained Payum gateway or a dedicated CoreShop bundle. Mark the NVP integration as deprecated in the README.

### I-15 — Add tests
- **Where:** the bundle has no `Tests/` directory.
- **Fix:** add unit tests for `GetStatus` mapping (TODO-2 **B-07**), Offline `StatusAction` (**B-08**), the PayPal converter (TODO-1 **C-01**, TODO-2 **B-01**, **B-02**), `UpdatePaymentStateExtension` (**B-06**), and functional tests for `PaymentController` guards (TODO-1 **C-02**) and the transaction boundary (TODO-1 **C-03**).
