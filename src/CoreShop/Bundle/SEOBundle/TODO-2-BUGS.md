# SEOBundle – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-27 (branch `s74-p84`).
Scope: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md) (this bundle, `Component/SEO`, and the call paths listed there).
Paths are relative to the bundle root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this bundle. Items marked **(plausible)** follow from the code but were not reproduced against a running shop. Items marked **(verified)** were confirmed by running code against the vendored libraries or by reading the vendored library source or the compiled container.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Bundle class and configuration

### B-01 — `getVersion()` / `getComposerVersion()` throw a `TypeError` in this installation — (verified)
- **Where:** `CoreShopSEOBundle.php:45-69`.
- **Problem:**
  - The fork's root package is `six-it/core-shop`, so `InstalledVersions::isInstalled('coreshop/core-shop')` is `false` (`:59`).
  - `coreshop/seo-bundle` is only **replaced** by the root package (`vendor/composer/installed.php:328-333`, no `pretty_version`). `isInstalled()` is `true`, `getPrettyVersion()` returns `null`, and the `: string` return type throws `TypeError: …::getComposerVersion(): Return value must be of type string, null returned`.
  - `getVersion()` calls it in both branches (`:48`, `:51`), so it always throws. It is used by `bin/console pimcore:bundle:list --details` (`vendor/six-it/pimcore-source/bundles/CoreBundle/src/Command/Bundle/ListCommand.php:79`), which then fails for **all** bundles.
- **Reproduced:** with the project autoloader, `InstalledVersions::isInstalled('coreshop/seo-bundle')` → `true`, `getPrettyVersion('coreshop/seo-bundle')` → `NULL`.
- **Fix:** `?? ''` after each `getPrettyVersion()` and check `six-it/core-shop` as the first package name. Same pattern in the other bundle classes (OptimisticEntityLockBundle TODO-2 **B-01**, PimcoreBundle TODO-2 **B-14**, FrontendBundle TODO-2 **B-22**); fix them together with one shared helper (TODO-3 **I-08**).

### B-02 — The configuration tree is named `core_shop_locale` instead of `core_shop_seo` — (verified)
- **Where:** `DependencyInjection/Configuration.php:29` (`new TreeBuilder('core_shop_locale')`, copy-paste from `LocaleBundle/DependencyInjection/Configuration.php:29`). The extension alias is `core_shop_seo` (derived from `CoreShopSEOExtension`; also listed in `CoreBundle/DependencyInjection/CoreShopCoreExtension.php:57`).
- **Impact:**
  - Validation errors point at the wrong bundle. Reproduced: `processConfiguration(new Configuration(), [['foo' => 1]])` → `Unrecognized option "foo" under "core_shop_locale"` although the user wrote `core_shop_seo:`.
  - Symfony's config builder generator names the class after the tree root (`vendor/symfony/config/Builder/ConfigBuilderGenerator.php:56`). There is **no** `Symfony\Config\CoreShopSeoConfig` in `var/cache/dev/Symfony/Config/` (verified), only `CoreShopLocaleConfig`, which is generated twice (once per bundle; last writer wins). A `config/packages/*.php` file using `static function (CoreShopSeoConfig $config)` fails with "class not found". As long as both trees are identical this is silent; the moment LocaleBundle or this bundle adds an option, the `CoreShopLocaleConfig` builder silently describes the wrong bundle.
  - `bin/console config:dump-reference core_shop_seo` prints `core_shop_locale:` as the root key, so copy-pasting the reference breaks the config.
- **Fix:** `new TreeBuilder('core_shop_seo')`. Also fix the node type (**B-03**).

### B-03 — `autoconfigure_with_attributes` is a `scalarNode`: any non-bool value passes validation and then crashes the container build with a `TypeError` — (verified)
- **Where:** `DependencyInjection/Configuration.php:35`, used at `DependencyInjection/CoreShopSEOExtension.php:45` and passed to `Autoconfiguration::registerForAutoConfiguration(…, bool $autoconfigureWithAttributes)` (`Component/Registry/Autoconfiguration.php:26-55`). Both files have `declare(strict_types=1)`. **(dependent)** `CoreBundle/DependencyInjection/Configuration.php:42` has the same `scalarNode`, and `CoreShopCoreExtension::prepend()` (`:129-135`) copies that value into this bundle's config unchanged.
- **Problem:** `scalarNode` accepts strings and ints. Reproduced: `processConfiguration(new Configuration(), [['autoconfigure_with_attributes' => 'no']])` returns `['autoconfigure_with_attributes' => 'no']`. With strict types, `'no'`, `'false'`, `0` or `1` (all common in YAML/ENV-driven configs) throw `TypeError: …registerForAutoConfiguration(): Argument #5 must be of type bool` during `cache:clear`; a value like `'false'` cannot even be coerced correctly. The generated `config/reference.php` shape shows `scalar|Param|null` instead of `bool`.
- **Fix:** `->booleanNode('autoconfigure_with_attributes')->defaultFalse()->end()` here and in CoreBundle (and in every other bundle that copied the node, see `CoreShopCoreExtension::$bundlesWithAttributeConfiguration`).

## Extractors and presentation (`Component/SEO`)

### B-04 — `TitleExtractor` / `DescriptionExtractor` claim to support duck-typed objects but then throw; `null` throws a `TypeError` — (verified)
- **Where:** `Component/SEO/Extractor/TitleExtractor.php:27-43`, `Component/SEO/Extractor/DescriptionExtractor.php:27-42`.
- **Problem:**
  - `supports()` returns `true` for `$object instanceof SEOAwareInterface || method_exists($object, 'getMetaTitle')` (resp. `getMetaDescription`), but `updateMetadata()` starts with `Assert::isInstanceOf($object, SEOAwareInterface::class)`. Reproduced with an object that only has `getMetaTitle()`: `supports()` → `true`, `updateMetadata()` → `Webmozart\Assert\InvalidArgumentException: Expected an instance of …SEOAwareInterface`.
  - Any Pimcore DataObject class with a field called `metaTitle` / `metaDescription` (a very common field name; Pimcore generates `getMetaTitle()` for it) that is passed to `SEOPresentationInterface::updateSeoMetadata()` therefore causes a 500 instead of SEO tags.
  - `method_exists(null, …)` → `TypeError: method_exists(): Argument #1 ($object_or_class) must be of type object|string, null given` (reproduced). A `string` (class name) passes `supports()` and then fails the assertion. `SEOPresentationInterface::updateSeoMetadata($object)` has no type, so callers get no early error.
- **Fix:** pick one contract. Either only `instanceof SEOAwareInterface` in `supports()` (recommended; the `method_exists()` fallback is the only reason for the crash), or keep duck typing and drop the assertion and call the method dynamically. Type the parameter as `object` (TODO-3 **I-04**).

### B-05 — Tags are appended, never replaced: calling `updateSeoMetadata()` twice, or combining it with document/template meta, produces duplicate `og:*` / `name` / `http-equiv` tags — (verified code path)
- **Where:** `Component/SEO/SEOPresentation.php:36-65` uses `appendProperty()`, `appendName()`, `appendHttpEquiv()`. `vendor/six-it/pimcore-source/lib/Twig/Extension/Templating/HeadMeta.php:165-200` (`append*` → `Container::append()`, no duplicate check) vs. `:285-300` (`set*` removes entries with the same key first).
- **Impact:**
  - A project that sets `og:title`/`og:type` in the layout or in the Pimcore document's "Navigation & Properties → HTML meta" (rendered through the same `HeadMeta` container) and also renders a product gets two `og:title` tags; Facebook/LinkedIn then pick the first (usually the generic one), not the product's.
  - Calling `updateSeoMetadata()` for a parent and then for a child object (e.g. category then product, or a fragment/ESI sub-request that renders a product teaser) adds a second set of `og:*` tags instead of overwriting the first.
  - The title and `description` are handled with `set` semantics (`headTitle->set()`, `setDescription()` → `setName()`), so the behaviour is inconsistent within the same method.
- **Fix:** use `setProperty()` / `setName()` / `setHttpEquiv()`. Keep `append*` only for keys that are legally repeatable (`og:image` with multiple images, see TODO-3 **I-11**).

### B-06 — Open Graph descriptions are not normalised (line breaks, HTML, full length), and HTML entities in descriptions are double-escaped — (verified code path) (dependent)
- **Where:**
  - `Component/Core/Model/Product.php:44-47` falls back to `shortDescription`, `Component/Core/Model/Category.php:30-41` to `description`; both are multi-line **textarea** fields in the shipped classes (`CoreBundle/Resources/install/pimcore/classes/CoreShopProductBundle/CoreShop{Product,Category}.json`). `Category` already calls `strip_tags()`, i.e. HTML input is expected; projects commonly switch these fields to wysiwyg (the product `description` field already is wysiwyg).
  - `HeadMeta::setDescription()` runs `normalizeString()` (`strip_tags` + one line, `vendor/six-it/pimcore-source/lib/Twig/Extension/Templating/Traits/TextUtilsTrait.php:27-43`) but **not** `html_entity_decode()`; `OGExtractor` (`Component/SEO/Extractor/OGExtractor.php:39-48`) passes the raw value to `addExtraProperty('og:description', …)`, which is not normalised at all.
  - Every value is then escaped once more with `htmlspecialchars()` (double-encode is on).
- **Impact:** `og:description` contains the full text with line breaks (and HTML tags for custom `SEOOpenGraphAwareInterface` implementations or wysiwyg fields). With wysiwyg content, search snippets and social previews show literal `&nbsp;` / `&uuml;`. Neither description is length-limited (search engines cut at ~155-160 chars, OG at ~200-300).
- **Fix:** normalise once, in `SEOPresentation` (or a small `SEOTextNormalizer`): `html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')`, collapse whitespace, trim, optionally truncate (`setDescription($text, 160, '…')`), and use the same for `og:title` / `og:description`. Remove the partial `strip_tags()` from `Category::getMetaDescription()` once the presentation normalises.

### B-07 — `og:image` can be relative or malformed — (verified code path)
- **Where:** `Component/SEO/Extractor/ImageExtractor.php:45-54`.
- **Problem:**
  - The absolute-URL check is `str_starts_with($path, 'http')`. A protocol-relative path (`//cdn.example.com/…`, what `pimcore.assets.frontend_prefixes` is typically set to) gets the host prepended: `https://shop.example.com//cdn.example.com/…`.
  - `Tool::getHostUrl()` returns `''` when there is no request host and no "Settings → System → Website → Domain" (`vendor/six-it/pimcore-source/lib/Tool.php:383-417`, logs a warning). The tag then contains a relative path, which Open Graph does not accept (`og:image` must be an absolute URL). Same for `localhost` behind a reverse proxy without `trusted_proxies`.
  - Pimcore already has the correct helper: `ImageThumbnailTrait::getFrontendPath()` (`vendor/six-it/pimcore-source/models/Asset/Thumbnail/ImageThumbnailTrait.php:317-325`) uses `preg_match('@^(https?|data):@')` and the frontend prefixes. The extractor re-implements it worse. A `data:` URI (possible for inline/placeholder thumbnails) must not be used as `og:image` at all.
- **Fix:** `$url = $thumbnail->getFrontendPath();`, skip the tag when the result is not an absolute `http(s)://` URL (`str_starts_with($url, '//')` → prefix the request scheme), and log once. Together with TODO-1 **C-01**.

### B-08 — Registering a custom extractor with the type of a built-in one (to replace it) crashes the first page that uses SEO, not the container build — (verified code path)
- **Where:** `Component/Registry/RegisterSimpleRegistryTypePass.php:56-72` adds one `register` method call per tag; `Component/Registry/PrioritizedServiceRegistry.php:37-50` throws `ExistingServiceException` for a duplicate identifier when the registry service is **instantiated**.
- **Problem:** the documentation tells users to tag custom extractors with `{ name: coreshop.seo.extractor, type: … }` (`docs/03_Bundles/SEO_Bundle.md:72-79`) but does not list the reserved types (`description`, `title`, `opengraph`, `image`, `document`). Using one of them (the natural way to "override the title extractor") builds the container fine and then throws on every product/category page, because `SEOPresentation` is constructed lazily per request. The same happens with autoconfiguration when two classes have the same short name (type derived via `Container::underscore()` of the short class name, `:61-63`).
- **Fix:** detect duplicates in `ExtractorRegistryServicePass` (override `process()` or fix `RegisterSimpleRegistryTypePass`) and either throw a clear `InvalidArgumentException` at compile time or let the later definition replace the earlier one (documented decoration path). Document the reserved types and how to replace a built-in extractor (decorate the service id `CoreShop\Component\SEO\Extractor\TitleExtractor`).

### B-09 — The registry gets autowiring aliases of the wrong type — (verified container) (dependent)
- **Where:** `Resources/config/services.yml:8-14` (tag `coreshop.registry`, `type_hint: seoExtractors`) → `PimcoreBundle/DependencyInjection/Compiler/RegisterTypeHintRegistriesPass.php:41-73`; and `Component/Registry/RegisterSimpleRegistryTypePass.php:50-54` (via `ExtractorRegistryServicePass`).
- **Evidence:** compiled container lines 56739, 56741 and 57019: `CoreShop\Component\Registry\ServiceRegistryInterface $seoExtractorsRegistry`, `CoreShop\Component\Registry\ServiceRegistry $seoExtractorsRegistry` and `CoreShop\Component\Registry\ServiceRegistryInterface $seoExtractorServiceRegistry` all point to `coreshop.registry.seo.extractor`, a `PrioritizedServiceRegistry` that implements **neither** `ServiceRegistryInterface` nor extends `ServiceRegistry`. There is no `PrioritizedServiceRegistryInterface $…` alias.
- **Impact:** a service that autowires one of these documented-looking argument names gets a `TypeError` when it is built. A service that type-hints the correct `PrioritizedServiceRegistryInterface` cannot be autowired by name.
- **Fix:** register `PrioritizedServiceRegistryInterface` aliases for prioritized registries in both passes (PimcoreBundle TODO-2 **B-15** describes the same root cause). In this bundle, add an explicit alias `CoreShop\Component\Registry\PrioritizedServiceRegistryInterface $seoExtractorRegistry: '@coreshop.registry.seo.extractor'` until the passes are fixed.

### B-10 — Category meta description does not fall back when the meta field is an empty string — (plausible) (dependent)
- **Where:** `Component/Core/Model/Category.php:30-41` uses `null !== $this->getPimcoreMetaDescription()`, while `Product.php:39-47` and `Category::getMetaTitle()` use `?:`.
- **Impact:** a category whose `pimcoreMetaDescription` was edited and cleared (Pimcore stores `''` for an emptied textarea in many cases) returns `''`; `DescriptionExtractor` skips it (truthiness check) and the category page has **no** meta description instead of the `description` fallback. Same for `getPimcoreMetaDescription()` returning whitespace only.
- **Fix:** `$meta = trim((string) $this->getPimcoreMetaDescription($language)); if ($meta !== '') { return $meta; }` (or `?:` like Product). Add a Behat scenario for categories (the feature file covers products only).

### B-11 — Head placeholders are not reset between requests in long-running workers; SEO tags of the previous request leak into the next one — (plausible) (dependent)
- **Where:** `SEOPresentation` writes into `Pimcore\Twig\Extension\Templating\HeadMeta` / `HeadTitle`, which keep their state in the shared `Placeholder\ContainerService` (compiled container line 39704: no `kernel.reset` tag, the class does not implement `ResetInterface`).
- **Impact:** with FrankenPHP worker mode, RoadRunner or Swoole (`runtime/*`), the second request served by a worker still has the first request's `og:*` tags and title; because of **B-05** the list also grows with every request (memory). PHP-FPM is not affected.
- **Fix:** root cause is in Pimcore (tag `ContainerService` with `kernel.reset` → method that clears `$_items`). Until then document that SEO is not worker-safe, or register a `kernel.reset`-tagged service in this bundle that clears the `HeadMeta`/`HeadTitle` containers.

## Documentation

### B-12 — The bundle documentation describes a service id and an API that do not exist — (verified)
- **Where:** `docs/03_Bundles/SEO_Bundle.md` (outside the bundle).
- **Problem / Fix:**
  - `:35-40`: `$this->get('coreshop.seo.presentation')` — the service id `coreshop.seo.presentation` does not exist (0 matches in the compiled container); the service is `CoreShop\Component\SEO\SEOPresentationInterface`. `AbstractController::get()` no longer exists in Symfony 6+/7. Show constructor injection of `SEOPresentationInterface` instead (or add `coreshop.seo.presentation` as a deprecated alias for BC).
  - `:46-48`: `SEOImageAwareInterface` is listed twice; the third entry must be `SEOOpenGraphAwareInterface`. Typo "pg-description" → `og:description`. `PimcoreSEOAwareInterface` is not mentioned.
  - `:8-12`: `composer require coreshop/seo-bundle:^4.0` does not match this fork (`six-it/core-shop`, replaced package, see **B-01**).
  - Missing: reserved extractor types and how to replace one (**B-08**), `priority` tag attribute and execution order (TODO-3 **I-09**), `autoconfigure_with_attributes` / `#[AsSEOExtractor]`, and the `coreshop_seo` thumbnail dependency (TODO-1 **C-01**).
