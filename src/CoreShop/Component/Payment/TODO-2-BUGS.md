# Payment Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Functional bugs that are wrong behaviour but not critical by the definition there (they break a feature, an admin action or a non-default configuration, not the shop as a whole).
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. **(dependent)**, **(plausible)** and **(reproduced)** have the same meaning as in TODO-1.

Already tracked in `PaymentBundle/TODO-2-BUGS.md`, **not repeated here**: **B-01**, **B-04**, **B-05**, **B-08**, **B-10**, **B-11**, **B-12**, **B-16**, **B-17** (see the overlap list in TODO-1). The items below are new.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Rule evaluation

### B-01 — (reproduced) Deactivating a payment provider rule hides the payment provider from checkout instead of just disabling the rule
- **Where:** `Checker/PaymentProviderRuleChecker.php:44-56`, `Validator/PaymentProviderRuleValidator.php:34-38`, `Resolver/RuleBasedPaymentProviderResolver.php:47-51`. Root behaviour: `Component/Rule/Condition/RuleConditionsValidationProcessor.php:41-43` (`if (!$rule->getActive()) return false;`). **(dependent)** `CoreBundle/Checkout/Step/PaymentCheckoutStep.php:62` (`isPaymentProviderValid()`), `PaymentBundle/Form/Type/PaymentProviderChoiceType.php:42`.
- **Problem:**
  - An inactive rule is evaluated as "not valid". `PaymentProviderRuleValidator` returns `true` only when the provider has **no** rule groups, or when at least one group's rule is valid. A provider whose only rule is inactive therefore has groups but no valid rule, and `RuleBasedPaymentProviderResolver` removes it from the checkout.
  - Reproduced: provider with one group whose rule is `active = false` → `isPaymentProviderRuleValid()` is `false`; the same rule with `active = true` and no conditions → `true`.
  - A merchant who deactivates a "2 % surcharge" rule to stop charging the fee instead removes the payment method. If it is the only payment method, checkout can no longer be completed (the payment step is never valid). If the inactive rule's group has `stopPropagation`, the checker returns `null` right away (`:49-51`), so later valid groups are not even evaluated.
  - The Behat features check only the rule itself ("the payment-provider-rule should be invalid", `features/domain/payment_provider/payment_provider_rules/*_condition.feature`), not the provider's availability, so the effect is not covered.
- **Fix:**
  - Skip groups whose rule is inactive in `PaymentProviderRuleChecker` (`continue`, also before the `stopPropagation` check), and treat "all groups inactive" like "no groups" in `PaymentProviderRuleValidator`.
  - If "inactive rule hides provider" is intended (it matches `Component/Shipping/Checker/CarrierShippingRuleChecker.php`), document it in the admin and in `docs/03_Development/10_Payment` — today nothing says so. See also TODO-3 **I-01** (availability vs. pricing).
  - Add a Behat scenario "provider with only an inactive rule is still available / is not available" (whichever is decided).

### B-02 — The `paymentProviderRule` **action** applies the referenced rule's actions even when that rule is inactive or its conditions do not match
- **Where:** `Rule/Action/PaymentProviderRuleActionProcessor.php:41-47` (`getPrice()`), `:57-63` (`getModification()`); compare the **condition** with the same name, `Rule/Condition/PaymentProviderRuleConditionChecker.php:41-47`, which does evaluate the referenced rule (and so respects `active`).
- **Problem:**
  - Rule A has the action "payment provider rule = B". `PaymentProviderRuleActionProcessor` loads B and runs **all** of B's actions through `Processor/PaymentProviderRuleActionProcessor`. B's `active` flag and B's conditions are ignored.
  - An admin who deactivates B ("cash on delivery fee, only for orders below 50") expects the fee to stop; it is still charged on every order that matches A. A rule whose conditions restrict a fee to some countries applies to all countries when it is reused through an action.
  - The condition and the action with the same type name behave differently, which makes reuse of rules unpredictable.
  - Same pattern in `Component/Shipping/Rule/Action/ShippingRuleActionProcessor.php`.
- **Fix:** decide the contract. Recommended: the action applies B's actions only if `$rule->getActive()` and B's conditions are valid (inject `RuleValidationProcessorInterface`, as the condition checker does). Otherwise, at least skip inactive rules and document "conditions of the referenced rule are ignored" in the admin label. Combine with the cycle guard of `PaymentBundle/TODO-1-CRITICAL.md` **C-01**.

### B-03 — A stored rule that references an unknown condition or action type takes down the payment step for every customer
- **Where:** `Processor/PaymentProviderRuleActionProcessor.php:45` and `:70` (`$this->actionServiceRegistry->get($action->getType())`), conditions via `Component/Rule/Condition/RuleConditionsValidationProcessor.php:61` (`$this->ruleRegistry->get($condition->getType())`). Registry: `Component/Registry/ServiceRegistry.php` `get()` (throws `NonExistingServiceException`).
- **Problem:**
  - Condition and action types are stored as strings. When a project removes or renames a custom condition / action, or disables a bundle that registered one, the stored rules still reference it.
  - `RuleBasedPaymentProviderResolver` evaluates the rules of **every** active provider while the payment step renders, so one broken rule returns 500 for the whole payment step, for all customers, not only for the affected provider. `CartPaymentProcessor` hits the action registry on every cart recalculation of carts that selected the provider.
  - The exception names neither the rule nor the provider, so the admin cannot find the broken rule from the log.
  - Same class of bug as `Component/Notification/TODO-2-BUGS.md` **B-03** and `Component/Index/TODO-2-BUGS.md` **B-07**; fix in a consistent way.
- **Fix:** check `has()` before `get()`. For an unknown condition type: log a warning (rule id, rule name, type) and treat the rule as not valid. For an unknown action type: log and skip the action. Optionally validate stored types on save, and add a lint command that lists rules with unknown types.

### B-04 — (dependent, plausible) The store filter uses the store of the **request context**, not the store of the cart that is checked
- **Where:** `Component/Core/Payment/Resolver/StoreBasedPaymentProviderResolver.php:34-37` (`findActiveForStore($this->storeContext->getStore())`, `$subject` ignored), used through `Resolver/PaymentProviderResolverInterface` by `Validator/PaymentProviderValidator.php:36` and `Resolver/RuleBasedPaymentProviderResolver.php:39`. Wiring: `CoreBundle/Resources/config/services/payment.yml:8-13`.
- **Problem:**
  - `PaymentProviderResolverInterface::resolvePaymentProviders(?ResourceInterface $subject)` receives the cart, but the store filter ignores it and asks the store context. In a storefront request both are the same. In any other context they are not: admin order creation for a store other than the admin's resolved store, a console command / Messenger worker that recalculates carts, an API request, or a persisted cart reopened after a store switch.
  - The result is that `PaymentProviderValidator` and the choice list use the wrong store's providers: a provider of the cart's store is rejected as invalid, or a provider that is not assigned to the cart's store is accepted.
  - If the store context cannot resolve a store (CLI without request), `getStore()` throws `StoreNotFoundException`.
- **(plausible):** derived from the code; the admin order creation flow was not run.
- **Fix:** if `$subject` is a `StoreAwareInterface` with a store, use `$subject->getStore()`; fall back to the context only when there is no subject. Let the decorator filter the inner result instead of querying again (see `PaymentBundle/TODO-3-IMPROVEMENTS.md` **I-03**).

## Models

### B-05 — (dependent, plausible) Reading a title / description / label in a locale without a translation (and without a fallback translation) adds an empty translation row, which makes the next flush fail
- **Where:** `Model/PaymentProvider.php:93-96`, `:103-106`, `:113-116` (`getTitle()`, `getDescription()`, `getInstructions()`), `Model/PaymentProviderRule.php:54-57` (`getLabel()`). Root behaviour: `Component/Resource/Model/TranslatableTrait.php` `getTranslation()` (creates a new translation and calls `addTranslation()` when neither the locale nor the fallback exists). Mapping: `PaymentBundle/Resources/config/doctrine/model/PaymentProviderTranslation.orm.xml` (`title` NOT NULL), `ResourceBundle/EventListener/ORMTranslatableListener.php:104` (the `translations` association is mapped with `cascade: persist, merge, remove`).
- **Problem:**
  - The fallback is the default locale. When a provider has no translation in the default locale (it was created in another language, or the default locale was changed later), a storefront request in a language without translation calls `getTitle()`, and the getter **adds** a new translation with `title = null` to the managed collection.
  - The next `EntityManager::flush()` in the same request (for example creating the `Payment` in `OrderPaymentProvider` → Payum storage, or saving the provider in the admin) cascades the new row → `Column 'title' cannot be null` → 500. For rules, `CartPaymentProcessor` calls `getLabel($cart->getLocaleCode())` on every cart recalculation; the empty label row is saved silently (the column is nullable) and accumulates.
  - A read should not change data.
- **(plausible):** the add-on-read is in the trait; the full flush path was not run in a shop.
- **Fix:** in the payment models, read through a lookup without side effects (e.g. `$this->translations->get($locale) ?? $this->translations->get($this->fallbackLocale)` and return `null` when neither exists), or fix the trait centrally (`getTranslation(…, create: false)`) and track it in `Component/Resource`. Getter return types become `?string` (TODO-3 **I-05**).

### B-06 — `PaymentInterface::STATE_UNKNOWN` is exposed as a payment state, but it is not a place of the `coreshop_payment` state machine
- **Where:** `Model/PaymentInterface.php:40`, `PaymentBundle/Resources/config/pimcore/workflow.yml:12-19` (places: `new`, `processing`, `authorized`, `completed`, `failed`, `cancelled`, `refunded`). Users: `PayumBundle/Request/GetStatus.php:95-100` (Payum status sentinel), `PayumBundle/Extension/UpdatePaymentStateExtension.php:89` (skips it).
- **Problem:** the constant lives next to the real states, so it looks like a valid value for `setState()`. A project (or a custom Payum action) that stores it (`$payment->setState(PaymentInterface::STATE_UNKNOWN)`) puts the payment in a marking that the workflow does not know: `$workflow->can()` returns `false` for every transition, the admin shows no transition buttons, and the payment can only be fixed in the database. `setState()` is untyped and does not check the value.
- **Fix:** move the sentinel to `PayumBundle\Request\GetStatus` (keep the old constant `@deprecated`), or add `unknown` as a place with transitions. In the long run, a `PaymentState` enum makes invalid states impossible (TODO-3 **I-05**).
