# AddressBundle – TODO 3: Improvements (code quality, readability, logic, PHP 8.4 / Symfony 7.4 idioms)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
None of these are defects on their own. They make the bundle cleaner, safer, faster, or more idiomatic for the target stack.
Paths are relative to the bundle root unless noted otherwise. Component items (`CC-`, `CB-`, `CI-`) are in `src/CoreShop/Component/Address/TODO-*.md`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 language features

### I-01 — Make promoted constructor properties `readonly`
- **Where:** `Collector/CountryCollector.php:31-32`, `Form/Type/AddressIdentifierChoiceType.php:31`, `CountryChoiceType.php:33`, `SalutationChoiceType.php:31`, `StateChoiceType.php:31`, `ZoneChoiceType.php:31`, `Twig/FormatAddressExtension.php:28`, `Validator/Constraints/ValidAddressIdentifierValidator.php:30`
- Use `private readonly`. Where the parent class allows it, declare the whole class `final readonly class` (for example `FormatAddressExtension`). `ConstraintValidator` and `DataCollector` have mutable parents, so use readonly properties there.

### I-02 — Attribute classes: `final readonly`, public property
- **Where:** `Attribute/AsCountryContext.php`, `Attribute/AsRequestBasedResolverCountryContext.php`
- Both classes are identical. Make them `final readonly class` with `public ?int $priority`. Keep `getPriority()` because `Autoconfiguration::getProperties()` reflects on getters. Alternatively, use PHP 8.4 asymmetric visibility (`public private(set)`). Optionally extract a shared base or trait.

### I-03 — Complete the native types
- `CountryCollector::__construct($countryChangeSupport = false)` → `bool $countryChangeSupport = false`
- `AddressIdentifierRepository::findByName($name)` → `string $name` (together with the interface change in Component CI-01)
- `ValidAddressIdentifierValidator::validate($value, …)` → `mixed $value`
- `CountryRepository::findByName(): array` → document `@return list<CountryInterface>`

### I-04 — Shorter closures and first-class callables
- Turn the `usort` callbacks in all four choice types into arrow functions: `fn (CountryInterface $a, CountryInterface $b): int => …`.
- Use a first-class callable for the Twig filter: `new TwigFilter('coreshop_format_address', $this->addressFormatter->formatAddress(...), …)` (`Twig/FormatAddressExtension.php:35`).
- Apply Rector's `UP_TO_PHP_84` and `SYMFONY_74` sets as a baseline pass.

## Symfony 7.4 idioms

### I-05 — Remove duplication between the choice types and load choices lazily
- **Where:** `Form/Type/AddressIdentifierChoiceType.php`, `CountryChoiceType.php`, `StateChoiceType.php`, `ZoneChoiceType.php` (nearly identical 40-line `configureOptions()`)
- Extract an `AbstractResourceChoiceType` (repository, `active` filter, sorting). Use `'choice_loader' => ChoiceList::lazy($this, fn () => …, $options['active'])` so the query runs only when choices are actually needed, and is cached per option set, instead of on every form build.

### I-06 — Declare option types
- `active`: `setAllowedTypes('active', ['null', 'bool'])` in all choice types.
- `show_address_identifier_choice`: `setAllowedTypes(..., 'bool')` (`AddressType.php:86`).
- `country`: `setAllowedTypes('country', CountryInterface::class)` instead of the `setAllowedValues` closure (`SalutationChoiceType.php:39-41`).

### I-07 — Modernise the constraint
- **Where:** `Validator/Constraints/ValidAddressIdentifier.php`
- Beyond the deprecation fix in B-16: return `ValidAddressIdentifierValidator::class` from `validatedBy()` and drop the `alias` tag in `services.yml:52`. Use the `{{ value }}` placeholder convention and a translation key for the message. Add `#[\Attribute]`.

### I-08 — Use `AbstractDataCollector` for the collector
- **Where:** `Collector/CountryCollector.php`; `Resources/config/services/profiler.yml`
- Extending `Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector` with a static `getTemplate()` removes `getName()`/`reset()` boilerplate and the template/id tag attributes. Either add a profiler panel or keep the collector toolbar-only on purpose. Only load `profiler.yml` when `WebProfilerBundle` is enabled (`CoreShopAddressExtension.php:57` already reads `kernel.bundles`).

### I-09 — Twig attribute filters (blocked)
- `#[AsTwigFilter]` (Twig ≥ 3.21, Symfony 7.3+) would replace `FormatAddressExtension`. It is blocked by the root `composer.json` conflict `twig/twig >=3.9.0 <3.14.0`. Revisit when that conflict is lifted.

### I-10 — Optional: move to the `AbstractBundle` / `configure()` / `loadExtension()` API
- `CoreShopAddressBundle` + `DependencyInjection/Configuration.php` + `CoreShopAddressExtension.php` could become a single `AbstractBundle`. This depends on `AbstractResourceBundle`/`AbstractModelExtension` in ResourceBundle supporting it, so treat it as a cross-bundle decision.

## Configuration / DI

### I-11 — Clean up `Configuration`
- **Where:** `DependencyInjection/Configuration.php`
- Line 62: the misplaced indentation of `->children()` hides the tree structure.
- Lines 264-279: `->prototype('scalar')` → `->scalarPrototype()`.
- Lines 280-283: `permissions` is a `scalarNode` with an array default. Use `arrayNode()->scalarPrototype()->defaultValue([...])`.
- Line 247: `cannotBeOverwritten(true)` → `cannotBeOverwritten()`.
- Line 208: `address_identifier.path` looks unused for a Doctrine resource. Verify and remove if it is.
- `zone` has no `graphql` node, unlike the other resources (see B-15).

### I-12 — Remove dead checks in the Extension
- **Where:** `DependencyInjection/CoreShopAddressExtension.php:49-55`
- `pimcore_admin` and `stack` use `addDefaultsIfNotSet()`, so `array_key_exists()` is always true. Call the registration directly.

### I-13 — Stop making every service public
- **Where:** `Resources/config/services.yml:7-8`, `services/form.yml:11-12`, `services/pimcore.yml:2-3`, `services/profiler.yml:2-3`
- `_defaults: public: true` exposes form types, the collector, formatters and contexts. Make them private and keep public only what `\Pimcore::getContainer()->get()` needs (the repositories, which the ResourceBundle driver already makes public).

### I-14 — Reformat the empty constructors of the compiler passes
- **Where:** `DependencyInjection/Compiler/CompositeCountryContextPass.php:29-37`, `CompositeRequestResolverPass.php:29-37` (same pattern in the component, see Component CI-02)
- `public function __construct(\n        ) {` is malformed; write `public function __construct()`. The PHP-CS-Fixer rules `method_argument_space` / `no_spaces_inside_parenthesis` would catch it.

## Pimcore core extensions

### I-15 — Reduce boilerplate in the Pimcore data types
- **Where:** `CoreExtension/AddressIdentifier.php`, `Country.php`, `State.php`, `CountryMultiselect.php`
- Since Pimcore 11, `getFieldType()` is the contract. The `public string $fieldtype` property is redundant and is also serialized into class definitions, so return a literal instead.
- `getModel()` reads a container parameter on every call. Memoize it.
- The three `Select` subclasses differ only in the service id and parameter name, so a shared abstract class could take those as parameters.
- `State::getRepository()` returns the generic `RepositoryInterface`, while the others return a specific interface. Keep this consistent.
- The `\Pimcore::getContainer()` service locator is unavoidable for Pimcore data types. Add a short comment saying why.

## Forms / logic

### I-16 — `AddressType` cleanups
- **Where:** `Form/Type/AddressType.php`
- Remove the `_redirect` field or wire it properly (see C-04).
- Derive salutation choices from the **address's** country rather than the store context country (`PRE_SET_DATA`/`PRE_SUBMIT` listener). The same mechanism can drive state filtering (B-02).
- Mark `company` and `phoneNumber` as optional consistently, and do the same for `state` (C-01).

### I-17 — Locale-aware sorting and no N+1 queries in the choice types
- **Where:** the `usort` blocks in all four choice types
- `<=>` sorts by bytes, so "Österreich" ends up after "Zypern". Use `\Collator::create($locale)->compare()`. `symfony/intl`, which depends on ext-intl, is already required at the root.
- `getName()` lazy-loads translations per entity. Add repository methods such as `findActiveWithTranslations(?bool $active)` that fetch-join `translations` (and sort in SQL where possible).

### I-18 — Clean up the Doctrine mappings
- **Where:** `Resources/config/doctrine/model/*.orm.xml`
- `salutations`: `simple_array` → `json` (avoids the comma problem, see B-06).
- `<generator strategy="AUTO"/>` → explicit `IDENTITY` (forward-compatible with ORM 3 / DBAL 4 on PostgreSQL).
- Give `CountryTranslation.name` / `StateTranslation.name` an explicit `type="string"`.
- Give indexes descriptive names (`isoCode` → `idx_coreshop_country_iso_code`).
- `orphan-removal="false"` is the default and can go (`Zone.orm.xml:20`).
- `State.country` has no `on-delete`, while the ORM cascades the remove from Country. Decide on the DB-level behaviour.

### I-19 — Strengthen validation
- **Where:** `Resources/config/validation/*.yml`
- `Address.yml`: add `Length(max)` to match the Pimcore input lengths, `Choice` for salutation, and a state ∈ country check (B-02).
- `Country.yml`: `isoCode` → `Country` constraint (ISO-3166 alpha-2) + `Length(2)`.
- `State.yml`: `Length(max=4)` on `isoCode`.
- `Type: boolean` on typed `active` properties is redundant.
- Add `Length(max=255)` on translation `name` fields.

### I-20 — `CountryController::listActiveAction` cleanups
- **Where:** `Controller/CountryController.php:27-37`
- Replace the `@var` docblock cast with a dedicated repository method (`findActive()`, sorted, with translations joined).
- Decide whether `isGrantedOr403()` should apply. The base `listAction` skips it too, so making it consistent is a ResourceBundle decision.

### I-21 — Small repository fixes
- **Where:** `Doctrine/ORM/CountryRepository.php:49`
- `'o.isoCode= :isoCode'` is missing a space.
- Compare `isoCode` case-insensitively, or normalise to uppercase on write.

## Address formatting / security hardening

### I-22 — `AddressFormatter` / `coreshop_format_address` hardening
- **Where:** `Twig/FormatAddressExtension.php:35`
- The formatter itself is tracked in the component: CC-01 (null-country crash), CC-02 (unsandboxed Twig), CB-05 (escaping), CI-05 (template caching).
- `is_safe: ['html']` is declared even when called with `asHtml: false`. Split into two filters or declare safety conditionally.

### I-23 — Serializer metadata consistency
- **Where:** `Resources/config/serializer/*.yml`
- `State.isoCode` is `Detailed`-only while `Country.isoCode` is in `List`. Align them.
- `translations: ArrayCollection` → typed `ArrayCollection<…Translation>`.
- Missing trailing newlines in several files.

## Tooling / tests

### I-24 — Tests and static analysis
- The bundle has no tests. Add unit tests for the choice types (including C-01 and C-02 regressions), the validator (B-07), the collector reset (C-03), `Configuration` (B-14), and the Extension's DataHub/profiler conditional loading.
- After upgrading to PHPStan 2.x (B-17), run at the project level with `phpstan-symfony` and `phpstan-doctrine`.

## Readability

### I-25 — Misleading constructor parameter in `StateChoiceType`
- **Where:** `Form/Type/StateChoiceType.php:31, 44, 49`
- The parameter is named `$countryRepository`, but `Resources/config/services/form.yml:42` injects `@coreshop.repository.state`. Rename it to `$stateRepository`.
