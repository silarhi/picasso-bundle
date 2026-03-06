# PicassoBundle

Responsive image component for Symfony, inspired by [Next.js Image](https://nextjs.org/docs/app/api-reference/components/image).

PicassoBundle automatically generates optimized, responsive `<picture>` elements with multiple formats (AVIF, WebP, JPEG),
srcset generation, and blur placeholders — all from a single Twig component.

## Features

- **Responsive `<picture>` output** with automatic srcset and multiple format generation
- **Blur placeholders** (LQIP) for smooth loading transitions
- **Pluggable loaders** — Filesystem, [Flysystem](https://flysystem.thephpleague.com/), [VichUploaderBundle](https://github.com/dustin10/VichUploaderBundle), URL
- **Pluggable transformers** — [Glide](https://glide.thephpleague.com/) (local), [Imgix](https://imgix.com/) (CDN), or custom service
- **Twig component** (`<Picasso:Image>`) and **Twig function** (`picasso_image_url()`)
- **Automatic image dimension detection** from streams
- **URL signing** for secure on-demand transformation
- **Priority images** — eager loading + `fetchpriority="high"` for above-the-fold content
- **Extensible** via `#[AsImageLoader]` and `#[AsImageTransformer]` attributes

## Requirements

- PHP 8.2+
- Symfony 6.4+ or 7.0+
- [Symfony UX Twig Component](https://symfony.com/bundles/ux-twig-component/current/index.html) 2.13+

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

## Configuration

The bundle uses named loaders and transformers. A minimal setup with a filesystem loader and Glide:

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

When only one loader and one transformer are configured, they are automatically used as defaults — no need to set `default_loader` or `default_transformer`.

<details>
<summary>Full configuration reference</summary>

```yaml
picasso:
    default_loader: ~          # auto-detected when only one is enabled
    default_transformer: ~     # auto-detected when only one is enabled

    device_sizes: [640, 750, 828, 1080, 1200, 1920, 2048, 3840]
    image_sizes: [16, 32, 48, 64, 96, 128, 256, 384]
    formats: [avif, webp, jpg] # last entry is the <img> fallback format
    default_quality: 75        # 1–100
    default_fit: contain       # contain | cover | crop | fill

    placeholders:
        blur:
            enabled: true      # enable blur placeholders globally
            size: 10           # tiny image width/height in px
            blur: 5            # blur radius
            quality: 30        # JPEG quality for blur image (1–100)

    loaders:
        # Filesystem loader — reads images from local directories
        filesystem:
            type: filesystem   # inferred from the key name
            paths:
                - '%kernel.project_dir%/public/uploads'

        # Flysystem loader — reads images via a Flysystem storage
        my_flysystem:
            type: flysystem
            storage: 'default.storage'  # Flysystem service ID (required)

        # Vich loader — reads images managed by VichUploaderBundle
        vich:
            type: vich         # inferred from the key name

        # URL loader — loads remote images by URL
        url:
            type: url          # inferred from the key name
            http_client: ~     # optional: custom HTTP client service ID

    transformers:
        # Glide transformer — local image processing
        glide:
            type: glide        # inferred from the key name
            sign_key: ~        # signing key for secure URLs
            cache: '%kernel.project_dir%/var/glide-cache'
            driver: gd         # gd | imagick
            max_image_size: ~  # optional max pixel count

        # Imgix transformer — CDN-based image processing
        imgix:
            type: imgix        # inferred from the key name
            base_url: ~        # e.g. https://my-source.imgix.net
            sign_key: ~        # optional signing key

        # Custom transformer — delegate to your own service
        my_transformer:
            type: service
            service: 'App\Image\MyTransformer'  # must implement ImageTransformerInterface
```

</details>

> **Note:** The loader/transformer `type` is automatically inferred from the key name when it matches a known type (`filesystem`, `flysystem`, `vich`, `url`, `glide`, `imgix`). Use `type` explicitly when your key name differs from the type.

## Usage

### Twig Component (recommended)

The `<Picasso:Image>` component renders a responsive `<picture>` element with `<source>` tags for each configured format and a fallback `<img>` with a full srcset.

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

| Property       | Type     | Default | Description                                                              |
| -------------- | -------- | ------- | ------------------------------------------------------------------------ |
| `src`          | `string` | —       | Image path relative to the loader's base (or a URL for URL loaders)      |
| `width`        | `int`    | auto    | Display width in pixels (auto-detected from source if omitted)           |
| `height`       | `int`    | auto    | Display height in pixels (auto-detected from source if omitted)          |
| `sizes`        | `string` | —       | Responsive `sizes` attribute                                             |
| `sourceWidth`  | `int`    | auto    | Explicit source image width (skips metadata detection)                   |
| `sourceHeight` | `int`    | auto    | Explicit source image height (skips metadata detection)                  |
| `loader`       | `string` | —       | Override the default loader (e.g. `filesystem`, `vich`, `flysystem`)     |
| `transformer`  | `string` | —       | Override the default transformer (e.g. `glide`, `imgix`)                 |
| `quality`      | `int`    | 75      | Override quality (1–100)                                                 |
| `fit`          | `string` | contain | Fit mode: `contain`, `cover`, `crop`, `fill`                             |
| `placeholder`  | `bool`   | —       | Override blur placeholder for this image (overrides global config)        |
| `priority`     | `bool`   | false   | Mark as high-priority: eager loading, `fetchpriority="high"`, no blur    |
| `loading`      | `string` | lazy    | Loading attribute (`lazy` or `eager`). Auto-set to `eager` when priority |
| `fetchPriority`| `string` | —       | Fetch priority (`high`, `low`, `auto`). Auto-set to `high` when priority |
| `unoptimized`  | `bool`   | false   | Serve the original image without any transformation                      |
| `context`      | `array`  | `[]`    | Extra context for the loader (e.g. Vich entity/field)                    |

#### Blur Placeholder

When enabled (default), the component generates a tiny blurred version of the image and inlines it as a CSS `background-image` on the `<img>` tag. Once the full image loads, the blur background is removed via an `onload` handler, creating a smooth transition.

You can control blur globally via configuration or per-image via the `placeholder` prop:

```twig
{# Disable blur for this specific image #}
<Picasso:Image src="icon.png" width="64" height="64" :placeholder="false" />

{# Enable blur even if globally disabled #}
<Picasso:Image src="hero.jpg" width="1200" height="800" :placeholder="true" />
```

> **Note:** Blur is automatically disabled when `priority` is `true`, since priority images should load eagerly without placeholders.

#### Priority Images

For above-the-fold images (hero banners, LCP images), use the `priority` prop. This sets `loading="eager"`, `fetchpriority="high"`, and disables the blur placeholder:

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

#### Extra HTML Attributes

The component forwards any extra attributes to the inner `<img>` tag:

```twig
<Picasso:Image
    src="photo.jpg"
    width="400"
    height="300"
    class="rounded shadow-lg"
    id="main-photo"
    alt="Photo"
/>
```

### Twig Function

The `picasso_image_url()` function generates a single transformed image URL. Useful for backgrounds, meta tags, or anywhere you need a plain URL.

```twig
<img src="{{ picasso_image_url('photo.jpg', width: 300, format: 'webp') }}" alt="Thumbnail">
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

| Parameter     | Type     | Description                                  |
| ------------- | -------- | -------------------------------------------- |
| `width`       | `int`    | Target width in pixels                       |
| `height`      | `int`    | Target height in pixels                      |
| `format`      | `string` | Output format (`avif`, `webp`, `jpg`, etc.)  |
| `quality`     | `int`    | Output quality (1–100)                       |
| `fit`         | `string` | Fit mode (`contain`, `cover`, `crop`, `fill`)|
| `blur`        | `int`    | Blur radius                                  |
| `dpr`         | `int`    | Device pixel ratio                           |
| `loader`      | `string` | Override default loader                      |
| `transformer` | `string` | Override default transformer                 |
| `context`     | `array`  | Extra context for the loader                 |

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

## Loader & Transformer Examples

### VichUploaderBundle

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

### Flysystem

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

### URL Loader

Load and display remote images with automatic dimension detection:

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

### Imgix (CDN)

```yaml
picasso:
    transformers:
        imgix:
            base_url: 'https://my-source.imgix.net'
            sign_key: '%env(IMGIX_SIGN_KEY)%'  # optional
```

### Custom Transformer via Service

```yaml
picasso:
    transformers:
        cloudinary:
            type: service
            service: 'App\Image\CloudinaryTransformer'
```

## Custom Loaders and Transformers

Register custom implementations using PHP attributes — they are auto-discovered by the bundle:

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
        // ...
    }
}
```

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
        // ...
    }
}
```

Then use them by name:

```twig
<Picasso:Image src="photo.jpg" loader="s3" transformer="cloudinary" width="800" alt="Photo" />
```

## Routes

The bundle registers a route for on-demand image transformation (used by Glide):

```
GET /image/{transformer}/{loader}/{path}
```

Import the routes in your application:

```yaml
# config/routes/picasso.yaml
picasso:
    resource: '@PicassoBundle/config/routes.php'
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

## Static Analysis & Code Style

```bash
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/twig-cs-fixer lint
vendor/bin/rector process --dry-run
```

## License

MIT License. See [LICENSE](LICENSE) for details.
