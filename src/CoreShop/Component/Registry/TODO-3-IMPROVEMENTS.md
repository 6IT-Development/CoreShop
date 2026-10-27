# Registry Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, PHP 8.4 modernisation and forward-compatibility items. None of them is a functional bug on its own.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have part of the fix outside this component. Items marked **(verified)** were confirmed against the code, the vendored sources or the compiled container.

Items already tracked in other TODO files are not repeated; see the list at the end. Several bundle TODOs propose replacing parts of this component with Symfony's native tagged iterators (`LocaleBundle/TODO-3-IMPROVEMENTS.md` **I-01**, **I-03**, `FrontendBundle/TODO-3-IMPROVEMENTS.md` **I-18**, `Component/Address/TODO-3-IMPROVEMENTS.md`). **I-10** below is the component-level plan for that.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Packaging

### I-01 — (verified) `composer.json`: declare PHP 8.4 and align the dev tooling with the monorepo
- **Where:** `composer.json:26-33`, `:45-50`.
- **Problem / Fix:**
  - No `"php"` constraint at all. Other components declare `"php": "~8.4.0"` (e.g. `Component/Payment/composer.json:27`), as does the root `composer.json:88`. Add it.
  - `require-dev` pins `phpstan/phpstan ^1.10`, `phpstan-doctrine ^1.3.40`, `phpstan-webmozart-assert ^1.2.4`; the root uses `^2.x`. PHPStan 1.x does not fully understand PHP 8.4 syntax (property hooks, asymmetric visibility). Use `^2.1`; drop `phpstan-doctrine` and `phpstan-webmozart-assert` (neither Doctrine nor Webmozart is used here).
  - `"minimum-stability": "dev"` for a library is unnecessary with `prefer-stable`; keep only if the path repositories need it.
  - `branch-alias` `dev-master: 4.1-dev`; the root uses `dev-next: 4.1-dev`. Align.
  - Fix the odd indentation of `"extra"` (`:47`).

---

## Registry contracts and implementations

### I-02 — Add generics to both registry interfaces
- **Where:** `ServiceRegistryInterface.php`, `PrioritizedServiceRegistryInterface.php`.
- **Problem:** `get(): object`, `all(): array` force every consumer to write `/** @var CheckoutStepInterface $step */` casts (e.g. `Component/Order/Checkout/CheckoutManager.php:46-51`) and to `Assert::isInstanceOf()` again.
- **Fix:** `@template T of object` on both interfaces; `register(string $identifier, T $service)`, `get(string $identifier): T`, `all(): array<string, T>`, `getNextTo(): ?T`, `getAllPreviousTo(): list<T>`. Add `@implements ServiceRegistryInterface<T>` in implementations and `@param ServiceRegistryInterface<ConditionCheckerInterface>` at consumer constructors (incremental).

### I-03 — Give both registries a common read-only base interface
- **Where:** `ServiceRegistryInterface.php`, `PrioritizedServiceRegistryInterface.php` (same `all/unregister/has/get` methods, no common supertype).
- **Problem:** code that only reads (`has()` / `get()` / `all()`) must pick one of two unrelated interfaces. This is why the autowiring aliases are typed wrongly (TODO-2 **B-08**) and why the registry passes need a class sniff (TODO-2 **B-10**).
- **Fix:** introduce `ReadableServiceRegistryInterface` (`has`, `get`, `all`) and let both extend it; add a `\Countable` / `\IteratorAggregate` capability there if wanted. Consumers that only read type against the base.

### I-04 — `PrioritizedServiceRegistry`: one index helper, typed parameters, no repeated sorting
- **Where:** `PrioritizedServiceRegistry.php`.
- **Problem / Fix:**
  - Typo `$priortyMap` (`:23`, used 13×) → `$priorityMap`; make it `private readonly PriorityMap`.
  - `getNextTo()`, `hasNextTo()`, `getPreviousIndex()`, `getIndex()` each implement their own lookup loop, with different comparison operators (`===` vs `==`, TODO-2 **B-04**). Replace with one `private function indexOf(string $identifier): ?int` (PHP 8.4: `array_find_key($keys, static fn ($k) => (string) $k === $identifier)`), and build all navigation on it.
  - `hasNextTo()` ends with a redundant `$this->has($keys[$nextIndex])` (`:112`) — the key came from the map. `isset($keys[$i + 1])` is enough.
  - `getIndex($identifier)` (`:168`) has no parameter type; the interface has `string`.
  - Each call of `getKeys()` re-sorts the whole map (`PriorityMap.php:114`). `getAllPreviousTo()` calls it, then `get()` per element. Cache the sorted key list in `PriorityMap` and invalidate it in `set()` / `remove()`.
  - Use `$service instanceof $this->interface` (TODO-2 **B-05**); constructor properties `private readonly string`.
  - Add `#[\Override]` to all interface methods (both registries).

### I-05 — Rewrite `PriorityMap` as a small typed, immutable-entry collection
- **Where:** `PriorityMap.php` (whole file).
- **Problem / Fix:**
  - Entries are `\stdClass` with dynamic `value` / `priority` / `sequence` properties. Use a `final readonly class PriorityMapEntry { public function __construct(public mixed $value, public int $priority, public int $sequence) {} }` (internal), or `array{value: mixed, priority: int, sequence: int}` shapes.
  - Missing return types: `set()` (returns the internal `\stdClass`, leaking internal state — return `void` or `static`), `get(): mixed`, `has(): bool`, `remove(): void`, `getKeys(): array`, `setAscOrder(): static`, `setDescOrder(): static`, `toArray(): array`. Missing parameter types on `get($key)`, `has($key)`, `remove($key)` (`set()` already requires `string`).
  - `getScalarKey()` (`:36-43`): the `spl_object_hash()` branch is dead (`set()` only accepts `string`); remove it and type keys as `string`.
  - Sorting by a string-built callable `[$this, $this->order . 'SortStrategy']` (`:114`, `:190`) hides two private methods from static analysis and IDEs. Use a `SortOrder` enum (`Asc`, `Desc`) and a single comparator with `<=>` (also fixes TODO-2 **B-06**).
  - `ORDER_ASC` / `ORDER_DESC`: typed class constants (`public const string ORDER_ASC = 'asc';`, PHP 8.3+) or replace with the enum.
  - Replace the hand-written `\Iterator` with `\IteratorAggregate::getIterator(): \Generator` (TODO-2 **B-07**).
  - Docblocks are stale or misspelled (`Mve iterator next`, `Get count or map`, `@return \stdClass`). Remove the ones the native types make redundant.
  - Make the class `final` (no subclasses in the codebase).

### I-06 — Exceptions: typed constructors, consistent messages, accessible data
- **Where:** `ExistingServiceException.php`, `NonExistingServiceException.php`.
- **Problem / Fix:**
  - Constructor parameters `$context`, `$type` are untyped; declare `string $context, string $type, array $existingServices` with `@param list<string|int>` for the list.
  - `ExistingServiceException` does not `ucfirst()` the context (`service of type "x" already exists.`), `NonExistingServiceException` does. The context is passed as e.g. `'checkout-manager-steps'`, which reads as "Checkout-manager-steps service …". Pick one format.
  - `NonExistingServiceException` with an empty list prints `available x services: ""`; print `none` instead.
  - Expose the data for callers (`public private(set) string $type`, PHP 8.4 asymmetric visibility, or `public readonly`) so controllers can show a friendly message without parsing text.
  - Add a marker `RegistryExceptionInterface` so callers can catch both.

---

## Autoconfiguration and compiler passes

### I-07 — (dependent) Replace getter reflection in `Autoconfiguration` with an explicit contract or native Symfony attributes
- **Where:** `Autoconfiguration.php`; all `Bundle/*/Attribute/As*.php`.
- **Problem / Fix:**
  - `throw new \Exception(...)` (`:43`) → `\InvalidArgumentException` / `LogicException`.
  - The `@var class-string $attribute` docblock (`:46-48`) sits above a method call, not the variable; type the parameter `@param class-string|null $attribute` instead.
  - The configurator closure re-uses the name `$attribute` for the attribute instance (shadows the outer class-string) and has no type; name it `$instance` (`object $instance, \Reflector $reflector`).
  - Method name `registerForAutoConfiguration` (capital C) differs from Symfony's `registerForAutoconfiguration`; add a correctly cased alias and deprecate the old one.
  - Reflection over `get*()` (`:57-72`) forces every attribute to keep private properties plus getters (noted as a blocker in a dozen bundle TODO-3 files). Prefer: attributes implement `TagAttributesProviderInterface::toTagAttributes(): array` returning the exact tag keys (fixes TODO-1 **C-01** and TODO-2 **B-14** together), **or** read `get_object_vars()` of `final readonly` attributes with public promoted properties and a key map. Then the bundle attributes can become `final readonly class` with public properties (PHP 8.4 `public private(set)` where mutation is needed).
  - Long term: `#[AutoconfigureTag('coreshop.xyz')]` on the interfaces plus `#[AsTaggedItem(index: 'type', priority: …)]` on implementations covers `type` and `priority` natively; only `form-type` needs a CoreShop attribute.

### I-08 — Deduplicate `RegisterRegistryTypePass` / `RegisterSimpleRegistryTypePass` and allow using them without subclasses
- **Where:** `RegisterRegistryTypePass.php`, `RegisterSimpleRegistryTypePass.php`; `PimcoreBundle/DependencyInjection/Compiler/RegisterTypeHintRegistriesPass.php:55-73` (third copy of the alias-name expression), `NotificationBundle/DependencyInjection/Compiler/AbstractNotificationRulePass.php` (fourth copy of the type derivation).
- **Problem / Fix:**
  - Both passes repeat the same "derive type, build `$map`, add `register` call, set parameter" loop. Extract a shared `RegistryTypeResolver` (or trait) with `resolveType(ContainerBuilder, string $id, array $tag): string` (TODO-2 **B-11**) and `aliasName(string $tag): string`.
  - `RegisterRegistryTypePass` calls `findDefinition($id)` inside the inner per-tag loop (`:48`); move it to the outer loop as the simple pass does.
  - Both classes (and `PrioritizedCompositeServicePass`) are `abstract` without abstract methods. The codebase has **63** subclasses that only forward constructor arguments. Make them concrete (`final` or `readonly` constructor props) so bundles can write `$container->addCompilerPass(new RegisterSimpleRegistryTypePass('…', '…', '…'))`, keep the abstract base classes as deprecated aliases for BC.
  - Constructor properties: `protected string` → `protected readonly string` (`private readonly` in `PrioritizedCompositeServicePass`).
  - Alias name: `str_replace(['coreshop.', '.'], …)` removes `coreshop.` anywhere in the tag, and `trim(preg_replace(...))` + `strtolower()` is hard to read. Use Symfony's `u($tag)->trimPrefix('coreshop.')->snake()->camel()` (String component) or a small documented helper; detect two registries producing the same alias name.
  - `$tag['priority'] ?? 1000` (`RegisterSimpleRegistryTypePass.php:68`) is a magic number; make it a constant and document it (see TODO-2 **B-06**).

### I-09 — `PrioritizedCompositeServicePass`: naming, Symfony priority support, configurable alias
- **Where:** `PrioritizedCompositeServicePass.php`.
- **Problem / Fix:**
  - The variable `$channelContextDefinition` (`:48`, `:65`, `:72`, copied from Sylius) → `$compositeDefinition`.
  - Only the tag attribute `priority` is read. Symfony's `PriorityTaggedServiceTrait::findAndSortTaggedServices()` also supports `#[AsTaggedItem(priority: …)]` and `getDefaultPriority()`; use it, then the composites can receive services in order instead of each one maintaining its own Laminas `PriorityQueue`.
  - `addAliasForCompositeIfServiceDoesNotExist()` always creates a **public** alias (TODO-2 **B-13**); make public-ness a constructor option (default `false`).

### I-10 — (dependent) Offer a lazy, `ServiceLocator`-backed registry
- **Where:** `ServiceRegistry.php`, `PrioritizedServiceRegistry.php`, both registry passes; registry definitions in the bundles' `services*.yml` (17 prioritized + 52 simple registries in the compiled container — verified).
- **Problem:** the passes add one `register($type, new Reference($id))` method call per service. Building a registry instantiates **every** registered checker / processor / calculator with all their dependencies (repositories, translators, …), even when one type is needed (e.g. one rule condition). This is paid on every request that touches a rule registry.
- **Fix:** add `LocatorServiceRegistry implements ServiceRegistryInterface` wrapping a `Psr\Container\ContainerInterface` / `ServiceProviderInterface` (built with `ServiceLocatorTagPass::register()` in the pass, or `#[AutowireLocator('coreshop.xyz', indexAttribute: 'type')]`). `has()` / `get()` stay lazy; `all()` instantiates on demand. For prioritized registries use a locator plus a pre-sorted key list computed at compile time (`#[AutowireIterator(..., defaultPriorityMethod: …)]`). Keep the current classes for BC and runtime registration (`DefaultCheckoutManagerFactory`).

### I-11 — Validate at compile time that tagged services implement the registry's interface
- **Where:** `RegisterRegistryTypePass.php`, `RegisterSimpleRegistryTypePass.php`; runtime check in `ServiceRegistry.php:42-46`, `PrioritizedServiceRegistry.php:43-47`.
- **Problem:** a wrongly tagged service is only detected when the registry is instantiated (runtime `InvalidArgumentException`). Real cases exist: `PaymentBundle/TODO-2-BUGS.md` **B-01** (the only tagged payment price calculator does not implement the registry's interface), `NotificationBundle/TODO-2-BUGS.md` **B-12** (action registry built with the condition interface).
- **Fix:** read the registry definition's first argument (the interface; resolve parameters), resolve each tagged service's class and check `$container->getReflectionClass($class)?->isSubclassOf($interface)` (or equality). Throw an `InvalidArgumentException` naming the service, the tag and the expected interface.

---

## Tests and documentation

### I-12 — Add unit and compiler-pass tests (none exist)
- **Where:** no tests reference `CoreShop\Component\Registry` (`tests/`, `spec/`, `features/` checked).
- **Fix:** PHPUnit tests for:
  - `ServiceRegistry` / `PrioritizedServiceRegistry`: register, duplicate, unknown id, interface vs class vs subclass (TODO-2 **B-05**), numeric identifiers (**B-04**), every navigation method at first / middle / last / unknown position (**B-01**–**B-03**).
  - `PriorityMap`: ASC/DESC, ties keep registration order (**B-06**), nested iteration (**B-07**), `remove()`.
  - Each pass with a `ContainerBuilder`: alias registry id (**B-09**), parameterised class (**B-10**), class-less child definition / global class (**B-11**), duplicate type (**B-12**), alias type for prioritized registries (**B-08**), decorators / duplicates / self in the composite (TODO-1 **C-02**).
  - `Autoconfiguration` with one attribute per bundle: tag keys match what the pass reads (TODO-1 **C-01**), getters with parameters / enums (**B-14**), both modes (**B-15**).

### I-13 — Document priority semantics and tag attributes in the README
- **Where:** `README.md` (generic boilerplate only; links use `http://www.coreshop.org`).
- **Fix:** document: ascending order in `PrioritizedServiceRegistry` (lower priority first — the **opposite** of Symfony's tagged iterators, where higher priority comes first), the tie-break rule, the default `1000`, the supported tag attributes (`type`, `priority`, `form-type`), how type defaults are derived, how to replace a built-in type, and the `autoconfigure_with_attributes` modes. Update links to `https://`.

### I-14 — PHP 8.4 / Symfony 7.4 modernisation checklist for the remaining files
- **Where:** all files.
- **Fix:**
  - `#[\Override]` on all interface and `CompilerPassInterface::process()` implementations.
  - `final` where there are no subclasses (`ServiceRegistry`, `PriorityMap`, both exceptions if no one extends them — check the monorepo first); `readonly` promoted properties throughout.
  - PHP 8.4 array helpers instead of manual loops: `array_find_key()` (index lookup), `array_any()` (interface checks over lists).
  - `sprintf()` → keep, but use `%s` with `get_debug_type($service)` instead of `$service::class` in error messages (clearer for proxies).
  - Remove docblocks that duplicate native types.

---

## Already tracked elsewhere (not repeated)
- Bundle attribute classes becoming `final readonly` (blocked by `getProperties()`, unblocked by **I-07**): `AddressBundle` TODO-3, `CustomerBundle` TODO-3, `CurrencyBundle` TODO-3, `LocaleBundle` TODO-3, `StoreBundle` TODO-3, `ShippingBundle` TODO-3, `SEOBundle` TODO-3, `RuleBundle` TODO-3, `NotificationBundle` TODO-3, `PimcoreBundle` TODO-3 **I-08**, `ProductQuantityPriceRulesBundle` TODO-3, `CoreBundle` TODO-3.
- `NotificationBundle/TODO-3-IMPROVEMENTS.md` **I-02** (do not extend `RegisterRegistryTypePass`), **I-04** (duplicate detection – see TODO-2 **B-12**).
- `LocaleBundle/TODO-3-IMPROVEMENTS.md` **I-01**, **I-03**, `FrontendBundle/TODO-3-IMPROVEMENTS.md` **I-18**, `Component/Address/TODO-3-IMPROVEMENTS.md` (tagged iterators instead of custom composites/registries – bundle side of **I-09** / **I-10**).
- `Component/Order/TODO-3-IMPROVEMENTS.md` (tests for `getAllPreviousTo()` – covered by **I-12**).
