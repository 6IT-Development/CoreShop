# VariantBundle – TODO 3: Improvements (code quality, readability, logic, PHP 8.4 / Symfony 7.4 idioms)

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-29 (branch `s74-p84`).
None of these are defects on their own. They make the bundle cleaner, safer, easier to test, or more idiomatic for the target stack.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 language features

### I-01 — Native return types, `#[\Override]`, `final` and `readonly` throughout
- **Where:** every class of the bundle
- Missing return types:
  - `VariantController::getAttributesAction()` / `generateVariantsAction()` → `: JsonResponse`
  - `CreateVariantMessageHandler::__invoke()` → `: void`
  - `AttributeListener::validate()` / `ProductListener::validate()` → `: void`
  - `VariantExtension::setSelected()` → `: array`
  - `serializeGroups()` / `serializeIndex()` → `: array|string|int|float|bool|\ArrayObject|null`, or narrow it to `array`
- Add `#[\Override]` to:
  - `getSubscribedEvents`, `validate`, `validatedBy`, `getTargets`, `generate` and `__invoke` where they implement an interface
  - `getFunctions` / `getFilters`
  - `getConfigTreeBuilder`, `load`, `getSupportedDrivers`, `getModelNamespace`, `build`
- Make these `final`: `VariantController`, `VariantGeneratorService`, `CreateVariantMessage`, `CreateVariantMessageHandler`, `VariantLinkGenerator` and `ValidAttributesTypeValidator`. Use a decorator instead of extending.
- Constructor-promoted properties in `final` classes are `protected`. Use `private readonly` (`AttributeListener`, `ProductListener`, `VariantExtension`, `CreateVariantMessageHandler`, `VariantLinkGenerator`).
- Remove the empty constructor in `ValidAttributesTypeValidator.php:30-32` (`public function __construct(\n        ) {}`).
- Remove the leftover `@var` docblocks that only restate a type that `Assert::isInstanceOf()` / `instanceof` already narrows (`AttributeListener.php:51-53` sits **before** the `instanceof` and claims `|null`; `ProductListener.php:52-56`; the validators).

### I-02 — `CreateVariantMessage` as an immutable value object
- **Where:** `Messenger/CreateVariantMessage.php`
- Use `final readonly class CreateVariantMessage { public function __construct(public int $objectId, /** @var list<int> */ public array $attributeIds, public ?int $userId = null) {} }`. Drop the setters, because a message must not change after dispatch.
- The message is serialised with PHP `serialize()` into `messenger_messages`. A readonly class with public properties keeps the payload small and stable. Consider the Symfony Serializer transport serializer (JSON), so messages survive class renames.
- If the batch approach of **I-09** is adopted, replace it with `GenerateVariantsMessage(int $productId, array<int, list<int>> $groupedAttributeIds, ?int $userId)`.

### I-03 — Modern constraint classes (fixes TODO-2 **B-01**)
- **Where:** `Validator/Constraints/*`, `Resources/config/services.yml:40-46`
- Add `#[\Attribute(\Attribute::TARGET_CLASS)]` and a `#[HasNamedArguments]` constructor with `public string $message`. Then the constraints can also be used as PHP attributes.
- Drop `validatedBy()` with the string aliases (`coreshop_variant_valid_attributes`, `coreshop_variant_valid_attribute_type`) and the `alias:` tag options. The default `static::class . 'Validator'` already resolves to the validator classes, and with `autoconfigure` no tag is needed.
- Use `Constraint::CLASS_CONSTRAINT` with the return type `string|array` as declared by the parent in 7.4.

### I-04 — Twig extension: explicit colour normalisation, no duplicated registration list
- **Where:** `Twig/Extension/VariantExtension.php`
- The `valueColor` callback is declared as `static function (?string $innerObject)`, but `getValueColor()` returns `?RgbaColor` (`Component/Variant/Model/AttributeColorInterface.php`). It only works because the caller is not `strict_types` and `RgbaColor::__toString()` coerces the object to `#rrggbbaa` (reproduced). Write it explicitly: `static fn (?RgbaColor $color): ?string => $color?->getHex(true, true)` (or the format the frontend JS expects).
- Functions and filters are two hand-maintained lists of the same 7 entries. Build both from one map: `foreach (self::CALLABLES as $name => $callable)`. Alternatively, use Twig 3.21+ attribute-based extensions (`#[AsTwigFunction]` / `#[AsTwigFilter]`) on a runtime class (`RuntimeExtensionInterface`), so the collector and the serializer are only built when a template uses them.
- `setSelected()` mutates the `ResolvedAttributeGroup` objects it receives, which a filter should not do. Return the input unchanged, or document that it is a mutating helper. Guard `$product->getAttributes() ?? []`.
- Inject `NormalizerInterface` by its interface (`'@serializer'` works today, but autowiring makes the dependency explicit).

---

## Symfony 7.4 idioms / DI

### I-05 — `services.yml`: autowiring, private services, no dead arguments
- **Where:** `Resources/config/services.yml`
- `_defaults: public: true` makes **every** service public. Only the controller (which `controller.service_arguments` handles) and possibly the generator service for BC need to be public. Use `_defaults: { autowire: true, autoconfigure: true, public: false }`.
- **Dead constructor arguments:** `VariantController` has no constructor and `parent: …AdminController`. `ResolveChildDefinitionsPass` **appends** numeric child arguments to the parent's three arguments (`vendor/symfony/dependency-injection/Compiler/ResolveChildDefinitionsPass.php`), so `AdminController::__construct()` receives 6 arguments and silently ignores the generator service, the bus and the translator. They reach the action through `controller.service_arguments` instead. Remove the `arguments:` block.
- The action type-hints the **concrete** `VariantGeneratorService`. Type-hint `VariantGeneratorServiceInterface`, so decorators are honoured.
- `CreateVariantMessageHandler`: use `#[AsMessageHandler]` instead of the manual tag. With autowiring, the explicit `arguments` are not needed.
- `AttributeCollector` is defined here (`Component/Variant` service) with `'@event_dispatcher'`. Consider moving it to a component-level config, or at least autowiring it.
- `VariantLinkGenerator`: use `decoration_priority` explicitly. The `.inner` reference can be written as `'@.inner'`.
- The validation-group parameters (`coreshop.variant.*.validation_groups`) are fine. Expose them in the bundle configuration so they can be overridden without touching parameters.

### I-06 — Tidy the configuration tree, the extension and the bundle class
- **Where:** `DependencyInjection/Configuration.php`, `DependencyInjection/CoreShopVariantExtension.php`, `CoreShopVariantBundle.php`
- `->prototype('scalar')` → `->scalarPrototype()` (four times).
- `->cannotBeOverwritten(true)` → `->cannotBeOverwritten()`.
- The three `pimcore.*` model sections are copies of each other. Extract `addPimcoreModel(NodeBuilder $children, string $name, string $model, string $interface, string $installFile)`.
- `scalarNode('repository')->cannotBeEmpty()` has no default, so it cannot be empty but may be missing. Make that explicit (`->defaultNull()`), or remove `cannotBeEmpty()`.
- Extension:
  - `array_key_exists('pimcore_admin' | 'stack', $configs)` is always true (`addDefaultsIfNotSet`). Remove the checks.
  - Remove the commented-out `registerResources()` line.
  - The variable name `$configs` is reused for the processed config. Rename it to `$config`.
  - `getConfiguration([], $container)` → `new Configuration()`.
- Bundle class:
  - The `build()` override only calls the parent. Remove it.
  - `getSupportedDrivers()` returns `DRIVER_DOCTRINE_ORM` although the bundle has no Doctrine resources (`Resources/config/doctrine/model` does not exist). `AbstractResourceBundle::build()` still registers a `DoctrineOrmMappingsPass` for that missing directory. It is a no-op only because the enabled parameter `core_shop_variant.driver.doctrine/orm` is never set. Return `[CoreShopResourceBundle::DRIVER_PIMCORE]` (or `[]`), so the intent is clear.
- `Configuration` and `Extension` are `final`. Consider PHP config (`services.php` with `ContainerConfigurator`) for the new code, as some other bundles on this branch did.

### I-07 — Controller: request DTO, proper HTTP errors, permission checks in one place
- **Where:** `Controller/VariantController.php`, `Resources/config/pimcore/routing.yml`
- Use `#[MapRequestPayload] GenerateVariantsRequest $request` (with `#[Assert\Positive] int $id`, `#[Assert\All(new Assert\All(new Assert\Positive()))] array $attributes`) instead of `getParameterFromRequest()` + `if (!$x) throw \InvalidArgumentException`. Symfony then returns 422 with the violations automatically.
- Add a private `resolveProduct(int $id): ProductVariantAwareInterface` that loads the product, follows the variant parent, checks permissions (TODO-1 **C-01**) and throws a 404. Both actions duplicate this today (`:41-55` and `:93-112`).
- Use route attributes (`#[Route('/admin/variant/attributes', name: 'coreshop_admin_variant_attributes', methods: ['GET'], options: ['expose' => true])]`) and import the controller via `type: attribute`, or at least add `methods: [GET]` to the YAML route.
- The tree payload (`text`, `leaf`, `iconCls`, `checked`) is UI markup built in PHP. Map it through a small presenter or normaliser so it can be tested.
- Use `$group->getName()` (localised) instead of `getKey()` for the tree labels, and fall back to the key when the name is empty.

---

## Logic / API design

### I-08 — De-duplicate the listener validation code
- **Where:** `EventListener/AttributeListener.php:73-88`, `EventListener/ProductListener.php:93-110`
- Both `validate()` methods are identical. Both build a `NiceValidationException`, put it in an array of one element, then map that array to messages to build a `ValidationException`. Replace them with a shared `ValidationExceptionFactory` / trait: `throw new ValidationException(implode(PHP_EOL, array_map(fn ($v) => $v->getMessage(), iterator_to_array($violations))))`. Decide whether `NiceValidationException` (what the admin UI shows nicely) or `ValidationException` is meant, and use only one.
- `AttributeListener::preUpdate`: the `do … while ($parent != null)` loop with a loose comparison. Use `for ($parent = $object->getParent(); $parent !== null; $parent = $parent->getParent())`, or `getClosestParentOfClass()` (Pimcore `Concrete`), which needs the class ID.
- `ProductListener` mixes validation and name generation. Split the name generation into its own listener or service, so it can also be reused by `VariantGeneratorService` (which builds the same name with different code, `VariantGeneratorService.php:82-88`).

### I-09 — `VariantGeneratorService`: pure combination generator, one batch message, deterministic order
- **Where:** `Service/VariantGeneratorService.php`, `Service/VariantGeneratorServiceInterface.php`, `Controller/VariantController.php:114-124`
- `generateCombinations(array $groupedAttributes, array $currentCombination, int $groupIndex, array &$combinations)` exposes recursion state and a by-reference out-parameter in a **public interface**. Replace it with `public function combinations(array $grouped): \Generator` (iterative cartesian product, `yield`), so memory stays flat and the caller can stop at a limit (TODO-2 **B-03**).
- `generateVariants()` is not used anywhere in the repository. Remove it, or use it from the handler.
- Dispatch **one** `GenerateVariantsMessage` per request and let the handler iterate the generator. This gives one transaction boundary per run, one summary notification (TODO-2 **B-11**), and no queue explosion.
- **Order:** the JS builds `{groupId: [...]}`, and JS objects iterate integer keys in ascending order, so the attributes and therefore the variant **key and name are ordered by group ID**, not by group `sorting` ("Red - XL" vs "XL - Red" depends on which group was created first). Sort the resolved attributes by `getAttributeGroup()->getSorting()` before building the key and name.
- `new ($product::class)()` → `$product::create()` (Pimcore factory), so model overrides and DI-aware factories are respected.
- Split the service into `VariantExistenceChecker` (TODO-2 **B-02**), `VariantKeyGenerator` / `VariantNameGenerator` (shared with `ProductListener`) and the generator itself.

### I-10 — Created variants should carry the requesting user, and notifications should link the element
- **Where:** `Service/VariantGeneratorService.php:90-95`, `Messenger/Handler/CreateVariantMessageHandler.php`
- Variants are saved with `userOwner` / `userModification` = 0 ("system"). Pass the user ID from the message and call `setUserOwner()` / `setUserModification()`, so the admin's "created by" column and the version history are correct.
- `sendToUser(…, $element)`: pass the variant (or the product) so the notification is clickable.
- Translate the notification title and text (`coreshop.variant_generator.notification.*` in `admin.*.yaml`), and use the user's admin language for `getName($language)`. Today the text is hard-coded English and the name is in the default locale.

### I-11 — `ValidAttributesTypeValidator`: query instead of loading the whole group subtree
- **Where:** `Validator/Constraints/ValidAttributesTypeValidator.php:55-70`
- Every attribute save loads and hydrates **every object** below the group (`new DataObject\Listing()` with no class filter, iterated). For large groups (sizes, colours, hundreds of values) this is the slowest part of saving an attribute.
- The rule ("all attributes in a group have the same class") can be checked with one query: `SELECT COUNT(*) FROM objects WHERE parentId = :group AND classId IS NOT NULL AND classId <> :classId` (or a `DataObject\Listing` with `setCondition('parentId = ? AND classId <> ?')` and `getTotalCount()`).
- The comparison `!$value instanceof $child` is asymmetric for subclasses. Compare `getClassId()` instead.
- Give the violation a translated message that names the expected class.

### I-12 — `MainVariantListener`: compute the main variant cheaply and only when it changes
- **Where:** `EventListener/MainVariantListener.php`
- `findMainVariant()` loads all variants, then their attributes and groups, and sorts in PHP. For parents with many variants, prefer an indexed listing (`parentId = ?`, `published = 1`, joined on the attributes' sorting), or cache the result per request.
- Only call `setMainVariant()` when the ID differs from the current one, and only save the parent when something changed (TODO-2 **B-05**).
- Subscribe to `POST_DELETE` (and to unpublish) as described in TODO-1 **C-03**.
- Consider moving the "main variant" logic into a dedicated `MainVariantResolver` service in `Component/Variant` with an interface. This makes it testable and replaceable (some shops want "cheapest" or "first in stock").

### I-13 — Class definitions
- **Where:** `Resources/install/pimcore/classes/*.json`
- `attributeGroup` (Value and Color) is editable, but the listener overwrites it. Set `noteditable: true` and add a tooltip ("derived from the parent attribute group").
- `CoreShopAttributeGroup.json` has no `modificationDate`, while the other two do. Add it, so class-import tools can compare versions.
- `sorting` is `numeric` with `decimalPrecision: null`, but the interface types it `?float`. Decide between integer and float sorting and set `integer: true` or a precision.
- Consider `"index": true` / a composite index for the fields that are queried (none today, but **I-11** / **I-12** query on `parentId`, which already has an index).

### I-14 — Admin JS (`resource.js`) clean-up
- **Where:** `Resources/public/pimcore/js/resource.js`
- `const window = new Ext.window.Window(…)` shadows the global `window`. Rename it to `generatorWindow`.
- `if (!store)` in the `load` listener is always false. Check `successful` and show `operation.getError()`.
- Add `failure:` handlers and a load mask while the request runs (TODO-2 **B-08**).
- The button only appears when `tab.data.data.allowedAttributeGroups` was non-empty **when the tab was opened**. Re-evaluate it on `postSaveObject`, or always show the button and let the server answer.
- Show the number of combinations ("12 variants will be created") before **Apply**, and warn above a threshold (TODO-2 **B-03**).
- `handler: variantHandler.bind(this, tab)` binds `tab` as the first argument, but `variantHandler` takes no parameters and uses the closure's `tab`. Drop the bind argument.
- Add "Select all / none" per group. Large groups are tedious to tick one by one.

---

## Translations, docs, tests

### I-15 — Translations
- **Where:** `Resources/translations/*`
- Typos:
  - `admin.en.yaml:14` "Attribute Group**e**"
  - `messages.en.yaml:5` "occ**u**red" → "occurred"
  - `validators.de.yml:5` "zug**e**wiesen"
  - `validators.en.yml:8` "Wrong Attribute Type" (capitalisation; also make it say which type is expected)
  - `validators.de.yml` "Attribut Gruppe(n)" → "Attributgruppe(n)"
- Mixed extensions: `admin.*.yaml` / `messages.*.yaml` vs `validators.*.yml`. Use `.yaml` everywhere (other bundles on this branch use `.yml`, so align with the branch convention).
- `coreshop.ui.variant.error.general` (`messages.*.yaml`) is not used anywhere in the repository. Remove it, or use it in the frontend.
- The admin keys used in the JS (`coreshop.variant_generator.generate`) are here, but `apply`, `close`, `success` and `error` rely on Pimcore core keys. That is fine, just document it.

### I-16 — README and docs
- **Where:** `README.md`, `docs/03_Bundles/Variant_Bundle.md`
- `README.md` is the generic CoreShop text ("It is build from…", http links). Add a short description of the bundle, the configuration options (`redirect_to_main_variant`, `stack`, the `pimcore.*` model overrides) and the messenger transport.
- The docs register the bundle through `AppKernel::registerBundlesToCollection` (Pimcore ≤ 10 style). Use `config/bundles.php` / `Kernel::registerBundlesToCollection` for the current skeleton.
- The docs tell users to model `attributes` as **ManyToManyObjectRelation**. This is incompatible with the generator's duplicate check (TODO-2 **B-02**), and CoreShop itself uses `coreShopRelations` (ManyToManyRelation). Align the docs with the code, or fix the check so both work.
- Document the failed transport, the required permissions (after TODO-1 **C-01**) and the main-variant rules (published only, after TODO-1 **C-03**).

### I-17 — Tests
- **Where:** none exist for this bundle (only Behat contexts in `Behat/Context/*/VariantContext.php`)
- Unit tests:
  - `VariantGeneratorService` combination generator: empty groups, one group, N groups, and the limit.
  - Key and name building, with null names and ordering by group sorting.
  - The duplicate check against both relation formats (TODO-2 **B-02**).
  - `ValidAttributesValidator`: missing group, duplicate group, not-allowed group, attribute without group.
  - `VariantLinkGenerator`: variant, parent with or without a published main variant, and the flag off.
  - `VariantExtension::serializeIndex()` respects groups.
- Functional tests: controller permission denied, bad payload → 4xx, keys with apostrophes (TODO-1 **C-02**).
- Behat: generate variants via the admin endpoint and assert that publishing a subset sets a **published** main variant (TODO-1 **C-03**).

### I-18 — **(dependent)** `InheritanceHelper::useInheritedValues()` does not restore the flag when the closure throws
- **Where:** `Component/Pimcore/DataObject/InheritanceHelper.php:29-38`, used in `EventListener/ProductListener.php:74-76`
- If `getName()` throws (for example a broken localized field), `getGetInheritedValues` stays `false` for the rest of the request or worker run, and every later getter ignores inheritance. Wrap the call in `try { … } finally { setGetInheritedValues($backup); }`.
