<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://img.shields.io/packagist/v/silarhi/picasso-bundle?style=for-the-badge&label=stable&color=0d6efd&labelColor=1a1a2e">
        <img src="https://img.shields.io/packagist/v/silarhi/picasso-bundle?style=for-the-badge&label=stable&color=0d6efd"
            alt="Latest Stable Version">
    </picture>
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://img.shields.io/packagist/dt/silarhi/picasso-bundle?style=for-the-badge&color=198754&labelColor=1a1a2e">
        <img src="https://img.shields.io/packagist/dt/silarhi/picasso-bundle?style=for-the-badge&color=198754" alt="Total Downloads">
    </picture>
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://img.shields.io/packagist/l/silarhi/picasso-bundle?style=for-the-badge&color=6f42c1&labelColor=1a1a2e">
        <img src="https://img.shields.io/packagist/l/silarhi/picasso-bundle?style=for-the-badge&color=6f42c1" alt="License">
    </picture>
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://img.shields.io/packagist/php-v/silarhi/picasso-bundle?style=for-the-badge&color=777bb4&labelColor=1a1a2e">
        <img src="https://img.shields.io/packagist/php-v/silarhi/picasso-bundle?style=for-the-badge&color=777bb4" alt="PHP Version">
    </picture>
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://img.shields.io/github/actions/workflow/status/silarhi/picasso-bundle/continuous-integration.yml?style=for-the-badge&label=CI&color=20c997&labelColor=1a1a2e">
        <img src="https://img.shields.io/github/actions/workflow/status/silarhi/picasso-bundle/continuous-integration.yml?style=for-the-badge&label=CI&color=20c997"
            alt="CI Status">
    </picture>
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fsilarhi%2Fpicasso-bundle%2Fbadges%2Fcoverage.json&style=for-the-badge&labelColor=1a1a2e">
        <img src="https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fsilarhi%2Fpicasso-bundle%2Fbadges%2Fcoverage.json&style=for-the-badge" alt="Coverage">
    </picture>
</p>

<h1 align="center">PicassoBundle</h1>

<p align="center">
    <strong>The missing image component for Symfony.</strong><br>
    Inspired by <a href="https://nextjs.org/docs/app/api-reference/components/image">Next.js Image</a> — built for the Symfony ecosystem.
</p>

<p align="center">
    Write one line of Twig. Get AVIF, WebP, responsive srcset, blur placeholders, and lazy loading — automatically.
</p>

---

### Before PicassoBundle

```html
<!-- You write all of this manually... and maintain it forever -->
<picture>
    <source
        type="image/avif"
        srcset="/images/hero-640.avif 640w, /images/hero-1080.avif 1080w, /images/hero-1920.avif 1920w"
        sizes="100vw"
    />
    <source
        type="image/webp"
        srcset="/images/hero-640.webp 640w, /images/hero-1080.webp 1080w, /images/hero-1920.webp 1920w"
        sizes="100vw"
    />
    <img
        src="/images/hero-1080.jpg"
        srcset="/images/hero-640.jpg 640w, /images/hero-1080.jpg 1080w, /images/hero-1920.jpg 1920w"
        sizes="100vw"
        width="1920"
        height="1080"
        loading="lazy"
        alt="Hero"
    />
</picture>
```

### After PicassoBundle

```twig
<twig:Picasso:Image src="hero.jpg" width="1920" height="1080" sizes="100vw" alt="Hero" />
```

> Same output. Zero boilerplate. All formats, srcsets, and placeholders generated automatically.

---

## Why PicassoBundle?

Images account for the largest share of page weight on most websites.
Serving them correctly — with modern formats, responsive srcsets, proper
lazy loading, and blur placeholders — is critical for both
**Core Web Vitals** and **user experience**, but the implementation is
tedious and error-prone.

PicassoBundle solves this the same way Next.js Image did for React:
**a single component that handles everything**.

|                         | Without PicassoBundle                 | With PicassoBundle                       |
| ----------------------- | ------------------------------------- | ---------------------------------------- |
| **Format negotiation**  | Manual AVIF/WebP/JPEG `<source>` tags | Automatic from config                    |
| **Responsive srcset**   | Hand-crafted per breakpoint           | Generated from `sizes` prop              |
| **Blur placeholders**   | DIY or skip it                        | Built-in (LQIP, BlurHash, or custom)     |
| **Dimension detection** | Hardcoded or forgotten                | Auto-detected from image stream          |
| **LCP optimization**    | Manually set loading/fetchpriority    | One `priority` prop                      |
| **Image sources**       | Filesystem only                       | Filesystem, S3, Flysystem, Vich, URL     |
| **CDN support**         | Build your own integration            | Imgix out of the box, or plug in any CDN |

---

## Table of Contents

- [Why PicassoBundle?](#why-picassobundle)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
    - [Upgrading from 1.x](#upgrading-from-1x)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
    - [Minimal Configuration](#minimal-configuration)
    - [Full Configuration Reference](#full-configuration-reference)
    - [Configuration Options Explained](#configuration-options-explained)
- [Usage](#usage)
    - [Twig Component](#twig-component-recommended)
    - [Twig Function](#twig-function)
    - [ImageHelper Service](#imagehelper-service)
- [Placeholders](#placeholders)
    - [Transformer Placeholder (LQIP)](#transformer-placeholder-lqip)
    - [BlurHash Placeholder](#blurhash-placeholder)
    - [Custom Placeholder Service](#custom-placeholder-service)
    - [Controlling Placeholders Per Image](#controlling-placeholders-per-image)
- [Priority Images](#priority-images)
- [Loaders](#loaders)
    - [Filesystem Loader](#filesystem-loader)
    - [Flysystem Loader](#flysystem-loader)
    - [VichUploaderBundle Loader](#vichuploaderbundle-loader)
    - [URL Loader](#url-loader)
    - [Chain Loader](#chain-loader)
    - [Custom Loader](#custom-loader)
- [Transformers](#transformers)
    - [Glide (Local)](#glide-local)
        - [Choosing a driver](#choosing-a-driver)
        - [Rendering each variant once](#rendering-each-variant-once)
        - [Storing the Glide cache on Flysystem](#storing-the-glide-cache-on-flysystem)
        - [Serving thumbnails from a CDN](#serving-thumbnails-from-a-cdn)
    - [Imgix (CDN)](#imgix-cdn)
    - [Custom Transformer](#custom-transformer)
- [Routes](#routes)
    - [URL Aliases](#url-aliases)
    - [Error Responses](#error-responses)
    - [1.x URLs](#1x-urls)
- [Cache Purge](#cache-purge)
    - [Programmatic Usage](#programmatic-usage)
- [How It Works](#how-it-works)
- [Performance](#performance)
- [Testing & Quality](#testing--quality)
- [Contributing](#contributing)
- [License](#license)

---

## Features

- **One component, full optimization** — `<twig:Picasso:Image>` renders a complete `<picture>` with AVIF, WebP, and JPEG sources
- **Automatic responsive srcset** — generates width descriptors for all configured breakpoints, no manual work
- **Blur placeholders** — built-in LQIP and [BlurHash](https://blurha.sh/) support for instant perceived loading
- **Smart dimension detection** — reads image dimensions from the stream automatically, preserves aspect ratio
- **Priority images** — one prop for `loading="eager"` + `fetchpriority="high"` (LCP optimization)
- **Multiple image sources** — Local filesystem, [Flysystem](https://flysystem.thephpleague.com/) (S3, GCS, Azure),
  [VichUploaderBundle](https://github.com/dustin10/VichUploaderBundle), remote URLs
- **Local or CDN transforms** —
  [Glide](https://glide.thephpleague.com/) for self-hosted,
  [Imgix](https://imgix.com/) for CDN, or bring your own
- **Signed URLs** — HMAC-signed transformation URLs prevent abuse
- **PSR-6 metadata caching** — dimension detection and BlurHash results cached
- **Fully extensible** — add custom loaders, transformers, or placeholders
  with PHP attributes (`#[AsImageLoader]`, `#[AsImageTransformer]`,
  `#[AsPlaceholder]`)

## Requirements

| Dependency                                                                                    | Version         |
| --------------------------------------------------------------------------------------------- | --------------- |
| PHP                                                                                           | 8.2+            |
| Symfony                                                                                       | 6.4 / 7.0 / 8.0 |
| [Symfony UX Twig Component](https://symfony.com/bundles/ux-twig-component/current/index.html) | 2.13+           |

### Optional Dependencies

| Package                                   | Required for                                                                 |
| ----------------------------------------- | ---------------------------------------------------------------------------- |
| `league/glide`                            | Glide transformer (local image processing)                                   |
| `intervention/image-driver-vips`          | Glide `vips` driver ([libvips](#choosing-a-driver))                          |
| `symfony/lock`                            | Glide `lock` option ([one render per variant](#rendering-each-variant-once)) |
| `kornrunner/blurhash` + `imagine/imagine` | BlurHash placeholder                                                         |
| `league/flysystem-bundle`                 | Flysystem loader                                                             |
| `vich/uploader-bundle`                    | VichUploader loader                                                          |
| `symfony/http-client`                     | URL loader                                                                   |

## Installation

```bash
composer require silarhi/picasso-bundle
```

If not using Symfony Flex, register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Silarhi\PicassoBundle\PicassoBundle::class => ['all' => true],
];
```

Install a transformer — at least one is required:

```bash
# Option A: Glide (local image transformation)
composer require league/glide

# Option B: Imgix (CDN-based transformation)
# No extra package needed, just configure your Imgix base URL
```

### Upgrading from 1.x

2.0 contains breaking changes: one directory per filesystem loader, one mapping per vich loader, no more
`_metadata` in Glide URLs, new constructor arguments and `immutable` cache headers by default. Follow
[UPGRADE-2.0.md](UPGRADE-2.0.md) for the steps, and see the [CHANGELOG](CHANGELOG.md) for everything that changed.

## Quick Start

**1. Configure** a loader, a transformer and a placeholder:

```yaml
# config/packages/picasso.yaml
picasso:
    loaders:
        filesystem:
            path: '%kernel.project_dir%/public/uploads'
    transformers:
        glide:
            sign_key: '%env(PICASSO_SIGN_KEY)%'
    placeholders:
        blur: { type: transformer } # optional: blurred preview while the image loads
```

**2. Import the routes** (required for Glide local serving):

```yaml
# config/routes/picasso.yaml
picasso:
    resource: '@PicassoBundle/config/routes.php'
```

**3. Use the Twig component** in your templates:

```twig
<twig:Picasso:Image
    src="photo.jpg"
    width="800"
    height="600"
    sizes="(max-width: 768px) 100vw, 800px"
    alt="A beautiful landscape"
/>
```

This renders a `<picture>` element with `<source>` tags for AVIF and
WebP, a fallback `<img>` with JPEG srcset, and an inline blur
placeholder — all automatically. Placeholders are opt-in: without the
`placeholders` entry, images render without one (see [Placeholders](#placeholders)).

## Configuration

### Minimal Configuration

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

### Full Configuration Reference

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

### Configuration Options Explained

#### `device_sizes` and `image_sizes`

These arrays define which widths are generated in the srcset attribute:

- **`device_sizes`** — Breakpoint widths for responsive (fluid) images.
  When the component has a `sizes` attribute, all device and image sizes
  are merged and included in the srcset.
- **`image_sizes`** — Smaller widths for fixed-size images (icons,
  thumbnails). When no `sizes` attribute is provided, srcset includes
  only `1x` and `2x` descriptors based on the specified `width`.

#### `formats`

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

#### `default_fit`

Controls how images are resized within the target dimensions:

| Fit       | Description                                                          |
| --------- | -------------------------------------------------------------------- |
| `contain` | Scales down to fit within the box, preserving aspect ratio (default) |
| `cover`   | Scales to fill the box, cropping excess                              |
| `crop`    | Crops to exact dimensions                                            |
| `fill`    | Stretches to fill the box exactly                                    |

#### `resolve_metadata`

Controls whether the bundle reads image streams to auto-detect dimensions (width/height).

- `false` (default) — dimensions are not auto-detected; provide them explicitly or accept no `width`/`height` in the HTML
- `true` — enables auto-detection via the `MetadataGuesser`

This can also be set **per-loader** (filesystem loaders default to `true`) and overridden at **runtime** with the `resolveMetadata` component prop or `imageData()` parameter.

Detection reads the stream progressively (64KB initially, doubling up to 2MB), so dimensions are found even in files whose headers sit behind large embedded EXIF/ICC/XMP segments. SVG is not supported (`getimagesize()` cannot parse XML); pass explicit dimensions or use `unoptimized` for SVG sources.

#### `cache`

Configures PSR-6 caching for metadata detection (image dimensions) and BlurHash encoding:

- `true` (default) — uses the `cache.app` service
- `false` — disables caching
- `'my_cache_pool'` — uses a custom PSR-6 cache pool service ID

> **Tip:** The `type` option for loaders, transformers, and placeholders is automatically inferred from the key name
> when it matches a known type (`filesystem`, `flysystem`, `vich`, `url`, `glide`, `imgix`, `transformer`, `blurhash`).
> Use `type` explicitly only when your key name differs from the type.

## Usage

### Twig Component (recommended)

The `<twig:Picasso:Image>` component renders a responsive `<picture>` element
with `<source>` tags for each configured format and a fallback `<img>`
with a full srcset.

```twig
<twig:Picasso:Image
    src="photo.jpg"
    width="800"
    height="600"
    sizes="(max-width: 768px) 100vw, 800px"
    alt="A beautiful landscape"
/>
```

#### Component Properties

| Property          | Type           | Default | Description                                                      |
| ----------------- | -------------- | ------- | ---------------------------------------------------------------- |
| `src`             | `string`       | —       | Image path relative to the loader's base                         |
| `width`           | `int`          | auto    | Display width (auto-detected from source)                        |
| `height`          | `int`          | auto    | Display height (auto-detected from source)                       |
| `sizes`           | `string`       | —       | Responsive `sizes` attribute                                     |
| `sourceWidth`     | `int`          | auto    | Explicit source width (skips detection)                          |
| `sourceHeight`    | `int`          | auto    | Explicit source height (skips detection)                         |
| `loader`          | `string`       | —       | Override default loader                                          |
| `transformer`     | `string`       | —       | Override default transformer                                     |
| `quality`         | `int`          | 75      | Override quality (1–100)                                         |
| `fit`             | `string`       | contain | Fit mode: `contain`, `cover`, `crop`, `fill`                     |
| `placeholder`     | `string\|bool` | —       | `true`/`false` to enable/disable, or a placeholder name          |
| `placeholderData` | `string`       | —       | Literal data URI, bypasses placeholder services                  |
| `priority`        | `bool`         | false   | Eager loading, `fetchpriority="high"`, no placeholder            |
| `loading`         | `string`       | lazy    | `lazy` or `eager`. Auto-set when priority                        |
| `fetchPriority`   | `string`       | —       | `high`, `low`, `auto`. Auto-set when priority                    |
| `unoptimized`     | `bool`         | false   | Serve original image without transformation                      |
| `resolveMetadata` | `bool`         | —       | Override metadata resolution (see [below](#metadata-resolution)) |
| `context`         | `array`        | `[]`    | Extra context for the loader (e.g. Vich)                         |

#### Automatic Dimension Detection

When `width` and `height` are not provided, PicassoBundle can
detect them from the image stream. You can also provide `sourceWidth`
and `sourceHeight` to skip detection entirely, which is useful for
performance when you already know the image dimensions:

```twig
{# Auto-detected dimensions (requires resolve_metadata enabled) #}
<twig:Picasso:Image src="photo.jpg" sizes="100vw" alt="Photo" />

{# Explicit source dimensions (skips stream detection) #}
<twig:Picasso:Image src="photo.jpg" :sourceWidth="4000" :sourceHeight="3000" width="800" height="600" alt="Photo" />
```

The component also preserves aspect ratio when only one display dimension is provided:

```twig
{# height is calculated automatically from the source aspect ratio #}
<twig:Picasso:Image src="photo.jpg" width="800" sizes="100vw" alt="Photo" />
```

#### Metadata Resolution

To reduce **Cumulative Layout Shift (CLS)**, `width` and `height`
attributes are only rendered in the HTML when **both** are available.
If only one dimension is provided and the other cannot be resolved,
neither is output — preventing the browser from reserving incorrect
space.

Metadata resolution (reading the image stream to detect dimensions) is
controlled at three levels, with this precedence: **runtime > per-loader > global**.

| Level      | Option             | Default                                        |
| ---------- | ------------------ | ---------------------------------------------- |
| Global     | `resolve_metadata` | `false`                                        |
| Per-loader | `resolve_metadata` | `null` (inherit global); `true` for filesystem |
| Runtime    | `resolveMetadata`  | `null` (inherit per-loader/global)             |

```twig
{# Force metadata resolution for this image, regardless of config #}
<twig:Picasso:Image src="photo.jpg" :resolveMetadata="true" width="800" sizes="100vw" alt="Photo" />

{# Disable metadata resolution for this image #}
<twig:Picasso:Image src="photo.jpg" :resolveMetadata="false" width="800" height="600" alt="Photo" />
```

Filesystem loaders default to `resolve_metadata: true` because reading
local files is cheap. For remote loaders (URL, Flysystem with remote
backends), it defaults to `false` to avoid unnecessary network requests.

When metadata resolution is enabled, the source dimensions are read even if
both `width` and `height` are given: they only cap the `srcset` candidates to
the source width, so an image is never upscaled, while the rendered `width`
and `height` stay as given. With resolution disabled, giving both display
dimensions skips the read.

In responsive mode (`sizes`), every `srcset` candidate keeps the aspect ratio
of the given `width` and `height`, like the fallback `src`.

#### Extra HTML Attributes

The component forwards any extra attributes to the inner `<img>` tag:

```twig
<twig:Picasso:Image
    src="photo.jpg"
    width="400"
    height="300"
    class="rounded shadow-lg"
    id="main-photo"
    data-controller="lightbox"
    alt="Photo"
/>
```

#### Unoptimized Mode

Use `unoptimized` to serve the image as-is, without any transformation. The `src` value is passed directly to the `<img>` tag:

```twig
<twig:Picasso:Image src="/images/logo.svg" :unoptimized="true" alt="Logo" />
```

### Twig Function

Two Twig functions are available:

- **`picasso_image()`** — renders a full responsive `<picture>` element (same
  output as the `<twig:Picasso:Image>` component) for consumers who don't want to
  install `symfony/ux-twig-component`.
- **`picasso_image_url()`** — generates a single transformed image URL.

#### `picasso_image()`

Renders a complete responsive `<picture>` element with all configured formats,
srcsets, placeholder, and `<img>` fallback. It accepts the same named arguments
as the `<twig:Picasso:Image>` Twig component.

```twig
{# Same output as <twig:Picasso:Image src="photo.jpg" width="800" height="600" sizes="100vw" alt="A photo" /> #}
{{ picasso_image(
    src='photo.jpg',
    width=800,
    height=600,
    sizes='100vw',
    attributes={alt: 'A photo', class: 'rounded shadow-lg'}
) }}
```

Extra HTML attributes (`alt`, `class`, `id`, `data-*`, …) are passed via the
`attributes` named argument and forwarded to the inner `<img>` tag.

All available parameters mirror the `<twig:Picasso:Image>` component:
`src`, `width`, `height`, `sizes`, `sourceWidth`, `sourceHeight`, `loader`,
`transformer`, `quality`, `fit`, `placeholder`, `placeholderData`, `priority`,
`loading`, `fetchPriority`, `unoptimized`, `resolveMetadata`, `context`,
`attributes`.

See [Component Properties](#component-properties) for the full reference.

#### `picasso_image_url()`

Generates a single transformed image URL.
Useful for backgrounds, meta tags, Open Graph images, or anywhere you need a plain URL.

```twig
{# Simple thumbnail #}
<img src="{{ picasso_image_url('photo.jpg', width: 300, format: 'webp') }}" alt="Thumbnail">

{# Open Graph meta tag #}
<meta property="og:image" content="{{ picasso_image_url('hero.jpg', width: 1200, height: 630, format: 'jpg', fit: 'cover') }}">

{# CSS background image #}
<div style="background-image: url('{{ picasso_image_url('bg.jpg', width: 1920, format: 'webp', quality: 80) }}')">
```

All available parameters:

```twig
{{ picasso_image_url(
    'photo.jpg',
    width: 800,
    height: 600,
    format: 'webp',
    quality: 85,
    fit: 'cover',
    blur: 10,
    dpr: 2,
    loader: 'product_image',
    transformer: 'imgix',
    context: { entity: product }
) }}
```

| Parameter     | Type     | Description                                   |
| ------------- | -------- | --------------------------------------------- |
| `width`       | `int`    | Target width in pixels                        |
| `height`      | `int`    | Target height in pixels                       |
| `format`      | `string` | Output format (`avif`, `webp`, `jpg`, etc.)   |
| `quality`     | `int`    | Output quality (1–100)                        |
| `fit`         | `string` | Fit mode (`contain`, `cover`, `crop`, `fill`) |
| `blur`        | `int`    | Blur radius                                   |
| `dpr`         | `int`    | Device pixel ratio                            |
| `loader`      | `string` | Override default loader                       |
| `transformer` | `string` | Override default transformer                  |
| `context`     | `array`  | Extra context for the loader                  |

### ImageHelper Service

The `picasso_image_url()` Twig function delegates to `ImageHelperInterface`, which you can also inject directly in your PHP code:

```php
use Silarhi\PicassoBundle\Service\ImageHelperInterface;

class MyController
{
    public function __construct(private ImageHelperInterface $imageHelper) {}

    public function index(): Response
    {
        $url = $this->imageHelper->imageUrl(
            path: 'photo.jpg',
            width: 300,
            format: 'webp',
        );

        // ...
    }
}
```

#### Image Data for JSON APIs

The `imageData()` method returns an `ImageRenderData` DTO containing all
rendering data (sources, srcset, placeholder, dimensions, loading attributes)
plus the resolved loader, transformer and placeholder names.
It implements `JsonSerializable`, making it ideal for headless / API-driven
frontends (React, Vue, mobile apps, etc.):

```php
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class ImageApiController
{
    public function __construct(private ImageHelperInterface $imageHelper) {}

    public function show(): JsonResponse
    {
        $data = $this->imageHelper->imageData(
            src: 'hero.jpg',
            width: 1200,
            height: 800,
            sizes: '100vw',
            placeholder: true,
        );

        return new JsonResponse($data);
    }
}
```

The JSON response contains everything a frontend needs to render a responsive `<picture>` element:

```json
{
    "fallbackSrc": "/image/glide/filesystem/hero.jpg?w=1200&h=800&fm=jpg&s=...",
    "fallbackSrcset": "/image/glide/.../hero.jpg?w=640&fm=jpg&s=... 640w, ... 1920w",
    "sources": [
        { "type": "image/avif", "srcset": "..." },
        { "type": "image/webp", "srcset": "..." }
    ],
    "placeholderUri": "data:image/jpeg;base64,...",
    "width": 1200,
    "height": 800,
    "loading": "lazy",
    "fetchPriority": null,
    "sizes": "100vw",
    "unoptimized": false,
    "attributes": {},
    "loader": "filesystem",
    "transformer": "glide",
    "placeholder": "blur"
}
```

`imageData()` accepts the same parameters as the `<twig:Picasso:Image>` Twig component (`src`, `width`, `height`, `sizes`, `quality`, `fit`, `placeholder`, `priority`, `loader`, `transformer`, etc.).

## Placeholders

Placeholders generate a low-quality preview displayed while the full image loads.
The placeholder is inlined as a CSS `background-image` on the `<img>` tag and
automatically removed via an `onload` handler once the full image has loaded.

### Transformer Placeholder (LQIP)

The transformer placeholder generates a tiny blurred version of the image using
your configured transformer (Glide or Imgix). This is the simplest placeholder
to set up — it requires no extra dependencies.

```yaml
picasso:
    default_placeholder: blur
    placeholders:
        blur:
            type: transformer
            size: 10 # tiny image width/height in px
            blur: 5 # blur radius
            quality: 30 # JPEG quality (1–100)
```

### BlurHash Placeholder

The BlurHash placeholder encodes the image as a [BlurHash](https://blurha.sh/) string
and decodes it to a tiny PNG data URI. This produces a smooth gradient-like preview
that is very small (around 20–30 bytes as a hash).

```bash
composer require kornrunner/blurhash imagine/imagine
```

```yaml
picasso:
    default_placeholder: blurhash
    placeholders:
        blurhash:
            type: blurhash
            components_x: 4 # horizontal components (1–9, higher = more detail)
            components_y: 3 # vertical components (1–9, higher = more detail)
            size: 32 # decoded placeholder image size in px
            driver: gd # gd | imagick
```

### Custom Placeholder Service

You can create your own placeholder by implementing `PlaceholderInterface`:

```php
use Silarhi\PicassoBundle\Attribute\AsPlaceholder;
use Silarhi\PicassoBundle\Placeholder\PlaceholderInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;

#[AsPlaceholder('thumbhash')]
class ThumbHashPlaceholder implements PlaceholderInterface
{
    public function generate(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        // Generate and return a data URI
        return 'data:image/png;base64,...';
    }
}
```

Or register it via configuration:

```yaml
picasso:
    default_placeholder: thumbhash
    placeholders:
        thumbhash:
            type: service
            service: 'App\Image\ThumbHashPlaceholder'
```

### Controlling Placeholders Per Image

```twig
{# Uses the default placeholder from config #}
<twig:Picasso:Image src="photo.jpg" width="800" height="600" sizes="100vw" alt="Photo" />

{# Disable placeholder for this image #}
<twig:Picasso:Image src="icon.png" width="64" height="64" :placeholder="false" />

{# Select a specific named placeholder #}
<twig:Picasso:Image src="hero.jpg" width="1200" height="800" placeholder="blurhash" />

{# Pass a literal data URI directly (bypasses all placeholder services) #}
<twig:Picasso:Image src="photo.jpg" width="800" height="600" placeholderData="data:image/png;base64,..." />
```

## Priority Images

For above-the-fold images (hero banners, LCP images), use the `priority` prop.
This sets `loading="eager"`, `fetchpriority="high"`, and disables the blur
placeholder for optimal Largest Contentful Paint (LCP) performance:

```twig
<twig:Picasso:Image
    src="hero-banner.jpg"
    width="1920"
    height="1080"
    sizes="100vw"
    :priority="true"
    alt="Hero banner"
/>
```

> **Note:** Placeholders are automatically disabled when `priority` is
> `true`, since priority images should load immediately without showing
> a placeholder first.

## Loaders

Loaders fetch image data from a source. Each loader implements `ImageLoaderInterface` and is registered by name.

### Filesystem Loader

Reads images from a local directory. Each filesystem loader reads from exactly one `path`; declare one loader per directory:

```yaml
picasso:
    default_loader: uploads
    loaders:
        uploads:
            type: filesystem
            path: '%kernel.project_dir%/public/uploads'
        assets:
            type: filesystem
            path: '%kernel.project_dir%/assets/images'
```

```twig
<twig:Picasso:Image src="photos/landscape.jpg" width="800" height="600" alt="Landscape" />
<twig:Picasso:Image src="logo.png" loader="assets" width="200" height="80" alt="Logo" />
```

Paths escaping the directory (`../`) are treated as missing images.

### Flysystem Loader

Reads images via a [Flysystem](https://flysystem.thephpleague.com/) storage, supporting S3, GCS, Azure, and more.

```bash
composer require league/flysystem-bundle
```

```yaml
picasso:
    loaders:
        my_s3:
            type: flysystem
            storage: 'default.storage' # your Flysystem service ID
```

```twig
<twig:Picasso:Image src="photo.jpg" loader="my_s3" width="800" height="600" alt="S3 image" />
```

### VichUploaderBundle Loader

Loads images managed by [VichUploaderBundle](https://github.com/dustin10/VichUploaderBundle).

```bash
composer require vich/uploader-bundle
```

Both VichUploaderBundle `2.9+` and `3.0+` are supported. VichUploader v3 requires PHP `8.3+`, so Composer resolves to v2 on PHP 8.2.

A vich loader serves **one VichUploader mapping**. Name the loader after the mapping, and that is all the configuration it needs:

```yaml
vich_uploader:
    mappings:
        product_image:
            upload_destination: '%kernel.project_dir%/public/uploads/products'
            uri_prefix: /uploads/products
        user_avatar:
            upload_destination: avatars.storage # a Flysystem storage works too
            uri_prefix: /uploads/avatars

picasso:
    loaders:
        product_image: { type: vich } # serves the "product_image" mapping
        avatars: { type: vich, mapping: user_avatar } # or pick the mapping explicitly
```

When VichUploader has a single mapping, `vich: ~` is enough: the loader serves that mapping.

Pass the entity as `context`. The upload field is found from the loader's mapping:

```twig
<twig:Picasso:Image
    loader="product_image"
    :context="{ entity: product }"
    width="400"
    height="300"
    alt="Product image"
/>
```

Only when an entity has several fields using the same mapping do you need to name one with `field` (e.g. `:context="{ entity: gallery, field: 'coverFile' }"`).

Mistakes are reported with the fix:

- At container build: a `mapping` that does not exist, or a vich loader that could serve several mappings, lists the available mappings.
- At render time: an entity without a field using the loader's mapping, a `field` using another mapping, or an ambiguous field throws an `InvalidImageReferenceException` naming the entity, the field and the mappings involved.

### URL Loader

Loads and transforms remote images by URL. Requires a PSR-18 HTTP client.

```bash
composer require symfony/http-client
```

```yaml
picasso:
    loaders:
        url: ~ # type inferred from key name
```

```twig
<twig:Picasso:Image
    src="https://example.com/remote-image.jpg"
    loader="url"
    width="800"
    height="600"
    alt="Remote image"
/>
```

### Chain Loader

Groups several filesystem, flysystem or vich loaders under one name, for images spread over several directories, storages or VichUploader mappings. Each image is rendered with the first loader of the chain holding it:

- an image path goes to the first loader whose directory or storage has it (or to the first loader when none does: its URL then 404s, as with any loader);
- an entity goes to the first vich loader whose mapping one of its fields uses.

```yaml
picasso:
    loaders:
        uploads: { type: filesystem, path: '%kernel.project_dir%/public/uploads' }
        assets: { type: filesystem, path: '%kernel.project_dir%/assets/images' }
        images:
            type: chain
            loaders: [uploads, assets]
```

```twig
<twig:Picasso:Image src="logo.png" loader="images" width="200" height="80" alt="Logo" />
```

Generated URLs name the loader that holds the image (`/image/glide/assets/logo.png…`), never the chain, so a chain adds no work to serving. Finding the loader asks each source whether it has the image, until one does: list the loaders holding most images first, and keep in mind that this is a network call for a Flysystem storage on a remote bucket. Entities are matched by mapping, without asking any storage.

A chain is how 1.x configurations keep their loader name, and so their templates, when moving to one loader per directory or mapping:

```yaml
# 1.x
picasso:
    loaders:
        filesystem:
            paths: ['%kernel.project_dir%/public/uploads', '%kernel.project_dir%/assets/images']
        vich: ~ # every VichUploader mapping

# 2.0
picasso:
    loaders:
        uploads: { type: filesystem, path: '%kernel.project_dir%/public/uploads' }
        assets: { type: filesystem, path: '%kernel.project_dir%/assets/images' }
        filesystem: { type: chain, loaders: [uploads, assets] }
        product_image: { type: vich }
        user_avatar: { type: vich }
        vich: { type: chain, loaders: [product_image, user_avatar] }
```

### Custom Loader

Create a custom loader by implementing `ImageLoaderInterface` and tagging it with `#[AsImageLoader]`:

```php
use Silarhi\PicassoBundle\Attribute\AsImageLoader;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;

#[AsImageLoader('s3')]
class S3Loader implements ImageLoaderInterface
{
    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        // Fetch from S3, return an Image DTO
    }
}
```

A loader that hands images over to other loaders, as a chain does, returns them with `Image::$loader` set to the name of the loader that loaded them: generated URLs then name that loader, which serves them.

If local transformers (like Glide) should serve your loader's images, implement `ServableLoaderInterface` instead. Its `getSource()` method returns the `ImageSourceInterface` all its originals are read from when a transformed image is requested: the loader name in the image URL is all that is needed to find the original again, so a servable loader reads from a single source. Two implementations ship with the bundle:

- `LocalImageSource` reads from a local directory (paths escaping it via `..` are treated as missing).
- `FlysystemImageSource` reads from a Flysystem storage.

Any other storage works by implementing the two methods of `ImageSourceInterface` yourself, without writing a Flysystem adapter:

```php
use Silarhi\PicassoBundle\Attribute\AsImageLoader;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Source\ImageSourceInterface;

final class BlobImageSource implements ImageSourceInterface
{
    public function __construct(private BlobRepository $blobs) {}

    public function exists(string $path): bool
    {
        return $this->blobs->has($path);
    }

    public function readStream(string $path)
    {
        return $this->blobs->openStream($path)
            ?? throw new ImageNotFoundException(sprintf('Blob "%s" not found.', $path));
    }
}

#[AsImageLoader('blob')]
final class BlobLoader implements ServableLoaderInterface
{
    public function __construct(private BlobImageSource $source) {}

    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        $path = $reference->path ?? '';

        return new Image(path: $path, stream: fn () => $this->source->readStream($path));
    }

    public function getSource(): ImageSourceInterface
    {
        return $this->source;
    }
}
```

## Transformers

Transformers generate URLs for on-demand image transformation.

### Glide (Local)

[Glide](https://glide.thephpleague.com/) processes images locally using GD, Imagick or libvips. Glide 2, 3 and 4 are supported.

```bash
composer require league/glide
```

```yaml
picasso:
    transformers:
        glide:
            sign_key: '%env(PICASSO_SIGN_KEY)%'
            cache: '%kernel.project_dir%/var/glide-cache'
            driver: gd # gd | imagick | vips
            max_image_size: ~ # optional: max pixel count (width x height)
            base_url: ~ # optional: e.g. https://img.example.com to point image URLs at a CDN
            defer_cache_write: false # store a cache miss after the response is sent
            lock:
                enabled: false # render a missing variant once (see "Rendering each variant once")
            public_cache:
                enabled: false # serve from public dir for better performance
                prefix: '' # optional: path prepended to cache keys (see "Serving thumbnails from a CDN")
```

> **Important:** When using Glide, you must [import the bundle routes](#routes) so that the image controller can serve transformed images.

Glide URLs are stable: the same image and transformation always produce the same URL and signature. A thumbnail used several times in a page, or across pages, is fetched once and caches cleanly in browsers and CDNs.

A cached variant costs two calls to the cache storage (its size, then its contents), and a conditional request (`If-Modified-Since`) one: the content type is read from the image's first bytes. That matters when the cache is an object store, where each call is an HTTP request. On such a storage, responses carry no `Last-Modified` header, which would cost one more call: signed URLs never change, and [`cache_control`](#error-responses) makes them cacheable for a year anyway.

#### Choosing a driver

| Driver    | Needs                                                                                                |
| --------- | ---------------------------------------------------------------------------------------------------- |
| `gd`      | The `gd` extension. The default.                                                                     |
| `imagick` | The `imagick` extension.                                                                             |
| `vips`    | `composer require intervention/image-driver-vips` (Glide 3 or later), libvips, and the FFI extension |

[libvips](https://www.libvips.org/) is the fastest driver on Linux: in the official Alpine image it renders a whole srcset two to three times faster than GD and Imagick, and AVIF about five times faster (see [Performance](#performance)). Glide hands every driver the whole source file, so libvips cannot shrink large sources while decoding them, and uses about as much memory as the others. The Intervention driver calls it through [FFI](https://www.php.net/manual/en/book.ffi.php):

- Install libvips: `apt install libvips42` (Debian/Ubuntu), `apk add vips vips-heif` (Alpine; `vips-heif` adds AVIF), `brew install vips` (macOS).
- Enable FFI for the web SAPI: the default `ffi.enable=preload` only allows it on the command line and in preloaded scripts, so set `ffi.enable=true` for PHP-FPM, FrankenPHP or Apache.

Composer picks the driver version matching your Glide version (1.x for Glide 3, 4.x for Glide 4). A missing package fails at container build and says what to install.

#### Rendering each variant once

Nothing stops several requests from rendering the same missing variant at the same time: a crawler fetching a page's images, a burst of visitors after a newsletter, or a CDN with many edge locations each asking the origin. Every one of them decodes the source and encodes the variant, so 16 concurrent requests cost 16 renders (and their memory).

With `lock`, the first request renders the variant while the others wait for it, then serve it from the cache:

```yaml
framework:
    lock: '%env(LOCK_DSN)%' # e.g. redis://redis:6379, or flock on a single server

picasso:
    transformers:
        glide:
            lock:
                enabled: true
                # factory: lock.factory # the LockFactory framework.lock configures, or your own service
                # ttl: 30 # seconds a lock outlives a renderer that crashed
                # wait: 10 # seconds a miss waits for a render in progress
```

- Install the component: `composer require symfony/lock`. Use a store shared by every server rendering images (Redis, a database...). `flock` only serializes the renders of one server.
- Hits take no lock: only a miss locks, keyed by the variant's cache path.
- With `defer_cache_write`, the lock is held until the variant is uploaded: before that, the waiting requests would not find it in the cache.
- If the renderer dies, its lock expires after `ttl` and a waiting request renders the variant itself. Keep `ttl` above your slowest render (plus its upload with `defer_cache_write`).
- A request waits at most `wait` seconds, then renders the variant itself, without the lock: a render held up (e.g. by an upload to a stalled storage) never keeps the others waiting until `max_execution_time`, a fatal error that also ends a long-running worker (FrankenPHP, RoadRunner). Keep `wait` well under `max_execution_time`, leaving room for the render itself.
- Waiting requests hold a PHP worker while they wait, idle instead of rendering: they cost a worker slot, not CPU or memory.

#### Storing the Glide cache on Flysystem

The `cache` option is polymorphic: pass a local path _or_ the name of a [Flysystem bundle](https://github.com/thephpleague/flysystem-bundle) storage. The bundle resolves the name at boot via its internal `FlysystemRegistry` (the same one used by the Vich loader) — if no storage matches, the value is treated as a path. This lets you keep sources and derivatives on different Flysystem instances:

```yaml
# config/packages/flysystem.yaml
flysystem:
    storages:
        sources.storage:
            adapter: 'aws'
            options:
                client: 'aws_s3_client'
                bucket: 'my-originals'
        thumbs.storage:
            adapter: 'aws'
            options:
                client: 'aws_s3_client'
                bucket: 'my-thumbs'
```

```yaml
# config/packages/picasso.yaml
picasso:
    loaders:
        flysystem:
            storage: 'sources.storage' # source images
    transformers:
        glide:
            sign_key: '%env(PICASSO_SIGN_KEY)%'
            cache: 'thumbs.storage' # rendered cache on a different Flysystem
            driver: gd
```

> **Note:** `public_cache: enabled: true` writes rendered files at a path your web server is expected to serve directly. Combined with a remote Flysystem cache, that only works if the underlying bucket is publicly served at the matching URL prefix (see [Serving thumbnails from a CDN](#serving-thumbnails-from-a-cdn)) — otherwise leave `public_cache` disabled and let the bundle's controller stream the cached file.

#### Serving thumbnails from a CDN

For large catalogs, keep the rendered variants in a bucket and put a CDN in front of it, so that a thumbnail that already exists never reaches PHP. The application only handles misses, and still renders them on the fly:

```
Browser ──► CDN ──► bucket (S3, R2, GCS…)       hit: served by the CDN/bucket, no PHP
                      │ 403/404
                      ▼
                    Symfony /image/…            miss: rendered, stored in the bucket, returned
```

```yaml
picasso:
    cache_control:
        error_max_age: 60 # let the CDN absorb repeated 404s for a minute
    transformers:
        glide:
            sign_key: '%env(PICASSO_SIGN_KEY)%'
            cache: 'thumbs.storage' # Flysystem storage of the bucket
            base_url: 'https://img.example.com' # the CDN host
            defer_cache_write: true # upload a miss after the response is sent
            public_cache:
                enabled: true
                prefix: 'image' # the URL path before the transformer name (/image/glide/…)
```

- **`base_url`** makes every generated image URL point at the CDN: `https://img.example.com/image/glide/…`.
- **`public_cache.prefix`** makes the cache key equal the URL path: the variant served at `/image/glide/flysystem/photo.jpg/fm_webp%2Cw_640.webp` is stored under the key `image/glide/flysystem/photo.jpg/fm_webp,w_640.webp`, which is exactly what the CDN looks up in the bucket. [URL aliases](#url-aliases) appear in both alike. Set it to what comes before the transformer name in the URL path: `image` with the default [routes](#routes), or e.g. `media/image` when they are imported with a `/media` prefix.
- **On a miss**, the application renders the variant, stores it in the bucket and returns it with `Cache-Control: public, max-age=31536000, immutable` (the `cache_control` defaults). The next request is a hit.
- **`defer_cache_write`** keeps the upload out of the client's wait: a miss is rendered to a local temporary directory, answered from there, and moved to the bucket on `kernel.terminate`, after the client has been released (`fastcgi_finish_request()` under PHP-FPM and FrankenPHP, after the request in FrankenPHP worker mode). The directory is only created by a miss and deleted once its renders are uploaded, so only the variants of requests in flight are on local disk. A failed upload is logged, not thrown: the next request renders the variant again. The upload still occupies the PHP worker until it completes, so size the worker pool for bursts of misses.
- **`cache_control.error_max_age`** makes the image controller's 404s cacheable (`Cache-Control: public, max-age=…`), so a CDN does not send every request for a missing image to the application. Without it, 404s stay uncacheable.

The signature is only checked on a miss: that is all it needs to protect, since it guards the rendering, and a variant that already exists is public anyway.

On the CDN side:

- Use the bucket as the origin, and fall back to the application on `403`/`404` (CloudFront origin groups, a Cloudflare Worker reading R2, Fastly, or a reverse proxy such as nginx with `proxy_intercept_errors` and `error_page 403 404 = @app`). S3 answers `403` for a missing key when the reader cannot list the bucket.
- Leave the query string (`s`) out of the cache key, but forward it to the application on a miss: it carries the signature.
- Give hits served from the bucket a long lifetime in the CDN's cache policy (or response headers policy): the bundle does not set `Cache-Control` on the objects it stores.

A variant URL never changes meaning, so its cache never needs revalidating. Changing the source file behind an unchanged path therefore needs a [purge](#cache-purge), which clears the bucket but not the CDN's edge caches. Uploads with unique file names (as VichUploaderBundle generates) never need either.

### Imgix (CDN)

[Imgix](https://imgix.com/) processes images via their CDN. No local processing is needed.

```yaml
picasso:
    transformers:
        imgix:
            base_url: 'https://my-source.imgix.net'
            sign_key: '%env(IMGIX_SIGN_KEY)%' # optional
            api_key: '%env(IMGIX_API_KEY)%' # optional, enables cache purge
```

### Custom Transformer

Create a custom transformer by implementing `ImageTransformerInterface`:

```php
use Silarhi\PicassoBundle\Attribute\AsImageTransformer;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;

#[AsImageTransformer('cloudinary')]
class CloudinaryTransformer implements ImageTransformerInterface
{
    public function url(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        // Build and return a Cloudinary URL
    }
}
```

Or register it via configuration:

```yaml
picasso:
    transformers:
        cloudinary:
            type: service
            service: 'App\Image\CloudinaryTransformer'
```

## Routes

The bundle registers a route for on-demand image transformation (used by Glide and other local transformers):

```text
GET /image/{transformer}/{loader}/{path}
```

Import the routes in your application:

```yaml
# config/routes/picasso.yaml
picasso:
    resource: '@PicassoBundle/config/routes.php'
```

> **Note:** Routes are only required when using a local transformer
> like Glide. CDN-based transformers (Imgix) generate external URLs
> and do not need this route.

### URL Aliases

By default, image URLs name the transformer and the loader: `/image/glide/product_image/photo.jpg`. Give either one a `url_alias` to replace its name in the URL with a shorter one, or with one that keeps your configuration names private:

```yaml
picasso:
    transformers:
        glide: { url_alias: g }
    loaders:
        product_image: { type: vich, url_alias: pi }
# → /image/g/pi/photo.jpg
```

Loaders and transformers registered with the attributes take it as `urlAlias`: `#[AsImageLoader('s3', urlAlias: 's')]`, `#[AsImageTransformer('cloudinary', urlAlias: 'c')]`.

- An alias only changes URLs. Templates, `default_loader`, `default_transformer` and purges keep using the names.
- URLs naming a loader or transformer by its name keep being served after it gets an alias, so adding one does not break the URLs already published.
- An alias may contain letters, digits, `_` and `-`. It must not be the alias or the name of another loader (or transformer, for a transformer alias). A loader and a transformer can share one, since they fill different URL segments. Conflicts fail at container build.
- With `public_cache`, cache keys use the aliases as well, so they keep mirroring the URL path. Setting or changing an alias moves the cache keys like renaming would: purge the variants first, or let the old ones be.

### Error Responses

The image controller answers a `404 Not Found` whenever an image cannot be served, and wraps the reason in a `NotFoundHttpException` whose previous exception tells the cases apart:

| Cause                                                                                   | Previous exception          |
| --------------------------------------------------------------------------------------- | --------------------------- |
| Source file missing, invalid signature, malformed public-cache path                     | `ImageNotFoundException`    |
| Source file exists but is not a decodable image (truncated upload, PDF named `.jpg`...) | `UndecodableImageException` |

This lets a `kernel.exception` listener react to one case only. For instance, an application redirecting unservable image URLs to the original file should do so for `ImageNotFoundException` only: for an `UndecodableImageException` the original _is_ the broken file.

```php
if ($throwable instanceof NotFoundHttpException
    && $throwable->getPrevious() instanceof ImageNotFoundException) {
    // Safe to redirect to the original file
}
```

The controller, not the transformer, owns the `Cache-Control` of what it serves, configured under `picasso.cache_control`:

- Served images (and their `304 Not Modified`) get `public, max-age=<max_age>` plus `immutable` when enabled; a transformer's `Expires` is dropped so it cannot contradict `max-age`. With `max_age: ~`, the transformer's own headers are kept. Redirects keep theirs.
- 404s are not cacheable by default. Set `error_max_age` to let clients and CDNs keep them for that many seconds (`Cache-Control: public, max-age=…`).

When two requests render the same variant at once and the cache storage rejects the second write (S3-compatible storages may answer `409 Conflict`), the request is still answered with the variant the first one cached, instead of an error.

### 1.x URLs

Image URLs published by 1.x (in CDN caches, emails, search engines, saved pages) keep working. A 1.x loader reading several directories or VichUploader mappings put the source of each image in an encrypted `_metadata` query param. Since 2.0, that source names the loader reading it, so such a URL is answered with a `301 Moved Permanently` to the same image and transformation under that loader, whatever its loader name has become (a [chain](#chain-loader), another loader, or none). Nothing needs configuring:

- The source is matched against the `path` of filesystem loaders and the upload destination of vich loaders, also when the project moved to another directory since (deployments into a new release directory).
- When no loader reads a 1.x source any more, its URLs answer `404`: declare a loader for it.
- Each redirect triggers a deprecation, so the logs tell when 1.x URLs stop being requested. 1.x URLs without `_metadata` were already 2.0 URLs.

## Cache Purge

Both built-in transformers support purging cached image variants via the `PurgableTransformerInterface`.

A purge throws a `PurgeException` when the cache can't be cleared: the Glide cache storage fails to delete the variants, or the Imgix API rejects the request. With Glide, purging an image that was never cached is a no-op.

### Glide

Glide cache is purged automatically — no extra configuration needed. In standard mode, `Server::deleteCache()` removes all cached variants. In public cache mode, the bundle deletes the cache directory for the specific transformer/loader/path combination (under `public_cache.prefix` when set). Purging does not reach a CDN in front of the cache: clear its edge caches separately.

### Imgix

Imgix purge requires an API key and a PSR-18 HTTP client:

```yaml
picasso:
    transformers:
        imgix:
            type: imgix
            base_url: 'https://my-source.imgix.net'
            api_key: '%env(IMGIX_API_KEY)%'
            # Optional: defaults to psr18.http_client for all three
            # http_client: 'psr18.http_client'
            # request_factory: 'psr18.http_client'
            # stream_factory: 'psr18.http_client'
```

### Programmatic Usage

Use the `ImagePipeline` service to purge from your code:

```php
use Silarhi\PicassoBundle\Service\ImagePipeline;

class ImageManager
{
    public function __construct(private ImagePipeline $pipeline) {}

    public function deleteImage(string $path): void
    {
        // Purge all cached variants
        $this->pipeline->purge($path);

        // Or specify loader/transformer explicitly
        $this->pipeline->purge($path, loader: 'filesystem', transformer: 'glide');
    }
}
```

You can also use the `PurgableTransformerInterface` directly:

```php
use Silarhi\PicassoBundle\Transformer\PurgableTransformerInterface;

if ($transformer instanceof PurgableTransformerInterface) {
    $transformer->purge($path, ['loader' => 'filesystem', 'transformer' => 'glide']);
}
```

## How It Works

When you use `<twig:Picasso:Image>`, the component:

1. **Loads** the image metadata via the configured loader (filesystem, Flysystem, Vich, URL)
2. **Detects dimensions** from the image stream (or uses explicitly provided values)
3. **Generates srcset** entries for each configured format at all responsive breakpoints
4. **Generates a placeholder** (if configured) — a tiny blurred image inlined as a CSS background
5. **Renders** a `<picture>` element with `<source>` tags per format and a fallback `<img>`

The generated HTML follows modern best practices:

- `<source>` elements for modern formats (AVIF, WebP) with automatic MIME type detection
- Full `srcset` with width descriptors for responsive loading
- `sizes` attribute for accurate viewport-based selection
- `loading="lazy"` by default for below-the-fold images
- Blur placeholder with CSS `background-image` and `onload` cleanup

## Performance

Numbers from `benchmarks/run.php` in the official `php:8.4-cli-alpine` image ([`benchmarks/Dockerfile`](#running-the-benchmarks): GD with its bundled libgd, Imagick 7.1 and libvips from Alpine, OPcache on), on an Apple M-series machine running Linux arm64 natively. Default bundle configuration (AVIF, WebP and JPEG, 16 widths), one PHP worker per core, league/glide 4.1. Sources are generated photo-like JPEGs: small (800×600), HD (1920×1080) and 4K (3840×2160). Expect other hardware to differ in scale, not in shape. The same benchmarks on macOS (PHP 8.5, Homebrew libraries) give the same picture for PHP-side work, but not for image encoding: see [the differences](#on-macos).

### Pages

| Operation                                        | Time   |
| ------------------------------------------------ | ------ |
| One URL (`picasso_image_url()`)                  | 6.0 µs |
| Image with fixed width and height (7 URLs)       | 35 µs  |
| Responsive image, `sizes` set (42 URLs)          | 170 µs |
| Responsive image + `resolveMetadata` (PSR-6 hit) | 166 µs |
| Twig page with 30 responsive images              | 5.5 ms |

Rendering never touches the cache storage: a crawler fetching HTML pages costs URL signing (plus one `stat()` per image with the filesystem loader).

### Cache hits

| Mode            | Cache storage             | Storage calls | 200                  | 304             |
| --------------- | ------------------------- | ------------- | -------------------- | --------------- |
| Glide cache     | local disk                | 2             | 116 µs (8,600 req/s) | 1 call, 95 µs   |
| Glide cache     | object store (10 ms/call) | 2             | 23.9 ms              | 1 call, 12.9 ms |
| public cache    | local disk                | 2             | 117 µs (8,561 req/s) | 1 call, 97 µs   |
| public cache    | object store (10 ms/call) | 2             | 25.4 ms              | 1 call, 13.1 ms |
| deferred writes | local disk                | 2             | 119 µs (8,420 req/s) | 1 call, 104 µs  |
| deferred writes | object store (10 ms/call) | 2             | 24.1 ms              | 1 call, 12.7 ms |

Before 2.0, a hit went through Glide's own response factory: 5 storage calls (6 with deferred writes), about twice the time on local disk and 60 ms (74 ms) on the object store, and a 304 still opened the variant. No memory or file descriptor growth over 5,000 hits in one worker.

### Junk URLs

| Request (all answered 404)                | Time   |
| ----------------------------------------- | ------ |
| Invalid signature                         | 681 µs |
| Valid signature, missing image            | 693 µs |
| Unknown loader                            | 457 µs |
| Tracking param appended (`&utm_source=x`) | 643 µs |

A 404 costs about six hits (Symfony's error handling, and an `error`-level log line per request): set [`cache_control.error_max_age`](#error-responses) so a CDN absorbs them. Any query param the signature does not cover, tracking params included, invalidates the URL.

### Cache misses

Render time and peak memory (RSS) of one variant:

| Source | Width | Format | gd             | imagick        | vips           |
| ------ | ----- | ------ | -------------- | -------------- | -------------- |
| small  | 640   | jpg    | 14 ms, 77 MB   | 11 ms, 77 MB   | 9 ms, 77 MB    |
| small  | 640   | webp   | 30 ms, 77 MB   | 32 ms, 77 MB   | 17 ms, 77 MB   |
| small  | 640   | avif   | 66 ms, 77 MB   | 48 ms, 77 MB   | 27 ms, 94 MB   |
| hd     | 640   | jpg    | 18 ms, 77 MB   | 22 ms, 77 MB   | 19 ms, 87 MB   |
| hd     | 640   | webp   | 30 ms, 77 MB   | 45 ms, 77 MB   | 22 ms, 88 MB   |
| hd     | 640   | avif   | 76 ms, 77 MB   | 61 ms, 81 MB   | 31 ms, 94 MB   |
| hd     | 1920  | jpg    | 31 ms, 77 MB   | 28 ms, 101 MB  | 22 ms, 86 MB   |
| hd     | 1920  | webp   | 135 ms, 77 MB  | 170 ms, 120 MB | 73 ms, 83 MB   |
| hd     | 1920  | avif   | 195 ms, 110 MB | 238 ms, 133 MB | 57 ms, 163 MB  |
| 4k     | 640   | jpg    | 59 ms, 81 MB   | 76 ms, 148 MB  | 31 ms, 101 MB  |
| 4k     | 640   | webp   | 68 ms, 80 MB   | 94 ms, 148 MB  | 33 ms, 98 MB   |
| 4k     | 640   | avif   | 103 ms, 84 MB  | 106 ms, 155 MB | 38 ms, 107 MB  |
| 4k     | 3840  | jpg    | 120 ms, 115 MB | 102 ms, 264 MB | 57 ms, 120 MB  |
| 4k     | 3840  | webp   | 523 ms, 146 MB | 677 ms, 338 MB | 286 ms, 129 MB |
| 4k     | 3840  | avif   | 847 ms, 278 MB | 853 ms, 341 MB | 150 ms, 386 MB |

About 77 MB of each figure is the PHP process itself (kernel, autoloader).

A crawler fetching every srcset URL of one image that was never rendered:

| Source | Variants | gd    | imagick | vips  | Cache written |
| ------ | -------- | ----- | ------- | ----- | ------------- |
| small  | 33       | 0.6 s | 0.5 s   | 0.3 s | 0.7 MB        |
| hd     | 42       | 1.7 s | 1.8 s   | 0.8 s | 2.3 MB        |
| 4k     | 48       | 5.9 s | 6.9 s   | 2.2 s | 7.7 MB        |

Sixteen concurrent requests for the same missing variant (4K source, WebP, 1920 wide, gd), each in its own process:

| [`lock`](#rendering-each-variant-once) | Renders | Responses | CPU (all processes) | Wall time |
| -------------------------------------- | ------- | --------- | ------------------- | --------- |
| off                                    | 16      | 16 × 200  | 6.6 s               | 0.61 s    |
| on                                     | 1       | 16 × 200  | 1.0 s               | 0.32 s    |

### Memory

RSS of one long-running worker stays flat over 60 cold renders (HD source, 640/1080/1920 wide) with every driver and format: at most +0.02 MB per render, after a 15-render warm-up.

`memory_limit` only bounds part of a render. GD built with the libgd bundled in PHP (as in the official Docker images) allocates its pixel buffers through PHP, so they count; the encoders (libjpeg, libwebp, libavif) and the Imagick and libvips drivers allocate outside PHP's memory manager, and do not count:

| Render (4K source, 3840 wide) | gd                             | imagick             | vips                |
| ----------------------------- | ------------------------------ | ------------------- | ------------------- |
| JPEG, `memory_limit=96M`      | fatal error (memory exhausted) | 264 MB RSS, renders | 124 MB RSS, renders |
| JPEG, `memory_limit=128M`     | 130 MB RSS, renders            | 280 MB RSS, renders | 134 MB RSS, renders |
| AVIF, `memory_limit=128M`     | 277 MB RSS, renders            | 341 MB RSS, renders | 385 MB RSS, renders |

So size the worker pool by RSS, not by `memory_limit`, and give GD a `memory_limit` that fits the largest source you accept (about 15 bytes per source pixel: a 4K source needs 128M), or set `max_image_size`.

### On macOS

With Homebrew's libraries (GD 2.3.3 linked to the system libgd, libavif, libvips 8.18), PHP-side numbers are within 10% of the Linux ones, but encoding differs:

- libvips encodes AVIF two to three times slower than GD and Imagick (1,167 ms for a 4K source to 3840 wide) instead of five times faster: Homebrew's AV1 encoder settings differ. A full crawl of a 4K image takes about 5 s with every driver.
- GD's AVIF encoder leaks about 2.5 MB of RSS per render, which adds up in long-running workers. It does not on Alpine.
- GD's pixel buffers do not count toward `memory_limit`, as GD uses the system libgd.

Benchmark the image your production runs: encoders are where platforms differ.

### Tuning for crawlers

- **Put a CDN in front of the cache** ([Serving thumbnails from a CDN](#serving-thumbnails-from-a-cdn)): hits then never reach PHP, and misses are the only cost left.
- **Enable [`lock`](#rendering-each-variant-once)** with a store shared by your servers: a crawler or a CDN asking for a missing variant from several places renders it once.
- **Consider the [`vips` driver](#choosing-a-driver)** on Linux: it renders a whole srcset two to three times faster than GD or Imagick, and AVIF five times faster.
- **Trim what a crawler can ask for.** Every format and width is one more variant per image: the default 3 formats × 16 widths make up to 48. Drop the widths your layout never uses (the 3840-wide variants are the slowest by far) and consider dropping WebP when you serve AVIF: browsers that do not support AVIF fall back to JPEG.
- **Set `max_image_size`**, so a huge upload cannot be rendered at full size.
- **Size workers by RSS, not `memory_limit`**: a 4K AVIF render takes 280 to 390 MB depending on the driver.
- **Set [`cache_control.error_max_age`](#error-responses)**, so repeated junk URLs are answered by the CDN.

### Running the benchmarks

```bash
XDEBUG_MODE=off php -d opcache.enable_cli=1 benchmarks/run.php            # every scenario, ~5 minutes
XDEBUG_MODE=off php -d opcache.enable_cli=1 benchmarks/run.php hit herd   # some of: render hit notfound miss crawl herd memory
```

Each scenario prints a Markdown table. Drivers are benchmarked when available (`imagick` extension; `intervention/image-driver-vips` with libvips and FFI). Generated images, caches and kernels go to `benchmarks/var/`, which can be deleted at any time.

To measure on Linux, as most production servers run, `benchmarks/Dockerfile` builds the official `php:8.4-cli-alpine` image with GD (bundled libgd, AVIF), Imagick and libvips (Alpine packages, AVIF included), FFI and OPcache:

```bash
docker build -f benchmarks/Dockerfile -t picasso-bench .
docker run --rm picasso-bench              # every scenario
docker run --rm picasso-bench miss memory  # some of them
```

Pass `--build-arg PHP_VERSION=8.2` to benchmark another PHP version, and `--cpus`/`--memory` to `docker run` to match your production limits.

## Testing & Quality

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
```

## Contributing

Contributions are welcome! Please make sure your changes pass all quality checks before submitting a pull request:

```bash
vendor/bin/phpunit && vendor/bin/phpstan analyse && vendor/bin/php-cs-fixer fix --dry-run --diff
```

## License

MIT License. See [LICENSE](LICENSE) for details.

---

<p align="center">
    Built with care by <a href="https://github.com/silarhi">SILARHI</a>.<br>
    If PicassoBundle saves you time, consider giving it a star on GitHub.
</p>
