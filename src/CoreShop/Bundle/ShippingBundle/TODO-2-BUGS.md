# ShippingBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs that give wrong results, wrong UI behaviour or avoidable 500s in a limited area, but do not take down a whole area on their own.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code (PHP 8.4.27) or by reading the vendored library source / the compiled container.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Dependency injection / autoconfiguration

### B-01 — Attribute and interface autoconfiguration do not produce working rule elements — (verified code path) (dependent)
- **Where:** `DependencyInjection/CoreShopShippingExtension.php:71-109`, `Attribute/*`, `Component/Registry/Autoconfiguration.php:46-73`, `Component/Registry/RegisterRegistryTypePass.php:58-61`.
- **Problem:**
  - `Autoconfiguration::getProperties()` turns `getFormType()` into the tag key `formType`; `RegisterRegistryTypePass` only reads `form-type`. So `#[AsShippingRuleConditionChecker(type: 'x', formType: X::class)]` / `#[AsShippingRuleActionProcessor(...)]` registers the checker but **no form type**. `RuleBundle/Form/Type/AbstractConfigurableRuleElementType.php:50-54,71-75` then adds no configuration fields, so the condition/action is listed in the admin but its configuration cannot be saved (extra-fields error or empty configuration). Same root cause as NotificationBundle TODO-1 **C-01**; fix it centrally (accept both spellings in the passes).
  - Interface-based autoconfiguration of actions targets the wrong interface (TODO-1 **C-04**), so custom `CarrierPriceActionProcessorInterface` / `CarrierPriceModificationActionProcessorInterface` implementations are never tagged even with `autoconfigure: true`.
  - Interface-based autoconfiguration (default mode) adds the tag **without** `type`/`form-type`, so the type becomes `Container::underscore(<short class name>)` (e.g. `my_weight_condition_checker`) and there is never a form. This is not documented; either document it or drop interface-based autoconfiguration for rule elements that need a form type.
- **Fix:** as above; add a container test per attribute.

### B-02 — `#[AsCarrierPriceCalculator]` cannot set a priority, so attribute-registered calculators always override the shipping-rule calculator — (verified code path) (dependent)
- **Where:** `Attribute/AsCarrierPriceCalculator.php` (only `type`), `Component/Registry/RegisterSimpleRegistryTypePass.php:59-63` (`$tag['priority'] ?? 1000`), `Component/Registry/PriorityMap.php:159-166` (ASC order), `Component/Shipping/Calculator/CarrierPriceCalculator.php:36-46` (last non-throwing calculator wins).
- **Problem:** the core `shipping-rule` calculator has priority `256` (`Resources/config/services.yml:37`). Every calculator registered via the attribute (or via interface autoconfiguration) gets `1000`, runs **after** it and replaces its price. There is no way to register a "fallback" calculator via attribute.
- **Fix:** add `?int $priority = null` to the attribute. See **B-03** for the calculator semantics.

### B-03 — Price calculator chain: "last one wins", and the shipping-rule calculator returns `0` instead of "no price" — (verified code path) (dependent)
- **Where:** `Component/Shipping/Calculator/CarrierPriceCalculator.php:36-46`, `Component/Shipping/Calculator/CarrierShippingRulePriceCalculator.php:44-71`, same loop in `CompositePriceCalculator.php`.
- **Problem:**
  - Every calculator is executed and each result overwrites the previous one; together with ASC ordering this means the **highest** priority wins, but only after running all others (shipping rules are evaluated even when a later calculator overrides them).
  - `CarrierShippingRulePriceCalculator` returns `0` when no rule matches instead of throwing `NoShippingPriceFoundException`, so a custom calculator with a priority below 256 is always overwritten by `0` (free shipping) when the carrier has no matching rule.
- **Fix:** iterate in descending priority and return the first result that does not throw; throw `NoShippingPriceFoundException` in `CarrierShippingRulePriceCalculator` when no rule matches; document the order. Check `CoreBundle`'s taxed calculator for callers that rely on `0`.

### B-04 — Postcode condition: whitespace, leading zeros and prefix overlaps give wrong matches — (verified) (dependent)
- **Where:** `Component/Shipping/Rule/Condition/PostcodeConditionChecker.php:31-79`, form `Form/Type/Rule/Condition/PostcodeConfigurationType.php`.
- **Reproduced** (running the checker's `checkPostCode()` loop):
  - `'1010, 1020'` vs `1020` → **no match** (the entry is `' 1020'`; only the delivery postcode is space-stripped). The admin info text (`coreshop_condition_postcodes_info`) suggests "CSV format", where `, ` is normal.
  - `'01000-01999'` vs `01500` → **no match**: `(int)` drops the leading zero, the range is expanded to `1000…1999`, prefix compare against `0150` fails. Affects all German `0xxxx` postcodes, Dutch/Swiss/Austrian ranges written with leading zeros, etc.
  - `'WR1-WR5'` also matches `WR10 …`–`WR19 …` (prefix match of `WR1`).
  - Large ranges (`10000-99999`) are expanded into an array of 90 000 strings per check and per carrier, on every cart recalculation.
- **Fix:** trim entries (see TODO-1 **C-01**); compare ranges numerically with `str_pad()` to the width of the bound, without expanding; for alphanumeric ranges require a non-digit after the numeric part (or compare the outward code only). Add unit tests.

## Forms / validation

### B-05 — `ShippingRuleConfigurationType`: the model transformer never maps the stored ID back to a choice — (verified code path)
- **Where:** `Form/Type/Rule/Common/ShippingRuleConfigurationType.php:48-63`.
- **Problem:** both closures are identical: they accept a `ShippingRuleInterface` and return its ID. `transform()` receives the **stored ID** (int), so it always returns `null`; the field is always empty when the form is built from existing data (any server-side rendering, `PATCH` with `clear_missing = false`, form-based API). `reverseTransform()` works only by accident (the choice type returns an entity).
- **Fix:** `transform(int|string|null $id): ?ShippingRuleInterface` via the repository, `reverseTransform(?ShippingRuleInterface $rule): ?int`. Also add a check that a rule does not reference itself (endless recursion in `ShippingRuleConditionChecker` / `ShippingRuleActionProcessor` → stack overflow / 500, **(plausible)**).

### B-06 — Constraints without a group never run; `priority` accepts floats — (verified code path)
- **Where:** `Resources/config/validation/ShippingRuleGroup.yml:7` (`Type: { type: integer }`), `Resources/config/validation/Carrier.yml:6` (`Type: { type: boolean }`), forms validate with `%coreshop.form.type.*.validation_groups%` = `[coreshop]` (`Resources/config/services/form.yml:1-16`).
- **Problem:** constraints without `groups` are in `Default`, which is not validated, so these two are dead. `Form/Type/ShippingRuleGroupType.php:31` uses `NumberType` for `priority` (integer column); `1.5` is accepted, passed to the untyped `setPriority()` and silently truncated by Doctrine.
- **Fix:** add `groups: [coreshop]`; use `IntegerType` for `priority`.

### B-07 — Missing range checks on rule condition/action configuration — (verified code path)
- **Where:** `Form/Type/Rule/Condition/{Amount,Weight,Dimension}ConfigurationType.php`.
- **Problem:**
  - `minAmount` has `GreaterThanOrEqual(0)`, `maxAmount` has none; weight and dimensions have no lower bound at all. Negative values are saved and then ignored by the checkers (`> 0` checks), so the admin shows a limit that is not applied.
  - No `min <= max` check: `minWeight = 10, maxWeight = 5` saves fine and the rule can never match.
  - `DimensionConfigurationType` uses `IntegerType`: product dimensions are floats (`ShippableItemInterface::getWidth()` etc.); limits like `12.5` cm cannot be entered (admin JS `decimalPrecision: 0` matches the bug, see `Resources/public/pimcore/js/shippingrule/conditions/dimension.js:44,53,62`).
- **Fix:** `PositiveOrZero` on all numeric fields, a class-level `Callback`/`Expression` constraint for `min <= max` (only when both > 0), `NumberType` with `scale` for dimensions (and the JS field).

### B-08 — No length / format validation for carrier string fields; long values end as SQL errors — (plausible)
- **Where:** `Resources/config/doctrine/model/Carrier.orm.xml:12-16` and `CarrierTranslation.orm.xml:12-13` (all `string` = `VARCHAR(255)`), `Form/Type/CarrierType.php:33-34`, `Form/Type/CarrierTranslationType.php:31-34` (`description` is a `TextareaType`), `Resources/config/validation/*.yml` (no `Length`).
- **Problem:** a description or tracking URL longer than 255 characters (tracking URLs with placeholders and query strings easily are) → `Data too long` (MySQL strict mode) → admin 500 instead of a form error. `trackingUrl` is not validated as a URL template at all.
- **Fix:** `Length(max: 255)` constraints, or better `type="text"` for `description` and `length="2048"` for `trackingUrl` (migration); a `Regex`/`Url` constraint for `trackingUrl` that allows the `%s`/placeholder syntax used by the frontend.

### B-09 — `ShippingRuleGroupCollectionType` uses `by_reference: true`; new groups depend on the admin JS sending `carrier` — (verified code path)
- **Where:** `Form/Type/ShippingRuleGroupCollectionType.php:36-41`, `Component/Shipping/Model/Carrier.php:167-182` (`addShippingRule()` sets the back-reference), `Resources/config/doctrine/model/ShippingRuleGroup.orm.xml:21-26` (`carrierId` NOT NULL).
- **Problem:** with `by_reference: true` the form writes into the collection directly and never calls `addShippingRule()` / `removeShippingRule()`, so the back-reference is only set because `Resources/public/pimcore/js/carrier/item.js:252,301` posts `carrier: this.data.id`. Any other client (API, import through the form, a new carrier whose ID is still `null` in the JS) creates a group without carrier → NOT NULL violation (500); `validation/ShippingRuleGroup.yml` `NotBlank` on `carrier` catches it only if the form is validated before flush.
- **Fix:** `'by_reference' => false` so the adder/remover keep both sides in sync; drop the `carrier` field from `ShippingRuleGroupType` (the parent sets it).

### B-10 — `CarrierChoiceType` assumes `choices` are carrier entities and ignores its own `active` option — (verified code path)
- **Where:** `Form/Type/CarrierChoiceType.php:37-75`; used by `CoreBundle/Form/Extension/CartCreationTypeExtension.php:38` and `OrderShipmentCreationTypeExtension.php:30`.
- **Problem:**
  - `buildView()` calls `$carrier->getDescription()` / `getId()` on every entry of `choices`. Passing custom choices (IDs, grouped choices, a filtered list as array of labels) → `Error: Call to a member function getDescription() on …`.
  - The option `'active' => true` is defined but never read; `hideFromCheckout` carriers are always offered.
  - Labels use the technical `identifier` instead of the translated `title`.
- **Fix:** use `$view->vars['choices']` (`ChoiceView::$data`) and `instanceof CarrierInterface`; remove `active` or implement it as a filter; use `choice_label: fn (CarrierInterface $c) => $c->getTitle() ?: $c->getIdentifier()`.

## Runtime / component

### B-11 — `CarrierShippingRuleChecker` passes the carrier under key `0` instead of `carrier` — (verified code path) (dependent)
- **Where:** `Component/Shipping/Checker/CarrierShippingRuleChecker.php:47-51` (`[$carrier, 'shippable' => …, 'address' => …]`). `ShippingRuleConditionChecker.php:39` passes `['carrier' => $carrier, …]`.
- **Problem:** custom or nested conditions that read `$params['carrier']` get it only when the rule is evaluated through a `shippingRule` condition, not for the top-level rule. Inconsistent and easy to miss.
- **Fix:** use `'carrier' => $carrier`.

### B-12 — `getConfig` (and `list`) endpoints have no permission check — (verified code path) (dependent)
- **Where:** `Controller/CarrierController.php:26-48`, `Controller/ShippingRuleController.php:27-33`; `ResourceBundle/Controller/ResourceController.php:80-89` (`listAction` has no `isGrantedOr403()` either).
- **Problem:** any logged-in Pimcore admin user without the `coreshop_permission_carrier` / `coreshop_permission_shipping_rule` permission can list all carriers and shipping rules (names, identifiers) and the config. Low impact (admin-only, read-only), but inconsistent with `get`/`save`/`delete`.
- **Fix:** call `$this->isGrantedOr403()` in both `getConfigAction()` methods (and in `ResourceController::listAction()` centrally).

### B-13 — `CarrierTranslation` serializer has no `locale`, `ShippingRuleGroup` uses the root name of `ShippingRule` — (verified code path)
- **Where:** `Resources/config/serializer/Model.CarrierTranslation.yml`, `Resources/config/serializer/Model.ShippingRuleGroup.yml:3`.
- **Problem:** the `translations` collection of a carrier (`Model.Carrier.yml:34-37`) is serialized without the locale of each entry (only the collection key carries it, which is lost in list/XML output); `xml_root_name: shipping_rule` for `ShippingRuleGroup` makes XML output of carriers and rules indistinguishable.
- **Fix:** expose `id` and `locale` on the translation, rename the root to `shipping_rule_group`. Check the admin JS before changing the JSON shape.
