# SEO Component – TODO 1: Critical errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-28 (branch `s74-p84`).
Scope: every PHP file of `src/CoreShop/Component/SEO` (14 files: `SEOPresentation{,Interface}.php`, `Extractor/*.php` ×6, `Model/*.php` ×6) and `composer.json`, plus the config files that wire or use this component: `SEOBundle/Resources/config/services.yml`, `SEOBundle/Resources/config/services/extractor.yml`, `SEOBundle/DependencyInjection/{CoreShopSEOExtension,Configuration}.php`, `SEOBundle/DependencyInjection/Compiler/ExtractorRegistryServicePass.php`, `FrontendBundle/Resources/install/pimcore/image-thumbnails.yml` (`coreshop_seo`), `FrontendBundle/Resources/views/layout.html.twig`, and the consumers `Component/Core/Model/{Product,Category}{,Interface}.php`, `Component/Product/Model/Product.php` (`getImage()`), `FrontendBundle/Controller/{Frontend,Product,Category}Controller.php` and `Behat/Context/Domain/SEOContext.php`. Call paths were followed into `Component/Registry` (`PrioritizedServiceRegistry`, `PriorityMap`) and into Pimcore (`HeadMeta`, `HeadTitle`, `Placeholder/*`, `Asset/Image/Thumbnail`, `ImageThumbnailTrait`, `Thumbnail/Processor`, `Tool::getHostUrl()`).
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/` (Symfony 7.4.21, `six-it/pimcore-source`). Items marked **(dependent)** have their root cause or part of the fix outside this component. Items marked **(verified)** were checked with a PHP 8.4.27 script against the component code and the vendored libraries. Items marked **(plausible)** follow from the code, but the trigger depends on data, configuration or custom code.

"Critical" here means: a security issue, data loss, or an error that takes down every request or a whole area (here: product or category detail pages) once it is triggered.

**No Symfony 7.4 / PHP 8.4 blocker was found in this component.** `php -l` with `error_reporting=-1` (PHP 8.4.27) passes on every file, there are no implicitly nullable parameters, and the compiled dev container's deprecation log has no entry for this component. The component is byte-identical to the vendored `six-it/core-shop` copy (`diff -r` is empty).

**Checked and not an issue:** output escaping. `HeadMeta` and `HeadTitle` escape every value with `htmlspecialchars()` (see SEOBundle TODO-1, "Checked and not an issue"). The component writes nothing to the database.

**Most of this component was already scanned as part of the SEOBundle TODOs** (the bundle has no logic of its own). Those items are **not repeated** here. Every SEOBundle item whose code lives in this component is listed in the overlap table at the end of this file, so this file is the full map for the component.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Error isolation

### C-01 — An exception in any extractor turns the whole product or category detail page into an HTTP 500. SEO metadata has no error isolation — (verified code path, trigger plausible) (dependent)
- **Where:** `SEOPresentation.php:67-81` (`extractSeoMetaData()` calls `supports()` / `updateMetadata()` for every registered extractor, with no `try`/`catch`) and `SEOPresentation.php:36-65` (writes into `HeadMeta` / `HeadTitle`, which can throw). Callers: `FrontendBundle/Controller/ProductController.php:48,66` and `FrontendBundle/Controller/CategoryController.php:245`. They run **before** the template is rendered, so nothing of the page is shown.
- **Problem:** SEO metadata is optional for a page, but any failure while it is built is fatal. Known triggers:
  - The `coreshop_seo` thumbnail definition is missing: `NotFoundException` from `ImageExtractor.php:45`. This is SEOBundle TODO-1 **C-01**. That item has the local fix for this one trigger.
  - Thumbnail generation fails. `vendor/six-it/pimcore-source/models/Asset/Image/Thumbnail/Processor.php:132-149` sets `$deferred = false` for `isFrontendRequestByAdmin()` (the admin preview of a product page), so the image is processed **synchronously** inside `getPath()`. A broken or unsupported source image (CMYK TIFF, truncated upload, Imagick policy limit) or a storage error (S3 / Flysystem outage) then throws from `ImageExtractor`.
  - A duck-typed object passes `supports()` and fails `Assert::isInstanceOf()` in `TitleExtractor` / `DescriptionExtractor` (SEOBundle TODO-2 **B-04**).
  - `HeadMeta` rejects a key or value with a `TypeError` (TODO-2 **B-01**, verified).
  - Any custom extractor registered through the `coreshop.seo.extractor` tag or `#[AsSEOExtractor]` (third-party code, the documented extension point).
- **Impact:** one bad image or one buggy extractor takes down every detail page that reaches it. Category pages are affected too (title, description and Open Graph extractors run for every category).
- **Fix:**
  - In `extractSeoMetaData()`, wrap each `supports()` + `updateMetadata()` pair in `try { … } catch (\Throwable $e)`. Log a `warning` with the extractor class, the object class and the id (`$object->getId()` when it exists), then continue with the next extractor. Inject an optional `?Psr\Log\LoggerInterface $logger = null`, so the component stays framework-free (`psr/log` in `composer.json`, TODO-3 **I-08**).
  - Wrap the `HeadMeta` / `HeadTitle` writes (`:40-64`) the same way, per entry. One rejected tag must not stop the others.
  - Add a `bool $strict = false` constructor flag (wire it to `%kernel.debug%` in `SEOBundle/Resources/config/services.yml`) that rethrows. Errors stay visible in dev, while prod degrades to "no or fewer SEO tags".
  - Keep the local fixes (SEOBundle TODO-1 **C-01**, TODO-2 **B-04**). This item is the safety net, not a replacement for them.
  - Add a unit test: an extractor that throws, followed by one that sets the title. The title must still be rendered and the exception must be logged.

---

## Overlap with other TODOs (not repeated here)

All of the following are in `src/CoreShop/Bundle/SEOBundle/TODO-*.md`. The code to change is in **this** component unless noted.

| SEOBundle item | Component file(s) | Short description |
|---|---|---|
| TODO-1 **C-01** | `Extractor/ImageExtractor.php:45` | Missing `coreshop_seo` thumbnail definition → 500 on every product detail page with an image. |
| TODO-2 **B-04** | `Extractor/TitleExtractor.php:27-43`, `Extractor/DescriptionExtractor.php:27-42` | `supports()` accepts duck-typed objects (`method_exists`) and `updateMetadata()` then fails the assertion. `null` gives a `TypeError`. |
| TODO-2 **B-05** | `SEOPresentation.php:40-56` | `append*` instead of `set*`, so tags are duplicated across calls and with layout or document meta. |
| TODO-2 **B-06** | `Extractor/OGExtractor.php:39-48`, `SEOPresentation.php:62-64` | Descriptions are not normalised (line breaks, HTML, entities, length). TODO-2 **B-02** here extends it to titles. |
| TODO-2 **B-07** | `Extractor/ImageExtractor.php:45-54` | `og:image` can be relative or malformed (`str_starts_with($path, 'http')`, empty host). TODO-2 **B-03** here adds the frontend-context cause. |
| TODO-2 **B-08** | (registry, bundle) | Registering an extractor with a built-in type crashes at runtime instead of at compile time. |
| TODO-2 **B-09** | (bundle) | Autowiring aliases of the wrong registry type for `coreshop.registry.seo.extractor`. |
| TODO-2 **B-10** | (`Component/Core/Model/Category.php`) | The category meta description does not fall back when it is `''`. |
| TODO-2 **B-11** | `SEOPresentation.php` (state in Pimcore `ContainerService`) | Head placeholders leak between requests in worker runtimes. |
| TODO-2 **B-12** | (docs) | Wrong service id and API in `docs/03_Bundles/SEO_Bundle.md`. |
| TODO-3 **I-01** | `composer.json` | No `php` constraint, PHPStan 1.x, stray indentation, dev-only settings. TODO-3 **I-08** here has the delta. |
| TODO-3 **I-04** | all files | Native types instead of docblocks and assertions, `final readonly` extractors, typed `SEOMetadata` properties, wrong `@return` / `@var` docblocks. |
| TODO-3 **I-09** | (`extractor.yml`, `PriorityMap`) | Extractor order is undefined (all priority 1000, reverse order on ties). |
| TODO-3 **I-10** | `Model/SEOMetadata.php`, `SEOPresentation.php` | Dead state (`id`, `locale`, `metaKeywords`, `originalUrl`), unreachable `toArray()` branches, inconsistent empty checks, `getImage()` called twice. TODO-2 **B-05** here gives `locale` a purpose. |
| TODO-3 **I-11** | `SEOPresentation.php`, `Extractor/*` | Feature gaps: canonical / `og:url`, image size and alt, `og:site_name`, `twitter:card`, product price tags, multiple images. |
| TODO-3 **I-12** | (tests) | Behat covers only the product title and description. TODO-3 **I-09** here has the component unit-test delta. |
