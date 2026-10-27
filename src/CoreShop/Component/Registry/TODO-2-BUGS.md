# Registry Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are wrong behaviours, crashes in limited areas, and contract violations that do not meet the "critical" bar.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have part of the fix or the visible symptom outside this component. Items marked **(reproduced)** were checked with a PHP 8.4.27 script against the repo classes (`ContainerBuilder` from the vendor, `symfony/dependency-injection` 7.4.21). Items marked **(verified container)** were checked in the compiled dev container. Items marked **(plausible)** follow from the code, but the trigger depends on data or custom code.

**Overlap with other TODOs:** see the list at the end of this file. Bundle items that only describe the symptom of a root cause listed here are referenced, and should be closed together with the fix here.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## `PrioritizedServiceRegistry` navigation (checkout steps)

### B-01 — (reproduced) `getAllPreviousTo()` never returns the first entry
- **Where:** `PrioritizedServiceRegistry.php:150-166` (`for ($i = $prevIndex; $i > 0; --$i)`). Consumer: `Component/Order/Checkout/CheckoutManager.php:74-77` → `FrontendBundle/Controller/CheckoutController.php:65-69` ("check all previous steps").
- **Problem:** index 0 is excluded. Steps `customer, address, shipping, payment, summary` → `getAllPreviousTo('summary')` returns 3 entries (no `customer`), `getAllPreviousTo('address')` returns `[]`. The first checkout step is never re-validated before a later step is shown.
- **Fix:** `for ($i = $prevIndex; $i >= 0; --$i)`, or `array_reverse(array_slice($objects, 0, $index))`. Same item as `Component/Order/TODO-2-BUGS.md` **B-23** (consumer side) — fix here.

### B-02 — (reproduced) `getNextTo()` with an unknown identifier emits a warning and then throws a `TypeError`
- **Where:** `PrioritizedServiceRegistry.php:75-93`.
- **Problem:** when the identifier is not found, `$nextIndex` stays `-1`; `count($keys) > -1` is always true, so `$keys[-1]` is read ("Undefined array key -1" — an `ErrorException` under Symfony's debug error handler) and `get(null)` throws `TypeError: get(): Argument #1 ($identifier) must be of type string, null given`. The interface returns `?object`, so callers expect `null`. Triggered by `CheckoutController` for a step that was removed by `OptionalCheckoutStepInterface::isRequired()` (stale tab, back button).
- **Fix:** return `null` when the identifier is not registered (`if (!$this->has($identifier)) return null;`), then `return $keys[$index + 1] ?? null` → `get()`.

### B-03 — (reproduced) `getIndex()` returns `false` into an `int` return type
- **Where:** `PrioritizedServiceRegistry.php:168-173`; interface `PrioritizedServiceRegistryInterface.php:43`. Consumers: `Component/Order/Checkout/CheckoutManager.php:93-96`, `CoreBundle/Twig/CheckoutIdentifierExtension.php:69`, `:86`, `FrontendBundle/Controller/CheckoutController.php:121`.
- **Problem:** `array_search()` returns `false` for an unknown identifier → `TypeError: getIndex(): Return value must be of type int, false returned` → HTTP 500 in the checkout and in the step navigation Twig function.
- **Fix:** throw `NonExistingServiceException` (consistent with `get()`), or return `-1` and document it. Same item as `Component/Order/TODO-2-BUGS.md` **B-24** (third sub-point) — fix here.

### B-04 — (reproduced) Numeric-string identifiers break all strict comparisons
- **Where:** `PriorityMap.php:54-62`, `:112-117` (array keys); `PrioritizedServiceRegistry.php:80-81`, `:100-101`, `:172` (`===`) vs. `:121` (`==`).
- **Problem:** PHP casts numeric-string array keys (`'1'`, `'20'`) to `int`. `getKeys()` / `all()` return `int` keys, so `$key === $identifier` never matches. Reproduced with identifiers `'1'`, `'2'`: `hasNextTo('1')` → `false`, `getNextTo('1')` → warning + `TypeError` (B-02), `getIndex('2')` → `TypeError` (B-03). `getPreviousIndex()` uses loose `==`, so `hasPreviousTo()` works while `hasNextTo()` does not. `all()` also returns `int` keys although the interface implies `string` identifiers.
- **Fix:** compare as strings (`(string) $key === $identifier`) in one shared `indexOf()` helper, or store entries in a list (`list<array{id: string, …}>`) instead of a keyed array. Same applies to `ServiceRegistry::all()` (keys `'1'` become `1`).

### B-05 — (reproduced) The interface check rejects valid services when the registry is typed against a class
- **Where:** `PrioritizedServiceRegistry.php:43-47`, `ServiceRegistry.php:42-46`.
- **Problem:** `in_array($this->interface, class_implements($service), true)` only checks interfaces. If the registry's "interface" is a (base) class:
  - `PrioritizedServiceRegistry` rejects **every** service, even an instance of exactly that class (reproduced: `Service needs to implement "A", "A" given.`).
  - `ServiceRegistry` accepts the exact class (`!== $service::class`) but rejects subclasses (reproduced: `"A", "B" given`).
  - The two registries behave differently for the same input.
- **Fix:** `if (!$service instanceof $this->interface)` in both (covers interfaces, classes and subclasses). Validate in the constructor that `$interface` is an existing class or interface (`interface_exists() || class_exists()`).

---

## `PriorityMap`

### B-06 — (reproduced, dependent) Equal priorities are ordered in **reverse** registration order, in both directions
- **Where:** `PriorityMap.php:159-166`, `:176-183` (`$declaration1->sequence < $declaration2->sequence ? 1 : -1` in both strategies); `RegisterSimpleRegistryTypePass.php:68` (default priority `1000` for every tag without `priority`).
- **Problem:** three entries registered `first, second, third` with the same priority come out as `third, second, first`, for ASC **and** DESC. Every tag without an explicit `priority` gets `1000`, so all of them tie and run in reverse service-definition order. For "last one wins" chains (composite purchasable / product / shipping price calculators, SEO extractors) the **first**-defined service wins. Symfony's own tagged-iterator convention keeps definition order on ties. The comparator also never returns `0` (`compare($a, $a)` → `-1`), which is not a valid total order.
- **Fix:** `return [$a->priority, $a->sequence] <=> [$b->priority, $b->sequence]` (ASC) and `[$b->priority, $a->sequence] <=> [$a->priority, $b->sequence]` (DESC) — stable by registration order. This changes existing orders, so list it in the UPGRADE notes and set explicit priorities in the bundle YAML first (`SEOBundle/TODO-3-IMPROVEMENTS.md` **I-09**, `ShippingBundle/TODO-2-BUGS.md` **B-02**, `Component/Order/TODO-3-IMPROVEMENTS.md` **I-12**).

### B-07 — (reproduced) `PriorityMap` iteration is not re-entrant; `current()` on an exhausted map warns
- **Where:** `PriorityMap.php:188-226` (`\Iterator` on the internal array pointer), `:233-236` (`toArray()` → `iterator_to_array($this)` rewinds the same pointer); `PrioritizedServiceRegistry::all()` → `toArray()`.
- **Problem:** any `toArray()` / `all()` / nested `foreach` over the same map while an outer `foreach` is running resets the shared pointer; the outer loop then stops early (reproduced: 2 entries, outer loop sees 1). `current()` after the end reads `false->value` → "Attempt to read property "value" on false". `getKeys()` also re-sorts the list in place on every read, which moves the pointer as well.
- **Fix:** implement `\IteratorAggregate` with a generator over a sorted copy (`yield $key => $entry->value`), drop the `\Iterator` methods. See TODO-3 **I-05**.

---

## Compiler passes

### B-08 — (verified container, dependent) `RegisterSimpleRegistryTypePass` registers a `ServiceRegistryInterface` autowiring alias for **prioritized** registries
- **Where:** `RegisterSimpleRegistryTypePass.php:49-54` (always `ServiceRegistryInterface::class`, even when `$isPrioritizedRegistry` is `true`).
- **Problem:** `PrioritizedServiceRegistry` does not implement `ServiceRegistryInterface`. The compiled container has **31** `CoreShop\Component\Registry\ServiceRegistryInterface $…` aliases that point to one of the 17 `PrioritizedServiceRegistry` services (14 `…ServiceRegistry` aliases from this pass, e.g. `$orderPurchasablePriceCalculatorServiceRegistry`, `$shippingPriceCalculatorServiceRegistry`, `$seoExtractorServiceRegistry`; 17 `…Registry` aliases from `PimcoreBundle/DependencyInjection/Compiler/RegisterTypeHintRegistriesPass.php`). A service that autowires one of these names gets a `TypeError` when it is built. There is no `PrioritizedServiceRegistryInterface $…` alias at all.
- **Fix:** register the alias with `$isPrioritizedRegistry ? PrioritizedServiceRegistryInterface::class : ServiceRegistryInterface::class`. Fix `RegisterTypeHintRegistriesPass` the same way (`PimcoreBundle/TODO-2-BUGS.md` **B-15**, `SEOBundle/TODO-2-BUGS.md` **B-09**).

### B-09 — (reproduced) Both registry passes crash when the registry id is an alias
- **Where:** `RegisterRegistryTypePass.php:38-43`, `RegisterSimpleRegistryTypePass.php:37-41`.
- **Problem:** `$container->has($id)` is `true` for aliases, then `$container->getDefinition($id)` throws `ServiceNotFoundException: You have requested a non-existent service "…"` (reproduced). An app that replaces a CoreShop registry by aliasing its id to its own definition breaks the container build.
- **Fix:** use `hasDefinition() || hasAlias()` and `findDefinition()` (as `PrioritizedCompositeServicePass` already does).

### B-10 — (reproduced, plausible) The prioritized-registry detection fails for parameterised or inherited classes and then registers with the wrong signature
- **Where:** `RegisterSimpleRegistryTypePass.php:41-47` (`class_implements($registry->getClass())`), `:67-71`.
- **Problem:**
  - Class `%some.registry.class%`: `class_implements()` emits a PHP warning ("Class %…% does not exist and could not be loaded", an exception under the debug error handler) and returns `false`, so the registry is treated as non-prioritized. Reproduced: the method call becomes `register('x', Reference)` (2 arguments) on a `PrioritizedServiceRegistry` → `ArgumentCountError`/`TypeError` when the registry is instantiated at runtime.
  - Class `null` (a `ChildDefinition` with `parent:` before `ResolveChildDefinitionsPass`): `class_implements(null)` → `TypeError` at compile time under `strict_types`.
- **Fix:** resolve the class first (`$container->getParameterBag()->resolveValue($definition->getClass())`, follow `ChildDefinition` parents) and use `$container->getReflectionClass($class, false)?->implementsInterface(PrioritizedServiceRegistryInterface::class)`. Throw a clear `InvalidArgumentException` when the class cannot be resolved.

### B-11 — (reproduced) Deriving the default `type` from the class name throws `TypeError` for class-less and namespace-less definitions
- **Where:** `RegisterRegistryTypePass.php:50-52`, `RegisterSimpleRegistryTypePass.php:61-63` (`Container::underscore(substr(strrchr($definition->getClass(), '\\'), 1))`).
- **Problem:** under `strict_types`:
  - class `null` (a tagged `parent:` child definition without its own `class`) → `strrchr(): Argument #1 ($haystack) must be of type string, null given`;
  - a class without namespace → `strrchr()` returns `false` → `substr(): Argument #1 ($string) must be of type string, false given`;
  - a `%parameter%` class → the type is derived from the parameter name, not the class.
  All are compile-time failures or silently wrong types. NotificationBundle has a third copy of this expression (`NotificationBundle/TODO-2-BUGS.md` **B-11**).
- **Fix:** one shared helper: resolve the class (see B-10), then `Container::underscore((new \ReflectionClass($class))->getShortName())`; throw a clear error ("tag `x` on service `y` needs a `type` attribute") when there is no class.

### B-12 — (verified, dependent) Duplicate `type` values are only detected when the registry is instantiated at runtime
- **Where:** `RegisterRegistryTypePass.php:46-63`, `RegisterSimpleRegistryTypePass.php:57-73` (`$map[$type] = $type` silently overwrites; one `register` call per tag); `ServiceRegistry.php:38-40`, `PrioritizedServiceRegistry.php:39-41` (`ExistingServiceException`).
- **Problem:** two services with the same `type` (e.g. a project service meant to replace a built-in rule condition, or an autoconfigured class without `type` whose short name matches a built-in) compile fine. The registry then throws `ExistingServiceException` the first time it is built — in the admin rule editor, the cart or a worker — not at `cache:clear`. For `RegisterRegistryTypePass`, the form registry has already been given the second form type, so form and checker can disagree.
- **Fix:** collect `type → service id` in the pass and throw an `InvalidArgumentException` naming both service ids at compile time, or support an explicit `replace: true` tag attribute / decoration. Owned here; consumer items: `SEOBundle/TODO-2-BUGS.md` **B-08**, `NotificationBundle/TODO-3-IMPROVEMENTS.md` **I-04**, `RuleBundle/TODO-2-BUGS.md` **B-03**.

### B-13 — (verified container, dependent) `PrioritizedCompositeServicePass` aliases a shared interface to whichever composite runs first, and makes the alias public
- **Where:** `PrioritizedCompositeServicePass.php:56-63` (`if ($container->has($this->serviceId)) return; … ->setPublic(true)`).
- **Problem:** when several composites are registered with the same `serviceId` (StorageList passes the generic `StorageListContextInterface` for every list), the first pass wins silently; the compiled container aliases the generic interface to the **wishlist** context (`StorageListBundle/TODO-2-BUGS.md` **B-13**). The alias is also forced `public`, which keeps the composite out of dead-service removal and exposes it via `$container->get()` without need.
- **Fix:** detect that the existing alias points to a different composite and throw (or skip the alias when `serviceId` is shared); do not force `setPublic(true)` — let the bundle decide.

---

## `Autoconfiguration`

### B-14 — (reproduced) `getProperties()` calls every public `get*()` method of the attribute, including ones with parameters or non-scalar results
- **Where:** `Autoconfiguration.php:57-72`.
- **Problem:**
  - A public getter with a required parameter → `ArgumentCountError` during container compilation (reproduced).
  - A method named exactly `get` → tag key `''`.
  - A getter returning an object or enum (e.g. a `BackedEnum` priority/strategy) → the tag attribute is not scalar → `CheckDefinitionValidityPass` fails the build ("A tag attribute must be of a scalar-type").
  - Static and inherited public `get*` methods are read too.
  - `null` values are written as tag attributes (`type => null`, `priority => null`). Passes that use `isset()` treat them as missing, but passes that use `array_key_exists()` would not.
- **Fix:** only read non-static, parameter-less getters declared on the attribute (`getNumberOfRequiredParameters() === 0 && !isStatic()`), skip `null`, and reject non-scalar values with a clear message — or replace reflection with an explicit contract (TODO-3 **I-07**).

### B-15 — (plausible) `autoconfigure_with_attributes` silently switches registration mode: attributed classes are mis-registered in one mode, attribute-less implementations disappear in the other
- **Where:** `Autoconfiguration.php:33-55`.
- **Problem:**
  - `false` (default): the attribute is ignored. A class with `#[AsXyz(type: 'foo', formType: …)]` that also implements the interface is tagged **without** attributes, so its type is derived from the class name (B-11) and its form type is lost.
  - `true`: the interface autoconfiguration is not registered at all. Existing project classes that rely on implementing the interface (the documented way before attributes) stop being registered without any error; their stored rules then fail at runtime with `NonExistingServiceException`.
- **Fix:** register both: interface autoconfiguration for classes without the attribute, and the attribute configurator for classes with it (skip the interface tag when the attribute is present, e.g. by checking `$definition->getTags()` in the configurator or by putting `#[AutoconfigureTag]` on the interface and using the attribute only for tag attributes). Then the flag can be deprecated.

---

## Already tracked elsewhere (not repeated)
- `Component/Order/TODO-2-BUGS.md` **B-23** (= B-01, consumer side), **B-24** (`CheckoutManager::getStep()` / `getPreviousStep()` nullability; its `getIndex()` part = B-03).
- `Bundle/PaymentBundle/TODO-2-BUGS.md` **B-01** and `Bundle/NotificationBundle/TODO-2-BUGS.md` **B-12** (a registry that cannot be instantiated because a tagged service does not implement its interface – bundle config; compile-time check proposed in TODO-3 **I-11**).
- `Component/Payment/TODO-2-BUGS.md` **B-03**, `Component/Notification/TODO-2-BUGS.md` (`RuleApplier` → `ServiceRegistry::get()` for an unknown stored type throws) – the throwing `get()` is the correct registry contract; the fix belongs to the callers (`has()` first).
- `Bundle/ShippingBundle/TODO-2-BUGS.md` **B-02** (attribute has no `priority`), `Bundle/SEOBundle/TODO-3-IMPROVEMENTS.md` **I-09** (explicit extractor priorities) – bundle side of B-06.
- `Bundle/LocaleBundle/TODO-2-BUGS.md` **B-04**, `Bundle/CurrencyBundle/TODO-2-BUGS.md` **B-18**, `Bundle/StoreBundle/TODO-2-BUGS.md` **B-04** – root cause is TODO-1 **C-02**.
