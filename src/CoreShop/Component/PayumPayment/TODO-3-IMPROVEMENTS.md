# PayumPayment Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, PHP 8.4 modernisation and forward-compatibility items (Payum 2, Doctrine DBAL 4 / ORM 3). None of them is a functional bug on its own.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have part of the fix outside this component. Items marked **(verified)** were confirmed against the vendored sources or the compiled container.

Items already tracked in `PayumPaymentBundle/TODO-3-IMPROVEMENTS.md` **I-07** (DBAL `object` type for token `details`, `one-to-one` relation shape) and **I-08** (interface / property typing) are not repeated; the items below add what those do not cover.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — `composer.json` does not describe this package
- **Where:** `composer.json:4`, `:26-36`, `:41-57`.
- **Problem / Fix:**
  - `"description": "CoreShop Payment Component."` is copied from `coreshop/payment`; use `"CoreShop Payum Payment Component (Payum models for payment providers, gateway configs and security tokens)."`.
  - `symfony/http-client ^7.4` is required but nothing in this component uses it (the component only has 4 model classes). Move it to the package that wires the HTTP client (`PayumPaymentBundle`, see its TODO-1 **C-03**) or drop it.
  - Directly used but undeclared: `coreshop/resource` (`ResourceInterface`, `SetValuesTrait` in all models) — currently only transitive through `coreshop/payment`. Declare `"coreshop/resource": "^4.0"`.
  - `"php": "~8.4.0"` blocks PHP 8.5 although nothing here is version-specific; use `"^8.4"` (align with the monorepo policy).
  - `payum/payum ^1.7`: the 1.x line is the last with the untyped interfaces this code relies on; add a note / conflict for `payum/payum >=2` until I-02 is done.
  - `require-dev`: PHPStan 1.x is EOL → `phpstan/phpstan ^2.1`, `phpstan/phpstan-doctrine ^2`, `phpstan/phpstan-webmozart-assert ^2`; add `phpunit/phpunit ^11` for the tests in I-07.
  - `"minimum-stability": "dev"` and the path `repositories` entry only matter inside the monorepo; remove them from the split package.

### I-02 — (verified) Native types and `#[\Override]` on `PaymentSecurityToken`, ahead of Payum 2
- **Where:** `Model/PaymentSecurityToken.php:33-116`. Symfony's `DebugClassLoader` reports 6 deprecations for this class in `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainerDeprecations.log`: `setDetails()` might add `void`, `getDetails()` `?object`, `getHash()` / `getTargetUrl()` / `getAfterUrl()` / `getGatewayName()` `string`.
- **Problem / Fix:**
  - When Payum adds these native return types, the current untyped implementations become a fatal "Declaration must be compatible" error. Add them now (return-type covariance makes this safe on Payum 1.7): `setDetails(mixed $details): void`, `getDetails(): ?object` (Payum stores an `IdentityInterface`), `getHash(): string`, `getTargetUrl(): string`, `getGatewayName(): string`, setters `: void` with typed parameters. `getAfterUrl()`: Symfony predicts `string`, but the column is nullable and Payum's own `Token` returns `null` for tokens without after-URL; a `?string` override would be **incompatible** with a future `string` parent type, so decide explicitly (store `''` instead of `null`, or keep it untyped with `@return string|null` until Payum's final signature is known).
  - Typed properties: `protected string $hash`, `protected ?object $details = null`, `protected ?string $afterUrl = null`, `protected ?string $targetUrl = null` (mapping is `nullable="true"`, see I-05), `protected string $gatewayName`. Remove the `@var` docblocks and `@psalm-suppress MissingConstructor`.
  - Mark all interface implementations with `#[\Override]` (PHP 8.3+), in all 4 classes.
  - Fix the odd empty multi-line constructor signature (`:58-59`, `public function __construct(\n        ) {`) → `public function __construct()`. Same pattern in `Component/Core/Model/PaymentProvider.php:32-33` and `Component/Payment/Model/PaymentProvider.php` (**dependent**).

### I-03 — `GatewayConfig`: typed id and explicit encryption API
- **Where:** `Model/GatewayConfig.php:30-40`.
- **Problem / Fix:**
  - `/** @var int */ protected $id;` while `getId(): ?int` → `protected ?int $id = null;` (allowed: `$id` is not declared in the Payum parent).
  - Redeclare `protected $config = [];` / `protected $decryptedConfig = [];` (untyped, see TODO-1 **C-01**) and add `isEncrypted(): bool`, so callers stop poking at `getConfig()['encrypted']`.
  - Override `encrypt()` / `decrypt()` here with value normalisation (tracked as `PayumPaymentBundle/TODO-2-BUGS.md` **B-02**), so the model owns its storage format.
  - Implement `\Stringable` (`gatewayName`) for admin / log output, consistent with `PaymentProvider`.

## Code quality and readability

### I-04 — `PaymentProvider`: remove the duplicate `__toString()` and add a null-safe factory accessor
- **Where:** `Model/PaymentProvider.php:26`, `:40-43`; callers **(dependent)**: `PayumPaymentBundle/Form/Extension/PaymentProviderChoiceTypeExtension.php:32-35`, `PayumPaymentBundle/Form/Extension/PaymentProviderTypeExtension.php`, `CoreBundle/Form/Type/Checkout/PaymentType.php:81`, `:148`, `PayumBundle/Controller/PaymentController.php:144-165`.
- **Problem / Fix:**
  - `__toString()` and `implements \Stringable` repeat exactly what the parent `Component/Payment/Model/PaymentProvider.php` already declares; delete both.
  - The chain `$provider->getGatewayConfig()->getFactoryName()` / `->getGatewayName()` appears in at least 5 places, each one a null-dereference risk (`PayumPaymentBundle` TODO-1 **C-02**, TODO-2 **B-05**). Add `getGatewayFactoryName(): ?string` and `getGatewayName(): ?string` to `PaymentProviderInterface` / `PaymentProvider` (`return $this->gatewayConfig?->getFactoryName();`) and `hasGatewayConfig(): bool`, then use them at the call sites.
  - `setGatewayConfig()` should also accept `null` (explicit detach), see `PayumPaymentBundle` TODO-3 **I-08**.

### I-05 — Align `PaymentSecurityToken` with Payum's token contract and mapping — (dependent)
- **Where:** `Model/PaymentSecurityToken.php`, `PayumPaymentBundle/Resources/config/doctrine/model/PaymentSecurityToken.orm.xml:5-9`, Payum reference `vendor/payum/payum/src/Payum/Core/Bridge/Doctrine/Resources/mapping/Token.orm.xml`.
- **Problem / Fix:**
  - `targetUrl` is `nullable="true"` here but `nullable="false"` in Payum's mapping, and Payum always sets it. Make it `NOT NULL` (migration) and type it `string`.
  - The `hash` id has no explicit `column` and is `length="100"` while `Random::generateToken()` produces 43 characters; fine, but add `column="hash"` and a short comment so it stays aligned with the dead PayumBundle copy's removal (`PayumBundle` TODO-3 **I-03**).
  - Consider extending `Payum\Core\Model\Token` instead of re-implementing it (identical behaviour; keeps future Payum fixes), adding only `ResourceInterface` + the overrides from TODO-2 **B-02** / **B-04**.
  - Token `details` → `json` storage (`PayumPaymentBundle` TODO-3 **I-07**): on the model side, store `['class' => ..., 'id' => ...]` and rebuild an `Identity` in `getDetails()`, so no `unserialize()` is involved.

### I-06 — Avoid N+1 gateway-config loads on the checkout payment step — (dependent)
- **Where:** `PayumPaymentBundle/Resources/config/doctrine/model/PaymentProvider.orm.xml:8` (lazy `many-to-one`), `CoreBundle/Doctrine/ORM/PaymentProviderRepository.php:27-38` (`findActiveForStore()` without join), `PayumPaymentBundle/Form/Extension/PaymentProviderChoiceTypeExtension.php:32-35` (`choice_attr` reads `getGatewayConfig()->getFactoryName()` per provider).
- **Problem / Fix:** rendering the payment step triggers one extra query per active provider to load its gateway config. Add `->leftJoin('o.gatewayConfig', 'gc')->addSelect('gc')` in `findActiveForStore()` (or `fetch="EAGER"` on the relation once it is `one-to-one`).

## Tests and documentation

### I-07 — No tests for this component
- **Where:** component root (no `Tests/` directory, no `phpunit` in `composer.json`).
- **Fix:** add unit tests for:
  - `GatewayConfig`: constructor-less hydration + encrypted config (TODO-1 **C-01**), `encrypt()` / `decrypt()` round trip with `null` / `int` / `bool` / nested values (`PayumPaymentBundle` TODO-2 **B-02**).
  - `PaymentSecurityToken`: 43-character URL-safe hash on construction, `getId()` returns the hash (TODO-2 **B-04**), `setValues()` cannot change `hash` / URLs (TODO-2 **B-02**).
  - `PaymentProvider`: null-safe accessors from I-04, `setGatewayConfig(null)`.
  - Add the component to the PHPStan config (level ≥ 6) after I-02 / I-03 so the remaining psalm suppressions can go.

### I-08 — README does not describe the component
- **Where:** `README.md:1-24`.
- **Fix:** document the three models and what they are for (Payum dynamic-gateway config storage, Payum token storage, payment provider ↔ gateway relation), that `GatewayConfig` supports encryption via `payum.dynamic_gateways.encryption.defuse_secret_key`, that `PaymentSecurityToken` hashes are bearer credentials (never log them), how to override the models (after TODO-2 **B-05**), and unify the link targets (the README mixes `coreshop.com` and `coreshop.org`).
