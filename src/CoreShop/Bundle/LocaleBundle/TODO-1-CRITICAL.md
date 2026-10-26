# LocaleBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: all PHP in this bundle (`CoreShopLocaleBundle.php`, `Attribute/AsLocaleContext.php`, `DependencyInjection/{Configuration,CoreShopLocaleExtension}.php`, `DependencyInjection/Compiler/CompositeLocaleContextPass.php`, `Form/Type/LocaleChoiceType.php`), `Resources/config/services.yml`, `composer.json`, `README.md`, the `Component/Locale` component it wires (`Context/{Composite,Fixed,Pimcore}LocaleContext.php`, `Context/LocaleContextInterface.php`, `Context/LocaleNotFoundException.php`, `Model/LocaleAwareInterface.php`, `composer.json`), the shared helpers it relies on (`Component/Registry/{PrioritizedCompositeServicePass,Autoconfiguration}.php`), and the code and config that depend on it:
`Component/Core/Locale/Context/PimcoreAdminLocaleContext.php`, `CoreBundle/Resources/config/services/locale.yml`, `CoreBundle/DependencyInjection/CoreShopCoreExtension.php` (`prepend()`), `Component/Resource/Translation/{TranslatableEntityLocaleAssigner,Provider/PimcoreTranslationLocaleProvider}.php`, `Component/Core/Translation/TranslatableEntityPimcoreLocaleAssigner.php`, `ResourceBundle/Resources/config/services/integrations/translation.yml`, `ResourceBundle/EventListener/ORMTranslatableListener.php`, `Component/Resource/Model/TranslatableTrait.php`, `Component/Core/Context/{ShopperContext,StoreBasedCartContext}.php`, `CoreBundle/Report/{Categories,Products,Manufacturer,AbandonedCarts}Report.php`, `FrontendBundle/Controller/RegisterController.php`, `OrderBundle/Form/Type/CartCreationType.php`, `SEOBundle/DependencyInjection/Configuration.php`, `Behat/Context/Setup/LocaleContext.php`, `Behat/Resources/config/services/contexts/setup.yml`.

"Critical" here means: a security issue, money charged or stored wrongly, data loss or data written wrongly, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers. All PHP files of the bundle and of `Component/Locale` pass `php -l` on PHP 8.4.27. There are no implicit nullable parameters, no dynamic properties and no removed Symfony APIs (the extension already extends `Symfony\Component\DependencyInjection\Extension\Extension` through `AbstractPimcoreExtension`, not the deprecated HttpKernel one). The `lazy: true` service (`PimcoreLocaleContext`) is non-final, so Symfony 7.4 can proxy it. Packaging gaps (no `php` constraint, undeclared Symfony / Doctrine bridge / registry dependencies, PHPStan 1.x) are in **B-10**. Missing PHP 8.4 / Symfony 7.4 idioms are in TODO-3.

**Overlap with other TODOs:**
- `Bundle/CurrencyBundle/TODO-2-BUGS.md` **B-18** describes the same decorator-recursion problem for currency contexts. This bundle's version is **B-04**. Fix it once in `Component/Registry/PrioritizedCompositeServicePass.php` for all `*ContextPass` bundles.
- `Component/Customer/TODO-2-BUGS.md` **B-04** asks to audit the memoising/stateful contexts (store, currency, locale) for `ResetInterface`. The locale part is **B-07**.
- The Resource component (`Component/Resource/Model/TranslatableTrait.php`) has no TODO yet. The read-creates-a-translation behaviour in **C-01** belongs there too.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## C-01 — (dependent) The active translatable-entity locale assigner sets the fallback locale to the *current* locale: every read in an untranslated locale creates an empty translation, which the next `flush()` persists

- **Where:**
  - `Component/Resource/Translation/TranslatableEntityLocaleAssigner.php:44-45`: `setCurrentLocale($currentLocale)` **and** `setFallbackLocale($currentLocale)`. The correct variant, `Component/Core/Translation/TranslatableEntityPimcoreLocaleAssigner.php:46` (`setFallbackLocale($fallbackLocale)`), exists but is not registered as a service anywhere.
  - Wiring: `ResourceBundle/Resources/config/services/integrations/translation.yml:16-20` aliases `TranslatableEntityLocaleAssignerInterface` to the broken class and injects `LocaleContextInterface` (= this bundle's `CompositeLocaleContext`).
  - Trigger: `ResourceBundle/EventListener/ORMTranslatableListener.php:69` (`postLoad`) calls the assigner for every loaded translatable entity (`Country`, `State`, `Carrier`, `PaymentProvider`, `PaymentProviderRule`, `ProductUnit`, `TaxRate`, `CartPriceRule`, product price rules).
  - Effect: `Component/Resource/Model/TranslatableTrait.php` `getTranslation()`. When neither the current nor the fallback locale has a translation, it **creates a new translation and adds it to the collection** (`addTranslation()`). The association is mapped with `cascade: ['persist', 'merge', 'remove']` (`ORMTranslatableListener.php:104`).
- **Problem:**
  1. Because fallback = current, the fallback branch can never find anything that the first lookup didn't. For any locale without a stored translation, the getter (`Country::getName()`, `Carrier::getTitle()`, …) gets a fresh empty translation instead of the default-language text.
  2. That empty translation is now a managed-to-be entity in the collection. Any later `EntityManager::flush()` in the same request persists it, for example an admin save of any resource, a voucher-code usage update during checkout, or an optimistic-lock update. The result is an empty `*_translation` row for that locale.
  3. Once the empty row exists, the real fallback is permanently shadowed for that locale, even after the assigner is fixed. The storefront shows empty country/state names in address forms and empty carrier/payment titles in checkout. The admin grid shows blank labels.
  4. **B-01** makes this much worse in the admin: `PimcoreAdminLocaleContext` returns lower-cased codes such as `de_at`, which never have a translation. Every admin request that loads a translatable entity therefore produces a junk `de_at`/`en_gb` translation.
- **Impact:** wrong data written to the database silently (empty translation rows in locales nobody maintains), and blank customer-facing labels (country, carrier, payment provider) in checkout. It needs a data clean-up once fixed.
- **Fix direction:**
  - Set the fallback to `TranslationLocaleProviderInterface::getDefaultLocaleCode()`: either fix `TranslatableEntityLocaleAssigner` or point the interface alias at `TranslatableEntityPimcoreLocaleAssigner` and delete the duplicate (I-19).
  - In `TranslatableTrait::getTranslation()`, do not attach a newly created translation to the collection on a *read*. Return a detached, empty translation, and only add it when a setter writes to it. Then a missing translation can never leak into a flush.
  - Ship a migration or command that deletes translation rows whose translatable fields are all `NULL`/empty.
  - Fix **B-01** together with this item.
