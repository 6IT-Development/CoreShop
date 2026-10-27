# PayumPaymentBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items (Symfony 8, Doctrine ORM 3, DBAL 4, Payum 2). None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as sub-bullets.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(verified)** were confirmed by reading the vendored library source or the compiled container.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — `composer.json` does not describe what the bundle needs
- **Where:** `composer.json:26-37`.
- **Problem / Fix:**
  - No `php` constraint (the component already has `"php": "~8.4.0"`, `Component/PayumPayment/composer.json:27`). Add `"php": "^8.4"` (or the same `~8.4.0`).
  - Directly used packages are not declared: `symfony/form`, `symfony/dependency-injection`, `symfony/config`, `symfony/http-foundation`, `symfony/options-resolver`, `symfony/event-dispatcher(-contracts)`, `doctrine/orm`, `jms/serializer-bundle`, `coreshop/resource-bundle` (`AbstractResourceBundle`, `ResourceController`, `FormTypeRegistry`), `pimcore` (`PimcoreBundleInterface`, `BundleCollection`). Add them with `^7.4` for Symfony.
  - `payum/payum-bundle: ^2.5` → `^2.7` (first line with Symfony 7 support; 2.7.2 is installed).
  - `php-http/guzzle7-adapter` is required but nothing in the bundle uses it; either use it for the HTTP client (TODO-1 **C-03**) or drop it. `php-http/message-factory` (pulled in transitively) is abandoned.
  - `coreshop/payment-bundle` / `coreshop/payum-payment` `^4.0` while the code lives in the `six-it/core-shop` fork with `branch-alias 4.1-dev`; align names/versions with the fork's release process.
  - `require-dev`: `phpstan/phpstan ^1.10` + extensions are EOL; move to PHPStan 2.x (`phpstan/phpstan ^2.1`, `phpstan-doctrine ^2`, `phpstan-webmozart-assert ^2`).
  - `"minimum-stability": "dev"` in a library package has no effect for consumers; remove it or keep it only in the monorepo root.

### I-02 — Native types, `readonly`, `final` and `#[\Override]` throughout
- **Where / Fix:**
  - `Controller/PaymentProviderController.php`: not `final`; `getConfigAction(): Response` → `: JsonResponse` (that is what `viewHandler->handle()` returns); rename `$resources` (`:33`) to `$resource`; replace the `@var PaymentProviderInterface $formData` docblock (`:37-40`) with `assert($formData instanceof PaymentProviderInterface)`.
  - `DependencyInjection/CoreShopPayumPaymentExtension.php`, `DependencyInjection/Compiler/RegisterPaymentSettingsFormsPass.php`, `Event/PaymentProviderSupportsEvent.php`, `Resolver/EventBasedPaymentProviderResolver.php`: make `final`.
  - Promoted constructor properties → `private readonly` in `EventBasedPaymentProviderResolver:29-32`, `PaymentProviderSupportsEvent:29-32`, `CryptedGatewayConfigTypeExtension:32-34`, `GatewayConfigType:34`.
  - `PaymentProviderSupportsEvent:31`: fully-qualified `\CoreShop\Component\Resource\Model\ResourceInterface` although it is imported on `:22`.
  - `Events.php:23`: typed class constant `public const string SUPPORTS_PAYMENT_PROVIDER` (PHP 8.3+).
  - Closures that do not use `$this` → `static fn` / `static function` (`PaymentProviderChoiceTypeExtension:32`, `PaymentProviderTypeExtension:35`); add `: void` to the form event listeners.
  - Add `#[\Override]` to every implemented interface / parent method (`process()`, `getConfigTreeBuilder()`, `load()`, `buildForm()`, `configureOptions()`, `getExtendedTypes()`, `resolvePaymentProviders()`, `build()`, `registerDependentBundles()`, the `PimcoreBundleInterface` methods).

### I-03 — Remove dead and misleading code
- **Where / Fix:**
  - `Form/Extension/PaymentProviderTypeExtension.php:51-54`: `getExtendedType()` was removed from `FormTypeExtensionInterface` in Symfony 5; only `getExtendedTypes()` is used. Delete it.
  - `Form/Extension/PaymentProviderChoiceTypeExtension.php:33`: copy-pasted Symfony docs comment ("adds a class like attending_yes, attending_no") – replace with what it does (adds `data-factory` used by `FrontendBundle/Resources/public/static/js/shop.js:16`).
  - `CryptedGatewayConfigTypeExtension.php:47-49`, `:62-64`: `@var GatewayConfigInterface` docblocks placed after the assignment and contradicting the `instanceof CryptedInterface` check; check `instanceof GatewayConfigInterface && instanceof CryptedInterface` instead.
  - `@psalm-suppress` annotations (`PaymentProviderTypeExtension:43`, `GatewayConfigType:50`, `:55`): the project uses PHPStan; `FormTypeRegistry::has()/get()` are not deprecated. Remove them, fix the underlying types (**I-08**).
  - `GatewayConfigType:51-56`: `has()` + `get()` double lookup; `get()` already returns `null` when missing.
  - `Resources/config/services.php:24-26`: the comment "YAML does not allow nullOnInvalid" is wrong – YAML supports `'@?payum.dynamic_gateways.cypher'` (ignore-on-invalid → `null` for arguments). Move the definition into `services/form.yml` and drop the second loader in `CoreShopPayumPaymentExtension:33,38`, or convert all service files to PHP config.

### I-04 — Service definitions: visibility, duplicates, autowiring
- **Where / Fix:**
  - `Resources/config/services/payment.yml:2-3`: `_defaults: public: true` makes every service public. Only the controller (already public via `controller.service_arguments`) needs it; make the registries, resolver and HTTP client private.
  - Duplicate definitions across bundles (last loaded wins, confusing to maintain) **(dependent)**:
    - `coreshop.form_registry.payment.settings` also in `CoreBundle/Resources/config/services/payment.yml:5-6`.
    - `coreshop.form_registry.payum_gateway_config` also in `PayumBundle/Resources/config/services.yml:10-11` (PayumBundle TODO-2 **B-10**).
    - Route `coreshop_admin_payment_provider_config` also in `CoreBundle/Resources/config/pimcore/routing.yml:4-6` (and a third route with the same path in PaymentBundle, PaymentBundle TODO-2).
    - `Events::SUPPORTS_PAYMENT_PROVIDER` duplicated as `Component/Core/Events.php:23` with the same string.
    Keep each one only here, since this bundle owns the gateway-config concepts.
  - `EventBasedPaymentProviderResolver` arguments: use `.inner` via `service('.inner')` / `#[AutowireDecorated]`, and `event_dispatcher` via autowiring; the explicit `CoreShop\...EventBasedPaymentProviderResolver.inner` id is noise.
  - Add `methods: [GET]` to the `get-config` route.

### I-05 — Compiler passes: make the ordering dependency explicit and the data consistent
- **Where:** `CoreShopPayumPaymentBundle.php:52-53`, `RegisterGatewayConfigTypePass.php:55-58`, `RegisterPaymentSettingsFormsPass.php:28-33`.
- **Problem / Fix:**
  - `RegisterPaymentSettingsFormsPass` depends on the parameter set by `RegisterGatewayConfigTypePass` and only works because it is registered second with the same priority. Merge them, or register with explicit priorities.
  - `%coreshop.gateway_factories%` maps `type => type` for tagged factories but `offline => 'coreshop.payum_gateway_factory.offline'`, a service id that does not exist. All consumers only use the keys (`PaymentProviderController:49`, `CoreBundle/Form/Type/Checkout/PaymentType.php:92`); make it a plain list of factory names (or a consistent `name => label` map for the admin combo, where `type`/`name` are currently identical).
  - `RegisterGatewayConfigTypePass:29`: `has()` on a service defined in this very bundle is always true; drop the guard or keep it with a comment why.
  - Error message typo `RegisterPaymentSettingsFormsPass:45` "frontend-from".
  - See TODO-2 **B-11** for class resolution.

### I-06 — `pimcore/config.yml` overrides another bundle's config implicitly
- **Where:** `Resources/config/pimcore/config.yml:11-16` (sets `core_shop_payment.resources.payment_provider.classes.{model,admin_controller}`).
- **Problem / Fix:** relying on Pimcore's bundle-config auto-loading order to replace PaymentBundle's model/controller is fragile (CoreBundle sets the model again). Use `PrependExtensionInterface::prepend()` in `CoreShopPayumPaymentExtension` so the override is explicit, ordered, and still overridable by the application config.

### I-07 — Doctrine mappings: DBAL 4 / ORM 3 readiness and relation shape — (dependent)
- **Where:** `Resources/config/doctrine/model/*.orm.xml`.
- **Problem / Fix:**
  - `PaymentSecurityToken.orm.xml:6`: `type="object"` is deprecated in DBAL 3.4+ and removed in DBAL 4; it also `unserialize()`s database content (object-injection surface if the DB is ever writable by an attacker). Move to `json` (token details are a Payum `Identity` – store class + id) with a data migration (also tracked in PayumBundle TODO-3 for its dead copy).
  - `PaymentProvider.orm.xml`: `many-to-one` + `cascade-all` → `one-to-one` with unique join column, `cascade: persist` (see TODO-2 **B-10**); drop the empty `<join-column>` body; add the `xsi:schemaLocation` like Payum's files so XML mapping validation (ORM 3 default) works.
  - `GatewayConfig.orm.xml`: add the unique index on `gateway_name` (TODO-1 **C-04**).
  - The `table` attribute on `mapped-superclass` relies on CoreShop's superclass→entity conversion; document it in the file.
  - Column names `afterUrl` / `targetUrl` / `gatewayName` (camelCase) differ from Payum's snake_case mapping; only change together with a migration, otherwise leave a comment.

### I-08 — Model typing in `Component/PayumPayment` — (dependent)
- **Where:** `Component/PayumPayment/Model/{PaymentProviderInterface,PaymentProvider,GatewayConfig,PaymentSecurityToken}.php`.
- **Problem / Fix:**
  - `PaymentProviderInterface::setGatewayConfig(GatewayConfig $gatewayConfig)` takes the concrete class and has no return type; `getGatewayConfig()` is documented as non-null but the implementation returns `?GatewayConfig`. Use `setGatewayConfig(?GatewayConfigInterface $gatewayConfig): void` and `getGatewayConfig(): ?GatewayConfigInterface` (Payum's interface) – this removes the psalm suppressions here and the null bugs (TODO-1 **C-02**, TODO-2 **B-05**).
  - `GatewayConfig::$id`: `protected ?int $id = null;`.
  - `PaymentSecurityToken`: untyped properties/methods, odd empty multi-line constructor signature (`:58-59`), `getId(): ?int` always returns `null` although the identifier is the string `hash` – return the hash (`string|int|null` per `ResourceInterface`) or document why not.
  - Drop the `@psalm-suppress MissingConstructor` annotations once properties are typed with defaults.

### I-09 — Admin controller: do not round-trip secrets, and make decryption explicit
- **Where:** `Controller/PaymentProviderController.php:29-43`, `Form/Extension/CryptedGatewayConfigTypeExtension.php`.
- **Problem / Fix:**
  - `getAction` builds a full form only so that a `PRE_SET_DATA` listener decrypts the entity as a side effect. Replace with an explicit `GatewayConfigCypher` service (decrypt into an array, not into the entity; see TODO-2 **B-03**) used by both the controller and the form extension.
  - Treat secret fields (PayPal `password`, `signature`, Sofort `config_key`) as write-only: return a placeholder (e.g. `********`) instead of the stored value, and keep the stored value when the placeholder comes back on save. This also closes the remaining part of TODO-1 **C-01** and matches PaymentBundle TODO-3 (password-type fields in `paypal_express_checkout.js`).
  - Mark which config keys are secret in the gateway configuration form types (an option or an interface), so the masking is not hardcoded per gateway.

### I-10 — `EventBasedPaymentProviderResolver` / `PaymentProviderSupportsEvent` semantics
- **Where:** `Resolver/EventBasedPaymentProviderResolver.php:35-50`, `Event/PaymentProviderSupportsEvent.php:45-53`.
- **Problem / Fix:**
  - The event is a veto, but any later listener can call `setSupported(true)` and undo an earlier veto. Either replace the setter with `markUnsupported()` (one-way) or call `stopPropagation()` when a listener vetoes.
  - Nothing in `src/CoreShop` listens to `coreshop.payment_provider.supports`; document the extension point (README / docs) or drop it.
  - One event per provider per resolve call; the resolver is called several times per checkout page (`PaymentProviderChoiceType`, `PaymentType`, `PaymentProviderValidator`). Consider one event carrying the whole list, or memoising per request + subject.
  - Type-hint `Psr\EventDispatcher\EventDispatcherInterface` / Symfony Contracts instead of the component interface.
  - Good place to drop providers without a usable gateway config (TODO-1 **C-02**).

### I-11 — `payum.yml`: the static `offline` gateway is only reachable through Payum's deprecated fallback
- **Where:** `Resources/config/pimcore/payum.yml:13-15`; `vendor/payum/payum/src/Payum/Core/Registry/DynamicRegistry.php:72-89`.
- **Problem / Fix:** `DynamicRegistry::getGateway()` checks dynamic gateway configs first and only falls back to static gateways via the `@deprecated` backward-compatibility path ("will throw invalid argument exception in 2.x"). The static `offline` gateway is therefore only used if no provider has gateway name `offline`, and disappears with Payum 2. Remove it (offline providers are dynamic configs with `factoryName = offline`) or document why it is needed.

### I-12 — `Configuration.php` tightening
- **Where:** `DependencyInjection/Configuration.php:38-88`.
- **Problem / Fix:**
  - `driver` accepts any scalar although only `doctrine/orm` is supported (`CoreShopPayumPaymentBundle::getSupportedDrivers()`); use `enumNode('driver')->values([CoreShopResourceBundle::DRIVER_DOCTRINE_ORM])`.
  - `factory` nodes lack `cannotBeEmpty()` (model/controller have it).
  - `options` is an untyped `variableNode`; document or remove.
  - The two resource sections are identical except for the model; build them with a small helper to avoid copy-paste.
  - Consider adding an `encryption` / `cypher_key` option here that is forwarded to Payum via `prepend()` (TODO-2 **B-01**).

### I-13 — Tests and static analysis
- **Where:** the bundle has no tests.
- **Fix:** add at least
  - unit tests for both compiler passes (tag without `type`, duplicate tags, parameterised classes, the `offline` default);
  - form tests for `GatewayConfigType` + `CryptedGatewayConfigTypeExtension` (new provider first save, encrypt/decrypt round trip with `null`/`int`/`bool`, no entity mutation on display);
  - a kernel test that boots the container and instantiates every public service (would have caught TODO-1 **C-03**);
  - a serializer test for provider list/detail and order JSON (TODO-1 **C-01**);
  - PHPStan level ≥ 6 on the bundle and `Component/PayumPayment` with `phpstan-doctrine` and `phpstan-symfony`.

### I-14 — Documentation
- **Where:** `README.md`.
- **Fix:** the README is the generic CoreShop text (with an `http://www.coreshop.org` link and "It is build from"). Document what the bundle provides: the `coreshop.gateway_configuration_type` and `coreshop.payment.form.settings` tags and their attributes (`type`, `payum-factory`), the `coreshop.payment_provider.supports` event, the encryption setup (TODO-2 **B-01**), and the required dependent bundles (TODO-2 **B-09**).
