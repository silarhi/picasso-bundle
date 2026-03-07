<p align="center">
    <img src="https://img.shields.io/packagist/v/silarhi/picasso-bundle?style=flat-square&label=stable" alt="Latest Stable Version">
    <img src="https://img.shields.io/packagist/dt/silarhi/picasso-bundle?style=flat-square" alt="Total Downloads">
    <img src="https://img.shields.io/packagist/l/silarhi/picasso-bundle?style=flat-square" alt="License">
    <img src="https://img.shields.io/packagist/php-v/silarhi/picasso-bundle?style=flat-square" alt="PHP Version">
    <img src="https://img.shields.io/github/actions/workflow/status/silarhi/picasso-bundle/continuous-integration.yml?style=flat-square&label=CI" alt="CI Status">
</p>

# PicassoBundle

Responsive image component for Symfony, inspired by [Next.js Image](https://nextjs.org/docs/app/api-reference/components/image).

PicassoBundle automatically generates optimized, responsive `<picture>` elements with multiple formats (AVIF, WebP, JPEG),
srcset generation, and blur placeholders — all from a single Twig component.

---

## Table of Contents

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
- [License](#license)

---

## Features

- **Responsive `<picture>` output** with automatic srcset and multiple format generation
- **Pluggable placeholders** — blur (LQIP), blurhash, or custom placeholder services for smooth loading transitions
- **Pluggable loaders** — Filesystem,
  [Flysystem](https://flysystem.thephpleague.com/),
  [VichUploaderBundle](https://github.com/dustin10/VichUploaderBundle), URL
- **Pluggable transformers** — [Glide](https://glide.thephpleague.com/) (local), [Imgix](https://imgix.com/) (CDN), or custom service
- **Twig component** (`<Picasso:Image>`) and **Twig function** (`picasso_image_url()`)
- **Automatic image dimension detection** from streams
- **URL signing** for secure on-demand transformation
- **Priority images** — eager loading + `fetchpriority="high"` for above-the-fold content
- **Metadata caching** — PSR-6 cache support for image dimensions and BlurHash data
- **Extensible** via `#[AsImageLoader]`, `#[AsImageTransformer]`, and `#[AsPlaceholder]` attributes

## Requirements

| Dependency | Version |
|---|---|
| PHP | 8.2+ |
| Symfony | 6.4 / 7.0 / 8.0 |
| [Symfony UX Twig Component](https://symfony.com/bundles/ux-twig-component/current/index.html) | 2.13+ |

### Optional Dependencies

| Package | Required for |
|---|---|
| `league/glide` + `league/glide-symfony` | Glide transformer (local image processing) |
| `kornrunner/blurhash` + `imagine/imagine` | BlurHash placeholder |
| `league/flysystem-bundle` | Flysystem loader |
| `vich/uploader-bundle` | VichUploader loader |
| `symfony/http-client` | URL loader |

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

This renders a `<picture>` element with `<source>` tags for AVIF and WebP, a fallback `<img>` with JPEG srcset, and an inline blur placeholder — all automatically.

## Configuration

### Minimal Configuration

When only one loader and one transformer are configured, they are automatically used as defaults — no need to set `default_loader` or `default_transformer`.

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
    default_quality: 75        # 1–100
    default_fit: contain       # contain | cover | crop | fill

    # --- Metadata cache ---
    cache: true                # true = cache.app, false = disabled, or a PSR-6 service ID

    # --- Placeholders ---
    placeholders:
        blur:
            type: transformer  # inferred from key name when matching a known type
            size: 10           # tiny image width/height in px
            blur: 5            # blur radius
            quality: 30        # JPEG quality for blur image (1–100)

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
            type: filesystem   # inferred from key name
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
            type: glide        # inferred from key name
            sign_key: ~        # signing key for secure URLs
            cache: '%kernel.project_dir%/var/glide-cache'
            driver: gd         # gd | imagick
            max_image_size: ~  # optional max pixel count
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

- **`device_sizes`** — Breakpoint widths for responsive (fluid) images. When the component has a `sizes` attribute, all device and image sizes are merged and included in the srcset.
- **`image_sizes`** — Smaller widths for fixed-size images (icons, thumbnails). When no `sizes` attribute is provided, srcset includes only `1x` and `2x` descriptors based on the specified `width`.

#### `formats`

The list of output formats. A `<source>` element is generated for each format except the last one, which is used as the `<img>` fallback. The default `[avif, webp, jpg]` produces:

```html
<picture>
    <source type="image/avif" srcset="..." />
    <source type="image/webp" srcset="..." />
    <img src="..." srcset="..." />  <!-- jpg fallback -->
</picture>
```

Supported formats: `avif`, `webp`, `jpg`, `jpeg`, `pjpg`, `png`, `gif`.

#### `default_fit`

Controls how images are resized within the target dimensions:

| Fit | Description |
|---|---|
| `contain` | Scales down to fit within the box, preserving aspect ratio (default) |
| `cover` | Scales to fill the box, cropping excess |
| `crop` | Crops to exact dimensions |
| `fill` | Stretches to fill the box exactly |

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

| Property | Type | Default | Description |
|---|---|---|---|
| `src` | `string` | — | Image path relative to the loader's base |
| `width` | `int` | auto | Display width (auto-detected from source) |
| `height` | `int` | auto | Display height (auto-detected from source) |
| `sizes` | `string` | — | Responsive `sizes` attribute |
| `sourceWidth` | `int` | auto | Explicit source width (skips detection) |
| `sourceHeight` | `int` | auto | Explicit source height (skips detection) |
| `loader` | `string` | — | Override default loader |
| `transformer` | `string` | — | Override default transformer |
| `quality` | `int` | 75 | Override quality (1–100) |
| `fit` | `string` | contain | Fit mode: `contain`, `cover`, `crop`, `fill` |
| `placeholder` | `string\|bool` | — | `true`/`false` to enable/disable, or a placeholder name |
| `placeholderData` | `string` | — | Literal data URI, bypasses placeholder services |
| `priority` | `bool` | false | Eager loading, `fetchpriority="high"`, no placeholder |
| `loading` | `string` | lazy | `lazy` or `eager`. Auto-set when priority |
| `fetchPriority` | `string` | — | `high`, `low`, `auto`. Auto-set when priority |
| `unoptimized` | `bool` | false | Serve original image without transformation |
| `context` | `array` | `[]` | Extra context for the loader (e.g. Vich) |

#### Automatic Dimension Detection

When `width` and `height` are not provided, PicassoBundle automatically detects them from the image stream. You can also provide `sourceWidth` and `sourceHeight` to skip detection entirely, which is useful for performance when you already know the image dimensions:

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

| Parameter | Type | Description |
|---|---|---|
| `width` | `int` | Target width in pixels |
| `height` | `int` | Target height in pixels |
| `format` | `string` | Output format (`avif`, `webp`, `jpg`, etc.) |
| `quality` | `int` | Output quality (1–100) |
| `fit` | `string` | Fit mode (`contain`, `cover`, `crop`, `fill`) |
| `blur` | `int` | Blur radius |
| `dpr` | `int` | Device pixel ratio |
| `loader` | `string` | Override default loader |
| `transformer` | `string` | Override default transformer |
| `context` | `array` | Extra context for the loader |

### ImageHelper Service

The `picasso_image_url()` Twig function delegates to `ImageHelper`, which you can also inject directly in your PHP code:

```php
use Silarhi\PicassoBundle\Service\ImageHelper;

class MyController
{
    public function __construct(private ImageHelper $imageHelper) {}

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
            size: 10       # tiny image width/height in px
            blur: 5        # blur radius
            quality: 30    # JPEG quality (1–100)
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
            components_x: 4    # horizontal components (1–9, higher = more detail)
            components_y: 3    # vertical components (1–9, higher = more detail)
            size: 32           # decoded placeholder image size in px
            driver: gd         # gd | imagick
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

> **Note:** Placeholders are automatically disabled when `priority` is `true`, since priority images should load immediately without showing a placeholder first.

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
            storage: 'default.storage'  # your Flysystem service ID
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
        vich: ~   # type inferred from key name
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
        url: ~   # type inferred from key name
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
            driver: gd              # gd | imagick
            max_image_size: ~       # optional: max pixel count (width x height)
            public_cache:
                enabled: false      # serve from public dir for better performance
```

> **Important:** When using Glide, you must [import the bundle routes](#routes) so that the image controller can serve transformed images.

### Imgix (CDN)

[Imgix](https://imgix.com/) processes images via their CDN. No local processing is needed.

```yaml
picasso:
    transformers:
        imgix:
            base_url: 'https://my-source.imgix.net'
            sign_key: '%env(IMGIX_SIGN_KEY)%'  # optional
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

```
GET /image/{transformer}/{loader}/{path}
```

Import the routes in your application:

```yaml
# config/routes/picasso.yaml
picasso:
    resource: '@PicassoBundle/config/routes.php'
```

> **Note:** Routes are only required when using a local transformer like Glide. CDN-based transformers (Imgix) generate external URLs and do not need this route.

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

## License

MIT License. See [LICENSE](LICENSE) for details.
