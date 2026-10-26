# Address Component – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: all PHP in `src/CoreShop/Component/Address` and its `composer.json`. Bundle-side wiring lives in `src/CoreShop/Bundle/AddressBundle` (see that bundle's own TODO files: `C-`, `B-`, `I-` numbers).

"Critical" here means: silent data corruption, an uncaught error / HTTP 500 on a reachable path, or a security issue.
Paths are relative to the component root unless noted otherwise. Component items use the prefixes `CC-` (critical), `CB-` (bugs) and `CI-` (improvements).

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## CC-01 — `AddressFormatter` throws a fatal `Error` for an address without a country

- **Where:** `Formatter/AddressFormatter.php:41, 49`; exposed by `AddressBundle/Twig/FormatAddressExtension.php:35`
- **Problem:** `$address->getCountry()->getAddressFormat()` is called without a null check, but `AddressInterface::getCountry()` returns `?CountryInterface`. The Pimcore field `country` is not mandatory (`AddressBundle/Resources/install/pimcore/classes/CoreShopAddress.json`). `ResourceBundle/CoreExtension/Select::load()` returns `null` when the referenced country row was deleted. Addresses created via the admin, imports, the API or a deleted country therefore have no country.
  - Result: "Call to a member function getAddressFormat() on null". This is an `\Error`, so nothing catches it. A single broken address takes down:
    - `FrontendBundle/Resources/views/Customer/addresses.html.twig:32` (the customer's address book)
    - `Common/order_detail.html.twig:16` (order history)
    - the checkout address step (`CoreBundle/Form/Type/Checkout/AddressType.php:74, 87` formats **every** customer address while the form is built)
    - admin order detail and order creation (`OrderBundle/Controller/OrderController.php:377, 414, 423`, `OrderCreationController.php:194, 200`)
  - A `null` `addressFormat` causes a `TypeError` in `createTemplate(string)` in the same way. The Doctrine column is `NOT NULL`, but the setter is untyped.
- **Fix direction:** if there is no country, or the format is empty, fall back to a built-in default format (or return `''`). Type `getAddressFormat(): string`. Add a regression test.

## CC-02 — (Security) Admin-editable `Country.addressFormat` is executed as unsandboxed Twig

- **Where:** `Formatter/AddressFormatter.php:49`; editable via `AddressBundle/Form/Type/CountryType.php:42` (admin `country` permission, `AddressBundle/DependencyInjection/Configuration.php:98`)
- **Problem:** The stored format is compiled with `Environment::createTemplate()` using the application's full Twig environment. That environment includes every registered function, filter and tag (`include`, `source`, Pimcore/CoreShop helpers, and any `object.method()` call on the passed `country`/address objects). An admin user who **only** has the `country` permission can inject template code (SSTI). That code runs in the frontend, checkout, mail and admin contexts on every address render. This turns a narrow back-office permission into arbitrary data access in the template layer.
- **Fix direction:** render inside a dedicated `Twig\Environment` (or a `SandboxExtension` with `sandboxed: true`). Use a `SecurityPolicy` that allows only `{{ }}` output, `if`, and a few safe filters (`upper`, `lower`, `trim`, `default`), with no method calls. Alternatively, replace Twig with a simple `%placeholder%` substitution. Validate the format on save (a constraint in the bundle that compiles it under the same policy).
