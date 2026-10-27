# Rule Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope and references: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are wrong behaviours, crashes in limited areas and contract violations that do not meet the "critical" bar.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have their root cause or part of the fix outside this component. Items marked **(verified)** were checked with a PHP 8.4.27 script. Items marked **(plausible)** follow from the code, but the trigger depends on data or custom code. Items marked **(latent)** are not reachable through the shipped code today.

**Overlap with other TODOs:** see the list at the end of [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Those items are **not repeated** here.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Nested conditions

### B-01 — `NestedConditionChecker` does not validate its configuration. Missing keys give warnings and arbitrary results, and array children give a `TypeError` — (verified)
- **Where:** `Condition/NestedConditionChecker.php:33-67`.
- **Problem:** `$configuration['operator']` and `$configuration['conditions']` are read without checks. Results with PHP 8.4:
  - No `conditions` key with `and`: two warnings ("Undefined array key", "foreach() argument must be of type array|object, null given"), and the nested condition is **valid**. The same happens with `not`.
  - No `operator` key: a warning, and the condition is **invalid**.
  - An unknown operator, for example `xor`, or a different case such as `AND`: the `switch` matches nothing, and the result is `false` with no hint at all.
  - `configuration` is `null` (the column is nullable). `RuleConditionsValidationProcessor` passes `[]`, so you get the first two cases.
  - A child that is not a `ConditionInterface`, for example an array after a JMS restore (RuleBundle TODO-1 **C-01**), raises a `TypeError` in `isConditionValid()`.
  - In prod the warnings are only logged and the rule silently changes meaning. In debug, Symfony's `ErrorHandler` turns them into exceptions, so dev and prod behave differently.
- **Fix:**
  - Read the configuration defensively: `$operator = NestedOperator::tryFrom($configuration['operator'] ?? '')` (see TODO-3 **I-04**) and `$conditions = $configuration['conditions'] ?? []`.
  - Treat an unknown operator as invalid and log it. Do not fall through silently.
  - Accept array children of the form `['type' => …, 'configuration' => …]` and build a `Condition` from them, or skip them with a log entry. This keeps damaged rows working (RuleBundle TODO-1 **C-01**, "defensive" part).
  - Add a unit test for the truth table: `and` / `or` / `not` × {0 children, all true, mixed, all false} × the malformed configurations above.

### B-02 — (dependent, plausible) Conditions inside a `nested` condition are not seen by code that inspects the conditions of a rule
- **Where:** `Model/RuleInterface.php:43` / `Model/RuleTrait.php:82-85`: `getConditions()` returns only the top level. The component has no API to walk nested children. Consumers that loop over the top level only:
  - `Component/Order/Modifier/VoucherModifier.php:75`, `:137` look for `type === 'voucher'` to count `maxUsagePerUser` per customer.
  - `RuleBundle/Assessor/RuleAvailabilityAssessor.php:42` looks for `timespan` (RuleBundle TODO-2 **B-01** says "acceptable, but undocumented").
  - `CoreBundle/EventListener/RecursiveVariantCacheInvalidationEventListener.php:50` looks for `products` (that listener has its own bug, tracked in CoreBundle TODO-2).
- **Problem:** the nested form uses the same condition registry, so editors can put a `voucher` condition into an `and` group, for example "voucher X AND customer group Y". The checker still runs, because `NestedConditionChecker` evaluates the child. But `VoucherModifier` never finds the voucher condition, so the per-customer usage counter is never incremented, and the "max uses per customer" limit is **never enforced** for that voucher. The availability task also ignores nested timespans, so expired rules stay active.
- **Fix:** add a recursive walker to the component (TODO-3 **I-06**) and use it in all three consumers. Until then, document that `voucher` and `timespan` must be top-level conditions, or reject them inside `nested` in the form.

---

## Validation processors

### B-03 — `TraceableRuleConditionsValidationProcessor` records wrong data in the profiler — (verified)
- **Where:** `Condition/TraceableRuleConditionsValidationProcessor.php:39-117`.
- **Problem:**
  - `isConditionValid()` (`:65-72`) calls `addProcessedRule($subject, $rule, $isValid, $condition)` without `$conditionResult`, so the default `false` is recorded. It also records the **condition** result as the **rule** result. When the method is called directly, a passing condition appears as failed (reproduced). When it is called from `isValid()`, the entry is overwritten one line later, so it is the same work done twice.
  - Conditions are keyed by `$condition->getId()` (`:110`). Nested children are stored inside `configuration` and never have an id, so they all share the key `null` → `''`. They overwrite each other and the parent nested condition, and they are flattened into the rule's own condition list (reproduced: an `or` group with two children produced one entry, `key='' type=nested`). The profiler cannot show nested trees.
  - The rule `result` (`:109`) is overwritten on every call. `Component/Order/Cart/Rule/Action/CartItemActionProcessor.php:158` calls `isValid()` once per condition, so the profiler shows the result of the **last** condition as the rule result.
  - Inactive rules return before anything is recorded (`:41-43`), so the panel cannot tell "inactive" from "not evaluated".
- **Impact:** debug and profiler only, but it is the tool developers use to find out why a price rule does not apply. Wrong data there costs debugging time.
- **Fix:** record per call, not per rule: `{rule, conditions: [{type, config, result, children: [...]}], result, reason: inactive|evaluated}`. Pass `$conditionResult` explicitly. Key unsaved or nested conditions by position or `spl_object_id()`. Record the nested structure by letting `NestedConditionChecker` push and pop a context, or by giving the traceable decorator a depth counter. Store scalars only (RuleBundle TODO-2 **B-05**).

### B-04 — (latent) `isValid()` accepts any `$conditions`, but only works for `array` and `Countable`
- **Where:** `Condition/RuleConditionsValidationProcessorInterface.php:29`, `Condition/RuleConditionsValidationProcessor.php:39-47`, `Condition/TraceableRuleConditionsValidationProcessor.php:39-49`.
- **Problem:** the parameter has no type and no docblock. `count($conditions)` throws `TypeError` for `null` (reproduced) and for any non-countable `iterable` (generators, `IteratorAggregate`). The `count()` check is not needed at all: the `foreach` returns `true` for an empty list.
- **Fix:** type it as `iterable $conditions` with `@param iterable<ConditionInterface> $conditions`, and remove the `count()` early return in both implementations. In the traceable class, keep the "no conditions" trace entry by using a flag set inside the loop. Also see TODO-3 **I-02**.

---

## Models

### B-05 — `setSort()` breaks the fluent API that the other setters provide, and `getConfiguration()` can return `null` although documented as `array`
- **Where:** `Model/Action.php:72-75`, `Model/Condition.php:72-75` (`setSort()` returns nothing, while `setType()` and `setConfiguration()` return `$this`). `Model/ActionInterface.php:47-50` documents `@return array`, but the property is unset for a new object and the column is `nullable="true"` (`RuleBundle/Resources/config/doctrine/model/Action.orm.xml:12`).
- **Problem:**
  - `$condition->setType('x')->setSort(1)->setConfiguration([...])` raises "Call to a member function setConfiguration() on null".
  - Callers trust the `array` docblock and write `$action->getConfiguration()['key']`. On a new or migrated action, that gives an "Undefined array key" or "Trying to access array offset on null" warning. Static analysis does not catch it, because it believes the docblock. `RuleConditionsValidationProcessor.php:63` already defends with `?? []` for conditions, but the action processors do not. `ConditionInterface` documents `array|null`, `ActionInterface` documents `array`, so the two are inconsistent.
- **Fix:** make the setters consistently return `static` (or consistently `void`, see TODO-3 **I-07**). Initialise `$configuration = []` in both models, and declare `getConfiguration(): array` once the typing in TODO-3 **I-02** lands.
