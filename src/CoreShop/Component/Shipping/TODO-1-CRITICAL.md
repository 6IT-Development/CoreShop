# Shipping Component – TODO 1: Critical errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-28 (branch `s74-p84`).
Scope: every PHP file of `src/CoreShop/Component/Shipping` (46 files) and `composer.json`, plus the config files that map or wire this component: `ShippingBundle/Resources/config/doctrine/model/{Carrier,CarrierTranslation,ShippingRule,ShippingRuleGroup}.orm.xml`, `ShippingBundle/Resources/config/serializer/Model.*.yml`, `ShippingBundle/Resources/config/validation/*.yml`, `ShippingBundle/Resources/config/services.yml`, `ShippingBundle/Resources/config/services/{shipping-rules,shipping-taxation,form}.yml`, and `CoreBundle/Resources/config/services/{shipping,cart-processor,checkout}.yml`. Call paths were followed into `Component/Rule` (`RuleValidationProcessor`, `RuleConditionsValidationProcessor`), `Component/Registry` (`ServiceRegistry`, `PrioritizedServiceRegistry`), `Component/Resource/Model/{TranslatableTrait,SetValuesTrait}.php` and the Core implementations in `Component/Core/Shipping/**`, `Component/Core/Order/Processor/CartShippingProcessor.php` and `Component/Core/Model/Carrier.php`.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4.x, laminas-stdlib 3.21.0). Items marked **(dependent)** have their root cause or part of the fix outside this component. Items marked **(reproduced)** were run with PHP 8.4.27 against the component code and the vendored libraries. Items marked **(plausible)** follow from the code, but the trigger depends on data or configuration.

"Critical" here means: shop-wide outage (cart, checkout), money charged wrongly or data written wrongly on a common path, or a blocker for Symfony 7.4 / PHP 8.4.

**No Symfony 7.4 / PHP 8.4 blocker was found.** All 46 files pass `php -l` on 8.4.27, and loading every class/interface/trait with `E_ALL` produces no deprecation. The component does not use Symfony directly. There is one silent **PHP 8.4 behaviour change** that alters shipping prices: `round()` no longer pre-rounds, so the percent actions are off by one cent in some cases (reproduced, TODO-2 **B-01**).

**Overlap with `ShippingBundle/TODO-*.md` (already tracked there, not repeated here):** bundle TODO-1 **C-01** (an empty postcode entry matches every postcode), **C-02** (a deleted referenced shipping rule turns conditions/actions into "false"/"0"), **C-03** (a rule group without a shipping rule → `TypeError` in `CarrierShippingRuleChecker`), TODO-2 **B-03** (calculator chain "last one wins"; `CarrierShippingRulePriceCalculator` returns `0` instead of "no price"), **B-04** (postcode whitespace / leading zeros / prefix overlaps / range expansion), **B-11** (carrier passed under key `0`), TODO-3 **I-12** (rules evaluated twice per request), **I-13** (small clean-ups in `PostcodeConditionChecker`, `AbstractConditionChecker`, models).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Data written wrongly

### C-01 — `Carrier::setTitle()` / `setDescription()` with a language write into the **fallback** locale's translation. The default language is overwritten and the other translations are never created — (reproduced) (dependent)
- **Where:** `Model/Carrier.php:102-105` (`setDescription()`), `:112-115` (`setTitle()`), `:189-195` (`getTranslation()` with `$useFallbackTranslation = true` by default). Root behaviour: `Component/Resource/Model/TranslatableTrait.php:58-93` returns the fallback translation when the requested locale does not exist yet. **(dependent)** callers: `CoreBundle/Fixtures/Data/Demo/CarrierFixture.php:73-74` (loops over all valid languages), `Behat/Context/Setup/ShippingContext.php:608`, and any project import or migration script that sets per-language carrier texts.
- **Problem:**
  - `setTitle('Standardversand', 'de')` calls `getTranslation('de')`. When no `de` translation exists yet but the fallback locale `en` does, the trait **returns the `en` translation**. The setter then overwrites the English title, and no `de` row is created.
  - Reproduced: `setFallbackLocale('en'); setTitle('Standard', 'en'); setTitle('Standardversand', 'de');` → the only translation is `en => Standardversand`, `count(getTranslations()) === 1`.
  - The demo fixture writes one title per language, so a demo install stores only the default-language row, and that row holds the **last** language's title and description (`Standard - <LAST LANG>`). Import scripts lose every non-default language and corrupt the default one. Checkout, order confirmation mails and invoices then show the wrong carrier name.
  - The admin is not affected, because the translation form writes into the `translations` collection by locale.
  - Same bug as `Component/Payment/TODO-1-CRITICAL.md` **C-01**, which already points to this file. Fix both the same way.
- **Fix:**
  - Setters: `$this->getTranslation($language, false)->setTitle($title);`, and the same for `setDescription()`. This matches `Component/Taxation/Model/TaxRate.php`, `Component/Address/Model/{Country,State}.php` and `Component/Product/Model/ProductUnit.php`, which already pass `false`.
  - Getters can keep the fallback (but see TODO-2 **B-08** for the side effect of reads).
  - Data repair note: for shops installed with the demo fixture or imported by script, check `coreshop_carrier_translation` for missing locales and re-import the texts.
  - Unit test with two locales for both setters (TODO-3 **I-13**).

---

## Money charged wrongly

### C-02 — The shipping price can become **negative**, and the shop then pays the customer for shipping — (reproduced) (dependent)
- **Where:** `Calculator/CarrierShippingRulePriceCalculator.php:61` (`return $price + $modifications;`, no lower bound), `Processor/ShippingRuleActionProcessor.php:71-88` (every modification is computed from the **base** `$price` and summed), `Rule/Action/DiscountPercentActionProcessor.php:29`. **(dependent)** `Component/Core/Shipping/Rule/Action/DiscountAmountActionProcessor.php` (`-1 * amount`, no cap), `Component/Core/Shipping/Calculator/TaxedCarrierPriceRuleCalculator.php:63-93` and `Component/Core/Order/Processor/CartShippingProcessor.php:105-127` (adds the result as `AdjustmentInterface::SHIPPING` without a check).
- **Problem:**
  - The form limits one `discountPercent` action to 0–100 %, but nothing limits the **sum**. Two discount actions of 60 % on a base price of 1000 give `1000 − 600 − 600 = −200`. This was reproduced with the component classes.
  - `discountAmount` (Core) has no upper bound against the base price. Examples: "5 € off" on a rule whose price action is 3 €; a discount in EUR on a price in another currency after conversion; a rule that has **only** a discount action (base price `0`) → negative price.
  - A `shippingRule` action (`Rule/Action/ShippingRuleActionProcessor.php`) adds the referenced rule's modifications on top, which makes the stacking harder to see in the admin.
  - The negative value goes into the cart as a shipping adjustment. It lowers the order total and the tax base, and it can push the order total below the item total. `CheapestDefaultCarrierResolver` (Core) then picks this carrier as the **default** for every cart where the rule matches, because it is the "cheapest".
- **Fix:**
  - Clamp in one central place: `return max(0, $price + $modifications);` in `CarrierShippingRulePriceCalculator` (and in any calculator that sums modifications). Log a warning with the rule id when the clamp applies, so the admin can fix the rule.
  - Optionally add a rule-level validation on save that warns when the sum of the discount percents is over 100 %.
  - Unit test: base 1000, 2 × 60 % discount → 0, not −200.

---

## Error that takes down the cart / checkout

### C-03 — A shipping rule that references itself (directly or in a cycle) through the `shippingRule` condition or action causes infinite recursion. Every cart recalculation crashes with a fatal error — (verified code path, plausible trigger) (dependent)
- **Where:**
  - Condition: `Rule/Condition/ShippingRuleConditionChecker.php:36-46` → `RuleValidationProcessorInterface::isValid()` → the same checker again.
  - Action: `Rule/Action/ShippingRuleActionProcessor.php:36-50,52-67` → `Processor/ShippingRuleActionProcessor.php:46-58,73-86` → the same action again.
  - Neither one tracks which rules are already being evaluated.
  - **(dependent)** `ShippingBundle/Form/Type/ShippingRuleChoiceType.php` lists **all** rules, including the one being edited. Nothing on save checks for cycles. `ShippingBundle/TODO-2-BUGS.md` **B-05** mentions the self-reference only as a side note.
- **Problem:**
  - Rule A has the condition "shipping rule = A", or A → B → A. On PHP 8.3+ the recursion ends with `Error: Maximum call stack size … reached` (`zend.max_allowed_stack_size`). Without that limit, the process segfaults.
  - The condition runs for **every** carrier during carrier resolution: `CarriersResolver` / `StoreBasedShippableCarriersDiscovery` → `ShippingRuleCarrierValidator`. The action runs for every valid carrier when `CheapestDefaultCarrierResolver` compares prices, and for the selected carrier in `CartShippingProcessor`. So one bad rule on one carrier breaks the cart page, every add-to-cart and the checkout for **all** customers whose cart reaches that rule.
  - It is an easy mistake to make in the admin: "reuse rule X" while editing X, or two rules that reference each other to share a price.
  - Same pattern and severity as `PaymentBundle/TODO-1-CRITICAL.md` **C-01**.
- **Fix:**
  - Component: keep a stack of rule ids that are being evaluated (for example, pass `'_shipping_rule_stack' => [...ids]` in `$params` / `$context`, or use a small `ShippingRuleRecursionGuard` service that is reset per request). When a rule id is already on the stack, log an `error` with the cycle and return `false` (condition) or `0` (action). Do not throw.
  - **(dependent)** ShippingBundle: exclude the edited rule from the choice list, and add a validator on save that walks the `shippingRule` references (nested conditions included) and rejects cycles.
  - Unit tests for a direct self-reference and a two-rule cycle, for both the condition and the action.

### C-04 — A stored action whose type has no registered processor throws on every price calculation of that carrier. The cart and checkout fail with HTTP 500 for every customer the carrier is valid for — (verified code path, plausible trigger) (dependent)
- **Where:** `Processor/ShippingRuleActionProcessor.php:47` and `:74` (`$this->actionServiceRegistry->get($action->getType())` with no `has()` check). `Component/Registry/ServiceRegistry.php:65-72` throws `NonExistingServiceException`. Conditions have the same problem in `Component/Rule/Condition/RuleConditionsValidationProcessor.php:61`, which is tracked as `Component/Rule/TODO-1-CRITICAL.md` **C-01** and not repeated here.
- **Problem:**
  - Action types are stored as free-text strings (`coreshop_rule_action.type`). A rule keeps its stored type when a project removes or renames a custom action, disables the bundle that registered it, or restores an older database or version.
  - From then on, `getPrice()` / `getModification()` throw for the rule. `CheapestDefaultCarrierResolver` (Core) prices **every** valid carrier while it resolves the default carrier, and `CartShippingProcessor` prices the selected one on every cart recalculation. So one broken rule on one carrier is enough for a 500 on the cart and checkout for every customer whose cart matches that rule.
  - The exception message lists all registered action types but names neither the rule nor the carrier, so the admin cannot find the broken rule from the log.
  - Same class of bug as `Component/Payment/TODO-2-BUGS.md` **B-03**, which is classed as a bug there because only the payment step is affected. Here it is critical because the cart processor is affected. Fix both the same way.
- **Fix:**
  - Check `$this->actionServiceRegistry->has($type)` first. When the type is missing, log a `critical` entry (rule id, rule name, action id, type) and **skip** the action. Inject an optional `LoggerInterface` so the component stays framework-free.
  - Resolve each action's processor once per call instead of twice (TODO-3 **I-07**).
  - Add the action types to the "unknown types" health check proposed in `Component/Rule/TODO-1-CRITICAL.md` **C-01**.
