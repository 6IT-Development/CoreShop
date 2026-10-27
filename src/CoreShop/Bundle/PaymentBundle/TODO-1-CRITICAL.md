# PaymentBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (`CoreShopPaymentBundle.php`, `Attribute/*`, `Controller/PaymentProviderRuleController.php`, `CoreExtension/{PaymentProvider,PaymentProviderMultiselect}.php`, `DependencyInjection/{Configuration,CoreShopPaymentExtension}.php`, `DependencyInjection/Compiler/*`, `Doctrine/ORM/*`, `Form/Type/**`), `composer.json`, all of `Resources/config/**` (`services.yml`, `services/{form,paymentprovider-rules,pimcore,workflow,data_hub}.yml`, `pimcore/{config,admin,routing,workflow}.yml`, `doctrine/model/*.orm.xml`, `serializer/*.yml`), `Resources/public/pimcore/js/**` and `Resources/translations/*`. Also checked: the component this bundle wires (`Component/Payment/**`, all PHP) and the code and config that depend on it:
`CoreBundle/Resources/config/{services/payment.yml,services/cart-processor.yml:87-97,pimcore/coreshop/coreshop_payment.yml,pimcore/workflow.yml,doctrine/model/{Payment,PaymentProvider}.orm.xml,serializer/Model.PaymentProvider.yml}`, `CoreBundle/Doctrine/ORM/PaymentProviderRepository.php`, `CoreBundle/Checkout/Step/PaymentCheckoutStep.php`, `CoreBundle/Form/Type/Checkout/PaymentType.php`, `Component/Core/Order/Processor/CartPaymentProcessor.php`, `Component/Core/Payment/**`, `Component/Core/Model/{Payment,PaymentProvider}.php`, `Component/Order/Payment/OrderPaymentProvider.php`, `Component/Order/Model/Order.php` (totals), `Component/Core/Order/Committer/OrderCommitter.php`, `OrderBundle/Resources/config/pimcore/workflow/{coreshop_payment,coreshop_order_sales_type}.yml`, `OrderBundle/Manager/CartManager.php`, `PayumPaymentBundle/Resources/config/pimcore/routing.yml`, `RuleBundle/Form/Type/*`, `Component/Rule/Condition/*`, `Component/Registry/{RegisterRegistryTypePass,RegisterSimpleRegistryTypePass,Autoconfiguration,PrioritizedServiceRegistry}.php`, `ResourceBundle/CoreExtension/{Select,Multiselect}.php`, `ResourceBundle/Form/Type/ResourceTranslationsType.php`, `MoneyBundle/Form/Type/MoneyType.php`.

Reference for API checks: `../pimcore2teszt10211524/vendor/` (`symfony/*` v7.4.17–7.4.21, `doctrine/orm` 2.20.13, `doctrine/dbal` 3.10.6, `jms/serializer-bundle` 5.5.2). The vendored `six-it/core-shop` copies of `Bundle/PaymentBundle` and `Component/Payment` are identical to the working tree (`diff -rq` is empty), so the findings apply to the installed shop as well.

"Critical" here means: a security issue, money charged or stored wrongly, data loss or data written wrongly, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** live outside this bundle, but they come from how it is used, and they should be fixed together with it. Items marked **(plausible)** follow from the code, but were not reproduced against a running shop. Items marked **(reproduced)** were checked with a PHP 8.4.27 script against the vendored classes.

**Symfony 7.4 / PHP 8.4 compatibility:** no blockers. `php -l` (PHP 8.4.27) passes on all 74 PHP files (bundle + component). No implicit nullable parameters, no dynamic properties, no removed Symfony APIs. `MethodMarkingStore(bool $singleState, string $property)` matches the 7.4 signature (`services/workflow.yml:5-9`). The `TreeBuilder` calls do not hit the two new 7.4 deprecations (default value + `isRequired()`). Forward-compatibility items (Doctrine ORM 3 XSD validation, `scalarNode` with array default, `AbstractBundle`) are in TODO-3.

**Overlap with other TODOs:** `Component/Currency/TODO-1-CRITICAL.md` **C-01** (payment amount vs. currency) and `NotificationBundle/TODO-1-CRITICAL.md` (attribute `formType` vs. `form-type`, see TODO-2 **B-03** here) touch the same code paths. They are referenced, not repeated.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Checkout availability

### C-01 — A payment provider rule that references itself (directly or in a cycle) causes infinite recursion; the payment step and every cart recalculation crash with a fatal error
- **Where:** `Component/Payment/Rule/Condition/PaymentProviderRuleConditionChecker.php:40-44`, `Component/Payment/Rule/Action/PaymentProviderRuleActionProcessor.php:41-44,57-60`, `Component/Payment/Processor/PaymentProviderRuleActionProcessor.php:44-55,69-81`. Choice list: `Form/Type/PaymentProviderRuleChoiceType.php:39-50` (lists **all** rules, including the one being edited). Admin UI: `Resources/public/pimcore/js/paymentproviderrule/{conditions,actions}/paymentProviderRule.js`.
- **Problem:**
  - The `paymentProviderRule` condition evaluates another rule with `RuleValidationProcessor::isValid()`. The `paymentProviderRule` action calls `PaymentProviderRuleActionProcessor::getPrice()` / `getModification()` for another rule. Neither tracks which rules are already being evaluated.
  - Rule A with condition "payment provider rule = A" (or A → B → A) recurses until PHP runs out of stack / memory (`Allowed memory size exhausted` or a segfault, depending on `zend.max_allowed_stack_size` in 8.3+). This is a fatal error, not an exception, so nothing catches it.
  - `RuleBasedPaymentProviderResolver` evaluates the rules of **every** active provider when the payment step renders (`PaymentProviderChoiceType` → `resolvePaymentProviders($cart)`), and `CartPaymentProcessor` evaluates the selected provider's rule on every cart change. One misconfigured rule therefore takes down the payment step for every customer, and every cart request of customers who already selected that provider.
  - Nothing in the admin prevents it: the choice list contains the rule itself, there is no validator, and saving succeeds.
- **(plausible):** not run in a shop; the recursion follows directly from the code (no guard in any of the three classes).
- **Fix:**
  - Validate on save: walk the `paymentProviderRule` references of conditions (including nested ones) and actions, and reject cycles with a form error (a constraint on `PaymentProviderRuleType`).
  - Exclude the edited rule from `PaymentProviderRuleChoiceType` (pass the current id as an option).
  - Add a runtime guard as defence in depth: pass a `visited_rules` list in `$params` / `$context` and return `false` / `0` (and log a warning) when a rule is visited twice.

### C-02 — A rule group without a rule is saved, and then the payment step fails with a `TypeError` for every customer
- **Where:** `Resources/public/pimcore/js/provider/item.js:253-266` (adds a row with `paymentProviderRule: null`; the editor's `required: true` is not enforced), `:313-324` (sends it as is), `Form/Type/PaymentProviderRuleGroupType.php:33` (no `NotNull` constraint), `Resources/config/doctrine/model/PaymentProviderRuleGroup.orm.xml:28-33` (`nullable="true"`), `Component/Payment/Checker/PaymentProviderRuleChecker.php:44-47`.
- **Problem:**
  - An admin who clicks **Add** in the provider's rule grid and saves without choosing a rule stores a `PaymentProviderRuleGroup` with `paymentProviderRuleId = NULL`. For a new group the form does not even call the setter (`null` → `null`), so the non-nullable `setPaymentProviderRule(PaymentProviderRuleInterface)` does not stop it.
  - `PaymentProviderRuleChecker::findValidPaymentProviderRule()` passes `$rule->getPaymentProviderRule()` (= `null`) to `RuleValidationProcessorInterface::isValid(ResourceInterface, RuleInterface, …)` → `TypeError`.
  - The checker runs in `RuleBasedPaymentProviderResolver` for **all** providers on the payment step, in `PaymentProviderValidator` (checkout validation) and in `CartPaymentProcessor`. The whole payment step returns 500 until the group is removed.
- **(plausible):** the save path was traced through the form and Doctrine mapping, the `TypeError` follows from the signature.
- **Fix:**
  - Add `NotNull` to the `paymentProviderRule` field, make the column `nullable="false"` (migration: delete groups with `NULL` first), type the property `?PaymentProviderRuleInterface` only if `null` stays a valid state.
  - In the checker, skip groups without a rule (`continue`) instead of passing `null` on.
  - Enforce the selection in the ExtJS grid before save (`item.js` `getSaveData()`).

### C-03 — (dependent) The payment total is computed **before** the payment fee is added; the fee is shown to the customer but, depending on callback order, not charged
- **Where:** `Component/Core/Order/Processor/CartPaymentProcessor.php:45-48` (sets `paymentTotal` from `getTotal()`) and `:69-76` (adds the payment adjustment afterwards, which changes `total` via `Order::recalculateAfterAdjustmentChange()`, `Component/Order/Model/Order.php:324-330`). `CartAdjustmentClearer` (priority 650) removes the payment adjustment on every mutable run first. Consumers of `paymentTotal`: `Component/Order/Payment/OrderPaymentProvider.php:54` (Payum amount), `OrderBundle/StateResolver/OrderPaymentStateResolver.php:64-111` ("fully paid" checks). Order of the final processing: `CoreBundle/Resources/config/pimcore/workflow.yml:24-27` (`commit_order`, after `order`) and `OrderBundle/Resources/config/pimcore/workflow/coreshop_order_sales_type.yml:40-43` (`immutability`, after `order`), both with priority 0; `OrderCommitter::commitOrder()` calls `persistCart()` (= `process()`) twice (`Component/Core/Order/Committer/OrderCommitter.php:74,109`).
- **Problem:**
  - On every mutable run the sequence is: clear adjustments → … → `paymentTotal = total` (without fee) → add fee (total now includes it). So a cart always has `paymentTotal = total − payment fee`.
  - The value is only correct if the **last** `process()` before payment runs on an immutable order (then the method sets `paymentTotal` from a total that still contains the stored fee and returns early). That depends on whether the `immutability` callback runs before `commit_order`. Both are `after` callbacks on the same transition with the same priority, so the order is decided by bundle/config load order, not by code.
  - If `commit_order` runs first, the customer sees "Payment fee 2.00, Total 102.00", Payum charges 100.00, and `OrderPaymentStateResolver` marks the order **paid** because the completed payment equals `paymentTotal`. With a discount action (`discountPercent`, `discountAmount`) the opposite happens: the customer is charged more than the shown total.
- **(plausible):** not run end-to-end. The ordering of the two `after` callbacks was not determined; it must be checked in the target project.
- **Verify:** place an order with an `additionAmount` payment rule and compare `totalGross` with `paymentTotal` (and the Payum amount) on the stored order.
- **Fix:**
  - Compute `paymentTotal` once, after all adjustments, in its own processor with the lowest priority (below `CartCurrencyConversionProcessor`, 100), or at the end of `CartPaymentProcessor::process()` after `addAdjustment()`. Do not rely on immutability for correctness.
  - Give `commit_order` and `immutability` explicit priorities.
  - Coordinate with `Component/Currency/TODO-1-CRITICAL.md` **C-01** (which currency `paymentTotal` is in) and TODO-2 **B-05** here (units of `paymentTotal`).

### C-04 — Rule labels stored as `NULL` (any shop upgraded from 4.0) crash every request that reads the rule's translations
- **Where:** `Component/Payment/Model/PaymentProviderRuleTranslation.php:36` (`protected string $label = ''`), `Component/Payment/Model/PaymentProviderRuleTranslationInterface.php:27-29`, `Resources/config/doctrine/model/PaymentProviderRuleTranslation.orm.xml:12` (`nullable="true"`). Readers: `Component/Core/Order/Processor/CartPaymentProcessor.php:66` (`getLabel()` on every cart recalculation with a matching rule), `Resources/config/serializer/Model.PaymentProviderRule.yml:26-29` (admin edit).
- **Problem:**
  - Commit `69fa08b9e` ("fixed some types") changed `$label` from an untyped property to `string` without a migration. Before, an empty label in the default language was stored as `NULL` (the form maps an empty text field to `null`, and the old setter accepted it).
  - Doctrine hydrates `NULL` into the typed property with reflection: `TypeError: Cannot assign null to property …PaymentProviderRuleTranslation::$label of type string` **(reproduced** with `ReflectionProperty::setValue()` on the vendored class).
  - The translations collection is loaded lazily on `getLabel()`, so every cart request whose payment rule has such a row fails, and the rule can no longer be opened in the admin to fix it.
- **Fix:**
  - Decide one contract and make code and mapping agree: either `?string $label = null` with `getLabel(): ?string` (matches the column and the "optional label" UI, `CartPaymentProcessor` already falls back to a default label), or keep `string` and add a migration `UPDATE coreshop_payment_provider_rule_translation SET label = '' WHERE label IS NULL` plus `nullable="false"`.
  - The admin save path for an empty label has the same root cause, see TODO-2 **B-10**.
