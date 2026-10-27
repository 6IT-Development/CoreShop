# Locale Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: all PHP in this component (`Context/{Composite,Fixed,Pimcore}LocaleContext.php`, `Context/LocaleContextInterface.php`, `Context/LocaleNotFoundException.php`, `Model/LocaleAwareInterface.php`), `composer.json`, `README.md`. Also checked: the code and config that depend on it:
`Bundle/LocaleBundle/*` (services, compiler pass, extension, configuration), `CoreBundle/Resources/config/services/{locale,context,reports,checkout}.yml`, `Component/Core/Locale/Context/PimcoreAdminLocaleContext.php`, `Component/Core/Context/{ShopperContext,StoreBasedCartContext}.php`, `CoreBundle/Checkout/Step/CustomerCheckoutStep.php`, `CoreBundle/Report/{Customers,Vouchers,Sales,AbandonedCarts,Categories,Products,Manufacturer}Report.php`, `OrderBundle/EventListener/CheckoutCartLocaleAssignerListener.php`, `OrderBundle/Resources/config/services/{checkout_listener,profiler,grid_config}.yml`, `OrderBundle/Collector/CartCollector.php`, `OrderBundle/Pimcore/GridColumnConfig/Operator/{PriceFormatter,Factory/PriceFormatterFactory}.php`, `FrontendBundle/Controller/RegisterController.php`, `FrontendBundle/Resources/install/pimcore/staticroutes.yml`, `Component/Resource/Translation/TranslatableEntityLocaleAssigner.php`, `Component/Core/Notification/Rule/Action/Order/OrderMailActionProcessor.php`, `PayumBundle/Action/{,Offline/}ResolveNextRouteAction.php`, `Behat/Context/Setup/LocaleContext.php`.

Reference for API checks: `../pimcore2teszt10211524/vendor/six-it/pimcore-source/lib/{Localization/LocaleService,Localization/LocaleServiceInterface,Tool}.php`, `../pimcore2teszt10211524/vendor/symfony/http-foundation/Request.php`, `../pimcore2teszt10211524/var/classes/DataObject/{CoreShopOrder,CoreShopCustomer}.php`.

"Critical" here means: a security issue, money charged or stored wrongly, data loss or data written wrongly, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items marked **(dependent)** live outside this component, but they come from how it is used, and they should be fixed together with it.

**Symfony 7.4 / PHP 8.4 compatibility:** no blockers. `php -l` (PHP 8.4.27) passes on all 6 PHP files. There are no implicit nullable parameters, no dynamic properties and no removed functions. The component uses no Symfony API at all. The Pimcore APIs it calls (`LocaleServiceInterface::findLocale(): string`, `Tool::isValidLanguage(?string)`, `Tool::getDefaultLanguage(): ?string`) exist with these signatures in the installed `six-it/pimcore-source`. The generated `setLocaleCode(?string): static` of `CoreShopOrder`/`CoreShopCustomer` is compatible with `LocaleAwareInterface`. The installed `laminas/laminas-stdlib` is 3.21.0, which declares PHP 8.4 support. The constraint floor `^3.6` is too low (TODO-3 **I-07**).

**Overlap with other TODOs:** the LocaleBundle TODOs (`Bundle/LocaleBundle/TODO-*.md`, scan date 2026-10-26) already scanned this component as part of their scope. Their items are **not repeated here**. Fix them together with this file:
- LocaleBundle **C-01** (translatable entity assigner sets fallback = current locale, empty translations get persisted). It is caused by a consumer of `LocaleContextInterface`, not by this component.
- LocaleBundle **B-02**, **B-03**, **B-07** (`PimcoreLocaleContext`, `FixedLocaleContext`), **B-08** (locale codes concatenated into SQL table names), **B-09** (registration), **B-10** (`composer.json`).
- LocaleBundle **I-01**, **I-06**, **I-08**, **I-10**, **I-11**, **I-14**, **I-15**, **I-16**, **I-17**, **I-18** (component classes).
- **Corrections to the LocaleBundle TODOs** found during this scan (see TODO-2 **B-01**): LocaleBundle **B-02** (`TypeError` in `PimcoreLocaleContext`) cannot happen. LocaleBundle **B-03** is worse than described: the context never throws at all, not even for a missing locale. LocaleBundle **B-09** cannot happen with the built-in contexts. LocaleBundle **I-08**'s suggestion to initialise `CompositeLocaleContext::$localeContexts` inline is not valid PHP (see TODO-3 **I-03**).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## No critical items

No new critical items were found in this component or its dependents. Reasons:

- The component has no I/O, no persistence and no money logic. Its only effects are the locale codes it returns.
- The locale codes do reach SQL identifiers and persisted fields. The SQL path is already tracked as LocaleBundle **B-08**. The persisted-data path (empty translation rows) is LocaleBundle **C-01**.
- The "whole area goes down" scenarios in the LocaleBundle TODOs (uncaught `LocaleNotFoundException` / `TypeError`) cannot be reached with the built-in wiring, because `PimcoreLocaleContext` never throws (TODO-2 **B-01**). The real risk is the opposite: a wrong or empty locale is returned silently and stored (TODO-2 **B-02**, **B-03**). These are rated as bugs, not critical: they produce wrong-language output and junk values, not data loss or outages.

Re-check this file when TODO-2 **B-01** is fixed. Once `PimcoreLocaleContext` can actually throw, every unguarded `getLocaleCode()` caller (TODO-2 **B-05**, LocaleBundle **B-09**) becomes reachable. Fix them first, or in the same change.
