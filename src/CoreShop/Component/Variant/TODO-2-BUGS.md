# Variant Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope, path conventions and markers (**dependent**, **reproduced**, **plausible**) are the same as in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Component items use the prefixes `CC-` (critical), `CB-` (bugs) and `CI-` (improvements); bundle items are `C-`, `B-`, `I-` in `Bundle/VariantBundle/TODO-*.md`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Model / ProductVariantTrait

### CB-01 — `findMainVariant()` throws on a variant without attributes or on an attribute without group, which blocks saving the parent product — (plausible)
- **Where:** `Model/ProductVariantTrait.php:81-105`. Called from `VariantBundle/EventListener/MainVariantListener.php:51` (`preUpdate` of the **parent**). Bundle side: VariantBundle TODO-2 **B-07**.
- **Problem:**
  - `$a->getAttributes()` is `?array` by contract (`ProductVariantAwareInterface.php:33`). `usort(null, …)` → `TypeError` under `strict_types`.
  - `$attributeA->getAttributeGroup()->getSorting()`: `getAttributeGroup()` is `?AttributeGroupInterface`; an attribute whose group was deleted, or that sits outside a group (bundle **B-09**), → `Error: Call to a member function getSorting() on null`.
  - Because this runs in the parent's `preUpdate`, one broken variant makes the **parent product unsaveable** in the admin (and in imports).
- **Fix:** `$a->getAttributes() ?? []`, `$attr->getAttributeGroup()?->getSorting() ?? 0.0`, `$attr->getSorting() ?? 0.0`. Skip variants without attributes instead of crashing.

### CB-02 — `findMainVariant()` ordering is inconsistent and non-deterministic — (reproduced)
- **Where:** `Model/ProductVariantTrait.php:81-105`, `:31-44` (no order key on the listing)
- **Problem:**
  - Arrays are compared with `<=>`, which compares the **element count first**: `[1.0] <=> [0.0, 0.0]` is `-1` (reproduced). A variant with fewer attributes (e.g. an incomplete one) always wins, regardless of sorting values.
  - `null` sorting values break transitivity: `null <=> 0.0` is `0` but `null <=> -1.0` is `-1` (reproduced; `null` is compared as `false`). With mixed `null` / negative / zero values the comparator is not a total order and `uasort()` returns an arbitrary result.
  - Only the attribute sorting values are compared; the attribute **groups** are not. Two variants whose attributes come from different groups are compared position by position as if they were the same group.
  - Ties (same sorting values, e.g. all `null` – the default for new attributes) keep the listing order. `getVariants()` sets no `orderKey`, so the order is whatever MySQL returns – it can change after an index rebuild or between MySQL/MariaDB versions, and the main variant flips on the next parent save.
- **Fix:** build an explicit sort key per variant: list of `[groupSorting ?? 0, groupId, attributeSorting ?? 0, attributeId]` tuples ordered by group, compare tuple by tuple, and use the variant ID as final tie-breaker. Set an explicit order on the listing (`setOrderKey('o_id')` / `id`).

### CB-03 — `getAttributes()` returning `null` triggers PHP warnings (→ HTTP 500 in debug) in `findAttributeForGroup()` and `AttributeCollector` — (reproduced warning) (plausible trigger)
- **Where:** `Model/ProductVariantTrait.php:46-55`, `AttributeCollector.php:69`
- **Problem:**
  - `foreach ($product->getAttributes() as …)` with `null` emits `Warning: foreach() argument must be of type array|object, null given` (reproduced on 8.4.27). Symfony's `ErrorHandler` turns warnings into `ErrorException` in debug mode → the product page 500s; in prod the variant is silently treated as having no attributes and the warning floods the log.
  - Trigger: a variant created manually without attributes (the `attributes` field inherits; the parent normally has none), or a product passed to `coreshop_variant_attribute_list()` that is not a variant.
  - `findAttributeForGroup()` compares `getId() === getId()` – both are `?int`; for unsaved objects `null === null` returns the wrong attribute.
- **Fix:** `foreach ($product->getAttributes() ?? [] as $attribute)`; in `findAttributeForGroup()` use `$attribute->getAttributeGroup()?->getId()` and require a non-null ID.

### CB-04 — `getVariants()` has a dead `else` branch that would throw `TypeError`
- **Where:** `Model/ProductVariantTrait.php:39-41`
- **Problem:** `getChildren()` returns `Pimcore\Model\DataObject\Listing` (`vendor/six-it/pimcore-source/models/DataObject/AbstractObject.php:327-334`), not an array, but the method is declared `: array` and the file is `strict_types` → `TypeError` if the branch is ever reached. It also only returns **direct** children (unlike the `if` branch) and **excludes unpublished** ones (`$includingUnpublished = false`), so the two branches disagree.
- **Fix:** remove the branch (the trait is only meaningful on `Concrete`; enforce it with an `assert`/exception), or use `iterator_to_array($this->getChildren([...], true)->getObjects())`-equivalent semantics matching the main branch.

---

## AttributeCollector

### CB-05 — Unavailable / inactive variants still appear in the redirect index and the selector, linking customers to 404 pages — (dependent) (plausible)
- **Where:** `AttributeCollector.php:56-64` (pre-condition event), `:123-153` (`getIndex()`). No listener for `coreshop.attribute.collector.preCondition` exists anywhere in the repository (grep). 404 rule: `FrontendBundle/Controller/ProductController.php` (`getActive() !== true`).
- **Problem:**
  - Out of the box nothing filters variants by `active` (or stock); the event is a hook nobody uses. Inactive variants are offered in the selector and the JS (`coreshop.plugin.variant.js:129-146`) redirects to their URL → 404.
  - `getIndex()` does not honour the event: a variant rejected by a listener still gets an index entry (with an empty attribute map and a URL). Its URL is generated (`LinkGenerator::generate()`) for nothing, and any custom JS that matches partial selections can still land on it.
- **Fix:**
  - In `getIndex()`, skip the variant when the event says the condition is not met (dispatch once, reuse the result).
  - Ship a default listener in CoreBundle that rejects `!$product->isPublished() || ($product instanceof ProductInterface && $product->getActive() !== true)` (dependent on CoreBundle).

### CB-06 — `getIndex()` crashes the product page when the class has no link generator or one variant's URL cannot be generated — (plausible)
- **Where:** `AttributeCollector.php:143`
- **Problem:**
  - `ClassDefinition::getLinkGenerator()` is `?LinkGeneratorInterface` (`vendor/six-it/pimcore-source/models/DataObject/ClassDefinition.php:1048`). A project class without `linkGeneratorReference` → `Error: Call to a member function generate() on null`.
  - Any exception from the generator for a single variant (missing route parameter, missing slug for the current site/locale) aborts the whole page instead of just dropping that variant from the index.
  - The doc block `@psalm-var Concrete&ProductVariantAwareInterface $variant` hides that `ProductVariantAwareInterface` does not guarantee `getClass()`.
- **Fix:** inject `Symfony\Component\Routing\Generator\UrlGeneratorInterface`/Pimcore's link generator service, or check `$generator = $variant->getClass()?->getLinkGenerator()` and skip the variant (log a warning) when it is `null` or throws.

### CB-07 — `$showInList` default differs between interface (`true`) and implementation (`false`)
- **Where:** `AttributeCollectorInterface.php:29,34,39` vs `AttributeCollector.php:42,56,114`; Twig callables in `VariantBundle/Twig/Extension/VariantExtension.php:39-55`
- **Problem:** PHP uses the default of the concrete class, so the documented contract ("by default only groups with `showInList`") is not what happens. A decorator or alternative implementation that follows the interface default silently changes which attribute groups the product page shows. The Twig functions call without the argument, so the result depends on which implementation is wired.
- **Fix:** decide the intended default (the storefront currently relies on `false`) and make both signatures identical; document it.

### CB-08 — `ResolvedAttributeGroup::type` is the class of the *first* attribute; the template compares it to a hard-coded class name — (dependent)
- **Where:** `AttributeCollector.php:86` (`setType(get_class($attribute))`), `FrontendBundle/Resources/views/Product/_variants.html.twig:9` (`attributeGroup.type == "Pimcore\\Model\\DataObject\\CoreShopAttributeColor"`), model override in `VariantBundle/DependencyInjection/Configuration.php` (`attribute_color.classes.model`)
- **Problem:**
  - A project that configures its own colour class (allowed by the bundle configuration) gets colour groups rendered as text because the FQCN differs.
  - A group containing both colour and value attributes is typed by whichever attribute happens to be seen first; the other kind is rendered with the wrong widget (bundle `ValidAttributesTypeValidator` prevents mixing on save, but not for existing data).
- **Fix:** expose a semantic type instead of a class name, e.g. `ResolvedAttributeGroup::isColor(): bool` (computed with `instanceof AttributeColorInterface`) or a `'color'|'value'` string, and update the template. Keep `type` for BC but deprecate it.

---

## Model / Resolved

### CB-09 — `Resolved*` collections lose their key invariant: `setAttributes()` re-keys, `removeAttribute()` never removes, `getAttribute()` throws — (reproduced)
- **Where:** `Model/Resolved/ResolvedAttributeGroup.php:57-84`, `Model/Resolved/ResolvedIndex.php:39-65`, `Model/Resolved/ResolvedAttribute.php:55-80`
- **Problem:**
  - `ResolvedAttributeGroup` keys `attributes` by attribute ID (`addAttribute()`, `hasAttribute()`, `getAttribute()`), but `setAttributes(array $attributes)` stores the list as given (`0..n` keys). Afterwards `hasAttribute()` returns `false`, `addAttribute()` adds duplicates and `getAttribute($id)` returns `null`. Reproduced: `new ArrayCollection(array_values([10 => 'a']))->containsKey(10)` is `false`.
  - `getAttribute(int $key): ResolvedAttribute` – `ArrayCollection::get()` returns `null` for a missing key → `TypeError` under `strict_types`.
  - `ResolvedIndex` stores `groupId => attributeId` (ints), but `removeAttribute()` calls `removeElement($attributeGroup)` with the **group object** → never matches, the entry stays (reproduced: count unchanged). `setAttributes()` has the same re-keying problem.
  - `ResolvedAttribute::hasProduct()` uses `ArrayCollection::contains()` (strict identity). The same product loaded twice (e.g. after `RuntimeCache::clear()` in a worker, `force` loading) is added twice; the psalm suppression hides the interface/`Concrete` mismatch.
  - Keys are `getId()` (`?int`); an unsaved attribute or group is stored under key `null` → `''`, colliding with every other unsaved one.
- **Fix:** key by ID everywhere (`setAttributes()` must re-index by `getAttribute()->getId()`), `removeAttribute()` → `$this->attributes->remove($attributeGroup->getGroup()->getId())`, `getAttribute(int $id): ?ResolvedAttribute`, compare products by ID, reject objects without ID.

### CB-10 — Typed properties are read before initialisation when the objects are not built by `AttributeCollector`
- **Where:** `Model/Resolved/ResolvedAttributeGroup.php:26,30` (`$group`, `$type`), `Model/Resolved/ResolvedAttribute.php:27` (`$attribute`, optional constructor arg), `Model/Resolved/ResolvedIndex.php:27` (`$url`)
- **Problem:** `getGroup()`, `getType()`, `getAttribute()`, `getUrl()` throw `Error: … must not be accessed before initialization` on a freshly constructed object. The Symfony serializer (`VariantBundle/Resources/config/serialization/Resolved*.yaml` exposes all of them in group `coreshop`) calls these getters, so normalising a partially filled object (custom collector, decorator, test) fails with a 500 instead of an empty value. `ResolvedIndex::addAttribute()` also dereferences `getGroup()`.
- **Fix:** make the required values constructor arguments (`new ResolvedAttributeGroup($group, $type)`, `new ResolvedAttribute($attribute)`, `new ResolvedIndex($url)`), see CI-09.

---

## Packaging

### CB-11 — `composer.json` does not declare what the component uses — (dependent: same issue as VariantBundle TODO-2 **B-15**)
- **Where:** `composer.json:26-33`
- **Problem:**
  - Missing `"php": "~8.4.0"` (root `composer.json:88`).
  - Used but not declared:
    - `six-it/pimcore-source` (`AbstractObject`, `Concrete`, `RgbaColor`, `getList()`, `getChildren()`),
    - `symfony/event-dispatcher-contracts` (`AttributeCollector`, `VariantAvailabilityEvent`),
    - `doctrine/collections` (`Model/Resolved/*`).
  - `coreshop/resource` is the only requirement, so the component only installs correctly as part of the monorepo.
  - `require-dev` pins `phpstan/phpstan ^1.10` / `phpstan-doctrine ^1.3.40` / `phpstan-webmozart-assert ^1.2.4`, while the root uses `phpstan/phpstan ^2.1` (root `composer.json:134`) → the component cannot be analysed standalone with the root config.
- **Fix:** add the missing `require` entries with the root's constraints, bump the phpstan packages to 2.x, align `branch-alias` with the root (CI-11).
