# PayumPaymentBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (15 files: `CoreShopPayumPaymentBundle.php`, `Events.php`, `Controller/`, `DependencyInjection/**`, `Event/`, `Form/**`, `Resolver/`, `Resources/config/services.php`), `composer.json`, and all of `Resources/config/**` (`services.yml`, `services/{form,payment}.yml`, `pimcore/{config,payum,routing}.yml`, `serializer/Model.PaymentProvider.yml`, `doctrine/model/*.orm.xml`). Call paths were followed into `Component/PayumPayment` (all models), `PaymentBundle` (`PaymentProviderType`, `PaymentProviderChoiceType`, serializer metadata, `provider/{panel,item}.js`), `CoreBundle` (`services/payment.yml`, `services/checkout.yml`, `routing.yml`, `Form/Type/Checkout/PaymentType.php`, serializer metadata), `PayumBundle` (`CoreShopPayumBundle`, gateway configuration forms, `services.yml`), `ResourceBundle` (`ResourceController`, `ViewHandler`, `AbstractResourceType`, `FormTypeRegistry`, `resource/item.js`), `OrderBundle` (`OrderController`, `Model.Order.yml`) and `Component/Core` (`Events`, `StoreBasedPaymentProviderResolver`).

Reference for API checks: `vendor/` (`symfony/form` 7.4.21, `symfony/dependency-injection` 7.4.21, `doctrine/dbal` 3.10.6, `doctrine/orm` 2.20.13, `payum/payum` 1.7.7, `payum/payum-bundle` 2.7.2, `guzzlehttp/guzzle` 7.16.0, `php-http/guzzle7-adapter` 1.1.0, `jms/serializer` 3.32.9, `defuse/php-encryption`) and the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml`. This bundle is byte-identical to the vendored `six-it/core-shop` copy (`diff -r` is empty).

"Critical" here means: a security issue, money charged or stored wrongly, data loss or data written wrongly, or an error that takes down every request, a whole area, or the install or upgrade path once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by reading the vendored library source or the compiled container. Items marked **(latent)** are not reachable today but break as soon as the linked fix lands.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers. `php -l` (PHP 8.4.27) passes on every PHP file in the bundle, and every service definition resolves in the compiled container. The one runtime incompatibility (Guzzle 7, see **C-03**) is in a service that is not yet used. Forward-compatibility and modernisation items are in TODO-3 (**I-01** to **I-04**, **I-08**, **I-13**).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security

### C-01 — Payment gateway credentials are sent to the admin browser in every provider list and order detail response, because the serializer metadata for `gatewayConfig` never applies — (verified metadata, plausible reach) (dependent)
- **Where:**
  - `Resources/config/serializer/Model.PaymentProvider.yml:1` maps `CoreShop\Bundle\PayumPaymentBundle\Model\PaymentProvider`. That class does not exist. The model is `CoreShop\Component\PayumPayment\Model\PaymentProvider`, and `pimcore/config.yml:7-9` registers the directory with `namespace_prefix: CoreShop\Bundle\PayumPaymentBundle` (compiled container line ~8960). The file is never loaded.
  - `CoreBundle/Resources/config/serializer/Model.PaymentProvider.yml:9-10` lists `gatewayConfig` under `CoreShop\Component\Core\Model\PaymentProvider`. JMS `YamlDriver` only builds metadata for properties **declared** in that class (`vendor/jms/serializer/src/Metadata/Driver/YamlDriver.php:148-151`); `gatewayConfig` is declared in `Component/PayumPayment/Model/PaymentProvider.php:28`. That entry is ignored too.
  - `Payum\Core\Model\GatewayConfig` (and CoreShop's subclass) has no serializer metadata at all, so `config` and `decryptedConfig` are serialized as plain properties.
  - `ResourceBundle/Controller/ViewHandler.php:37-38` always adds the `Default` group. Properties without metadata are in `Default`, so `gatewayConfig.config` is in **every** response that contains a provider.
- **Reach:**
  - `ResourceController::listAction()` (`ResourceBundle/Controller/ResourceController.php:79-88`, group `List`, **no** `isGrantedOr403()`): every logged-in admin user, with or without the payment-provider permission, gets all providers with their PayPal `username` / `password` / `signature` and Sofort `config_key`.
  - `OrderBundle/Controller/OrderController.php:388` serializes the order without groups; `OrderBundle/Resources/config/serializer/Model.Order.yml:35-38` exposes `paymentProvider`. Every admin who can open an order gets the gateway credentials of that order's provider.
  - `getAction` / `saveAction` / `cloneAction` responses (expected for the edit form, but see **I-09** about not sending stored secrets back at all).
  - With encryption on (TODO-2 **B-01**) the list and order responses contain ciphertext instead of plaintext, which is still more than they need.
- **Fix:**
  - Fix the metadata registration: `namespace_prefix: "CoreShop\\Component\\PayumPayment"`, and rename the class key in `Model.PaymentProvider.yml` to `CoreShop\Component\PayumPayment\Model\PaymentProvider`, with `exclusion_policy: ALL` and `gatewayConfig` exposed only in `Detailed`.
  - Add `Model.GatewayConfig.yml` for `CoreShop\Component\PayumPayment\Model\GatewayConfig` (and, because JMS walks the hierarchy, for `Payum\Core\Model\GatewayConfig` through a separate `namespace_prefix: Payum\Core\Model` directory) with `exclusion_policy: ALL`: expose `id`, `gatewayName`, `factoryName` in `List`/`Detailed`; never expose `decryptedConfig`; expose `config` only in a dedicated group (e.g. `GatewayConfigEdit`) that only `PaymentProviderController::getAction()` uses.
  - Remove the dead `gatewayConfig` entry from `CoreBundle/Resources/config/serializer/Model.PaymentProvider.yml`.
  - Exclude `gatewayConfig` from the order serialization (only `id`, `identifier`, `title` are needed there).
  - Add a functional test: list endpoint and order detail JSON must not contain `config`.

---

## Whole area down

### C-02 — One active payment provider without a gateway config takes down the checkout payment step for every customer — (plausible) (dependent)
- **Where:** `Form/Extension/PaymentProviderChoiceTypeExtension.php:32-35`: `$val->getGatewayConfig()->getFactoryName()`. `Component/PayumPayment/Model/PaymentProvider.php:35` returns `?GatewayConfig`. The same dereference exists in `CoreBundle/Form/Type/Checkout/PaymentType.php:81` and `:148`.
- **Problem:**
  - `choice_attr` is evaluated for every provider returned by the resolver chain (`StoreBasedPaymentProviderResolver` → `findActiveForStore()`, compiled container line ~17985). If any active provider of the store has `gatewayConfig === null`, rendering the payment step throws `Error: Call to a member function getFactoryName() on null`, so nobody can check out.
  - A provider without a gateway config is easy to get: the admin "factory" combo is optional (`PaymentBundle/Resources/public/pimcore/js/provider/item.js:104-122`), the provider is created by `addAction` with only an identifier, and `PaymentProvider.orm.xml:9` uses `on-delete="SET NULL"`, so deleting a gateway config row nulls the reference.
  - The closure is also typed against `CoreShop\Component\PayumPayment\Model\PaymentProviderInterface`. A custom resolver that returns a plain `CoreShop\Component\Payment\Model\PaymentProviderInterface` causes a `TypeError` for the same page.
- **Fix:**
  - In the extension, type the closure parameter as the base `PaymentProviderInterface`, use `$val instanceof PayumPaymentProviderInterface ? $val->getGatewayConfig()?->getFactoryName() : null`, and return `[]` when there is no factory.
  - Fix the two `CoreBundle` call sites the same way (**dependent**).
  - Prevent the state at the source: a provider must not be activatable without a gateway config with a non-empty `factoryName` (validation constraint on the Payum provider model, see TODO-2 **B-06**), and filter such providers out in the resolver (`EventBasedPaymentProviderResolver` is a good place).
  - Add a test: one active provider with `gatewayConfig = null` → payment step still renders the remaining providers.

### C-03 — `coreshop.payum.http_client` can never be instantiated on Guzzle 7: wiring it into the PayPal / Sofort gateways breaks every payment through them — (verified, latent)
- **Where:** `Resources/config/services/payment.yml:18-20` (`factory: [Payum\Core\Bridge\Guzzle\HttpClientFactory, create]`). Consumers: `PayumBundle/Form/Type/PaypalGatewayConfigurationType.php:61` and `SofortGatewayConfigurationType.php:43` write `payum.http_client = '@coreshop.payum.http_client'` into the gateway config.
- **Problem:**
  - `vendor/payum/payum/src/Payum/Core/Bridge/Guzzle/HttpClientFactory.php:29` reads `\GuzzleHttp\ClientInterface::VERSION`. Guzzle 7.16 only has `MAJOR_VERSION` (`vendor/guzzlehttp/guzzle/src/ClientInterface.php:19`), so the factory throws `Error: Undefined constant GuzzleHttp\ClientInterface::VERSION`. Even with that constant, `:30-32` throws `LogicException('This version of Guzzle is not supported.')` for anything but Guzzle 6. The class is `@deprecated` and removed in Payum 2.
  - Today nothing reaches it, only because the PayPal / Sofort form listeners never call `$event->setData()` (PayumBundle TODO-2 **B-09**). The fix proposed there ("call `$event->setData($data)`") stores `@coreshop.payum.http_client` in the gateway config; from then on Payum resolves the service on every gateway build and every capture, notify and status request through PayPal / Sofort fails.
- **Fix:**
  - Replace the definition with a PSR-18/HTTPlug based client that works on Guzzle 7 / Symfony 7.4, e.g. `Payum\Core\Bridge\Httplug\HttplugClient` (`vendor/payum/payum/src/Payum/Core/Bridge/Httplug/HttplugClient.php`) wrapping either `Symfony\Component\HttpClient\HttplugClient` (`symfony/http-client ^7.4` is already required by `Component/PayumPayment/composer.json:30`) or `Http\Adapter\Guzzle7\Client` (the already required `php-http/guzzle7-adapter`).
  - Make it private and only expose it through the gateway config reference.
  - Land this **before** or **together with** PayumBundle TODO-2 **B-09**, and add a kernel test that fetches `coreshop.payum.http_client` from the container.

---

## Money charged wrongly

### C-04 — Two gateway configs can share one `gatewayName`; Payum then uses the first one, so payments for one provider are processed with another provider's merchant credentials — (plausible)
- **Where:** `Form/Extension/PaymentProviderTypeExtension.php:35-47` (gateway name only set when `null`), `Resources/config/doctrine/model/GatewayConfig.orm.xml` (no unique constraint; Payum's `gateway_name` column from `vendor/payum/payum/src/Payum/Core/Bridge/Doctrine/Resources/mapping/GatewayConfig.orm.xml:5` is not unique either). Lookup: `vendor/payum/payum/src/Payum/Core/Registry/DynamicRegistry.php:78-79` (`findBy(['gatewayName' => $name])` + `array_shift()`).
- **Problem:**
  - The gateway name is copied from the provider identifier once, on first save, and never updated. Provider identifiers are unique (`PaymentBundle/Resources/config/doctrine/model/PaymentProvider.orm.xml:12`), gateway names are not.
  - Scenario: provider `paypal` (account A) is renamed to `paypal_old`; its gateway config keeps `gatewayName = paypal`. A new provider `paypal` (account B) is created and gets a second gateway config with `gatewayName = paypal`. `PayumBundle` builds tokens with `getGatewayConfig()->getGatewayName()` (`PayumBundle/Controller/PaymentController.php:148`), and `DynamicRegistry` returns the **first** row, i.e. account A. Customers who pick the new provider pay into the old merchant account; refunds / notifications go to the wrong gateway.
  - The static `offline` gateway from `pimcore/payum.yml:13-15` adds a third source for the name `offline` (see TODO-3 **I-11**).
- **Fix:**
  - Add a unique index on `coreshop_payment_gateway_config.gateway_name` (migration that first reports or renames duplicates).
  - Decide the rule and enforce it: either keep the gateway name immutable and independent of the identifier (generate it, e.g. `provider_<id>` or a UUID, on create), or keep it in sync on every identifier change. Immutable is safer because existing tokens and payments reference the name.
  - Add a `UniqueEntity(fields: ['gatewayName'])` constraint and a test for the rename scenario.
