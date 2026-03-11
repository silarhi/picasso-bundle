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
<Picasso:Image src="hero.jpg" width="1920" height="1080" sizes="100vw" alt="Hero" />
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
    - [Custom Loader](#custom-loader)
- [Transformers](#transformers)
    - [Glide (Local)](#glide-local)
    - [Imgix (CDN)](#imgix-cdn)
    - [Custom Transformer](#custom-transformer)
- [Routes](#routes)
- [How It Works](#how-it-works)
- [Testing & Quality](#testing--quality)
- [Contributing](#contributing)
- [License](#license)

---

## Features

- **One component, full optimization** — `<Picasso:Image>` renders a complete `<picture>` with AVIF, WebP, and JPEG sources
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

| Package                                   | Required for                               |
| ----------------------------------------- | ------------------------------------------ |
| `league/glide` + `league/glide-symfony`   | Glide transformer (local image processing) |
| `kornrunner/blurhash` + `imagine/imagine` | BlurHash placeholder                       |
| `league/flysystem-bundle`                 | Flysystem loader                           |
| `vich/uploader-bundle`                    | VichUploader loader                        |
| `symfony/http-client`                     | URL loader                                 |

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
composer require league/glide league/glide-symfony

# Option B: Imgix (CDN-based transformation)
# No extra package needed, just configure your Imgix base URL
```

## Quick Start

**1. Configure** a loader and a transformer:

```yaml
# config/packages/picasso.yaml
picasso:
    loaders:
        filesystem:
            paths:
                - '%kernel.project_dir%/public/uploads'
    transformers:
        glide:
            sign_key: '%env(PICASSO_SIGN_KEY)%'
```

**2. Import the routes** (required for Glide local serving):

```yaml
# config/routes/picasso.yaml
picasso:
    resource: '@PicassoBundle/config/routes.php'
```

**3. Use the Twig component** in your templates:

```twig
<Picasso:Image
    src="photo.jpg"
    width="800"
    height="600"
    sizes="(max-width: 768px) 100vw, 800px"
    alt="A beautiful landscape"
/>
```

This renders a `<picture>` element with `<source>` tags for AVIF and
WebP, a fallback `<img>` with JPEG srcset, and an inline blur
placeholder — all automatically.

## Configuration

### Minimal Configuration

When only one loader and one transformer are configured, they are
automatically used as defaults — no need to set `default_loader` or
`default_transformer`.

```yaml
picasso:
    loaders:
        filesystem:
            paths:
                - '%kernel.project_dir%/public/uploads'
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

    # --- Metadata cache ---
    cache: true # true = cache.app, false = disabled, or a PSR-6 service ID

    # --- Placeholders ---
    placeholders:
        blur:
            type: transformer # inferred from key name when matching a known type
            size: 10 # tiny image width/height in px
            blur: 5 # blur radius
            quality: 30 # JPEG quality for blur image (1–100)

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
            type: filesystem # inferred from key name
            paths:
                - '%kernel.project_dir%/public/uploads'

        # my_flysystem:
        #     type: flysystem
        #     storage: 'default.storage'

        # vich:
        #     type: vich

        # url:
        #     type: url
        #     http_client: ~       # optional: custom PSR-18 HTTP client service ID
        #     request_factory: ~   # optional: custom PSR-17 request factory service ID

    # --- Transformers ---
    transformers:
        glide:
            type: glide # inferred from key name
            sign_key: ~ # signing key for secure URLs
            cache: '%kernel.project_dir%/var/glide-cache'
            driver: gd # gd | imagick
            max_image_size: ~ # optional max pixel count
            public_cache:
                enabled: false # serve transformed images from public directory


        # imgix:
        #     type: imgix
        #     base_url: ~      # e.g. https://my-source.imgix.net
        #     sign_key: ~      # optional signing key

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

The `<Picasso:Image>` component renders a responsive `<picture>` element
with `<source>` tags for each configured format and a fallback `<img>`
with a full srcset.

```twig
<Picasso:Image
    src="photo.jpg"
    width="800"
    height="600"
    sizes="(max-width: 768px) 100vw, 800px"
    alt="A beautiful landscape"
/>
```

#### Component Properties

| Property          | Type           | Default | Description                                             |
| ----------------- | -------------- | ------- | ------------------------------------------------------- |
| `src`             | `string`       | —       | Image path relative to the loader's base                |
| `width`           | `int`          | auto    | Display width (auto-detected from source)               |
| `height`          | `int`          | auto    | Display height (auto-detected from source)              |
| `sizes`           | `string`       | —       | Responsive `sizes` attribute                            |
| `sourceWidth`     | `int`          | auto    | Explicit source width (skips detection)                 |
| `sourceHeight`    | `int`          | auto    | Explicit source height (skips detection)                |
| `loader`          | `string`       | —       | Override default loader                                 |
| `transformer`     | `string`       | —       | Override default transformer                            |
| `quality`         | `int`          | 75      | Override quality (1–100)                                |
| `fit`             | `string`       | contain | Fit mode: `contain`, `cover`, `crop`, `fill`            |
| `placeholder`     | `string\|bool` | —       | `true`/`false` to enable/disable, or a placeholder name |
| `placeholderData` | `string`       | —       | Literal data URI, bypasses placeholder services         |
| `priority`        | `bool`         | false   | Eager loading, `fetchpriority="high"`, no placeholder   |
| `loading`         | `string`       | lazy    | `lazy` or `eager`. Auto-set when priority               |
| `fetchPriority`   | `string`       | —       | `high`, `low`, `auto`. Auto-set when priority           |
| `unoptimized`     | `bool`         | false   | Serve original image without transformation             |
| `context`         | `array`        | `[]`    | Extra context for the loader (e.g. Vich)                |

#### Automatic Dimension Detection

When `width` and `height` are not provided, PicassoBundle automatically
detects them from the image stream. You can also provide `sourceWidth`
and `sourceHeight` to skip detection entirely, which is useful for
performance when you already know the image dimensions:

```twig
{# Auto-detected dimensions #}
<Picasso:Image src="photo.jpg" sizes="100vw" alt="Photo" />

{# Explicit source dimensions (skips stream detection) #}
<Picasso:Image src="photo.jpg" :sourceWidth="4000" :sourceHeight="3000" width="800" height="600" alt="Photo" />
```

The component also preserves aspect ratio when only one display dimension is provided:

```twig
{# height is calculated automatically from the source aspect ratio #}
<Picasso:Image src="photo.jpg" width="800" sizes="100vw" alt="Photo" />
```

#### Extra HTML Attributes

The component forwards any extra attributes to the inner `<img>` tag:

```twig
<Picasso:Image
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
<Picasso:Image src="/images/logo.svg" :unoptimized="true" alt="Logo" />
```

### Twig Function

The `picasso_image_url()` function generates a single transformed image URL.
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
    loader: 'vich',
    transformer: 'imgix',
    context: { entity: product, field: 'imageFile' }
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
rendering data (sources, srcset, placeholder, dimensions, loading attributes).
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
    "attributes": {}
}
```

`imageData()` accepts the same parameters as the `<Picasso:Image>` Twig component (`src`, `width`, `height`, `sizes`, `quality`, `fit`, `placeholder`, `priority`, `loader`, `transformer`, etc.).

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
<Picasso:Image src="photo.jpg" width="800" height="600" sizes="100vw" alt="Photo" />

{# Disable placeholder for this image #}
<Picasso:Image src="icon.png" width="64" height="64" :placeholder="false" />

{# Select a specific named placeholder #}
<Picasso:Image src="hero.jpg" width="1200" height="800" placeholder="blurhash" />

{# Pass a literal data URI directly (bypasses all placeholder services) #}
<Picasso:Image src="photo.jpg" width="800" height="600" placeholderData="data:image/png;base64,..." />
```

## Priority Images

For above-the-fold images (hero banners, LCP images), use the `priority` prop.
This sets `loading="eager"`, `fetchpriority="high"`, and disables the blur
placeholder for optimal Largest Contentful Paint (LCP) performance:

```twig
<Picasso:Image
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

Reads images from local directories. Supports multiple paths (searched in order).

```yaml
picasso:
    loaders:
        filesystem:
            paths:
                - '%kernel.project_dir%/public/uploads'
                - '%kernel.project_dir%/assets/images'
```

```twig
<Picasso:Image src="photos/landscape.jpg" width="800" height="600" alt="Landscape" />
```

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
<Picasso:Image src="photo.jpg" loader="my_s3" width="800" height="600" alt="S3 image" />
```

### VichUploaderBundle Loader

Loads images managed by [VichUploaderBundle](https://github.com/dustin10/VichUploaderBundle).

```bash
composer require vich/uploader-bundle
```

```yaml
picasso:
    loaders:
        vich: ~ # type inferred from key name
```

```twig
<Picasso:Image
    src="product-photo.jpg"
    :context="{ entity: product, field: 'imageFile' }"
    width="400"
    height="300"
    alt="Product image"
/>
```

The `context` must include the `entity` (the Doctrine entity instance) and `field` (the VichUploader mapping field name).

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
<Picasso:Image
    src="https://example.com/remote-image.jpg"
    loader="url"
    width="800"
    height="600"
    alt="Remote image"
/>
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

If your loader provides direct filesystem access for local transformers (like Glide), implement `ServableLoaderInterface` instead.

## Transformers

Transformers generate URLs for on-demand image transformation.

### Glide (Local)

[Glide](https://glide.thephpleague.com/) processes images locally using GD or Imagick.

```bash
composer require league/glide league/glide-symfony
```

```yaml
picasso:
    transformers:
        glide:
            sign_key: '%env(PICASSO_SIGN_KEY)%'
            cache: '%kernel.project_dir%/var/glide-cache'
            driver: gd # gd | imagick
            max_image_size: ~ # optional: max pixel count (width x height)
            public_cache:
                enabled: false # serve from public dir for better performance
```

> **Important:** When using Glide, you must [import the bundle routes](#routes) so that the image controller can serve transformed images.

### Imgix (CDN)

[Imgix](https://imgix.com/) processes images via their CDN. No local processing is needed.

```yaml
picasso:
    transformers:
        imgix:
            base_url: 'https://my-source.imgix.net'
            sign_key: '%env(IMGIX_SIGN_KEY)%' # optional
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

## How It Works

When you use `<Picasso:Image>`, the component:

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
