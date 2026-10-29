# Variant Component – TODO 3: Improvements (code quality, readability, logic, PHP 8.4 / Symfony 7.4 idioms)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
None of these are defects on their own. They make the component cleaner, safer, easier to test, or more idiomatic for the target stack.
Path conventions as in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Component items use the prefixes `CC-` (critical), `CB-` (bugs) and `CI-` (improvements).

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 language features

### CI-01 — Native return types on all interfaces
- **Where:** `AttributeCollectorInterface.php:41`, `Model/AttributeInterface.php:27,31,35,39`, `Model/AttributeColorInterface.php:27`, `Model/ProductVariantAwareInterface.php:28,35,42,50,52`
- Missing return types:
  - `AttributeCollectorInterface::getIndex()` → `: array` (`array<int, ResolvedIndex>`)
  - `AttributeInterface::setName/setValueText/setAttributeGroup/setSorting`, `AttributeColorInterface::setValueColor`, `ProductVariantAwareInterface::setName/setAttributes/setAllowedAttributeGroups/setMainVariant` → `: static` (as `AttributeGroupInterface` already does; Pimcore-generated setters return `static`, so the generated classes stay compatible – verify by regenerating the class definitions).
  - `ProductVariantAwareInterface::getVariantParent()` → `: ProductVariantAwareInterface` (or `?…`, see CC-01). The trait already declares `: Concrete`, which is narrower than the interface and weaker than the domain type.
- Keep `strict_types` and add the return types in the same release as the matching implementations (BC break for third-party implementers → note in UPGRADE).

### CI-02 — `final`, `readonly`, `#[\Override]`, constructor promotion
- `AttributeCollector` → `final` (decorate via the interface), `private readonly EventDispatcherInterface $eventDispatcher`, `#[\Override]` on the four interface methods.
- `VariantAvailabilityEvent` → `final`, `private readonly ProductVariantAwareInterface $product`.
- `Model/Resolved/*` → `final`; remove the odd empty constructor parameter lists (`ResolvedAttributeGroup.php:34-37`, `ResolvedIndex.php:29-32`: `public function __construct(\n        ) {`), use constructor promotion for required values (CB-10).
- Use `$attribute::class` instead of `get_class($attribute)` (`AttributeCollector.php:86`).

### CI-03 — Generics / precise doc types instead of `@var` casts
- `@param list<ProductVariantAwareInterface> $products` on `getAttributes()`, `@return list<ResolvedAttributeGroup>` (after `usort()` the array is a list), `@return array<int, ResolvedIndex>` on `getIndex()`.
- `@return list<AttributeInterface>` on `AttributeGroupInterface::getAttributes()`, `@return list<static>` on `getVariants()`.
- Remove the inline `@var` / `@psalm-var` / `@psalm-suppress` blocks in `AttributeCollector.php:66-73,140-142`, `ProductVariantTrait.php:72-74`, `ResolvedAttribute.php:76-78` once the types are expressed in signatures.

---

## AttributeCollector

### CI-04 — Simplify `getAttributes()` and remove dead code
- `AttributeCollector.php:93-102`: the `if/else` plus the trailing `addAttribute()` (a no-op for an existing entry) can be:
  ```php
  $resolved = $group->getAttribute($attribute->getId()) ?? new ResolvedAttribute($attribute);
  $resolved->addProduct($product);
  $group->addAttribute($resolved);
  ```
- `AttributeCollector.php:145-146`: `$attribute = $attributeGroup->getAttributes(); $attribute = reset($attribute);` is dead (the value is never used).
- `AttributeCollector.php:132-135`: the `instanceof` check is redundant once `getVariants()` is typed; the `[$variant]` call re-dispatches the availability event per variant (see CI-05).
- Validate input at the boundary: the Twig functions pass user-template data straight into `getAttributes(array $products)`; a non-`ProductVariantAwareInterface` element currently fails deep inside `VariantAvailabilityEvent`'s constructor. `Assert::allIsInstanceOf()` gives a clear message.
- `usort()` closure: `$a->getGroup()->getSorting() <=> …` – use `?? 0.0` to avoid the `null` transitivity issue described in CB-02.

### CI-05 — Avoid repeated work on the product page
- `_variants.html.twig` calls `coreshop_variant_attribute_list_object(product)` **and** `coreshop_variant_index(product)`. Each call:
  - resolves the variant parent and runs a new `getVariants()` listing query,
  - dispatches the availability event for every variant,
  - loads every attribute and attribute group.
  `getIndex()` additionally builds a separate `ResolvedAttributeGroup` set per variant and calls the link generator per variant.
- `ResolvedAttributeGroup::getAttributes()` re-sorts on every call (Twig loops, `serializeGroups()`, `ResolvedIndex::addAttribute()`).
- Proposal: a single `resolve(ProductVariantAwareInterface $product, bool $showInList): VariantMatrix` method returning groups **and** index from one pass; sort once on insertion or cache the sorted array; memoise per request (`ResetInterface`/`kernel.reset` to stay worker-safe).

### CI-06 — Name the pre-condition event
- `'coreshop.attribute.collector.preCondition'` is a magic string (`AttributeCollector.php:60`) and undocumented.
- Add `final class VariantEvents { public const string ATTRIBUTE_COLLECTOR_PRE_CONDITION = 'coreshop.attribute.collector.preCondition'; }` (PHP 8.3+ typed class constants), or dispatch without a name so listeners can use `#[AsEventListener(event: VariantAvailabilityEvent::class)]`; keep the old name as an alias for BC.
- Consider `StoppableEventInterface`: once one listener sets `conditionMet = false`, the remaining listeners need not run (`setConditionMet(false)` + `stopPropagation()`).
- Document the event in `docs/03_Bundles/Variant_Bundle.md` (see CB-05).

---

## Models

### CI-07 — `ProductVariantAwareInterface` / `ProductVariantTrait` clean-up
- `ProductVariantTrait.php:61`: compare with `AbstractObject::OBJECT_TYPE_VARIANT` instead of the literal `'variant'` (already used elsewhere in the same file).
- `ProductVariantTrait.php:107-111`: the trailing `count($variants) > 0` check is redundant after `if (!$variants) return null;` – `return reset($variants) ?: null;`.
- `ProductVariantTrait.php:34`: `$this::getList()` instantiates a throw-away object (`AbstractObject::getList()` does `new static()`); `static::getList()` is equivalent and clearer; better: a dedicated repository method.
- `findMainVariant()` sorts inside the comparator (`usort()` of both attribute lists on **every** comparison → O(n log n · m log m)). Compute each variant's sort key once (Schwartzian transform) – this is also where the CB-02 fix lives.
- `setMainVariant(?Concrete $purchasable)`: parameter name is a leftover from another model; type it as `?ProductVariantAwareInterface $mainVariant` (and `getMainVariant(): ?ProductVariantAwareInterface`) – the field is meant to hold a variant of this product.
- `setAttributes(array $attributes)` / `setAllowedAttributeGroups(array $allowedGroups)` cannot be cleared to `null` through the interface although the getters return `?array`; use `?array` for symmetry with the Pimcore-generated setters.
- `findMainVariant(): ?self` in the interface vs `?ProductVariantAwareInterface` in the trait – use `?static` in both for precise IDE/phpstan types.

### CI-08 — `AttributeGroup::getAttributes()`
- `Model/AttributeGroup.php:25-35`:
  - Returns children in tree order, but every consumer sorts by `getSorting()` – return them already sorted (and document it).
  - Only **direct** children are returned; the bundle listener assigns groups to deeper descendants too. Settle the rule as described in VariantBundle TODO-2 **B-09** and implement it here.
  - `getChildren()` results are cached per object instance (`AbstractObject::$children`); in long-running workers the list becomes stale after attributes are added. Either document it or query a fresh listing.
  - `array_values(array_filter($this->getChildren([self::OBJECT_TYPE_OBJECT])->getObjects(), static fn ($o) => $o instanceof AttributeInterface))` is shorter and clearer than the manual loop.

### CI-09 — `Model/Resolved/*` as small value objects
- Required data as constructor arguments (fixes CB-10); remove setters that are not needed by the collector (`setAttributes()`, `setProducts()`, `removeAttribute()`, `removeProduct()` have no callers in the repository – grep).
- Consistent return types: `ResolvedIndex::getAttributes()` returns an `ArrayCollection` while `ResolvedAttributeGroup::getAttributes()` / `ResolvedAttribute::getProducts()` return arrays. Return `array<int,int>` everywhere.
- `ResolvedIndex::addAttribute(?ResolvedAttributeGroup $attributeGroup)`: the nullable parameter has no caller passing `null` – make it non-nullable.
- With plain arrays the `doctrine/collections` dependency (CB-11) can be dropped entirely.
- `ResolvedAttributeGroup::$selected` uses `0` as "nothing selected"; use `?int $selected = null` so `0` is not a magic value.

### CI-10 — Empty abstract model classes
- `Model/Attribute.php`, `Model/AttributeColor.php`, `Model/AttributeValue.php` are empty `abstract` classes used as Pimcore parent classes. Add a short class doc block saying so (and that `AttributeColor`/`AttributeValue` must stay in the hierarchy for the `instanceof` checks in `VariantBundle`), so they are not "cleaned up" as dead code.

---

## Packaging, docs and tests

### CI-11 — `composer.json` hygiene
- Fix the indentation of `"extra"` (`composer.json:47`, three spaces).
- Align `branch-alias` with the root (`dev-next`/`dev-4.1` → `4.1-dev`; this file has `dev-master`).
- After CB-11: run `composer validate --strict` and `composer normalize` for the component.

### CI-12 — Service definition belongs next to the component's consumers, autowired
- `AttributeCollector` is wired in `VariantBundle/Resources/config/services.yml:9-12` as a **public** service with an explicit `'@event_dispatcher'` argument. Make it private + autowired (`EventDispatcherInterface` is autowirable) and keep the interface alias public only if third parties fetch it from the container (see VariantBundle TODO-3 **I-05**).

### CI-13 — README / documentation
- `README.md` is the generic CoreShop boilerplate (and links to the outdated `coreshop.org` domain over `http://`). Add a short usage section: what `ProductVariantTrait` expects from the using class (`attributes`, `allowedAttributeGroups`, `mainVariant` fields), the `AttributeCollector` API, the pre-condition event and the `Resolved*` structure consumed by the frontend JS.

### CI-14 — Extract main-variant selection into a replaceable service
- Selection logic currently lives in a model trait (`findMainVariant()`), so projects cannot change it ("cheapest", "first in stock", "first published") without overriding the Pimcore model class. Provide `MainVariantResolverInterface` + default implementation in this component; keep `findMainVariant()` as a thin BC wrapper. See also VariantBundle TODO-3 **I-12**.

### CI-15 — Unit tests
- There are no tests for this component. The logic is pure enough to unit-test with mocks:
  - `ProductVariantTrait::getVariantParent()`: normal chain, foreign parent, `null` parent (CC-01).
  - `getVariants()` path escaping (CC-02) – at least an assertion on the generated condition/parameters.
  - `findMainVariant()`: count mismatch, `null` sorting, tie-breaker, missing group (CB-01, CB-02).
  - `AttributeCollector::getAttributes()` grouping/sorting/`showInList`, event filtering, `getIndex()` with a filtered variant and a missing link generator (CB-05, CB-06).
  - `Resolved*` key invariants (CB-09).
- Add the component to the root phpstan configuration at the root's level once CB-11 is done.
