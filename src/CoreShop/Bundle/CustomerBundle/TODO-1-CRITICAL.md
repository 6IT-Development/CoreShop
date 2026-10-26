# CustomerBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: all PHP in this bundle, `Resources/config/**` (services, form, pimcore config/admin/routing, serializer, validation), `Resources/install/**` (class definitions, grid config), `Resources/public/**`, translations and `composer.json`, plus the code it depends on or that builds on it:
`Component/Customer/**` (models, contexts, repository interfaces), `Component/Core/Context/TokenBasedCustomerContext.php`, `Component/Core/Model/{Customer,Company,User}.php`, `CoreBundle/Pimcore/Repository/CustomerRepository.php`, `CoreBundle/Form/{Type/GuestRegistrationType,EventSubscriber/CustomerRegistrationFormSubscriber,Extension/CustomerTypeExtension}.php`, `CoreBundle/Resources/config/{pimcore/coreshop/coreshop_customer.yml,services/{customer,form}.yml,validation/Customer.yml}`, `CoreBundle/Resources/install/pimcore/classes/CoreShopCustomerBundle/*.json`, `FrontendBundle/Controller/{SecurityController,CustomerController,RegisterController}.php`, `UserBundle/Form/Type/ChangePasswordType.php`, `OrderBundle/Form/Type/{AdminCustomerCreationType,CartCreationType}.php`, `ResourceBundle/{Controller/ResourceController,DependencyInjection/Driver/Pimcore/PimcoreDriver,Resources/public/pimcore/js/global.js}`, `Component/Registry/{Autoconfiguration,PrioritizedCompositeServicePass}.php`, `config/packages/security.yaml`, and the Pimcore options-provider API (`../pimcore-source`).

"Critical" here means: a security issue, data loss, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Items marked **(dependent)** live outside this bundle, but they come from how this bundle's repository contract is used, and they should be fixed together with it.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard blockers found. `php -l` (PHP 8.4.27) passes on every file in the bundle and in `Component/Customer`. There are no implicit nullable parameters and no removed Symfony APIs. `Configuration` was processed with `symfony/config` 7.4.17 and builds without errors (it does have the logic problems listed in TODO-2 B-09 to B-11). The only deprecation hit at runtime is Pimcore's `MultiSelectOptionsProviderInterface` (deprecated since Pimcore 11.2, see I-06). Modernisation items are in TODO-3.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## C-01 — (dependent) Registering with someone else's guest e-mail takes over their guest customer, including all of their past orders and addresses

- **Where:**
  - `CoreBundle/Form/EventSubscriber/CustomerRegistrationFormSubscriber.php:61-66`: `findGuestByEmail($rawData['email']['first'])`. If a guest exists, it calls `$existingCustomer->setUser($data->getUser())` and swaps the form data to that existing customer.
  - `Pimcore/Repository/CustomerRepository.php:40-73`: `findGuestByEmail()` / `findUniqueByEmail()` match on the e-mail string only (`email = ? AND user__id IS NULL`). Nothing proves the caller owns that address.
  - `FrontendBundle/Controller/CustomerController.php:89`: the account page lists `findOrdersByCustomer($this->getCustomer())`, so after the takeover the new account sees every order the guest placed. The guest's stored addresses become the new account's addresses as well.
  - `CoreBundle/Resources/config/validation/Customer.yml`: `RegisteredUser` only blocks e-mails that already have a *user*. `UniqueCustomer` does not apply to guests. Registration has no e-mail verification step.
- **Problem:** an attacker who knows (or guesses) the e-mail address of someone who checked out as a guest can register a new account with that address and a password of their choice. The victim's guest customer object is attached to the attacker's user. The attacker can then read the victim's order history (names, addresses, phone numbers, items, totals), reuse the stored addresses, and receive any later "customer" e-mails. This is account takeover plus a personal-data leak. Under GDPR it would be a reportable breach.
- **Fix direction:**
  - Do not merge a guest into a new account based on the typed e-mail alone. Create a fresh customer, and offer "claim your guest orders" only after the address has been verified (a signed, expiring link sent to that e-mail address).
  - Or require e-mail confirmation before the account becomes active and before the merge runs.
  - Document the contract on `CustomerRepositoryInterface::findGuestByEmail()` ("lookup only: not proof of ownership") so that other callers do not repeat the pattern (see C-02).

## C-02 — (dependent) Guest checkout with another person's e-mail reuses and overwrites that person's existing guest customer

- **Where:**
  - `CoreBundle/Form/Type/GuestRegistrationType.php:90-114` (`PRE_SUBMIT` listener): `findGuestByEmail($data['email']['first'])`. If a guest customer exists and has no user, it runs `$form->setData($customer)`, and the submitted firstname, lastname, salutation and so on are mapped onto **that** object.
  - Same repository lookup as C-01 (`Pimcore/Repository/CustomerRepository.php:65-68`).
- **Problem:** anyone who enters someone else's e-mail address in the guest checkout:
  - overwrites the victim's stored name and other data (data integrity),
  - gets their own order attached to the victim's customer object, so it shows up in the victim's history if the victim later registers (or the attacker does, see C-01),
  - very likely sees the victim's earlier addresses in the address step. `CoreBundle/Checkout/Step/CustomerAddressCheckoutStep.php:105` passes the customer to `AddressChoiceType`, which lists `$customer->getAddresses()`. **Verify this one in the storefront before you rank it.**
- **Fix direction:** create a new guest customer for every guest checkout (guests are cheap), or reuse one only when the session already owns it (for example the customer id stored in the cart or session). Never let a typed e-mail address select an existing record. If de-duplication is wanted for reporting, do it offline or by linking records, not by handing the object to an anonymous form.
