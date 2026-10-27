# TaxationBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items (Symfony 8). None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as sub-bullets.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle.

**Already fine for Symfony 7.4:** the extension extends CoreShop's `AbstractModelExtension` (based on `Symfony\Component\DependencyInjection\Extension\Extension`), service config is YAML (not the XML service format deprecated in 7.4; the `*.orm.xml` files are Doctrine mappings and not affected), `TreeBuilder` is constructed with a root name, the validation YAML uses named options (no array-options constructor, deprecated in 7.3), no `ContainerAwareInterface`, every form method has native return types, `getExtendedTypes()` (CoreBundle extension) is static/iterable, constructor property promotion is already used in the forms and the collector.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — `composer.json` (bundle and component) does not describe what the code needs
- **Where:** `composer.json:26-58`; `Component/Taxation/composer.json`.
- **Fix:**
  - No `php` constraint in either file. Add `"php": "^8.4"`.
  - Bundle: directly used packages are not declared: `symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel`, `symfony/http-foundation`, `symfony/form`, `symfony/options-resolver`, `symfony/validator` (validation YAML) (all `^7.4`), `doctrine/orm` (repositories / `QueryBuilder`), `gedmo/doctrine-extensions` (mappings use `gedmo:timestampable`), `jms/serializer-bundle` (`pimcore/config.yml:12-17`), `six-it/admin-ui-classic-bundle` (admin JS / routes). `pimcore/data-hub` belongs in `suggest` (`services/data_hub.yml`).
  - Component: `doctrine/collections` (`TaxRuleGroup`) is not declared.
  - `require-dev` pins PHPStan 1.x (EOL, does not understand PHP 8.4 syntax); bump to PHPStan 2 together with the other bundles.
  - `"minimum-stability": "dev"` and `repositories` (`path` `../../*/*`) have no effect for consumers of a library; move them to the monorepo root. `:42-47` uses 4-space indent while the rest uses 2; the component file has a stray 3-space `"extra"` line.

### I-02 — Configuration tree: inconsistent defaults, wrong node types, missing docs
- **Where:** `DependencyInjection/Configuration.php`.
- **Fix:**
  - `:177,181,185,189` `->prototype('scalar')` → `->scalarPrototype()`.
  - `:191-194` `permissions` is a `scalarNode` with an array default and `cannotBeOverwritten()`; since it never comes from user config, make it a constant passed by the extension (or `arrayNode()->scalarPrototype()`).
  - `:119` `tax_rule_group.classes.repository` has no default while `tax_rate` / `tax_rule` point to bundle repositories; `:135` `tax_rule.repository` and `:99` `translation.factory` lack `cannotBeEmpty()` that every sibling has. Make the three resources consistent.
  - `:158` `cannotBeOverwritten(true)` – the argument is the default; drop it.
  - `:70,86,110,128,149` `options` `variableNode`s have no `info()` / default; add `->info()` to every node so `config:dump-reference core_shop_taxation` is useful.
  - The two private methods mix `->children()` styles (`:62-63` vs `:171`); align formatting.

### I-03 — Extension and service wiring
- **Where:** `DependencyInjection/CoreShopTaxationExtension.php:29-48`; `Resources/config/services.yml`, `services/{form,pimcore}.yml`.
- **Fix:**
  - `:37` `array_key_exists('pimcore_admin', $configs)` is always true (`addDefaultsIfNotSet()`); remove the check.
  - `:31` reassigns `$configs` (input list) with the processed config; name it `$config`.
  - `:41-45` checks `kernel.bundles` by short name; `class_exists(PimcoreDataHubBundle::class)` / `$container->hasExtension('pimcore_data_hub')` is more robust.
  - All services are `public: true`; only the controllers (resolved by ResourceBundle) and services fetched via `\Pimcore::getContainer()->get()` (**I-11**) need it. Make `TaxCollector` and the form types private; form types only need `autoconfigure: true` (then the explicit `form.type` tags go away).
  - `services.yml:9-13` aliases the interface to the class id; prefer `TaxCollector` as private service + public alias only if needed.

### I-04 — Component models: add native types, fix the `rate` type and store rates as decimals
- **Where:** `Component/Taxation/Model/*.php`, `Component/Taxation/Repository/*.php`; `Resources/config/doctrine/model/TaxRate.orm.xml:13`.
- **Fix:**
  - Interfaces declare types only in docblocks: `TaxRateInterface::getName()/getRate()/setRate($rate)`, `TaxRateTranslationInterface::getName()/setName($name)`, `TaxRuleGroupInterface::getName()/setName()/getTaxRules()/hasTaxRules()/addTaxRule()/removeTaxRule()/hasTaxRule()`, `TaxRuleInterface::getBehavior()/setBehavior()/getTaxRuleGroup()/getTaxRate()`, `TaxItemInterface` setters. Add native parameter / return types (`?string`, `float`, `int`, `Collection<int, TaxRuleInterface>`, `: void` / `: static`) in one BC-breaking step with the implementations.
  - Properties are untyped (`protected $id`, `$rate = 0` (int, documented as float), `$name`, `$behavior`, `$taxRuleGroup`, `$taxRate`); use typed properties (`protected ?int $id = null;`, `protected float $rate = 0.0;` …).
  - Setter return values are inconsistent: `TaxRule` setters `return $this` (not declared), all other setters return nothing.
  - Constructors are formatted as `__construct(\n        ) {` (`TaxRate.php:49-52`, `TaxRuleGroup.php:50-53`); use `public function __construct()`.
  - `rate` is a `float` column and a PHP float everywhere (form, calculator, tax item). Store it as `decimal(10, 4)` (string in PHP, cast once) or as an integer in basis points so rates such as 8.875 % round-trip exactly (TODO-2 **B-05**).
  - `TaxRuleGroup.orm.xml:12` `name` has no length; `TaxRate`/`TaxRuleGroup` have no index on `active` (needed once the factory filters by it, `Component/Core/TODO-2-BUGS.md` **B-11**).

### I-05 — Replace the behaviour integers with a backed enum
- **Where:** `Component/Taxation/Calculator/TaxCalculatorInterface.php:25-40` (constants), `Form/Type/TaxRuleType.php:33-40` (hard-coded `0/1/2`), `Resources/config/validation/TaxRule.yml:7-9`, `Resources/public/pimcore/js/taxrulegroup/item.js:125-145` (and the CoreBundle copy), `Component/Core/Taxation/TaxCalculatorFactory.php` **(dependent)**.
- **Fix:**
  - Introduce `enum TaxRuleBehavior: int { case ThisTaxOnly = 0; case Combine = 1; case OneAfterAnother = 2; }`, use it as `enumType` in the Doctrine mapping and `EnumType` in the form; keep the constants as deprecated aliases.
  - Until then add `Choice: { choices: [0, 1, 2] }` to `behavior`; the current `Type: integer` accepts any int.
  - The constant names and docs are misleading: `DISABLE_METHOD` ("only use this tax") is not a calculator method at all; `TaxRulesTaxCalculator` treats it as "combine", and the "only this tax" meaning lives in the factory (stop collecting further rules). Document per-rule vs per-calculator behaviour: today only the **first** rule's behaviour is used for the whole calculator.
  - `TaxRuleType` labels are translation keys (`coreshop.form.tax_rule.behaviour.*`) rendered with `choice_translation_domain => false`, so any Twig rendering shows raw keys; the keys are also not in `Resources/translations`.

---

## Calculator, collector and tests

### I-06 — Controller / route: remove or harden `listRules`
- **Where:** `Controller/TaxRuleGroupController.php:29-38`, `Resources/config/pimcore/routing.yml:10-15`.
- **Fix:** no admin JS calls `coreshop_tax_rule_group_listRules` (the group detail already contains `taxRules`). Either drop the action, route and controller class (the default `ResourceController` would do) or keep it with the fixes from TODO-2 **B-06**, `JsonResponse` as return type, the serializer group (`['group' => 'List']`) passed explicitly, and an `instanceof TaxRuleGroupInterface` check instead of the `@var` docblock.

### I-07 — `TaxRulesTaxCalculator`: immutable state, typed constructor, no duplicated branches
- **Where:** `Component/Taxation/Calculator/TaxRulesTaxCalculator.php`, `TaxCalculatorInterface.php`.
- **Fix:**
  - `:25-38` `public array $taxRates` / `public int $computationMethod` can be changed from outside after construction (and the cached calculators are shared, `Component/Core/Taxation/CachedTaxCalculatorFactory.php`). Use `public function __construct(private readonly array $taxRates = [], private readonly int $computationMethod = self::COMBINE_METHOD)`; drop the untyped parameter and the `(int)` cast. Mark the class `final` or document it as an extension point.
  - `:80-85` both branches compute the same expression; `:101-106` likewise (only the `$price +=`/`-=` differs). After the fix of TODO-1 **C-01/C-02** extract one loop.
  - `:72` vs `:93`: one `array_sum()` is cast to `int`, the other is not; return `(int)` consistently or keep integer arrays typed (`array<int, int>` in PHPDoc).
  - `:52` `$taxes = 0` (int) then float math; start from `0.0`. `getTotalRate()` is recomputed on every `applyTaxes()`/`removeTaxes()` call; compute once in the constructor.
  - Interface docblocks are copy-pasted ("Return the tax amount associated to each taxes …" for four different methods, `@return int $taxes_amount`); describe net/gross input and the array key (tax-rate id) precisely.

### I-08 — No unit tests for the calculator and the collector
- **Where:** the component and the bundle have no `Tests/` directory.
- **Fix:** add PHPUnit tests for `TaxRulesTaxCalculator` (net / gross × combine / one-after-another × 0, 1, 2, 3 rates, rounding at 1 cent, duplicate rate, rate 0) and `TaxCollector` (merge, zero amounts, unknown id). They pin TODO-1 **C-01**, **C-02** and TODO-2 **B-01**, **B-02**.

### I-09 — `TaxCollector`: avoid repository lookups and dead work
- **Where:** `Component/Taxation/Collector/TaxCollector.php:66-95`.
- **Fix:**
  - `:71` queries the repository **before** the `$amount === 0` early return (`:73-75`); swap them.
  - The calculator already holds the `TaxRateInterface` objects, yet every amount is looked up again by id. Let the collector take `TaxCalculatorInterface::getTaxRates()` (keyed by id) instead of `find()`; `mergeTaxes()` can use `$tax->getTaxRate()` of the existing item. (Same pattern in CoreBundle, see `Component/Core/TODO-3-IMPROVEMENTS.md` **I-11**.)
  - Type the dependency as `TaxRateRepositoryInterface` (or drop it), make the promoted properties `readonly`, and replace the `@var` docblocks with `instanceof` / `Assert::isInstanceOf`.
  - `mergeTaxes(array $taxes1, array $taxes2)` – rename parameters (`$from`, `$into`) and document that keys must be tax-rate ids.

---

## Bundle code readability

### I-10 — Form types: copy-paste names, wrong block prefix, untyped options
- **Where:** `Form/Type/*.php`.
- **Fix:**
  - `TaxRateChoiceType.php:31,44,49`: constructor parameter is `$countryRepository` (copy-paste); rename to `$taxRateRepository` and type it as `TaxRateRepositoryInterface`.
  - `TaxRateChoiceType.php:73`: block prefix `coreshop_tax_rule_choice` for a **rate** choice; use `coreshop_tax_rate_choice` (note in UPGRADE for form themes).
  - `TaxRateChoiceType.php:61`: `active` option has no `setAllowedTypes('active', ['null', 'bool'])`. `TaxRuleGroupChoiceType` has no `active` option at all and always lists inactive groups; add the same option for consistency.
  - Sorting: `usort()` with `<=>` on possibly `null` names is not locale-aware; use `\Collator` (intl) or sort in the query. Closures can be `static fn`. `TaxRuleGroupChoiceType.php:39` has an unused `Options $options` parameter.
  - Block prefixes are inconsistent (`coreshop_taxation_tax_rule_group` vs `coreshop_tax_rate` / `coreshop_tax_rule`).
  - `TaxRateType`, `TaxRuleGroupType`, `TaxRuleType` are not `final` while the other three are; decide consciously (CoreBundle only extends them through a type extension, so `final` is possible).

### I-11 — CoreExtension data types: service location and copy-paste
- **Where:** `CoreExtension/TaxRate.php`, `CoreExtension/TaxRuleGroup.php`; **(dependent)** `ResourceBundle/CoreExtension/Select.php:32-38`.
- **Fix:**
  - Both classes are identical except for three strings; move the repository id / model parameter / interface into class constants or a small trait.
  - `\Pimcore::getContainer()->get()` / `getParameter()` (`:38,43`) are static service lookups that require the repository services to stay public; there is no DI for Pimcore data types, but the lookup can be centralised in `Select` with a typed helper.
  - `getRepository()` has no return type (parent abstract method is untyped); add `: RepositoryInterface` in both, together with the parent.
  - `getInterface()` returns a class name with a leading `\` that the parent concatenates into PHP type declarations; keep, but document it, or let the parent add the `\`.
  - The class-wide `@psalm-suppress InvalidReturnType, InvalidReturnStatement` hides real issues; remove after typing.

### I-12 — Repositories
- **Where:** `Doctrine/ORM/TaxRateRepository.php:26`, `Doctrine/ORM/TaxRuleRepository.php:27-35`, `Component/Taxation/Repository/*.php`.
- **Fix:** `findByName($name, $locale)` is untyped (docblock only) → `string $name, string $locale`; add `@extends EntityRepository<TaxRate>` / `@return list<…>` generics; add a deterministic `->orderBy('o.id')` to both queries; `findByGroup()` duplicates `$group->getTaxRules()`.

### I-13 — Serializer, validation and translation config cleanup
- **Where:** `Resources/config/serializer/*.yml`, `Resources/config/validation/*.yml`, `Resources/translations/admin.*.yml`.
- **Fix:**
  - `Model.TaxRate.yml:10-13`: property `name` does not exist on `TaxRate` (the name lives in the translation); it is ignored and duplicated by the `getName` virtual property (`:26-29`). Remove it.
  - `Model.TaxRateTranslation.yml:3`: `xml_root_name: tax_rate` → `tax_rate_translation`.
  - `validation/TaxRate.yml:6-7`: `translations: Valid: ~` has no `groups`, unlike every other constraint; be explicit (`groups: [coreshop]`).
  - `validation/TaxRule.yml:3-4`: `taxRuleGroup: NotBlank` can never fail through the group form (the parent sets it); harmless but misleading once `Valid` is added (TODO-2 **B-04**).
  - Translation key typo `coreshop_tax_rule_behavior_on_after_another` (all 34 `admin.*.yml` + both `taxrulegroup/item.js`); rename to `…_one_after_another` (keep the old key for a release).

### I-14 — Admin JS cleanup
- **Where:** `Resources/public/pimcore/js/**` (+ the CoreBundle copy of `taxrulegroup/item.js`).
- **Fix:**
  - `taxrulegroup/item.js:83` model field `tax` does not exist (column is `taxRate`); `:76` `listeners = {}` and `:159-161` empty `listeners` are dead; `:132-142` `break` after `return`; `trackMouseOver` / `forceFit` are ExtJS 3 options without effect.
  - `resource.js:21-22` registers the same list twice (`coreshop_taxrulegroups` and `coreshop_tax_rule_groups`); keep one and migrate users.
  - `resource.js:28-29` opens the tax **rate** panel under the item name `tax_item`, which is also the Pimcore field-collection resource name; rename to `tax_rate`.
  - The TaxationBundle `taxrulegroup/item.js` is fully replaced by the CoreBundle version (country / state columns) in every real install; consider making the CoreBundle one extend it instead of duplicating 200 lines.
