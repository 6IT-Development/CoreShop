# ProductQuantityPriceRules Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope and references: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs: wrong results, crashes on one path, or wrong behaviour in edge cases. Items that charge wrong prices across the shop, or that take down a whole area, are in TODO-1.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** have part of the fix outside this component. Items marked **(reproduced)** were checked with a PHP 8.4.27 script against the vendored classes. Items marked **(latent)** cannot be reached through today's callers but break as soon as someone uses the API as its signature allows.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Rule selection

### B-01 — Only the highest-priority valid rule is ever tried, so a rule without a matching range hides every lower-priority rule
- **Where:** `Fetcher/QuantityRuleFetcher.php:33-42` (`fetch()` returns `$rules[0]`), `Detector/QuantityReferenceDetector.php:40-45`, `Rule/Fetcher/ValidProductQuantityPriceRuleFetcher.php:31-45`.
- **Problem:**
  - `detectQuantityPrice()` picks one rule (by priority) and then asks that rule's calculator for a price. If that rule has no range for the quantity, the detector throws `NoPriceFoundException`, and the cart falls back to the normal price. This happens when the quantity is below the first range, when the rule has no range for the item's unit (`UnitVolumeCalculator`), or when the rule has no ranges at all. A lower-priority rule that **does** cover the quantity is never tried.
  - Example: rule A has priority 10 and ranges from 10. Rule B has priority 0 and ranges from 1. Quantity 5 gives the normal price, not rule B's price.
  - Rules without ranges reach this code. Bundle **B-06** (recycle-bin restore) produces them, and so do imports, because the `Count(min: 1)` constraint runs only in the admin form.
- **Fix:** in `detectQuantityPrice()`, try the rules in priority order and return the first price that is found (catch `NoPriceFoundException` for each rule). At minimum, skip rules with `!hasRanges()` in `ValidProductQuantityPriceRuleFetcher`. If "one rule only" is intended, document it in the interface and the docs. Keep `detectRule()` (used by Twig) consistent with whatever is chosen.

### B-02 — (reproduced) Rules with the same priority are chosen in an undefined order
- **Where:** `Fetcher/QuantityRuleFetcher.php:50-56`. **(dependent)** `ProductQuantityPriceRulesBundle/Doctrine/ORM/ProductQuantityPriceRuleRepository.php:36-44` (`findForProduct()` has no `ORDER BY`).
- **Problem:**
  - When two rules have the same priority, `usort` keeps their input order. That order comes from a query with no `ORDER BY`, so MySQL may return it differently after an index change, an `OPTIMIZE` or a dump and restore. It may also differ between a database load and a cache load. Which rule wins, and so the price, can change without any edit.
  - The comparator uses `===`, so `5` and `5.0` are not seen as equal, and `5 > 5.0` is false. Each rule then sorts after the other, which makes the comparator inconsistent. Reproduced: inputs `[A(5), B(5.0)]` sort to `B,A`, and inputs `[B, A]` sort to `A,B`. The float comes from the `NumberType` form field, which is tracked as bundle **B-09** and not repeated here.
- **Fix:** `static fn ($a, $b) => [(int) $b->getPriority(), $a->getId() ?? PHP_INT_MAX] <=> [(int) $a->getPriority(), $b->getId() ?? PHP_INT_MAX]`, which sorts by priority descending, then by id ascending. Add `->orderBy('o.priority', 'DESC')->addOrderBy('o.id', 'ASC')` to `findForProduct()` **(dependent)**.

### B-03 — (reproduced) `MemoryCachedValidRuleFetcher` gives unsaved products one shared cache slot and returns stale rules after the product's rules change
- **Where:** `Rule/Fetcher/MemoryCachedValidRuleFetcher.php:35-48`.
- **Problem:**
  - The cache key is `$product->getId()`. Every product with id `null` (an unsaved object, a variant being generated, a temporary object used for a price preview) uses the same key `""`. Reproduced: two different unsaved products get the same cached result.
  - The cached array is not dropped when `setQuantityPriceRules()` is called or the product is saved in the same request. For example, the admin saves the product and then recalculates an order or renders a price, and still gets the old rules.
  - **Not repeated:** the cache ignores the context and is never reset (bundle **B-12**).
- **Fix:** key the cache by the product object (`\WeakMap<QuantityRangePriceAwareInterface, array>`) instead of its id. This removes the `null` collision, and the entries go away with the object. Add the context hash from bundle **B-12** as a second key level. Do not cache when `getId() === null`.

## Price calculation

### B-04 — (reproduced) Percentage actions return a negative price, or raise a price that should drop, when the percentage is out of range
- **Where:** `Rule/Action/PercentageIncreaseAction.php:28`, `Rule/Action/PercentageDecreaseAction.php:28`.
- **Problem:**
  - `percentage_increase` with −150 % on 1000 returns **−500**: there is no lower bound. A negative unit price reaches the cart and the order.
  - `percentage_decrease` with −50 % returns **1500**, so the "discount" raises the price.
  - A decrease of more than 100 % is clamped to 0 without any notice.
  - The percentage is not validated anywhere (bundle **B-13**, plausible). Imports and the API bypass the form in any case.
- **Fix:** in both actions, require `0 <= percentage` (and `<= 100` for a decrease) with `Assert`, or throw `NoPriceFoundException`. Wrap the increase result in `max(0, …)` as well. Add the constraints from bundle **B-13** **(dependent)**.

### B-05 — (reproduced) `fetchRangePrice()` / `detectRangePrice()` crash with an `Error` for a range that has no rule
- **Where:** `Fetcher/QuantityPriceFetcher.php:58` (`$range->getRule()->getCalculationBehaviour()`). `Model/QuantityRangeInterface.php:60-63` documents `getRule()` as non-null, but `Model/QuantityRange.php:53-56` holds `?ProductQuantityPriceRuleInterface`, and `removeRange()` sets it to `null`.
- **Problem:** reproduced: `Call to a member function getCalculationBehaviour() on null`. This is an `\Error`, not `NoPriceFoundException`, so no caller handles it. Ranges without a rule come from JMS deserialization of rows that do not exist yet (bundle **B-06**), and from any range that was removed or created on its own. The Twig function `coreshop_quantity_price_rule_range_price(range, …)` **(dependent: CoreBundle)** accepts any range.
- **Fix:** throw `NoPriceFoundException` when `getRule()` is `null`. Better: pass the rule in explicitly (`fetchRangePrice(ProductQuantityPriceRuleInterface $rule, QuantityRangeInterface $range, …)`), and change `getRule()` to return `?ProductQuantityPriceRuleInterface`.

## Model consistency

### B-06 — (latent, reproduced) `removeRange()` detaches a range that belongs to another rule, and `addRange()` does not detach a range from its previous rule
- **Where:** `Model/ProductQuantityPriceRule.php:175-187`.
- **Problem:**
  - `removeRange()` calls `$priceRange->setRule(null)` before it checks that the range is in this rule's collection. Reproduced: `$b->removeRange($rangeOfA)` leaves the range in `$a->getRanges()`, but with `getRule() === null`. `rule` is the owning side of the association, so the next flush writes `rule_id = NULL`, and the range is gone from rule A after a reload. That is data loss.
  - `addRange()` sets the new rule, but leaves the range in the old rule's collection. The two sides of the association then disagree.
  - Today's only caller (`ProductQuantityPriceRulesBundle/CoreExtension/ProductQuantityPriceRules.php:468`) passes ranges of the same rule, so nothing breaks yet.
- **Fix:** `removeRange()`: `if ($this->ranges->removeElement($r) && $r->getRule() === $this) { $r->setRule(null); }`. `addRange()`: when `$r->getRule()` is another rule, call `$r->getRule()?->removeRange($r)` first. Do the same for `addCondition()` / `removeCondition()` if conditions become owned per rule.

### B-07 — (reproduced) The exception constructors accept only `\Exception` as the previous exception, so wrapping an `\Error` throws a `TypeError`
- **Where:** `Exception/NoPriceFoundException.php:23-28`, `Exception/NoRuleFoundException.php:23-28`.
- **Problem:** `?\Exception $previousException`. Reproduced: `new NoPriceFoundException('x', new \TypeError())` throws a `TypeError` itself. The error path then replaces the real error with an unrelated one. The fix for TODO-1 **C-02**, and for TODO-2 **B-05**, needs to wrap a `TypeError` or `\Error`.
- **Fix:** `?\Throwable $previous = null` in both constructors. Type the first parameter too: `string $calculatorClass`, `?string $message = null`. See TODO-3 **I-07**.

---

## Already tracked elsewhere (not repeated)
- `Bundle/ProductQuantityPriceRulesBundle/TODO-2-BUGS.md` **B-06** (ranges without a rule after recycle-bin restore), **B-09** (`priority` float vs `integer`), **B-11** (Twig functions throw `NoRuleFoundException`), **B-12** (`MemoryCachedValidRuleFetcher` ignores the context and is never reset), **B-13** (percentage not validated), **B-14** (`findWithActionOfType()` throws `\Exception`).
- `Component/Core/TODO-2-BUGS.md` **B-06** (price 0 means "no price" only in `UnitVolumeCalculator`, not in `VolumeCalculator`).
- `Bundle/CoreBundle/TODO-2-BUGS.md` **B-26** (`ProductQuantityRangeTypeExtension::roundQuantity()` precision / `TypeError`).
