# PaymentBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Functional bugs that are wrong behaviour but not critical by the definition there (they break a feature, an admin action or a non-default configuration, not the shop as a whole).
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. **(dependent)**, **(plausible)** and **(reproduced)** have the same meaning as in TODO-1.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Container / registries

### B-01 — `coreshop.registry.payment.price_calculators` cannot be instantiated: the only tagged service does not implement the registry's interface
- **Where:** `Resources/config/services.yml:11-26`, `Component/Payment/Calculator/PaymentProviderRulePriceCalculator.php:27` (implements `PaymentProviderRulePriceCalculatorInterface`, not `PaymentPriceCalculatorInterface`), `DependencyInjection/Compiler/PaymentCalculatorsPass.php`, `Component/Registry/PrioritizedServiceRegistry.php:43-47`.
- **Problem:**
  - The registry requires `PaymentPriceCalculatorInterface` (`getPrice($provider, $payable, array $configuration, array $context)`), but `PaymentProviderRulePriceCalculator` is tagged `coreshop.payment.price_calculator` and implements a different interface with a different signature.
  - The registry is public, tagged `coreshop.registry` (so it can be autowired as `ServiceRegistryInterface $paymentPriceCalculators`), and its `register()` checks the interface. The first `get()` of the registry throws `InvalidArgumentException: Payment-price-calculators needs to implement "…PaymentPriceCalculatorInterface", "…PaymentProviderRulePriceCalculator" given.` **(reproduced)**.
  - Today nothing in CoreShop reads the registry, so the error is latent. Any project that autowires it, or registers its own calculator following the `#[AsPaymentPriceCalculator]` attribute, hits it.
- **Fix:** decide whether payment prices are pluggable. If yes, let `CartPaymentProcessor` use the registry and make `PaymentProviderRulePriceCalculator` implement `PaymentPriceCalculatorInterface` (adapter). If no, remove the registry, the pass, the attribute and the autoconfiguration (TODO-3 **I-02**).

### B-02 — Action autoconfiguration targets the aggregate processor interface instead of the action interface
- **Where:** `DependencyInjection/CoreShopPaymentExtension.php:67-73` (`PaymentProviderRuleActionProcessorInterface`), compare `Resources/config/services/paymentprovider-rules.yml:16-22` (registry requires `ProviderActionProcessorInterface`).
- **Problem:**
  - `Component/Payment/Rule/Processor/PaymentProviderRuleActionProcessorInterface` is the **aggregate** that runs all actions of a rule (`Component/Payment/Processor/PaymentProviderRuleActionProcessor.php`). The individual actions implement `ProviderPriceActionProcessorInterface` / `ProviderPriceModificationActionProcessorInterface` (both extend `ProviderActionProcessorInterface`).
  - A project class implementing a payment rule action with `autoconfigure: true` is never tagged, so it never appears in the admin. The documentation (`docs/03_Development/10_Payment`) does not mention the manual tag.
  - The opposite case: a project decorating the aggregate with `autoconfigure: true` gets tagged as an action (type derived from the class name). The action registry then rejects it on first use (`ServiceRegistry::register()` interface check), which breaks every payment rule evaluation.
- **Fix:** register `ProviderActionProcessorInterface::class` for autoconfiguration. Conditions are correct (`PaymentConditionCheckerInterface`).

### B-03 — `#[AsPaymentRuleConditionChecker(formType: …)]` / `#[AsPaymentRuleActionProcessor(formType: …)]`: the form type is dropped silently
- **Where:** `Attribute/AsPaymentRule{ConditionChecker,ActionProcessor}.php` (`getFormType()`), `Component/Registry/Autoconfiguration.php` (`getProperties()` emits `formType`), `Component/Registry/RegisterRegistryTypePass.php:58` (reads `form-type`).
- **Problem:** with `autoconfigure_with_attributes: true`, the tag gets `formType`, the pass looks for `form-type`, so the condition/action has no configuration form. The admin shows it, but saving its configuration fails (no form registered for the type). Same root cause as `NotificationBundle/TODO-1-CRITICAL.md` (attribute keys); fix centrally there and verify for this bundle. `#[AsPaymentPriceCalculator]` has no `priority`, so attribute-registered calculators always get `1000` (`RegisterSimpleRegistryTypePass`).
- **Fix:** see the NotificationBundle item. Add a test that registers one condition and one action via attributes and checks `FormTypeRegistry::has($type, 'default')`.

## Price / rule evaluation

### B-04 — The `amount` condition ignores its `gross` and `useTotal` options, and accepts `maxAmount < minAmount`
- **Where:** `Component/Payment/Rule/Condition/AmountConditionChecker.php:26-50`, `Form/Type/Rule/Condition/AmountConfigurationType.php:42-56`, `Resources/public/pimcore/js/paymentproviderrule/conditions/amount.js:56-66`.
- **Problem:**
  - The admin offers "Gross" and "Use Total" checkboxes and stores them, but the checker always compares `getPaymentTotal()` (gross grand total including shipping and discounts). A merchant who configures "net subtotal between 50 and 500" gets "gross total between 50 and 500".
  - `maxAmount` has no `GreaterThanOrEqual(0)` (only the ExtJS field has `minValue: 0`), and nothing checks `min ≤ max`. A rule with `min 100, max 50` silently never matches, which hides the provider (rules work as availability filters, `RuleBasedPaymentProviderResolver`).
- **Fix:** implement the two flags (subtotal vs. total, gross vs. net, the way `Component/Shipping/Rule/Condition/AmountConditionChecker.php` uses the same two flags) or remove them from form, JS and stored configs. Add a class-level constraint `minAmount ≤ maxAmount` when both are > 0.

### B-05 — Payment amounts mix two units: `paymentTotal` is always "× 100", rule amounts and adjustments use `decimal_factor`
- **Where:** `Component/Core/Order/Processor/CartPaymentProcessor.php:45-48` **(dependent)** (`paymentTotal = round(total / decimal_factor, precision) * 100`), `Component/Payment/Calculator/PaymentProviderRulePriceCalculator.php:51-57` (passes `getPaymentTotal()` as `$price` to modification actions), `Component/Payment/Rule/Action/{AdditionPercent,DiscountPercent}ActionProcessor.php:28`, `Component/Payment/Rule/Condition/AmountConditionChecker.php:28-46`, `Form/Type/Rule/Condition/AmountConfigurationType.php:42-53` (`MoneyType` → `decimal_factor` units, `MoneyBundle/Form/Type/MoneyType.php`).
- **Problem:** with the default `decimal_factor: 100` both units are the same. With `money_decimal_precision: 4` (factor 10 000):
  - `minAmount` 50.00 is stored as `500000`, but `paymentTotal` of a 60.00 cart is `6000` → the condition fails; every amount condition is off by 100.
  - `additionPercent 2 %` on 100.00 returns `200` (2.00 in "× 100" units), which is added to the cart as an adjustment in factor-10 000 units = 0.02.
  - The payment fee (C-03 in TODO-1) and the Payum amount are affected in the same way.
- **Fix:** use `getTotal()` (factor units) inside rule evaluation and the price calculator, and convert to the gateway's minor units only where the payment is created (`OrderPaymentProvider`). Document which unit `PayableInterface::getPaymentTotal()` has. Coordinate with `MoneyBundle/TODO-3-IMPROVEMENTS.md` (money units) and `Component/Currency/TODO-1-CRITICAL.md` **C-01**.

### B-06 — `PaymentProviderRuleConfigurationType`: the model transformer turns the stored id into `null`
- **Where:** `Form/Type/Rule/Common/PaymentProviderRuleConfigurationType.php:48-63`.
- **Problem:** both closures are identical. The configuration stores an `int` id, `transform()` receives that `int`, is not a `PaymentProviderRuleInterface`, and returns `null`. The field is therefore always empty when the form is built from existing data. The ExtJS editor always resubmits the value, so the admin works by accident; any other client (API, import, a form that does not resubmit the field, `submit($data, false)`) loses the reference, and `NotBlank` then rejects the save.
- **Fix:** `transform: fn (?int $id) => $id ? $this->repository->find($id) : null`, `reverseTransform: fn (?PaymentProviderRuleInterface $rule) => $rule?->getId()`. Inject the repository (the type is already a service).

### B-07 — (dependent) The payment fee adjustment has gross = net, so no tax is ever calculated on it
- **Where:** `Component/Core/Order/Processor/CartPaymentProcessor.php:69-76` (`createWithData(PAYMENT, $label, $price, $price)`).
- **Problem:** shipping costs get a tax rule (`CartShippingProcessor` + tax calculation strategy), payment fees do not. In the EU a payment surcharge is an ancillary cost and follows the VAT of the main supply, so `totalTax` and invoices are wrong for shops that charge a fee. Depending on whether `price` is entered gross or net, either the shop pays the VAT from its margin or the net total is too high.
- **Fix:** add a tax rule group to the payment provider (or reuse the cart's dominant rate like shipping does) and compute net/gross separately. At least document that fees are tax-free. Check the business requirement first.

## Admin / persistence

### B-08 — Saving the rule groups of an inactive provider (or one not in the admin's resolved store) fails validation
- **Where:** `Form/Type/PaymentProviderRuleGroupType.php:34` (`paymentProvider` field = `PaymentProviderChoiceType`), `Form/Type/PaymentProviderChoiceType.php:41-43` (choices = `PaymentProviderResolverInterface::resolvePaymentProviders(null)`), **(dependent)** `Component/Core/Payment/Resolver/StoreBasedPaymentProviderResolver.php:36` (`findActiveForStore($storeContext->getStore())`), `Resources/public/pimcore/js/provider/item.js:320` (sends `paymentProvider: this.data.id`), `Form/Type/PaymentProviderRuleGroupCollectionType.php:36-41` (`by_reference: true`).
- **Problem:**
  - The choice list for the group's own provider is the **storefront** resolver chain: only active providers of the store that the admin request happens to resolve. For an inactive provider (the normal state while setting it up) or a provider of another store, the submitted id is not a valid choice → "This value is not valid." on every group → the provider cannot be saved at all once it has rule groups.
  - The client has to send the owning provider id because `by_reference: true` bypasses `addPaymentProviderRule()`, so nothing else sets the owning side.
  - Every admin save also runs the full resolver chain (DB query + store context) once per group.
- **(plausible):** derived from the resolver chain; whether the admin store context throws or returns the default store was not checked.
- **Fix:** remove the `paymentProvider` field from `PaymentProviderRuleGroupType`, set `by_reference: false` on the collection, and let `PaymentProvider::addPaymentProviderRule()` call `$group->setPaymentProvider($this)`.

### B-09 — Deleting a payment provider rule that is still in use fails with a foreign key error, or leaves dangling ids
- **Where:** `Resources/config/doctrine/model/PaymentProviderRuleGroup.orm.xml:28-33` (no `on-delete`), conditions/actions that store the id in JSON (`Component/Payment/Rule/Condition/PaymentProviderRuleConditionChecker.php:40-47`, `Component/Payment/Rule/Action/PaymentProviderRuleActionProcessor.php:41-47`).
- **Problem:**
  - A rule used by a provider's rule group cannot be deleted: the DB rejects it (`SQLSTATE[23000] … foreign key constraint fails`) and the admin gets a generic 500 instead of "rule is used by provider X".
  - A rule only referenced from another rule's `paymentProviderRule` condition or action is deleted without warning. The condition then returns `false` (the provider disappears from checkout) and the action returns `0` (the fee disappears), with no log entry.
- **Fix:** before delete, look up usages (groups + JSON configs) and return a clear error, or cascade groups (`on-delete="CASCADE"`) on purpose and document it. Log a warning when a referenced rule id is not found.

### B-10 — Saving a rule with an empty label in the default language returns 500
- **Where:** `Form/Type/PaymentProviderRuleTranslationType.php:30` (`TextType`, empty → `null`), `Component/Payment/Model/PaymentProviderRuleTranslation.php:48` (`setLabel(string $label)`), `ResourceBundle/Form/Type/ResourceTranslationsType.php` (the default locale's entry is `required`, so it is always created).
- **Problem:** the label is optional in the UI (`CartPaymentProcessor` falls back to `coreshop.paymentprovider.rule.label`). With an empty default-language label the form calls `setLabel(null)`; PropertyAccess turns the `TypeError` into an `InvalidArgumentException` ("Expected argument of type "string", "null" given"), which `symfony/form` 7.4 does not catch (`PropertyPathAccessor::setValue()` only catches `PropertyAccessException`) → 500.
- **(plausible):** traced through `symfony/form` 7.4.21; not clicked in the admin.
- **Fix:** together with TODO-1 **C-04** (nullable label), or `'empty_data' => ''` on the field.

### B-11 — A provider translation without a title cannot be saved, and `__toString()` can throw
- **Where:** `Resources/config/doctrine/model/PaymentProviderTranslation.orm.xml:12` (`title` NOT NULL), `Form/Type/PaymentProviderTranslationType.php:31-32` (no constraint), `Component/Payment/Model/PaymentProviderTranslation.php:39,51-54` (`$title` untyped, default `null`; `__toString(): string` returns it).
- **Problem:**
  - For a non-default language where the admin fills only "Description" or "Instructions", a translation entity is created with `title = null`. The `INSERT` fails with `Column 'title' cannot be null` → 500, no form error.
  - `(string) $translation` (Twig `{{ translation }}`, logging, JMS) throws `TypeError: Return value must be of type string, null returned` for such entities.
- **Fix:** `NotBlank` on `title` (validation group `coreshop`) for entries that are not empty, or make the column nullable and fall back to the identifier. `__toString()` → `return $this->title ?? '';`.

### B-12 — `PaymentTransitions` does not match the `coreshop_payment` state machine
- **Where:** `Component/Payment/PaymentTransitions.php:25-37`, `Resources/config/pimcore/workflow.yml:20-38` (transitions), `:47-55` (`transition_colors`).
- **Problem:** the class defines `TRANSITION_CREATE` and `TRANSITION_VOID`, which do not exist in the workflow (`$workflow->apply($payment, PaymentTransitions::TRANSITION_VOID)` throws `UndefinedTransitionException`), and it lacks `TRANSITION_AUTHORIZE`, which exists in the workflow (Payum reaches it only indirectly, via `getTransitionToState()` in `PayumBundle/Extension/UpdatePaymentStateExtension.php:96-100`, so code that wants to apply it by name has no constant). `transition_colors` lists the same two non-existing transitions.
- **Fix:** add `TRANSITION_AUTHORIZE = 'authorize'`; either add `void` (authorized → cancelled/voided) to the workflow or deprecate the two constants; remove the stale colors.

### B-13 — (dependent) Cancelling or failing an authorized payment does not update the order's payment state
- **Where:** `OrderBundle/Resources/config/pimcore/workflow/coreshop_payment.yml:6-9` (`resolve_state` only on `complete`, `process`, `refund`, `authorize`), `OrderBundle/StateResolver/OrderPaymentStateResolver.php`.
- **Problem:** `authorize` moves the order to `authorized`. When the authorization is then cancelled (`cancel` from `authorized`, e.g. expired or voided at the PSP) or a processing payment fails, the resolver is not called, so the order stays `authorized` / `awaiting_payment` with no valid payment behind it. Fulfilment that waits for "authorized" ships unpaid goods.
- **Fix:** add `fail` and `cancel` to `resolve_state.on` and make sure the resolver can move the order back (check the order-payment workflow has the needed transitions).

### B-14 — `get-config` route for payment providers points to an action that does not exist in this bundle
- **Where:** `Resources/config/pimcore/routing.yml:1-10` (`coreshop_payment_provider_config` → `coreshop.admin_controller.payment_provider::getConfigAction`), `DependencyInjection/Configuration.php:93` (default admin controller = `ResourceController`, no `getConfigAction`), **(dependent)** `PayumPaymentBundle/Resources/config/pimcore/routing.yml:1-3` (a second route with the same path), `Resources/public/pimcore/js/provider/panel.js:29` (uses the Payum route name).
- **Problem:** without `PayumPaymentBundle` the route is a 500; with it there are two routes for the same path and the first match wins depending on loading order. The JS only uses the Payum one.
- **Fix:** remove the `additional_routes` entry for `payment_provider` here. `PaymentProviderRuleController::getConfigAction()` (`Controller/PaymentProviderRuleController.php:27-33`) should also call `$this->isGrantedOr403()` like the other actions.

### B-15 — Admin JS: the "payment provider rule" condition/action combo allows multi-select, the backend accepts one id
- **Where:** `Resources/public/pimcore/js/paymentproviderrule/conditions/paymentProviderRule.js:33`, `.../actions/paymentProviderRule.js:33` (`multiselect: true`), `Form/Type/PaymentProviderRuleChoiceType.php` (single choice).
- **Problem:** selecting two rules sends an array → "This value is not valid." on save, with no hint which field. Also `value: me.data.paymentProviderRule` is read before the `if (this.data …)` guard (`:38` vs `:41`), so a missing `data` throws a JS error.
- **Fix:** remove `multiselect`, use `this.data?.paymentProviderRule` once.

### B-16 — Payment component without `CoreBundle`: a `Payment` cannot be persisted and `findForPayable()` never matches
- **Where:** `Resources/config/doctrine/model/Payment.orm.xml:13` (`orderId` integer NOT NULL), `Component/Payment/Model/Payment.php:67` (`$orderId`, no setter, not in `PaymentInterface`), `Doctrine/ORM/PaymentRepository.php:27-36`.
- **Problem:** only `Component/Core/Model/Payment::setOrder()` fills `orderId`. Using this bundle standalone (as `composer.json` allows), every `Payment` insert fails on `orderId`, and the repository method cannot be used. Even with Core, `coreshop_payment` has both `orderId` and `` `order` `` for the same value.
- **Fix:** move the order link into `PaymentInterface` (`setPayable(PayableInterface)` / `getPayableId()`), or make `orderId` nullable and document that Core owns it. Long term see TODO-3 **I-08**.

### B-17 — Clearing "Position" in the provider form returns 500
- **Where:** `Form/Type/PaymentProviderType.php:38-40` (`IntegerType`, `required: false`), `Resources/config/doctrine/model/PaymentProvider.orm.xml:14` (`position` NOT NULL), `Component/Payment/Model/PaymentProvider.php:128-131` (untyped setter accepts `null`).
- **Problem:** an empty field submits `null`, the setter stores it, and the `UPDATE` fails with `Column 'position' cannot be null` → 500 instead of a form error or a default.
- **Fix:** `'empty_data' => '1'` (or `0`) on the field, or `NotNull`; type the setter `setPosition(int $position): void` (TODO-3 **I-04**).
