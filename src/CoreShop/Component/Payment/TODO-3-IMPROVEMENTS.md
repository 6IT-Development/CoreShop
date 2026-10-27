# Payment Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Code quality, readability, logic and modernization items. None of them is a functional bug on its own. Items marked **(dependent)** live outside this component.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.

Already tracked in `PaymentBundle/TODO-3-IMPROVEMENTS.md`, **not repeated here**: **I-01** (one price API / one evaluation per cart), **I-02** (dead wiring, empty `__construct(\n) {}` bodies in `Model/{PaymentProvider,PaymentProviderRule}.php`), **I-04** (typed properties / return types of all models and interfaces), **I-05** (`private readonly` promoted properties, `final`), **I-12** (`AbstractConditionChecker` messages and `Assert`), **I-13** (repeated rule evaluation), **I-14** (`stopPropagation` semantics and explicit ordering), **I-18** (tests), **I-19** (docs). The items below complement them.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Design / logic

### I-01 — Separate "is this provider available?" from "what does it cost?"
- **Where:** `Validator/PaymentProviderRuleValidator.php:32-39`, `Resolver/RuleBasedPaymentProviderResolver.php:34-54`, `Calculator/PaymentProviderRulePriceCalculator.php:35-63`, **(dependent)** `Component/Core/Order/Processor/CartPaymentProcessor.php:54-77`.
- **Idea:**
  - The same rule groups do two jobs: their conditions decide whether the provider is offered at all, and the first valid rule's actions decide the fee. A merchant who wants "fee of 2.00 below 50, no fee above" must add a second, empty catch-all rule, otherwise the provider disappears above 50. A rule that only has actions (no conditions) hides nothing, a rule with conditions always hides. This is not visible in the admin or the docs, and it is the root of TODO-2 **B-01**.
  - Option A (small): document it in the provider grid ("rules restrict availability; add a rule without conditions to always offer the provider") and in `docs/03_Development/10_Payment`.
  - Option B (cleaner): add a flag on the rule group (`purpose: availability | price | both`, default `both` for BC), or let a provider be available when no **availability** rule exists, and treat price-only rules as optional.

### I-02 — Clearer names and namespaces for the rule processing classes
- **Where:** `Processor/PaymentProviderRuleActionProcessor.php` vs. `Rule/Action/PaymentProviderRuleActionProcessor.php` (two classes with the **same short name**), `Rule/Processor/PaymentProviderRuleActionProcessorInterface.php` (implemented by the class in `Processor/`), `Rule/Action/ProviderActionProcessorInterface.php` (empty marker), `Rule/Condition/PaymentConditionCheckerInterface.php:27` (`isPaymentProviderRuleValid()` checks one **condition**, not a rule).
- **Idea:**
  - Readers (and autowiring aliases in `PaymentBundle/Resources/config/services/paymentprovider-rules.yml:67-77`) have to compare full namespaces to know which class is meant. The aggregate also triggered the wrong autoconfiguration (`PaymentBundle/TODO-2-BUGS.md` **B-02**).
  - Suggested names: `Processor/PaymentProviderRuleActionProcessor` → `Rule/Processor/PaymentProviderRuleActionsApplier` (aggregate, next to its interface); `Rule/Action/PaymentProviderRuleActionProcessor` → `Rule/Action/ReferencedRuleActionProcessor`; `isPaymentProviderRuleValid()` → `isConditionValid()` / `isSatisfiedBy()`.
  - Keep the old names as `class_alias()` + `@deprecated` for one minor version (projects decorate these services by class name).
  - `ProviderActionProcessorInterface` can stay as the registry type, but give it a docblock that says so ("marker for the `coreshop.payment_provider_rule.action` registry; implement one of the two sub-interfaces").

### I-03 — Pass the rule and the full parameters into payment conditions; widen `PayableInterface`
- **Where:** `Rule/Condition/AbstractConditionChecker.php:27-38`, `Rule/Condition/PaymentConditionCheckerInterface.php:27`, `Rule/Condition/PaymentProviderRuleConditionChecker.php:44` (passes only `['payable' => …]` on), `Model/PayableInterface.php:21-26`. **(dependent)** all 10 checkers in `Component/Core/Payment/Rule/Condition/*.php` start with `if (!$payable instanceof OrderInterface) return false;`.
- **Idea:**
  - `AbstractConditionChecker::isValid()` receives `$rule` and `$params`, but forwards only provider, payable and configuration. The runtime cycle guard proposed in `PaymentBundle/TODO-1-CRITICAL.md` **C-01** (a `visited_rules` entry in `$params`) cannot reach `PaymentProviderRuleConditionChecker` without an API change, and `PaymentProviderRuleConditionChecker` drops all other params when it evaluates the referenced rule.
  - Add a second method or interface, e.g. `isPaymentConditionValid(PaymentProviderInterface $provider, PayableInterface $payable, RuleInterface $rule, array $configuration, array $params): bool`, and keep the old method as BC path in `AbstractConditionChecker`.
  - `PayableInterface` has only `getId()` and `getPaymentTotal()`. Every real condition needs more (store, currency, customer, items), so all of them cast to `OrderInterface` and silently return `false` for any other payable. Either document "payment rules work on orders only" and type against the order interface in Core, or add small optional interfaces (`StoreAwareInterface`, `CurrencyAwareInterface` exist already) and check those instead of the concrete order type.

### I-04 — Simplify the checker, validator and resolver
- **Where:** `Checker/PaymentProviderRuleChecker.php:38-58`, `Validator/PaymentProviderRuleValidator.php:34-38`, `Validator/PaymentProviderValidator.php:36-44`, `Resolver/RuleBasedPaymentProviderResolver.php:36-53`, `Resolver/PaymentProviderResolver.php:31-34`.
- **Idea:**
  - `PaymentProviderRuleChecker`: the `count() === 0` early return (`:40-42`) is redundant (the loop does nothing). The `$rule instanceof PaymentProviderRuleInterface ? $rule : $rule->getPaymentProviderRule()` branch is evaluated twice (`:45`, `:54`) and the first half is dead: `getPaymentProviderRules()` only contains `PaymentProviderRuleGroupInterface`. Resolve the rule once per iteration into a local variable.
  - `PaymentProviderRuleValidator` repeats the "no rules" check that the checker also does; keep it in one place (`hasPaymentProviderRules()` exists on the model, `Model/PaymentProvider.php:161-164`).
  - `PaymentProviderValidator` builds an id array with `array_map()` + `in_array()`. With PHP 8.4: `return array_any($validProviders, static fn (PaymentProviderInterface $p): bool => $p->getId() === $paymentProvider->getId());`.
  - `RuleBasedPaymentProviderResolver`: `return array_values(array_filter($paymentProviders, fn ($p) => $this->paymentProviderRuleValidator->isPaymentProviderRuleValid($p, $subject)));` and an `@return list<PaymentProviderInterface>` on `PaymentProviderResolverInterface::resolvePaymentProviders()` (today `array`, so every caller casts with `@var`).
  - `PaymentProviderResolver` ignores `$subject`; add a short comment that the parameter is only there for the decorators further up the chain.

### I-05 — PHP 8.3 / 8.4 language features
- **Where:** `PaymentTransitions.php:23-37`, `Model/PaymentInterface.php:26-40`, `Rule/Action/{AdditionPercent,DiscountPercent}ActionProcessor.php:28`, all classes that implement an interface method, all model getters.
- **Idea:** (typed properties, `readonly` and `final` are in `PaymentBundle/TODO-3-IMPROVEMENTS.md` **I-04** / **I-05**)
  - Typed class constants (PHP 8.3): `public const string STATE_NEW = 'new';`, `public const string IDENTIFIER = 'coreshop_payment';`.
  - Backed enums for the closed sets, with the constants kept as aliases for BC: `enum PaymentState: string { case New = 'new'; … }`, `enum PaymentTransition: string { … }`. `setState(PaymentState|string $state)` can then validate (TODO-2 **B-06**). Align the transition list with the workflow at the same time (`PaymentBundle/TODO-2-BUGS.md` **B-12**).
  - `#[\Override]` (PHP 8.3) on every method that implements or overrides a parent / interface method (`getTranslation()`, `createTranslation()`, `isValid()`, all `get*()` of the processors). It catches signature drift such as the one in `PaymentBundle/TODO-2-BUGS.md` **B-01** at compile time.
  - Percent actions: compute in one step and state the rounding explicitly: `(int) round($price * (float) $configuration['percent'] / 100, 0, \RoundingMode::HalfAwayFromZero)` (PHP 8.4 enum). `DiscountPercentActionProcessor` can return `-$this->addition(...)` or both can share one class (see **I-08**). Checked during this scan: with PHP 8.4.27 the current formula and the "multiply first" formula give the same results for 1–100 000 minor units at 0.5 / 1.5 / 2.5 / 3.5 / 7.5 / 12.5 %, so this is readability, not a bug.
  - Translated getters (`getTitle()`, `getDescription()`, `getInstructions()`, `getLabel()`) should declare `?string`; together with TODO-2 **B-05** they must not create rows.
  - Asymmetric visibility (PHP 8.4, `public private(set) ?int $id`) would remove the `getId()` boilerplate, but check Doctrine ORM / Pimcore hydration support first; not a priority.

### I-06 — `PaymentInterface` API cleanup beyond typing
- **Where:** `Model/PaymentInterface.php:42-110`, `Model/Payment.php:32-162`, **(dependent)** `Component/Core/Model/Payment.php:28-60`, `Component/Order/Payment/OrderPaymentProvider.php:51-66`.
- **Idea:**
  - `getDatePayment()` is documented as `\DateTime`; use `\DateTimeInterface` (or `\DateTimeImmutable` with `datetime_immutable` in the mapping) so callers cannot change the stored value by reference.
  - `setDescription(string)` is the only typed setter; the rest accept anything. Type them all in one go (see the bundle's **I-04**).
  - `currencyCode` (string) and Core's `currency` (entity) hold the same information; `OrderPaymentProvider` sets the currency and then the code again (`:64`), which is already done by `Core\Model\Payment::setCurrency()`. Keep the code as the single source in the component, let Core resolve the entity lazily.
  - The payable link (`$orderId` without accessor) belongs in the interface: see `PaymentBundle/TODO-2-BUGS.md` **B-16**.
  - `getDetails()` / `setDetails()` hold raw gateway data (Payum details). Document that it may contain personal data and must not be exposed to the storefront.

### I-07 — Read rule configuration defensively
- **Where:** `Rule/Condition/AmountConditionChecker.php:28-29` (`$configuration['minAmount']`, `['maxAmount']`), `Rule/Action/AdditionPercentActionProcessor.php:28`, `Rule/Action/DiscountPercentActionProcessor.php:28` (`['percent']`), `Rule/Condition/PaymentProviderRuleConditionChecker.php:40`, `Rule/Action/PaymentProviderRuleActionProcessor.php:41,57` (`['paymentProviderRule']`).
- **Idea:** configurations are JSON stored in the database and can come from older versions, imports or the API. A missing key raises `Warning: Undefined array key` (an `ErrorException` → 500 in debug mode) and then computes with `null`. Use `$configuration['percent'] ?? 0` with an explicit cast, or `Assert::keyExists()` with a message that names the action type, so a broken configuration shows up as a clear error instead of a silent `0`.

### I-08 — Merge the duplicated percent / amount actions
- **Where:** `Rule/Action/AdditionPercentActionProcessor.php`, `Rule/Action/DiscountPercentActionProcessor.php` (identical except the sign), **(dependent)** `Component/Core/Payment/Rule/Action/{AdditionAmountActionProcessor,DiscountAmountActionProcessor,PriceActionProcessor}.php` (three copies of the same currency lookup + conversion).
- **Idea:** one `PercentModificationActionProcessor` with a constructor flag `int $sign` (`1` / `-1`), registered twice with different `type`s; in Core, a small `AmountConverter` (find currency, assert, convert) shared by the three amount actions. Same change as the form-type merge in `PaymentBundle/TODO-3-IMPROVEMENTS.md` **I-11**, so both sides stay aligned.

## Packaging / wiring

### I-09 — Remove the Pimcore dependency from the component's provider interface
- **Where:** `Model/PaymentProviderInterface.php:26,86-94` (`Pimcore\Model\Asset` in the docblocks of `getLogo()` / `setLogo()`), `Model/PaymentProvider.php:27,57-60`.
- **Idea:** the component is meant to be framework-independent (`composer.json` requires only `coreshop/resource`, `coreshop/registry`, `coreshop/rule`), but it imports a Pimcore class. Either move the logo to `Component/Core/Model/PaymentProviderInterface` (Core already extends the provider), or type it as `?object` / a small `LogoAwareInterface` and keep the Pimcore type in Core. If it stays, declare `pimcore/pimcore` in `composer.json` (**I-13**).

### I-10 — (dependent) The rule availability assessor for payment rules has nothing to assess
- **Where:** `PaymentBundle/Resources/config/services/paymentprovider-rules.yml:126-131` (`coreshop.rule.availability_assessor.payment_provider_rule`), `RuleBundle/Assessor/RuleAvailabilityAssessor.php` (only looks at `timespan` conditions). No `timespan` condition is registered for payment rules (it exists for cart price, product price and quantity price rules).
- **Idea:** either register a `timespan` condition for payment rules (useful: time-limited surcharges / free payment for a campaign) so the assessor can deactivate expired rules — but only after TODO-2 **B-01** is fixed, otherwise an expired rule hides the provider — or remove the assessor service.

### I-11 — (dependent) Workflow `supports` and services should target the interface
- **Where:** `PaymentBundle/Resources/config/pimcore/workflow.yml:7` (`supports: CoreShop\Component\Payment\Model\Payment`).
- **Idea:** a project that replaces the payment model with its own class implementing `PaymentInterface` (allowed by the resource configuration) gets no workflow. Use `CoreShop\Component\Payment\Model\PaymentInterface`.

## Tests / docs

### I-12 — Component unit tests for the new findings
- **Where:** no PHPUnit tests in `Component/Payment` (bundle-level test plan: `PaymentBundle/TODO-3-IMPROVEMENTS.md` **I-18**).
- **Idea:** add, in addition to the bundle's list:
  - `PaymentProvider` / `PaymentProviderRule` setters with two locales and a fallback (TODO-1 **C-01**).
  - Getter in a locale without translation does not add a translation (TODO-2 **B-05**).
  - Provider with only an inactive rule, and inactive rule with `stopPropagation` (TODO-2 **B-01**).
  - `paymentProviderRule` action with an inactive / non-matching referenced rule (TODO-2 **B-02**).
  - Unknown condition / action type is skipped and logged (TODO-2 **B-03**).
  - Percent actions: rounding at .5 and sign (**I-05**).
  - The component tests need no Pimcore kernel: everything above runs with the plain models and a `ServiceRegistry` (the reproduction scripts of this scan did exactly that).

### I-13 — `composer.json`
- **Where:** `composer.json`.
- **Idea:**
  - Declare what the code uses directly: `doctrine/collections` (`Model/PaymentProvider.php:25-26`, `Model/PaymentProviderInterface.php:25`) and, while **I-09** is open, `pimcore/pimcore`.
  - `phpstan/phpstan ^1.10`, `phpstan-doctrine ^1.3`, `phpstan-webmozart-assert ^1.2` → the 2.x line, consistent with the rest of the repository.
  - `"minimum-stability": "dev"` and the `path` repository have no effect for consumers of a library; keep them only if the monorepo split needs them, and then consistently across all components.
  - `branch-alias dev-master: 4.1-dev` — check that it matches the branch this package is released from.

### I-14 — `README.md`
- **Where:** `README.md:9`.
- **Idea:** "It is build" → "It is built"; the "Read more on coreshop.org" link uses `http://www.coreshop.org` (the docs link below uses `https://docs.coreshop.org`). Add two sentences on what the component contains (provider model, rule-based availability and fees, payment model and transitions) and link the payment docs. Content for the docs themselves: `PaymentBundle/TODO-3-IMPROVEMENTS.md` **I-19** plus **I-01** here.
