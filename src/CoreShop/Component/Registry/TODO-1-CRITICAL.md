# Registry Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all 11 PHP files in this component (`Autoconfiguration`, `ExistingServiceException`, `NonExistingServiceException`, `PrioritizedCompositeServicePass`, `PrioritizedServiceRegistry(Interface)`, `PriorityMap`, `RegisterRegistryTypePass`, `RegisterSimpleRegistryTypePass`, `ServiceRegistry(Interface)`), `composer.json`, `README.md`. Also checked: the code and config that depend on it:
the 59 `Autoconfiguration::registerForAutoConfiguration()` calls in `Bundle/*/DependencyInjection/CoreShop*Extension.php`, the 63 subclasses of `RegisterRegistryTypePass` / `RegisterSimpleRegistryTypePass` / `PrioritizedCompositeServicePass` in `Bundle/*/DependencyInjection/Compiler/*`, the `#[As…]` attribute classes in `Bundle/*/Attribute/*`, the registry definitions in `Bundle/*/Resources/config/services*.yml` / `services/*.yml`, `PimcoreBundle/DependencyInjection/Compiler/RegisterTypeHintRegistriesPass.php`, `Component/Order/Checkout/{CheckoutManager,DefaultCheckoutManagerFactory}.php`, `CoreBundle/Twig/CheckoutIdentifierExtension.php`, `FrontendBundle/Controller/CheckoutController.php`.

Reference for API checks: `vendor/` = `../pimcore2teszt10211524/vendor/` (`symfony/dependency-injection` 7.4.21; `vendor/six-it/core-shop/src/CoreShop/Component/Registry` is identical to this folder) and the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml` (+ `...Deprecations.log`).

"Critical" here means: a security issue, money charged or stored wrongly, data loss or data written wrongly, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have part of the fix outside this component. Items marked **(reproduced)** were checked with a PHP 8.4.27 script against the repo classes (`ContainerBuilder` from the vendor). Items marked **(verified)** were confirmed by reading the code paths end to end. Items marked **(plausible)** follow from the code but were not reproduced against a running shop.

**Symfony 7.4 / PHP 8.4 compatibility:** no blockers. `php -l` (PHP 8.4.27) passes on all 11 files. No implicit-nullable parameters. The dev `Deprecations.log` has no entries for `CoreShop\Component\Registry\*`. All used DI APIs (`registerAttributeForAutoconfiguration`, `registerAliasForArgument`, `findTaggedServiceIds`, `Container::underscore`) exist unchanged in 7.4. Modernisation items are in TODO-3.

**This component is the root cause of several items already filed in bundle TODOs** as "(dependent)". They are owned here; the bundle items should be closed when the fix below lands. See the overlap list at the end of each file.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Attribute autoconfiguration

### C-01 — (reproduced, dependent) `Autoconfiguration::getProperties()` emits camelCase tag keys, but every compiler pass reads dashed keys: attribute registration either breaks container compilation or silently drops the form type
- **Where:** `Autoconfiguration.php:49-54`, `:57-72` (`getFormType()` → `formType`, `getNotificationType()` → `notificationType`); `RegisterRegistryTypePass.php:58` (reads `$tag['form-type']`); `NotificationBundle/DependencyInjection/Compiler/AbstractNotificationRulePass.php:65`, `:94` (reads `notification-type`, `form-type`).
- **Problem:**
  - The YAML tags in all bundles use `form-type` / `notification-type`. The attribute path writes `formType` / `notificationType`. No pass reads the camelCase spelling.
  - For every `#[As…RuleConditionChecker(formType: …)]` / `#[As…RuleActionProcessor(formType: …)]` (Payment, Shipping, Order cart rules, cart-item rules, Product price rules, ProductQuantityPriceRules, Notification), the checker/processor is registered, but no form is added to the form registry. The admin shows the element, and saving its configuration fails.
  - For the Notification attributes, the missing `notification-type` makes `AbstractNotificationRulePass` throw during `ContainerBuilder::compile()`. One attributed service in the project and `cache:clear`, every console command and every HTTP request fail (NotificationBundle **C-01**, reproduced there).
  - With `autoconfigure_with_attributes: true` (a documented bundle option), the whole attribute feature is broken for rule elements.
- **Fix:** fix it centrally, in one change:
  - Normalise the keys in `Autoconfiguration` (map camelCase getters to the dashed tag keys the passes expect, e.g. via an explicit key map or `strtolower(preg_replace('/[A-Z]/', '-$0', $key))` for multi-word keys), **or** make `RegisterRegistryTypePass` (and `AbstractNotificationRulePass`) accept both spellings (`$tag['form-type'] ?? $tag['formType'] ?? null`).
  - Prefer an explicit contract over reflection: let the attributes implement a `TagAttributesProviderInterface::toTagAttributes(): array` that returns the exact dashed keys (see TODO-3 **I-07**).
  - Add a `ContainerBuilder` test per attribute that compiles a container with one attributed service and asserts the registry and form-registry method calls (TODO-3 **I-12**).

---

## Composite services

### C-02 — (reproduced pass behaviour, plausible runtime) `PrioritizedCompositeServicePass` adds decorators, duplicates and the composite itself to the composite: a decorated context recurses until the stack overflows on every request
- **Where:** `PrioritizedCompositeServicePass.php:46-54`, `:65-81`; together with `Autoconfiguration.php:33-40` (`registerForAutoconfiguration($interface)->addTag($tag)`, the default mode). Consumers: the `*ContextPass` / `CompositeRequestResolverPass` classes in Locale, Currency, Customer, Store, Address, StorageList, Order (cart context) bundles.
- **Problem:**
  - With the default `autoconfigure_with_attributes: false`, **every** autoconfigured service that implements the context interface gets the composite's tag. Symfony's default `config/services.yaml` has `autoconfigure: true`.
  - An app that decorates the context (`decorates: CoreShop\…\LocaleContextInterface`, the usual way to cache or override it) gets its decorator tagged. The pass adds it to the composite it wraps → `decorator → composite → decorator → …` → `Maximum function nesting level` / segfault on every request that resolves the locale, currency, store, customer or cart.
  - A service that has an explicit tag with a priority **and** is autoconfigured gets two tags. Reproduced: one `add($service, 5)` and one `add($service)` call, so the service is in the queue twice (called twice, once at priority 0).
  - A composite that carries the tag itself is added to itself (reproduced: 1 method call on `composite` with `Reference('composite')`).
- **Fix:** in `injectTaggedServicesIntoComposite()`:
  - skip `$id === $this->compositeId` and the id the `serviceId` alias points to;
  - skip definitions with `getDecoratedService() !== null`;
  - de-duplicate per service id (keep the highest explicit priority; ignore attribute-less autoconfigure tags when an explicit tag exists).
  - Document that decorators of context interfaces need `autoconfigure: false` until this is fixed.
  - Same root cause as `LocaleBundle/TODO-2-BUGS.md` **B-04**, `CurrencyBundle/TODO-2-BUGS.md` **B-18**, `StoreBundle/TODO-2-BUGS.md` **B-04**, `CustomerBundle/TODO-3-IMPROVEMENTS.md` **I-11**. Fix once here and close those.

---

## Already tracked elsewhere (not repeated)
- `Bundle/NotificationBundle/TODO-1-CRITICAL.md` **C-01** (compile failure – consumer side of **C-01**).
- `Bundle/PaymentBundle/TODO-2-BUGS.md` **B-03**, `Bundle/ShippingBundle/TODO-2-BUGS.md` **B-01**, `Bundle/ProductQuantityPriceRulesBundle/TODO-2-BUGS.md` **B-02**, `Bundle/OrderBundle/TODO-2-BUGS.md` **B-38** (`formType` dropped – consumer side of **C-01**).
- `Bundle/ShippingBundle/TODO-1-CRITICAL.md` **C-04** (action autoconfiguration on the wrong interface – bundle config, not this component).
- `autoconfigure_with_attributes` is a `scalarNode` in many bundles and reaches `Autoconfiguration::registerForAutoConfiguration(bool …)` under `strict_types` → `TypeError`: `AddressBundle` TODO-2 **B-14**, `CurrencyBundle` TODO-2 **B-13** (sub-item), `CustomerBundle` TODO-2 **B-10**, `LocaleBundle` TODO-2 **B-05**, `PimcoreBundle` TODO-2 **B-16**, `RuleBundle` TODO-2 **B-06**, `SEOBundle` TODO-2 **B-03**, `StoreBundle` TODO-2 **B-05**, `ProductQuantityPriceRulesBundle` TODO-2 **B-03**, `ShippingBundle` TODO-3 **I-02**, `CoreBundle` TODO-3 **I-10**. The fix belongs in each bundle's `Configuration` (`booleanNode()`); this component's `bool` signature is correct.
