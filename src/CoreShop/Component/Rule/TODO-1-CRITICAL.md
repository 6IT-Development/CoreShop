# Rule Component – TODO 1: Critical errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: every PHP file of `src/CoreShop/Component/Rule` (17 files) and `composer.json`, plus the config files that wire or map this component: `RuleBundle/Resources/config/doctrine/model/{Condition,Action}.orm.xml`, `RuleBundle/Resources/config/serializer/Model.{Condition,Action}.yml`, `RuleBundle/Resources/config/services/*.yml`, `RuleBundle/DependencyInjection/Compiler/TraceableValidationProcessorPass.php`, the eight `RuleConditionsValidationProcessor` service definitions (Notification, CartItemPriceRule, CartPriceRule, PaymentProviderRule, ShippingRule, ProductPriceRule, ProductSpecificPriceRule, ProductQuantityPriceRules) and the six `NestedConditionChecker` service definitions (OrderBundle, PaymentBundle, ShippingBundle, ProductBundle ×2, ProductQuantityPriceRulesBundle), and the rule entity mappings that point to `ConditionInterface` / `ActionInterface`.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4.21, Doctrine ORM 2.20.13, DBAL 3.10.6, Collections 2.6.0). Items marked **(dependent)** have their root cause or part of the fix outside this component. Items marked **(verified)** were checked with a PHP 8.4.27 script against the component code and the vendored libraries. Items marked **(plausible)** follow from the code, but the trigger depends on data or configuration.

"Critical" here means: shop-wide outage, wrong prices or data loss on a common path, or a blocker for Symfony 7.4 / PHP 8.4.

**No Symfony 7.4 / PHP 8.4 blocker was found in this component.** The code runs on PHP 8.4 without deprecations from the component itself (the DBAL `array` type deprecation is tracked in RuleBundle TODO-3 **I-03**).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Condition evaluation

### C-01 — A stored condition whose type has no registered checker throws on every evaluation of that rule. Product pages, carts and checkout then fail with HTTP 500 — (verified, trigger plausible)
- **Where:** `Condition/RuleConditionsValidationProcessor.php:58-64` (`$this->ruleRegistry->get($condition->getType())`, with no guard). The same path is used for nested children through `Condition/NestedConditionChecker.php:37`. **(dependent)** `Component/Registry/ServiceRegistry.php` `get()` throws `NonExistingServiceException`.
- **Problem:**
  - The `type` column is free text (`RuleBundle/Resources/config/doctrine/model/Condition.orm.xml:10`), and nothing on load checks that a registered checker exists for it. A rule keeps its stored condition type when:
    - a bundle or custom condition checker is removed or disabled,
    - a condition type is renamed in a service tag,
    - a condition is saved through another rule type's registry (ProductQuantityPriceRulesBundle TODO-2: the nested form validates against the specific-price list), or
    - a database or version restore brings back an old type.
  - From then on, `isConditionValid()` throws `NonExistingServiceException` (reproduced: `Cond service "gone" does not exist …`). Rule evaluation runs on the product price path (product page, listing, index), the cart processor (every cart action) and checkout. One bad active rule is enough for a shop-wide 500.
  - The registry message lists every available type, so it can also reach logs and error pages.
- **Fix:**
  - In `isConditionValid()`, check `$this->ruleRegistry->has($type)` first. When the type is missing, **fail closed**: log a `critical` entry with the rule id, the condition id and the type, and return `false`. Use an injected `LoggerInterface`, which is optional so the component stays framework-free. Do not return `true`: inside a `not` nested condition the result is inverted, so the rule must be invalidated at rule level. Throw a dedicated `UnknownConditionTypeException` that `isValid()` catches and turns into "rule not valid".
  - Add a check on save: the rule forms already know the registry. RuleBundle should validate that every condition type, nested children included, exists in the registry of the rule's own type (see TODO-3 **I-06** for the recursive walker).
  - Add a console or health check (`coreshop:rules:check-availability` is a natural place) that lists active rules with unknown condition or action types.
- **Related:** RuleBundle TODO-1 **C-01** is the other path to the same kind of crash: nested children come back as arrays after a version restore and raise a `TypeError` in this component (`NestedConditionChecker.php:37` → `isConditionValid(ConditionInterface …)`). The defensive part of that fix belongs to this component and is tracked here as TODO-2 **B-01**. It is not repeated here.

---

## Overlap with other TODOs (not repeated here)

- RuleBundle TODO-1 **C-01**: a version or recycle-bin restore turns nested `Condition` objects into arrays, and `NestedConditionChecker` then throws a `TypeError` (dependent: this component).
- RuleBundle TODO-2 **B-04**: in debug, `TraceableRuleConditionsValidationProcessor` does not stop at the first failed condition and its service is shared. The component part of the fix is in TODO-3 **I-09**.
- RuleBundle TODO-2 **B-05**: `TraceableRuleConditionsValidationProcessor::$processed` grows without limit (no `ResetInterface`).
- RuleBundle TODO-3 **I-03**: the DBAL `array` column type for `configuration` is deprecated and removed in DBAL 4, and it `unserialize()`s content from the database.
- ProductQuantityPriceRulesBundle TODO-2: a nested quantity condition is validated against the wrong registry. This is one trigger of **C-01**.
