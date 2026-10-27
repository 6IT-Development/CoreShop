# Rule Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are improvements to code quality, readability, logic, performance, Symfony 7.4 / PHP 8.4 modernisation and forward compatibility (Symfony 8, Doctrine DBAL 4 / ORM 3). None of them is a functional bug on its own.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have their root cause or part of the fix outside this component.

**BC note:** this component is a shared base for six rule types (Order, Payment, Shipping, Product ×2, ProductQuantityPriceRules, Notification). Every interface signature change below breaks the classes that implement or extend it. Change the component and all implementers in one step. Check at least `Component/ProductQuantityPriceRules/Model/ProductQuantityPriceRule.php` (implements `RuleInterface` without `RuleTrait`), the five `RuleTrait` users and their Core subclasses.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — `composer.json` does not describe what the component needs
- **Where:** `composer.json:26-35`.
- **Fix:**
  - Add `"php": "~8.4.0"` (the root `composer.json` requires `~8.4.0`, but the component has no PHP constraint).
  - Add `"doctrine/collections": "^2.2"`. `Model/RuleInterface.php` and `Model/RuleTrait.php` use `Collection` / `ArrayCollection` directly, and today the package only comes in through `coreshop/resource`.
  - Remove `"six-it/pimcore-source"`. Nothing in this component uses Pimcore.
  - Align `require-dev` with the root (`phpstan/phpstan ^2.1`, and the matching `phpstan-doctrine` and `phpstan-webmozart-assert` 2.x). Remove `phpstan-webmozart-assert`, because the component does not use `Webmozart\Assert`.
  - Fix the indentation of `"extra"` (`:49`).

### I-02 — Add native types to the interfaces and models — (dependent: all rule implementers)
- **Where / Fix:**
  - `Model/ActionInterface.php`, `Model/ConditionInterface.php`:
    - `getType(): ?string`, `setType(string $type): static`
    - `getSort(): ?int`, `setSort(?int $sort): static`
    - `getConfiguration(): array` (with `[]` as the default, TODO-2 **B-05**), `setConfiguration(array $configuration): static`
  - `Model/RuleInterface.php`:
    - `getName(): ?string`, `setName(?string $name): void`
    - `getConditions(): Collection`, `getActions(): Collection`
    - `hasActions(): bool`, `hasAction(ActionInterface): bool`, `addAction(ActionInterface): void`, `removeAction(ActionInterface): void`
    - Use generics `@return Collection<int, ConditionInterface>` instead of `Collection|ConditionInterface[]`.
  - `Condition/RuleConditionsValidationProcessorInterface.php:29`: `iterable $conditions` (TODO-2 **B-04**).
  - `Repository/RuleRepositoryInterface.php:36,43`: `string $conditionType` / `string $actionType`, and `@return list<RuleInterface>` (RuleBundle TODO-2 **B-09** / TODO-3 **I-09**).
  - Models (`Model/Action.php`, `Model/Condition.php`): typed properties `protected ?int $id = null; protected ?string $type = null; protected ?int $sort = null; protected array $configuration = [];`.
    - **Order matters:** do this only **after** the DBAL `array` → `json` migration (RuleBundle TODO-3 **I-03**). Before that, `unserialize()` of nested child `Condition` objects that are already stored can fail on typed properties ("Cannot assign null to property …").
    - Doctrine ORM 2.20 supports typed properties. Do **not** use PHP 8.4 property hooks or `private(set)` on Doctrine-mapped properties until the project is on an ORM version that supports them.

### I-03 — Readonly, final and visibility on the services
- **Where / Fix:**
  - Promoted constructor properties that are never reassigned → `private readonly`:
    - `Condition/NestedConditionChecker.php:27` (today `protected`; nothing extends it)
    - `Condition/RuleConditionsValidationProcessor.php:29-30`
    - `Condition/RuleValidationProcessor.php:27`
    - `Condition/TraceableRuleConditionsValidationProcessor.php:30`
  - Mark `NestedConditionChecker`, `RuleConditionsValidationProcessor`, `RuleValidationProcessor` and `TraceableRuleConditionsValidationProcessor` `final`, or `@final` first for one minor version. They are extension points through their interfaces and are wired by class in YAML, and no subclass exists in the code base. `TraceableRuleConditionsValidationProcessor::addProcessedRule()` is `protected` only for subclassing; make it `private`.
  - Type the untyped parameters of `addProcessedRule()` (`:77`, `:79`) as `bool`.

---

## Logic and readability

### I-04 — Model the nested operator as an enum and rewrite `NestedConditionChecker` as a short loop
- **Where:** `Condition/NestedConditionChecker.php:31-68`. **(dependent)** `RuleBundle/Form/Type/Rule/Condition/AbstractNestedConfigurationType.php` (choices `and` / `or` / `not`).
- **Fix:**
  - Add `enum NestedOperator: string { case And = 'and'; case Or = 'or'; case Not = 'not'; }` to the component. Use it for the form choices (`EnumType`, or `array_column(NestedOperator::cases(), 'value', 'value')`), so the form and the checker cannot drift apart.
  - Replace the `switch` with `break 2` by a `match` on the operator and early returns:
    - `And` → return `false` at the first invalid child
    - `Or` → return `true` at the first valid child
    - `Not` → return `false` at the first valid child
    - After the loop: `And` → `true`, `Or` → `false`, `Not` → `true`.
    - Today the reader has to work out these rules from `$valid = in_array($operator, ['and', 'not'], true)`.
  - Document what `not` means: it is **NOR** ("none of the children is valid"), not "NOT (A AND B)". The admin label "not" suggests the second meaning. Also document the results for empty groups: `and` and `not` → valid, `or` → invalid.
  - Each child is evaluated through `isValid($subject, $rule, [$condition], $params)`, which checks `$rule->getActive()` again for every child. Call `isConditionValid()` directly. The rule was already checked at the top level.

### I-05 — Remove duplicate logic from the two condition processors
- **Where:** `Condition/RuleConditionsValidationProcessor.php:39-56`, `Condition/TraceableRuleConditionsValidationProcessor.php:39-63`.
- **Fix:**
  - The traceable decorator copies the inner "inactive" and "empty" logic instead of delegating, and its copy behaves differently: it does not stop at the first failed condition (RuleBundle TODO-2 **B-04**).
  - Let the decorator call `$this->ruleConditionsValidationProcessor->isValid()` and record through a callback or event, or move the loop into one shared `evaluate(iterable $conditions, callable $onResult)`.
  - Remove the redundant `count()` checks (TODO-2 **B-04**).

### I-06 — Add a recursive condition walker to the component — (dependent: Order, RuleBundle, CoreBundle, PaymentBundle)
- **Where:** new class, for example `Condition/ConditionTreeWalker` with `walk(iterable $conditions): \Generator<ConditionInterface>`, which descends into `nested` children. Another option is a small `NestedConditionInterface::getChildren()` contract.
- **Why:** several consumers need "all conditions of type X, at any depth":
  - `VoucherModifier` (TODO-2 **B-02**)
  - `RuleAvailabilityAssessor` (nested timespans)
  - the variant cache listener in CoreBundle
  - the cycle check proposed in PaymentBundle TODO-1 (walking `paymentProviderRule` references, nested ones included)
  - the "unknown type" check on save (TODO-1 **C-01**)

  Today each would have to know the internal `configuration['conditions']` layout.

### I-07 — `Condition` and `Action` are identical: share the code and add a proper `__clone()`
- **Where:** `Model/Action.php`, `Model/Condition.php` (88 lines each, identical except for the class name and the `$id` docblock: `int` vs. `int|null`). Same for `Model/ActionInterface.php` / `Model/ConditionInterface.php`.
- **Fix:**
  - Move the shared code into an abstract `AbstractRuleElement` (mapped superclass) or a `RuleElementTrait`, and add a shared `RuleElementInterface`. The Doctrine mappings stay separate.
  - Make the setters consistent (TODO-2 **B-05**).
  - Remove `@psalm-suppress MissingConstructor`: the project uses PHPStan, not Psalm.
  - Add `__clone()`: reset `$id = null`, and deep-clone nested child `Condition` objects inside `configuration`. **(dependent)** `ProductBundle/CoreExtension/ProductSpecificPriceRules.php:149-165` and `ProductQuantityPriceRulesBundle/CoreExtension/ProductQuantityPriceRules.php:170-…` clear `id` with `ReflectionProperty`. Their shallow `clone` also leaves the copy and the original sharing the same nested child objects in memory, so editing one before the flush changes the other.

### I-08 — Clean up `RuleTrait`
- **Where:** `Model/RuleTrait.php`.
- **Fix:**
  - `public $name` (`:36`) → `protected ?string $name = null`. The other properties are protected, and a public property lets callers skip `setName()`.
  - `public function __construct(\n        )` (`:48-51`): the empty parameter list is split over two lines. The same pattern is in `Order/Model/CartPriceRule.php:49-50` and `Product/Model/AbstractPriceRule.php:37-38`. A constructor inside a trait also forces each class with its own constructor to alias `initializeRuleCollections`. Keep only `initializeRuleCollections(): void` in the trait and give every rule class its own explicit constructor. `ShippingRule` and `NotificationRule` rely on the trait constructor today.
  - `__toString()` (`:62-65`): add `: string`, and add `\Stringable` to `RuleInterface` (ProductQuantityPriceRule already implements it). For an unsaved rule without a name it returns `" ()"`. Return something like `sprintf('%s (%s)', $this->getName() ?? 'unnamed', $this->getId() ?? 'new')`.
  - Add return types to `getName()`, `setName()`, `hasActions()`, `hasAction()`, `addAction()`, `removeAction()` and `initializeRuleCollections()` (with I-02).
  - Generic docblocks `@var Collection<int, ConditionInterface>` / `Collection<int, ActionInterface>`.

### I-09 — Redesign the traceable processor around the profiler lifecycle — (dependent: RuleBundle)
- **Where:** `Condition/TraceableRuleConditionsValidationProcessor.php`, `Condition/TraceableRuleConditionsValidationProcessorInterface.php:23`.
- **Fix:**
  - Implement `Symfony\Contracts\Service\ResetInterface` (the component may depend on `symfony/service-contracts`, or add a framework-free `reset()` method that the bundle tags `kernel.reset`), as in RuleBundle TODO-2 **B-05**.
  - Short-circuit like the inner processor, and record the conditions that were skipped as "not evaluated" (RuleBundle TODO-2 **B-04**).
  - Describe the return value of `getValidatedConditions(): array` with a `@phpstan-type` array shape. Today the panel template and `RuleCollector` depend on an undocumented nested array.
  - Fix the recording problems from TODO-2 **B-03**.

### I-10 — Document the checker and assessor contracts
- **Where:** `Condition/ConditionCheckerInterface.php:26`, `Condition/Assessor/RuleAvailabilityAssessorInterface.php`.
- **Fix:**
  - Add `@param array<string, mixed> $configuration` and `@param array<string, mixed> $params`, and list the `$params` keys the core passes: `cartPriceRule`, `voucher`, `carrier`, `store`, `customer`, …. Today every checker has to find them out on its own (ShippingBundle TODO-2 has a related inconsistency).
  - `RuleAvailabilityAssessorInterface` sits in the `Condition\Assessor` namespace but is about rule availability. Move it to `Rule\Assessor` with a deprecated alias.
  - `getRules(): array` → `iterable`, so a repository can return a `toIterable()` result (RuleBundle TODO-3 **I-06**: the N+1 and memory problem).

---

## Quality assurance

### I-11 — No tests
- **Where:** the component has no `Tests/` directory and no `phpunit.xml.dist`. Behat covers rule types end to end, but not the edge cases in this component.
- **Fix:** add unit tests for:
  - `NestedConditionChecker`: the full truth table and the malformed configurations from TODO-2 **B-01**
  - `RuleConditionsValidationProcessor`: inactive rule, empty conditions, unknown type (TODO-1 **C-01**), `iterable` input
  - `TraceableRuleConditionsValidationProcessor`: correct per-condition results, nested trees, short-circuit, `reset()`
  - `RuleTrait`: add and remove are idempotent, `__toString()`
  - `Condition` / `Action`: `__clone()` resets the id and deep-clones nested children

  Run PHPStan on the component at the project level, once the docblocks from I-02 / I-08 are corrected.
