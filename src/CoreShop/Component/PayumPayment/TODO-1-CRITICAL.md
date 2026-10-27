# PayumPayment Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all 4 PHP files in this component (`Model/GatewayConfig.php`, `Model/PaymentProvider.php`, `Model/PaymentProviderInterface.php`, `Model/PaymentSecurityToken.php`), `composer.json`, `README.md`. Also checked: the config and code that depend on it:
`PayumPaymentBundle/Resources/config/{doctrine/model/*.orm.xml,pimcore/{config,payum}.yml,services.php,services/payment.yml,serializer/Model.PaymentProvider.yml}`, `PayumPaymentBundle/{CoreShopPayumPaymentBundle,DependencyInjection/Configuration,Form/Extension/*}.php`, `PayumBundle/{CoreShopPayumBundle,Controller/PaymentController}.php`, `PayumBundle/Resources/config/{doctrine/model/*.orm.xml,pimcore/config.yml}`, `Component/Payment/Model/{PaymentProvider,PaymentProviderInterface}.php`, `Component/Core/Model/{PaymentProvider,PaymentProviderInterface}.php`, `Component/Resource/Model/{ResourceInterface,SetValuesTrait}.php`, `ResourceBundle/{AbstractResourceBundle,Controller/ResourceController}.php`, `CoreBundle/Doctrine/ORM/PaymentProviderRepository.php`, `CoreBundle/Fixtures/Data/Demo/PaymentProviderFixture.php`, `Behat/Context/Setup/PaymentContext.php`.

Reference for API checks: `vendor/` = `../pimcore2teszt10211524/vendor/` (`payum/payum` 1.7.7, `payum/payum-bundle` 2.7.2, `doctrine/dbal` 3.10.6, `doctrine/orm` 2.20.13, `symfony/*` 7.4.x) and the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml` (+ `...Deprecations.log`). The vendored `six-it/core-shop` copy of this component is identical to the working tree (`diff -r` is empty).

"Critical" here means: a security issue, money charged or stored wrongly, data loss or data written wrongly, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have their root cause or part of the fix outside this component. Items marked **(reproduced)** were checked with a PHP 8.4.27 script against the vendored classes. Items marked **(latent)** are not reachable with today's configuration but break as soon as a linked fix / setting lands.

**Symfony 7.4 / PHP 8.4 compatibility:** no blockers. `php -l` (PHP 8.4.27) passes on all 4 files; all classes load. The only Symfony-reported issues are 6 `DebugClassLoader` "might add a native return type" deprecations for `PaymentSecurityToken` (see TODO-3 **I-02**). The component itself has no Symfony code; `symfony/http-client` in `composer.json` is unused (TODO-3 **I-01**).

**Overlap with other TODOs:** `PayumPaymentBundle/TODO-*.md` already covers several items whose root cause lives here. They are **not repeated**, only referenced:
- `PayumPaymentBundle/TODO-1-CRITICAL.md` **C-02** (`PaymentProviderInterface::getGatewayConfig()` is documented non-null, implementation returns `?GatewayConfig` → checkout payment step crashes), **C-04** (no unique `gatewayName` → wrong merchant credentials).
- `PayumPaymentBundle/TODO-2-BUGS.md` **B-02** (`GatewayConfig::encrypt()` / `decrypt()` with non-string values; fix belongs in `Model/GatewayConfig.php`), **B-05** (null gateway config on save), **B-08** (configurable token / gateway config models not honoured), **B-10** (`many-to-one` + `cascade-all` on `gatewayConfig`).
- `PayumPaymentBundle/TODO-3-IMPROVEMENTS.md` **I-07** (`PaymentSecurityToken.details` uses DBAL `object` type; DBAL 4 removal + `unserialize()`), **I-08** (model typing in this component).
- `PayumBundle/TODO-3-IMPROVEMENTS.md` **I-03** (dead duplicate mappings for `CoreShop\Bundle\PayumBundle\Model\*`).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Money charged wrongly

### C-01 — (reproduced, latent) An encrypted `GatewayConfig` loaded through Doctrine returns `null` from `getConfig()` until someone decrypts it; `use_authorize` is then ignored and the shopper is **charged** instead of the amount being **authorized**
- **Where:** `Model/GatewayConfig.php:27-41` (extends `Payum\Core\Model\GatewayConfig` without any override). Base behaviour: `vendor/payum/payum/src/Payum/Core/Model/GatewayConfig.php` — `getConfig()` returns `$this->decryptedConfig` whenever `$this->config['encrypted']` is set; `decryptedConfig` is only initialised in `__construct()` and is **not mapped** (`vendor/payum/payum/src/Payum/Core/Bridge/Doctrine/Resources/mapping/GatewayConfig.orm.xml` maps only `gatewayName`, `factoryName`, `config`). **(dependent)** consumer: `PayumBundle/Controller/PaymentController.php:144-165` (`provideTokenBasedOnPayment()`).
- **Problem:**
  - Doctrine hydrates entities without calling the constructor, so for a stored row `decryptedConfig === null`. Payum only decrypts in two places: `CryptoStorageDecorator` (the dynamic-gateway config storage, used by `DynamicRegistry::getGateway()`) and the admin form extension. A `GatewayConfig` reached through the **relation** `PaymentProvider::getGatewayConfig()` is never decrypted.
  - Reproduced: `newInstanceWithoutConstructor()` + `config = ['encrypted' => true, 'use_authorize' => true, ...]` → `getConfig()` is `NULL`, `isset(getConfig()['use_authorize'])` is `false`.
  - `PaymentController::provideTokenBasedOnPayment()` reads `use_authorize` from exactly that relation **before** the gateway is built. With encryption enabled, every gateway that is configured to authorize (reserve) gets a **capture** token instead, i.e. the shopper's money is taken immediately; partial shipments / later cancellations then need refunds instead of voiding an authorization. Booleans are stored unencrypted by Payum, so the value is in the row, it is just not returned.
  - Any other code that reads `getConfig()` from the relation (custom gateway actions, admin serializers, the `getConfigAction`, project code) gets `null` instead of `array`, which turns into `TypeError`s for typed consumers (`array $config`).
  - **Latent** because encryption is not configured today (`PayumPaymentBundle/TODO-2-BUGS.md` **B-01** recommends enabling it) and no shipped form writes `use_authorize` (only manual / project config). Enabling encryption without this fix silently changes payment behaviour.
- **Fix:**
  - Override in `Model/GatewayConfig.php`: redeclare `protected $decryptedConfig = [];` (and `protected $config = [];`) **untyped with a default**. Defaults are applied by `newInstanceWithoutConstructor()`, so hydrated entities get `[]` instead of `null`. A typed redeclaration (`protected array $decryptedConfig`) is a fatal error because the parent property is untyped.
  - Make `getConfig()` fail loudly for an encrypted, not-yet-decrypted config (e.g. `throw new \LogicException('Gateway config "x" is encrypted and has not been decrypted.')`) instead of returning `null`, and add `isEncrypted(): bool` / `isDecrypted(): bool` helpers.
  - Expose non-secret flags without decryption: e.g. `GatewayConfig::getPlainOption(string $key): mixed` that reads booleans / non-secret keys from `config` directly, and use it in `PaymentController` for `use_authorize` (**dependent**), or decrypt via the injected `payum.dynamic_gateways.cypher` before reading.
  - Add a unit test: hydrated (constructor-less) encrypted config → `use_authorize` still `true`; and a functional test with encryption enabled → authorize token created.
