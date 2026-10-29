# Variant Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: all 16 PHP files in `src/CoreShop/Component/Variant` and its `composer.json`. Dependent config and callers that were followed: `Bundle/VariantBundle/Resources/config/{services.yml,serialization/*.yaml,validation/*.yaml}`, `Bundle/VariantBundle/DependencyInjection/Configuration.php`, `Bundle/VariantBundle/Twig/Extension/VariantExtension.php`, `Bundle/VariantBundle/EventListener/MainVariantListener.php`, `Component/Core/Model/Product.php` (the only user of `ProductVariantTrait`), `Bundle/FrontendBundle/Resources/views/Product/_variants.html.twig` and `Bundle/FrontendBundle/Resources/public/static/js/plugin/coreshop.plugin.variant.js`. Bundle-side wiring lives in `src/CoreShop/Bundle/VariantBundle` (see that bundle's own TODO files: `C-`, `B-`, `I-` numbers).

"Critical" here means: silent data corruption, a hang or an uncaught error / HTTP 500 on a reachable path, a security issue, or a blocker for Symfony 7.4 / PHP 8.4.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4, six-it/pimcore-source 2.x, doctrine/collections 2.6.0, PHP 8.4.27). Component items use the prefixes `CC-` (critical), `CB-` (bugs) and `CI-` (improvements).
Markers: **(dependent)** = root cause or part of the fix is outside this component; **(reproduced)** = run with PHP 8.4.27 against the vendored libraries; **(plausible)** = follows from the code, but the trigger depends on data or configuration.

**No Symfony 7.4 / PHP 8.4 blocker was found.** All 16 PHP files pass `php -l` on 8.4.27. No deprecated Symfony or PHP API is used (no implicit nullable parameters, no dynamic properties, `Symfony\Contracts\EventDispatcher\Event` is current).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Model / ProductVariantTrait

### CC-01 — `getVariantParent()` loops forever when a variant's parent is not of the same class — (plausible)
- **Where:** `Model/ProductVariantTrait.php:57-68`. Callers: `AttributeCollector.php:117,127`, `VariantBundle/Controller/VariantController.php:54,111`, `VariantBundle/EventListener/MainVariantListener.php:76`, `VariantBundle/EventListener/ProductListener.php:79`, `VariantBundle/Validator/Constraints/ValidAttributesValidator.php:40`. Tracked from the bundle side as VariantBundle TODO-2 **B-07** (dependent).
- **Problem:**
  ```php
  while ($parent->getType() === 'variant') {
      if ($parent->getParent() instanceof self) {
          $parent = $parent->getParent();
      }
  }
  ```
  - When `$parent->getParent()` is **not** an instance of the using class, `$parent` never changes and the loop never ends. Triggers: a variant moved (API, import, copy/paste) below a folder, an object of another class, or below a product of another class that also uses the trait (`self` is the *using* class, not the trait); a variant whose parent is `null` (half-deleted tree).
  - Storefront: `coreshop_variant_attribute_list_object()` / `coreshop_variant_index()` call it on every product page → the PHP-FPM worker spins until `max_execution_time`. Several such requests exhaust the FPM pool (self-inflicted DoS).
  - CLI / Messenger: no time limit → the worker or import hangs **forever** at 100 % CPU, also blocking all following messages.
  - Every save of such a variant hangs too (`ProductListener`, `MainVariantListener`).
- **Fix:**
  - Advance unconditionally and stop on anything that is not a matching variant:
    ```php
    $parent = $this;
    while ($parent->getType() === AbstractObject::OBJECT_TYPE_VARIANT) {
        $next = $parent->getParent();
        if (!$next instanceof ProductVariantAwareInterface) {
            throw new \LogicException(sprintf('Variant %d has no variant-aware parent.', $this->getId()));
        }
        $parent = $next;
    }
    return $parent;
    ```
  - Alternatively return `null` and make the return type `?ProductVariantAwareInterface` (callers must then handle it; see CI-07).
  - Add a guard (e.g. max depth or visited-ID set) as a belt-and-braces measure against cyclic data.

### CC-02 — `getVariants()` returns variants of **other** products: unescaped `LIKE` wildcards in the path and descendants of child objects — (plausible)
- **Where:** `Model/ProductVariantTrait.php:31-44` (`setCondition('path LIKE ?', [$this->getFullPath() . '/%'])`). Consumers: `findMainVariant()` (→ `VariantBundle/EventListener/MainVariantListener.php:51` **persists** the result as `mainVariant`), `AttributeCollector::getAttributesFromVariants/getAttributesFromObject/getIndex` (storefront selector + redirect index), `VariantBundle/Service/VariantGeneratorService` duplicate check.
- **Problem:**
  - The full path is used as a `LIKE` pattern without escaping. Pimcore keys may contain `_` (and `%`, `\` are not stripped by `Element\Service::getValidKey()` for objects either). `_` matches any single character, so product `/shop/shirt_red` also matches the variants of a sibling `/shop/shirt-red/…` or `/shop/shirt red/…`. A `%` in the key matches everything below a common prefix.
  - `path LIKE '<fullPath>/%'` matches **all descendants**, not only the variant chain. Pimcore allows plain objects below objects (accessories, bundles, sets modelled as children). Variants of a child product `/shop/A/B/B1` are returned as variants of `A`.
  - Consequences:
    - `MainVariantListener` writes a variant of a *different* product into `A.mainVariant`; `VariantLinkGenerator` (`redirect_to_main_variant: true` by default) then sends every link to `A` to another product (listings, sitemap, feeds, cart). This is persisted data corruption.
    - The storefront selector of `A` shows attribute values that belong to `B`, and the redirect index (`getIndex()`) contains URLs of `B`'s variants.
    - The variant generator thinks combinations already exist and skips them (bundle **B-02** makes this worse).
- **Fix:**
  - Escape the pattern: `addcslashes($this->getFullPath(), '\\%_') . '/%'` (MySQL's default `LIKE` escape is `\`).
  - Restrict to the real variant chain: either walk `getChildren([OBJECT_TYPE_VARIANT], true)` recursively (variants of variants), or filter the listing result with `fn ($v) => $v->getVariantParent()->getId() === $this->getId()` (after CC-01 is fixed). For performance, a DAO query on `parentId` per level is cheaper than `LIKE` on `path`.
  - Data repair: after deploying, re-save all variant-aware parents (or run a one-off command) to recompute `mainVariant`.

### CC-03 — Main-variant selection ignores published / active state; the parent product link then points to a 404 page — (dependent) (plausible)
- **Where:** root cause `Model/ProductVariantTrait.php:31-44` (`getVariants()` sets neither `setUnpublished()` nor a `published`/`active` condition) and `:70-112` (`findMainVariant()` sorts all of them). Full description, scenario and bundle-side fix: VariantBundle TODO-1 **C-03**.
- **Problem (component part):**
  - `getVariants()` relies on the global `DataObject::doHideUnpublished()` flag. It is `false` in the admin, in the CLI and in Messenger workers — exactly where `MainVariantListener::preUpdate()` runs. Unpublished variants are therefore candidates, and the "S" size that was never published becomes the main variant.
  - The result of the same method differs between admin and storefront (hidden global state), so the same product gets a different main variant depending on where it was saved.
  - Neither method knows about the Core `active` flag; `FrontendBundle/Controller/ProductController.php` returns 404 for `!isPublished() || getActive() !== true`.
- **Fix:**
  - Make visibility explicit: `getVariants(bool $includeUnpublished = false)` with `$list->setUnpublished($includeUnpublished)`, independent of the global flag.
  - `findMainVariant()` must only consider published variants and must expose a hook for further filtering (e.g. dispatch `VariantAvailabilityEvent` like `AttributeCollector` does, so Core can filter `getActive() !== true`), or move the logic into a replaceable `MainVariantResolverInterface` service (CI-14).
  - Return `null` when no eligible variant exists so the listener can clear `mainVariant`.
