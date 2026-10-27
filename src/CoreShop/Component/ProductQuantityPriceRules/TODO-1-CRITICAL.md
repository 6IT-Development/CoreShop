# ProductQuantityPriceRules Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all 28 PHP files of this component (`Calculator/`, `Detector/`, `Exception/`, `Fetcher/`, `Model/`, `Repository/`, `Rule/{Action,Condition,Fetcher}/`, `Events.php`), `composer.json` and `README.md`. Also checked: the config that wires or maps these classes. That is `ProductQuantityPriceRulesBundle/Resources/config/{services.yml,services/*.yml,doctrine/model/*.orm.xml,serializer/*.yml,validation/*.yml}` and `CoreBundle/Resources/config/services/{product-quantity-price-rules,product,cart-processor}.yml`. The scan also covers the direct consumers and extenders: `Component/Core/ProductQuantityPriceRules/**` (`UnitVolumeCalculator`, `Amount*Action`, `FixedAction`), `Component/Core/Order/Processor/CartItemsProcessor.php`, `Component/Core/Product/Cloner/ProductQuantityPriceRulesCloner.php`, `Component/Rule/Condition/{RuleValidationProcessor,RuleConditionsValidationProcessor}.php`, `Component/Registry/ServiceRegistry.php`, `ProductQuantityPriceRulesBundle/{CoreExtension,Doctrine/ORM,Twig}/*`, `CoreBundle/Twig/ProductQuantityPriceRuleRangesPriceExtension.php` and `CoreBundle/Form/Extension/ProductQuantityRange{,Collection}TypeExtension.php`.

Reference for API checks: `vendor/` = `../pimcore2teszt10211524/vendor/` (`symfony/*` 7.4.x, `doctrine/orm` 2.20.x, Pimcore via `six-it/pimcore-source`). The vendored `six-it/core-shop` copy of this component is identical to the working tree (`diff -rq` is empty).

"Critical" here means: money charged or stored wrongly, data loss or data written wrongly, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have their root cause or part of the fix outside this component. Items marked **(reproduced)** were checked with a PHP 8.4.27 script against the vendored classes. Items marked **(traced)** were followed through the code but not run end to end.

**Symfony 7.4 / PHP 8.4 compatibility:** no blockers. `php -l` (PHP 8.4.27) passes on all 28 files. Every class and interface loads under `E_ALL` without deprecations. There are no implicitly nullable parameters, no dynamic properties and no removed Symfony 7.4 APIs (the only Symfony API used is `HttpFoundation\RequestStack::getMainRequest()`). The container deprecation log has no entries for `CoreShop\Component\ProductQuantityPriceRules\*`. All service definitions resolve, and their arguments match the constructors. Forward-compatibility and modernisation items (native types, `readonly`, `#[\Override]`, `RoundingMode`, typed constants) are in TODO-3.

**Overlap with other TODOs:** the following critical items touch this component's classes but are already tracked elsewhere, so they are **not repeated**:
- `Bundle/ProductQuantityPriceRulesBundle/TODO-1-CRITICAL.md` **C-01** (draft, autosave or preview deletes live ranges), **C-02** (rule ids from the request are not checked against the product), **C-03** (`rangeStartingFrom` is serialized as `int`, so versions and the cache cut fractional limits).
- `Component/Core/TODO-1-CRITICAL.md` **C-07** (item quantity factor applied twice or not at all in `UnitVolumeCalculator` / `CartItemsProcessor`; Twig range price ignores the factor).
- `Component/Currency/TODO-1-CRITICAL.md` **C-02** (a deleted currency makes the `fixed` / `amount_*` quantity-range actions throw).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Price calculation: wrong price charged

### C-01 — (reproduced) Range lookup depends on the order of the collection, so ranges that are not sorted, or ranges of several units, give a wrong price without any error
- **Where:** `Calculator/VolumeCalculator.php:73-90` (`locate()`). The same loop is in `Component/Core/ProductQuantityPriceRules/Calculator/UnitVolumeCalculator.php:113-120` **(dependent)**.
- **Problem:**
  - `locate()` walks the ranges in collection order and `break`s at the first range whose `rangeStartingFrom` is greater than the quantity. The result is only correct when the collection is sorted ascending **and** holds only ranges that belong to the same unit.
  - The only thing that sorts the ranges is the Doctrine `<order-by rangeStartingFrom ASC>` (`ProductQuantityPriceRulesBundle/Resources/config/doctrine/model/ProductQuantityPriceRule.orm.xml`), and Doctrine applies it only when it loads the collection from the database. Nothing in the model (`ProductQuantityPriceRule::addRange()`) or the calculator sorts. The admin form checks ascending order only per unit, and only when CoreBundle's `ProductQuantityRangeCollectionTypeExtension` is installed.
  - Reproduced with ranges added in the order `1 (0 %)`, `10 (−20 %)`, `5 (−10 %)` and a price of 1000:
    - quantity 7 gives **1000** (expected 900);
    - quantity 12 gives **900** (expected 800);
    - ranges `10`, `2` with quantity 3 throw `NoPriceFoundException`, so the cart silently falls back to the normal price.
  - **Mixed units (traced, plausible):** the database order is global (`ORDER BY range_starting_from`), not per unit. When the context has no `unitDefinition` (`CartItemsProcessor.php:72-76` unsets it for items without a unit), `UnitVolumeCalculator` delegates to `VolumeCalculator`, which looks at the ranges of **every** unit. For example, ranges `piece 1`, `box 2`, `piece 10` and quantity 5 picks the **box** range.
- **When it happens:** any code path that holds an in-memory collection rather than one freshly loaded from the database. Examples: imports, API, Data Hub or fixture code that calls `addRange()` and then calculates prices in the same process (index rebuild, export, Messenger worker); entities that stay in the identity map of a long-running worker; Behat setup (`Behat/Context/Setup/ProductQuantityPriceRuleContext.php` adds ranges one by one).
- **Fix:** do not rely on order. Choose the range with the greatest `rangeStartingFrom <= quantity`, and do not stop early. For Core, filter by unit first, so that ranges without a unit match only items without a unit. One way is a `RangeLocator` service that both calculators share, so `UnitVolumeCalculator` stops copying the loop. Optionally keep the collection sorted in `addRange()`. Add a unit test with unsorted and mixed-unit ranges.

### C-02 — (reproduced) An unknown or empty pricing or calculation behaviour throws an exception the callers do not catch, so the cart and product page fail for every product that has such a rule
- **Where:** `Calculator/VolumeCalculator.php:61-71` (`$this->actionRegistry->get($pricingBehaviour)`), `Fetcher/QuantityPriceFetcher.php:44`, `:58` (`$this->calculatorRegistry->get(...)`). **(dependent)** callers: `Component/Core/Order/Processor/CartItemsProcessor.php:87-97` (catches only `NoRuleFoundException|NoPriceFoundException`), `CoreBundle/Twig/ProductQuantityPriceRuleRangesPriceExtension.php:59` (catches nothing).
- **Problem:**
  - `ServiceRegistry::get(string $identifier)` throws `NonExistingServiceException` for a type that is not registered. Under `strict_types` it throws a `TypeError` for `null`. Both were reproduced.
  - The behaviour strings are stored as plain columns (`pricing_behaviour`, `calculation_behaviour`). Only the admin form's choice list checks them. Nothing in the model, the validation YAML or the calculator checks them.
- **Impact:** a rule whose action or calculator no longer exists, for example because a project action or calculator was removed or renamed while rows still use it, or a row written by an import or the API with a typo, throws during cart processing. Adding the product to the cart, changing the quantity and every checkout step for a cart that contains it fail with HTTP 500. The product page fails as soon as the template renders a range price.
- **Fix:**
  - In `VolumeCalculator::calculateRangePrice()` and `QuantityPriceFetcher`, check `has()` first. If the type is missing, throw `NoPriceFoundException` with the original exception as `previous` (this needs TODO-2 **B-07**), so the existing fallback to the normal price applies. Log a warning that names the rule and range id.
  - Add a `Choice` / callback constraint against the registered types for `pricingBehaviour` and `calculationBehaviour`. **(dependent)** This goes in `ProductQuantityPriceRulesBundle/Resources/config/validation/`.
