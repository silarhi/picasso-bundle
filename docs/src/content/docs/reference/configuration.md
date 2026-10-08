---
title: 'Configuration'
description: 'Every option of the picasso configuration, with its default value.'
sidebar:
    order: 2
---

## Minimal configuration

When only one loader and one transformer are configured, they are
automatically used as defaults — no need to set `default_loader` or
`default_transformer`.

```yaml
picasso:
    loaders:
        filesystem:
            path: '%kernel.project_dir%/public/uploads'
    transformers:
        glide:
            sign_key: '%env(PICASSO_SIGN_KEY)%'
```

## Full reference

```yaml
picasso:
    # --- Defaults (auto-detected when only one of each type is configured) ---
    default_loader: ~
    default_transformer: ~
    default_placeholder: ~

    # --- Responsive breakpoints ---
    device_sizes: [640, 750, 828, 1080, 1200, 1920, 2048, 3840]
    image_sizes: [16, 32, 48, 64, 96, 128, 256, 384]

    # --- Output formats (last entry is the <img> fallback) ---
    formats: [avif, webp, jpg]

    # --- Image quality & fit ---
    default_quality: 75 # 1–100
    default_fit: contain # contain | cover | crop | fill

    # --- Metadata resolution ---
    resolve_metadata: false # Whether to auto-detect image dimensions from source (default: false)

    # --- Metadata cache ---
    cache: true # true = cache.app, false = disabled, or a PSR-6 service ID

    # --- HTTP cache headers of the images served by the bundle controller (Glide) ---
    cache_control:
        max_age: 31536000 # seconds a served image may be cached; null keeps the transformer's headers
        immutable: true # served images never change meaning, so caches need not revalidate them
        error_max_age: ~ # seconds a 404 may be cached by clients and CDNs (null: not cacheable)

    # --- Web profiler data collector (dev only) ---
    collector: false # set to true to record image renders / URL generations in the Symfony toolbar

    # --- Placeholders (none by default: each entry below is an opt-in example) ---
    placeholders:
        blur:
            enabled: true # false skips this entry without removing it
            type: transformer # transformer | blurhash | service; inferred only when the key is one of them
            size: 10 # tiny image width/height in px
            blur: 5 # blur radius (null disables blur)
            quality: 30 # quality of the blur image (1–100, null uses the transformer default)
            fit: crop # fit mode of the blur image (null uses the transformer default)
            format: jpg # format of the blur image (null uses the transformer default)

        # blurhash:
        #     type: blurhash
        #     components_x: 4    # horizontal components (1–9)
        #     components_y: 3    # vertical components (1–9)
        #     size: 32           # decoded placeholder image size in px
        #     driver: gd         # gd | imagick

        # my_placeholder:
        #     type: service
        #     service: 'App\Image\MyPlaceholder'

    # --- Loaders ---
    loaders:
        filesystem:
            enabled: true # false skips this loader without removing it
            type: filesystem # inferred from key name
            path: '%kernel.project_dir%/public/uploads' # one directory per loader
            # resolve_metadata: ~  # auto-set to true for filesystem loaders
            # url_alias: ~  # name of this loader in image URLs (see Routes)
            # default_placeholder: ~  # overrides the global default_placeholder for this loader
            # default_transformer: ~  # overrides the global default_transformer for this loader
            # private: false  # true: only your own routes serve its images (see Private Images)

        # my_flysystem:
        #     type: flysystem
        #     storage: 'default.storage'
        #     resolve_metadata: ~  # inherits from global (false)

        # product_image:
        #     type: vich
        #     mapping: ~  # VichUploader mapping; defaults to the loader name, or to the only mapping

        # url:
        #     type: url
        #     http_client: ~       # optional: custom PSR-18 HTTP client service ID
        #     request_factory: ~   # optional: custom PSR-17 request factory service ID
        #     allowed_hosts: []    # hosts Glide may fetch images from ("*.example.com" for subdomains); empty: any host
        #     resolve_metadata: ~  # inherits from global (false)

        # images:
        #     type: chain
        #     loaders: [filesystem, my_flysystem]  # tried in order: each image uses the first loader holding it

    # --- Transformers ---
    transformers:
        glide:
            enabled: true # false skips this transformer without removing it
            type: glide # inferred from key name
            sign_key: ~ # signing key for secure URLs
            cache: '%kernel.project_dir%/var/glide-cache' # local path OR a Flysystem storage name (e.g. 'thumbs.storage')
            driver: gd # gd | imagick | vips
            max_image_size: ~ # optional max pixel count
            base_url: ~ # optional scheme + host prepended to image URLs, e.g. a CDN
            defer_cache_write: false # store a cache miss after the response is sent
            url_alias: ~ # name of this transformer in image URLs (see Routes)
            lock:
                enabled: false # render a missing variant once, however many requests ask for it (symfony/lock)
                factory: lock.factory # LockFactory service ID
                ttl: 30 # seconds a lock outlives a renderer that crashed
                wait: 10 # seconds a miss waits for a render in progress before rendering the variant itself
            public_cache:
                enabled: false # serve transformed images from public directory
                prefix: '' # path prepended to cache keys so they mirror the URL path


        # imgix:
        #     type: imgix
        #     base_url: ~      # e.g. https://my-source.imgix.net
        #     sign_key: ~      # optional signing key
        #     api_key: ~       # optional API key for cache purge
        #     http_client: ~   # PSR-18 HTTP client service ID for purge
        #     request_factory: ~ # PSR-17 request factory service ID for purge
        #     stream_factory: ~  # PSR-17 stream factory service ID for purge

        # my_transformer:
        #     type: service
        #     service: 'App\Image\MyTransformer'
```

## Options explained

These arrays define which widths are generated in the srcset attribute:

- **`device_sizes`** — Breakpoint widths for responsive (fluid) images.
  When the component has a `sizes` attribute, all device and image sizes
  are merged and included in the srcset.
- **`image_sizes`** — Smaller widths for fixed-size images (icons,
  thumbnails). When no `sizes` attribute is provided, srcset includes
  only `1x` and `2x` descriptors based on the specified `width`.

### `formats`

The list of output formats. A `<source>` element is generated for each
format except the last one, which is used as the `<img>` fallback. The
default `[avif, webp, jpg]` produces:

```html
<picture>
    <source type="image/avif" srcset="..." />
    <source type="image/webp" srcset="..." />
    <img src="..." srcset="..." />
    <!-- jpg fallback -->
</picture>
```

Supported formats: `avif`, `webp`, `jpg`, `jpeg`, `pjpg`, `png`, `gif`.

### `default_fit`

Controls how images are resized within the target dimensions:

| Fit       | Description                                                                     |
| --------- | ------------------------------------------------------------------------------- |
| `contain` | Fits inside the box, keeping the aspect ratio: one side may be shorter (default) |
| `cover`   | Fills the box, keeping the aspect ratio, cropping the excess (same as `crop`)     |
| `crop`    | Fills the box, keeping the aspect ratio, cropping the excess                      |
| `fill`    | Fits inside the box, then pads it to the exact box size                           |

See them rendered side by side in [Fit modes](/guides/fit-modes/).

### `resolve_metadata`

Controls whether the bundle reads image streams to auto-detect dimensions (width/height).

- `false` (default) — dimensions are not auto-detected; provide them explicitly or accept no `width`/`height` in the HTML
- `true` — enables auto-detection via the `MetadataGuesser`

This can also be set **per-loader** (filesystem loaders default to `true`) and overridden at **runtime** with the `resolveMetadata` component prop or `imageData()` parameter.

Detection reads the stream progressively (64KB initially, doubling up to 2MB), so dimensions are found even in files whose headers sit behind large embedded EXIF/ICC/XMP segments. SVG is not supported (`getimagesize()` cannot parse XML); pass explicit dimensions or use `unoptimized` for SVG sources.

### `cache`

Configures PSR-6 caching for metadata detection (image dimensions) and BlurHash encoding:

- `true` (default) — uses the `cache.app` service
- `false` — disables caching
- `'my_cache_pool'` — uses a custom PSR-6 cache pool service ID

:::tip
The `type` option for loaders, transformers, and placeholders is automatically inferred from the key name
when it matches a known type (`filesystem`, `flysystem`, `vich`, `url`, `glide`, `imgix`, `transformer`, `blurhash`).
Use `type` explicitly only when your key name differs from the type.
:::
