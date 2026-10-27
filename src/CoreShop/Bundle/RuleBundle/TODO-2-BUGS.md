# RuleBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope and references: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs: wrong results, crashes in one path, wrong wiring, wrong config. Items with shop-wide data or availability impact are in TODO-1.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code against the vendored libraries or by reading the vendored library source or the compiled container. Items marked **(latent)** are not reachable today but break as soon as someone uses the feature.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Rule availability (maintenance task + `coreshop:rules:check-availability`)

### B-01 — `RuleAvailabilityAssessor::isValid()` crashes on incomplete timespan data and skips later conditions — (verified by reading + PHP run)
- **Where:** `Assessor/RuleAvailabilityAssessor.php:39-74`. Runs on every Pimcore maintenance run (`CoreBundle/Maintenance/RuleAvailabilityTask.php`) and from the command, for cart price, payment provider, shipping, product price and specific price rules.
- **Problems:**
  - `:47-49`: `$configuration['dateFrom'] / 1000` with no check. `getConfiguration()` can be `null` (column is nullable), keys can be missing (old/migrated data, API-created rules), and a non-numeric string (`''`) throws `TypeError: Unsupported operand types: string / int` (verified with PHP 8.4.27). Missing keys give warnings, which the debug `ErrorHandler` turns into exceptions.
  - One failing rule aborts `RuleAvailabilityProcessor::process()` before `flush()` (`Processor/RuleAvailabilityProcessor.php:47`), so **no** expired rule of any type is disabled in that run, every run.
  - `:53-58`: "future rule is also valid" `return true` leaves the loop on the first future timespan; a second, already expired timespan condition of the same rule is never checked.
  - `:60-64` is dead code: when `dateFrom` is in the future the method has already returned at `:55-57`.
  - Only top-level `timespan` conditions are seen; timespans inside `nested` conditions are ignored (acceptable, but undocumented).
- **Fix:** read the values with `$configuration['dateFrom'] ?? null`, skip the condition when they are not numeric, `continue` instead of `return true` for a future start, remove the duplicate block. Catch and log per rule in the processor so one bad rule does not stop the others. Share the time check with `TimeSpanConditionChecker` (TODO-3 **I-05**).

### B-02 — `RuleAvailabilityCheckEvent::$ruleType` is the PHP class name, not the rule type — (verified by reading)
- **Where:** `Processor/RuleAvailabilityProcessor.php:54`: `new RuleAvailabilityCheckEvent($rule, $rule::class, $ruleIsAvailable)`.
- **Impact:** listeners cannot tell `cart_price`/`shipping_rule`/`product_specific_price` apart by the registry type they were registered with. `$rule::class` is a Doctrine proxy class (`Proxies\__CG__\…`) when the rule was loaded as a proxy before, so a listener comparing with `CartPriceRule::class` silently does nothing. The event has no constant for its name (`'coreshop.rule.availability_check'` is a string literal).
- **Fix:** iterate `$this->ruleRegistry->all()` with keys and pass the registry key as `ruleType` (or add a separate `assessorType`), use `ClassUtils`/`DefaultProxyClassNameResolver` if the class is still needed, and add `RuleAvailabilityCheckEvent::NAME` (or dispatch without a name, using the class name).

### B-03 — Assessor autoconfiguration registers services without or with a duplicated type — (plausible)
- **Where:** `DependencyInjection/CoreShopRuleExtension.php:49-55`, `Attribute/AsRuleAvailabilityAssessor.php` (no constructor, no getters), `Component/Registry/RegisterSimpleRegistryTypePass.php` (missing `type` → `Container::underscore(<short class name>)`), `Component/Registry/ServiceRegistry.php:36-39` (throws `ExistingServiceException` on a duplicate key).
- **Impact:**
  - With interface autoconfiguration (default), every autoconfigured service that implements `RuleAvailabilityAssessorInterface` gets an extra tag **without** `type`. A project that defines two assessors with the generic `RuleAvailabilityAssessor` class in a file with `autoconfigure: true` gets both registered as `rule_availability_assessor` → `ExistingServiceException` when the registry is built: the maintenance task and the command fail every run. A service with an explicit tag is registered twice (explicit type + derived type) and processed twice.
  - With `autoconfigure_with_attributes: true`, `#[AsRuleAvailabilityAssessor]` cannot carry a type, so the same collision happens for two services of one class.
- **Fix:** give `AsRuleAvailabilityAssessor` a `type` (and `getType()`), skip the instanceof tag when the definition already has the tag (or document that the shared `RuleAvailabilityAssessor` class must be defined without autoconfigure), and fail early with a clear message on duplicate types.

## Debug / profiler wiring (`DependencyInjection/Compiler/TraceableValidationProcessorPass.php`, `Collector/`)

### B-04 — In debug (dev **and** test) the condition processors behave differently than in prod — (verified compiled container) (dependent: Component/Rule)
- **Where:**
  - `DependencyInjection/Compiler/TraceableValidationProcessorPass.php:40-52` decorates every service tagged `coreshop.rule.conditions.validation_processor` whenever `kernel.debug` is true.
  - All eight tagged processors are `shared: false` (Notification, CartItemPriceRule, CartPriceRule, PaymentProviderRule, ShippingRule, ProductPriceRule, ProductSpecificPriceRule, ProductQuantityPriceRules). The new decorator `Definition` is shared by default; `DecoratorServicePass` does not copy `shared`. Compiled dev container: `coreshop.product_price_rule.conditions.processor` is an alias of the shared `….decorated` service (`App_KernelDevDebugContainer.xml:42479`, `:57214`).
  - `Component/Rule/Condition/TraceableRuleConditionsValidationProcessor::isValid()` does **not** stop at the first failing condition, while `RuleConditionsValidationProcessor::isValid()` does.
- **Impact:** in dev and in the Behat/PHPUnit test kernel (debug on) one processor instance is shared by all consumers and every condition checker of a rule runs even after one has failed. Checkers that rely on an earlier condition (params set, voucher present) can throw only in debug, and tests do not exercise the prod code path.
- **Fix:** set `$decorator->setShared($container->findDefinition($serviceId)->isShared())`, make the traceable processor short-circuit like the inner one (record the remaining conditions as "not evaluated"), and only decorate when the profiler is enabled (`$container->hasDefinition('profiler')`) instead of on `kernel.debug` (TODO-3 **I-07**).

### B-05 — The traceable processors grow without limit and the collector stores live objects in the profile — (verified by reading, impact plausible) (dependent: Component/Rule)
- **Where:** `TraceableRuleConditionsValidationProcessor::$processed` keeps every subject, rule, action and condition object; it is never reset (no `ResetInterface`, no `kernel.reset` tag). `Collector/RuleCollector.php:53-72` copies these live objects into `$this->data`; `reset()` (`:75-78`) clears only the collector, not the processors.
- **Impact:**
  - Long-running debug processes (`messenger:consume` in dev, index rebuilds, imports) leak memory per processed subject; with B-04 the decorator is shared, so nothing is ever freed. In a worker the profiler data of request N also contains everything from requests 1…N-1.
  - The profile stores whole carts/products (Pimcore objects) and Doctrine entities with their collections: very large profile files, and the panel template (`Resources/views/Collector/rule.html.twig:48,54,74,95`) calls `getId`/`getName` on unserialized entities and proxies, which can fail for uninitialized proxies.
  - `processed` is keyed by `$subject->getId()` / `$rule->getId()`: unsaved subjects/rules (`null` id) are merged into one entry.
- **Fix:** store scalars only (subject class + id, rule id + name, condition type, result, `cloneVar($configuration)`), implement `ResetInterface` on the traceable processor and tag it `kernel.reset`, reset the processors from `RuleCollector::reset()`, and key by `spl_object_id()` when the id is `null`.

## Configuration (`DependencyInjection/`)

### B-06 — `autoconfigure_with_attributes` is a scalar node passed to a `bool` parameter — (verified by reading)
- **Where:** `DependencyInjection/Configuration.php:41` `scalarNode(...)->defaultFalse()`; `DependencyInjection/CoreShopRuleExtension.php:54,62` pass it to `Autoconfiguration::registerForAutoConfiguration(..., bool $autoconfigureWithAttributes)` under `strict_types=1`.
- **Impact:** any non-bool value (`'true'`, `'1'`, `1`, `~`) throws `TypeError` during container build; an env placeholder cannot work either (the value is needed at build time).
- **Fix:** `booleanNode('autoconfigure_with_attributes')->defaultFalse()`.

## Form types (`Form/**`) — used by every rule type in Order/Payment/Shipping/Product/Notification/ProductQuantityPriceRules

### B-07 — Collection subclasses never build `prototypes`, but `buildView()` iterates them — (latent)
- **Where:** `Form/Type/Core/AbstractConfigurationCollectionType.php:36-63`; `Form/Type/RuleConditionCollectionType.php:35-40` and `Form/Type/RuleActionCollectionType.php:35-40` override `buildForm()` without calling `parent::buildForm()`.
- **Impact:** the `prototypes` attribute is never set for any rule collection; `buildView()` does `foreach (null …)` → warning, which becomes an `ErrorException` in debug as soon as anybody calls `createView()` on a rule form (Twig admin, API docs, a project frontend form). Today the ExtJS admin only submits, so it is not hit.
- **Fix:** call `parent::buildForm($builder, $options)` in both subclasses (and in the `IndexBundle` copies), and use `$form->getConfig()->getAttribute('prototypes', [])` in `buildView()`. Better: build prototypes lazily in `buildView()` only (TODO-3 **I-08**).

### B-08 — Data mappers and the configurable element type assume things the forms do not guarantee — (latent)
- **Where / Problems:**
  - `Form/DataMapper/ConditionsFormMapper.php:51` / `ActionsFormMapper.php:51`: `$form->get('id')` throws `OutOfBoundsException` for an entry type without an `id` child; both mappers are public (`@internal` only by docblock) and reused by subclasses.
  - `:36-39` in both mappers: `mapDataToForms()` is a no-op (commented-out code), so entry forms never receive the stored entity. As a consequence `AbstractConfigurableRuleElementType`'s `PRE_SET_DATA`/`POST_SET_DATA` listeners (`Form/Type/AbstractConfigurableRuleElementType.php:44-63`) never see data in the non-nested case and are dead code there; the type is derived only from the submitted `type` in `PRE_SUBMIT`.
  - `AbstractConfigurableRuleElementType.php:64-76`: when the submitted `type` has no registered form type, any `configuration` field added in `PRE_SET_DATA` for the previous type is kept, so the new type's configuration is bound to the old type's form. `:101-108`: `$data->getType()` is called on `mixed` (fatal error for array data).
- **Fix:** guard with `$form->has('id')`, map existing entries in `mapDataToForms()` (or document clearly why not and remove the dead listeners), `$form->remove('configuration')` before re-adding / when the new type has no form, check `$data instanceof ConditionInterface || $data instanceof ActionInterface` in `getRegistryIdentifier()`.

## Repository (`Doctrine/ORM/RuleRepository.php`)

### B-09 — `findWithActionOfType()` / `findWithConditionOfType()` are not safe for all rule types — (latent)
- **Where:** `Doctrine/ORM/RuleRepository.php:35-55`.
- **Problems:** `innerJoin('o.actions', ...)` throws a `QueryException` for a rule entity without an `actions` association (ProductQuantityPriceRule has to override it). Both methods join a to-many association without `DISTINCT`, so the SQL returns one row per matching condition/action (the object hydrator de-duplicates the root entity, but `setMaxResults()`/pagination added by a subclass would be wrong). Parameters are untyped (`$conditionType`, `$actionType`).
- **Fix:** check `$this->getClassMetadata()->hasAssociation('actions')` (return `[]` otherwise), add `->distinct()`, type the parameters as `string` (interface in `Component/Rule/Repository/RuleRepositoryInterface.php` too, dependent).
