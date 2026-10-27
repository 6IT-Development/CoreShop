# CoreBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items (Symfony 8, Doctrine ORM 3, data-fixtures 2). None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as **Also (…)** bullets.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(reproduced)** were confirmed with a PHP 8.4.27 script against the vendored classes (the scripts lived in a session scratchpad and are not part of the repo).

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — (reproduced) All 9 custom constraints use the deprecated options-array constructor; 6 have uninitialised typed message properties
- **Where:** `Validator/Constraints/{AddToCartAvailability,AddToCartMinimumQuantity,AddToCartMaximumQuantity,CartStockAvailability,CartMinimumQuantity,CartMaximumQuantity,UniqueCustomer,RegisteredUser,QuantityRangePriceCurrencyAware}.php`; YAML usage in `Resources/config/validation/{AddToCart,Order,Customer}.yml`. Dependent: `ProductQuantityPriceRulesBundle/Form/Type/ProductQuantityRangeType.php:61` (`new $constraintClass(['groups' => ...])`, configured by `Resources/config/pimcore/coreshop/coreshop_product_quantity_price_rules.yml:9-15`).
- **Problem:** loading the CoreBundle YAML mappings triggers, for each of the 8 YAML constraints, *"Since symfony/validator 7.3: Using constraints not supporting named arguments is deprecated"* and *"Since symfony/validator 7.4: Support for evaluating options in the base Constraint class is deprecated"*. `QuantityRangePriceCurrencyAware` triggers the 7.4 message through `ProductQuantityRangeType`. All reproduced in `scratchpad/t4.php`. In Symfony 8 the base-class option evaluation is removed, so these constraints stop working. In addition, `public string $message;` (and `messageBelowMinimum`, `messageAboveMaximum`, `messageEmail`, `messageUsername`) has no default. `new AddToCartAvailability()` without options gives `Error: Typed property ... must not be accessed before initialization` (reproduced).
- **Fix:** give each constraint an explicit constructor:
  ```php
  #[\Attribute(\Attribute::TARGET_CLASS)]
  final class AddToCartAvailability extends Constraint
  {
      #[HasNamedArguments]
      public function __construct(
          public string $message = 'coreshop.cart_item.not_sufficient_stock',
          ?array $groups = null,
          mixed $payload = null,
      ) {
          parent::__construct(null, $groups, $payload);
      }
      #[\Override] public function getTargets(): string { return self::CLASS_CONSTRAINT; }
  }
  ```
  Put the translation keys from the YAML in as defaults. The YAML keeps working, because named arguments are matched by key. In `ProductQuantityRangeType`, use `new $constraintClass(groups: $constraint['groups'])`. Drop `validatedBy()` aliases (I-14).

### I-02 — Form API leftovers: `GuestAddressType::buildForm()` without `: void`, dead `getExtendedType()`
- **Where:** `Form/Type/Checkout/GuestAddressType.php:31`.
- **Problem:** this appears in the container deprecation log (`App_KernelDevDebugContainerDeprecations.log`): *Method "AbstractType::buildForm()" might add "void" as a native return type*. It is the only form type in the slice without it. The class also has no `configureOptions()` / `data_class`, while `checkout.yml:69-74` passes it two constructor arguments it does not have (they are silently dropped).
- **Fix:** add `: void`. Either extend `AbstractResourceType`, so the passed `dataClass` and validation groups are used, or remove the arguments from `checkout.yml`.
- **Also (`PaymentProviderTypeExtension::getExtendedType()` is a leftover from Symfony 4):**
  - **Where:** `Form/Extension/PaymentProviderTypeExtension.php:35-38`.
  - **Fix:** delete it. Only `getExtendedTypes(): iterable` is used. Also use `iterable` as the return type, like the other extensions.

### I-03 — Remove dead or legacy code and config
- **Where / what:**
  - `Resources/config/pimcore/security.yml`: not imported anywhere (`pimcore/config.yml` imports admin/coreshop/checkout/workflow/migrations only). It is written in Symfony 5 syntax: `anonymous: -`, `logout.success_handler`, `IS_AUTHENTICATED_ANONYMOUSLY`. It also uses `pimcore.security.encoder_factories` pointing to the service `coreshop.security.user.password_encoder_factory`, which does not exist anywhere in CoreShop. The same `encoder_factories` block is **active** in `pimcore/config.yml:19-22`; Pimcore 11 accepts the key but only uses `password_hasher_factories` (`PimcoreCoreExtension.php:243-248`), so it is a no-op. Delete the file and the block, or migrate them to `password_hasher_factories`.
  - `docs/01_Getting_Started/00_Installation.md:70`: `IS_AUTHENTICATED_ANONYMOUSLY` no longer exists in Symfony 7.4 (`AuthenticatedVoter` constants). No voter supports it, so the localhost rule denies everyone. Use `PUBLIC_ACCESS`.
  - `EventListener/ShopUserLogoutHandler.php:27,29-34`: import of the removed `LogoutSuccessHandlerInterface`, a commented-out `implements`, the `TOOD` comment.
  - `Security/ObjectUserProvider.php:35-38`: `loadUserByUsername()` (removed from the interface in Symfony 6).
  - `Storage/SessionStorage.php`: registered public service, never used.
  - `Customer/CustomerAlreadyExistsException.php`: never thrown (see TODO-2 **B-10**).
  - `Event/CustomerRegistrationEvent.php`: `$data` is always `[]`, and `$customer` duplicates `GenericEvent::$subject`.

### I-04 — Use `#[AsCommand]` and modern constructor style on all commands
- **Where:** `Command/*.php` (`configure()` with `setName()`/`setDescription()`), `Resources/config/services/commands.yml`.
- **Problem:** Works on 7.4 (lazy loading comes from the `command:` tag attribute), but the name is declared twice (tag + `setName`). For `list`/`help` without instantiation the tag also needs a `description`. `SetupStatesCommand` uses mutable `private` promoted properties.
- **Fix:** `#[AsCommand(name: …, description: …)]`, drop `setName`/`setDescription` and the `command:` tag attribute (autoconfigure). Use `private readonly` promoted properties and `#[\Override]` on `configure`/`execute`/`initialize`. From 7.3 on, simple commands like `InstallDemoCommand`, `InstallFixturesCommand`, `MigrateCommand` and `SetupStatesCommand` can be invokable (`__invoke(#[Argument] string $countries, #[Option] bool $activateCountry = false, SymfonyStyle $io)`). The parent `Command` is then optional. See also `FrontendBundle/TODO-3-IMPROVEMENTS.md` (same advice for `coreshop:frontend:install`).

### I-05 — Run nested commands without re-entering `Application::run()` and without mutating global application state
- **Where:** `Command/AbstractInstallCommand.php:41-52`, `Command/MigrateCommand.php:40-47`, `Command/MigrationGenerateCommand.php:36-43`, `Installer/Executor/CommandExecutor.php:38-82`.
- **Problem:** The code calls `$application->run()` again from inside a running command and permanently switches the shared application to `setCatchExceptions(false)` / `setAutoExit(false|true)`. Uncaught exceptions then reach the user as raw PHP errors. `getApplication()` can be `null` in `MigrateCommand`/`MigrationGenerateCommand` (no null check, PHPStan level max). `getDefaultParameters()` checks `hasOption('env')` twice (dead ternary). `--no-debug` is ignored for an already booted kernel.
- **Fix:** Use `$this->getApplication()->find($name)->run(new ArrayInput($args), $output)` (or `doRun()`, as the Symfony docs suggest for calling other commands). Do not touch `setCatchExceptions`/`setAutoExit`. Set `$input->setInteractive(false)` explicitly instead of copying options.

### I-06 — `doctrine/data-fixtures` 2.0 forward compatibility
- **Where:** 15 calls of `getReference()`/`hasReference()` without the `$class` argument: `CountryFixture.php:67,191-196`, `CarrierFixture.php:69`, `ProductWithUnitFixture.php:66,72,79`, `ShippingRuleGroupFixture.php:56,62,67,72`, `TaxRuleGroupFixture.php:72`, `AbstractProductFixture.php:647`. **(dependent)** `vendor/doctrine/data-fixtures/src/AbstractFixture.php:89-125`.
- **Problem:** Each call triggers "Argument $class of getReference() will be mandatory in 2.0". `composer.json` still allows `doctrine/data-fixtures ^1.5`, but versions before 1.6 do not have the `$class` parameter.
- **Fix:** Pass the class (`$this->getReference('taxRule', TaxRuleGroupInterface::class)`) and raise the constraint to `^1.6 || ^2.0`.

### I-07 — Mapping cleanup / ORM 3 forward compatibility
- **Where:** `Resources/config/doctrine/model/{Country,Store,Currency,ProductStoreValues}.orm.xml`.
- **Idea:**
  - `<cascade-merge/>` (`Country.orm.xml:9`, `Store.orm.xml:20`): `merge` is removed in ORM 3 and the element is not in the ORM 3 XSD. **Note:** `ResourceBundle/Doctrine/ORM/EntityMerger.php:293-307` decides what to merge by `isCascadeMerge`; replace that flag with an explicit list before dropping the element.
  - One-to-many on `<mapped-superclass>` (`Store.configurations`, `Currency.countries`, `ProductStoreValues.productUnitDefinitionPrices`) works in ORM 2.20 (`illegalToManyAssociationOnMappedSuperclass` is never thrown) but is rejected by ORM 3 **(plausible)**; check with ORM 3 how the resource listener turns these into entities.
  - `Store.configurations` is `fetch="EAGER"` (`Store.orm.xml:11-16`): every store load (done on every request by the store context) loads all configuration rows of that store. Make it LAZY; `ConfigurationService` already queries by key.
  - `ProductStoreValues.store` join column is nullable by default (`ProductStoreValues.orm.xml:22-24`), but `__toString()`/`getDataForEditmode()` dereference `getStore()`; and the unique key does not hold for `NULL`. Set `nullable="false"`; give `fieldName` an explicit `length`.
  - `Currency.countries` `orphan-removal` + `cascade-all`: see `CurrencyBundle/TODO-1-CRITICAL.md` **C-04** (not repeated). `QuantityRange.currencyId ON DELETE SET NULL`: see `Component/Currency/TODO-1-CRITICAL.md`. Configuration key/store uniqueness: see `ConfigurationBundle/TODO-1-CRITICAL.md`.

### I-08 — Migration metadata and transactional mode
- **Where:** almost all `Migrations/Version2023*`/`Version2024*` (`getDescription()` returns `''`, `down()` holds the "auto-generated, please modify" comment).
- **Problem:** `migrations:status`/`list` shows no description. The `ClassUpdate`-based migrations are transactional by default, but `ALTER TABLE` commits implicitly, so doctrine/migrations 3.9 triggers the `TransactionHelper` "relying on silencing is deprecated" deprecation for each of them (`vendor/doctrine/migrations/src/Tools/TransactionHelper.php:19-31`). Multi-statement `addSql()` strings (`Version20230705174306`, `Version20230711143927`, `Version20230829163505`, `Version20231201091924`) only work with emulated prepares on `pdo_mysql`.
- **Fix:** Add descriptions. Override `isTransactional(): bool { return false; }` in migrations that call Pimcore APIs. Use one `addSql()` per statement. Make `down()` either implement the revert or `$this->throwIrreversibleMigrationException()`.

### I-09 — PHP 8.4 idioms across the bundle (`readonly`, `#[\Override]`, `final`, typed constants, `match`, first-class callables)
- **Where:** all constructors with promoted properties (`AddressChoiceType`, `Checkout/*`, `CustomerRegistrationType`, `GuestRegistrationType`, `CartCreationCartItemTypeExtension`, the rule action types with `protected array $validationGroups`, all validators, `CustomerRegistrationFormSubscriber`).
- **Fix:**
  - Mark promoted dependencies `readonly`.
  - Add `#[\Override]` on `buildForm`, `configureOptions`, `buildView`, `getParent`, `getBlockPrefix`, `getExtendedTypes`, `validate`, `validatedBy`, `getTargets`, `mapDataToForms`, `mapFormsToData`.
  - Make non-final classes `final` where nothing extends them: `CartMinimumQuantityValidator`, `QuantityRangePriceCurrencyAware(Validator)`, `ProductQuantityRangeCollectionTypeExtension`, the Payment/Shipping action types, the Notification action types, `CustomerRegistrationType`, `GuestRegistrationType`, `CustomerDataMapper`, `QuantityValidatorService`.
  - Type `CarrierChoiceType::__construct($dataClass)` as `string`.
  - `ProductQuantityRangeTypeExtension::getScale()` should return `?int` natively, and `roundQuantity()` should return `: void`.
  - `CustomerDataMapper::__construct(\n) {` has the odd empty-constructor formatting; use `private readonly DataMapper $propertyPathDataMapper = new DataMapper()` as a promoted default, or initialise it inline. The same pattern is noted in CustomerBundle I-03.
- **Also (Promoted `readonly` properties, `final`, `#[\Override]`):**
  - **Where:** every constructor in the slice (`Checkout/Step/*`, `EventListener/*`, `EventListener/NotificationRules/*`, `EventListener/Order/*`, `Customer/*`, `Order/OrderMailProcessor.php`, `Storage/SessionStorage.php`, `Security/*`). There are 0 `readonly` and 0 `#[\Override]` in the slice.
  - **Fix:** `private readonly`. Add `#[\Override]` on all interface or parent implementations (`commitStep`, `prepareStep`, `validate`, `supports`, `voteOnAttribute`, `refreshUser`, `getSubscribedEvents`, …). Make `AccessVoter`, `ObjectUserProvider`, `CustomerManager`, `OrderMailProcessor` and the checkout steps `final`, or document them as extension points. Checkout steps are `class` (non-final) on purpose for overriding, so prefer decoration and say so in the docs.
- **Also (PHP 8.4 / modern idioms across the slice):**
  - **Where:** whole slice.
  - **Problem / Fix:**
    - Add `#[\Override]` to every interface/parent method implementation (none present today: all `getReportData`, `getPortletData`, `getTotal`, `collect`, `reset`, `getName`, `getFilters`, `getFunctions`, overridden controller methods). ConfigurationBundle TODO-3 I-02 asks the same project-wide.
    - Use `match` instead of the `switch` in `SalesReport.php:86-102` (also fixes TODO-2 **B-55** by forcing a `default`).
    - Mark the reports `final` where not already (`CarriersReport`, `CartsReport`, `CategoriesReport`, `CustomersReport`, `ManufacturerReport`, `PaymentProvidersReport`, `ProductsReport`, `SalesReport`, `VouchersReport` are non-final; `AbandonedCartsReport` is `final`). The private `$totalRecords` and promoted ctor args are good already.
    - `ProductVariantUnitSolidifierController` constants: fix the typo `STATUS_ERROR_NO_UNIT_DEFINITIONS = 'error_nno_unit_definitions'` (`:38`) — it works only because the JS builds the translation key from the same string (`workflow/variantUnitDefinitionSolidifier.js:45`), but the stored value is misspelled. Consider a native enum for status/strategy.
    - First-class callable syntax for the Twig delegations (`[$this->configurationService, 'getForStore']` → `$this->configurationService->getForStore(...)`) in `ConfigurationExtension`, `AddressAllocatorExtension`, `ProductPriceExtension`, `ValidPriceRulesExtension`.
- **Also (PHP 8.4 idioms):**
  - **Where:** `Attribute/AsPortlet.php`, `Attribute/AsReport.php` (`final readonly class`, keep the getters – `Autoconfiguration::getProperties()` reads them), `Application/Version.php` (typed constants), `Pimcore/Repository/ProductRepository.php:35` (`public const string`), `Pimcore/LinkGenerator/*` and `Pimcore/GridColumnConfig/**` (`readonly` promoted properties, `#[\Override]`).

## DependencyInjection / configuration / service registration

### I-10 — `Configuration` cleanup
- **Where:** `DependencyInjection/Configuration.php:39-42,94,103-106,109,113,121-161`.
- **Idea:**
  - `autoconfigure_with_attributes`: `booleanNode` (a value like `'yes'` is accepted by the tree — **(reproduced)** `tree.php` — and then `Autoconfiguration::registerForAutoConfiguration(bool)` throws `TypeError` under `strict_types`). Same item as `LocaleBundle/TODO-2-BUGS.md` **B-05**; fix both together.
  - `pimcore_admin.permissions`: `scalarNode` with an array default; any user value fails ("Expected "scalar", but got "array"" — **(reproduced)**). Use `arrayNode()->scalarPrototype()`. Same as `PaymentBundle/TODO-3-IMPROVEMENTS.md` (`permissions` bullet).
  - `send_usage_log` is never read (`grep` over `src/`). Remove or deprecate (`->setDeprecated()`).
  - `checkout_manager_factory`: validate that the value is a key of `checkout` (an unknown value is accepted — **(reproduced)** — and only fails later as a generic "alias to non-existent service" compile error).
  - `->prototype('scalar')` → `->scalarPrototype()`; replace the 12-line `ifTrue` closure for the reserved `cart` step with `fn (array $steps) => array_key_exists('cart', $steps)`.
  - No 7.4 `TreeBuilder` deprecations are triggered (no node combines `isRequired()` with a default); checked by processing the shipped `pimcore/checkout.yml` with E_ALL.

### I-11 — `CoreShopCoreExtension` cleanup
- **Where:** `DependencyInjection/CoreShopCoreExtension.php:45,74-76,88-91,93-105,124-136,154-156,168`.
- **Idea:** `private const array BUNDLES_WITH_ATTRIBUTE_CONFIGURATION` (typed constant); `array_key_exists('pimcore_admin', …)` is always true (`addDefaultsIfNotSet`); load `services_test.yml` on `=== 'test'` (or `str_starts_with`) instead of `str_contains('test')` (also matches `latest`, `contest`); build the steps locator with `ServiceLocatorTagPass::register($container, $services)`; the `coreshop.checkout_managers` parameter is never read; the `else throw` for a missing factory is unreachable once I-10 validates the key. `Extension`/`PrependExtensionInterface` are fully supported in 7.4; moving to `AbstractBundle` only makes sense together with `AbstractResourceBundle`.

### I-12 — Bundle class / compiler passes
- **Where:** `CoreShopCoreBundle.php:68-70,86-87,95-96`, `DependencyInjection/Compiler/RegisterIndexProductExtensionPass.php:36-63`, `DependencyInjection/Compiler/Register{Portlets,Reports}Pass.php:25-34`.
- **Idea:** `RegisterIndexProductExtensionPass` silently does nothing if it runs before `ResourceBundle`'s `StackClassesPass` (it only works because ResourceBundle is registered with priority 3800 and builds first) – give it an explicit lower priority (`addCompilerPass($pass, PassConfig::TYPE_BEFORE_OPTIMIZATION, -10)`). The OpenSearch registry key `$class . 'opensearch'` → `$class . '.opensearch'`. Bundle priorities: VariantBundle (2950) is above ProductBundle (2900), Notification and Tracking share 2000 – order by dependency. Typed constants (`public const string PORTLET_TAG`), remove the empty constructor formatting in both passes (or pass the strings directly in `build()`).

### I-13 — Register listeners with `#[AsEventListener]` / autoconfiguration, and stop making them public
- **Where:** `Resources/config/services/{listeners,customer,notification,handler,storage}.yml` (`_defaults: public: true`, manual `kernel.event_listener` tags with string event names such as `security.interactive_login` and `pimcore.dataobject.postUpdate`).
- **Fix:** `#[AsEventListener(event: InteractiveLoginEvent::class)]`, `DataObjectEvents::POST_UPDATE`, etc., with `autoconfigure: true`. Make listeners private (nothing fetches them from the container). That removes about 150 lines of YAML and the string-typo risk shown by TODO-2 **B-23**.

### I-14 — Service definition hygiene: aliases, `public: true`, dead arguments and parameters, abstract parents
- **Where:** `Resources/config/services/validators.yml` (alias-based `validatedBy()` strings such as `coreshop_add_to_cart_availability`), and `form.yml` / `form_extension.yml` (`_defaults: public: true`).
- **Fix:** drop the `alias` tags and the overridden `validatedBy()`. The default `static::class.'Validator'` resolves through the FQCN key that `AddConstraintValidatorsPass` already registers. Make form types and extensions private (they are looked up through the form registry's service locator), or use `autoconfigure: true` with `#[AsFormTypeExtension]` / `#[AutoconfigureTag]`.
- **Also (Dead and ignored service arguments and parameters):**
  - **Where:**
    - `Resources/config/services/checkout.yml:83`: `PaymentProviderResolverInterface` is passed as a 6th argument, but `Form/Type/Checkout/PaymentType.php:38-46` takes 5. The argument is ignored.
    - `Resources/config/services/form.yml:5`: `guest_registration_type.validation_groups` is never referenced (see TODO-2 **B-05**).
    - `form.yml:84-88`: the Payment `PriceActionConfigurationType` gets the `...payment_provider_rule.action.discount_amount...` groups; there is no `...price...` parameter.
    - `Form/Type/Order/PaymentType.php:32`: the `store` option is never read.
  - **Fix:** remove them, or add a dedicated `coreshop.form.type.payment_provider_rule.action.price.validation_groups`.
- **Also (Service definitions in `commands.yml` / `installer.yml`):**
  - **Where:** `Resources/config/services/commands.yml:2-5,20-28,37-42`, `Resources/config/services/installer.yml:5-6`.
  - **Problem:** The abstract PHP class `AbstractInstallCommand` is registered as a concrete service (it only works because it is private and unused). `InstallDemoCommand`, `InstallFixturesCommand` and `InstallCommand` use `InstallResourcesCommand` as their parent definition, which is confusing. `installer.yml` makes every installer helper public via `_defaults`.
  - **Fix:** Mark `AbstractInstallCommand` `abstract: true` and let all install commands use it as parent. Drop `public: true` where no `container->get()` needs it.
- **Also (Wiring hygiene):**
  - **Where:** `Resources/config/services/product.yml:91-96`, (dependent) `ProductBundle/Resources/config/services.yml:59`; `Resources/config/services/product-quantity-price-rules.yml:1`.
  - **Idea:** the service id `CoreShop\Component\Product\Calculator\ProductCustomAttributeCalculatorInterface` names a non-existent interface (the real one is `ProductCustomAttributesCalculatorInterface`), so autowiring by the real type does not find it; rename the alias. `product-quantity-price-rules.yml` is the only file of the slice without `_defaults` (private services) – harmless, but inconsistent with the other files (all `public: true`); prefer private everywhere and expose only what is fetched via `\Pimcore::getContainer()`.

### I-15 — `composer.json` constraints
- **Where:** `composer.json:25-63`.
- **Idea:** add `"php": "-8.4.0"` (root and `six-it/pimcore-source` require it); declare direct dependencies used by this bundle's own code: `doctrine/orm` (`^2.20`, ResourceBundle pins `^2.9` while `six-it/pimcore-source` allows `^3.0` – see I-41/I-07 before widening), `jms/serializer-bundle`, `symfony/dependency-injection`, `symfony/config`, `symfony/form` (`^7.4`); `phpstan/*` `^1.x` → `^2.x`. `coreshop/frontend-bundle` is required although CoreBundle does not register it – check whether it belongs in `suggest`.

## Forms / validators

### I-16 — Six copies of `getExistingCartItemQuantityFromCart()`, with dead branches and an `instanceof $product` that would throw
- **Where:** `Validator/Constraints/AddToCart{Availability,MinimumQuantity,MaximumQuantity}Validator.php:77-96 / 91-110 / 91-110`, `Cart{StockAvailability,MinimumQuantity,MaximumQuantity}Validator.php:85-104 / 105-124 / 103-122`.
- **Problem:**
  - The `if (!$product && $this->cartItemResolver->equals(...))` branch is unreachable: `validate()` already returned when the product is not `StockableInterface`. If it were reached with a non-equal item, `instanceof $product` with `null` throws `Error: Class name must be a valid object or a string` (reproduced, `t1.php`).
  - `AddToCartMinimumQuantityValidator.php:101` returns a possibly-null `getDefaultUnitQuantity()` from a `: float` method.
  - `instanceof $product && getId() ===` compares by class plus id. Comparing ids is enough for Pimcore objects.
  - The inline `@var` docblocks before `Assert` calls are redundant.
  - `isLowerThenMinLimit` / `isHigherThenMaxLimit` should be spelled "Than".
  - The `StorageListItemResolverInterface` dependency becomes unused once the dead branch is removed.
- **Fix:** move a single `sumQuantityForProduct(OrderInterface $cart, int $productId, ?OrderItemInterface $exclude = null): float` into `QuantityValidatorService`, and fold min/max into one `QuantityLimitValidator` (or keep the two constraints but share one service). Use `mixed $value` in `validate()` and `$x ?? 0.0` consistently.

### I-17 — `CustomerDataMapper::mapFormsToData()` bypasses the usual guards
- **Where:** `Form/DataMapper/CustomerDataMapper.php:57-78`.
- **Problem:**
  - Unlike `mapDataToForms()`, there is no `instanceof CustomerInterface` check before `setAddresses()`.
  - The address child is written even when it is disabled, not submitted or not synchronised. Symfony's `DataMapper` checks `isSubmitted() && isSynchronized() && !isDisabled()`.
  - The `'mapped' => false` on `CustomerRegistrationType.php:99` is silently overridden, which is confusing.
  - The getter `getAddresses()` is called twice.
- **Fix:** add the guards. Document that the mapper owns the `address` child, and drop `mapped => false` there, or check `getConfig()->getMapped()`.

### I-18 — Small form-type clean-ups
- **Where / Fix:**
  - `Form/Type/AddressChoiceType.php:68`: the `active` option is defined but unused. `:60` uses a non-strict `in_array`. Build the `choice_label` with the address formatter or a translator-friendly format instead of `street number`.
  - `Form/Type/Notification/Condition/UserTypeConfigurationType.php:31-38`: a list-style `choices` array gives the labels `0..3`. Use `array_combine($types, $types)` or translation keys.
  - `Form/Type/ProductPriceRule/Condition/QuantityConfigurationType.php:40-51`: `IntegerType` plus `Type('numeric')` is redundant. Add `PositiveOrZero` and a `minQuantity <= maxQuantity` check (callback on the parent).
  - `Form/Type/{Shipping,Payment}/Rule/Action/PriceActionConfigurationType.php:39-43`: no lower bound, so a negative fixed price is accepted. Add `PositiveOrZero`.
  - `Form/Type/Notification/Action/OrderMailConfigurationType.php:30`: the `parent::buildForm()` call on `AbstractType` is a no-op. `mails` has no `entry_type`, while the two store variants use `IntegerType` collections; align them.
  - `Form/Extension/CartCreationCartItemTypeExtension.php:40-45`: reads `$data['product']` before the `isset` check, which warns when the key is missing. Use `$data['product'] ?? null` and type the closure `: void`.
  - `Form/Extension/ProductQuantityRangeCollectionTypeExtension.php:71,77`: the error messages are hard-coded English, not translation keys, and contain a double space ("row 3  needs"). `$data` is not null-guarded.
  - `Validator/Constraints/QuantityRangePriceCurrencyAwareValidator.php:41`: `%d` truncates a fractional `rangeStartingFrom` (for example `2.5`). Use `%s`, or pass the number as its own parameter.
  - `Form/Type/Checkout/PaymentType.php:91-105`: creates a prototype sub-form for **every** gateway factory on every build, including during submit. Build the prototypes lazily in `buildView()` only. `:81` dereferences `getGatewayConfig()` without a null check (a provider without a gateway config gives a 500).

### I-19 — `CarrierChoiceType::buildView()` recomputes the cart context for every carrier
- **Where:** `Form/Type/Checkout/CarrierChoiceType.php:81-99`.
- **Fix:** resolve `$context` and `displayWithTax($context)` once before the loop. Each call can trigger store, currency and country resolution.

### I-20 — (dependent) Registration passwords only have `NotBlank`
- **Where:** `Form/Type/UserRegistrationType.php:38-45`; constraints in `UserBundle/Resources/config/validation/User.yml:3-4`.
- **Fix:** add `Length(min: 8|12)` and optionally `NotCompromisedPassword` / `PasswordStrength` (Symfony 7.x). This is the same issue as CustomerBundle `TODO-2-BUGS.md` B-02 for `ChangePasswordType`, so fix both together.

## Security / listeners

### I-21 — `AccessVoter`: typed signature and cacheable voter
- **Where:** `Security/AccessVoter.php:31-42`.
- **Fix:** `supports(string $attribute, mixed $subject)` (drop the `@phpstan-ignore`). Add `supportsAttribute(string $a): bool { return str_starts_with($a, $this->prefix); }` and `supportsType(): true`, so `TraceableAccessDecisionManager` / `AccessDecisionManager` cache the decision and skip the voter for other attributes. Make `$prefix` `readonly`. See TODO-2 **B-03** for the semantics.

### I-22 — `ObjectUserProvider`: implement `PasswordUpgraderInterface`
- **Where:** `Security/ObjectUserProvider.php`.
- **Fix:** implement `upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword)` (set the password, save without a version), so `migrate_from` hasher config works for shop users. Type `supportsClass(string $class)`.

### I-23 — Stateful shared services should implement `ResetInterface`
- **Where:** `Storage/CookieStorage.php:31-39` (request and response bags), `EventListener/ProductAvailabilityEventListener.php:33`.
- **Fix:** `implements ResetInterface` and clear the state in `reset()`. This matters for Messenger workers, FrankenPHP or RoadRunner (see TODO-2 **B-22**).

### I-24 — Merge `RequestCartRecalculation` and `RequestCartAvailability` into one subscriber
- **Where:** `EventListener/RequestCartRecalculation.php`, `EventListener/RequestCartAvailability.php`, `listeners.yml:51-66`.
- **Fix:** both run on every frontend main request, repeat the same guards, and load the cart. One subscriber with `match` on the cart state removes the duplication. Read the price-rule marker once per request; it is a DB query each time today (`ConfigurationRepository::findByKey`). Cache it, or put it in Pimcore's cache with a tag.

### I-25 — `ProductAvailabilityEventListener`: simplify
- **Where:** `EventListener/ProductAvailabilityEventListener.php:57,77,109-133`.
- **Fix:** `isset()` instead of `in_array()`. Check `$event->getArgument('saveVersionOnly')` and skip version-only saves. Batch the cart saves (one save per cart, not per item, when a cart contains the product more than once).

### I-26 — `RecursiveVariantCacheInvalidationEventListener`: `match` and one tag per rule type
- **Where:** `EventListener/RecursiveVariantCacheInvalidationEventListener.php:50-76`.
- **Fix:** compute the tag once: `$tag = match (true) { $r instanceof PaymentProviderRuleInterface => PAYMENT_TAG, $r instanceof ShippingRuleInterface => SHIPPING_TAG, default => "cs_rule_variant_{$id}" };`. Clear it once if any `products` condition has `include_variants` (`array_any()` is PHP 8.4). Product price rules and cart price rules share the `cs_rule_variant_<id>` namespace, so ids collide and over-invalidate; prefix them with the rule type.

### I-27 — `OrderDocumentWorkflowListener`: constructor injection instead of `setType()`
- **Where:** `EventListener/NotificationRules/OrderDocumentWorkflowListener.php:27-32`, `notification.yml:265-279`.
- **Fix:** promote `private readonly string $type` in the constructor. Today, calling the listener without `setType()` hits "must not be accessed before initialization". `AbstractNotificationRuleListener` could become a trait or be dropped in favour of autowiring.

### I-28 — `CustomerListener::prepareUserParameters()`
- **Where:** `EventListener/NotificationRules/CustomerListener.php:142-153`, `:51`.
- **Fix:** read `$customer = $user->getCustomer()` once (6 calls today) and handle `null` (`applyPasswordRequestResetRule` passes a possibly-`null` customer as rule subject). Prefer `$customer->getLocaleCode()` over the shopper context, so mails triggered outside the storefront use the customer's language. `shopperContext->getStore()` throws outside a store context.

### I-29 — `OrderMailNoteEventListener`: variable shadowing and clean-up
- **Where:** `EventListener/Order/OrderMailNoteEventListener.php:57-77`.
- **Fix:** `foreach ($recipients as $mail => $name)` overwrites the `Mail $mail` parameter. Rename it (`$address`). `isset($x) && !empty($x)` → `!empty($x)`. Use `implode()` with `array_map()` for the recipient list.

### I-30 — `OrderMailProcessor`: depend on `LoggerInterface`, catch `\Throwable`, deduplicate the attachment loops
- **Where:** `Order/OrderMailProcessor.php:33,40,80-116`.
- **Fix:** type-hint `Psr\Log\LoggerInterface` (a decorated or non-Monolog logger causes a `TypeError` today). Extract one `attachDocuments(iterable $docs, string $prefix, callable $number)` helper. `unset($emailParameters['____pimcore_cache_item__'], …)` is not needed once TODO-2 **B-28** is fixed.

## Checkout / customer / events

### I-31 — Deduplicate the checkout steps
- **Where:** `Checkout/Step/*.php`. The "customer not set" check appears 5 times. `Assert::isInstanceOf($cart, Core\OrderInterface)` appears 10 times. The `createForm()` + `isMethod('post')` + `handleRequest()` pattern appears 5 times. `validate()` of `AddressCheckoutStep` and `CustomerAddressCheckoutStep` is identical.
- **Fix:** an abstract `AbstractFormCheckoutStep` (or a trait) with `createForm(string $type, OrderInterface $cart, array $options)` and `requireCustomer(OrderInterface $cart): CustomerInterface`. `AddressCheckoutStep` can delegate `validate()` to the inner step. `handleRequest()` already checks the method itself, so the `isMethod('post')` guard can go. In `commitStep`, `if ($form->isSubmitted()) { if ($form->isValid()) {…} }` → a single `if`.

### I-32 — Newsletter token from a CSPRNG
- **Where:** `EventListener/NotificationRules/CustomerListener.php:92`.
- **Fix:** `bin2hex(random_bytes(32))` instead of `md5($id . $email . mt_rand() . time())`. The current token is not guessable online in practice, but `mt_rand` and `md5` should not be used for tokens.

### I-33 — Event classes: typed and readonly
- **Where:** `Event/RequestNewsletterConfirmationEvent.php:26-29` (`private $confirmLink` untyped), `Event/CustomerRegistrationEvent.php:26-38`.
- **Fix:** `private readonly string $confirmLink`. For `CustomerRegistrationEvent`, use promoted `readonly` properties and `getSubject()`, and drop `$data` (I-03).

### I-34 — `CustomerManager`: small clean-ups
- **Where:** `Customer/CustomerManager.php:51-72,98-100`.
- **Fix:** `getObjectVar()` reads raw properties to avoid lazy loading. Comment that, or use `getAddresses()` / `getUser()`. `setKey(File::getValidFilename(...))` followed by `setKey(Service::getUniqueKey(...))`: extract `assignUniqueKey()`. `mb_substr($customer->getLastname(), 0, 1)` fails with a `TypeError` on a `null` last name; guard it (`?? ''`).

## Reports / controllers / Twig / menu

### I-35 — Extract the repeated report preamble into a base class / trait
- **Where:** all `Report/*.php`.
- **Problem:** `from`/`to`/`store`/`page`/`limit`/`offset` parsing, the store lookup + `instanceof StoreInterface` guard, the `orderState` JSON handling, and the `FOUND_ROWS()` dance are copy-pasted into 8+ classes with small divergences (which is how TODO-2 **B-57**, TODO-2 **B-60**, TODO-2 **B-61**, TODO-1 **C-01** drifted apart). A `AbstractStoreReport` base (or a `ReportFilters` DTO built from the `ParameterBag`) would centralise parsing, validation (TODO-1 **C-01** fix), inclusive date ranges (TODO-2 **B-54** fix), and currency selection (TODO-2 **B-57**).
- **Fix:** introduce the base/DTO and migrate the reports onto it.

### I-36 — Replace `SQL_CALC_FOUND_ROWS` + `FOUND_ROWS()` (deprecated in MySQL 8.0.17)
- **Where:** `AbandonedCartsReport.php:89,113`, `CategoriesReport.php:94,147`, `CustomersReport.php:60,76`, `ManufacturerReport.php:102,129`, `ProductsReport.php:111,141,169`, `VouchersReport.php:75,92`.
- **Problem:** `SQL_CALC_FOUND_ROWS`/`FOUND_ROWS()` are deprecated and will be removed; they also force a full scan. On MariaDB they behave subtly differently with `LIMIT`.
- **Fix:** run a separate `COUNT(*)` (or `COUNT(*) OVER()` window) for the total.

### I-37 — `getParameterFromRequest` reimplements the deprecated `Request::get()`
- **Where:** `ResourceBundle/Controller/AdminController.php:48-63` (used throughout the slice).
- **Problem:** The helper replicates `Request::get()` precedence (attributes → query → request). That is fine for Symfony 7.4 (it avoids the deprecation), but callers then pass unvalidated mixed values straight into SQL/`find()`. Prefer reading from the specific bag (`$request->query->getInt(...)`, `->getString(...)`) at each call site, which also gives typed coercion for `from`/`to`/`page`/`limit`.
- **Fix:** read typed values from the explicit bag; keep the helper only for the attribute-or-query dual case.

### I-38 — `CheckoutIdentifierExtension` dynamic `coreshop_checkout_steps_*` function + `method_exists` dispatch
- **Where:** `Twig/CheckoutIdentifierExtension.php:23-116`.
- **Problem:** The `*`-suffixed dynamic Twig function with a hand-rolled `ucwords`/`method_exists` dispatch is hard to follow and bypasses IDE/static analysis. `getPreviousStepIdentifier` also re-reads `stepIdentifier` from the request (`:129`) instead of using its own `$stepIdentifier` argument, and dereferences `$request` without the null-guard the sibling methods have (`:123-129` — `getMainRequest()` can be null).
- **Fix:** use a `match` on an allowlist; pass `$stepIdentifier` through; guard `$request`.

### I-39 — `ConfigurationController` typing / dead branches
- **Where:** `Controller/ConfigurationController.php:39-47,64-73`.
- **Problem:** `$store` is reassigned from the loop key to the entity (`:39-43`), shadowing types; `is_array($configurations)` (`:66`) is always true (`findBy` returns array). Mostly covered by Configuration Component TODO-3 I-07 (single implementation + DTO); cross-reference.
- **Fix:** see ConfigurationBundle TODO-3 I-07.

### I-40 — Menu builder: duplicate child name and repetition
- **Where:** `Menu/MainMenuBuilder.php:366-382`.
- **Problem:** The payment container and its first child are both `addChild('coreshop_payment_providers')` (`:367` and `:375`) — KnpMenu silently returns the existing child on a duplicate name, so the nested "payment providers" item may be dropped/merged. 400 lines of near-identical `addChild()->setAttribute()` chains could be data-driven (array + loop), making permissions/order auditable.
- **Fix:** rename the container (e.g. `coreshop_payment`) and/or data-drive the tree.

## Product store values / Doctrine / serializer / repositories

### I-41 — Data type cleanup and ORM 3 readiness
- **Where:** `CoreExtension/StoreValues.php` passim.
- **Idea:**
  - Dead code: `getDataFromResource()` (`:316`), `getQueryColumnType()/getColumnType()` (`:135-143`; the class implements neither `ResourcePersistenceAwareInterface` nor `QueryResourcePersistenceAwareInterface`), `toNumeric()` (`:835-842`), `$phpdocType`, `minValue/maxValue` (never used by the JS).
  - `save()` (`:438`): create the `EntityMerger` once, outside the loop. `flush()` (`:486`, `:582`) flushes the **whole** default EM during a Pimcore object save, including unrelated pending changes of the request; flush only these entities or document it.
  - `unmarshalVersion()` (`:528-537`) reuses `$data` as loop variable while iterating `$data` – rename.
  - `unmarshalForCache()` (`:566-569`) creates a new temporary `EntityManager` (`TempEntityManagerTrait`) every time a product comes out of the cache – expensive on listing pages. (dependent) `ResourceBundle/CoreExtension/TempEntityManagerTrait.php` uses `EntityManager::create()`, which is removed in ORM 3 → `new EntityManager($conn, $config, $evm)`.
  - `createDataCopy()` (`:382-393`): `setAccessible()` is a no-op since 8.1 and deprecated in 8.5; superseded by TODO-1 **C-08**'s `__clone()`.
  - Generated getter (`:208-212`): calls `preGetData()` twice; `get<Key>ForStore()` can index directly (`$data[$store->getId()] ?? null`) because `preGetData()` keys by store id.
  - Typed properties (`public ?int $width = null`), `#[\Override]` on the Pimcore overrides, and service lookups (`\Pimcore::getContainer()->get(...)`) bundled into one lazy service locator.

### I-42 — JMS metadata cleanup
- **Where:** `Resources/config/serializer/*.yml`.
- **Idea:** `Model.Currency.yml` exposes non-existent `stores` (see `CurrencyBundle/TODO-3-IMPROVEMENTS.md`, `:142`). `Model.PaymentProvider.yml:9-10` `gatewayConfig` has no `expose: true` and the property is declared in the parent (`PayumPayment\Model\PaymentProvider`), so the entry is dead (dependent: the Payum one lacks `expose` too). `Model.ProductUnitDefinitionPrice.yml` exposes the back-reference `productStoreValues` in `List/Detailed` – JMS only stops the cycle via its visiting set; drop it. `Model.Store.yml` exposes all `countries` in the `List` group (heavy store grid). Use `integer` consistently (`Model.QuantityRange.yml` uses `int`).

### I-43 — Query readability / portability
- **Where:** `Doctrine/ORM/CarrierRepository.php:31-33,43-44` (array parameter for `=`; pass `$store` or `$store->getId()`), `Doctrine/ORM/CurrencyRepository.php:30-31` (`leftJoin` followed by `innerJoin` on it is an inner join – write `innerJoin`), `Pimcore/Repository/CategoryRepository.php:42,52` and `ProductRepository.php:202` (double-quoted SQL string literals break under `ANSI_QUOTES`; bind parameters instead), `CategoryRepository.php:112`, `ProductRepository.php:127` (`fetchAllAssociative($qb->getSQL(), $qb->getParameters())` → `$qb->executeQuery()->fetchFirstColumn()`), `CategoryRepository.php:137-150` (ignores `getChildrenSortOrder()`; `method_exists()` is always true in Pimcore 11), `CustomerRepository.php:31`, `WishlistRepository.php:49` (`setLimit(1)` when only the first row is used).

## Install / migrations / fixtures / maintenance

### I-44 — `InstallCommand` overrides user verbosity and hides sub-command output
- **Where:** `Command/InstallCommand.php:72`, `Command/AbstractInstallCommand.php:79-108`, `InstallFixturesCommand.php:41`, `InstallDemoCommand.php:41`.
- **Problem:** `$output->setVerbosity(VERBOSITY_NORMAL)` ignores `-q`/`-v`. Fixture and demo loads run with `NullOutput` (`passOutput = false`), so fixture errors that do not throw are invisible. The commented-out connection close (`:96-98`) is dead code.
- **Fix:** Drop the `setVerbosity` call. Pass the output when `-v` is set. Remove the dead comment.

### I-45 — Consistency of the two new migrations
- **Where:** `Migrations/Version20260419121310.php:1-16,20,44-49`, `Migrations/Version20260616120000.php:46-67`, `Resources/install/pimcore/classes/CoreShopUserBundle/CoreShopUser.json:131-152`, `CoreShopOrderBundle/CoreShopOrder.json:231-252`.
- **Problem:**
  - `Version20260419121310` has a CCL-only license header, while the rest of the bundle (and its sibling migration) is GPLv3 + CCL.
  - `phpdocType` is `Carbon::class` (`Carbon\Carbon`, no leading backslash) in the migration but `\Carbon\Carbon` in the JSON. A class updated by migration and a freshly installed class therefore differ in the exported definition (`git diff` noise in `var/classes`).
  - The User field uses `bigint`, the Order field uses `bigint(20)`. Both work, but Pimcore compares column types when it saves a class. On MySQL ≥ 8.0.19 (`bigint`) versus MariaDB (`bigint(20)`) one of them shows up as changed on each save **(plausible)**.
  - `lastActivatedAt` is added with `index: true` to `object_store_*`/`object_query_*` of the Order class. On large order tables this is a long blocking `ALTER TABLE` inside a migration. Worth a release note.
  - The same JSON field definitions now exist in three places each (`CoreBundle`, `OrderBundle`/`UserBundle` install JSON, migration). Keep them in sync, or build the migration array from the JSON file.
- **Fix:** Use the dual-license header. Use `'\\' . Carbon::class` or the literal string. Pick one column type. Document the index cost.

### I-46 — Expensive data migrations
- **Where:** `Migrations/Version20231004120900.php:33-55`, `Migrations/Version20230711144054.php:33-43`.
- **Problem:** `Version20231004120900` loads and saves every order and order item through the Pimcore API. That creates versions, fires `coreshop`/workflow/notification listeners, and runs inside one transaction (long locks) **(plausible)**. `Version20230711144054` emits one `UPDATE` per product.
- **Fix:** Use set-based SQL on `object_store_*`/`object_query_*` (or `DataObject\Service::useInheritedValues` plus `setOmitMandatoryCheck`, `Version::disable()`, and disabled events). Use a single `UPDATE … JOIN`.

### I-47 — Duplicate permission migration
- **Where:** `Migrations/Version20240421093551.php` vs. `MessengerBundle/Migrations/Version20240421093216.php`.
- **Problem:** Both create `coreshop_permission_messenger`. They are idempotent, so this is harmless, but redundant.
- **Fix:** Keep one (the MessengerBundle one) once TODO-2 **B-70** is fixed.

### I-48 — `FolderInstallerProvider` hard-codes six folders
- **Where:** `Resources/config/services/installer.yml:16-25`, `Installer/Executor/FolderInstallerProvider.php`.
- **Problem:** The list misses folders that other resources configure (for example `coreshop.folder.address`, wishlist). `ResourceBundle/DependencyInjection/Driver/Pimcore/PimcoreDriver.php:121-140` already builds `%coreshop.folders%` with all of them.
- **Fix:** Inject `%coreshop.folders%`.

### I-49 — Type against interfaces and drop the internal Pimcore console class
- **Where:** `Installer/Provider/DatabaseSetupCommandsProvider.php:30-33,81-89`, `Installer.php:20,66-69`.
- **Problem:** The code type-hints the concrete `Doctrine\Bundle\DoctrineBundle\Registry` and `EntityManager`, and builds `Pimcore\Console\Application` (marked `@internal`, psalm-suppressed). Constructor promotion is used, but nothing is `readonly`.
- **Fix:** Use `Doctrine\Persistence\ManagerRegistry` and `getConnection()` from it. Inject the command (`InstallCommand`) or use `Symfony\Bundle\FrameworkBundle\Console\Application`. Use `private readonly` everywhere in the slice (no property in the slice is reassigned after construction, except `CommandDirectoryChecker::$name`, see TODO-2 **B-74**).

### I-50 — Small clean-ups
- **Where / Fix:**
  - `Maintenance/RuleAvailabilityTask.php:33-46`: use typed class constants for the config key and the 24 h interval (`private const string LAST_RUN_KEY`, PHP 8.3+). Cast the stored value with `(int)`. The `null` versus "never ran" ambiguity is already covered in `Component/Configuration/TODO-2-BUGS.md`.
  - `Test/Service/NotificationRuleListenerInterface.php:25`: `clear()` has no return type (`: void`). `NotificationRuleListener::checkDirExists()` has no return type. It uses `mkdir(0777)`, `uniqid()` file names (collisions in fast loops) and `serialize()` of Pimcore objects (heavy, can fail on closures). Use `Filesystem::mkdir` and `bin2hex(random_bytes(8))`, and store only IDs.
  - `SetupStatesCommand`: validate the `countries` argument (empty entries from `DE,`), use `findBy(['country' => $country])` once per country instead of one query per division, and use Rinvex `alt_names`/translations for non-English languages instead of the English name for every language.
  - `CategoryFixture.php:86-87` and `AbstractProductFixture.php:666-667`: `setKey($x->getName())` uses the name in the current locale, which can be `null`. Use the English name explicitly.
  - `ProductWithAttributesFixture.php:247,262-266`: looks up attribute groups and values by localized `name` (`'size'` vs. stored `'Size'`/`'S'`). This works only because MySQL collations are case-insensitive **(plausible)**. Look them up by `key`.
