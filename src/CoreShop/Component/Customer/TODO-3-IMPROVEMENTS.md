# Customer Component – TODO 3: Improvements (code quality, readability, logic, PHP 8.4 / Symfony 7.4 idioms)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
None of these are defects on their own. They make the component cleaner, safer, easier to test, or more idiomatic for the target stack.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.
Where an item extends a CustomerBundle TODO-3 item, the bundle item number is given. Do them together. Bundle items that already fully cover component files (**I-02** `readonly`/promotion, **I-03** empty-constructor formatting, **I-05** `CustomerNotFoundException`, **I-09** unused request-based layer, **I-11** composite recursion guard, **I-21** tests) are not repeated.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 language features and typing

### I-01 — Native types on the model interfaces, and remove the redundant declarations (extends bundle I-04)
- **Where:** `Model/{Customer,Company,CustomerGroup}Interface.php`, `Model/CustomerAwareInterface.php`, `Component/Core/Model/{Customer,Company}Interface.php`
- Bundle I-04 covers the setter return types, the `@return list<…>` docs and the `Gender` enum. Component-specific additions:
  - `getId(): ?int` is re-declared in `CustomerInterface:27`, `CompanyInterface:27` and `CustomerGroupInterface:25`, but `Component/Resource/Pimcore/Model/PimcoreModelInterface` already declares it. Remove the duplicates.
  - `CustomerInterface` and `CompanyInterface` extend both `ResourceInterface` and `PimcoreModelInterface`, but `PimcoreModelInterface` already extends `ResourceInterface`. Drop the redundant parent. **Correction to bundle I-04:** `CustomerGroupInterface` *does* reach `ResourceInterface` through `PimcoreModelInterface`, so there is no inconsistency to fix there.
  - `getCustomerGroups(): ?array` → `array` (return `[]` instead of `null`). That removes the `null` case behind TODO-2 **B-01** and the `is_array()` guard in `Component/Core/Model/User.php:43`.
  - `CustomerAwareInterface::setCustomer()` has no return type, while `Component/Core/Model/UserInterface` and `OrderInterface` inherit it. Add `: void` (or `: static`, matching the generated Pimcore setters) in the same BC release as bundle I-04.
- BC break for implementors: plan it for a minor/major and note it in `UPGRADE`.

### I-02 — `#[\Override]`, `final`, and narrower visibility
- Add `#[\Override]` to every implemented method in `Context/*` and `Context/RequestBased/*` (bundle I-01 lists the bundle side).
- `Context/CustomerNotFoundException.php:21` is the only non-`final` concrete class in the component, and nothing extends it. Make it `final`, or keep it open on purpose and document it as a base for more specific "not found" exceptions (see bundle I-05).
- Dependent classes with `protected` promoted properties in `final` classes: `CoreBundle/EventListener/CustomerSecurityValidationListener.php:30-34`, `CustomerCompanyRelationListener.php:29-31`. `protected` has no meaning there; use `private readonly`.
- `Component/Core/Notification/Rule/Condition/User/UserTypeChecker.php:24` and `Component/Core/Context/ShopperContext.php:20` are open classes with no subclasses in `src/`. Make them `final` (decorate through the interface), or document them as extension points.

### I-03 — Replace `Laminas\Stdlib\PriorityQueue` with a Symfony tagged iterator, and make the composites immutable
- **Where:** `Context/CompositeCustomerContext.php:22-41`, `Context/RequestBased/CompositeRequestResolver.php:23-43`; `CustomerBundle/DependencyInjection/Compiler/Composite{CustomerContext,RequestResolver}Pass.php`; `composer.json:29`
- Symfony 7.4 can inject the tagged services already sorted by priority: `public function __construct(#[AutowireIterator('coreshop.context.customer')] private readonly iterable $contexts) {}`. Combined with `#[AsTaggedItem(priority: …)]` (or the existing `AsCustomerContext` attribute mapped to the tag priority), this removes:
  - the mutable `addContext()` / `addResolver()` API (a context can no longer be added at runtime by accident),
  - the two compiler passes (or reduces them to aliases),
  - the `laminas/laminas-stdlib` dependency of this component entirely.
- If Laminas stays for BC, raise the constraint from `^3.6` to a version that declares PHP 8.4 support (`^3.20`), and fix the docblock: `@var PriorityQueue|CustomerContextInterface[]` should be `@var PriorityQueue<CustomerContextInterface, int>`.
- Keep `addContext()` as a deprecated method for one minor, so projects that call it from their own passes get a deprecation instead of a fatal.

### I-04 — Simplify `TokenBasedCustomerContext` with the nullsafe and `?? throw` operators
- **Where:** `Component/Core/Context/TokenBasedCustomerContext.php:35-51`
- The token is fetched three times, and the `/** @var */` cast duplicates the `instanceof` check just above it. Equivalent and shorter:
  ```php
  $user = $this->tokenStorage->getToken()?->getUser();
  if (!$user instanceof UserInterface) {
      throw new CustomerNotFoundException();
  }

  return $user->getCustomer() ?? throw new CustomerNotFoundException();
  ```
- Add `#[\Override]`, `private readonly`.

## Logic and API design

### I-05 — Stop using exceptions for control flow when asking "is there a customer?" (extends bundle I-10)
- **Where:** `Context/CustomerContextInterface.php:25`; `Component/Core/Context/ShopperContext.php:96-110`, `:141-150`; `Component/StorageList/Core/Provider/CoreContextProvider.php:51-54`; `FrontendBundle/Controller/CustomerController.php:350-365`
- Each `hasCustomer()` followed by `getCustomer()` walks the whole composite twice. For an anonymous visitor every walk creates and catches several `CustomerNotFoundException`s (one per context, plus the request-based layer, bundle I-09). `ShopperContext::getContext()` alone does this on every cart and price calculation.
- Add a non-throwing accessor, for example `CustomerContextInterface::findCustomer(): ?CustomerInterface` (implemented once in an abstract base or a trait as `try { return $this->getCustomer(); } catch (CustomerNotFoundException) { return null; }`, and natively in the composite). Use it in `ShopperContext::hasCustomer()` / `getContext()` and in the callers above.
- Adding a method to the interface is a BC break. Introduce it as a separate `OptionalCustomerContextInterface` first, or add it in the next major.

### I-06 — Make "guest" an explicit concept of the customer model
- **Where:** `Model/CustomerInterface.php`, `Repository/CustomerRepositoryInterface.php:26-32`, `Component/Core/{Payment,Shipping,Product}/Rule/Condition/GuestConditionChecker.php`, `CoreBundle/Customer/CustomerManager.php:66`
- Today "guest" is inferred from "has no user" in at least six places, with different handling of "no customer at all" (TODO-1 **C-01**, TODO-2 **B-02**). A backed enum on the model (`enum CustomerType: string { case Guest = 'guest'; case Registered = 'registered'; case Managed = 'managed'; }`) plus `CustomerInterface::getType(): CustomerType` gives every consumer the same answer, and lets back-office customers (`Managed`) be excluded from guest lookups.
- Then `findGuestByEmail()` / `findCustomerByEmail()` become one `findOneByEmailAndType(string $email, CustomerType $type)`, which also replaces the boolean-flag method `findUniqueByEmail(string, bool)` (bundle I-15).
- Document on the repository interface that an e-mail lookup is "lookup only, not proof of ownership" (bundle C-01).

### I-07 — Remove dead and duplicated repository API
- `Component/Core/Repository/CustomerRepositoryInterface.php:26` `findOneByEmailWithoutUser()` and its implementation (`CoreBundle/Pimcore/Repository/CustomerRepository.php:27-41`) have **no callers** in `src/`, and do the same as `findGuestByEmail()`. Deprecate it (`@deprecated` + `trigger_deprecation()`), then remove it.
- Add generics so static analysis knows what the finders return: `@extends PimcoreRepositoryInterface<CustomerInterface>` (if `PimcoreRepositoryInterface` gets a `@template`), and use them in the callers that today cast with `/** @var CustomerInterface $customer */` (`CoreBundle/Form/Type/GuestRegistrationType.php:99-102`, `CoreBundle/Controller/CustomerTransformerController.php:170-174`).

### I-08 — One shared matcher for the customer, customer-group and guest rule conditions
- **Where:** the 11 checkers listed in TODO-2 **B-01** / **B-02**
- They are four (customers), four (groups) and three (guest) near-copies. They already differ in small ways: the cart variant checks `instanceof CustomerGroupInterface`, the others `instanceof ResourceInterface`; guest handling differs (B-02).
- Extract a small `CustomerConditionMatcher` service (or a trait) with `matchesCustomers(?CustomerInterface, array $ids)`, `matchesGroups(?CustomerInterface, array $ids)`, `isGuest(?CustomerInterface)`. Each checker only extracts the customer from its subject (cart, `$params['customer']`, payable, shippable).
- With PHP 8.4 the group check becomes one expression:
  ```php
  $ids = array_map(intval(...), $configuration['customerGroups'] ?? []);

  return array_any(
      $customer->getCustomerGroups(),
      static fn (CustomerGroupInterface $group): bool => in_array($group->getId(), $ids, true),
  );
  ```

### I-09 — `User::getRoles()`: return a list, and build it without the nested loop
- **Where:** `Component/Core/Model/User.php:31-52`
- `array_unique()` keeps the keys, so the result can be a non-list array (`[0 => 'ROLE_USER', 2 => 'ROLE_X']`). Symfony accepts it, but it shows up as an object in the profiler and in any JSON or serializer output of the token. Wrap it in `array_values()`.
- Shorter and without the inline `/** @var */`:
  ```php
  $groupRoles = array_map(
      static fn (CustomerGroupInterface $group): array => $group->getRoles() ?? [],
      $customer->getCustomerGroups() ?? [],
  );

  return array_values(array_unique(array_merge($roles, ...$groupRoles)));
  ```
- `getRoles()` is called on every token refresh and loads the customer and all its groups. Consider memoising it per instance (the user object is reloaded each request anyway).

### I-10 — Typed signatures and an enum for the address access types
- **Where:** `Component/Core/Customer/Address/AddressAccessOptionsProvider.php:33-77`, `Component/Core/Customer/Allocator/CustomerAddressAllocator{,Interface}.php`
- `getOptions($context, $fieldDefinition)` and the other two methods are untyped, with `@param` docblocks. Pimcore's `SelectOptionsProviderInterface` declares `array $context, Data $fieldDefinition`. Match it, add `#[\Override]`, and delete the docblocks.
- The three `ADDRESS_ACCESS_TYPE_*` string constants are a closed set: make them a backed enum (`AddressAccessType: string`). The allocator's `if` chain plus a generic `throw new \Exception(...)` becomes a `match` that is exhaustive by construction, and the options provider builds its list from `AddressAccessType::cases()`.

### I-11 — Small readability fixes in the dependent listeners and checkers
- `Component/Core/Notification/Rule/Condition/User/UserTypeChecker.php:34-55`: a loop over a one-element `$paramsToExist` array. The whole method is `return $subject instanceof CustomerInterface && ($params['type'] ?? null) === $configuration['userType'];`. The four `TYPE_*` constants are a natural backed enum.
- `StorageListBundle/Core/EventListener/StorageListBlamerListener.php:53-58`, `:60`: the variable and parameter named `$user` hold a **customer**. Rename them. The `return null;` after the `try`/`catch` at `:83` is unreachable.
- `CoreBundle/EventListener/CustomerSecurityValidationListener.php`: remove the unused `$className` argument and `Listing` import (with TODO-2 **B-05**), and replace `count($listing->getObjects()) === 0` with `$listing->setLimit(1)` + `$listing->getTotalCount()` (no hydration).
- Register the listeners with `#[AsEventListener(event: 'pimcore.dataobject.preUpdate')]` instead of YAML tags, so the event and the method name stay next to the code.

## Packaging and documentation

### I-12 — `composer.json` and README for the component (extends bundle I-17)
- Bundle B-14 / I-17 cover the missing requires, the `php` constraint and phpstan 2.x. Component-specific:
  - Declare `"php": "^8.4"`, `symfony/http-foundation: ^7.4`, `symfony/security-core: ^7.4` (only needed while `Company` implements `EquatableInterface`; it goes away with bundle B-08), `coreshop/locale` (because `CustomerInterface extends LocaleAwareInterface`).
  - Drop `laminas/laminas-stdlib` with I-03.
  - `six-it/pimcore-source: ^2.0` is behind the root constraint (`^2.1`). Align it.
- `README.md` describes CoreShop in general, not the component. Document the extension points: the `coreshop.context.customer` tag and its priority order (token-based, fixed, request-based), the `coreshop.context.customer.request_based.resolver` tag, the `AsCustomerContext` / `AsRequestResolverBasedCustomerContext` attributes, and the guest contract once I-06 lands.
