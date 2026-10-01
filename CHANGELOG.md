# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

### Added

- `ImageSourceInterface` (`exists()` + `readStream()`) in the new `Silarhi\PicassoBundle\Source` namespace: read access to the originals behind a servable loader, with `LocalImageSource` (local directory, no Flysystem dependency) and `FlysystemImageSource` (Flysystem storage). Custom storages can back a servable loader by implementing the two methods, without writing a Flysystem adapter.
- `ImageSourceFlysystemAdapter`, a read-only Flysystem adapter that lets Glide read any `ImageSourceInterface`.
- Glide options to serve thumbnails from a CDN whose origin is the cache bucket, so PHP only runs on cache misses: `base_url` (image URLs on the CDN host), `public_cache.prefix` (cache keys equal to the URL path) and `defer_cache_write` (a miss is rendered to local disk and moved to the cache storage on `kernel.terminate`, after the response has been sent).
- `cache_control` option: HTTP cache headers of the images served by the bundle controller, set by the controller instead of the transformer. `max_age` (default one year; `null` keeps the transformer's headers), `immutable` (default `true`) and `error_max_age` (cacheable 404s, default off).
- `mapping` option for vich loaders. It defaults to the loader name when that is a VichUploader mapping, else to the only mapping, so `product_image: { type: vich }` (or `vich: ~` with a single mapping) is enough. Unknown or ambiguous mappings fail at container build with the list of available mappings.
- Chain loader (`type: chain`, `loaders: [uploads, assets]`): one loader name for images spread over several filesystem, flysystem or vich loaders. Each image is rendered with the first loader holding it, and its URLs name that loader.
- `Image::$loader`: the loader that loaded an image when another one delegated to it. Generated URLs name it.
- `InvalidImageReferenceException`, thrown when an entity passed to a vich loader has no field using its mapping, several of them and no `field` context key, or a `field` using another mapping.

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

### Fixed

- Glide image URLs are now stable across renders, so browsers and CDNs can cache them: the randomly encrypted `_metadata` param that changed on every render is gone.
- Two Vich mappings storing files under the same relative path no longer share Glide cache entries or purges: each mapping is served under its own loader name.
- Purging a Glide image after serving it in the same process (FrankenPHP worker mode, RoadRunner, Swoole, Messenger consumers) now deletes the right cache path.
- Untransformed public-cache URLs no longer cause a redirect loop.

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

[2.0.0]: https://github.com/silarhi/picasso-bundle/compare/v1.3.2...HEAD
[1.3.2]: https://github.com/silarhi/picasso-bundle/compare/v1.3.1...v1.3.2
[1.3.1]: https://github.com/silarhi/picasso-bundle/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/silarhi/picasso-bundle/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/silarhi/picasso-bundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/silarhi/picasso-bundle/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/silarhi/picasso-bundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/silarhi/picasso-bundle/releases/tag/v1.0.0
