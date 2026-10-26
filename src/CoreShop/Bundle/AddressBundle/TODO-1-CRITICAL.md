# AddressBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: all PHP in this bundle plus `Resources/config/**`, and the templates and controllers that consume them.
Component items (`CC-`, `CB-`, `CI-`) are tracked separately in `src/CoreShop/Component/Address/TODO-*.md` (for example CC-01 null-country crash, CC-02 unsandboxed address-format Twig).

"Critical" here means: silent data corruption, an uncaught error / HTTP 500 on a reachable path, or a security issue.
Paths are relative to the bundle root unless noted otherwise.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## C-01 — `AddressType::state` is required → a random state is saved on every storefront address

- **Where:** `Form/Type/AddressType.php:61-64`; rendered by `FrontendBundle/Resources/views/Customer/address.html.twig:58` (`form_end` renders the rest of the form)
- **Problem:** `state` is added as `StateChoiceType` without `'required' => false`. In Symfony, a non-multiple, non-expanded `ChoiceType` with `required: true` gets **no empty placeholder**, so the browser pre-selects the first option. The storefront template never renders `form.state` itself. `form_end()` still prints it at the bottom of the form, so it gets submitted.
  - Result: once any states exist (for example after `coreshop:setup:states`), every address created or edited in the storefront is saved with the first active state in alphabetical order, even for countries that have no states. An existing correct state is overwritten in the same way.
  - The Pimcore class (`Resources/install/pimcore/classes/CoreShopAddress.json`, field `state`) declares `"mandatory": false`, and `Resources/config/validation/Address.yml` has no `state` constraint. The field is clearly meant to be optional.
  - On a fresh install with **no** active states, the hidden `required` `<select>` has no options. HTML5 validation (`valueMissing`) then blocks the storefront address form completely. The user cannot see this, because the field is rendered at the bottom of the form. Verify this in a browser.
- **Fix direction:** set `required => false` with a placeholder. Limit the choices to the selected country (see B-02). Add a "state belongs to country" constraint. Check whether project themes render `form.state` explicitly.

## C-02 — `SalutationChoiceType` resolves the country eagerly in `configureOptions()`

- **Where:** `Form/Type/SalutationChoiceType.php:38`
- **Problem:** `->setDefault('country', $this->countryContext->getCountry())` runs **every time the options resolver is built**, before any option the caller passed is applied.
  1. When no country can be resolved (admin context, CLI, API, mail rendering, Messenger handlers, a store without a base country), `CountryNotFoundException` is thrown. This happens **even when the caller passes `country` explicitly**. The type is used by `AddressType`, `CoreBundle/Form/Type/CustomerRegistrationType.php:65` and `CoreBundle/Form/Extension/CustomerTypeExtension.php:32`.
  2. Symfony caches the `OptionsResolver` per `ResolvedFormType` for the lifetime of the container. In long-running runtimes (FrankenPHP worker mode, RoadRunner, Swoole, functional test kernels) the country from the **first request is frozen for all later requests and users**. It is also a detached Doctrine entity once the EntityManager has been cleared, so later requests get the wrong salutations.
- **Fix direction:** use a lazy default: `setDefault('country', fn (Options $o) => $this->countryContext->getCountry())`. Prefer `setAllowedTypes('country', CountryInterface::class)` over the `setAllowedValues` closure. Consider defaulting to the address's own country (see I-16).

## C-03 — `CountryCollector` throws an uncaught `TypeError` after `reset()`

- **Where:** `Collector/CountryCollector.php:46-49, 59-65, 68-71`
- **Problem:** `reset()` sets `$this->data = []`. On the next `collect()`, line 62 calls `isCountryChangeSupported(): bool`, which reads a missing key and returns `null`, so PHP throws a `TypeError`. `TypeError` is an `\Error`, so the surrounding `catch (\Exception)` does **not** catch it. It propagates out of `Profiler::collect()` and turns every later request into a 500.
  - `reset()` runs through `services_resetter` (`kernel.reset`). That happens in worker runtimes, in Messenger workers, and in functional tests that reuse the kernel between requests. This affects dev and test environments only, but it breaks them.
  - `getCountry()` also reads a missing key after `reset()` (warning).
- **Fix direction:** `reset()` must restore the constructor's initial structure. Keep `$countryChangeSupport` as a typed `bool` property instead of reading it back from `$data`. Remove the self-assignment on line 62. See also B-04.

## C-04 — (Cross-bundle, security) Open redirect via `_redirect`; `AddressType::_redirect` is a dead field

- **Where:** `Form/Type/AddressType.php:69-71` (unmapped hidden `_redirect`); consumers: `FrontendBundle/Controller/CustomerController.php:197-199`, `FrontendController::getParameterFromRequest()` (`FrontendBundle/Controller/FrontendController.php:60-68`)
- **Problem:** The controller reads `_redirect` from **request attributes or the query string** and passes it straight to `$this->redirect()` without checking it. `/customer/address?_redirect=https://evil.tld` is therefore an open redirect after a successful address save. The same pattern exists in `RegisterController`, `CartController` and `StorageListController`.
  - The `_redirect` field this bundle adds is nested (`coreshop[_redirect]` / `address[_redirect]`), so the controllers never read it. It is dead and misleading.
- **Fix direction (track in FrontendBundle):** allow only relative paths or same-host URLs (or route names). In this bundle, remove the field or document how it is meant to be used (see I-16).
