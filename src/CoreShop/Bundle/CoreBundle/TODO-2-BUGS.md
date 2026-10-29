# CoreBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are functional bugs. They are wrong behaviour but not critical by the definition there: they break a feature, an admin action, a report or a non-default configuration, not the shop as a whole.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(reproduced)** were confirmed with a PHP 8.4.27 script against the vendored classes (the scripts lived in a session scratchpad and are not part of the repo).

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Security / login / logout

### B-01 — `ShopUserLogoutHandler` returns a `Response` from an event listener; the return value is ignored, so `after_logout_redirect_route` never takes effect
- **Status:** fixed — the listener now calls `$event->setResponse()` and is registered with priority 65, before `DefaultLogoutListener` (64), which skips when a response is already set. The handler no longer reads the store, so there is no `StoreNotFoundException` path. The cart session cleanup stays here: `SessionStoreStorageListLogoutSubscriber` only covers lists with `session.enable_logout_subscriber`, which the cart does not enable by default. The unused `$storeContext` argument is kept for BC.
- **Where:** `EventListener/ShopUserLogoutHandler.php:43-53`, `Resources/config/services/handler.yml:5-14`; `DependencyInjection/Configuration.php:41`. Vendor: `symfony/security-http/EventListener/DefaultLogoutListener.php` (priority 64 sets the response), `Firewall/LogoutListener.php:80-82`.
- **Problem:** the class was converted from a `LogoutSuccessHandlerInterface` (removed in Symfony 6) into a `LogoutEvent` listener, but it still `return`s the redirect. `DefaultLogoutListener` (priority 64) has already set a redirect to the firewall's `logout.target`, so `core_shop_core.after_logout_redirect_route` (default `coreshop_index`) and the `_locale` parameter are ignored. If `getStore()` throws `StoreNotFoundException`, logout fails with a 500.
- **Fix:** `$event->setResponse(new RedirectResponse(...))`, return `void`, and register it with a priority above 64 (or with `#[AsEventListener(event: LogoutEvent::class, dispatcher: …, priority: 65)]`). Catch `StoreNotFoundException`. Remove the `TOOD` comment and the import of the non-existent `LogoutSuccessHandlerInterface` (`:27`, `:30`, `:33-34`). The session-key removal duplicates `SessionStoreStorageListLogoutSubscriber`; keep one (see TODO-1 **C-06**).

### B-02 — Firewall name `coreshop_frontend` hard-coded in DI and code: another firewall name breaks container compilation
- **Status:** fixed — new option `core_shop_core.security.firewall_name` (default `coreshop_frontend`, parameter `coreshop.security.firewall_name`). The logout listener tag is added by `DependencyInjection/Compiler/RegisterLogoutListenerPass` only when `security.event_dispatcher.<name>` exists; `CustomerLoginService` gets the same name. The StorageListBundle logout subscriber (`CoreShopStorageListExtension.php:299`) is still hard-coded; it only applies when a list sets `session.enable_logout_subscriber` and belongs to that bundle's TODO. The pass was checked with a standalone `ContainerBuilder`, not with a full kernel.
- **Where:** `Resources/config/services/handler.yml:14` (`dispatcher: security.event_dispatcher.coreshop_frontend`), `Customer/CustomerLoginService.php:34`; same pattern in `StorageListBundle/DependencyInjection/CoreShopStorageListExtension.php:298`. Vendor: `symfony/event-dispatcher/DependencyInjection/RegisterListenersPass.php:107-108,147` (`findDefinition()` throws).
- **Problem:** with a firewall named anything else, or none (headless/API projects), `findDefinition('security.event_dispatcher.coreshop_frontend')` throws `ServiceNotFoundException` and the whole application fails to boot. With a renamed firewall that does exist, `CustomerLoginService` writes a token for a firewall that does not exist, and the user is not logged in.
- **Fix:** add a `core_shop_core.security.firewall_name` option (default `coreshop_frontend`). Register the logout listener in the extension only when `security.event_dispatcher.<name>` exists (or in a compiler pass that checks `hasDefinition`). Use the same option in `CustomerLoginService` / `Security::login()`.

### B-03 — `AccessVoter` grants every `CORESHOP_*` attribute; with the default affirmative strategy a project voter cannot deny it
- **Status:** fixed — new option `core_shop_core.security.access_voter` (default `true`); with `false` the voter service is removed and a project voter decides the `CORESHOP_*` attributes. The class docblock documents both ways out (disable it, or replace the service with a subclass that overrides `voteOnAttribute()`). The default behaviour (grant all) is unchanged. `supportsAttribute()`/`supportsType()` are implemented, so the voter is skipped for other attributes (part of TODO-3 **I-21**; `$prefix` is not made `readonly` for BC with subclasses).
- **Where:** `Security/AccessVoter.php:34-42`, `Resources/config/services.yml:71-73`; used by `FrontendBundle` controllers and templates (`CORESHOP_CHECKOUT`, `CORESHOP_ORDER_CREATE`, `CORESHOP_CUSTOMER_PROFILE_*`, …). Vendor: `security-core/Authorization/AccessDecisionManager.php:49` (AffirmativeStrategy).
- **Problem:** the voter is the documented extension point ("override the permission"), but it always returns `ACCESS_GRANTED`. Under the default affirmative strategy one GRANT wins, so a project voter that returns DENIED for, say, `CORESHOP_CHECKOUT` (B2B approval, blocked customers) has no effect. The only way out is to decorate or remove this service, or to change the strategy for the whole application. Ownership checks are done in the controllers (verified for order and quote detail), so this is not an IDOR.
- **Fix:** document this and make the voter replaceable (config flag), or have it abstain unless no other voter supports the attribute. Alternatively use `strategy: priority` and give this voter the lowest priority. Implement `CacheableVoterInterface::supportsAttribute()` so the voter is skipped for other attributes (TODO-3 **I-21**).

### B-04 — `ObjectUserProvider::supportsClass()` compares class names strictly, and `refreshUser()` keeps unpublished users logged in
- **Status:** fixed — `supportsClass(string $class)` uses `is_a(..., true)`. `refreshUser()` throws `UserNotFoundException` for an unpublished user, which ends the session. An unpublished *customer* is not checked, because the login lookup does not check it either; that would log the user in and out again on the next request.
- **Where:** `Security/ObjectUserProvider.php:51-69`.
- **Problem:**
  - `$class === $this->className` fails for subclasses: Pimcore class overrides (`pimcore.models.class_overrides`) or a project `User` that extends the generated class. Symfony then finds no provider for the user in the session, and the user is logged out on every request.
  - `refreshUser()` uses `find()` (Pimcore `getById`, which also returns unpublished objects), so unpublishing a user or customer in the admin does not end active sessions **(plausible)**.
- **Fix:** `is_a($class, $this->className, true)`. In `refreshUser()`, throw `UserNotFoundException` for an unpublished user (or check it in a `UserChecker::checkPostAuth`). Type `supportsClass(string $class)`.

## Customer / registration

### B-05 — `RegisteredUser` never runs: CoreBundle overrides the guest validation group, so guest checkout accepts the e-mail of a registered account
- **Status:** fixed — CoreBundle no longer redefines CustomerBundle's `coreshop.form.type.customer_guest.validation_groups`. `GuestRegistrationType` uses its own parameter `coreshop.form.type.guest_registration_type.validation_groups`, now `[coreshop, coreshop_customer_guest]` (it was unused and set to `[coreshop_customer]`). The `RegisteredUser` message now points to the existing translation `coreshop.form.guest.email_is_customer`; `coreshop.form.customer.email.registered` was not translated anywhere. Projects that customized the guest form through `customer_guest.validation_groups` must move that to the new parameter. `RegisteredUserValidator` uses `findCustomerByEmail()`, which returns one customer; if a guest with the same e-mail is returned first, the check still misses (not changed here).
- **Where:** `Resources/config/services/form.yml:3` sets `coreshop.form.type.customer_guest.validation_groups: [coreshop]`. This overrides CustomerBundle's `[coreshop_customer_guest]` (`CustomerBundle/Resources/config/services/form.yml:3`). The compiled container confirms the value `[coreshop]`, and `GuestRegistrationType` receives it (`form.yml:44-52`). `Resources/config/validation/Customer.yml:8-10` puts `RegisteredUser` in group `coreshop_customer_guest`, which no form or validator call uses (checked with grep across `src/`).
- **Problem:** the "this e-mail is already registered, please log in" check (`Validator/Constraints/RegisteredUserValidator.php`) is dead. A guest checkout with a registered customer's e-mail goes through. Because `findGuestByEmail()` filters `user__id IS NULL`, `GuestRegistrationType` then creates a second customer with the same e-mail. That leads to duplicate customers and orders split across two records. Cross-reference: CustomerBundle `TODO-2-BUGS.md` B-16 ("`coreshop_customer_guest` group is effectively dead"). This item names the CoreBundle override that causes it.
- **Fix:** give `GuestRegistrationType` `[coreshop, coreshop_customer_guest]`, either as a dedicated parameter (`guest_registration_type.validation_groups` at `form.yml:5` already exists and is unused) or by moving `RegisteredUser` to `coreshop`, scoped to guests. Delete the unused parameter, and do not redefine a parameter that another bundle owns.

### B-06 — `CustomerRegistrationFormSubscriber` is a no-op: `setData()` in `PRE_SUBMIT` is ignored because the root form data is locked (reproduced)
- **Status:** fixed — `CustomerRegistrationFormSubscriber` is deleted and no longer registered by `CustomerRegistrationType`; the form data stays locked. Registering with a guest's e-mail keeps creating a new customer, which is what happened in practice already. Linking the guest history needs an e-mail ownership proof (CustomerBundle C-01) and is not implemented. The `$customerRepository` constructor argument of `CustomerRegistrationType` is kept for BC but no longer used.
- **Where:** `Form/EventSubscriber/CustomerRegistrationFormSubscriber.php:42-68`, wired in `Form/Type/CustomerRegistrationType.php:56`. The form is created in `FrontendBundle/Controller/RegisterController.php:52` with `createNamed(..., $factory->createNew())`, which sets the `data` option, so `FormType` calls `setDataLocked(true)`. `Form::setData()` returns early when the data is locked (`symfony/form/Form.php`, "Don't allow modifications of the configured data if the data is locked").
- **Problem:** the intended "upgrade an existing guest to a registered account" never happens. Registering with a guest's e-mail creates a **second** customer with the same e-mail, and the guest's orders stay on the old record. Reproduced with a standalone form (`scratchpad/t2.php`): with the lock the data stays `new`; with `setDataLocked(false)` it becomes `existingGuest`.
  **Important:** CustomerBundle `TODO-1-CRITICAL.md` C-01 (account takeover through this subscriber) is therefore **not exploitable as currently wired**. It becomes exploitable as soon as someone "fixes" this bug by adding `setDataLocked(false)`, as `GuestRegistrationType` does.
- **Fix:** delete the subscriber. Merging guest history needs e-mail ownership proof (a signed link), as CustomerBundle C-01 describes. Until then, either report "an order history exists for this address, verify to link it" or simply create the new customer. Do not unlock the data.

### B-07 — Guest checkout does not enforce "terms accepted" on the server
- **Status:** fixed — `termsAccepted` in `GuestRegistrationType` now has `new IsTrue(groups: $this->validationGroups)` and uses the form's validation groups instead of the hard-coded `['coreshop']`, as `CustomerRegistrationType` does.
- **Where:** `Form/Type/GuestRegistrationType.php:84-88`. `termsAccepted` has `mapped => false` and `validation_groups => ['coreshop']`, but no constraint. Compare `Form/Type/CustomerRegistrationType.php:101-106` (`new IsTrue(...)`).
- **Problem:** only the browser's HTML `required` attribute protects it. Any client that drops the checkbox (curl, a disabled-JS theme, a custom template) checks out without accepting the terms. That is a legal-compliance gap.
- **Fix:** add `'constraints' => [new IsTrue(groups: $this->validationGroups)]` and use `$this->validationGroups` instead of the hard-coded `['coreshop']`.

### B-08 — (dependent, plausible) `UniqueCustomerValidator` does not exclude the customer's own user, and depends on whether a lazy relation happens to be loaded
- **Status:** fixed — the validator reads the user through `getUser()` (no longer the raw lazy property) and skips the violation when the found user is the customer's own (same id). A new user (no id) is still checked against every existing login identifier. Not run against a shop.
- **Where:** `Validator/Constraints/UniqueCustomerValidator.php:48-67`; group `coreshop` (`Resources/config/validation/Customer.yml:3-6`). `CustomerBundle` `CustomerType` also validates `Customer` with `[coreshop]` (profile settings, `FrontendBundle/Controller/CustomerController.php:251-262`).
- **Problem:** `findByLoginIdentifier($user->getLoginIdentifier())` returns the customer's **own** user when an existing, registered customer is validated. That raises a false "e-mail already used" violation. Today this is masked only because `getObjectVar('user')` reads the raw property of a lazy `coreShopRelation` (Pimcore relations are lazy-loaded), so it is usually `null` and the check is skipped. The result is non-deterministic: whenever `getUser()` was called earlier in the request, profile saves fail.
- **Fix:** skip when `$otherUser->getId() === $user->getId()`, or compare the customer ids. Use `$value->getUser()`, or explicitly limit the constraint to new users (`$user->getId() === null`).

### B-09 — (reproduced) Malformed e-mail input causes a 500 in registration and guest checkout
- **Where:** `Form/Type/GuestRegistrationType.php:95,102,113` and `Form/EventSubscriber/CustomerRegistrationFormSubscriber.php:54,61`, both reading `$data['email']['first']`.
- **Problem:** posting `guest[email]=foo` (a string instead of the repeated-type array) makes `'foo'['first']` throw `TypeError: Cannot access offset of type string on string` (reproduced, `scratchpad/t1.php`). If `first` is missing, there is an undefined-key warning and `findGuestByEmail(null)` throws a `TypeError`. Related: FrontendBundle `TODO-2-BUGS.md` B-16 (array/scalar request params crash actions).
- **Fix:** `$email = $data['email']['first'] ?? null; if (!\is_string($email) || $email === '') return;`.

### B-10 — `CustomerManager::persistCustomer()` is not atomic: a duplicate login identifier leaves an orphaned customer and addresses
- **Where:** `Customer/CustomerManager.php:41-113`; class `Resources/install/pimcore/classes/CoreShopUserBundle/CoreShopUser.json` (`loginIdentifier` `unique: true`).
- **Problem:** the customer (`:73`) and its addresses (`:83`) are saved before the user (`:101`). Two concurrent registrations with the same e-mail both pass form validation. The second user save fails on the unique index, leaving a published customer in the `customer/…` folder with no user and with addresses. The visitor gets a 500. The same happens for any other exception during the user save.
- **Fix:** wrap the whole method in `Db::get()->transactional()` (Pimcore objects use the default DBAL connection) or do compensating deletes. Map the unique-constraint violation to `CustomerAlreadyExistsException` (the class exists in `Customer/CustomerAlreadyExistsException.php` but is never thrown).

### B-11 — `CustomerNewsletterConfirmListener` sends a new confirmation mail and invalidates the old token on every profile save
- **Where:** `EventListener/CustomerNewsletterConfirmListener.php:39-69` (`coreshop.customer.update_post`, `Resources/config/services/customer.yml:28`), `EventListener/NotificationRules/CustomerListener.php:79-115`; dispatched by `FrontendBundle/Controller/CustomerController.php:264`.
- **Problem:** while `newsletterActive && !newsletterConfirmed`, every save of the profile settings (name change, etc.) regenerates `newsletterToken` and sends another double-opt-in mail. Links in earlier mails stop working.
- **Fix:** send only when `newsletterActive` changed from `false` to `true` (compare with the stored value, or carry a flag in the event). Keep an existing unconfirmed token.

## Checkout

### B-12 — (dependent) `OptionalCheckoutStepInterface::isRequired()` is never called: zero-total carts still need a valid payment provider
- **Where:** `Checkout/Step/PaymentCheckoutStep.php:48-63`, `Checkout/Step/ShippingCheckoutStep.php:50-55`; no caller anywhere (`grep -rn "->isRequired("` → none); `Component/Order/Checkout/CheckoutManager.php:37-86`; `FrontendBundle/Controller/CheckoutController.php:47-58,161-170`.
- **Problem:** `PaymentCheckoutStep::isRequired()` returns `total > 0`, but neither `CheckoutManager` nor `CheckoutController` reads it. `doCheckoutAction` validates every `ValidationCheckoutStepInterface` step, and `PaymentCheckoutStep::validate()` requires a provider that passes `PaymentProviderValidator`. A cart that costs 0 (100 % voucher, free sample) can only be ordered if at least one provider's rules accept a 0 total. With an `amount` rule such as "min 1.00" on every provider, the checkout loops back to the payment step forever. The docs (`docs/03_Development/05_Checkout/02_Checkout_Step.md:25`) describe the interface as working.
- **Fix:** in `CheckoutManager` / `CheckoutController`, skip steps where `$step instanceof OptionalCheckoutStepInterface && !$step->isRequired($cart)`, both in the "previous steps valid" loop and in `doCheckoutAction`. Or let `validate()` return `true` when `!isRequired()`.

### B-13 — `SummaryCheckoutStep` creates a quote by default, and does not enforce "accept terms" for `checkout-do`
- **Where:** `Checkout/Step/SummaryCheckoutStep.php:51-67`; `Form/Type/Checkout/SummaryType.php:29-42`.
- **Problem:**
  - `getResponse()` sends the user to `coreshop_checkout_do` only when `submitOrder->isClicked()`. Otherwise it goes to `coreshop_cart_create_quote`. A submit button is "clicked" only if its name is in the POST data. A very common front-end pattern removes it: disabling the button on click to prevent double submits, or calling `form.submit()` from JS. The customer clicks "Buy" and gets a quote **(plausible)**. Pressing Enter also submits the first button in DOM order, which is `submitQuote`.
  - `validate()` always returns `true`, and the `acceptTerms` checkbox is unmapped. `doCheckoutAction` therefore accepts carts whose terms were never accepted (for example a direct GET to `/checkout-do`; see `FrontendBundle/TODO-2-BUGS.md` **B-03** for the missing method/CSRF restriction).
- **Fix:** default to `coreshop_checkout_do` and go to the quote route only when `submitQuote` is clicked (`match (true) { … }`). Persist a "terms accepted" marker (cart attribute or session flag, reset when the cart changes) and check it in `validate()`.

### B-14 — (plausible) `GuestAddressCheckoutStep`: a non-shippable cart still requires a shipping address, and each submit moves and re-keys the address objects
- **Where:** `Checkout/Step/GuestAddressCheckoutStep.php:60-96`, `Form/Type/Checkout/GuestAddressType.php:31-53`; compare `CustomerAddressCheckoutStep.php:110-113`, which removes `shippingAddress` / `useInvoiceAsShipping` for non-shippable carts.
- **Problem:**
  - The guest form always contains `shippingAddress`. When "use invoice as shipping" is unchecked on a digital-only cart, `foreach ([$invoice, $shipping])` calls `setParent()` on `null` and the step fails with an `Error` if the shipping sub-form was submitted empty.
  - Every resubmit calls `setKey(uniqid())` and `setParent(...)` on the existing address objects, which creates new versions and churns paths. Same pattern as `FrontendBundle/TODO-2-BUGS.md` **B-14**.
  - `Service::createFolderByPath($cart . '/addresses')` relies on `Concrete::__toString()` returning the full path.
- **Fix:** mirror the customer step: remove the shipping fields when `!$cart->hasShippableItems()`, null-check before saving, keep the key once one is set, and use `$cart->getFullPath()` explicitly.

### B-15 — `CartBlamerListener` copies the default address into both invoice and shipping, ignoring the address identifier; the cart is processed twice when it has no id
- **Where:** `EventListener/CartBlamerListener.php:63-90`; compare `Form/Type/Checkout/AddressType.php:51-79` (`allowed_address_identifier: [null,'shipping']` / `[null,'invoice']`).
- **Problem:** a default address of type `shipping` becomes the cart's invoice address (and the reverse). The address form would never allow this. Because both addresses are now set, `AddressCheckoutStep::validate()` passes and the customer can skip the address step. When the cart has no id, `process()` runs twice (`:81` and `:89`) and nothing is persisted.
- **Fix:** take the identifier into account (or use `AddressType`'s own defaults logic). Remove the second `process()` call.

### B-16 — (reproduced) Checkout `AddressType`: `empty_data` is an address object, so the "default address" fallback always fails with "The selected choice is invalid"
- **Where:** `Form/Type/Checkout/AddressType.php:78` and `:91` (`'empty_data' => $defaultShippingAddress / $defaultInvoiceAddress`).
- **Problem:** for a non-expanded `ChoiceType`, `empty_data` is **view** data (a string), and it goes through `ChoiceToValueTransformer::reverseTransform()`. An object fails the transformation. When the select is submitted empty, the field becomes unsynchronised with "The selected choice is invalid" instead of falling back to the customer's default address. Reproduced in `scratchpad/t3.php`: with the object, `synced=false` and data `NULL`; with the string id `'5'`, the default address is used.
- **Fix:** `'empty_data' => $defaultShippingAddress ? (string) $defaultShippingAddress->getId() : ''` (same for invoice). Alternatively, pre-select the default with `data` in `PRE_SET_DATA` when the cart has no address.

### B-17 — (plausible) `CarrierChoiceType` without `cart` reads an undefined option; with a cart that has no shipping address it calls the resolver with `null`
- **Where:** `Form/Type/Checkout/CarrierChoiceType.php:53-60` (choices closure: `resolveCarriers($cart, $cart->getShippingAddress())`) and `:79-99` (`buildView()`: `$cart = $options['cart']` while `cart` is only `setDefined` at `:65-69`). Used without `cart` by `Form/Extension/CartCreationTypeExtension.php:38` and `Form/Extension/OrderShipmentCreationTypeExtension.php:30`.
- **Problem:**
  1. When `cart` is not passed, the resolved options do not contain the key (reproduced: `OptionsResolver::setDefined()` without a default leaves it out). If one of these forms is ever rendered with `createView()`, the result is an "Undefined array key" warning, and then a `TypeError` in `resolveCartContext(null)` for the first carrier. The admin controllers currently only submit and never render, so this is latent.
  2. `CarriersResolverInterface::resolveCarriers(ShippableInterface, AddressInterface $address)` does not accept `null`, so a cart without a shipping address throws a `TypeError` while options resolve.
- **Fix:** `setDefault('cart', null)->setAllowedTypes('cart', ['null', OrderInterface::class])`. In `buildView()`, return early (no prices) when there is no cart. Fall back to `findAll()` (or an empty list) when `getShippingAddress()` is `null`. Also hoist `resolveCartContext()` out of the loop (see TODO-3 **I-19**).

### B-18 — (dependent) `AddressChoiceType` throws a `TypeError` (500) when the `customer` id does not resolve
- **Where:** `Form/Type/AddressChoiceType.php:44-51`: `$this->customerRepository->find($options['customer'])` followed by `allocateForCustomer($customer)` with no null check. Trigger: `OrderBundle/Controller/OrderCreationController.php:85-87,125-127` passes the raw `customer` request parameter into `CartCreationType`, and `Form/Extension/CartCreationTypeExtension.php:31-37` forwards it.
- **Problem:** an admin cart/sale-creation request without a valid `customer` parameter, or with a deleted customer id, fails with `TypeError: allocateForCustomer(): Argument #1 must be of type CustomerInterface, null given` while options are resolved. That is a 500 instead of a form error.
- **Fix:** return `[]` when the customer cannot be resolved (`if (!$customer instanceof CustomerInterface) return [];`). Optionally also accept a `CustomerInterface` in the option, so callers that already hold the object (checkout `AddressType.php:69,82`, profile) do not re-query it.

### B-19 — (plausible) `GuestAddressType` validates the shipping address even when "use invoice as shipping" is checked
- **Where:** `Form/Type/Checkout/GuestAddressType.php:31-57`. Compare the logged-in variant `Form/Type/Checkout/AddressType.php:125-152`, which copies the invoice into the shipping field in `PRE_SUBMIT`. Consumer: `Checkout/Step/GuestAddressCheckoutStep.php:72-86`, which only switches after `isValid()`.
- **Problem:** `shippingAddress` is a full `AddressType` with its own validation. If the theme hides or disables the shipping block when the checkbox is ticked, the shipping fields arrive empty and the form is invalid. The flag is only evaluated after a successful validation.
- **Fix:** in `PRE_SUBMIT`, when `useInvoiceAsShipping` is set, either copy `invoiceAddress` into `shippingAddress` or remove the `shippingAddress` child. Also declare `buildForm(): void` (see TODO-3 **I-02**).

## Cart / product listeners and cart validators

### B-20 — `RequestCartAvailability` answers any request with a 302 to the same URI: POST bodies are dropped and XHR/JSON clients get a redirect
- **Where:** `EventListener/RequestCartAvailability.php:38-82`, `Resources/config/services/listeners.yml:60-66`.
- **Problem:** when the cart needs recalculation, the listener adds a flash and redirects to `getRequestUri()`, whatever the method. A POST (add-to-cart, checkout step, payment form) becomes a GET after the 302, so the user's submission is silently lost. AJAX add-to-cart requests receive a redirect to the same JSON endpoint as GET, which then 405s or returns HTML **(plausible)**. The skip list excludes `_wdt` but not `_profiler` or `_fragment`. `getRequest()->getSession()` throws on stateless routes.
- **Fix:** handle only safe (`GET`/`HEAD`), non-XHR main requests (`$request->isMethodSafe() && !$request->isXmlHttpRequest()`). Use `hasSession()`. Skip all `_`-prefixed debug routes. Merge with `RequestCartRecalculation` (TODO-3 **I-24**).

### B-21 — (dependent, plausible) Every save of a purchasable, including the stock updates made for every order, marks all carts for recalculation and flushes the default entity manager inside a Pimcore event
- **Where:** `EventListener/ProductUpdateEventListener.php:32-41`, `EventListener/RequestCartRecalculation.php:55-66`, `Resources/config/services/listeners.yml:36-40`; `Component/Configuration/Service/ConfigurationService.php` (`set()` → `$entityManager->flush()`); `Component/Core/Inventory/Operator/OrderInventoryOperator.php` (persist + flush on `coreshop.manager.product` → `Concrete::save()`) via `Resources/config/pimcore/workflow.yml:6-19`.
- **Problem:**
  - `pimcore.dataobject.postUpdate` for any `PurchasableInterface` writes `SYSTEM.PRICE_RULE.UPDATE = time()`. `OrderInventoryOperator` saves every tracked product on order `create`, `pay` and `cancel`. So **every order** bumps the timestamp, and on its next request every active cart in the shop is re-processed and saved (`RequestCartRecalculation`). The same happens for every row of a product import, plus one config write per row.
  - `ConfigurationService::set()` flushes the whole default Doctrine EM from inside a Pimcore save event, which also flushes unrelated pending changes.
- **Fix:** bump the marker only when a price-relevant field changed (check `isFieldDirty()` on price/store values). Or record the change per product and recalculate only carts that contain it. Use a dedicated DBAL update or cache key instead of `ConfigurationService::set()`. At minimum, skip saves coming from `OrderInventoryOperator`.

### B-22 — `ProductAvailabilityEventListener` removes cart items on publish as well as unpublish, and its state can go stale
- **Where:** `EventListener/ProductAvailabilityEventListener.php:41-90,109-133`.
- **Problem:**
  - The trigger is "published flag changed" in either direction. Publishing a product removes it from every cart that contains it.
  - The service is shared and stateful. If `save()` throws after `preUpdate` (validation exception from another listener), the id stays in `$productIdsToCheck`. In a long-running process (Messenger worker, CLI import) the next save of that product skips the published check (`:57`) and its `postUpdate` empties the product from all carts even though nothing changed **(plausible)**.
  - `in_array(..., true)` on an id-keyed array is O(n) and only works because key equals value.
- **Fix:** act only on `published: true → false` (or for unavailability in general). Use `isset($this->productIdsToCheck[$id])`. Implement `ResetInterface`, and clear the entry in a `finally` / on the failure event.

### B-23 — `RecursiveVariantCacheInvalidationEventListener` clears the payment-rule tag for shipping rules
- **Where:** `EventListener/RecursiveVariantCacheInvalidationEventListener.php:67-71`; tag constant `Component/Core/Shipping/Rule/Condition/ProductsConditionChecker.php:33`.
- **Problem:** for `ShippingRuleInterface` it calls `Cache::clearTag(PaymentRuleProductsConditionChecker::PAYMENT_PROVIDER_RULE_RECURSIVE_VARIANT_CACHE_TAG)`. The shipping checker caches under `cs_shipping_rule_recursive_variant`, which is never cleared. After editing a shipping rule's product list (with "include variants"), the old product set stays cached until the Pimcore cache expires or is cleared. Carriers stay wrongly available or unavailable.
- **Fix:** use `ShippingProductsConditionChecker::SHIPPING_RULE_RECURSIVE_VARIANT_CACHE_TAG`. Also subscribe to `*.post_delete`, and write the dispatch as a `match` (TODO-3 **I-26**).

### B-24 — `CustomerOrderDeletionListener` counts carts as orders and turns the block into a 500
- **Where:** `EventListener/CustomerOrderDeletionListener.php:33-62`; `OrderBundle/Pimcore/Repository/OrderRepository.php:189-195` **(dependent)**.
- **Problem:**
  - `hasCustomerOrders()` counts every `CoreShopOrder` with `customer__id`, whatever its `saleState`. Any customer who ever had a persisted cart therefore cannot be deleted, which blocks GDPR erasure. Old carts must be deleted first, and nothing in the UI says so.
  - The listing ignores unpublished orders, so those are orphaned.
  - `preDelete` throws a plain `\InvalidArgumentException`, which reaches the admin as a 500 instead of a message.
- **Fix:** count only `saleState IN (order, quote)` with `setUnpublished(true)`. Delete or anonymise the customer's carts on deletion. Throw `Pimcore\Model\Element\ValidationException` in `preDelete`.

### B-25 — (plausible) Cart-level min/max/stock validators do not sum lines of unsaved carts, because they compare ids that are all `null`
- **Where:** `Validator/Constraints/CartStockAvailabilityValidator.php:93-96`, `CartMinimumQuantityValidator.php:113-116`, `CartMaximumQuantityValidator.php:111-114` (`if ($item->getId() === $cartItem->getId()) continue;`).
- **Problem:** for items that are not persisted yet (`getId() === null`), every other unsaved line is treated as "the same line" and skipped. Only the current line's quantity is checked, so two lines of the same product (for example different unit definitions, or separate admin cart-creation rows) bypass the min/max/stock limits. In the storefront, cart items are normally saved before the `coreshop` group validation runs, so the main exposure is admin order creation and custom flows.
- **Fix:** compare identity (`$item === $cartItem`), or better, aggregate once per product in one loop (`$totals[$productId] += qty`) and validate the totals. That is also O(n) instead of O(n²). See TODO-3 **I-16**.

### B-26 — (reproduced, dependent) `ProductQuantityRangeTypeExtension::roundQuantity()` rounds with the **previous** unit's precision and throws a `TypeError` on an empty value
- **Where:** `Form/Extension/ProductQuantityRangeTypeExtension.php:45-47,50-66,71-89`. `rangeStartingFrom` is added by `ProductQuantityPriceRulesBundle/Form/Type/ProductQuantityRangeType.php:46` **before** this extension adds `unitDefinition` (`:42`).
- **Problem:**
  1. Children are submitted in insertion order. When the `PRE_SUBMIT` listener of `rangeStartingFrom` runs, `$parent->get('unitDefinition')->getData()` still holds the **old**, pre-set unit. Changing a range's unit together with its quantity rounds with the old precision. For a new range the unit is `null`, so no rounding happens at all.
  2. `Form::submit()` casts scalars to string, but `null` stays `null`. Under `strict_types`, `str_replace(',', '.', null)` throws `TypeError` (reproduced in `scratchpad/t1.php`). An empty "starting from" on a range with a stored unit definition makes the product save fail with a 500.
- **Fix:** move the rounding to a `PRE_SUBMIT` listener on the **range** form (the parent). Read the unit id from the submitted array (`$data['unitDefinition']`), resolve it, and round `$data['rangeStartingFrom']` when it `is_numeric()`. Type the method `: void`.

## Order mails / documents

### B-27 — (plausible) `OrderDocumentWkhtmlPrintOptionsListener` passes `null` into `setOptions(string)` for stores without PDF settings, and the resulting `TypeError` is not caught by `OrderMailProcessor`
- **Where:** `EventListener/Order/OrderDocumentWkhtmlPrintOptionsListener.php:31-36`; `OrderBundle/Event/WkhtmlOptionsEvent.php:26,43` (`protected string $options`, `setOptions(string)`); `Component/Core/Configuration/ConfigurationService.php:44-61` (`getForStore()` returns `null`); `Order/OrderMailProcessor.php:85-95,104-114` (`catch (\Exception)`).
- **Problem:** `system.invoice.wkhtml` / `system.shipment.wkhtml` are only seeded by `Fixtures/Data/Application/ConfigurationFixture.php:54,57`. A store created later, with no global fallback row, gets `null` → `TypeError`. `OrderMailProcessor` catches `\Exception` only, so the `TypeError` (an `\Error`) aborts the whole mail instead of just skipping the attachment.
- **Fix:** `$event->setOptions((string) ($this->configurationHelper->getForStore(...) ?? ''))`, or skip the call when the value is `null`. Initialise `WkhtmlOptionsEvent::$options = ''`. Catch `\Throwable` in `OrderMailProcessor`.

### B-28 — `OrderMailProcessor`: caller params are dropped for non-Concrete customers, a `null` customer crashes, and the whole customer object goes into mail params and order notes
- **Where:** `Order/OrderMailProcessor.php:50-78`; `EventListener/Order/OrderMailNoteEventListener.php:81-87`.
- **Problem:**
  - `$params` is merged only inside `if ($customer instanceof Concrete)`. Otherwise caller params (including `doNotSendToDesignatedRecipient`) are lost from `$emailParameters`.
  - `$customer->getEmail()` (`:74`) runs even when `$customer` is `null`.
  - `getObjectVars()` puts every customer property into the mail params: the `user` relation, `newsletterToken`, internal Pimcore vars (only two are unset). These are rendered into Twig, written to the Pimcore email log, and `OrderMailNoteEventListener` stores every string param as order-note data, so the customer's `newsletterToken` ends up in order notes.
- **Fix:** always merge `$params`. Return early or log when there is no customer. Pass `customer` as an object plus an explicit allow-list of scalar fields (`firstname`, `lastname`, `email`, `gender`, …) instead of `getObjectVars()`.

### B-29 — Workflow notification params: `fromState` is the marking after the transition
- **Where:** `EventListener/NotificationRules/OrderWorkflowListener.php:48`, `PaymentWorkflowListener.php:49`, `OrderDocumentWorkflowListener.php:45`; vendor `symfony/workflow/Workflow.php` (`completed()` receives the updated marking).
- **Problem:** the listeners run on `workflow.*.completed`. At that point `$event->getMarking()` already holds the new places, so `fromState` equals `toState` (as an array of places). Mail templates that show "changed from X to Y" print the new state twice.
- **Fix:** take the from-places from the transition (`$event->getTransition()->getFroms()`), or capture them in a `workflow.*.transition` listener.

## Storage / admin listeners

### B-30 — `CookieStorage` cookies never get `Secure` on HTTPS and are session cookies
- **Where:** `Storage/CookieStorage.php:41-72`; vendor `http-kernel/EventListener/ResponseListener.php:56` (priority 0 → `Response::prepare()` → `setSecureDefault(true)`), `http-foundation/Response.php:300-303`.
- **Problem:** `onKernelResponse` runs at priority `-1024`, after `ResponseListener::prepare()` has set the secure default on the existing cookies. The currency cookie added later is sent without `Secure` even over HTTPS: `_currency_1=5; path=/; httponly; samesite=lax` **(reproduced, `scratchpad/core/b/cookie.php`)**. `expire = 0` also makes the currency choice disappear when the browser closes.
- **Fix:** `Cookie::create($name, (string) $value)->withSecure($event->getRequest()->isSecure())->withExpires(strtotime('+1 year'))`. Or run the listener above priority 0. Use `->withExpires(1)` for removals so the intent is explicit.

### B-31 — (plausible) `ProductStoreValuesAdminGetListener` warns on product classes without a `storeValues` field
- **Where:** `EventListener/ProductStoreValuesAdminGetListener.php:59-75`.
- **Problem:** `foreach ($data['data']['storeValues'] as &$storeValues)` assumes the key exists. A `ProductInterface` class without that field (custom product class, or a field renamed or localised) triggers "Undefined array key" plus "foreach() argument must be of type array". In debug mode Symfony's error handler turns that into an exception, and the admin cannot open the object. `ClassDefinition::getById()` runs for every object open.
- **Fix:** `if (!is_array($data['data']['storeValues'] ?? null)) return;`. Read `allowInherit` via `$object->getClass()`.

## Admin rule / product forms

### B-32 — Rule action forms: the currency `CallbackTransformer` cannot transform the stored value
- **Where:** `Form/Type/Shipping/Rule/Action/{AdditionAmount,DiscountAmount}ActionConfigurationType.php:55-70`, `Shipping/Rule/Action/PriceActionConfigurationType.php:51-66`, `Form/Type/Payment/Rule/Action/{AdditionAmount,DiscountAmount}ActionConfigurationType.php:55-70`, `Payment/Rule/Action/PriceActionConfigurationType.php:51-66`. Consumers read `$configuration['currency']` as an **id** (`Component/Core/{Shipping,Payment}/Rule/Action/AdditionAmountActionProcessor.php:48`).
- **Problem:** both directions are `fn($c) => $c instanceof CurrencyInterface ? $c->getId() : null`. The model value is an int id, so `transform()` always returns `null`, and the `CurrencyChoiceType` child never shows the stored currency. Reverse-transform works. The admin always posts the full config, so this goes unnoticed. Any partial submit (`clearMissing=false`, API/PATCH) or server-side rendering of these forms silently drops the currency, and the processor then fails `Assert::isInstanceOf($currency, CurrencyInterface::class)`. The same block of code is copied six times.
- **Fix:** add one shared `CurrencyIdTransformer` (id → `CurrencyRepository::find()` in `transform()`, entity → id in `reverseTransform()`), or use a plain `ChoiceType` over currency ids. Factor the six types into two (amount and price) with a `block_prefix` option.

### B-33 — (dependent) `ProductStoreValuesType`: the `PHP_INT_MAX` guard is dead; a too-large price is stored wrapped (negative or wrong)
- **Where:** `Form/Type/Product/ProductStoreValuesType.php:46-53`, together with `MoneyBundle/Form/Transformer/MoneyToIntegerTransformer.php` (`(int) round($value * $factor)`).
- **Problem:** the transformer already cast to `int`, so `getPrice() >= PHP_INT_MAX` can only match exactly `PHP_INT_MAX`. On PHP 8.4, `(int) round(1e19)` gives `-8446744073709551616` (reproduced). The admin-entered store price is persisted as a negative or arbitrary amount, with no error. Root cause and fix are in **MoneyBundle `TODO-2-BUGS.md` B-01 / B-07**.
- **Fix:** do the range check in the transformer (throw `TransformationFailedException`) as MoneyBundle B-07 proposes, then remove this listener. If you keep a guard here, validate the raw submitted string in `PRE_SUBMIT`.

### B-34 — `CartCreationTypeExtension` is defined twice with conflicting tags; it only works because of import order
- **Where:** `Resources/config/services/form.yml:95-97` (tag `form.type`, wrong for an extension) and `Resources/config/services/form_extension.yml:49-51` (tag `form.type_extension`). Both are imported by `Resources/config/services.yml:4-5`.
- **Problem:** the second definition replaces the first, including its tags. The compiled container shows only `form.type_extension`, so it works today. If the import order changes, or someone overrides `form.yml`, the extension is registered as a *type*. The admin cart-creation form then silently loses `shippingAddress`/`invoiceAddress`/`carrier`, and orders are created without addresses.
- **Fix:** delete the block from `form.yml:95-97`.

### B-35 — Block-prefix collisions between rule forms
- **Where:** `Form/Type/Payment/Rule/Action/AdditionAmountActionConfigurationType.php:73-76` returns `coreshop_shipping_rule_action_addition_amount`, and `Payment/.../DiscountAmountActionConfigurationType.php:73-76` returns `coreshop_shipping_rule_action_discount_amount`. Both are identical to the Shipping types. `Form/Type/Notification/Action/StoreOrderMailConfigurationType.php:48-51` returns `coreshop_notification_rule_action_store_mail`, the same as `StoreMailConfigurationType.php:46-49`.
- **Problem:** form-theme blocks, default form names and the profiler cannot tell the types apart, and a theme override for one changes the other. This is the same class of issue as CustomerBundle `TODO-2-BUGS.md` B-15.
- **Fix:** `coreshop_payment_provider_rule_action_addition_amount` / `..._discount_amount` and `coreshop_notification_rule_action_store_order_mail`.

## Service wiring / DependencyInjection

### B-36 — Five service definitions pass more constructor arguments than the class accepts; the extra dependencies are silently dropped
- **Where / Problem:** `wiring.php` parses `services.yml`, `services_test.yml` and all 33 `services/*.y*ml` (343 definitions), loads every class through the vendor autoloader (with the working tree preferred) and compares positional/named arguments with the constructor. **(reproduced)** Findings:
  - `services/routing.yml:5-21` → `Pimcore/LinkGenerator/DataObjectLinkGenerator.php:29-33`: 5 args, ctor takes 3. `@CoreShop\Component\Resource\Metadata\RegistryInterface` and `@CoreShop\Component\Pimcore\Slug\SluggableLinkGenerator` are ignored, so `coreshop.object.link_generator.product/category` never use Pimcore slugs although the wiring suggests they do (links are always `/{name}-p{id}` style routes).
  - `services/cart-processor.yml:62-70` → `Component/Core/Order/Processor/CartPriceRuleVoucherProcessor.php:33-38`: 5 args, ctor takes 4 (`@coreshop.factory.adjustment` dropped).
  - `services/checkout.yml:76-85` → `Form/Type/Checkout/PaymentType.php:38-46`: 6 args, ctor takes 5 (`PaymentProviderResolverInterface` dropped – the form cannot filter providers itself; it relies on `PaymentProviderChoiceType`).
  - `services/checkout.yml:69-74` → `Form/Type/Checkout/GuestAddressType.php:29` (extends `AbstractType`, no ctor): `data_class` and `validation_groups` (`%coreshop.form.type.checkout.address.validation_groups%`) are never applied, so overriding that parameter has no effect.
  - `services/form.yml:36-42` → `Form/Type/UserRegistrationType.php:28` (inherits `AbstractResourceType(dataClass, validationGroups)`): the 3rd arg `%coreshop.customer.security.login_identifier%` is dropped, and it receives the **customer** registration validation groups for the **user** class.
  - Symfony does not report surplus positional arguments, and PHP ignores extra arguments to userland constructors, so the container compiles fine; the intent is lost.
  - All other definitions resolve: no missing classes, no unknown named args, no missing `calls`. `data_hub.yml` cannot be class-checked here (DataHub not installed); its ctor (`ResourceList(Service, DoctrineProvider, string)`) matches by reading.
- **Fix:** decide per service: either remove the surplus arguments or add the constructor parameters (for `DataObjectLinkGenerator`, delegate to `SluggableLinkGenerator` when the object has a slug for the site/locale). Add a CI check (`lint:container` does **not** catch this; keep the script or a PHPStan rule such as `phpstan-symfony` + container XML).
- **Also (same finding from the listener/checkout scan, reproduced independently):**
  - **Where:**
    - `Resources/config/services/checkout.yml:69-74`: `GuestAddressType` gets 2 args but has no constructor (it extends `AbstractType`, not `AbstractResourceType`).
    - `checkout.yml:76-85`: `PaymentType` gets 6 args, the constructor takes 5 (`PaymentProviderResolverInterface` is ignored).
    - `cart-processor.yml:62-70`: `CartPriceRuleVoucherProcessor` gets 5 args for 4 (`coreshop.factory.adjustment` is ignored).
  - **Problem:** PHP ignores the extra positional arguments, so nothing fails, but the config misleads readers. It also hides that the payment form does not use the resolver it seems to be configured with.
  - **Fix:** remove the extra arguments, or switch these services to autowiring. `GuestAddressType::buildForm()` also lacks `: void` (Symfony 8 adds it to `FormTypeInterface`; DebugClassLoader reports it) — Form slice, (dependent).

### B-37 — `core_shop_order` is missing from `$bundlesWithAttributeConfiguration`; `core_shop_core.autoconfigure_with_attributes: true` does not reach OrderBundle
- **Where:** `DependencyInjection/CoreShopCoreExtension.php:45-62`, `:124-136`; `OrderBundle/DependencyInjection/Configuration.php:78`, `OrderBundle/DependencyInjection/CoreShopOrderExtension.php:118-181` (8 registries use the flag).
- **Problem:** the documented global switch enables attribute-based autoconfiguration for 16 bundles, but not for OrderBundle (cart price rule conditions/actions, cart processors, …). With the switch on, `#[As…]`-attributed order services stay untagged unless OrderBundle is configured separately; services that rely only on interface autoconfiguration keep working in Order but not in the other bundles → inconsistent behaviour.
- **Fix:** add `'core_shop_order'`; better, iterate over all extensions whose configuration tree has an `autoconfigure_with_attributes` node instead of a hard-coded list.

### B-38 — `load()` resets `coreshop.all.dependant.bundles`, wiping entries registered by extensions loaded before CoreBundle
- **Where:** `DependencyInjection/CoreShopCoreExtension.php:69-72`; `CustomerBundle/DependencyInjection/CoreShopCustomerExtension.php:45` (Newsletter), `OrderBundle/DependencyInjection/CoreShopOrderExtension.php:74` (WebToPrint); consumer `ResourceBundle/Installer/PimcoreDependantBundleInstaller.php:36-40`.
- **Problem:** each extension's `load()` gets a temporary container that already contains the parameters of the extensions processed before it (`MergeExtensionConfigurationPass`, Symfony 7.4). Order (3200) and Customer (3100) are registered with a higher priority than CoreBundle, so their entries are lost when CoreBundle sets the parameter to `[]`. `coreshop:resources:install` **without** `--application-name` therefore does not install the Newsletter/WebToPrint bundles. (`coreshop:install` passes `coreshop` and reads `coreshop.dependant.bundles`, which is not reset.)
- **(plausible)**
- **Fix:** delete line 69.

## Pimcore repositories

### B-39 — `WishlistRepository::findNamedStorageLists()` orders by `o_creationDate`, a column that no longer exists in Pimcore 11 → SQL error
- **Where:** `Pimcore/Repository/WishlistRepository.php:32-41` (line 36). Caller: `Component/StorageList/Core/Context/PimcoreListResolver.php:46`.
- **Problem:** Pimcore 11 removed the `o_` prefixes (`objects.creationDate`; `ClassDefinition/Dao.php:210` builds the view from `objects` + `object_store_X`). `setOrderKey('o_creationDate')` quotes it as an identifier (`AbstractListing::setOrderKey()`, `isValidOrderKey()` returns `true`), and the query fails with "Unknown column 'o_creationDate'". Every page that lists the customer's named wishlists (multi-wishlist feature) returns 500. `OrderRepository::findNamedStorageLists()` uses `id` and is fine.
- **(plausible)** (column rename verified in pimcore-source; not executed)
- **Fix:** `setOrderKey('creationDate')`.

### B-40 — `WishlistRepository::findLatestByStoreAndCustomer()` ignores `$name`
- **Where:** `Pimcore/Repository/WishlistRepository.php:43-61`; caller `Component/StorageList/Core/Context/PimcoreListResolver.php:60` passes the requested list name.
- **Problem:** named wishlists cannot be selected: any lookup by name returns the customer's latest wishlist in the store, so items are added to / shown from the wrong list.
- **(plausible)**
- **Fix:** `if (null !== $name) { $list->addConditionParam('name = ?', $name); }` (and `setLimit(1)`).

### B-41 — `CategoryRepository::findRecursiveChildCategoryIdsForStoreByCategories()` ignores the store
- **Where:** `Pimcore/Repository/CategoryRepository.php:59-92`; compare `ProductRepository.php:62-105` (`WHERE variants.stores LIKE :store`). Callers: `Component/Core/Rule/Condition/CategoriesConditionCheckerTrait.php:39` (product price, cart and cart-item price rules).
- **Problem:** child categories that are not assigned to the current store are treated as matching, so a category condition matches products through categories that are invisible in that store (multi-store shops).
- **(plausible)**
- **Fix:** add `WHERE variants.stores LIKE :store` (rename the alias to `children`), as in the product variant.

### B-42 — Path prefix queries use unescaped `LIKE` patterns; `_` and `%` in object keys match unrelated trees
- **Where:** `Pimcore/Repository/ProductRepository.php:54,75,117-120`, `Pimcore/Repository/CategoryRepository.php:69,104-106`.
- **Problem:** `path LIKE '<fullPath>/%'` treats `_` in the parent's key as a wildcard. `/products/shirt_1/%` also matches `/products/shirtX1/…`. Pimcore keys commonly contain `_`. Price rule conditions "product incl. variants" / "category incl. children" can then match foreign products.
- **(plausible)**
- **Fix:** `addcslashes($path, '%_\\')` (MySQL default escape `\`), or compare `LEFT(path, n) = :prefix`.

### B-43 — Variant id cache (`cs_rvids_*`) is never invalidated
- **Where:** `Pimcore/Repository/ProductRepository.php:62-105` (`Cache::save(..., $cacheTags + 'coreshop_variant_recursive', 500, 0, true)`); nothing clears `VARIANT_RECURSIVE_QUERY_CACHE_TAG` (`grep` over `src/`).
- **Problem:** adding a variant, moving it, or changing its `stores` does not invalidate the cached id list; price rule conditions ignore the change for up to 500 s (longer with a persistent cache). Only the tags passed by the caller can clear it.
- **(plausible)**
- **Fix:** clear the tag in a `pimcore.dataobject.postAdd/postUpdate/postDelete` listener for products/variants, or add the parent object tags (`object_<id>`) of the involved products.

## Product store values (`CoreExtension/StoreValues.php`)

### B-44 — Generated `set<Field>OfType()` hard-codes `getStoreValuesForStore()`; any field not named `storeValues` (or a second StoreValues field) reads/writes the wrong field
- **Where:** `CoreExtension/StoreValues.php:294-296`.
- **Problem:** the generated code uses `$this->getStoreValuesForStore($store)` instead of `$this->get<Key>ForStore($store)`. A field called e.g. `prices` gets values created from scratch on every call (never finds the existing row → duplicate insert → unique-key violation, see B-45) or fatals if the class has no `storeValues` field.
- **Fix:** `'return $this->get' . ucfirst($key) . 'ForStore($store);'`.
- Related: the generated `get<Key>ForStore()` (`:179-198`) falls back to the parent only when the **whole** field is empty; a variant with own values for store A returns `null` for store B instead of inheriting. Decide and document per-store inheritance.

### B-45 — Replacing a store's row (new entity for a store that already has one) fails with a unique-key violation
- **Where:** `Resources/config/doctrine/model/ProductStoreValues.orm.xml:6-8` (`UNIQUE(product, store, fieldName)`), `CoreExtension/StoreValues.php:476-486` (old rows `remove()`d, new rows `persist()`ed, one `flush()`).
- **Problem:** Doctrine's commit order is inserts → updates → deletes. Any save that contains a **new** entity for a store that still has a DB row (version restore after the row was re-created, `setStoreValuesForStore(new …)`, B-44) inserts the new row before deleting the old one → `UniqueConstraintViolationException`, the object save fails.
- **(plausible)**
- **Fix:** reuse the existing row for the same store (copy values onto it), or flush the removals first (`remove()` + `flush()` before persisting new rows, inside the same transaction).

### B-46 — CSV export of a StoreValues field always produces `{"<storeId>":{}}`
- **Where:** `CoreExtension/StoreValues.php:722-731`.
- **Problem:** `json_encode()` of `ProductStoreValues` entities (protected properties, no `JsonSerializable`) yields empty objects. **(reproduced)** `csv.php` → `{"1":{}}`. Grid CSV/XLSX export of prices is useless.
- **Fix:** serialize via JMS (`toArray($entity, SerializationContext::create()->setGroups(['Version']))`) or map to `['store' => id, 'price' => …]`.

### B-47 — Smaller defects in the data type
- **Where / Problem:**
  - `:688-697`: building the error message with `str_replace($key, $value, …)` throws `TypeError` when a message parameter is not a string (**(reproduced)** with an array: "Argument #2 ($replace) must be of type string"), hiding the validation error behind a 500. Use `strtr($template, array_map('strval', …))` or `$e->getMessage()` (already translated/interpolated).
  - `:704-720` `getVersionPreview()`: `return $data` for a non-array, non-null value violates the `string` return type → `TypeError`.
  - `:561-569` cache/version round trip: the `Version` group excludes `product` and `fieldName` (`serializer/Model.ProductStoreValues.yml`), so entities from the Pimcore object cache have `getProduct() === null`. `Controller/ProductController.php:40` ("restore inheritance") then treats the product's own row as foreign. **(plausible)**
  - `:585-644` `getDataForEditmode()`: `'inherited' => false` is hard-coded for existing rows and missing for filled-in stores, so the JS "restore inheritance" button (`tags/coreShopStoreValues.js:219`) shows on every own row and never reflects Pimcore's real inheritance state.
- **Fix:** as noted per bullet.

## Grid operator / Doctrine mapping / misc

### B-48 — `StorePrice` grid operator fatals for a deleted store, a store without currency, or a string `storeId`
- **Where:** `Pimcore/GridColumnConfig/Operator/StorePrice.php:31,40,51-59`.
- **Problem:** `find()` may return `null` (store deleted after the grid config was saved) → `getStoreValuesOfType('price', null)` `TypeError`; `$store->getCurrency()` may be `null`; `private int $storeId = $config->storeId` throws under `strict_types` when the saved JSON has `"storeId": "1"`. The whole grid fails to load.
- **(plausible)**
- **Fix:** cast `(int) ($config->storeId ?? 0)`, return an empty value when the store/currency is missing, and do the lookup after the `ProductInterface` check.

### B-49 — `CountryRepository::findForStore()` filters by `o.id` instead of `s.id`
- **Where:** `Doctrine/ORM/CountryRepository.php:27-37` (line 32).
- **Problem:** returns the country whose **id** equals the store id. No caller in `src/` today, but it is public API (`CountryRepositoryInterface`).
- **Fix:** `->andWhere('s.id = :storeId')`.

### B-50 — Deleting a store fails while carriers are assigned; deleting a tax rule group fails while store values reference it
- **Where:** `Resources/config/doctrine/model/Carrier.orm.xml:5-14` (`coreshop_carrier_stores` without `on-delete`, unidirectional, so Doctrine never clears it when a **Store** is removed; compare `PaymentProvider.orm.xml` with `on-delete="CASCADE"`), `ProductStoreValues.orm.xml:18-20` (`taxRuleId` without `on-delete`).
- **Problem:** the admin delete ends in a foreign-key exception (500) instead of unlinking or a clear message.
- **(plausible)**
- **Fix:** `on-delete="CASCADE"` on both join columns of `coreshop_carrier_stores`; `on-delete="SET NULL"` (or a validation message) for `taxRuleId`. Migration required.

### B-51 — (dependent) `coreshop_payment` has no index on the order reference; every payment lookup per order is a full table scan
- **Where:** `Resources/config/doctrine/model/Payment.orm.xml:5` (adds `` `order` `` pimcoreObject column, no index), `PaymentBundle/Resources/config/doctrine/model/Payment.orm.xml:13` (`orderId`, no index), `PaymentBundle/Doctrine/ORM/PaymentRepository.php:27-36` (`findForPayable()` → `WHERE o.orderId = :orderId`).
- **Problem:** `findForPayable()` runs on every order detail in the admin, in `OrderPaymentStateResolver` after every payment state change, in Payum capture/notify flows and in the customer order history. Without an index the query scans the whole payment table; on shops with many orders this is a measurable latency on checkout completion and notify callbacks. Neither column is indexed (and Core stores the same value twice, see `PaymentBundle/TODO-2-BUGS.md` **B-16**).
- **Fix:** add `<indexes><index name="payment_order" columns="orderId"/></indexes>` (and/or on `` `order` ``) plus a migration; long term drop one of the two duplicate columns.

### B-52 — `Application\Version` reports `1.0.1` for a 4.1 codebase
- **Where:** `Application/Version.php:23-29`; used by `ResourceBundle/AbstractResourceBundle.php` `getVersion()` for every CoreShop bundle and `Controller/SettingsController.php:32` (admin "about").
- **Problem:** every CoreShop bundle shows "1.0.1 (4.1.x)" in Pimcore's bundle manager and the settings panel; support/upgrade checks read a wrong version.
- **Fix:** derive it from `Composer\InstalledVersions::getPrettyVersion('coreshop/core-shop')` (fallback `coreshop/core-bundle`), or bump the constants in the release process.

## Reports

### B-53 — Carbon 3 interprets all report timestamps as UTC, shifting every date filter and label by the server offset
- **Where:** every report: `AbandonedCartsReport.php:51-52`, `CarriersReport.php:49-50`, `CartsReport.php:58-59,111`, `CategoriesReport.php:61-62`, `CustomersReport.php:48-49`, `ManufacturerReport.php:53-54`, `PaymentProvidersReport.php:49-50`, `ProductsReport.php:74-75`, `SalesReport.php:67-68,117`, `VouchersReport.php:54-55,95`.
- **Problem:** The admin JS sends `from`/`to` as `dateField.getValue().getTime()/1000` — a UTC-midnight epoch of the picked local day (`report/abstract.js:288-292`). `Carbon::createFromTimestamp($ts)` in Carbon 3 defaults to **UTC** (reproduced: a local-midnight epoch formats back one hour earlier in `Europe/Budapest`). Pimcore stores `orderDate`/`creationDate` as integer epochs too, so the `>`/`<` comparisons are epoch-to-epoch and mostly survive, but anywhere a Carbon value is re-formatted for display the day shifts. Reproduced: `SalesReport`/`CartsReport` turn a MySQL `DATE()` string `2025-11-15` into `datetext` `2025-11-14` via `Carbon::createFromTimestamp(strtotime($day))` (`CartsReport.php:111-113`). So chart/day labels and grouped buckets are off by a day in any non-UTC deployment.
- **Fix:** construct Carbon with the app timezone, e.g. `Carbon::createFromTimestamp($ts, date_default_timezone_get())`, or avoid Carbon for pure epoch math. For the `DATE()`-string labels use `Carbon::parse($day, $tz)` / `new \DateTimeImmutable($day)` rather than `strtotime`.

### B-54 — Default date range excludes the whole last day of the month (reproduced)
- **Where:** all reports, default `to` = `strtotime(date('t-m-Y'))` (e.g. `SalesReport.php:63`, `CategoriesReport.php:50`, etc.); SQL uses `orderDate < :toTimestamp`.
- **Problem:** `date('t-m-Y')` is the last day at **00:00:00** (reproduced: `2026-10-31 00:00:00`), and the filter is strictly `<`. So orders on the last day of the month (and, with `from = 01-m-Y` 00:00 plus `>`, this is consistent only at the lower bound) are dropped. The same `<` applies to the user-chosen `to`, which the JS sets to the picked day at local-midnight — the entire selected end day is excluded.
- **Fix:** use inclusive upper bounds: `<= end-of-day`, or add one day to `to` and keep `<`. Apply consistently to `from`/`to` across all reports.

### B-55 — `SalesReport` crashes (TypeError) for an unknown `groupBy` value (reproduced)
- **Where:** `SalesReport.php:74-102,121`.
- **Problem:** `$dateFormatter`/`$groupSelector` are only set in the `day|month|year` switch with no `default`. Any other `groupBy` (the value is unvalidated request input) leaves `$dateFormatter = null`; line 121 calls `$date->format(null)`, which is a `TypeError` on Carbon/PHP 8.4 (reproduced), 500-ing the sales report and the sales dashboard portlet. `GROUP BY ''` would also run an unintended aggregate.
- **Fix:** `match($groupBy) { 'day'/'month'/'year' => …, default => throw new \InvalidArgumentException(...) }` (or clamp to `day`).

### B-56 — `SalesReport` month/year grouping labels an arbitrary day's row and breaks across years
- **Where:** `SalesReport.php:92-100,116-121`.
- **Problem:** The SELECT returns `orderDate` (an arbitrary member row of each group because of `sql_mode=''`, which Pimcore sets — `pimcore-source/bundles/CoreBundle/config/pimcore/default.yaml:148`), while grouping is by `MONTH(...)`/`YEAR(...)`. For `month`, `GROUP BY MONTH()` merges the same month across different years into one bucket, and `datetext` ('F Y') is taken from a random row. Results are wrong for multi-year ranges.
- **Fix:** group by `YEAR(),MONTH()` (or `DATE_FORMAT(FROM_UNIXTIME(orderDate),'%Y-%m')`) and derive the label from the grouped key, not a stray `orderDate`.

### B-57 — `CustomersReport` hard-codes EUR instead of the store currency
- **Where:** `CustomersReport.php:78-83`.
- **Problem:** `salesFormatted` is formatted with the literal `'EUR'`. Every other report uses `$store->getCurrency()->getIsoCode()`. This report also takes no `store` filter at all (`:66-70` joins across all stores and sums `totalNet`), so in a multi-store or non-EUR shop the figures are mislabeled and mix currencies/stores.
- **Fix:** add a `store` filter like the other reports and format with the store's currency ISO code.

### B-58 — `PaymentProvidersReport` percentage denominator ignores sale state, so percentages understate / don't sum to 100
- **Where:** `PaymentProvidersReport.php:71-80`; same pattern `CarriersReport.php:71-79`.
- **Problem:** The CROSS JOIN denominator `t.cnt` counts rows filtered only by store + date (no `saleState`), while the numerator counts `saleState = STATE_ORDER` only. So the percentages are of all carts+orders, not of orders, and won't total 100% across providers/carriers. `CarriersReport` additionally filters `carrier IS NOT NULL` in the numerator but not the denominator.
- **Fix:** apply the same `saleState = STATE_ORDER` (and carrier/provider NOT NULL) predicate inside the denominator subquery.

### B-59 — `AbandonedCartsReport` silently overrides the user's date filter and can return an empty/odd window
- **Where:** `AbandonedCartsReport.php:54-78`.
- **Problem:** The "abandoned = 48h" logic rewrites `fromTimestamp`/`toTimestamp`: `if ($from->gt($minFrom)) $fromTimestamp = $minFrom->getTimestamp();` sets the lower bound to `now-3d` whenever the user's `from` is later than 3 days ago, and `$to` is capped at `now-2d`. A user asking for "today" gets `from = now-3d`, `to = now-2d` — not what the filter shows. Also `$maxTo = now-2d+1s` and `$minFrom = now-3d` are fixed windows unrelated to the chosen range; selecting an old range returns nothing. Mutating `$to` but not re-deriving `$from` from the same base makes the window inconsistent.
- **Fix:** define the abandoned window explicitly and clamp the user range **into** it (intersection), or document the fixed 48–72h window in the UI and ignore the pickers. Verify intended behaviour first.

### B-60 — Pagination `offset` can be overridden directly by the client, and `page`/`limit` are unvalidated
- **Where:** `AbandonedCartsReport.php:61-63`, `CategoriesReport.php:64-66`, `CustomersReport.php:51-53`, `ManufacturerReport.php:56-58`, `ProductsReport.php:77-79`, `VouchersReport.php:57-59` (`$offset = $parameterBag->get('offset', …)`).
- **Problem:** `offset` is read from the request and only cast to `(int)` in the `LIMIT` clause. The paging toolbar sends `start`/`limit`, not `offset`, so the `$page`-derived fallback is what runs — but a client can pass `offset` directly. `limit` is also unvalidated (no max), so `limit=100000000` is a cheap DoS, and `page`/`limit` are compared with `=== 1` against string query values (reproduced: `'1' === 1` is false), so when `page` is present as a string the "page 1 ⇒ offset 0" shortcut never applies and relies on the arithmetic branch instead (works, but the `=== 1` guard is dead for real requests).
- **Fix:** read `start`/`limit` to match the Ext paging proxy, cast to int, clamp `limit` to a sane max, drop the `offset` passthrough, and remove the `=== 1` micro-optimisation.

### B-61 — `ProductsReport` "object" (non-variant) filter uses `= NULL`, which matches nothing
- **Where:** `ProductsReport.php:128-133`.
- **Problem:** `$productTypeCondition = 'orderItems.mainObjectId = NULL';` — `= NULL` is never true in SQL. Selecting objectType `object` returns zero rows. Should be `IS NULL`.
- **Fix:** `'orderItems.mainObjectId IS NULL'`.

### B-62 — `ProductsReport` container branch has no `ORDER BY` and groups by a possibly-ambiguous column
- **Where:** `ProductsReport.php:110-126`.
- **Problem:** The `container` query ends at `GROUP BY products.o_id` with no `ORDER BY` (the other branch orders by `orderCount DESC`), so container results come back unordered. Also the derived `products` subquery selects `id,name,type` but groups by `products.o_id` and filters `products.o_type='object'` — those `o_`-prefixed columns are not in the subquery's select list; this relies on the underlying `object_localized_*` view exposing `o_id`/`o_type`, which is fragile.
- **Fix:** add `ORDER BY orderCount DESC`; group/select consistent column names.

### B-63 — `PaymentProvidersReport` renders provider title as literal 'unkown' typo and never sets `totalRecords`
- **Where:** `PaymentProvidersReport.php:97` (`'unkown'`); `CarriersReport.php`, `PaymentProvidersReport.php`, `CartsReport.php`, `SalesReport.php` all leave `$totalRecords = 0` while `getTotal()` returns it.
- **Problem:** Chart reports never populate `totalRecords`; harmless for charts (no paginator) but `getTotal()` is part of `ReportInterface` and returns a misleading 0. The user-visible 'unkown' is a typo.
- **Fix:** fix the spelling; document that chart reports have no total, or compute it.

## Controllers

### B-64 — `OrderCreationController::getSubscribedServices` lists the same service twice
- **Where:** `Controller/OrderCreationController.php:148-154`.
- **Problem:** `TaxedShippingCalculatorInterface::class` appears on both line 150 and line 153 (and `CarriersResolverInterface` is both a `SubscribedService` autowire alias and a bare class entry). Harmless (the locator de-dupes; confirmed the compiled container builds, `subs.php` shows 17 merged entries) but it is dead duplication and signals a copy-paste error.
- **Fix:** list each service once.

## Migrations

### B-65 — All "Pimcore API" migrations run their side effects during `--dry-run` / `--write-sql`
- **Where:** `Migrations/Version20230928104945.php`, `Version20230928105158.php`, `Version20231004120900.php`, `Version20240117102103.php`, `Version20240211090209.php`, `Version20240211095530.php`, `Version20240211095804.php`, `Version20240218161440.php`, `Version20240421093551.php`, `Version20240718133201.php`, `Version20240920110320.php`, `Version20240920110705.php`, `Version20241018060845.php`, `Version20260419121310.php`, `Version20260616120000.php`. **(dependent)** `vendor/doctrine/migrations/src/Version/DbalExecutor.php:137-158` (`$migration->$direction($toSchema)` is called unconditionally; only collected `addSql()` statements honour `isDryRun()`).
- **Problem:** These migrations change state directly in `up()` (`ClassUpdate::save()` → class definition files + `ALTER TABLE object_*`, `Staticroute::save()`, `Definition::save()`, order saves, Web2Print install, `bundles.php` write). Doctrine calls `up()` in dry-run mode too, so `doctrine:migrations:migrate --dry-run` changes the system and reports "did not result in any SQL statements". The changes are also not covered by the migration transaction (DDL commits implicitly), so a failure halfway cannot be rolled back.
- **Fix:** Moving the work to `postUp()` does not help, because `postUp()` is also called in dry-run. Add a guard instead: capture `MigratorConfiguration::isDryRun()` in an `onMigrationsMigrating` listener (`Doctrine\Migrations\Events`) and skip the side effects when it is set. If that is not done, document that these migrations must not be dry-run. Also return `false` from `isTransactional()` for them (see TODO-3 **I-08**).

### B-66 — The two new class-definition migrations fail on customised classes, and `Version20260419121310` cannot be reverted
- **Where:** `Migrations/Version20260419121310.php:34-68,70-73`, `Migrations/Version20260616120000.php:36-67`. **(dependent)** `Component/Pimcore/DataObject/AbstractDefinitionUpdate.php:109-122,217-251` (`findField()` throws `ClassDefinitionFieldNotFoundException`).
- **Problem:**
  - Both use `insertFieldAfter('passwordResetHash' | 'orderDate', …)`. A project that renamed, removed or moved these anchor fields into a localized field or field collection gets an uncaught `ClassDefinitionFieldNotFoundException`. The migration run aborts and every later migration stays blocked. Earlier migrations in this bundle have the same pattern, but these two are new and were added to code that already relies on the field (`OrderRepository.php:107`, `UserRepository.php:69`).
  - `Version20260419121310::down()` is empty (template comment), although its sibling `Version20260616120000::down()` implements `removeField()`. `migrations:migrate prev` reports success but leaves the field in place.
- **Fix:** Catch `ClassDefinitionFieldNotFoundException` and fall back to `$classUpdater->insertField($definition)` (append to the first panel). Implement `down()` the same way as in `Version20260616120000` (`hasField` → `removeField` → `save`).

### B-67 — Non-idempotent or unguarded schema and data migrations
- **Where:** `Migrations/Version20230705174306.php:33-38`, `Version20230711143927.php:33-38`, `Version20231201091924.php:33-38`, `Version20230711144054.php:33-43`.
- **Problem:**
  - `Version20230705174306` runs `UPDATE notes SET description = title WHERE type='history_state_change'` and then sets `title = 'coreshop_history_change'`. If it runs a second time (re-run after `migrations:version --delete`, or restored metadata table), every description is overwritten with `coreshop_history_change`. The original state-change text is lost (data loss).
  - `Version20230711143927` and `Version20231201091924` `ALTER`/`CREATE` without a `hasColumn`/`hasTable` guard. `Version20230829163505` and `Version20241105123053` in the same bundle do have guards. Re-running fails with "Duplicate column / table exists".
  - `Version20230711144054` selects `taxRule` from `object_query_<productClassId>`. The current `CoreShopProduct.json` no longer has a `taxRule` field (tax rule moved to `storeValues`). On any install where the classes were created from the current JSON but the migration was not marked as executed (for example classes installed via `coreshop:resources:install` and then `coreshop:migrate`), it fails with an unknown column **(plausible)**. Products that reference a deleted tax-rule group violate `FK_9EED0E97AC7C6E20` and abort the run **(plausible)**.
- **Fix:** Add `WHERE title <> 'coreshop_history_change'` (or `description IS NULL OR description = ''`) to the first statement. Add `$schema->getTable(..)->hasColumn()` / `$schema->hasTable()` guards. In `Version20230711144054`, check the column with `$this->connection->createSchemaManager()->listTableColumns()` first, and use one `UPDATE … JOIN … WHERE EXISTS (SELECT 1 FROM coreshop_tax_rule_group …)` statement instead of one statement per product.

### B-68 — `Version20230829163505` creates a table that does not match the Doctrine mapping
- **Where:** `Migrations/Version20230829163505.php:37-40`. **(dependent)** `PaymentBundle/Resources/config/doctrine/model/PaymentProviderRuleTranslation.orm.xml`, `ResourceBundle/EventListener/ORMTranslatableListener.php:123-160`.
- **Problem:** The migration creates `label VARCHAR(256) NULL`, a nullable `locale VARCHAR(5)` and a unique index named `translatable_id_locale`. The mapping gives `label` the default length 255. The translatable listener maps `locale` as `NOT NULL` and names the unique constraint `coreshop_payment_provider_rule_translation_uniq_trans`. `doctrine:schema:validate` reports drift. `doctrine:schema:update` / `coreshop:resources:create-tables` diffs want to alter the table, and fresh and upgraded installs end up with different schemas.
- **Fix:** Add a follow-up migration that aligns the column definitions and renames the index to what `SchemaTool` generates (or generate the SQL with `doctrine:migrations:diff` filtered to this table).

### B-69 — `Version20240718133201` force-installs the Pimcore Web2Print bundle
- **Where:** `Migrations/Version20240718133201.php:31-42`.
- **Problem:** Every shop that has `pimcore/web-to-print-bundle` in `vendor/` gets it installed, including shops that uninstalled it on purpose. `class_exists()` checks only the package. If the bundle class exists but is not enabled in the kernel, `\Pimcore::getContainer()->get(Installer::class)` throws `ServiceNotFoundException` and the migration run aborts **(plausible)**. `$installer &&` is dead code because `get()` never returns null.
- **Fix:** Check `$container->has(Installer::class)` (bundle enabled) and only print a hint instead of installing. The migration should not decide which optional Pimcore bundles are installed.

### B-70 — `coreshop:migrate` skips the IndexBundle and MessengerBundle migrations
- **Where:** `Command/MigrateCommand.php:45-46`. **(dependent)** `IndexBundle/Migrations/*`, `MessengerBundle/Migrations/*`, `IndexBundle/Resources/config/pimcore/config.yml:6`, `MessengerBundle/Resources/config/pimcore/config.yml:5`.
- **Problem:** The command passes `--prefix CoreShop\Bundle\CoreBundle\Migrations`. The CoreShop migrations in other bundles (`coreshop_index.indexLastVersion`, the messenger SettingsStore flag) never run through the documented CoreShop command. `InstallCommand` also marks only the CoreBundle prefix as installed, so on a fresh install these other migrations stay pending.
- **Fix:** Use `--prefix CoreShop\Bundle` (Pimcore's `FilteredMigrationsRepository` uses `str_starts_with`), or loop over all CoreShop bundles. Also mark them in `InstallCommand`.

## Install commands / installer

### B-71 — `coreshop:install` returns 0 on failure and misclassifies sub-command errors
- **Where:** `Command/InstallCommand.php:79-106`, `Installer/Executor/CommandExecutor.php:46-59`.
- **Problem:**
  - Exit code `1` (the generic `Command::FAILURE`) is turned into `Symfony\Component\Process\Exception\RuntimeException('…permission error.')`. `InstallCommand` catches only that, sets `$errored` and carries on: it runs the remaining steps on a broken base, marks the migrations (TODO-1 **C-10**) and returns `0`. CI and `Installer::install()` treat a failed install as success.
  - Any other exit code throws a plain `\Exception` that is not caught. The install then aborts without the "some error occurred" message. Exceptions thrown inside a sub-command propagate unwrapped (`setCatchExceptions(false)`) and also bypass the handler.
  - `CommandExecutor` uses `symfony/process` exception classes, but neither `composer.json` declares `symfony/process` (it is only available transitively through Pimcore).
- **Fix:** Return `Command::FAILURE` when `$errored`. Throw one dedicated `CommandFailedException` (with command name and exit code) for any non-zero code and catch it in `InstallCommand`. Stop after the first failing step, because later steps depend on earlier ones.

### B-72 — `Installer::install()` ignores the result of `coreshop:install` and passes a malformed argument
- **Where:** `Installer.php:66-76`.
- **Problem:**
  - `['--no-interaction' => true, '--application-name coreshop']` adds the string as list element `0`. `ArrayInput` treats the integer key as a positional argument and stores it silently. The option is never passed **(reproduced: `inst.php` → command runs, the value ends up as `arguments[0]`)**. `coreshop:install` has no such option anyway.
  - The exit code of `$application->run()` is discarded and `parent::install()` then marks the bundle as installed. Pimcore's `bundle:install` (CLI or admin UI) reports success after a failed install. Output goes to a fresh `ConsoleOutput` (stdout), so admin-UI installs show nothing.
- **Fix:** Remove the bogus element. Check the return value (`if (0 !== $code) throw new InstallationException(...)`). Pass a `BufferedOutput` and attach its content to the exception or log it.

### B-73 — Dangerous, dead "drop tables" path in `DatabaseSetupCommandsProvider`, and a misleading reset prompt
- **Where:** `Installer/Provider/DatabaseSetupCommandsProvider.php:44-53,55-73,76-79`.
- **Problem:**
  - `getRequiredCommands()` builds `$commands['coreshop:resources:drop-tables' … '--force']` when `--no-interaction` is set, then discards it and returns `setupDatabase()`. Today this is dead code. A "fix" that returns `$commands` merged with the setup commands would drop all CoreShop tables on every non-interactive install, including `Installer::install()` (TODO-1 **C-11** path).
  - When the user answers "y", `drop-tables` runs `SchemaTool::dropSchema()` for **all** `coreshop.*` resources: stores, countries, carriers, payment providers, rules, payments, price rules, voucher codes and so on. The Pimcore objects (orders, products, customers) stay and keep dangling IDs. The prompt only says "erase your CoreShop Tables".
  - Schema detection looks only for `coreshop_store`. A half-finished earlier install (some tables present, not `coreshop_store`) makes `create-tables` fail with "table exists" **(plausible)**.
- **Fix:** Delete the dead block. Make the warning list the consequence ("all CoreShop configuration and payment records are deleted; orders/products keep dangling references"). Require `--force-reset` in non-interactive mode instead of a prompt. Use `SchemaTool::getUpdateSchemaSql()` to detect partial schemas.

### B-74 — `CommandDirectoryChecker` prints empty paths and is stateful
- **Where:** `Installer/Checker/CommandDirectoryChecker.php:25,39-46,60-66,78-81`.
- **Problem:** On failure the messages use `realpath($directory)`. For a directory that does not exist (the `mkdir` failure case) this is `false`, so the message reads `Create directory "" …`. `$name` is an uninitialised typed property: calling `ensureDirectoryExists()` before `setCommandName()` gives `Error: must not be accessed before initialization`. The public shared service keeps the last command name between calls.
- **Fix:** Use `$directory` itself in messages. Pass the command name as a parameter instead of using a setter.

### B-75 — `SetupStatesCommand` crashes for NO and SG, stores numeric ISO codes for IS and PA, and loses all work on one bad row
- **Where:** `Command/SetupStatesCommand.php:104-111,121-164,171,173-180`. **(dependent)** `AddressBundle/Resources/config/doctrine/model/State.orm.xml` (`isoCode` `length="4"`), `vendor/rinvex/countries/resources/divisions/{no,sg,is,pa}.json`.
- **Problem:**
  - The Rinvex division keys for Norway (`NO-01` … `NO-22`) and Singapore (`SG-01` … `SG-05`) have 5 characters, but `coreshop_state.isoCode` is `VARCHAR(4)`. With MySQL strict mode the single `flush()` at `:171` fails with "Data too long". The whole run is lost, including states of other countries given in the same call. Without strict mode the code is truncated to `NO-0`. The duplicate check (`findOneBy(['isoCode' => 'NO-01'])`) then never matches, so every run inserts all states again **(verified against the vendor data: 26 keys longer than 4)**.
  - `is.json` and `pa.json` are JSON **lists**, not maps. `foreach ($divisions as $isoCode => $division)` yields `0, 1, 2, …`, and these become the stored ISO codes.
  - Two divisions have `name: null` (`TM` `X-`, `PH` `MNL`). They are skipped correctly, but `!$division['name']` throws a warning if the key is missing.
  - The command returns `Command::SUCCESS` even when no country was found or every country was skipped. Scripts cannot detect failure.
  - Duplicate countries make `findByCode()` throw: see `AddressBundle/TODO-2-BUGS.md` B-09 **(dependent)**.
- **Fix:** Normalise the code (`str_contains($isoCode, '-') ? substr($isoCode, strpos($isoCode, '-') + 1) : $isoCode`) and validate `strlen <= 4` (or widen the column with a migration). Skip divisions with a non-string key and warn. `flush()` per country inside try/catch, then `clear()`. Return `Command::FAILURE` when nothing was processed or an error occurred.

## Fixtures / test support

### B-76 — Demo fixtures are not re-runnable and depend on references that are only set on first creation
- **Where:** `Fixtures/Data/Demo/ProductUnitFixture.php:41-92`, `ProductWithUnitFixture.php:41-122`, `CarrierFixture.php:58-80`, `ShippingRuleGroupFixture.php:53-79`, `TaxRuleGroupFixture.php:57-80`, `AbstractProductFixture.php:562-568,647`. **(dependent)** `ProductBundle/Resources/config/doctrine/model/ProductUnit.orm.xml:12` (`name` unique).
- **Problem:**
  - `ProductUnitFixture` has no existence guard and `name` is unique. A second `coreshop:install:demo` fails with `UniqueConstraintViolationException`.
  - The "guarded" fixtures (`if (!count(findAll()))`) set their reference (`taxRule`, `carrier`, `shippingRule0..2`) only inside the guard. If a tax rule group already exists (for example created by the merchant before loading the demo), `CarrierFixture`, `ShippingRuleGroupFixture` and every product fixture call `getReference('taxRule')`, which throws `OutOfBoundsException`.
  - `ProductWithUnitFixture` and `ProductWithAttributesFixture` have no guard, so every run adds 10 + 5 products.
  - `ProductWithUnitFixture` uses `unit-*` references but does not declare `ProductUnitFixture` in `getDependencies()`. It works only because the loader orders fixtures without dependencies first.
- **Fix:** In the `else` branch, set the reference from the existing entity (as `StoreFixture` does). Guard `ProductUnitFixture` with `findOneBy(['name' => …])`. Add `ProductUnitFixture::class` to `ProductWithUnitFixture::getDependencies()`.

### B-77 — `AttributeGroupsFixture` overwrites its sorting counter
- **Where:** `Fixtures/Data/Demo/AttributeGroupsFixture.php:75,85,97,109,128`.
- **Problem:** The inner `foreach ($attributes as $index => $attributeKey)` reuses `$index`, the outer group-sorting counter. The groups get sorting `10`, `12`, `13` instead of `10`, `20`, `30`.
- **Fix:** Rename the inner key (`$position`).

### B-78 — `ProductWithUnitFixture` casts before multiplying, so prices are whole currency units
- **Where:** `Fixtures/Data/Demo/ProductWithUnitFixture.php:99`.
- **Problem:** `(int) $faker->randomFloat(2, 200, 400) * $decimalFactor` truncates the float first. `AbstractProductFixture.php:646` correctly writes `(int) ($faker->randomFloat(...) * $decimalFactor)`.
- **Fix:** Add the parentheses.

### B-79 — `NotificationRulesFixture` silently drops all rules on any error and persists unvalidated form data
- **Where:** `Fixtures/Data/Application/NotificationRulesFixture.php:52-101`.
- **Problem:** The inner `catch` rethrows (`throw $ex;` followed by a dead comment). The outer `catch (\Exception)` returns **before** `flush()`, so one bad rule discards all rules without any output. `$form->submit($rule)` is never checked with `isValid()`, so invalid or partial `NotificationRule` objects can be persisted. A missing e-mail document becomes `null` without a warning, so the rule never sends mail. `json_decode()` without `JSON_THROW_ON_ERROR` returns `null` for an invalid file, and `foreach (null)` only warns.
- **Fix:** Remove the swallow-all `catch`. Use `JSON_THROW_ON_ERROR`. Skip and log invalid forms (`if (!$form->isValid()) { … continue; }`). Warn when an e-mail document is missing.

### B-80 — `CountryFixture` crashes on non-ICU language codes and picks a random language as fallback
- **Where:** `Fixtures/Data/Application/CountryFixture.php:95-99,116-120`.
- **Problem:** `Languages::getAlpha3Code()` throws `MissingResourceException` for Pimcore languages that are not plain ICU codes (for example `en-GB` with a dash, or custom codes). The whole application fixture set aborts **(plausible)**. For languages Rinvex has no translation for, `getTranslation()` returns `current($translations)` (the first entry in the file), not English.
- **Fix:** Wrap `getAlpha3Code()` in try/catch and fall back to `eng`. Use `$country->getName()` (English) when the requested translation is missing.

### B-81 — `NotificationRuleListener::clear()` deletes the whole directory instead of the notification files
- **Where:** `Test/Service/NotificationRuleListener.php:43-56`.
- **Problem:** `$fs->remove($file->getPath())` passes the **directory** of the file. The first match removes `%kernel.cache_dir%/notifications` with everything in it **(reproduced: `nrl.php` → directory and an unrelated `keep.txt` are gone)**. It works for the Behat suite only by accident.
- **Fix:** `$fs->remove($file->getPathname())`, or `$fs->remove(iterator_to_array($finder))`.

## Already tracked in other TODO files

### B-82 — Already tracked in other TODO files (cross-references only, not re-described)
- `EventListener/CustomerSecurityValidationListener.php:39-41` — inverted request check, so the e-mail uniqueness check never runs on admin saves: see `Component/Customer/TODO-2-BUGS.md` **B-05**. The `$className` argument (`:33`, `listeners.yml:79`) is unused.
- `EventListener/CustomerCompanyRelationListener.php:42-57` — only published customers are reset: see `Component/Customer/TODO-2-BUGS.md` **B-08**.
- `Storage/CookieStorage.php` — `Component/Currency/TODO-2-BUGS.md` **B-03** covers the crafted-cookie crash. **Correction to that item:** with Symfony 7.4, `new Cookie($name, '')` is sent as a deletion (`=deleted; expires=<past>; Max-Age=0`), and the default `Cookie` has `HttpOnly` and `SameSite=Lax` **(reproduced)**. The real cookie problem is B-30 below.
- `Checkout`/`doCheckoutAction` GET + CSRF: `FrontendBundle/TODO-2-BUGS.md` **B-03**. Payment amount units: `PaymentBundle/TODO-2-BUGS.md` **B-05**. Inventory `cancel()` logic: `InventoryBundle/TODO-2-BUGS.md` **B-01**.
- **Also (`CustomerTransformerController` 500s on missing objects; duplicate search can load every company (dependent)):**
  - **Where:** `Controller/CustomerTransformerController.php:40-46,64-101,170-190,213-236`.
  - **Problem / Fix:** **already tracked** as Customer Component `TODO-2-BUGS.md` **B-06** (null `find()` → `TypeError` not caught by `catch (\Exception)`; empty `value` ⇒ `name LIKE '%%'` hydrates all companies; unescaped `%`/`_`; wrong `$type` handling). Cross-reference only.
- **Also (`CurrencyCollector` queries the DB in its constructor and `getCurrencies()` can TypeError after reset (dependent, dev-only)):**
  - **Where:** `Collector/CurrencyCollector.php:21-29,37-40,62-65`.
  - **Problem / Fix:** **already tracked** as Currency Component `TODO-2-BUGS.md` **B-14** (work belongs in `collect()`, store scalars not entities, `?? []` guard, catch `\Throwable`). Cross-reference only.
- **Also (Report table names built from locale/class ids without validation (dependent)):**
  - **Where:** `CategoriesReport.php:104,135`, `ProductsReport.php:105,154`, `ManufacturerReport.php:111` (locale concatenated into `object_localized[_query]_<classId>_<locale>`).
  - **Problem / Fix:** **already tracked** as LocaleBundle `TODO-2-BUGS.md` **B-08** (locale codes concatenated into SQL identifiers; case-sensitive table-name failures). Cross-reference only. Note the locale value itself is produced by `PimcoreLocaleContext` (validated against `Tool::isValidLanguage`), so the risk is low in practice but the identifiers are still unquoted.
