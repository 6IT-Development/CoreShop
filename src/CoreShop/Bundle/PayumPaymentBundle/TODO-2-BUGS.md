# PayumPaymentBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs that are not critical by the definition in TODO-1: they break one feature, one admin flow, or an opt-in setup, or they need an unusual action to trigger.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by reading the vendored library source or the compiled container.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Gateway config encryption

### B-01 — Gateway credentials are stored in plain text; the encryption extension is inert — (verified, security hardening)
- **Where:** `Resources/config/services.php:30-33` (`payum.dynamic_gateways.cypher` with `nullOnInvalid()`), `Form/Extension/CryptedGatewayConfigTypeExtension.php:39-41`, `Resources/config/pimcore/payum.yml:9-11` (no `encryption` key under `dynamic_gateways`).
- **Problem:** Payum only defines `payum.dynamic_gateways.cypher` when `payum.dynamic_gateways.encryption.defuse_secret_key` is configured. Nothing configures it, so the compiled container passes `null` to `CryptedGatewayConfigTypeExtension` and the extension returns immediately. PayPal API username / password / signature and the Sofort config key are stored as clear JSON in `coreshop_payment_gateway_config.config`, and end up in DB dumps, backups and staging copies. Together with TODO-1 **C-01** they are also sent to the browser in clear text.
- **Fix:**
  - Configure `payum.dynamic_gateways.encryption.defuse_secret_key: '%env(PAYUM_CYPHER_KEY)%'` (document key generation with `vendor/bin/generate-defuse-key`).
  - Ship a console command / migration that encrypts existing rows (load, `setConfig(getConfig())`, `encrypt()`, flush).
  - Fix **B-02** and **B-03** first, otherwise enabling encryption breaks the admin.
  - If encryption stays optional, log a warning at container build time when it is off.

### B-02 — Once encryption is enabled, saving a gateway config with an empty optional field or a non-string value throws a `TypeError` — (verified)
- **Where:** `Form/Extension/CryptedGatewayConfigTypeExtension.php:59-73` → `Payum\Core\Model\GatewayConfig::encrypt()` (`vendor/payum/payum/src/Payum/Core/Model/GatewayConfig.php`, encrypt loop) → `Defuse\Crypto\Crypto::encrypt()` (`vendor/defuse/php-encryption/src/Crypto.php:21-27`).
- **Problem:** Payum's `encrypt()` only skips the key `encrypted` and `bool` values; everything else goes to `Crypto::encrypt()`, which throws `TypeError('String expected for argument 1. Null given instead.')` for anything that is not a string. An empty `TextType` field submits `null`, an `IntegerType` gives `int`, a nested option gives `array`. The admin save then returns a 500 and the provider cannot be saved. `decrypt()` has the same problem for values stored before encryption was switched on.
- **Fix:** normalise the config before encrypting: drop `null` values, keep `bool`, cast `int`/`float` to string, JSON-encode arrays (and decode them after decrypt). Best done in an override of `encrypt()` / `decrypt()` in `Component/PayumPayment/Model/GatewayConfig.php` (**dependent**), so Payum's runtime decrypt sees the same format. Add a round-trip test with `null`, `int`, `bool` and nested values.

### B-03 — Opening a provider in the admin decrypts the managed entity in place; any flush in the same request writes clear text flagged as encrypted — (plausible)
- **Where:** `Form/Extension/CryptedGatewayConfigTypeExtension.php:44-58` (`PRE_SET_DATA`: `decrypt()` then `setConfig($gatewayConfig->getConfig())`), `Controller/PaymentProviderController.php:29-43` (builds the form only to trigger that listener).
- **Problem:**
  - After `decrypt()`, `getConfig()` returns `decryptedConfig`, which still contains `encrypted => true`. `setConfig()` then copies the clear values plus the `encrypted` flag into the persisted `config` property of the **managed** entity.
  - `getAction` does not flush, so today nothing is written. But any flush in the same request (a Doctrine/Pimcore listener, a future `ResourceController` change, a validation-failed `saveAction` followed by another write in the same EntityManager) persists clear text with `encrypted: true`. On the next payment, Payum calls `decrypt()` on clear text, Defuse throws `WrongKeyOrModifiedCiphertextException`, and the gateway is unusable until an admin re-enters every credential.
- **Fix:** never mutate the entity for display. In `getAction`, decrypt into a detached copy (or read `decryptedConfig` through a dedicated read model) and serialize that; in `PRE_SET_DATA`, call `decrypt()` only (it fills `decryptedConfig`, which `getConfig()` already returns) and drop the `setConfig()` call. Together with TODO-3 **I-09** (do not send stored secrets back at all).

---

## Admin flows

### B-04 — The first save of a new provider silently drops the gateway credentials — (plausible)
- **Where:** `Form/Type/GatewayConfigType.php:39-63`, `ResourceBundle/Form/Type/AbstractResourceType.php:37` (`allow_extra_fields: true`), `PaymentBundle/Resources/public/pimcore/js/provider/item.js:104-122`, `:158-160`, `:330-343`.
- **Problem:**
  - The `config` child is only added in `PRE_SET_DATA`, based on the **existing** gateway config's `factoryName`. A new provider (created by `addAction` with only an identifier) has no gateway config, so the form has no `config` field.
  - The admin picks a factory, fills in the credentials and saves. The JS posts `gatewayConfig.factoryName` and `gatewayConfig.config.*`; the server stores the factory name and, because extra fields are allowed, drops `config` without an error. The UI shows "saved" and keeps the typed values on screen (`resource/item.js:125` only replaces `this.data`). Payments then fail with missing credentials until the admin reopens and saves again.
- **Fix:** add a `PRE_SUBMIT` listener in `GatewayConfigType` that reads the submitted `factoryName` and adds the matching `config` type (same registry lookup as `PRE_SET_DATA`). Reject a `factoryName` that is not registered (see **B-06**). Add a functional test: create + save once → config persisted.

### B-05 — `PaymentProviderTypeExtension` dereferences a nullable gateway config and can write a `null` gateway name — (plausible)
- **Where:** `Form/Extension/PaymentProviderTypeExtension.php:35-47`.
- **Problem:**
  - `$paymentMethod->getGatewayConfig()` is `?GatewayConfig` (`Component/PayumPayment/Model/PaymentProvider.php:35`). When the submitted payload has no gateway config (provider saved without a factory, API clients, the clone flow), `->getGatewayName()` throws `Error: Call to a member function getGatewayName() on null` → 500 on save. The `@psalm-suppress DocblockTypeContradiction` hides exactly this, because `PaymentProviderInterface::getGatewayConfig()` is documented as non-null (`Component/PayumPayment/Model/PaymentProviderInterface.php:27-30`).
  - If `identifier` is empty, `setGatewayName(null)` is called. `gateway_name` is `NOT NULL` (`vendor/payum/payum/src/Payum/Core/Bridge/Doctrine/Resources/mapping/GatewayConfig.orm.xml:5`), so the flush fails with a DBAL exception instead of a form error.
- **Fix:** use `$paymentMethod->getGatewayConfig()?->...` and return early when there is none; only set the gateway name when a non-empty identifier exists (and see TODO-1 **C-04** for the naming rule). Fix the interface docblock / return type (TODO-3 **I-08**).

### B-06 — The gateway config has no validation at all, and its validation group is a project leftover (`fotobuch`) — (dependent)
- **Where:** `Resources/config/services/form.yml:1-2` (`coreshop.form.type.gateway_config.validation_groups: [fotobuch]`), `Form/Type/GatewayConfigType.php:42` (`factoryName` is a free `TextType`). There is no `validation/*.yml` for `GatewayConfig` anywhere in `src/CoreShop`.
- **Problem:**
  - `fotobuch` has been there since the bundle was introduced (`git log -S fotobuch` → `c9ae24f6e`). In the full CoreShop stack, PayumBundle's `[coreshop]` overrides it by load order (compiled container line ~4368; PayumBundle TODO-2 **B-10**). With this bundle alone, or with a different bundle order, constraints in the `coreshop` group of the gateway configuration forms are skipped.
  - Any string is accepted as `factoryName`, not only the keys of `%coreshop.gateway_factories%`. A typo or a removed factory is only detected at payment time (`Gateway factory "x" does not exist`).
  - Nothing requires a gateway config on an active provider (root cause of TODO-1 **C-02**).
- **Fix:** set `[coreshop]` here and remove the duplicate from PayumBundle; add constraints: `factoryName` `NotBlank` + `Choice(choices: <keys of coreshop.gateway_factories>)` (or make it a `ChoiceType` fed by that parameter), `gatewayName` `NotBlank` + unique; on the Payum provider model, a callback constraint "active ⇒ gateway config with factory".

### B-07 — `getConfigAction` has no permission check — (verified)
- **Where:** `Controller/PaymentProviderController.php:45-62`; compare `getAction` at `:31`.
- **Problem:** every other action calls `isGrantedOr403()`; this one does not, so any admin user can call `/admin/coreshop/payment_providers/get-config`. The data is only the list of factory names, so the impact is low, but it is inconsistent with the rest of the controller. `ResourceController::listAction()` has the same gap (**dependent**, part of TODO-1 **C-01**).
- **Fix:** call `$this->isGrantedOr403()` first; add the same check to `ResourceController::listAction()`.

---

## Configuration and wiring

### B-08 — `payum.yml` hardcodes the token and gateway-config classes, so the configurable models in `Configuration.php` are ignored by Payum — (plausible)
- **Where:** `Resources/config/pimcore/payum.yml:5-11`; `DependencyInjection/Configuration.php:56-83` (`resources.gateway_config.classes.model`, `resources.payment_security_token.classes.model`).
- **Problem:** the bundle lets a project replace both models, but Payum's token storage and dynamic-gateway config storage are always built for `CoreShop\Component\PayumPayment\Model\PaymentSecurityToken` / `GatewayConfig`. These are mapped as **mapped superclasses** (`doctrine/model/*.orm.xml`); CoreShop only turns the **configured** model into an entity. After an override, Payum's storages query a class that is not an entity (`MappingException: Class ... is not a valid entity or mapped super class`) and every payment fails. The payment storage on line 3 already uses the parameter form.
- **Fix:** use `"%coreshop.model.payment_security_token.class%"` and `"%coreshop.model.gateway_config.class%"` as keys, like line 3, and check in the compiled container that the generated storage service ids still resolve (the id is derived from the key).

### B-09 — The bundle cannot be used on its own, although that is its stated purpose — (plausible)
- **Where:** `CoreShopPayumPaymentBundle.php:41-46` (only registers `CoreShopPaymentBundle`), `Resources/config/pimcore/config.yml:1-9` (imports `payum.yml`, configures `jms_serializer`), `Resources/config/pimcore/routing.yml:1-3` (route to `coreshop.admin_controller.payment_provider`, which only exists because `config.yml:11-16` replaces PaymentBundle's admin controller).
- **Problem:** the introducing commit (`c9ae24f6e`) describes the bundle as the bridge for "non CoreShop projects". Without `CoreShopPayumBundle`, nobody registers `Payum\Bundle\PayumBundle\PayumBundle` (it is added in `PayumBundle/CoreShopPayumBundle.php:35`), so loading `payum:` from `payum.yml` fails with "There is no extension able to load the configuration for "payum"". The `offline` gateway factory actions also live in `PayumBundle`.
- **Fix:** register `PayumBundle` (and `JMSSerializerBundle` if not already registered by `ResourceBundle`) in `registerDependentBundles()`, and declare `payum/payum-bundle` accordingly in `composer.json` (TODO-3 **I-01**). Add a minimal kernel test that boots only `CoreShopPayumPaymentBundle` + its dependencies.

### B-10 — Deleting a provider deletes its gateway config immediately; in-flight payments, notifications and refunds for it then fail — (plausible)
- **Where:** `Resources/config/doctrine/model/PaymentProvider.orm.xml:8-15` (`many-to-one` + `cascade-all` + `on-delete="SET NULL"`).
- **Problem:**
  - `cascade-all` includes `remove`. Deleting a provider removes the gateway config row. Open payment tokens and existing payments reference the gateway by name (`DynamicRegistry::getGateway()`), so PayPal/Sofort return URLs, async notifications (IPN) and later refunds for orders paid through that provider throw `Gateway "x" does not exist`.
  - The relation is modelled as many-to-one, so a gateway config could legally be shared; removing one provider would then null the other providers' reference through `SET NULL`, which leads straight to TODO-1 **C-02**.
- **Fix:** model it as `one-to-one` (unique `gateway_config_id`) with `cascade: persist` only; prevent deletion of providers that have payments (or soft-disable them via `active = false`) and remove the gateway config only when no payment references it. See TODO-3 **I-07** for the mapping cleanup.

### B-11 — The compiler passes read `Definition::getClass()` unresolved, which yields wrong or empty types for parameterised, child or factory definitions — (plausible)
- **Where:** `DependencyInjection/Compiler/RegisterGatewayConfigTypePass.php:39-50`, `DependencyInjection/Compiler/RegisterPaymentSettingsFormsPass.php:50-51`.
- **Problem:**
  - `getClass()` returns `null` for a `ChildDefinition` that inherits the class, and a string like `%app.form.class%` for parameterised classes. In `RegisterGatewayConfigTypePass:43`, `strrchr(null, '\\')` is a PHP 8.1+ deprecation (`Passing null to parameter #1`) and `substr(false, 1)` another; the derived type becomes `''`. Both passes then register `null` or an unresolved `%...%` string as the form type, which fails only when the admin or checkout form is built.
  - `:39` uses `findDefinition($id)` but `:50` uses `getDefinition($id)`; the two differ for aliases.
  - Duplicate `type` / `payum-factory` tags silently overwrite each other in the registry.
- **Fix:** resolve the class with `$container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass())` and fall back to the service id when it is a class name; throw a clear `InvalidArgumentException` when no class can be resolved; require the `type` attribute instead of deriving it; throw on duplicates.
