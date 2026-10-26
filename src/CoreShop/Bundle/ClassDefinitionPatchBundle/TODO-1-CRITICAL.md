# ClassDefinitionPatchBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: all PHP in this bundle, `Resources/config/services.yml`, `composer.json`, and the code it depends on: `Component/Pimcore/DataObject/{ClassUpdate,AbstractDefinitionUpdate,ClassUpdateInterface}.php`, the registration in `CoreBundle/CoreShopCoreBundle.php:100`, and `docs/03_Bundles/ClassDefinitionPatch_Bundle.md`.

"Critical" here means: silent corruption of a Pimcore class definition, generated PHP classes that fatal at load time (which takes the whole app down), an uncaught error on a reachable path, or a security issue.
Paths are relative to the bundle root unless noted otherwise.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers found. The bundle uses no APIs that are removed or deprecated in Symfony 7.4, and no PHP 8.4 deprecations such as implicit nullable parameters. Modernisation items are in TODO-3.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## C-01 — `listingParentClass` is set to the **listing traits array**, which breaks the class definition for any patch that uses `listing_parent_class`

- **Where:** `Patcher.php:73-75`
  ```php
  if (null !== $patch->getListingParentClass()) {
      $classUpdater->setProperty('listingParentClass', $patch->getListingUseTraits()); // wrong getter
  }
  ```
- **Problem:** this is a copy-paste error. The listing parent class is written with the value of `getListingUseTraits()` (`?array`) instead of `getListingParentClass()` (`?string`).
  - `listing_use_traits` is a prototyped array node in `DependencyInjection/Configuration.php:53-57`. Symfony Config always finalises such a node to `[]` when it is omitted, so `$patch['listing_use_traits'] ?? null` (`CoreShopClassDefinitionPatchExtension.php:70`) is **never `null`**. Any patch that sets only `listing_parent_class: \App\Listing\Foo` therefore writes `listingParentClass = []`.
  - `Service::importClassDefinitionFromJson()` calls `setListingParentClass()` whenever `isset($importData['listingParentClass'])`, and `isset([])` is `true`:
    - Pimcore 10/11 untyped setter (`(string) $listingParentClass`): you get an "Array to string conversion" warning, which Symfony's debug `ErrorHandler` turns into an exception. In no-debug mode the value `"Array"` is stored. It passes Pimcore's name regex (`ClassDefinition::save()`), so the generated `Listing.php` becomes `class Listing extends Array`. That is a compile-time fatal for every request that touches the listing.
    - Typed setter (`string`), if the `six-it/pimcore-source` fork has one: `TypeError` on `--force`. Classes patched earlier in the same run are already saved (see B-05).
  - `--dump` shows the wrong value too, so the preview does not catch the problem.
- **Fix direction:** use `$patch->getListingParentClass()`. Add a regression test that runs a patch with only `listing_parent_class` set. Fix together with B-01, which is in the same block.

## C-02 — Interface and trait de-duplication uses a substring check: entries are skipped silently, or duplicated so the generated class fatals

- **Where:** `Patcher.php:77-91` (`implementsInterfaces`), `93-107` (`useTraits`), `109-123` (`listingUseTraits`)
- **Problem:** `!str_contains($interfaces, $patchInterface)` runs against the whole comma-separated string. It does not compare tokens.
  1. **False positive, so the entry is silently skipped.** Patch `\App\Model\Price` against an existing `\App\Model\PriceAwareInterface,...` is treated as already present and never added. The same happens with traits (`\App\Traits\Price` vs `\App\Traits\PriceRange`). The class then fails at runtime wherever CoreShop or the project type-checks against the missing interface. Nothing is reported, and `--dump` shows no diff for it.
  2. **False negative, so the entry is duplicated.** PHP class names are case-insensitive, and Pimcore stores the string as-is. `\App\FooInterface` vs `\app\FooInterface`, or `\App\FooInterface` vs `App\FooInterface` with or without spaces after commas, is not detected. The entry is appended again, and the generated DataObject class gets `implements \App\FooInterface,\app\FooInterface`. That is a compile-time fatal ("cannot implement previously implemented interface"), so every request that loads the class returns a 500.
  - Whitespace around commas in existing definitions (`"\A, \B"`) is not normalised either.
- **Fix direction:** split the existing value on `,`, trim each part, and normalise it (`ltrim($x, '\\')`, compare with `strcasecmp`). Merge as a set, then join again. Extract the logic into one helper, because all three blocks are copy-pasted (see I-12). Add unit tests for prefix, case, and backslash variants.

## C-03 — Fields inside `localizedfields` are never detected as existing: every re-run inserts them again

- **Where:** `Patcher.php:125-139`, together with `Component/Pimcore/DataObject/ClassUpdate.php:40` and `AbstractDefinitionUpdate.php:60-63`
- **Problem:** `hasField()` checks `$this->fieldDefinitions`, which is `ClassDefinition::getFieldDefinitions()`. That only holds **top-level** data fields. Children of a `localizedfields` container appear only under the single key `localizedfields`. `findField()`, on the other hand, recurses into the whole layout tree, so `before`/`after` happily target a localized field, and the new field ends up **inside** `localizedfields`.
  - First run: the field is inserted into the localized container. This is correct.
  - Any later run: the command always re-applies **all** configured patches, for example when a new patch is added months later. `hasField('name2')` is still `false`, so the field is inserted **again** and the container now holds a duplicate. `replace: true` / `replace: false` are never honoured for localized fields, so changes to their config are never applied.
  - CoreShop's own classes (`CoreShopProduct`, `CoreShopCategory`, and others) put most text fields in `localizedfields`, so this is the common case, not an edge case. A duplicated child breaks the class editor in Pimcore admin. It can also change the localized-table layout on save, because Pimcore's Localizedfield DAO drops columns it no longer sees mapped. **Verify** the exact Pimcore behaviour against `six-it/pimcore-source`.
- **Fix direction:** decide existence by walking the JSON layout tree, which `findField()` already does. Do not use `getFieldDefinitions()`. For example, add `ClassUpdate::hasFieldInLayout()` or make `hasField()` recurse into `localizedfields` (`Data\Localizedfields::getFieldDefinitions()`). Add an idempotency test: applying the same patch twice must produce an identical definition (see also B-04).
