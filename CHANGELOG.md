# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.4.0] - Unreleased

### Added

- `ImageSourceUnavailableException`, thrown by a source whose storage cannot be reached or answers with a transient error (`5xx`, `408`, `425`, `429`, a timeout, a dropped connection): the image may exist, it just cannot be read right now. `FlysystemImageSource` throws it from `readStream()` and `exists()`, telling those failures from a missing file by the HTTP client exception the object store adapter keeps as previous (AWS SDK, Guzzle, PSR-18, Symfony HttpClient). Custom sources may throw it too.

### Changed

- An image whose source storage is unavailable is answered with a `503 Service Unavailable`, `Retry-After: 30` and `Cache-Control: no-store`, by the image controller and by `ImageServer::serve()` (`ServiceUnavailableHttpException`, previous: the `ImageSourceUnavailableException`). Symfony logs 5xx exceptions as `critical`: lower `ServiceUnavailableHttpException` with `framework.exceptions` if an outage of your storage should not page you.
- With `defer_cache_write`, an upload the storage refuses for being unavailable is logged as a `warning` instead of an `error`, without asking the storage whether the variant is there (one more call to a failing storage). That check is now only made for a conflicting write (`409`, `412`) or a storage that does not speak HTTP.

### Fixed

- An object store failing during a Glide miss (outage, `5xx`) answered a `500` (`League\Glide\Filesystem\FilesystemException: Could not read the image`), logged as `critical`, or a `404` that `error_max_age` let CDNs keep after the outage when the existence check failed. It is now the `503` above. Requires Glide 3 or later: Glide 2 drops the source's exception, so such a read stays a `500` there.
- A source read finding nothing after the existence check passed (e.g. S3 answering `403` to the check, which the AWS SDK reads as "exists", then `404` to the read) answered a `500`; it is now a `404`.
- A [chain](README.md#chain-loader) member whose storage is unavailable no longer fails the rendering: it counts as not holding the image.

## [2.3.0] - 2026-10-08

### Added

- `allowed_hosts` option on url loaders: the hosts Glide may fetch their images from (`images.example.com`, or `*.example.com` for subdomains). Empty, the default, allows any host.

### Fixed

- Glide now serves the images of url loaders: their URLs answered `404` (`Loader "url" does not support serving.`). The remote image is downloaded on the first request of each variant (one request, also telling whether it exists), then served from the Glide cache. Set a `sign_key` on the transformer, and `allowed_hosts` on the loader, so that your server cannot be made to fetch other URLs.

## [2.2.1] - 2026-10-06

### Fixed

- `public_cache` with a local `cache` path wrote its directories `0700` (Flysystem's default), so a web server running as another user than PHP (shared hosting) answered `403` on every variant once the first one was cached, and could not even read the `.htaccess` files of those directories. The variants are now written world-readable: `0644` files, `0755` directories. A cache without `public_cache`, only read by PHP, keeps the private default.

## [2.2.0] - 2026-10-06

### Added

- Private images, served by your own routes behind your firewall and security voters, with the same `<picture>` as public ones (srcset of each format, fallback, transformer placeholder):
    - `route` and `routeParameters` on the `<twig:Picasso:Image>` component, `picasso_image()`, `picasso_image_url()` and `ImageHelperInterface`: every generated URL points at that route, with the transformation and its signature in the query string. Only local transformers (Glide, without `public_cache`) serve routes.
    - `ImageServer::serve($request, $image, $loader)`, called by your route once it has authorized the image (e.g. the entity a voter checked). The signature must match that image, so the URL only chooses among the transformations your application generated. Responses are `Cache-Control: private, no-cache`.
    - `private` loader option (`private` on `#[AsImageLoader]`): the bundle route refuses the loader, and rendering it without a route throws.
- `InvalidRouteException`, thrown when a private loader is rendered without a route, a route is used with a transformer that cannot serve it (Imgix, Glide with `public_cache`), or a route parameter is named like a transformation param.

### Changed

- `ImageHelperInterface::imageUrl()` and `imageData()` take two optional parameters, `route` and `routeParameters`. Custom implementations of the interface (e.g. decorators) must add them.

### Fixed

- `picasso_image_url()`, `ImageHelperInterface::imageUrl()`, `ImagePipeline::url()` and `ImagePipeline::purge()` ignored the loader's `default_transformer` and used the global `default_transformer`, unlike `<twig:Picasso:Image>` and `picasso_image()`. URLs of such loaders now use the loader's transformer, and purges purge its cache. A private image URL from `picasso_image_url()` was signed with another transformer's key than the one `ImageServer` checks, and was not served.
- The web profiler recorded `picasso_image_url()` calls under the global `default_transformer` instead of the loader's.

## [2.1.0] - 2026-10-03

### Fixed

- Responsive `srcset` candidates (with `sizes`) ignored the given `height`: an image rendered at `width=1200 height=500` with `fit=cover` had a fallback cropped to 12:5 but candidates in the source's aspect ratio, so the browser could show a differently framed image, and large candidates of a portrait source were huge (a 3840w candidate of a 1800x2700 photo came out 3840x5760, beyond a 256M `memory_limit` with GD). Every candidate now keeps the aspect ratio of the given `width` and `height`.
- With both `width` and `height` given, `srcset` candidates were not capped to the source width, so small sources were upscaled up to the largest device size. Loaders that resolve metadata (`resolve_metadata`, `true` for filesystem loaders by default) now read the source dimensions in that case too, only to cap the candidates; the rendered `width` and `height` stay as given.
- Crops taller than the source (e.g. a 1:2 crop of a 3:2 photo) were upscaled in height, as candidates were only capped to the source width. They are now also capped to the width whose height matches the source height.

## [2.0.1] - 2026-10-02

### Fixed

- Glide `lock`: a request waiting for the render in progress of the same variant waited as long as that render held its lock, up to `lock.ttl` (30 seconds by default), and could run out of `max_execution_time`: a fatal error, which also ends a FrankenPHP worker. It now waits at most `lock.wait` seconds (new option, default 10), then renders the variant itself.

## [2.0.0] - 2026-10-02

### Added

- `ImageSourceInterface` (`exists()` + `readStream()`) in the new `Silarhi\PicassoBundle\Source` namespace: read access to the originals behind a servable loader, with `LocalImageSource` (local directory, no Flysystem dependency) and `FlysystemImageSource` (Flysystem storage). Custom storages can back a servable loader by implementing the two methods, without writing a Flysystem adapter.
- `ImageSourceFlysystemAdapter`, a read-only Flysystem adapter that lets Glide read any `ImageSourceInterface`.
- Glide options to serve thumbnails from a CDN whose origin is the cache bucket, so PHP only runs on cache misses: `base_url` (image URLs on the CDN host), `public_cache.prefix` (cache keys equal to the URL path) and `defer_cache_write` (a miss is rendered to local disk and moved to the cache storage on `kernel.terminate`, after the response has been sent).
- `cache_control` option: HTTP cache headers of the images served by the bundle controller, set by the controller instead of the transformer. `max_age` (default one year; `null` keeps the transformer's headers), `immutable` (default `true`) and `error_max_age` (cacheable 404s, default off).
- `mapping` option for vich loaders. It defaults to the loader name when that is a VichUploader mapping, else to the only mapping, so `product_image: { type: vich }` (or `vich: ~` with a single mapping) is enough. Unknown or ambiguous mappings fail at container build with the list of available mappings.
- Chain loader (`type: chain`, `loaders: [uploads, assets]`): one loader name for images spread over several filesystem, flysystem or vich loaders. Each image is rendered with the first loader holding it, and its URLs name that loader.
- `Image::$loader`: the loader that loaded an image when another one delegated to it. Generated URLs name it.
- `url_alias` option for loaders and transformers (`urlAlias` on `#[AsImageLoader]` and `#[AsImageTransformer]`): the name of a loader or transformer in image URLs and public-cache keys, e.g. `/image/g/pi/photo.jpg` instead of `/image/glide/product_image/photo.jpg`. URLs using the names keep being served. Conflicting aliases fail at container build.
- `InvalidImageReferenceException`, thrown when an entity passed to a vich loader has no field using its mapping, several of them and no `field` context key, or a `field` using another mapping.
- Glide `lock` option (requires `symfony/lock`): concurrent requests for the same missing variant render it once. The first request renders it while the others wait, then serve it from the cache; with `defer_cache_write`, the lock is held until the variant is uploaded. `lock.factory` picks the `LockFactory` service (default: the one `framework.lock` configures) and `lock.ttl` (default 30 seconds) how long a lock outlives a renderer that crashed.

### Changed

- **BC break:** `ServableLoaderInterface::getSource()` takes no argument and returns `ImageSourceInterface` instead of `FilesystemOperator|string`. A servable loader now reads from a single source. Custom servable loaders must drop the `$metadata` parameter, and wrap a local path in `LocalImageSource` and a Flysystem storage in `FlysystemImageSource`:
    ```php
    // before
    public function getSource(array $metadata): FilesystemOperator|string
    {
        return $this->storage; // or a local path
    }
    // after
    public function getSource(): ImageSourceInterface
    {
        return new FlysystemImageSource($this->storage); // or new LocalImageSource($path)
    }
    ```
- **BC break:** a filesystem loader reads one directory, set with `path`; the `paths` list is removed. Declare one filesystem loader per directory, and keep the former loader name as a chain of them so templates do not change:
    ```yaml
    # before
    loaders:
        filesystem:
            paths: ['%kernel.project_dir%/public/uploads', '%kernel.project_dir%/assets']
    # after
    loaders:
        uploads: { type: filesystem, path: '%kernel.project_dir%/public/uploads' }
        assets: { type: filesystem, path: '%kernel.project_dir%/assets' }
        filesystem: { type: chain, loaders: [uploads, assets] }
    ```
- **BC break:** a vich loader serves one VichUploader mapping. With several mappings, declare one vich loader per mapping (named after it, or with `mapping`), and keep the former loader name as a chain of them so templates do not change. The upload field is now found from the mapping, so the `field` context key is only needed when several fields of an entity share it; before, an omitted `field` picked the entity's first mapping:
    ```yaml
    # before
    loaders:
        vich: ~ # every VichUploader mapping
    # after
    loaders:
        product_image: { type: vich }
        user_avatar: { type: vich }
        vich: { type: chain, loaders: [product_image, user_avatar] }
    ```
- **BC break:** Glide URLs no longer carry the encrypted `_metadata` query param, and `Image::$metadata`, `UrlEncryption` (`picasso.url_encryption`) and `EncryptionException` are removed. A custom loader that told roots apart with `metadata` must be split into one loader per root, chained if they share a name. 1.x URLs carrying it are redirected (301) to the same image under the loader now reading their source, whatever their loader name has become; a deprecation is triggered on each redirect. 1.x URLs without it keep working as long as their loader name still exists:
    ```
    # before
    /image/glide/filesystem/photo.jpg?w=640&fm=webp&_metadata=<encrypted root>&s=…
    # after (the 1.x URL above answers 301 to it)
    /image/glide/uploads/photo.jpg?w=640&fm=webp&s=…
    ```
- **BC break:** `GlideTransformer` and `ImageController` require a `UrlAliases` constructor argument (second for `GlideTransformer`, after the router), and `ImageController` also takes an `array $cacheControl` before it (`$transformerRegistry, $loaderRegistry, $cacheControl, $urlAliases, ?$stopwatch`). Code instantiating them directly must pass `new UrlAliases([], [])` when no URL alias is configured; services wired by the bundle are unaffected.
- **BC break:** `VichMappingHelperInterface`: `getFilePropertyName()` and `getUploadDestination()` are replaced by `resolveField(object $entity, string $mapping, ?string $field): string`, and the `$field` argument of `readMimeType()` and `readDimensions()` is no longer nullable. Only custom implementations or decorators of the interface are affected.
- `FilesystemLoader::load()` now treats paths escaping its base directory (`..`) as missing, as serving already did.
- Glide purges now throw a `PurgeException` when the cache storage cannot delete the variants, instead of failing silently.
- Images served by the bundle controller now carry `immutable` by default, and the `Expires` header set by Glide is dropped. Configurable under `cache_control`; `max_age: ~` keeps the transformer's headers, as before:
    ```
    # before
    Cache-Control: max-age=31536000, public
    Expires: <one year from now>
    # after
    Cache-Control: immutable, max-age=31536000, public
    ```
- Untransformed public-cache URLs now use the reserved params segment `_untransformed`. URLs with an empty params segment now return 404 (they used to loop through redirects):
    ```
    # before
    /image/glide/uploads/photo.jpg/.jpg
    # after
    /image/glide/uploads/photo.jpg/_untransformed.jpg
    ```
- Glide serves a cached variant with two calls to the cache storage (size, then contents) instead of five, and answers a conditional request with one call, without opening the variant. The content type is read from the image's first bytes. On a cache storage whose streams are not local files (an object store), responses no longer carry a `Last-Modified` header, which would cost one more call.
- `league/glide-symfony` is no longer needed by the Glide transformer, which unlocks Glide 4 (`league/glide-symfony` only supports Glide 2 and 3). It can be removed from your dependencies.

### Fixed

- Glide image URLs are now stable across renders, so browsers and CDNs can cache them: the randomly encrypted `_metadata` param that changed on every render is gone.
- Two Vich mappings storing files under the same relative path no longer share Glide cache entries or purges: each mapping is served under its own loader name.
- Purging a Glide image after serving it in the same process (FrankenPHP worker mode, RoadRunner, Swoole, Messenger consumers) now deletes the right cache path.
- Untransformed public-cache URLs no longer cause a redirect loop.
- The Glide `vips` driver, accepted by the configuration since 1.x, now works: it maps to `intervention/image-driver-vips` (Glide 3 or later), and a missing package fails at container build with the command to install it, instead of an "Unable to resolve driver" error when the transformer is first used.

## [1.3.2] - 2026-09-30

### Fixed

- Glide now answers 404 on sources that exist but cannot be decoded, and throws the new `UndecodableImageException` so consumers can tell a broken source from a missing one.
- Glide survives concurrent writes of the same cached variant: when an object store rejects the losing write, the variant is served from the cache.

## [1.3.1] - 2026-09-16

### Added

- Support for `league/glide` 4.

### Fixed

- Public-cache URLs are no longer broken by consumers that split `srcset` on commas.

## [1.3.0] - 2026-06-23

### Added

- Opt-in Symfony web profiler collector (`picasso.collector: true`).

### Changed

- Image dimensions are detected by reading streams progressively (up to 2 MB), so large EXIF/ICC/XMP segments no longer hide them.

## [1.2.0] - 2026-05-28

### Added

- The Glide `cache` option accepts a Flysystem storage name.

## [1.1.0] - 2026-04-18

### Added

- `picasso_image()` Twig function, rendering the same `<picture>` element as the component without `symfony/ux-twig-component`.
- Support for `symfony/ux-twig-component` 3 and `league/glide` 3.

## [1.0.1] - 2026-04-11

### Fixed

- Double slash in generated image paths.

## [1.0.0] - 2026-04-11

Initial release.

[2.4.0]: https://github.com/silarhi/picasso-bundle/compare/v2.3.0...HEAD
[2.3.0]: https://github.com/silarhi/picasso-bundle/compare/v2.2.1...v2.3.0
[2.2.1]: https://github.com/silarhi/picasso-bundle/compare/v2.2.0...v2.2.1
[2.2.0]: https://github.com/silarhi/picasso-bundle/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/silarhi/picasso-bundle/compare/v2.0.1...v2.1.0
[2.0.1]: https://github.com/silarhi/picasso-bundle/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/silarhi/picasso-bundle/compare/v1.3.2...v2.0.0
[1.3.2]: https://github.com/silarhi/picasso-bundle/compare/v1.3.1...v1.3.2
[1.3.1]: https://github.com/silarhi/picasso-bundle/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/silarhi/picasso-bundle/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/silarhi/picasso-bundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/silarhi/picasso-bundle/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/silarhi/picasso-bundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/silarhi/picasso-bundle/releases/tag/v1.0.0
