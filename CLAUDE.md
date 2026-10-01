# CLAUDE.md

This file provides guidance for Claude Code when working on the PicassoBundle project.

## Project Overview

PicassoBundle is a Symfony bundle that provides responsive image components, inspired by Next.js Image. It generates optimized `<picture>` elements with multiple formats, srcset, and blur placeholders.

## Tech Stack

- **Language**: PHP 8.2+
- **Framework**: Symfony 6.4 / 7.0 / 8.0
- **Key dependency**: Symfony UX Twig Component 2.13+
- **Optional**: League Glide (local transforms), Imgix (CDN transforms), Flysystem, VichUploaderBundle

## Repository Structure

```
src/
├── Attribute/          # AsImageLoader, AsImageTransformer, AsPlaceholder attributes
├── Controller/         # ImageController (serves transformed images), NotFoundExceptionFactory (@internal: 404s
│                       #   following error_max_age), LegacyUrlController (@internal: redirects 1.x URLs)
├── DependencyInjection/ # VichLoaderPass (binds each vich loader to its VichUploader mapping),
│                       #   UrlAliasPass (collects loader/transformer url_alias from their tags)
├── DataCollector/      # PicassoDataCollector + CollectingImageHelper decorator (web profiler integration)
│   └── Dto/            #   RenderEntry, UrlEntry, MetadataEntry, Totals (collector payload DTOs)
├── Dto/                # Image, ImageReference, ImageRenderData, ImageSource, ImageTransformation, SrcsetEntry
├── Exception/          # Domain exceptions (PicassoExceptionInterface and implementations)
├── Loader/             # FilesystemLoader, FlysystemLoader, FlysystemRegistry, UrlLoader,
│                       #   VichUploaderLoader, VichMappingHelper, ChainLoader + interfaces
│                       #   (ImageLoaderInterface, ServableLoaderInterface, VichMappingHelperInterface)
├── Placeholder/        # TransformerPlaceholder, BlurHashPlaceholder + PlaceholderInterface
├── Source/             # ImageSourceInterface + LocalImageSource, FlysystemImageSource
│                       #   (read access to originals for local transformers) and
│                       #   ImageSourceFlysystemAdapter (read-only Flysystem bridge used by Glide)
├── Service/            # CacheKeyGenerator, ImageHelper, ImageHelperInterface, ImagePipeline,
│                       #   LoaderRegistry, TransformerRegistry, PlaceholderRegistry,
│                       #   SrcsetGenerator, MetadataGuesser, MetadataGuesserInterface,
│                       #   LegacyMetadataResolver (@internal: 1.x "_metadata" tokens → loader),
│                       #   UrlAliases (loader/transformer names ↔ their URL segments)
├── Transformer/        # GlideTransformer, ImgixTransformer + interfaces
│                       #   (ImageTransformerInterface, LocalTransformerInterface, PurgableTransformerInterface),
│                       #   DeferredCacheWriter (@internal: kernel.terminate upload of Glide misses),
│                       #   GlideResponseFactory (@internal: Glide responses in two storage calls)
├── Twig/
│   ├── Component/      # ImageComponent (Picasso:Image Twig component)
│   └── Extension/      # PicassoExtension (picasso_image_url, picasso_image Twig functions)
└── PicassoBundle.php   # Bundle class with configuration tree and service wiring
config/
└── routes.php          # Bundle routes
templates/
├── image.html.twig     # Generic <picture>/<img> render template (used by function and component)
├── components/
│   └── Image.html.twig # Twig component template (thin wrapper around image.html.twig)
└── Collector/
    └── picasso.html.twig # Web profiler toolbar/panel template (opt-in via picasso.collector: true)
tests/                  # PHPUnit tests mirroring src/ structure
benchmarks/             # Stress benchmarks (run.php, BenchKernel, CountingStorage) + Dockerfile (official
                        #   php:8.4-cli-alpine with gd, imagick, vips) for Linux numbers; var/ is generated and ignored
```

## Build & Test Commands

```bash
# Install dependencies
composer install

# Run tests
vendor/bin/phpunit

# Static analysis (level: max)
vendor/bin/phpstan analyse

# Code style check
vendor/bin/php-cs-fixer fix --dry-run --diff

# Code style fix
vendor/bin/php-cs-fixer fix

# Twig code style
vendor/bin/twig-cs-fixer lint

# Code modernization check
vendor/bin/rector process --dry-run

# composer.json validation + normalization check
composer validate --strict
composer normalize --dry-run

# composer.json normalization fix
composer normalize

# Stress benchmarks (Markdown tables; see README "Performance")
XDEBUG_MODE=off php -d opcache.enable_cli=1 benchmarks/run.php [render|hit|notfound|miss|crawl|herd|memory]
docker build -f benchmarks/Dockerfile -t picasso-bench . && docker run --rm picasso-bench [scenario ...]
```

## CI/CD

- Uses **Laminas CI Matrix Action** (`.github/workflows/continuous-integration.yml`)
- Configured extensions: `gd`, `pcov`
- Ignores PHP platform requirements for PHP 8.4+ (future versions)
- Additional check (`.laminas-ci.json`): `composer validate --strict && composer normalize --dry-run --diff` on lowest PHP with latest dependencies
- Runs on: `ubuntu-latest`

## Architecture Notes

- **Loaders** fetch image data from a source (filesystem, Flysystem, URL, Vich). They implement `ImageLoaderInterface` and are registered via the `#[AsImageLoader('name')]` attribute or the `picasso.loader` service tag.
    - `ServableLoaderInterface` extends `ImageLoaderInterface` for loaders whose originals local transformers (like Glide) can serve. `getSource()` takes no argument and returns the single `ImageSourceInterface` (see **Sources**) the loader reads from, never a storage-library type, so loaders stay independent of what the transformer is built on.
    - **One source per loader.** A servable loader reads from exactly one root, so the loader name in a Glide URL (`/image/{transformer}/{loader}/{path}`) is all serving needs to find the original: no loader data travels in URLs. Since 2.0 Glide never emits the `_metadata` query param (1.x URLs carrying it are redirected, see **1.x URLs**), and there is no `Image::$metadata` nor URL encryption. Keep it that way: when a loader would need per-image data to locate a source, split it into several loaders instead. This also keeps Glide cache paths (`transformer/loader/path`) and purges unambiguous.
    - `FilesystemLoader` reads one `path` (config: `path`, required). The pre-2.0 `paths` list is rejected by the config tree with a migration message: declare one filesystem loader per directory.
    - `VichUploaderLoader` serves one VichUploader mapping (constructor: `mapping`, `uploadDestination`). `load()` finds the upload field on the entity from the mapping via `VichMappingHelperInterface::resolveField()`, so the `field` context key is only needed when several fields of an entity share the mapping; wrong or ambiguous references throw `InvalidImageReferenceException`. `getSource()` resolves `uploadDestination` to a `FlysystemImageSource` when `FlysystemRegistry::has()` it, else to a `LocalImageSource`.
    - `VichLoaderPass` (compiler pass, `src/DependencyInjection/`) binds each vich loader, tagged `.picasso.vich_loader` by `loadExtension()`, to its mapping and upload destination read from the `vich_uploader.mappings` parameter (only known once every extension is loaded, hence a pass). Mapping resolution: the loader's `mapping` option, else the loader name when it is a mapping, else the only mapping; otherwise it throws an `InvalidConfigurationException` listing the available mappings. Config errors should always say how to fix them.
    - `UrlLoader` loads images from remote URLs via Symfony HttpClient.
    - `ChainLoader` (`type: chain`, `loaders: [...]`) keeps one loader name for images spread over several roots, which is also how 1.x multi-root loaders keep their name (and templates) in 2.0. It renders each image with the first member holding it: a reference with a path goes to the first member whose `getSource()->exists()` (else the first member accepting it, so a missing image renders a URL that 404s, like with a single loader); a reference without a path (an entity) goes to the first member whose `load()` does not throw `InvalidImageReferenceException`, without touching any storage. Members must be filesystem, flysystem or vich loaders declared under `picasso.loaders` (`checkChain()`): they need a source to probe, and no nesting keeps resolution linear. The chain never serves (not servable).
    - **Delegation without type checks.** A loader that hands an image over to another returns it with `Image::$loader` set to the delegate's name; `ImagePipeline::url()` and `ImageHelper` put `$image->loader ?? $requested` in the transformer context, so URLs (and `ImageRenderData::$loader`) name the loader that serves. Per-loader defaults (`default_transformer`, `default_placeholder`, `resolve_metadata`) still come from the requested loader. Never make the pipeline check for a loader class to find its delegate.
    - `FlysystemRegistry` manages multiple named Flysystem storage instances.
- **Sources** (`src/Source/`) are the read access to originals behind a servable loader. `ImageSourceInterface` has two methods: `exists(path)` and `readStream(path)` (throws `ImageNotFoundException`). Paths are relative to the source root.
    - `LocalImageSource` reads a local directory with no Flysystem dependency. It resolves `.`/`..` itself and treats paths escaping the root, empty paths and null bytes as missing. `FilesystemLoader::load()` uses it too, so rendering and serving share the same path handling.
    - `FlysystemImageSource` wraps a `FilesystemOperator`. Flysystem failures are reported as missing (`exists()` → false, `readStream()` → `ImageNotFoundException`); other errors propagate unchanged.
    - `ImageSourceFlysystemAdapter` is a read-only Flysystem `FilesystemAdapter` over any source, used by `GlideTransformer::serve()` (`new Filesystem(new ImageSourceFlysystemAdapter($loader->getSource()))`). It translates bundle exceptions to Flysystem ones (`UnableToReadFile`, `UnableToCheckFileExistence`) so Glide's own error mapping is unchanged, refuses writes and metadata lookups, and exposes no directories (`directoryExists()` → false, empty `listContents()`). It must only use exception factories that exist in Flysystem 2 (e.g. not `UnableToListContents`/`UnableToCheckDirectoryExistence`, both 3.x-only). Wrapping it in `League\Flysystem\Filesystem` keeps Flysystem's path-traversal check on the serve path.
- **Transformers** generate URLs for on-demand image transformation (Glide locally, Imgix via CDN). They implement `ImageTransformerInterface` and are registered via the `#[AsImageTransformer('name')]` attribute or the `picasso.transformer` service tag.
    - `LocalTransformerInterface` extends `ImageTransformerInterface` for transformers that serve images locally (e.g., Glide) and need a loader to access source files.
    - `PurgableTransformerInterface` extends `ImageTransformerInterface` for transformers that support cache purging. GlideTransformer purges via `Server::deleteCache()` (standard mode) or Flysystem directory deletion (public cache mode). ImgixTransformer purges via the Imgix Management API (`POST /api/v1/purge`) when an `api_key` and PSR-18 HTTP client are configured.
    - `GlideTransformer`'s `cache` constructor argument is polymorphic: it accepts a local path string and, when a `FlysystemRegistry` is injected and `has($cache)` returns true, resolves the string to the matching `FilesystemOperator` (a Flysystem storage name). This lets users write `cache: 'thumbs.storage'` in YAML and have it route to a Flysystem bucket without a second config option. `FlysystemRegistry` is registered unconditionally whenever `League\Flysystem\FilesystemOperator` is installed (no longer gated behind the Vich loader).
    - **Storage calls.** `GlideTransformer::serve()` answers hits itself through the `@internal` `GlideResponseFactory`, never through Glide's `makeImage()` (which asks `fileExists()` first) nor Glide's Symfony response factory (`mimeType()`, `fileSize()`, `lastModified()` on top of `readStream()`): a hit costs `fileSize()` (existence + Content-Length in one call) and `readStream()`, a conditional request `lastModified()` only (a 304 never opens the variant). The content type is sniffed from the first 16 bytes (fallback `mimeType()`, then `application/octet-stream`); size and date come from `fstat()` when the stream is a `plainfile`, so remote storages send no `Last-Modified`. `GlideResponseFactory` is also set as Glide's response factory for misses (`create()` takes `mixed $path`: Glide 2's interface leaves it untyped). Any `FilesystemException` in `fromCache()` means "miss", as Glide's `cacheFileExists()` does. This replaced `league/glide-symfony`, which never supported Glide 4: do not reintroduce it.
    - **Render lock** (`lock: { enabled, factory, ttl }`, optional `symfony/lock`): on a miss only, `serve()` creates a lock keyed `picasso.glide.<xxh128(cache path)>`; if `acquire()` fails it blocks (`acquire(true)`), then re-checks the cache storage and serves the other process's render, or renders itself when nothing was stored (holder crashed, lock expired). The lock is released in `finally`, except with deferred writes, where it is handed to `DeferredCacheWriter::defer()` and released after the upload: waiters look in the cache storage, which only holds the variant once uploaded. `loadExtension()` rejects `lock.enabled` without `symfony/lock` installed.
    - **Drivers.** `GlideTransformer::DRIVERS` maps driver names Glide does not resolve itself to Intervention driver classes (`vips` → `Intervention\Image\Drivers\Vips\Driver`, from `intervention/image-driver-vips`, Glide 3+); Glide's `ServerFactory` accepts a class name. `loadExtension()` fails with the install command when the class is missing.
    - `GlideTransformer::serve()` error mapping: a missing source or bad signature throws `ImageNotFoundException`; a source that exists but cannot be decoded throws `UndecodableImageException`. `ImageController` turns both into a `NotFoundHttpException` with the domain exception as previous, so consumers can tell them apart. Decoder failures are matched by class name (`DECODING_EXCEPTIONS`) rather than caught, because each supported Glide major pulls a different intervention/image major (v2 `NotReadableException`, v3/v4 `DecoderException`). A Glide `FilesystemException` (e.g. an object store rejecting the losing write of two concurrent renders of the same variant) is swallowed only when `Server::cacheFileExists()` now reports the variant, which is then served from the cache; otherwise it is rethrown.
    - **CDN setup** (Glide options `base_url`, `defer_cache_write`, `public_cache.prefix`, plus the top-level `cache_control`): lets a CDN whose origin is the cache bucket answer every hit, so PHP only runs on misses. `base_url` is prepended to every generated URL (the transformer-level `base_url` key is shared with Imgix, where it is the source domain). `public_cache.prefix` is prepended to public-cache keys (`computeCachePath()`, `purge()`), so the key can equal the URL path (`image/glide/<loader>/<path>/<params>.<ext>` for the default `/image` route); it defaults to `''` because existing public-cache setups point `cache` at `public/image` and rely on the unprefixed layout. The bundle sets nothing on the objects it stores, so the CDN's cache policy gives hits their lifetime. With `defer_cache_write`, `GlideTransformer` names a local render directory (a random directory under `sys_get_temp_dir()`, one per instance, so per worker thread) but never creates it in the constructor: under PHP-FPM every request builds an instance, and most only generate URLs or serve hits. `serve()` checks the cache storage first and, on a miss, builds a `LocalFilesystemAdapter` over the render directory (creating it), switches the Glide server's cache to it, then hands `(render dir, cache storage, cache path, lock)` to the `@internal` `Transformer\DeferredCacheWriter`. The writer (registered once, tagged `kernel.event_listener` on `kernel.terminate` → `flush()` and `kernel.reset`) moves each file with `readStream`/`writeStream`, always deletes the local copy, releases the lock, logs (never throws) a failed upload unless the variant is already stored, and once every pending file is moved deletes each render directory whole (`deleteDirectory('')`), which also removes the variant folders Glide created in it. Because the Glide server outlives requests in worker mode, `serve()` resets the server's cache to the cache storage on every call and `purge()` does too, or a purge after a deferred miss would hit the render directory. Do not reintroduce a Flysystem decorator around the cache storage to defer writes: switching the server's cache per request is what keeps this small. The writer works on `FilesystemOperator`, not `ImageSourceInterface`: it reads, writes and deletes Glide cache storages, which Glide requires to be Flysystem, whereas sources are read-only access to originals.
- **URL aliases** (`url_alias` on loaders and transformers, `urlAlias` on `#[AsImageLoader]` / `#[AsImageTransformer]`): the segment naming a loader or transformer in `/image/{transformer}/{loader}/{path}` URLs. Rule: **names inside, aliases on the wire**. Contexts, registries, `ImagePipeline`, purges and the collector only ever see names. Only two places translate: `GlideTransformer` maps name → alias when generating URLs and public-cache keys (`publicCacheDirectory()`), so keys keep mirroring the URL path; `ImageController` and `LegacyUrlController` map the URL segments back to names before looking up registries, and pass names in the serve context. `Service\UrlAliases` holds both maps. It is a required constructor argument wherever it is used (`GlideTransformer` right after the router, `ImageController` after `cache_control`, `LegacyUrlController` before `error_max_age`), and its own two maps are required too: pass `new UrlAliases([], [])` for no aliases. Its `resolve*()` methods return the name for an alias, else the segment unchanged, so names keep being served once an alias is set (published URLs do not break). Config and attributes both put `url_alias` on the `picasso.loader` / `picasso.transformer` tag, and `UrlAliasPass` builds the service from the tags only. It rejects aliases outside `[A-Za-z0-9_-]`, an alias shared by two loaders (or two transformers), and an alias equal to another loader's (transformer's) name, which is what keeps resolution unambiguous. Loaders and transformers may share an alias (different segments). The route order (transformer first) is deliberately unchanged: swapping it would move every published URL and public-cache key.
- **HTTP cache headers** (`cache_control`: `max_age`, `immutable`, `error_max_age`) are owned by `ImageController`, never by transformers: transformers render, the controller decides how long clients and CDNs keep the result. On a successful response or a 304 it sets `public`, `max-age` and `immutable` (or not) and drops `Expires` (Glide's response factory sets one a year out, which would contradict a shorter `max-age`); with `max_age: null` it leaves the transformer's headers alone; redirects (e.g. Glide's legacy public-cache redirect) are left alone. Every `NotFoundHttpException` it throws carries `Cache-Control: public, max-age=<error_max_age>` when that is set, uncacheable otherwise. The options are nullable scalars validated as "null or a non-negative integer" because `integerNode` rejects an explicit `null`. The controller receives the whole `cache_control` config array (`CacheControlConfig` type alias) as a required constructor argument (the required `UrlAliases`, then the optional `Stopwatch`, come after it); the bundle config supplies the defaults (one year, immutable, no 404 caching), and passing `max_age: null, immutable: false, error_max_age: null` leaves every header alone.
- **Placeholders** generate placeholder data URIs or URLs for images (e.g., blurred thumbnails). They implement `PlaceholderInterface` and are registered via the `#[AsPlaceholder('name')]` attribute or the `picasso.placeholder` service tag.
    - `TransformerPlaceholder` reuses the configured transformer to generate a tiny blurred image URL.
    - `BlurHashPlaceholder` encodes the image as a BlurHash string and decodes it to a tiny PNG data URI (requires `kornrunner/blurhash`).
- **Registries** (`LoaderRegistry`, `TransformerRegistry`, `PlaceholderRegistry`) use Symfony service locators for lazy-loading.
- **ImagePipeline** orchestrates loader + transformer for the Twig function. Also provides a `purge()` convenience method that resolves loader/transformer names and delegates to `PurgableTransformerInterface::purge()`.
- **ImageHelper** provides a convenience API for generating single image URLs with named parameters. Supports `resolveMetadata` parameter to control metadata resolution at runtime.
- **ImageComponent** is the main Twig component (`<twig:Picasso:Image>`) that generates `<picture>` with `<source>` elements. Supports `resolveMetadata` prop.
- **PicassoExtension** registers two Twig functions:
    - `picasso_image_url(path, …)` — returns a single transformed image URL.
    - `picasso_image(src, …, attributes={…})` — renders a full responsive `<picture>` element (same HTML as the `<twig:Picasso:Image>` component). Intended for consumers that do not install `symfony/ux-twig-component`. Uses `needs_environment` + `is_safe: ['html']` and renders `@Picasso/image.html.twig`, which is the same template the component delegates to.
- **1.x URLs** (deprecated, removed in 3.0). Only published URLs are supported at runtime: 1.x configs and templates are migrated at upgrade (config errors say how, a chain keeps a loader name). A 1.x loader reading several roots put each image's root in an encrypted `_metadata` Glide param (`{"path": …}` or `{"upload_destination": …}`, AES-256-GCM: nonce ‖ tag ‖ ciphertext, base64url, key `sha256(sign_key)` of the first Glide transformer). The URL alone says where the image now lives, so all 1.x handling sits at the HTTP edge, in three `@internal` pieces:
    - `LegacyUrlController` decorates `picasso.controller.image` whenever a Glide transformer exists. Requests with a `_metadata` param to a Glide transformer (looked up by name in a locator, never by type) get a 301 to the 2.0 URL, whatever loader the URL names; every other request goes to `ImageController` untouched. 404s go through `NotFoundExceptionFactory`, shared with `ImageController`, so both follow `error_max_age`.
    - `LegacyMetadataResolver` decrypts tokens and maps roots to loaders: exactly, else by their path relative to `kernel.project_dir`, so tokens minted in another release directory still match. The root → loader map (first loader per root wins) gets filesystem `path`s in `loadExtension()` and vich upload destinations in `VichLoaderPass`.
    - `GlideTransformer::redirectToLoader()` checks the signature (which covers `_metadata`) and answers a 301 to the same transformation under another loader, rebuilt with `url()` so only known params survive. Glide itself knows nothing of `_metadata`.
    - Keep it that way: no 1.x code in `ImageController`, `serve()`, rendering or the config tree. Dropping 1.x support is deleting these pieces and their wiring.
- **Metadata resolution** (`resolve_metadata`): Controls whether the `MetadataGuesser` reads image streams to detect dimensions. Configurable globally (default: `false`), per-loader (filesystem defaults to `true`), or at runtime via the `resolveMetadata` parameter. To reduce CLS, `width` and `height` attributes are only rendered when **both** are available. The guesser reads streams progressively (64KB initially, doubling up to a 2MB cap) so dimensions are found even when large EXIF/ICC/XMP segments push the image header past the first read; results are cached under a versioned namespace (`metadata.v2`) in the configured PSR-6 pool.
- **SrcsetGenerator** builds responsive srcset strings across configured widths and formats.
- **PicassoDataCollector** is an opt-in `AbstractDataCollector` for the Symfony web profiler. Enabled via the bundle `collector` option (default: `false`). When enabled, the bundle registers `CollectingImageHelper`, a decorator over `ImageHelperInterface` that times every `imageData()` / `imageUrl()` call and forwards the result to the collector. Recorded entries are stored as typed DTOs in `src/DataCollector/Dto/` (`RenderEntry`, `UrlEntry`, `MetadataEntry`, `Totals`) so the template consumes property access instead of array shapes. Entries record _resolved_ loader/transformer/placeholder names, never `(default)`: render entries read them from `ImageRenderData` (which exposes `loader`, `transformer` and `placeholder` resolved by `ImageHelper`), and URL entries resolve them through `ImagePipeline::resolveLoaderName()` / `resolveTransformerName()` — the same methods `ImagePipeline::url()` uses internally. When nothing was recorded (`Totals::$handled`, derived in the DTO together with `headline`), the toolbar item is hidden, the menu entry disabled and the panel replaced by an empty state. The toolbar headline counts `renders + urls` (direct Twig calls); the full panel breaks down each operation type with durations.
- All bundle configuration and service wiring lives in `PicassoBundle.php` (uses `AbstractBundle`).

## Domain Exceptions

All bundle exceptions implement `PicassoExceptionInterface` (extends `Throwable`), allowing consumers to catch any bundle-level error with a single type. Always throw domain-specific exceptions rather than generic PHP exceptions (`LogicException`, `RuntimeException`, etc.).

| Exception                        | Extends                    | When to use                                                                                                                                                                                  |
| -------------------------------- | -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `LoaderNotFoundException`        | `InvalidArgumentException` | Requested loader name is unknown or missing from context                                                                                                                                     |
| `TransformerNotFoundException`   | `InvalidArgumentException` | Requested transformer name is unknown or missing from context                                                                                                                                |
| `ImageNotFoundException`         | `RuntimeException`         | Source image could not be found or signature is invalid                                                                                                                                      |
| `InvalidMetadataException`       | `LogicException`           | Image metadata is malformed or invalid                                                                                                                                                       |
| `InvalidImageReferenceException` | `InvalidArgumentException` | The image reference cannot be served by the requested loader (e.g. an entity without a field using the vich loader's mapping, a `field` using another mapping, several fields sharing it)    |
| `InvalidConfigurationException`  | `LogicException`           | Invalid bundle configuration (missing type, missing package)                                                                                                                                 |
| `ImageProcessingException`       | `RuntimeException`         | Image processing failure (stream read errors, encoding)                                                                                                                                      |
| `PlaceholderNotFoundException`   | `InvalidArgumentException` | Requested placeholder name is unknown or missing from context                                                                                                                                |
| `PurgeException`                 | `RuntimeException`         | Cache purge operation failure (filesystem error, API error)                                                                                                                                  |
| `UndecodableImageException`      | `RuntimeException`         | Source image exists but cannot be decoded (truncated, not an image); mapped to 404 like `ImageNotFoundException`, but kept distinct so consumers can tell a broken source from a missing one |

## Coding Conventions

- Strict types everywhere: `declare(strict_types=1)` in all PHP files
- PSR-4 autoloading under `Silarhi\PicassoBundle\`
- Code style enforced by PHP-CS-Fixer (`.php-cs-fixer.dist.php`) — uses `@Symfony` and `@Symfony:risky` rulesets
- Static analysis enforced by PHPStan (`phpstan.neon`) at **level max**
- Twig style enforced by Twig-CS-Fixer (`.twig-cs-fixer.php`)
- Code modernization managed by Rector (`rector.php`) — targets PHP 8.2+, includes deadCode, codeQuality, and typeDeclarations rulesets

### Dependency Constraints

- **Integration libraries** (symfony/_, league/_, imagine, vich, psr/\*, kornrunner/blurhash) keep **wide version ranges** (e.g. `^6.4 || ^7.0 || ^8.0`) so the CI matrix genuinely tests from the lowest to the latest supported versions. Never bump their floors to the currently installed version.
- **QA tools** (php-cs-fixer, phpstan/\*, phpunit, rector, twig-cs-fixer, composer-normalize) are bumped to current versions via `composer bump:tools`.
- `intervention/image-driver-vips` (Glide `vips` driver) is not a dev dependency on purpose: it pulls `jcupitt/vips`, which needs `ext-ffi` and libvips, absent from the CI images. The driver tests skip when it cannot run; install it locally (`composer require --dev intervention/image-driver-vips` then revert `composer.json`) to run them.
- `config.bump-after-update` was removed on purpose: it bumped **all** dev dependencies after every `composer update`, including integration libraries — do not re-add it.
- Floors are empirically verified: when changing a floor, run `composer update --prefer-lowest && vendor/bin/phpunit` locally. Known hard floors:
    - `league/glide ^2.3` — needs `Server::setCachePathCallable`.
    - `league/flysystem-bundle ^2.1` — 2.0 only supports Symfony 4/5. Tests must stick to flysystem v2-compatible APIs (`fileExists`, not the v3-only `directoryExists`).
    - `vich/uploader-bundle ^2.9 || ^3.0` — floor: tests use `Metadata\Driver\AttributeDriver` and fixtures use the `Mapping\Attribute` namespace, both introduced in 2.9. v3 is supported too (it needs PHP ≥8.3, so composer falls back to v2 on PHP 8.2). v3 makes `PropertyMappingFactory` return `PropertyMappingInterface` instead of the concrete `PropertyMapping`; `VichMappingHelper` therefore keeps resolved mappings in **local variables** only — naming either type in a signature would break the other version.
    - `kornrunner/blurhash ^1.2` — 1.0/1.1 declare PHP `^7.x` only and can never install on this bundle's PHP ≥8.2.

### PHPStan Custom Types

The project uses PHPStan custom type aliases to avoid duplicating complex type annotations across classes. When a structured type is used in multiple files, define it once and import it.

**Global type aliases** (defined in `phpstan.neon` via `typeAliases`):

| Alias                | Type                                                                   | Used in                                |
| -------------------- | ---------------------------------------------------------------------- | -------------------------------------- |
| `TransformerParams`  | `array<string, int\|string>`                                           | `GlideTransformer`, `ImgixTransformer` |
| `CacheControlConfig` | `array{max_age: int\|null, immutable: bool, error_max_age: int\|null}` | `ImageController`, `PicassoBundle`     |

**Local type aliases** (defined with `@phpstan-type` on an interface, imported with `@phpstan-import-type` in implementations):

| Alias                  | Type                                                                 | Defined on                   | Imported in                                                                                                                                                        |
| ---------------------- | -------------------------------------------------------------------- | ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `ImageGuessedMetadata` | `array{width: int\|null, height: int\|null, mimeType: string\|null}` | `MetadataGuesserInterface`   | `MetadataGuesser`                                                                                                                                                  |
| `ImageDimensions`      | `array{0: int, 1: int}`                                              | `VichMappingHelperInterface` | `VichMappingHelper`                                                                                                                                                |
| `TransformerContext`   | `array<string, mixed>`                                               | `ImageTransformerInterface`  | `GlideTransformer`, `ImgixTransformer`, `PurgableTransformerInterface`, `PlaceholderInterface`, `TransformerPlaceholder`, `BlurHashPlaceholder`, `SrcsetGenerator` |

**Guidelines for adding new custom types:**

- Use `@phpstan-type` on the canonical interface when the type is part of a contract (interface + implementations).
- Use `@phpstan-import-type from InterfaceName` in implementing classes to reference the type.
- Use `typeAliases` in `phpstan.neon` when the type is shared between unrelated classes (no common interface).
- Only extract a type alias when the same structured type (array shapes, complex unions) appears in 2+ files. Simple generic types like `array<string, mixed>` do not need aliases.

## API Documentation

**Always update documentation when adding or modifying public API.** This includes any change to interfaces, public methods, configuration options, DTOs, attributes, Twig functions/components, or controller routes.

When making API changes, update the following:

1. **`README.md`** — Update usage examples, configuration reference, and feature descriptions to reflect the new or changed API.
2. **`CLAUDE.md`** — Update the relevant sections:
    - **Repository Structure** — Add new files/directories or update descriptions.
    - **Architecture Notes** — Document new or changed services, interfaces, and their roles.
    - **Domain Exceptions** table — Add entries for any new exception classes.
    - **PHPStan Custom Types** tables — Add entries for new type aliases.
    - **Common Patterns** — Update or add patterns for new extension points.
3. **PHPDoc blocks** — Ensure all public and protected methods on interfaces and services have accurate `@param`, `@return`, and `@throws` annotations.
4. **Bundle configuration** — When adding or changing config options in `PicassoBundle::configure()`, document the new options in both `README.md` and the Architecture Notes.
5. **`CHANGELOG.md`** — Add an entry to the top section, the next release (currently `[2.0.0] - Unreleased`), following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) sections (Added, Changed, Deprecated, Removed, Fixed, Security). When tagging the release, replace `Unreleased` with the release date and `HEAD` in its compare link with the tag. Prefix breaking changes with **BC break:** and say what consumers must change. Skip dependency bumps, CI and tooling changes.

**Checklist for API changes:**

- [ ] `README.md` reflects the current public API and configuration
- [ ] `CHANGELOG.md` has an entry in the next release section for every user-facing change
- [ ] `CLAUDE.md` sections are up to date (structure, architecture, exceptions, types, patterns)
- [ ] PHPDoc annotations are accurate on all affected interfaces and classes
- [ ] New extension points (loaders, transformers, placeholders) have a corresponding entry in Common Patterns
- [ ] New exceptions are added to the Domain Exceptions table and implement `PicassoExceptionInterface`

## Common Patterns

- **Adding a new loader**: Create a class implementing `ImageLoaderInterface` (or `ServableLoaderInterface` if local transformers should serve its images), add `#[AsImageLoader('name')]`, and it auto-registers. A loader delegating to other loaders sets `Image::$loader` on what it returns.
- **Adding a new source**: Implement `ImageSourceInterface` (`exists()` + `readStream()`) and return it from a servable loader's `getSource()`. Glide reads it through `ImageSourceFlysystemAdapter`, so no Flysystem adapter is needed.
- **Adding a new transformer**: Create a class implementing `ImageTransformerInterface` (or `LocalTransformerInterface` for local serving, or `PurgableTransformerInterface` for cache purge support), add `#[AsImageTransformer('name')]`, and it auto-registers.
- **Adding a new placeholder**: Create a class implementing `PlaceholderInterface`, add `#[AsPlaceholder('name')]`, and it auto-registers. Alternatively, configure via `type: service` in the `placeholders` config.
- **Bundle configuration**: All config options are defined in `PicassoBundle::configure()` and wired in `PicassoBundle::loadExtension()`.
