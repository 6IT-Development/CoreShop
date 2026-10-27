# Locale Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this component, but they come from how it is used, and they should be fixed together with it.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. Items are roughly ordered by impact. Items marked **(plausible)** follow from the code, but were not reproduced against a running shop.
Items already covered by the LocaleBundle TODOs are not repeated (see the overlap list in TODO-1).

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Locale resolution

### B-01 — `PimcoreLocaleContext` can never throw `LocaleNotFoundException`, so the composite's fall-through and every "locale not found" branch in CoreShop are dead code
- **Where:** `Context/PimcoreLocaleContext.php:33-42`. The context checks `null === $pimcoreLocale`, and the docblock says `@var string|null`. But:
  - `Pimcore\Localization\LocaleServiceInterface::findLocale(): string` (`LocaleServiceInterface.php:24`). It never returns `null`. When nothing is found it returns `''` (`LocaleService.php:45-57`).
  - `LocaleService::getLocaleFromRequest()` returns `$mainRequest->getLocale()`. `Symfony\Component\HttpFoundation\Request::getLocale(): string` is `$this->locale ?? $this->defaultLocale` (`Request.php:1492-1495`). `LocaleListener` sets the default to `framework.default_locale` (`'en'` unless configured). So **every HTTP request** has a locale, even on routes without `{_locale}`.
  - Without a main request (CLI, Messenger consumers), `findLocale()` returns `Tool::getDefaultLanguage()`.
- **Problem:**
  - The `throw` at `:41` is unreachable. With the default wiring (`Bundle/LocaleBundle/Resources/config/services.yml`, admin 300 → fixed 200 → Pimcore 100), `CompositeLocaleContext::getLocaleCode()` therefore always returns a value. Any context registered with a priority below 100 is never called. LocaleBundle **B-03** says the context "only throws for a missing locale". In fact it never throws.
  - All `catch (LocaleNotFoundException)` blocks and checks are dead in practice: `Component/Core/Context/ShopperContext.php:87-96` (`hasLocaleCode()` is always `true`), `StoreBasedCartContext.php:64-66`, `Component/Resource/Translation/TranslatableEntityLocaleAssigner.php:40`, `Component/Core/Translation/TranslatableEntityPimcoreLocaleAssigner.php:41`. Nobody tests them, and they will be wrong the day a context really throws.
  - Corrections to the LocaleBundle TODOs:
    - LocaleBundle **B-02** (`TypeError` from `return Tool::getDefaultLanguage()`) cannot happen. `getDefaultLanguage()` returns `null` only when no language is configured (`Tool.php:158-172`). In that case `isValidLanguage()` returns `true` for every input (`Tool.php:73-76`), so the `return Tool::getDefaultLanguage()` line is never reached. The real problem in that setup is **B-02** below.
    - LocaleBundle **B-09** (registration 500 on `LocaleNotFoundException`) cannot be triggered with the built-in contexts. It needs a custom context with priority > 100 that throws something else, or the fix for this item.
- **Fix:**
  - Decide what "found" means and implement it explicitly. Recommended: only an explicit request locale counts (`$request->attributes->get('_locale')`, or `Request::$locale` being set), not Symfony's default. Throw `LocaleNotFoundException` otherwise, and for `''`.
  - Move the defaulting into a separate low-priority context (see LocaleBundle **B-03** and TODO-3 **I-01**).
  - Before or with this change, guard the callers that will then start to see exceptions (**B-05**, LocaleBundle **B-09**). Fix the docblock (`@var string`).

### B-02 — With no Pimcore language configured, `PimcoreLocaleContext` returns `''` (CLI) or any request locale, unvalidated
- **Where:** `Context/PimcoreLocaleContext.php:38-48`, `Tool.php:67-80` (`isValidLanguage()`: "if not configured, every language is valid").
- **Problem:**
  - CLI / Messenger: `findLocale()` returns `''`. `isValidLanguage('')` is `true`, so the context returns the empty string. That is a "valid" result for the composite.
  - HTTP: the context returns the raw request locale. For the built-in static routes this is whatever matches `(\w+)` in `FrontendBundle/Resources/install/pimcore/staticroutes.yml` (e.g. `/foo/shop` → `foo`). Otherwise it is the Symfony default.
  - These values then flow into persisted fields and SQL: `StoreBasedCartContext.php:59` (`setLocaleCode('')`), customer locale (`CustomerCheckoutStep.php:67`, `RegisterController.php:61`), translation lookups (`TranslatableEntityLocaleAssigner`), and the `object_localized_<classId>_<locale>` table names in the reports (LocaleBundle **B-08**).
  - This only happens on a misconfigured or fresh install, but nothing reports it. The shop "works" with empty or made-up locale codes.
- **Fix:** do not rely on `isValidLanguage()` when the list is empty. Read `Tool::getValidLanguages()` once. If it is empty, or if the resolved code is `''`, throw `LocaleNotFoundException` with a clear message ("No Pimcore languages configured"). Add a test for both paths (TODO-3 **I-08**).

### B-03 — (dependent) Requests without `{_locale}` resolve to Symfony's `default_locale`, and consumers store it on the cart, the order and the customer
- **Where:** cause in **B-01** (`Request::getLocale()` fallback). Writers: `Component/Core/Context/StoreBasedCartContext.php:59` (overwrites the cart locale on **every** `getCart()`), `OrderBundle/EventListener/CheckoutCartLocaleAssignerListener.php:42` (`coreshop.checkout.do.pre`), `CoreBundle/Checkout/Step/CustomerCheckoutStep.php:67`, `FrontendBundle/Controller/RegisterController.php:61`. Readers: `Component/Core/Notification/Rule/Action/Order/OrderMailActionProcessor.php:52` (mail template choice), `CoreBundle/EventListener/NotificationRules/*Listener.php` (`_locale` parameter), `PayumBundle/Action/{,Offline/}ResolveNextRouteAction.php:41,50` (return route).
- **Problem:** the built-in storefront routes all have `/%_locale/...`, so the default shop is fine. But a project route without `{_locale}` that touches the cart (custom AJAX/API controllers, a header mini-cart fragment, a webhook that loads the session cart) resolves the locale to `framework.default_locale`. If that is a valid Pimcore language (e.g. `en`), it wins silently. `StoreBasedCartContext` then rewrites the cart's locale. The order is later placed with that locale, so the confirmation mail and the Payum return/thank-you URL use the wrong language. **(plausible)**
- **Fix:** with **B-01** fixed, a non-localized request no longer yields a locale, so consumers can tell "no locale" apart from "default locale". In `StoreBasedCartContext`, only set the locale when it is empty, or when the request has an explicit `_locale` (i.e. `ShopperContext::hasLocaleCode()` that actually works).

### B-04 — `PimcoreLocaleContext` reads the **main** request, so sub-requests in another language get the main request's locale
- **Where:** `Context/PimcoreLocaleContext.php:38` → `LocaleService::getLocaleFromRequest()` (`getMainRequest()`).
- **Problem:** fragments rendered with `render(controller(...))`, ESI, Pimcore snippets/renderlets, or `pimcore_inc` of a document in another language run as sub-requests with their own `_locale`. Translatable CoreShop entities (country, carrier names) and prices formatted in them use the main request's locale instead. **(plausible)**
- **Fix:** read the locale from `RequestStack::getCurrentRequest()` in the context, instead of going through `LocaleServiceInterface::findLocale()` (see TODO-3 **I-01**).

## Contract

### B-05 — (dependent) `LocaleAwareInterface::getLocaleCode()` is nullable, but consumers treat a missing order locale as a fatal error or pass `null` as a route parameter
- **Where:** `Model/LocaleAwareInterface.php:23` (`?string`, no documented fallback). The order locale is only set in the storefront flow (`StoreBasedCartContext.php:59`, `CheckoutCartLocaleAssignerListener.php:42`) and in the admin `CartCreationType` (`OrderBundle/Form/Type/CartCreationType.php:41`). Orders created by imports, API code or `OrderFactory` directly keep `null`. Consumers:
  - `Component/Core/Notification/Rule/Action/Order/OrderMailActionProcessor.php:52-56` throws a generic `\Exception('OrderMailActionProcessor: Language is not set.')`, so the order-confirmation/notification rule fails.
  - `PayumBundle/Action/ResolveNextRouteAction.php:41,50` and `Offline/ResolveNextRouteAction.php:41` set `'_locale' => null`. Generating the localized `coreshop_checkout_thank_you` / `coreshop_index` routes then fails, or builds a URL without the language prefix. **(plausible)**
  - `CoreBundle/EventListener/NotificationRules/{Payment,Order}WorkflowListener.php`, `OrderCommentsListener.php` pass `'_locale' => null` into the rule parameters.
- **Fix:** document the contract on `LocaleAwareInterface`: `null` means "unknown", and callers fall back to `TranslationLocaleProviderInterface::getDefaultLocaleCode()`. Apply that fallback in the consumers listed, or set a default locale when an order is created outside the storefront.
