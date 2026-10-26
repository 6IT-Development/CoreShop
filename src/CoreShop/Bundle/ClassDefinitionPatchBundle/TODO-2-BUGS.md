# ClassDefinitionPatchBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
These items do not meet the bar for TODO-1. They are wrong behaviour, broken features, or latent errors.
Paths are relative to the bundle root unless noted otherwise.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Patcher logic

### B-01 — `listing_use_traits` is guarded by `listing_parent_class`, so it is ignored on its own
- **Where:** `Patcher.php:109`
- The block that merges `listingUseTraits` checks `null !== $patch->getListingParentClass()` instead of `getListingUseTraits()`. A patch that sets only `listing_use_traits` does nothing, silently. If someone builds a `Patch` manually with a parent class and `null` traits, line 112 runs `foreach (null)`. PHP emits a warning, which becomes an `ErrorException` in debug.
- **Fix direction:** guard with `getListingUseTraits()` (and treat `[]` as "nothing to do"). Fix together with C-01, which is in the same area.

### B-02 — `replace: true` is the default and swaps the **whole** existing field for the partial config definition
- **Where:** `DependencyInjection/Configuration.php:64`, `Patcher.php:127-128`, `Component/Pimcore/DataObject/AbstractDefinitionUpdate.php:137-146`
- If the field already exists, `replaceField()` overwrites its JSON with whatever is in `definition`. Typically that is just `fieldtype` and `title`. Everything else is lost: `datatype`, width, `mandatory`, `index`, permissions, tooltips, and any change made in the admin class editor since then. If `fieldtype` differs, Pimcore alters or drops the column on save, which can **lose data**.
- `replace` is not documented at all (see B-10). Users who copy the docs example will rarely expect "re-running replaces my field".
- **Fix direction:** make `replace: false` the default (merge properties), or require an explicit `replace` whenever the field already exists. At minimum, document it and show a warning in `--dump` when a field is fully replaced.

### B-03 — `before` on the first child inserts **after** it
- **Where:** `Component/Pimcore/DataObject/AbstractDefinitionUpdate.php:96-98` (also `insertLayoutBefore`, `154-156`). This is cross-component, but this bundle is its main consumer.
- `if ($index === 0) { $index = 1; }` means `before: <first field of a panel>` puts the new field in second place, after the target. The docs promise "before".
- **Fix direction:** remove the special case, because `array_splice($childs, 0, 0, …)` is valid. Check whether any other caller relied on the old behaviour.

### B-04 — `--force` re-saves every configured class even when nothing changed
- **Where:** `Patcher.php:35-40, 52-55`, together with `Component/Pimcore/DataObject/ClassUpdate.php:50-57`
- `importClassDefinitionFromJson()` always sets `modificationDate`/`userModification`, regenerates the PHP classes, runs the table ALTER logic, and dispatches `PRE_UPDATE`/`POST_UPDATE` class events. Every run therefore produces VCS diffs in `var/classes/definition_*.php` (or the configured class location), even though the docs say to commit the result and not re-run it on deploy.
- **Fix direction:** skip the save when `getOriginalJsonDefinition() == getJsonDefinition()`, and report "unchanged" per class.

### B-05 — No error handling: one bad patch aborts the run halfway, and the exit code / `save()` result are ignored
- **Where:** `Patcher.php:35-40, 54`; `Command/PatchCommand.php:53-72`
- Problems:
  1. `new ClassUpdate()` throws `ClassDefinitionNotFoundException` for an unknown class name (for example an optional CoreShop class that is not installed, or a typo). `findField()` throws `ClassDefinitionFieldNotFoundException` for an unknown `before`/`after` target. Both bubble out as raw stack traces.
  2. Patching is not transactional. Classes 1…N-1 are already saved (PHP files regenerated, tables altered) when class N fails, and the user has no summary of what was applied.
  3. `--dump` also aborts at the first failing class, so the user cannot preview the remaining patches.
  4. `ClassUpdate::save()` returns `bool`, and `patchClass()` drops it. `execute()` always returns `0`, even when nothing was applied.
- **Fix direction:** validate all patches first (dry run over every class), then save. Catch per class, collect the errors, and print a summary. Return `Command::FAILURE` when anything failed.

### B-06 — `Patch::getFields(): ?array` is iterated without a null guard
- **Where:** `PatchInterface.php:39-42`, `Patch.php:38, 99-105`, `Patcher.php:125`
- The interface allows `null`, but `foreach ($patch->getFields() as $field)` assumes an array. That gives a warning, or an `ErrorException` in debug, for any `Patch` that is not built from config. The other getters have the opposite problem: they are typed `?array`, but config always delivers `[]`, so the `null !== …` checks in `Patcher.php:77, 93, 109` are dead code.
- **Fix direction:** make the collections non-nullable (`array`, default `[]`), and check emptiness instead of null (see I-04).

## Configuration and validation

### B-07 — The config tree accepts input that later crashes, or generates broken classes
- **Where:** `DependencyInjection/Configuration.php:33-72`, `PatchField.php:23-35`
- Problems:
  1. `definition` is a `variableNode`, so `definition: foo` (a scalar) passes config validation. `PatchField::__construct(?array $definition)` then throws a `TypeError` when the container instantiates `Patches`, which happens on the command.
  2. `before` and `after` can both be set. `before` wins silently (`Patcher.php:132-135`).
  3. A **new** field without `definition.fieldtype` is inserted as an incomplete definition. Pimcore then fails, or creates garbage, in `generateLayoutTreeFromArray()`.
  4. `interface`, `use_traits`, `listing_use_traits`, `parent_class` and `listing_parent_class` are not checked for existence (`interface_exists` / `trait_exists` / `class_exists`) or for a leading `\`. The generated DataObject classes live in the `Pimcore\Model\DataObject` namespace, so a name without a leading backslash resolves to the wrong class. A missing interface or trait becomes a compile-time fatal on the generated class. The docs example even uses `interface: 'blub'`.
  5. Empty strings (`group: ''`) are accepted and written.
- **Fix direction:** add `->validate()` rules to the tree (`definition` must be an array; `before` and `after` are mutually exclusive; `cannotBeEmpty()`). Check class, interface and trait existence in the command before saving. Do not do it at container compile time, because user classes may not be autoloadable there.

## Console output

### B-08 — `--dump` coloring and header stripping never work, because `UnicodeString` is used as if it were a regex API
- **Where:** `Console/ColorConsoleDiffFormatter.php:74-79, 107-120`
- This was ported from Symplify/Rector, where Nette `Strings::split()` and `Strings::replace()` are regex-based. The Symfony equivalents used here are **literal**. This was verified against `symfony/string` `UnicodeString::split()` and `UnicodeString::replace()`:
  - `->split(self::NEWLINES_REGEX)` without `$flags` splits on the literal string `"#\n\r|\n#"`. That never matches, so the whole diff stays one "line".
  - `->replace(self::PLUS_START_REGEX, …)` (and the minus and at variants) does a literal search for `#^(\+.*)#`. That never matches, so no line is ever colored.
  - Because there is only one "line", the `'--- Original'` / `'+++ New'` removal (lines 82-90) never triggers either.
- Result: `--dump` prints a plain uncolored unified diff, including the headers the code tries to remove.
- **Fix direction:** use `preg_split('/\R/', …)` and `preg_replace` (or `UnicodeString::replaceMatches()` and `split(…, flags: …)`). Add a unit test for the formatter. See I-14 for a simpler design.

### B-09 — `NEWLINES_REGEX` has the CRLF order reversed
- **Where:** `Console/ColorConsoleDiffFormatter.php:52`
- `"#\n\r|\n#"` matches `LF CR`, which is not a real line ending. Windows `CR LF` is split on the `\n` and leaves a trailing `\r` on each line. This only matters once B-08 is fixed.
- **Fix direction:** use `/\R/`.

## Packaging and documentation

### B-10 — The documentation is wrong in several places that matter
- **Where:** `docs/03_Bundles/ClassDefinitionPatch_Bundle.md`
- Problems:
  1. The install command uses the wrong package name: `coreshop/class-definitions-patch-bundle`. The real name is `coreshop/class-definition-patch-bundle` (`composer.json:2`).
  2. The "Patching CoreShop Classes" example uses an **unquoted** `%coreshop.model.order.pimcore_class_name%:` key. Symfony YAML rejects a plain scalar that starts with `%` ("The reserved indicator "%" cannot start a plain scalar"), so the example cannot be parsed. It must be quoted: `'%coreshop.model.order.pimcore_class_name%':`.
  3. `interface: 'blub'` is not a real FQCN. If applied, it would generate a fatal class (see B-07).
  4. Kernel registration still refers to `app/AppKernel.php`. Inside CoreShop the bundle is already registered by `CoreBundle/CoreShopCoreBundle.php:100`, and the docs do not mention that.
  5. These options are undocumented: `replace` (and its destructive default, see B-02), `after`, `parent_class`, `group`, `description`, `listing_parent_class`, `use_traits`, `listing_use_traits`.
- **Fix direction:** correct the examples and add a full option reference. Also link the docs from the bundle `README.md`.

### B-11 — The split package's `composer.json` does not declare what the code uses
- **Where:** `composer.json:26-35`
- Problems:
  1. `symfony/string` is missing (`Console/ColorConsoleDiffFormatter.php:22`). So are `symfony/console`, `symfony/config`, `symfony/dependency-injection` and `symfony/http-kernel`. A standalone install (from `.gitsplit.yml`) only works by accident, through transitive dependencies.
  2. There is no `php` constraint (the root has `~8.4.0`).
  3. `six-it/pimcore-source: ^2.0`, but the root was bumped to `^2.1` (commit `d670a7c6b`).
  4. `require-dev` has `phpstan/phpstan ^1.5.4` and its extensions `^1`, while the root uses `^2.1` / `^2.0`.
- **Fix direction:** add the explicit `symfony/*: ^7.4` and `php: ~8.4.0` requirements, and align with the root `composer.json`.
