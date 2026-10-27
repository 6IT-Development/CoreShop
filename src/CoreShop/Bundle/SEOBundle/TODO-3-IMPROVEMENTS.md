# SEOBundle – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, Symfony 7.4 / PHP 8.4 modernisation and forward-compatibility items (Symfony 8). None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as sub-bullets.
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(verified)** were confirmed by running code against the vendored libraries or by reading the vendored library source or the compiled container.

**Already fine for Symfony 7.4:** the extension extends `Symfony\Component\DependencyInjection\Extension\Extension` (not the deprecated `HttpKernel\DependencyInjection\Extension`), service config is YAML (not the XML format deprecated in 7.4), `TreeBuilder` is constructed with a root name, no `ContainerAwareInterface`, no removed `Kernel`/`Bundle` APIs.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## Symfony 7.4 / PHP 8.4 compatibility and forward compatibility

### I-01 — `composer.json` (bundle and component) does not describe what the code needs
- **Where:** `composer.json:26-47`, `Component/SEO/composer.json:26-57`.
- **Problem / Fix:**
  - No `php` constraint in either file. Add `"php": "^8.4"`.
  - Bundle: directly used packages are not declared: `symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel` (all `^7.4`), `coreshop/registry` (`ExtractorRegistryServicePass`, `Autoconfiguration`, `PrioritizedServiceRegistry` in `services.yml`), `coreshop/pimcore-bundle` (provides the `coreshop.registry` tag processor `RegisterTypeHintRegistriesPass`; without it the tag is silently ignored).
  - Bundle: `CoreShopSEOBundle::getVersion()` reaches into `coreshop/core-bundle` (`Application\Version`, `:47-48`) without declaring it. Remove the coupling (**I-08**) instead of adding a dependency on the core bundle.
  - Component: `composer/composer` (`InstalledVersions`) is not needed there, but `webmozart/assert` `^1.10` should be bumped to `^1.11` (PHP 8.4-tested) or dropped together with the assertions (**I-04**).
  - Both: `require-dev` pins PHPStan 1.x; PHPStan 2 is current and has the PHP 8.4 rules. Bump together with the other bundles.
  - Both: `"minimum-stability": "dev"` and `repositories` (`path` `../../*/*`) in a library package have no effect for consumers; drop them or move them to the root project. `Component/SEO/composer.json:49` has a stray indentation (`   "extra"`).

### I-02 — Configuration: right root name, right node types, and the options the code currently hard-codes
- **Where:** `DependencyInjection/Configuration.php`.
- **Fix:**
  - Root name and `booleanNode`: see TODO-2 **B-02** / **B-03**.
  - `:30` the `/** @var ArrayNodeDefinition $rootNode */` and the import `:21` are not needed (`TreeBuilder::getRootNode()` is generic-typed in 7.x).
  - Add the values that are hard-coded in `Component/SEO` today and inject them: `image_thumbnail` (default `coreshop_seo`, TODO-1 **C-01**), `description_max_length` (default `160`, TODO-2 **B-06**), optionally `og_site_name` / `twitter_card` (**I-11**). Add `->info()` texts so `config:dump-reference core_shop_seo` documents them.

### I-03 — Extension: dead code and naming; consider `AbstractBundle`
- **Where:** `DependencyInjection/CoreShopSEOExtension.php:32-47`.
- **Fix:**
  - `:38` `$container->getParameter('kernel.bundles');` — result is discarded; remove.
  - `:34` `$configs = $this->processConfiguration(...)` overwrites the input with a different shape; name it `$config`.
  - `:35` `__DIR__ . '/../Resources/config'` → `\dirname(__DIR__) . '/Resources/config'`.
  - Optional (Symfony ≥ 6.1): merge bundle class, extension and configuration into one `AbstractBundle` (`configure()`, `loadExtension()`, `prependExtension()`). The bundle then needs `PimcoreBundleInterface` implemented directly (Pimcore's `AbstractPimcoreBundle` extends the plain `Bundle`). Only worth doing if done for all CoreShop bundles at once.
  - Optional: move `services.yml` to `services.php` (`ContainerConfigurator`) for static analysis of class names; YAML is not deprecated, so this is not required.

### I-04 — `Component/SEO`: use native PHP 8.x types instead of docblocks and assertions
- **Where:** `Extractor/*.php`, `Model/*.php`, `SEOPresentation*.php`.
- **Fix:**
  - `ExtractorInterface::supports($object)` / `updateMetadata($object, …)` and `SEOPresentationInterface::updateSeoMetadata($object)` → `object $object` (implementations without a type stay compatible, parameter types are contravariant). Removes the `null`/`string` cases of TODO-2 **B-04**.
  - Inside the extractors, replace `Assert::isInstanceOf()` + `/** @var X $object */` with an `if (!$object instanceof X) { return; }` guard (or nothing, since `supports()` was checked). Then `webmozart/assert` is no longer needed in the component.
  - Extractors are stateless: `final readonly class` (PHP 8.2).
  - `Model/SEOMetadata.php`: typed properties (`private ?string $title = null;`, `private array $extraProperties = [];` with `@var array<string, string>`), return types on `setId()`, `removeExtraProperty()`, `removeExtraName(string $key)` (`:74`, `:155`, `:177` have none / untyped parameter), `getLocale(): ?string` (`:95`). Drop `@psalm-suppress MissingConstructor` once the properties have defaults.
  - `Model/SEOMetadataInterface.php`: the `@return string` docblocks (`:31`, `:46`, `:58`) contradict the `?string` return types; remove them.
  - `Model/PimcoreSEOAwareInterface.php:25,29`: setters have no return type; add `: static` (matches Pimcore's generated setters).
  - `Extractor/ImageExtractor.php:42-44`: `/** @var Image\Thumbnail $thumbnail */` is wrong (`getThumbnail()` returns `ThumbnailInterface`); remove.
  - `SEOPresentation.php:70-73`: `/** @var ExtractorInterface $extractor */` → type the registry as `PrioritizedServiceRegistryInterface<ExtractorInterface>` (generic `@template`) once Registry supports it.

### I-05 — `#[AsSEOExtractor]`: modern attribute and priority support
- **Where:** `Attribute/AsSEOExtractor.php`, `Component/Registry/Autoconfiguration.php:49-72` **(dependent)**.
- **Fix:**
  - `public function __construct(public readonly ?string $type = null, public readonly ?int $priority = null)`. Keep `getType()` (and add `getPriority()`) because `Autoconfiguration::getProperties()` builds the tag attributes from public `get*` methods; `priority` then flows into the tag that `RegisterSimpleRegistryTypePass` reads (`$tag['priority'] ?? 1000`). Filter `null` values out in `getProperties()` so `type: null` is not written as a tag attribute.
  - When `autoconfigure_with_attributes: true`, interface-based autoconfiguration is switched **off** (`Autoconfiguration.php:33-40`), so existing extractors that only implement `ExtractorInterface` silently stop being registered. Document this, or register both (attribute wins when present).
  - Type the closure parameters in `registerAttributeForAutoconfiguration()` (`ChildDefinition $definition, object $attribute, \Reflector $reflector`).

### I-06 — `services.yml`: drop the blanket `public: true`, autowire, explicit extractor priorities
- **Where:** `Resources/config/services.yml`, `Resources/config/services/extractor.yml`.
- **Fix:**
  - `_defaults: public: true` (`:4-6`) makes the registry and `SEOPresentation` public. No caller needs it: `FrontendController::getSubscribedServices()` uses a service locator (compiled container lines ~49997ff. `service_closure`), the Behat context gets constructor injection. Make them private; keep only `SEOPresentationInterface` public for one minor version (BC for `$container->get()` callers) and deprecate it with `deprecated:`.
  - `SEOPresentation` arguments (`:17-21`) can be autowired (`autowire: true`, bind `$extractorRegistry: '@coreshop.registry.seo.extractor'`). The list items at `:19-21` are not indented under `arguments:` (valid YAML, but inconsistent with every other file).
  - Give the built-in extractors explicit `priority` tag attributes (see **I-09**).
  - Add `coreshop.seo.presentation` as a deprecated alias if the documented id should keep working (TODO-2 **B-12**).

## Readability and structure

### I-07 — `ExtractorRegistryServicePass`: formatting and constants
- **Where:** `DependencyInjection/Compiler/ExtractorRegistryServicePass.php:25-34`.
- **Fix:** `public function __construct(\n        ) {` is a broken formatter artefact; write `public function __construct()`. Use typed class constants (PHP 8.3) and `final` constants: `final public const string EXTRACTOR_TAG = 'coreshop.seo.extractor';`. Consider constants for the registry id / parameter name too, and reuse them in `services.yml` comments and the docs. Duplicate detection: TODO-2 **B-08**.

### I-08 — Bundle class: shared version helper, no reach into CoreBundle
- **Where:** `CoreShopSEOBundle.php:45-69`.
- **Fix:**
  - The SEO bundle should not know about `CoreShop\Bundle\CoreBundle\Application\Version` (`:47-48`, string class name with a leading backslash instead of `::class`). Move the "CoreShop version + package version" logic into one shared trait/helper used by all CoreShop bundle classes (fixes TODO-2 **B-01** in one place; same proposal as OptimisticEntityLockBundle TODO-3 **I-07** / PimcoreBundle TODO-3 **I-14**).
  - `getNiceName()` / `getDescription()` return near-identical strings; fine, but `getDescription()` could say what the bundle does ("SEO metadata extractors for Pimcore HeadMeta/HeadTitle").

### I-09 — Make the extractor execution order explicit — (verified)
- **Where:** `Resources/config/services/extractor.yml` (no `priority`), `Component/Registry/RegisterSimpleRegistryTypePass.php:68` (default `1000`), `Component/Registry/PriorityMap.php:159-166` (ascending order; for equal priorities the **later** registered entry comes first).
- **Problem:** all five built-ins have priority `1000`, so the order is only defined by the tie-breaker. Reproduced with the vendored classes: `all()` returns `DocumentExtractor, ImageExtractor, OGExtractor, TitleExtractor, DescriptionExtractor`, i.e. the **reverse** of the file order. Because later extractors overwrite earlier values ("last write wins"), whether a custom extractor overrides a built-in depends on service definition order, which nobody documents. Overlaps exist today (`TitleExtractor`/`DocumentExtractor` both set the title, `DescriptionExtractor`/`DocumentExtractor` both set the description).
- **Fix:** explicit priorities in `extractor.yml` (e.g. `document: 100`, `title: 200`, `description: 200`, `opengraph: 300`, `image: 300`), document "lower priority runs first, higher priority runs later and wins", and make the tie-breaker in `PriorityMap` keep registration order (stable) **(dependent)**.

### I-10 — `SEOMetadata` / `SEOPresentation`: remove dead state, small logic cleanups
- **Where:** `Component/SEO/Model/SEOMetadata.php`, `Component/SEO/SEOPresentation.php`.
- **Fix:**
  - `id`, `locale`, `metaKeywords` (+ getters/setters) are never read or written by any extractor or by `SEOPresentation` (Doctrine-entity leftovers from the Symfony CMF origin). `originalUrl` is set by nobody and never rendered. Remove them, or implement them (**I-11**).
  - `toArray()` (`:209-225`): the `\Traversable` and exception branches are unreachable because the setters take `array`. Either accept `iterable` in the setters or drop the helper.
  - `SEOPresentation::updateSeoMetadata()` (`:36-65`): `if ($x = $seoMetadata->getExtraProperties()) { foreach … }` — the assignment-in-condition is unnecessary (`foreach` over `[]` is a no-op); iterate directly.
  - `extractSeoMetaData()` (`:67-81`) returns the concrete `SEOMetadata` and instantiates it with `new`; return `SEOMetadataInterface` and allow a factory/callable so projects can extend the model without overriding the presentation.
  - Empty-value handling is inconsistent: `TitleExtractor` uses `!== null` (so `''` overwrites an earlier title), the other extractors and `SEOPresentation` use truthiness (so the string `"0"` is dropped). Use one helper (`trim((string) $v) !== ''`).
  - `ImageExtractor`: `supports()` and `updateMetadata()` both call `$object->getImage()` (for `Product` that is `getImages()` twice, `Component/Product/Model/Product.php:29-36`); read it once in `updateMetadata()` and return early when it is `null`.

### I-11 — SEO feature gaps worth closing while touching the code
- **Where:** `Component/SEO/SEOPresentation.php`, `Component/SEO/Extractor/*`.
- **Suggestions:**
  - Canonical URL and `og:url`: `SEOMetadata::originalUrl` exists but is unused. Add an extractor that uses the link generator (`pimcore_url` / CoreShop `LinkGeneratorInterface`) and render `<link rel="canonical">` via `HeadLink` and `og:url`. Category pages with filters/sorting/pagination (`FrontendBundle/Resources/views/Common/paginator.html.twig`) especially benefit.
  - `og:image:width` / `og:image:height` / `og:image:alt` (the thumbnail knows its size; alt from the asset metadata), `og:site_name` (store name), `og:locale`, `twitter:card` (`summary_large_image` when an image exists).
  - `og:type` for products is `product` (`Component/Core/Model/Product.php:59-62`); with `product:price:amount` / `product:price:currency` the tags become useful for shopping previews (needs the price calculator, so better in CoreBundle).
  - Multiple product images as repeated `og:image` (the only place where `append*` is correct, TODO-2 **B-05**).

### I-12 — Tests
- **Where:** `features/domain/seo/seo.feature`, `src/CoreShop/Behat/Context/Domain/SEOContext.php`.
- **Fix:** the feature only covers product title/description. Add scenarios for categories (incl. TODO-2 **B-10**), Open Graph tags, `og:image` with and without the thumbnail definition (TODO-1 **C-01**), a Pimcore `Page`, a custom extractor that overrides a built-in (TODO-2 **B-08**, **I-09**), and calling `updateSeoMetadata()` twice (TODO-2 **B-05**). The extractors are plain classes: add PHPUnit tests for them and for `Configuration` (`core_shop_seo` root, boolean node).
