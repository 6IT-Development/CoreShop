# Customer Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). Items marked **(dependent)** live outside this component, but they come from how its contexts, models or repository contracts are implemented, wired or used, and they should be fixed together with it.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.
Items are roughly ordered by impact.

Already tracked elsewhere, and **not repeated here**: CustomerBundle **B-04** (`FixedCustomerContext` never reset, and asked before the token context), **B-06** (`findUniqueByEmail()` returns an arbitrary row), **B-07** (`findCompanyByName()` / `findByNewsletterToken()` return `null` on duplicates), **B-08** (`Company::isEqualTo()`), **B-14** (`composer.json`); FrontendBundle **B-04** (`RegisterController::getCustomer()`). Fix those together with the items here that mention them.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Rule conditions that read customers

### B-01 — (dependent) The customer and customer-group condition checkers trust their input: nullable groups, missing configuration keys and loose comparisons
- **Where:**
  - `Component/Core/Cart/Rule/Condition/CustomerGroupsConditionChecker.php:36-38`, `Component/Core/Product/Rule/Condition/CustomerGroupsConditionChecker.php:43-45`, `Component/Core/Payment/Rule/Condition/CustomerGroupsConditionChecker.php:43-45`, `Component/Core/Shipping/Rule/Condition/CustomerGroupsConditionChecker.php:45-47`
  - `Component/Core/{Cart:35,Product:38,Payment:42,Shipping:44}/Rule/Condition/CustomersConditionChecker.php`
  - Contract: `Model/CustomerInterface.php:49` `getCustomerGroups(): ?array`
  - Configuration: `CoreBundle/Form/Type/Rule/Condition/{CustomerGroups,Customers}ConfigurationType.php` (a `CollectionType` of the default `TextType`, with no constraints)
- **Problem:**
  - `foreach ($customer->getCustomerGroups() as …)` runs over a value the interface allows to be `null`. `Component/Core/Model/User.php:43` guards it with `is_array()`, but the four checkers do not. A custom `CustomerInterface` implementation (or a Pimcore getter override) that returns `null` triggers "foreach() argument must be of type array|object" on every rule evaluation. In `debug` mode that warning becomes an `ErrorException`, so every product page or cart with such a rule returns 500.
  - `$configuration['customerGroups']` / `$configuration['customers']` are read without `?? []`. A rule whose condition configuration does not contain the key (imported through the API, fixtures, data migrations, or older rules) produces "Undefined array key" and then `in_array(): Argument #2 ($haystack) must be of type array, null given`, which is a `TypeError`. The callers catch only "no price found" exceptions, so the page fails.
  - The ids are stored as **strings** (`TextType` entries) and compared with loose `in_array()`. That works by accident for `12 == "12"`, but there is no validation: an admin can store `"abc"` or `""`. With an empty-string entry, an unsaved customer (`getId() === null`) matches, because `null == ""` is `true` in PHP.
- **Fix direction:**
  - Read the configuration as `array_map('intval', $configuration['customerGroups'] ?? [])`, and compare strictly (`in_array($id, $ids, true)`) after the cast. Return `false` early for a `null` id.
  - Iterate `$customer->getCustomerGroups() ?? []`.
  - Constrain the configuration form: `entry_type: IntegerType` (or a `ChoiceType` of existing ids) with `Positive`, and `Count(min: 1)`.
  - Better: extract the shared logic into one service (see TODO-3 **I-08**), so the four copies cannot drift.

### B-02 — (dependent) "Guest" means three different things in the three `GuestConditionChecker`s, and back-office customers count as guests
- **Where:** `Component/Core/Payment/Rule/Condition/GuestConditionChecker.php:34-44`, `Component/Core/Shipping/Rule/Condition/GuestConditionChecker.php:36-46`, `Component/Core/Product/Rule/Condition/GuestConditionChecker.php:34-38`
- **Problem:**
  - Payment and shipping: **no customer at all** → `true` ("is guest"). Product: no customer → `false` ("is not guest"). The same "Guest" condition in the admin therefore gives opposite answers for an anonymous visitor, depending on the rule type. A product price rule "guests only" never applies to anonymous visitors (before checkout the cart has no customer), while a shipping rule "guests only" applies to every cart that has not reached the customer step yet.
  - All three define a guest as "customer without user". Customers created in the back office (`OrderBundle/Controller/CustomerCreationController.php`) have no user either, so B2B customers fall under "guest only" rules. This is the same modelling gap as TODO-1 **C-01**.
  - The checkers ignore their `$configuration`, so a rule cannot express "**not** guest".
- **Fix direction:** introduce an explicit guest flag or customer type on `CustomerInterface` (TODO-1 C-01), document "no customer = guest" (or not) once, and implement it in one shared helper. Add a `guest: bool` configuration (default `true`) so "registered only" rules are possible. Add unit tests for "no customer", "guest", "registered" and "managed" for all three rule types.

## Contexts

### B-03 — `RequestBased\CustomerContext` turns any `\UnexpectedValueException` from a resolver into "customer not found"
- **Where:** `Context/RequestBased/CustomerContext.php:35-42`, `:49-57`
- **Problem:** the `try` wraps both `getMainRequest()` (which throws `\UnexpectedValueException` itself) **and** `$this->requestResolver->findCustomer($request)`. A resolver that fails with an `\UnexpectedValueException` (or a subclass of it, which is common in token decoding, JWT and serializer code) is silently reported as "no customer". The visitor is then treated as anonymous instead of the error being logged. Today no resolver is registered (CustomerBundle I-09), so it is latent until someone adds one.
- **Fix direction:** throw `CustomerNotFoundException` directly from `getMainRequest()` (CustomerBundle I-05) and remove the `try`/`catch` around the resolver call, so only the documented "not found" exception means "not found".

### B-04 — (dependent) Customer-bound state is cached in shared services and never reset, so long-running processes mix customers
- **Where:**
  - `Component/StorageList/Core/Context/StoreBasedStorageListContext.php:30,40-43,56,62`: memoises `$this->storageList`, after `CoreContextProvider::provideContextForStorageList()` (`Component/StorageList/Core/Provider/CoreContextProvider.php:51-54`) has written the current customer into it.
  - `Context/FixedCustomerContext.php:25` (CustomerBundle B-04).
  - No class in `src/` implements `Symfony\Contracts\Service\ResetInterface`, and no service is tagged `kernel.reset`.
- **Problem:** in PHP-FPM every request gets a new container, so this does not show. In a Messenger consumer, a long-running CLI command (imports, mass order creation, cart expiry jobs that touch carts of several customers) or a worker runtime (FrankenPHP worker mode, RoadRunner), the first resolved cart or wishlist (and the customer written into it) is returned for every later customer handled by the same process.
- **Fix direction:** implement `ResetInterface` on `StoreBasedStorageListContext` (`reset(): void { $this->storageList = null; }`) and on `FixedCustomerContext`, and tag them `kernel.reset` (or rely on autoconfiguration). Audit the other memoising contexts (store, currency, locale) together with this, because they feed the same `ShopperContext`.

## Dependent listeners and controllers

### B-05 — (dependent) `CustomerSecurityValidationListener` skips the e-mail uniqueness check for every HTTP request except Pimcore edit and preview requests, so admin saves are never checked
- **Where:** `CoreBundle/EventListener/CustomerSecurityValidationListener.php:39-41`, `:53-70`; `CoreBundle/Resources/config/services/listeners.yml:75-81` (only `pimcore.dataobject.preUpdate`)
- **Problem:**
  - `if (hasCurrentRequest() && !isFrontendRequestByAdmin()) return;` returns early for a normal admin "save object" request (`/admin/object/save` has none of the `pimcore_editmode`/`pimcore_preview`/`pimcore_admin`/… parameters that `RequestHelper::isFrontendRequestByAdmin()` looks for). So the check runs only without a request (CLI, Messenger) and for editmode/preview requests. An admin can give a registered customer the e-mail address of another registered customer.
  - It listens only to `preUpdate`, not `preAdd`, so a newly created customer is never checked.
  - Consequence: two registered customers with the same e-mail. `findCustomerByEmail()` then returns an arbitrary one (CustomerBundle B-06), and `CoreBundle/Validator/Constraints/RegisteredUserValidator.php:47` validates against whichever row comes first. With `login_identifier: email`, `CustomerManager::persistCustomer()` (`CoreBundle/Customer/CustomerManager.php:86-88`) copies the e-mail into the user's login identifier, so the duplicate can propagate to the user objects.
  - The constructor argument `$className` (from `%coreshop.model.customer.class%`) and the `use Pimcore\Model\DataObject\Listing;` import are unused.
- **Fix direction:** **verify the intended scope first** (the most likely intent is "skip storefront requests, because the forms validate there"). Then express it directly, for example with `PimcoreContextResolver::matchesPimcoreContext($request, PimcoreContextResolver::CONTEXT_ADMIN)`, or simply run the check whenever `$object->getUser() !== null`, regardless of the request. Also listen to `pimcore.dataobject.preAdd`. Remove the dead argument and import. Add a functional test that saves a duplicate e-mail through the admin.

### B-06 — (dependent) `CustomerTransformerController` turns missing objects into 500s, and the duplicate search can load every company
- **Where:** `CoreBundle/Controller/CustomerTransformerController.php:40-46`, `:64-101`, `:170-190`, `:213-236`
- **Problem:**
  - `dispatchExistingAssignmentAction()` and `dispatchNewAssignmentAction()` take `find()` results and only annotate them with `/** @var CustomerInterface */`. For a deleted or unknown id, `null` is passed to `moveCustomerTo…(CustomerInterface $customer, …)`, which throws a `TypeError`. That is an `\Error`, not an `\Exception`, so `catch (\Exception $e)` does not catch it, and the admin JS gets an HTML 500 page instead of `{success: false}`. `$customer->getCompany()->getId()` (`:228`) has the same problem.
  - `checkForNameDuplicatesAction()`: `value=` (empty string) is not `null`, so it runs `name LIKE '%%'` and hydrates **every** company. `%` and `_` in the input are not escaped.
  - `getEntityDetailsAction()` with an unknown `$type` returns `success: true` with `data: null`. For an invalid company it says "Invalid **Customer** Object".
- **Fix direction:** check `instanceof` and return a JSON error before calling the helper. Catch `\Throwable` (and log it) at the controller boundary. Require at least 2–3 characters for the duplicate search, escape `%`/`_` (`addcslashes($value, '%_\\')`), and add `setLimit(50)`. Validate `$type` against `['customer', 'company']` (route requirement). Fix the message. **Verify** that the routes require a CoreShop admin permission (they only require an admin login today), because they move customers between companies.

### B-07 — (dependent) `StorageMultiListController` dereferences a `null` customer for users without a customer object
- **Where:** `StorageListBundle/Controller/StorageMultiListController.php:136-142`; `Component/Core/Context/ShopperContext.php:144` (`'customer' => $this->hasCustomer() ? … : null`)
- **Problem:** the action only requires `IS_AUTHENTICATED_REMEMBERED`. A CoreShop user whose customer relation is empty or points to an unpublished customer (`TokenBasedCustomerContext` then throws `CustomerNotFoundException`) gets `null` as `'customer'`, and `->getId()` on it throws an `Error` (500) as soon as the list form is submitted.
- **Fix direction:** read the customer once and deny access when it is `null` (`$customer = $context['customer'] ?? throw new AccessDeniedException()`), then compare ids.

### B-08 — (dependent) `CustomerCompanyRelationListener` only resets published customers, and can leave a company half-deleted
- **Where:** `CoreBundle/EventListener/CustomerCompanyRelationListener.php:34-57`
- **Problem:**
  - `$this->customerRepository->getList()` excludes unpublished objects by default, so unpublished (for example deactivated) customers keep `company_only` / `own_and_company` after the company is deleted. `CustomerAddressAllocator` falls back to the customer's own addresses, so the storefront survives. But the admin field then holds a value that `AddressAccessOptionsProvider` no longer offers, and the next admin save shows an empty select.
  - Each customer is saved individually inside `preDelete`. If one save fails (validation, mandatory field), the exception aborts the company delete after some customers were already changed.
  - `CustomerRepositoryInterface` (the base component interface) is injected, but the code relies on `Core\Model\CustomerInterface` methods (`getAddressAccessType()`) without an `instanceof` check.
- **Fix direction:** `$list->setUnpublished(true)`, filter with `addressAccessType != 'own_only'` in the query, guard with `instanceof CoreCustomerInterface`, and wrap the updates in a transaction (`Db::get()->transactional(...)`), or move the work to `postDelete` and log failures.
