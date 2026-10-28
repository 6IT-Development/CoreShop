# SEO Component – TODO 3: Improvements

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-28 (branch `s74-p84`).
Scope and references: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are code quality, readability, logic, PHP 8.4 modernisation and forward-compatibility items. None of them is a functional bug on its own. Where an item merges several findings, the extra ones are listed as sub-bullets.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this component.

**Overlap with other TODOs:** SEOBundle TODO-3 **I-01**, **I-04**, **I-09**, **I-10**, **I-11** and **I-12** are about code in this component (native types, `final readonly` extractors, typed `SEOMetadata` properties, wrong docblocks, extractor order, dead state, feature gaps, tests). They are listed in [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md) and are **not repeated** here. The items below only add what those items do not cover. Do SEOBundle TODO-3 **I-04** first, because several items here build on it.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-2-BUGS.md](TODO-2-BUGS.md)

---

## PHP 8.4 modernisation (delta to SEOBundle TODO-3 I-04)

### I-01 — Use the PHP 8.3 / 8.4 features that SEOBundle I-04 does not list
- **Where:** all files.
- **Fix:**
  - `#[\Override]` (PHP 8.3) on every interface implementation (`supports()`, `updateMetadata()`, `updateSeoMetadata()`, every `SEOMetadata` getter and setter). A renamed interface method then fails at compile time instead of silently leaving dead code.
  - `SEOPresentation.php:29-34`: constructor-promoted `protected readonly` properties. The dependencies are never reassigned.
  - `Model/SEOMetadata.php`: PHP 8.4 asymmetric visibility (`public private(set) array $extraProperties = [];`) or property hooks could replace the trivial getter / setter pairs. **Recommendation:** keep the methods, because the interface and BC for custom extractors need them, and use typed properties only (SEOBundle **I-04**). Re-evaluate when the interface is redesigned (**I-03**).
  - `Model/SEOMetadata.php:155`, `:177`, `:199`: `if (array_key_exists($key, $arr)) { unset($arr[$key]); }` → `unset($this->extraProperties[$key]);` (`unset` of a missing key is a no-op).
  - `Behat/Context/Domain/SEOContext.php` (`productShouldHaveMetaDescription()`): the manual `foreach` / `break` search for the description item → `array_find($items, static fn ($i) => $i instanceof \stdClass && ($i->name ?? null) === 'description')` (PHP 8.4) **(dependent)**.

## Architecture and API

### I-02 — Split "extract" from "render". `SEOPresentation` hard-wires Pimcore's Twig helpers and returns nothing
- **Where:** `SEOPresentationInterface.php:23`, `SEOPresentation.php:27-82`.
- **Problem:**
  - `updateSeoMetadata(): void` writes straight into `HeadMeta` / `HeadTitle`. A caller cannot read the result. A headless API, JSON-LD, `hreflang`, a sitemap or a test must render HTML and parse `HeadMeta` (that is what `Behat/Context/Domain/SEOContext.php` does).
  - A "component" depends on `Pimcore\Twig\Extension\Templating\*` (rendering) as well as on Pimcore models. The extraction logic is framework-neutral, and the rendering is not.
  - `SEOPresentation` is a non-`final` class with `protected` properties and a `protected extractSeoMetaData()`, so the only way to customise it is inheritance. `extractSeoMetaData()` returns the concrete `SEOMetadata` (`:67`).
- **Fix:**
  - Add `SEOMetadataExtractorInterface::extract(object $object, ?string $locale = null): SEOMetadataInterface` (the current `extractSeoMetaData()` logic, plus TODO-1 **C-01** isolation and TODO-2 **B-05** locale).
  - Keep `SEOPresentationInterface::updateSeoMetadata()` as a thin "extract + apply to `HeadMeta` / `HeadTitle`" (an `SEOMetadataRendererInterface`). BC: the signature stays, and the return type can change from `void` to `SEOMetadataInterface` in the next major.
  - Make both classes `final` and document decoration (`decorates:`) as the extension mechanism.
  - Create the metadata through a small factory (or `?\Closure $metadataFactory`), so projects can use their own `SEOMetadataInterface` implementation without overriding the class.

### I-03 — Complete `SEOMetadataInterface`, and make the merge policy from `ExtractorInterface` real
- **Where:** `Model/SEOMetadataInterface.php`, `Extractor/ExtractorInterface.php:36-46`.
- **Problem:**
  - The interface lacks `removeExtraProperty()`, `removeExtraName()`, `removeExtraHttp()`, `getLocale()` / `setLocale()` and any `has*()`. A custom extractor that receives `SEOMetadataInterface` cannot remove a tag that an earlier extractor set without depending on the concrete class.
  - `ExtractorInterface::updateMetadata()` says "It is up to the strategy to check if certain fields are already set by previous strategies and decide on a merge strategy", but no built-in extractor checks anything. They all overwrite, so the effective policy is "last one wins", and the order is undefined (SEOBundle TODO-3 **I-09**). `DocumentExtractor` and `TitleExtractor` / `DescriptionExtractor` both write the title and the description.
  - Arrays are untyped (`array`) in the interface (TODO-2 **B-01**).
- **Fix:**
  - Add the missing methods to the interface (a BC break for custom implementations, so do it in the next major or through a new `SEOMetadataInterface` v2 extending the old one).
  - Add `@param array<string, string>` / `@return array<string, string>` to the extra-* accessors.
  - Decide and document one policy. **Recommendation:** "later (higher priority) extractors override, and an empty value never overrides". Implement it in `SEOMetadata` setters (ignore `''` after trimming) instead of in each extractor. Rewrite the docblock accordingly.

### I-04 — `DocumentExtractor`: unused in the shipped code, and narrower than it looks
- **Where:** `Extractor/DocumentExtractor.php:25-47`.
- **Problem / Fix:**
  - No shipped caller passes a document to `updateSeoMetadata()` (only `ProductController` and `CategoryController` call it, with data objects). The extractor is only useful for projects that call it from their own document controllers. Document this in `docs/03_Bundles/SEO_Bundle.md` **(dependent)**, or call it from `FrontendBundle`'s default document controller.
  - It supports only `Document\Page`. `Document\Hardlink` wrappers (`Document\Hardlink\Wrapper\Page`) work because they extend `Page`, but `Document\Snippet`, `Document\Link` and `Document\Email` are ignored without a hint. That is fine, but state it in the class docblock.
  - Pimcore pages also have custom meta tags (`Page::getMetaData()`, "Navigation & Properties → HTML meta"). Pimcore renders those itself, so do not duplicate them here. Add a comment that this is intentional, or map them into `SEOMetadata` so that SEOBundle TODO-2 **B-05** deduplication works across both sources.
  - Normalise the title and description (TODO-2 **B-03**, SEOBundle TODO-2 **B-06**). Editors often paste multi-line text into the description field.

### I-05 — `SEOImageAwareInterface` and `ImageExtractor`: hard-coded, single image, Pimcore-only
- **Where:** `Model/SEOImageAwareInterface.php:23-26`, `Extractor/ImageExtractor.php:27-56`.
- **Fix:**
  - Inject the thumbnail name through the constructor (`string $thumbnailName = 'coreshop_seo'`), and do not hard-code it at `:45` (SEOBundle TODO-3 **I-02** adds the config option, and this is the component side).
  - `og:image` is the only Open Graph tag that may repeat. Add an optional `SEOImagesAwareInterface::getSEOImages(): iterable<Image>` (or reuse `getImages()`), so a product can expose more than its first image (SEOBundle TODO-3 **I-11**). This needs `append` semantics for `og:image` only (SEOBundle TODO-2 **B-05**).
  - Emit `og:image:width` / `og:image:height` from `$thumbnail->getWidth()` / `getHeight()`, and `og:image:alt` from the asset's `alt` metadata. Then the extra properties must hold strings (TODO-2 **B-01**).

### I-06 — `SEOPresentation::updateSeoMetadata()`: one loop instead of three copies
- **Where:** `SEOPresentation.php:40-56`.
- **Fix:** the three `if ($x = …) { foreach … }` blocks differ only in the getter and the `HeadMeta` method. Use a single map, `['Property' => $m->getExtraProperties(), 'Name' => $m->getExtraNames(), 'HttpEquiv' => $m->getExtraHttp()]`, and one loop that calls `$this->headMeta->{'set' . $type}((string) $key, $value)` (with `set`, see SEOBundle TODO-2 **B-05**). Better still, call the explicit methods in a small private `applyTags(string $type, array $tags)` helper, so static analysis still sees the calls. This is also the single place for the per-tag `try`/`catch` (TODO-1 **C-01**).

## Readability

### I-07 — Docblocks and comments
- **Where:** `Extractor/ExtractorInterface.php:25-46`, `Model/SEOMetadataInterface.php`, `Extractor/*.php`.
- **Fix:**
  - `ExtractorInterface`: "strategy" and "content" are Symfony CMF wording (the component was ported from `SeoBundle`). Say "extractor" and "object". `@param object               $object` (`:44`) has stray alignment spaces. Both `@param` lines become redundant once the parameter is typed `object` (SEOBundle TODO-3 **I-04**).
  - Add a one-line class docblock to each extractor that says which interface it reacts to and which tags it writes (`TitleExtractor` → `<title>`, `OGExtractor` → `og:title`, `og:description`, `og:type`, and so on). Today you have to read the code to find out which extractor produces a tag.
  - `SEOMetadataInterface`: "Gets the original URL … depending on your settings" refers to CMF settings that do not exist here. Remove the sentence, or implement the canonical URL (SEOBundle TODO-3 **I-11**).
  - Inline `/** @var X $object */` before `Assert::isInstanceOf()` is placed **above** the assertion in `DocumentExtractor.php:34-37`, `OGExtractor.php:34-37`, `TitleExtractor.php:34-37` and `ImageExtractor.php:37-40`, and **below** it in `DescriptionExtractor.php:34-38`. Both forms disappear with SEOBundle TODO-3 **I-04**. Until then, keep one order.

## Packaging and tests

### I-08 — `composer.json`: delta to SEOBundle TODO-3 I-01
- **Where:** `composer.json:26-35`.
- **Fix:**
  - `phpstan/phpstan-doctrine` in `require-dev` is not needed. The component has no Doctrine code (`SEOMetadata` is not mapped). Remove it.
  - Add `psr/log` (`^3.0`) when the optional logger of TODO-1 **C-01** is introduced.
  - `coreshop/registry` is used only for `PrioritizedServiceRegistryInterface` in `SEOPresentation`. If **I-02** moves the presentation behind its own interface, consider accepting `iterable<ExtractorInterface>` (a Symfony `!tagged_iterator` with `priority`) instead. That removes the dependency and the custom registry pass for consumers of the component.
  - Keep `"php": "^8.4"` (SEOBundle **I-01**) in sync with the root `composer.json`.

### I-09 — Unit tests for the component: delta to SEOBundle TODO-3 I-12
- **Where:** the component has no `Tests/` directory.
- **Fix:** all classes are plain PHP and can be unit-tested without a kernel (`HeadMeta` / `HeadTitle` only need a `new ContainerService()`, as the scan script for this TODO did). Add PHPUnit tests for:
  - `SEOMetadata`: every setter and getter, the `add*` / `remove*` methods, and the validation from TODO-2 **B-01** / **B-02**;
  - each extractor: `supports()` true and false, empty and `null` values, and duck-typed objects (SEOBundle TODO-2 **B-04**);
  - `SEOPresentation`: the order and override behaviour with two extractors (**I-03**), an extractor that throws (TODO-1 **C-01**), calling it twice (SEOBundle TODO-2 **B-05**), and the title and description normalisation (TODO-2 **B-03**).
  - Wire them into the component's `phpunit.xml.dist` and the root test suite.
