# ClassDefinitionPatchBundle – TODO 3: Improvements (code quality, readability, logic, PHP 8.4 / Symfony 7.4 idioms)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
None of these are defects on their own. They make the bundle cleaner, safer, easier to test, or more idiomatic for the target stack.
Paths are relative to the bundle root unless noted otherwise.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 language features

### I-01 — Turn `Patch` and `PatchField` into `final readonly` value objects
- **Where:** `Patch.php`, `PatchField.php`
- Both classes are immutable in practice, but they are declared with mutable `protected` properties. Make them `final readonly class` with promoted `public` or `private` properties. PHP 8.4 asymmetric visibility (`public private(set)`) is another option if the getters are dropped.
- Remove the `string|array|null` normalisation in `Patch::__construct()` (lines 40-56). `Configuration` already runs `castToArray()`, so the constructor can take `array $interfaces = []`. The same goes for `PatchField`: `array $definition = []` replaces the null juggling in lines 30-32.
- Plural naming: `interface` → `interfaces` (in the getter and internally; the config key can stay for backward compatibility).

### I-02 — `ColorConsoleDiffFormatter` clean-up
- **Where:** `Console/ColorConsoleDiffFormatter.php`
- Use typed class constants (PHP 8.3+): `private const string PLUS_START_REGEX = …;`. Drop the redundant `@var string` docblocks.
- Fix the typo `MINUT_START_REGEX` → `MINUS_START_REGEX`.
- Remove the empty, oddly formatted `__construct(\n ) {}`. The template can be a constant, or built inline in `format()`. `formatWithTemplate()` then disappears.
- Make the class `final`. Replace the closures with `static fn` / first-class callables.

### I-03 — Add `#[\Override]` to interface and parent implementations
- **Where:** `Patcher.php`, `Patches.php`, `Patch.php`, `PatchField.php`, `Command/PatchCommand.php` (`configure`, `execute`), `DependencyInjection/*` (`load`, `getConfigTreeBuilder`)
- PHP 8.3 then catches signature drift at compile time, for example after the interface changes in I-04.

### I-04 — Tighten the native types and generics on the public contracts
- `PatchesInterface::getPatch()` has **no return type**. It should be `: PatchInterface`.
- `PatchesInterface::getPatches()` / `PatcherInterface::getPatches()` should be documented as `@return array<string, PatchInterface>`.
- `PatcherInterface::patchClass(Patch $patch)` takes the **concrete** class, while `old()` and `new()` take `PatchInterface`. Make it `PatchInterface` everywhere.
- `PatchInterface::getFields()` is documented as `@return PatchField[]|null` → change it to `@return list<PatchFieldInterface>` and make it non-nullable (see B-06). Apply the same to `getInterface()`, `getUseTraits()` and `getListingUseTraits()` → `list<string>`.
- `PatchFieldInterface::getDefinition(): ?array` is never null after construction → `array<string, mixed>`.
- `PatcherInterface::old()` / `new()` should be documented as `@return array<string, mixed>`.

### I-05 — Use newer PHP idioms where they simplify the code
- PHP 8.4 `new Foo()->bar()` without wrapping parentheses (`ConsoleDiffer.php:33-34`, formatter).
- `array_any()` / `array_find()` (PHP 8.4) for the token-aware de-duplication in C-02 / I-12.
- Plain `preg_split` / `preg_replace` in place of `UnicodeString` in the formatter. The diff is ASCII, and the unicode wrapper is what caused B-08.

### I-06 — Rename `PatcherInterface::old()` / `new()`
- `new` is a legal method name, but it is confusing next to the `new` keyword. Names like `getOriginalDefinition()` / `getPatchedDefinition()` (or `preview(): PatchResult` returning both) read better. Keep the old names as `@deprecated` proxies for one minor release.

## Symfony 7.4 idioms

### I-07 — Modernise `PatchCommand`
- **Where:** `Command/PatchCommand.php`, `Resources/config/services.yml:18-23`
- Use `#[AsCommand(name: 'coreshop:patch:classes', description: '…')]`. That keeps the command lazy in `bin/console list` without instantiating the `Patcher`. The `console.command` tag in YAML currently has no `description`, so `list` must construct the command. Optionally, use a Symfony 7.3+ invokable command with `#[Option]` parameters.
- Return `Command::SUCCESS` / `Command::FAILURE` instead of `0`.
- Use `SymfonyStyle` (`$io->section()`, `$io->success()`, `$io->table()` for a per-class summary) instead of the hand-written `=====` banners.
- `--force` without `--dump` writes PHP files and ALTERs tables with no preview and no prompt. Ask for confirmation (`$io->confirm()`, skipped with `--no-interaction`).
- Running without any option does nothing and exits `0`. Print a hint, or default to `--dump`.
- Add an optional `classes` argument (`coreshop:patch:classes CoreShopProduct`) to limit the scope.
- Fill in a useful `setHelp()` (it is currently one sentence), with an example for each option.

### I-08 — Service definitions: drop `public: true`, wire against interfaces
- **Where:** `Resources/config/services.yml`
- `_defaults: public: true` exposes every service. Only the interface aliases, if anything, need to be public for BC.
- `Patcher` gets the concrete `Patches` (`services.yml:11`, `Patcher.php:26`) even though a `PatchesInterface` alias exists. It should type-hint and receive `PatchesInterface`.
- Enable `autowire: true` / `autoconfigure: true` so the explicit `arguments` lists (lines 10-21) go away. The empty `ColorConsoleDiffFormatter:` entry (line 13) becomes unnecessary.

### I-09 — Consider an `AbstractBundle` in place of `Configuration` + `Extension`
- **Where:** `CoreShopClassDefinitionPatchBundle.php`, `DependencyInjection/*`
- The bundle is tiny. Symfony's `AbstractBundle::configure(DefinitionConfigurator)` + `loadExtension()` would merge three classes into one, and the alias `core_shop_class_definition_patch` stays the same. This is optional: no other CoreShop bundle uses `AbstractBundle` yet, so align it with whatever direction the repo takes.

### I-10 — Simplify how the extension builds patches
- **Where:** `DependencyInjection/CoreShopClassDefinitionPatchExtension.php:40-75`
- Each field is registered as its own service `coreshop.patch.<class>.field.<field>` and referenced, while the `Patch` definitions themselves are inline. Inline both (`new Definition(...)` directly in the argument), or simpler still, pass the normalised config array as a parameter and build the value objects in a `PatchesFactory` at runtime. That gives no DI object graph and is easier to test.
- The `?? null` / `?? []` / `?? true` fallbacks (lines 42, 52-55, 64-70) are dead code, because the config tree already guarantees those keys and their defaults.

### I-11 — Make `Configuration` more readable and self-documenting
- **Where:** `DependencyInjection/Configuration.php`
- The indentation does not match the nesting (`->arrayNode('patches')` and `->useAttributeAsKey` on lines 35-36). `->beforeNormalization()->\n castToArray()->end()` is split across lines in an unusual way (lines 40-41, 49-50, 54-55).
- Add `->info()` and `->example()` to each node, so `bin/console config:dump-reference core_shop_class_definition_patch` documents the options. That also partly covers B-10.
- Add `->normalizeKeys(false)` on `patches` and `fields`, so class and field names are never rewritten (dash → underscore normalisation).
- The validation rules belong here too (see B-07).

## Logic and code quality

### I-12 — Extract the duplicated comma-list merge in `Patcher`
- **Where:** `Patcher.php:77-123`
- Three nearly identical blocks (`implementsInterfaces`, `useTraits`, `listingUseTraits`) differ only in property name and getter. Two copy-paste defects (C-01, B-01) came from this duplication. Replace them with one `private function mergeClassList(?string $existing, array $additions): ?string`, which is also where the token-aware fix for C-02 goes. Then loop over a `property => getter` map.
- The simple scalar properties (lines 61-75) can use the same map pattern.

### I-13 — Make `ClassUpdate` injectable
- **Where:** `Patcher.php:57-59`
- `new ClassUpdate($patch->getClassName())` hard-wires a Pimcore DB lookup, so `Patcher` cannot be unit-tested. Inject a small `ClassUpdateFactoryInterface` (or a `callable`). Also make `Patcher` `final` with `private readonly` dependencies, instead of the `protected` property plus non-final class.

### I-14 — Make the dump output readable and cheaper to produce
- **Where:** `Command/PatchCommand.php:54-65`, `Patcher.php:42-50`, `Console/ConsoleDiffer.php`
- `print_r()` of nested arrays produces a very noisy diff. Use `json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`, which matches the format Pimcore stores.
- `old()` and `new()` each build a fresh `ClassUpdate`: two DB loads, two `generateClassDefinitionJson()` calls, and the whole patch logic run twice per class. Compute both from one instance (for example `preview(): array{old: array, new: array}`).
- Skip classes whose diff is empty, and print "no changes".
- `ConsoleDiffer`: build the `Differ` once (as a constructor-initialised `readonly` property, or inject it). Pass the header explicitly (`new UnifiedDiffOutputBuilder('')`). The header-stripping loop in the formatter (lines 81-90) can then be deleted instead of relying on the library's default `"--- Original\n+++ New\n"`.

### I-15 — Remove or use the dead API on `Patches`
- **Where:** `Patches.php:33-45`, `PatchesInterface.php:25-27`
- `hasPatch()` / `getPatch()` have no callers in the repository. Either use them (for the `classes` argument in I-07) or drop them. If kept, throw a dedicated exception and use `sprintf`.

### I-16 — Feature gaps worth closing
- `ClassUpdate` already supports `removeField`, `insertLayoutBefore`/`After`, `replaceLayout`, and `removeLayout`, but the config cannot express them. Add `remove: true` for fields, and a `layouts:` section.
- `Component/Pimcore/DataObject/BrickDefinitionUpdate.php` and `FieldCollectionDefinitionUpdate.php` exist. Patching object bricks and field collections would reuse the same machinery.
- Add a pre-flight check that class definitions are writable (Pimcore `PIMCORE_CLASS_DEFINITION_WRITABLE` / `config_location` settings). Today a read-only setup only fails deep inside Pimcore's `save()`.

### I-17 — Tests and static analysis
- The bundle has **no tests**. Minimum set:
  - `Configuration`: tree processing, defaults, the validation from B-07.
  - `CoreShopClassDefinitionPatchExtension`: wiring of `Patches`.
  - `Patcher`: merge logic (C-01, C-02, B-01), field insertion order (B-03), idempotency (C-03, B-04), all against a fake `ClassUpdate` (needs I-13).
  - `ColorConsoleDiffFormatter`: coloring and header stripping (B-08).
- Run PHPStan at max level on this bundle once the I-04 types are in place.

### I-18 — Dependency constraints
- **Where:** `composer.json:29`, root `composer.json:106`
- `sebastian/diff: ^4.0 | ^5.0 | ^6.0`: v4 and v5 are old for PHP 8.4. Consider `^6.0 | ^7.0`, and check compatibility with PHPUnit 10's requirement in the root `require-dev`.
- The packaging fixes themselves are in B-11.

### I-19 — README
- **Where:** `README.md`
- The README is the generic CoreShop boilerplate. Add a short usage summary and link to `docs/03_Bundles/ClassDefinitionPatch_Bundle.md`.
