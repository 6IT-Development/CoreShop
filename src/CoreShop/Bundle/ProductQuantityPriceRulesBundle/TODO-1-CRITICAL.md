# ProductQuantityPriceRulesBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this bundle (21 files: `CoreShopProductQuantityPriceRulesBundle.php`, `Attribute/`, `CoreExtension/`, `DependencyInjection/**`, `Doctrine/ORM/`, `Event/`, `Form/Type/**`, `Twig/`), `composer.json`, and all of `Resources/config/**` (`services.yml`, `services/{rules,product-quantity-price-rules,actions,calculator,data_hub}.yml`, `pimcore/{config,admin}.yml`, `doctrine/model/*.orm.xml`, `serializer/*.yml`, `validation/*.yml`). Call paths were followed into `Component/ProductQuantityPriceRules` (models, fetchers, detector, `MemoryCachedValidRuleFetcher`), `Component/Rule` (`RuleConditionsValidationProcessor`), `Component/Registry` (`RegisterRegistryTypePass`, `RegisterSimpleRegistryTypePass`, `Autoconfiguration`), `ResourceBundle` (`TempEntityManagerTrait`, `EntityMerger`, `Serialization/VersionObjectConstructor`, `AbstractModelExtension::registerPimcoreResources`), `RuleBundle` (form types), `ProductBundle` (`specific-price-rules.yml`), `CoreBundle` (`pimcore/coreshop/coreshop_product_quantity_price_rules.yml`, `services/product-quantity-price-rules.yml`, `serializer/Model.{QuantityRange,ProductQuantityPriceRule}.yml`, `QuantityRangeUnitValidationListener`, `ProductQuantityPriceRulesCloner`, `ProductQuantityRangeTypeExtension`), `FrontendBundle` (`Product/detail.html.twig`) and the bundle's admin JS (`Resources/public/pimcore/js/**`, only to see what requests are sent).

Reference for API checks: `vendor/` (`symfony/*` 7.4.21, `six-it/pimcore-source`, `six-it/admin-ui-classic-bundle`, `doctrine/orm` 2.20.13, `jms/serializer` 3.32.9) and the compiled dev container `../pimcore2teszt10211524/var/cache/dev/App_KernelDevDebugContainer.xml` (+ its `…Deprecations.log`). This bundle is byte-identical to the vendored `six-it/core-shop` copy (`diff -r` is empty).

"Critical" here means: a security issue, data loss or data written wrongly (including wrong prices), or an error that takes down every request, a whole area, or the install or upgrade path once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code against the vendored libraries or by reading the vendored library source or the compiled container.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers. `php -l` (PHP 8.4.27) passes on every PHP file in the bundle, every service definition resolves in the compiled container, and the container deprecation log has no entry for this bundle. There are no implicitly nullable parameters (PHP 8.4 deprecation). One runtime Symfony 7.4 deprecation (array options passed to a `Constraint`, breaks the currency check in Symfony 8) is TODO-2 **B-04**; the modernisation and forward-compatibility items (Doctrine ORM 3 included) are in TODO-3 (**I-01** to **I-05**).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Data loss / data written wrongly

### C-01 — Saving a draft version, autosave or preview deletes quantity ranges from the live product immediately — (verified code path)
- **Where:**
  - `CoreExtension/ProductQuantityPriceRules.php:313-365` (`getDataFromEditmode()`) calls `checkForRangeOrphans()` for every posted rule that has an id.
  - `CoreExtension/ProductQuantityPriceRules.php:443-475` (`checkForRangeOrphans()`) calls `$entityManager->remove($invalidatedRange)` and then `$entityManager->flush()` on the temp entity manager.
  - `ResourceBundle/CoreExtension/TempEntityManagerTrait.php`: the temp EM is created with `$entityManager->getConnection()`, i.e. the **same DBAL connection** as the live data. The flush writes `DELETE FROM coreshop_product_quantity_price_rule_range …` straight to the database.
  - `vendor/six-it/admin-ui-classic-bundle/src/Controller/Admin/DataObject/DataObjectController.php:1309-1314` (`saveAction`) calls `applyChanges()` (and so `getDataFromEditmode()`) for **every** task: `publish`, `unpublish`, `version` ("Save draft"), `autoSave` and `session` (preview). For `version`/`autoSave` on a published object only `saveVersion()` runs afterwards (`:1374-1381`), the live object is never saved.
- **Impact:**
  - An editor removes a range in the editor and presses "Save draft" (or autosave fires, or preview is opened): the range is deleted from the live rule at once, the shop sells with the remaining ranges, and the draft cannot be discarded to get it back.
  - The deletes happen while the posted data is still being parsed. If a later rule fails form validation (`:360` throws), the save fails, but the ranges of all earlier rules are already deleted. The admin controller catches the `Throwable` and runs `applyChanges()` a second time on `$objectFromDatabase` (`:1312-1313`), so the work is repeated once more before the error reaches the user.
  - The deletes run outside the Pimcore save transaction, so a failing `$object->save()` (validation, listener exception) cannot roll them back.
- **Fix:**
  - `getDataFromEditmode()` must not write to the database. Remove the `flush()`/`remove()` from `checkForRangeOrphans()`; only call `$storedRule->removeRange($range)` in memory (the `ranges` association already has `orphan-removal="true"`, so the delete happens when `save()` flushes the main EM).
  - Better: let the form's `CollectionType` (`allow_delete: true`, `by_reference: false`) do the orphan handling by binding ranges by id (TODO-3 **I-09**) and drop `checkForRangeOrphans()` and the `refresh()` completely.
  - All writes then happen only in `save()`, i.e. only on `publish`/`unpublish`/real save, never on `version`/`autoSave`/`session`.

### C-02 — Rule ids from the request are not checked against the edited product: an editor can take over, change or strip the rules of any other product — (verified code path, plausible exploit)
- **Where:**
  - `CoreExtension/ProductQuantityPriceRules.php:332-336`: `$ruleId` comes from the posted JSON and is loaded with `$specificPriceRuleRepository->find($ruleId)`. Nothing checks `$storedRule->getProduct() === $object->getId()`.
  - `:339`: `checkForRangeOrphans()` then deletes every range of that foreign rule whose id was not posted (written immediately, see **C-01**).
  - `:342-348`: the form binds the posted data onto the foreign rule and `setProduct($object->getId())` re-assigns it.
  - `:367-402` (`save()`): `EntityMerger::merge()` + `flush()` stores the foreign rule under the edited product. The original product loses the rule.
- **Impact:** a user who may only save product A can post `{"quantityPriceRules": [{"id": <rule of product B>, …}]}` (or any other field name of this type) and delete product B's ranges, change its prices, or move the whole rule to product A. Product B's object permissions (workspaces) are bypassed. No UI path sends a foreign id today (the clipboard strips range ids, `ranges.js:531-533`), so this needs a hand-made request, but it also happens by accident when a stale editor tab posts after a rule was moved/copied.
- **Fix:**
  - Load with `findOneBy(['id' => $ruleId, 'product' => $object->getId()])`. When nothing is found, drop the posted id and treat the rule as new (or throw a `ValidationException`).
  - Same check for posted range ids once ranges are bound by id (TODO-3 **I-09**): only accept ids that belong to the loaded rule.

### C-03 — Fractional `rangeStartingFrom` values are cut to integers in versions and in the cache, so the shop uses wrong range limits (wrong prices) and restoring a version writes the cut value back — (verified)
- **Where:**
  - `Resources/config/doctrine/model/QuantityRange.orm.xml:11`: `rangeStartingFrom` is a `float` column; `Form/Type/ProductQuantityRangeType.php:46` uses `NumberType` (decimals allowed); the model setter takes `float` (`Component/ProductQuantityPriceRules/Model/QuantityRange.php`).
  - `Resources/config/serializer/Model.QuantityRange.yml:10-13`: `rangeStartingFrom` is serialized as `type: int`. `CoreBundle/Resources/config/serializer/Model.QuantityRange.yml` does not override it.
  - `CoreExtension/ProductQuantityPriceRules.php:204-267`: `marshalVersion()`, `marshalRecycleData()` and `marshalForCache()` all use this metadata (group `Version`).
  - Run against the vendored `jms/serializer` 3.32.9: `toArray()` of a property typed `int` with value `2.5` returns `2`; `fromArray(['a' => 2.5])` also returns `2` (verified).
- **Impact:**
  - Products with unit definitions (kg, m, l, …) often use fractional starting points (e.g. "from 2.5 m"). The cached product object, which is what the frontend and cart price calculation use, has `2` instead of `2.5`. Quantities between 2 and 2.5 get the cheaper range: wrong prices in the cart and in orders.
  - Publishing an old version or restoring from the recycle bin writes the cut value back into the database permanently.
  - The admin "versions" diff/preview shows the wrong value.
- **Fix:** `type: float` (or `double`) for `rangeStartingFrom` in `Model.QuantityRange.yml`. Invalidate existing cache entries of affected products after the fix (CoreShop cache tags or `pimcore:cache:clear`). Versions already written with `int` stay cut; note this in the upgrade notes.
