# AddressBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Bugs are incorrect behaviour, latent crashes on uncommon paths, wrong config wiring, and Symfony 7.4 deprecations that will break on 8.0.
Paths are relative to the bundle root unless noted otherwise. Component items (`CC-`, `CB-`, `CI-`) are in `src/CoreShop/Component/Address/TODO-*.md`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Forms

### B-01 — `StateType::getBlockPrefix()` returns `coreshop_zone` (copy-paste)
- **Where:** `Form/Type/StateType.php:43-46` (same value as `Form/Type/ZoneType.php:36-39`)
- **Problem:** Two form types share one block prefix, which means they also share a default form name. Form-theme blocks (`coreshop_zone_widget`/`_row`) apply to both. The CSRF token id (derived from the form name) and request payload keys collide.
- **Fix:** return `coreshop_state`.

### B-02 — `StateChoiceType` ignores the country; nothing validates that the state belongs to the country
- **Where:** `Form/Type/StateChoiceType.php:38-57`; `Resources/config/validation/Address.yml` (no `state` rule); also used in `CoreBundle/Form/Extension/TaxRuleTypeExtension.php:36`
- **Problem:** All (active) states of all countries are offered. A submitted address can combine country AT with state "California", and the server accepts it.
- **Fix:** add a `country` option to filter the choices, or update the field dynamically with `PRE_SET_DATA`/`PRE_SUBMIT` listeners in `AddressType`. Add a class-level constraint "state ∈ country->getStates()" (needs Component CB-03 first). Related: C-01.

### B-03 — Editing an address whose country, state or salutation is no longer offered silently changes it
- **Where:** `Form/Type/AddressType.php:36-64` (`'active' => true` on country/state; salutations from the context country)
- **Problem:** If the stored country or state was deactivated, or the salutation is not in the current country's list, that value is missing from the choices. With `required: true` and no placeholder (see C-01), the browser selects the first option, and saving the form overwrites the stored value without warning.
- **Fix:** always include the bound value in the choices (a choice loader that merges the current data), or show a validation error instead of a silent swap.

### B-04 — `CountryCollector` stores a Doctrine entity in the serialized profile; other collector defects
- **Where:** `Collector/CountryCollector.php:60-62`; `Resources/views/Collector/country.html.twig:7`; `Resources/config/services/profiler.yml:9`
- **Problems:**
  1. `$this->data['country']` holds the entity, which is serialized into profiler storage. When a stored profile is loaded later, the Doctrine proxy class (`Proxies\__CG__…`) and the uninitialized translations may be unavailable: `__PHP_Incomplete_Class`, or the name renders as "Undefined".
  2. `country_name` is collected, but the template reads `collector.country.name`.
  3. `getCountry()` is called twice (line 60 and line 61), so the context is resolved twice.
  4. Line 62 assigns `country_change_support` to itself, which does nothing.
  5. `$this->data['admin']` is set but never used. `country_change_support` is always `false` (`profiler.yml:9`), so the feature does nothing.
- **Fix:** store only scalars (id, ISO code, name), or `cloneVar()`. Make the template use them. Remove the dead data. Related: C-03.

### B-05 — `CountryChoiceType` with `multiple: true` only works with Doctrine Collections
- **Where:** `Form/Type/CountryChoiceType.php:23, 37-42`
- **Problem:** `CollectionToArrayTransformer` throws `TransformationFailedException` when the model value is a plain array, for example a Pimcore `coreShopCountryMultiselect` field or an array property. `symfony/doctrine-bridge` is also not declared in the bundle `composer.json` (see B-17).
- **Fix:** add the transformer only when the bound data is a `Collection`, or expose an option for it. Declare the dependency.

### B-06 — `SalutationChoiceType` choice building is fragile and inconsistent with `AddressFormatter`
- **Where:** `Form/Type/SalutationChoiceType.php:43-52`; `Component/Address/Formatter/AddressFormatter.php` (salutation translation); `Resources/config/doctrine/model/Country.orm.xml:18`
- **Problems:**
  1. `getSalutations()` may return `null` (`simple_array`, `nullable="true"`), which makes `foreach` over `null` emit a warning.
  2. The form translates `coreshop.form.customer.salutation.` + `str_replace(' ', '_', strtolower(trim($s)))`, while `AddressFormatter` translates `coreshop.form.customer.salutation.` + the **raw** value. The same salutation therefore uses two different translation keys (for example `Mr` in the form vs `mr` in the formatter).
  3. `strtolower()` is not multibyte-safe; use `mb_strtolower()`.
  4. `simple_array` stores values comma-separated, so a salutation that contains a comma is split into two.
- **Fix:** guard against `null` (`?? []`). Use one shared key-normalisation helper for both the form and the formatter (formatter side: Component CB-05). Store salutations as `json` (see I-18).

## Validation

### B-07 — `ValidAddressIdentifierValidator`: wrong exception class, `TypeError` paths, almost never triggers
- **Where:** `Validator/Constraints/ValidAddressIdentifierValidator.php:23, 34-52`; `Resources/config/validation/Address.yml:17-18`
- **Problems:**
  1. It throws `Symfony\Component\Form\Exception\UnexpectedTypeException`. It should throw `Symfony\Component\Validator\Exception\UnexpectedTypeException`, and as written it adds a hidden dependency on the Form component.
  2. The validated getter `getAddressIdentifier()` returns `?AddressIdentifierInterface`, an **object**. `setParameter('%address_identifier%', $value)` requires a `string`, and with `strict_types` that throws a `TypeError`.
  3. `find($entity)` on a new (unpersisted) identifier makes Doctrine throw a missing-identifier exception before the violation is built.
  4. In practice the constraint never adds a violation: a persisted entity is always found, and a deleted one is already `null`.
- **Fix:** validate the semantics: the value is `instanceof AddressIdentifierInterface`, it is persisted, and it is `active`. Build the message parameter from `getName()`. Use the Validator exception and `mixed $value`.

### B-08 — No validation for `AddressIdentifier`, and no uniqueness rules anywhere
- **Where:** `Resources/config/validation/` (no `AddressIdentifier.yml`); `Resources/config/services/form.yml:8` (validation groups defined for it)
- **Problem:** An identifier can be saved with an empty or duplicate `name`. Countries (`isoCode`), zones (`name`) and states (`country` + `isoCode`) have no `UniqueEntity` constraint either. Duplicates then break the repository lookups in B-09 and B-10.
- **Fix:** add `AddressIdentifier.yml` (NotBlank, UniqueEntity on `name`). Add `UniqueEntity` to Country, Zone and State.

## Doctrine / repositories

### B-09 — `CountryRepository::findByCode()` can throw `NonUniqueResultException`
- **Where:** `Doctrine/ORM/CountryRepository.php:46-54`; `Resources/config/doctrine/model/Country.orm.xml:7-9, 15`
- **Problem:** `isoCode` has a plain (non-unique) index and is `nullable`, yet `getOneOrNullResult()` is used. Duplicate codes crash `CoreBundle/Command/SetupStatesCommand.php:97` and `CountryFixture`. `nullable="true"` also contradicts `NotBlank` in `validation/Country.yml`.
- **Fix:** add a unique constraint, make the column `NOT NULL`, and add `UniqueEntity` (B-08). Write a migration.

### B-10 — `AddressIdentifierRepository::findByName()` can throw `NonUniqueResultException`
- **Where:** `Doctrine/ORM/AddressIdentifierRepository.php:27-35`; `Resources/config/doctrine/model/AddressIdentifier.orm.xml:12`
- **Problem:** `name` is not unique, but `getOneOrNullResult()` is used.
- **Fix:** add a unique constraint and `UniqueEntity` (B-08). Write a migration.

### B-11 — `Country.addressFormat` is a `VARCHAR(255)` but is edited as a multi-line Twig template
- **Where:** `Resources/config/doctrine/model/Country.orm.xml:17`; `Form/Type/CountryType.php:42` (`TextareaType`)
- **Problem:** Longer formats are truncated, or fail with "Data too long" in strict SQL mode.
- **Fix:** map the field as `type="text"` and write a migration.

## Context / service wiring

- **B-12** → moved to Component **CB-01** (stateful contexts never reset).
- **B-13** → moved to Component **CB-02** (resolver returning `null`).

### B-14 — `autoconfigure_with_attributes` is a `scalarNode` but is passed to a `bool` parameter under `strict_types`
- **Where:** `DependencyInjection/Configuration.php:63`; `DependencyInjection/CoreShopAddressExtension.php:70, 78`
- **Problem:** Values such as `1`, `"true"` or an env placeholder make container compilation fail with a `TypeError` in `Autoconfiguration::registerForAutoConfiguration(bool $autoconfigureWithAttributes)`.
- **Fix:** use `booleanNode()->defaultFalse()`.

### B-15 — `resources.*.graphql.enabled` flags have no effect
- **Where:** `DependencyInjection/Configuration.php:99-104, 120-125, 163-168, 184-189, 209-214`; `Resources/config/services/data_hub.yml`; `DependencyInjection/CoreShopAddressExtension.php:59-61`
- **Problem:** `data_hub.yml` registers the GraphQL type generators whenever the DataHub bundle is present, regardless of `enabled: false`. Nothing else in the ResourceBundle reads the flag either. `zone` has no flag and no generator, which is inconsistent with the other resources.
- **Fix:** register each generator only when its flag is true (in the Extension, or in a compiler pass), or remove the flags.

## Packaging / Symfony 7.4 compatibility

### B-16 — `ValidAddressIdentifier` uses the options-array constraint API (deprecated in 7.3/7.4, removed in 8.0)
- **Where:** `Validator/Constraints/ValidAddressIdentifier.php`; `Resources/config/validation/Address.yml:18` (`{ groups: [coreshop] }`)
- **Problem:** Since Symfony 7.3, custom constraints without named constructor arguments (`#[HasNamedArguments]`) trigger "Passing an array of options to configure the … constraint is deprecated". The YAML loader passes an options array, which emits the deprecation on Symfony 7.4 and breaks on 8.0. The class also has no `#[\Attribute]`, so it cannot be used as a PHP attribute.
- **Fix:** add a constructor with `#[HasNamedArguments]` (`?string $message = null, ?array $groups = null, mixed $payload = null`) and `#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]`.

### B-17 — The bundle `composer.json` does not describe its real requirements
- **Where:** `composer.json:26-35`
- **Problems:**
  1. No `"php": "^8.4"` constraint.
  2. Directly used packages are not declared: `symfony/form`, `symfony/validator`, `symfony/options-resolver`, `symfony/http-kernel`, `symfony/http-foundation`, `symfony/config`, `symfony/dependency-injection`, `symfony/doctrine-bridge`, `symfony/translation-contracts`, `twig/twig`, `doctrine/orm`, `jms/serializer-bundle`, `pimcore/pimcore`. None of them is pinned to `^7.4`.
  3. `phpstan/phpstan ^1.10` (and its plugins at `^1.x`) conflicts with the root `^2.1` and does not fully support PHP 8.4.
  4. `six-it/pimcore-source ^2.0` vs root `^2.1` (commit d670a7c6b).
  5. `branch-alias dev-master: 4.1-dev`; verify against the current release line.
- **Fix:** declare the dependencies with `^7.4` constraints and align them with the root `composer.json`.
