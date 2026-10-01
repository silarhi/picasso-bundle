# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

### Added

- `ImageSourceInterface` (`exists()` + `readStream()`) in the new `Silarhi\PicassoBundle\Source` namespace: read access to the originals behind a servable loader, with `LocalImageSource` (local directory, no Flysystem dependency) and `FlysystemImageSource` (Flysystem storage). Custom storages can back a servable loader by implementing the two methods, without writing a Flysystem adapter.
- `ImageSourceFlysystemAdapter`, a read-only Flysystem adapter that lets Glide read any `ImageSourceInterface`.
- Glide options to serve thumbnails from a CDN whose origin is the cache bucket, so PHP only runs on cache misses: `base_url` (image URLs on the CDN host), `public_cache.prefix` (cache keys equal to the URL path) and `error_max_age` (cacheable 404s).

### Changed

- **BC break:** `ServableLoaderInterface::getSource()` now returns `ImageSourceInterface` instead of `FilesystemOperator|string`. Custom servable loaders must wrap a local path in `LocalImageSource` and a Flysystem storage in `FlysystemImageSource`.
- `FilesystemLoader::load()` now treats paths escaping its base directory (`..`) as missing, as serving already did.
- Glide purges now throw a `PurgeException` when the cache storage cannot delete the variants, instead of failing silently.
- Glide image responses now carry `Cache-Control: public, max-age=31536000, immutable`.
- Untransformed public-cache URLs now use the reserved params segment `_untransformed` (e.g. `photo.jpg/_untransformed.jpg`). URLs with an empty params segment such as `photo.jpg/.jpg` now return 404.

### Fixed

- Glide image URLs are now stable across renders: `_metadata` is encrypted deterministically, so browsers and CDNs can cache them. URLs published with the previous random nonces keep working.
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
