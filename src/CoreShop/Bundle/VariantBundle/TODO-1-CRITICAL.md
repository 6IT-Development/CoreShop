# VariantBundle – TODO 1: Critical errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
Scope: every PHP file of `src/CoreShop/Bundle/VariantBundle` (20 files), `composer.json`, `Resources/config/{services.yml,pimcore/*.yml,validation/*.yaml,serialization/*.yaml}`, `Resources/translations/*`, `Resources/install/pimcore/classes/*.json` and `Resources/public/pimcore/js/resource.js`. Call paths were followed into `Component/Variant` (`ProductVariantTrait`, `AttributeGroup`, `AttributeCollector`, `Model/Resolved/*`), `Component/Pimcore/DataObject/{CompositeLinkGenerator,InheritanceHelper}.php`, `ResourceBundle/{AbstractResourceBundle,Controller/AdminController,CoreExtension/CoreShopRelations,DependencyInjection/Extension/AbstractModelExtension}.php`, `CoreBundle/Resources/install/pimcore/classes/CoreShopProductBundle/CoreShopProduct.json`, `FrontendBundle/Controller/ProductController.php` and `FrontendBundle/Resources/views/Product/_variants.html.twig`.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4.21, six-it/pimcore-source 2.x, PHP 8.4.27). Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(reproduced)** were run with PHP 8.4.27 against the bundle code and the vendored libraries. Items marked **(plausible)** follow from the code, but the trigger depends on data or configuration.

"Critical" here means: security hole, shop-wide or storefront-wide breakage on a common path, data written wrongly on a common path, or a blocker for Symfony 7.4 / PHP 8.4.

**No Symfony 7.4 / PHP 8.4 blocker was found.** All 20 PHP files pass `php -l` on 8.4.27. Loading the validation mapping with Symfony 7.4 produces deprecations for both custom constraints (reproduced, they will break on Symfony 8). That is tracked in TODO-2 **B-01**.

`Component/Variant` has no TODO files yet. Items that are rooted there but break this bundle are tracked here and marked **(dependent)**.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security

### C-01 — `VariantController` has no permission check: any backend user can read any product's attribute setup and create variants under any product
- **Where:** `Controller/VariantController.php:39-85` (`getAttributesAction`), `:87-132` (`generateVariantsAction`), `Resources/config/pimcore/routing.yml`. Compare with Pimcore's own controllers: `vendor/six-it/admin-ui-classic-bundle/src/Controller/Admin/DataObject/DataObjectController.php:849` (`$parent->isAllowed('create')`) and `VariantsController.php:65` (`isAllowed('view')`).
- **Problem:**
  - Both actions load the product with `DataObject::getById($id)` and act on it. There is no `isAllowed()` check and no Pimcore permission check (such as `objects`). The only gate is the admin firewall, so **any** logged-in backend user can call them. That includes users whose workspace hides the product tree, and users without the `objects` permission.
  - `generateVariantsAction` queues `CreateVariantMessage`s. The worker then **creates and saves** objects under the product with no user context. This bypasses the workspace `create` right. The variants are created unpublished. However, publishing a variant saves the parent (`MainVariantListener::postUpdate`), so later writes also happen outside the user's rights.
  - `getAttributesAction` is a GET route with no `methods:` restriction. It shows the allowed attribute groups and the keys and IDs of every attribute of any product ID it is given. This is an enumeration oracle for object IDs and keys.
  - Together with **C-02**, a low-privileged backend user can reach a raw SQL injection.
- **Fix:**
  - In both actions, after the product is resolved (and after the `getVariantParent()` switch): `if (!$product->isAllowed('view')) throw $this->createAccessDeniedException();`. In `generateVariantsAction`, also require `$product->isAllowed('create')` (Pimcore's right for adding children/variants).
  - Optionally require a dedicated Pimcore permission (for example `coreshop_permission_variant_generator`). Register it through the bundle's `pimcore_admin.permissions`, which `AbstractModelExtension::registerPimcoreResources()` already supports.
  - Add `methods: [GET]` to `coreshop_admin_variant_attributes`.
  - Re-check the right in the message handler: store the user ID in the message (already done), load the user, and check `$product->isAllowed('create', $user)`. Use the user as `userOwner`/`userModification` of the new variant (TODO-3 **I-10**).

### C-02 — SQL built by string concatenation: request input reaches raw SQL in the worker, and an apostrophe in a product or attribute-group key breaks the generator and every attribute save in that group
- **Where:**
  - `Service/VariantGeneratorService.php:54` — `setCondition('path LIKE \'' . $product->getFullPath() . '/%\'')`
  - `Service/VariantGeneratorService.php:55-58` — `'attributes LIKE "%object|' . $id . '%"'` → `addConditionParam($attributeCondition)` **without parameters**. `$id` comes unvalidated from the JSON body (`VariantController.php:94` → `generateCombinations` → message → handler).
  - `Validator/Constraints/ValidAttributesTypeValidator.php:56` — `setCondition('path LIKE \'' . $parent->getFullPath() . '/%\'')`
  - Key rules: `vendor/six-it/pimcore-source/models/Element/Service.php` `getValidKey()`. For objects it only replaces `/`, `<`, `>` and control characters, so `'` and `"` are allowed in object keys.
- **Problem:**
  - **Injection:** `attributes` is an array of arrays taken from the request body (`CoreShop\Bundle\ResourceBundle\EventListener\BodyListener` decodes it). The admin UI sends integers, but nothing enforces that. A string such as `1%" OR SLEEP(5) OR "` is pasted into the `WHERE` clause of the listing that the worker runs. This is a blind (boolean / time-based) SQL injection against the full Pimcore database. Combined with **C-01**, any backend user can reach it.
  - **Apostrophes in keys:** a product with the key `Levi's 501`, or an attribute group with the key `Men's sizes`, produces `path LIKE '/…/Levi's 501/%'`, which is a SQL syntax error.
    - Generator: every `CreateVariantMessage` for that product fails, is retried 3 times (5 / 10 / 20 min), and then lands in `coreshop_variant_failed`. No variant is ever generated and the user gets no feedback (TODO-2 **B-12**).
    - Validator: `ValidAttributesTypeValidator` runs on **every** pre-add and pre-update of an attribute (`AttributeListener` → validator → constraint in `Resources/config/validation/Attribute.yaml`). **No attribute under that group can be created or saved.** The admin gets a raw DBAL exception.
  - `"…"` string literals in MySQL break when `sql_mode` contains `ANSI_QUOTES` (they then name an identifier), so the generator also fails on such servers.
  - `%` and `_` in a key are LIKE wildcards, so `path LIKE` can also match the wrong subtree (for example `Size_1` matches `SizeX1`).
- **Fix:**
  - Validate the input in the controller (TODO-2 **B-03**): `attributes` must be `array<int|string, list<int>>` (cast with `(int)` and reject values ≤ 0), and every ID must belong to an allowed group of the product.
  - Always use bound parameters: `$list->setCondition('path LIKE ?', [$this->escapeLike($product->getFullPath()) . '/%'])`. `ProductVariantTrait::getVariants()` already uses a bound parameter. Better still, filter on `parentId` / `getChildren()` instead of path matching.
  - For the attributes condition, use bound parameters plus exact delimiters (see TODO-2 **B-02**): `attributes LIKE ?` with `'%,object|' . (int) $id . ',%'`.
  - In `ValidAttributesTypeValidator`, use `$parent->getChildren()`, or a listing with `parentId = ?` / `path LIKE ?`.
  - Regression tests: a product key and a group key that contain `'`, and an attribute ID payload that contains SQL.

---

## Storefront

### C-03 — The main variant can be an **unpublished** or **inactive** variant. The parent product's storefront link then points to a 404 page — (dependent) (plausible)
- **Where:** `EventListener/MainVariantListener.php:39-58` (`preUpdate` sets `mainVariant = findMainVariant()`), `:60-78` (`postUpdate` returns early for unpublished variants), `Pimcore/VariantLinkGenerator.php:43-49`. **(dependent)** `Component/Variant/Model/ProductVariantTrait.php` `getVariants()` / `findMainVariant()` (no published filter). Unpublished rows are only hidden when `DataObject::doHideUnpublished()` is true (`vendor/six-it/pimcore-source/lib/Model/Listing/Dao/QueryBuilderHelperTrait.php:69-72`), and in the admin and the CLI it is false. The 404 comes from `FrontendBundle/Controller/ProductController.php:82-83` (`!isPublished() || getActive() !== true`).
- **Problem:**
  - The variant generator creates **all** variants unpublished (`VariantGeneratorService.php:92`). The admin then publishes some of them. Every publish saves the parent (`postUpdate`). The parent's `preUpdate` then picks the first variant **by attribute sorting, including unpublished and inactive ones**.
  - Typical case: sizes S/M/L/XL are generated, and only M/L/XL are published because S is not sold. The main variant becomes S. `VariantLinkGenerator` (on by default, `redirect_to_main_variant: true`) returns S's URL for the parent everywhere the parent is linked: category listings, search, sitemaps, product feeds, and the cart "back to product" link. All of these then end in **404**.
  - Unpublishing or deactivating the current main variant later does **not** recompute it, because `postUpdate` returns early for unpublished objects and nothing listens to `POST_DELETE`. So the bug also appears on shops that never used the generator.
  - Admin preview hides it: `ProductController` lets frontend requests made by an admin through, so the problem is only visible to customers.
- **Fix:**
  - In the main-variant selection, only consider variants that are published (and, for Core products, `getActive() === true`). Either add a parameter to `findMainVariant(bool $publishedOnly = true)`, or filter in the listener. The listing needs `setUnpublished(false)` plus an explicit `published = 1` condition, because `doHideUnpublished()` is false in the admin.
  - When there is no published variant, set the main variant to `null` (`setMainVariant(null)`) instead of leaving the old value.
  - In `MainVariantListener`, recompute the parent on variant publish, unpublish, deactivate and delete (`POST_UPDATE` without the published guard, plus `POST_DELETE`). See TODO-2 **B-05** for doing this cheaply.
  - In `VariantLinkGenerator`, add a defensive check: only redirect when `$mainVariant->isPublished()` (and is active). Otherwise generate the parent's own link.
  - Data repair: after deploying, re-save the variant-aware parents, or run a one-off command that recomputes `mainVariant`.
