# SEO Component – TODO 2: Bugs

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-28 (branch `s74-p84`).
Scope and references: same as [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md). These are wrong behaviours, crashes in limited areas and contract violations that do not meet the "critical" bar.
Paths are relative to the component root unless noted otherwise. Paths starting with a bundle or component name are relative to `src/CoreShop/`. `vendor/` means `../pimcore2teszt10211524/vendor/`. Items marked **(dependent)** have their root cause or part of the fix outside this component. Items marked **(verified)** were checked with a PHP 8.4.27 script against the component code and the vendored libraries. Items marked **(plausible)** follow from the code, but the trigger depends on data or custom code. Items marked **(latent)** are not reachable through the shipped code today.

**Overlap with other TODOs:** the SEOBundle TODO-2 items **B-04** to **B-12** are about code in this component. They are listed in the table at the end of [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md) and are **not repeated** here.

Related files: [TODO-1-CRITICAL.md](TODO-1-CRITICAL.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## Metadata model

### B-01 — `setExtraProperties()` / `setExtraNames()` / `setExtraHttp()` accept any array. Integer keys or non-string values then crash `SEOPresentation` with a `TypeError` — (verified) (latent)
- **Where:** `Model/SEOMetadata.php:140-143`, `:162-165`, `:184-187` (plain `array`, no check of keys or values). `Model/SEOMetadataInterface.php:62-66` declares the same untyped arrays. They are used in `SEOPresentation.php:40-56` → `HeadMeta::append{Property,Name,HttpEquiv}($key, $value)` → `vendor/six-it/pimcore-source/lib/Twig/Extension/Templating/HeadMeta.php:188` → `createData(string $type, string $typeValue, string $content, array $modifiers)`. `HeadMeta.php` has `declare(strict_types=1)`.
- **Problem:** the `add*()` methods take `string $key, string $value`, but the bulk setters do not. Results with PHP 8.4:
  - `setExtraNames(['123' => 'x'])`: PHP turns the numeric-string key into `int(123)`, and `HeadMeta` throws `TypeError: …createData(): Argument #2 ($typeValue) must be of type string, int given`.
  - `setExtraProperties(['og:x' => null])` throws `TypeError: …Argument #3 ($content) must be of type string, null given`. The same happens for `int`, `float` or `bool` values, for example `og:image:width` set as an `int`.
  - A list (`['a', 'b']`) gives the keys `0` and `1` and throws as well.
  - No shipped extractor calls the bulk setters. Custom extractors are the expected users, and the error then reaches the page (TODO-1 **C-01**).
- **Fix:**
  - Validate in the setters: loop over the array and call `addExtra*()` for each entry, so the `string` types of the single-entry methods apply. Throw `\InvalidArgumentException` with the key name for `null` or non-scalar values. Cast scalars with `(string)`.
  - In `SEOPresentation`, cast the key with `(string) $key`, because array keys can never stay numeric strings.
  - Document the shape as `array<string, string>` on the interface (TODO-3 **I-03**).

### B-02 — `setId()` / `setLocale()` are untyped, but the getters are typed. A string id makes `getId()` throw — (verified) (latent)
- **Where:** `Model/SEOMetadata.php:74-77` (`setId($id)`, no type, no return type), `:79-82` (`getId(): ?int`), `:87-98` (`setLocale($locale)`, `getLocale()` with no return type, docblock `@return mixed`). The file has `declare(strict_types=1)`.
- **Problem:** `setId('5')` is accepted, and then `getId()` throws `TypeError: …SEOMetadata::getId(): Return value must be of type ?int, string returned` (reproduced). `setLocale()` accepts anything, so `getLocale()` cannot be relied on (it matters for **B-05**).
- **Fix:** `setId(?int $id): void` and `setLocale(?string $locale): void` / `getLocale(): ?string`, or remove `id` entirely (SEOBundle TODO-3 **I-10**). Keep `locale` for **B-05**.

## Presentation

### B-03 — `<title>` and `og:title` are not normalised: line breaks, tabs and HTML in names reach the page verbatim (escaped) — (verified) (dependent)
- **Where:** `SEOPresentation.php:58-60` (`$this->headTitle->set($seoMetadata->getTitle())`, no clean-up) and `Extractor/OGExtractor.php:39-41` (`og:title`). The sources are `Component/Core/Model/Product.php` and `Category.php` `getMetaTitle()` (`pimcoreMetaTitle ?: name`).
- **Problem:** SEOBundle TODO-2 **B-06** covers descriptions only. Titles have the same issue: `HeadTitle::set()` stores the raw string, and `HeadTitle::toString()` only escapes it. Reproduced: `set("Foo\n<b>Bar</b>")` renders `<title>Foo\n&lt;b&gt;Bar&lt;/b&gt;</title>`. A product name pasted from a PDF or a spreadsheet (line breaks, tabs, non-breaking spaces), or a name with markup from a custom field type, shows as a broken title in search results and social previews. `getMetaTitle()` has no length limit either (search engines cut at about 60 characters).
- **Fix:** run titles through the same normaliser as descriptions (SEOBundle TODO-2 **B-06**: `html_entity_decode(strip_tags(…))`, collapse whitespace, trim). Use it for both `<title>` and `og:title`. Do not truncate `<title>` in code. Make an optional `title_max_length` configurable instead (SEOBundle TODO-3 **I-02**).

### B-04 — `og:image` is not URL-encoded, and has no frontend prefix, when it is built outside a plain frontend request — (verified code path) (dependent)
- **Where:** `Extractor/ImageExtractor.php:45-46` calls `$thumbnail->getPath()` with no arguments. In `vendor/six-it/pimcore-source/models/Asset/Image/Thumbnail.php:51-56` the default is `$frontend = $args['frontend'] ?? Tool::isFrontend()`. `ImageThumbnailTrait::convertToWebPath()` (`models/Asset/Thumbnail/ImageThumbnailTrait.php:289-315`) adds `frontend_prefixes` and calls `urlencode_ignore_slash()` **only** when `$frontend` is `true`.
- **Problem:** when `Tool::isFrontend()` is `false`, the raw storage path is used: no CDN or frontend prefix, and no encoding. Spaces, umlauts, `#` or `?` in asset file names then give an invalid `og:image` URL. This happens with Behat or CLI rendering, Messenger handlers that render product pages (static page or cache warmers), and the admin context. SEOBundle TODO-2 **B-07** already proposes `getFrontendPath()` for other reasons. This item adds the reason that the call must force `'frontend' => true`.
- **Fix:** use `$thumbnail->getFrontendPath()` (it passes `['deferredAllowed' => true, 'frontend' => true]`), and implement it together with SEOBundle TODO-2 **B-07** and TODO-1 **C-01**. Add a test with an asset named `Bild mit Ümlaut #1.jpg`.

## Localisation

### B-05 — Extractors read localized fields without a language. Outside a request, SEO tags come from the wrong language — (plausible) (dependent)
- **Where:** `Extractor/TitleExtractor.php:39` (`getMetaTitle()`), `Extractor/DescriptionExtractor.php:39-40` (`getMetaDescription()`), `Extractor/OGExtractor.php:39-48` (`getOGTitle()`, `getOGDescription()`). The interfaces already have a `?string $language = null` parameter (`Model/SEOAwareInterface.php:23-25`, `Model/SEOOpenGraphAwareInterface.php:23-25`), but nothing passes one. `SEOMetadata` has a `locale` property (`Model/SEOMetadata.php:34`, `:87-98`) that is never set.
- **Problem:** with `null`, Pimcore's localized fields fall back to the locale of the current request (`LocaleServiceInterface`). This is correct for a normal frontend request. It is wrong when:
  - the page is rendered from CLI or Messenger (cache warmers, static page generation, Behat): the default language is used for every locale;
  - a sub-request or fragment for another locale is rendered;
  - a custom caller wants the metadata for a given language (for example `hreflang` alternates, a headless API, a sitemap).
  - `og:locale` (SEOBundle TODO-3 **I-11**) cannot be emitted, because the component does not know which language it rendered.
- **Fix:**
  - Add an optional locale to the entry point: `updateSeoMetadata(object $object, ?string $locale = null)`. Set it with `$seoMetadata->setLocale($locale)` before the extractors run, so the existing `locale` field gets a purpose (SEOBundle TODO-3 **I-10** proposes removing it; keep it for this).
  - Pass `$seoMetadata->getLocale()` to every localized getter in the extractors.
  - In the bundle, fill the default from CoreShop's `LocaleContextInterface` when no locale is given, so frontend behaviour does not change **(dependent)**.
