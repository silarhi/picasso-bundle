# PicassoBundle

Responsive image component for Symfony, inspired by [Next.js Image](https://nextjs.org/docs/app/api-reference/components/image).

PicassoBundle automatically generates optimized, responsive `<picture>` elements with multiple formats (AVIF, WebP, JPEG),
srcset generation, and blur placeholders — all from a single Twig component.

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
- **Extensible** via `#[AsImageLoader]`, `#[AsImageTransformer]`, and `#[AsPlaceholder]` attributes

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
    default_placeholder: ~     # auto-detected when only one is enabled

    device_sizes: [640, 750, 828, 1080, 1200, 1920, 2048, 3840]
    image_sizes: [16, 32, 48, 64, 96, 128, 256, 384]
    formats: [avif, webp, jpg] # last entry is the <img> fallback format
    default_quality: 75        # 1–100
    default_fit: contain       # contain | cover | crop | fill

    placeholders:
        # Transformer placeholder — generates a tiny blurred image via the transformer
        blur:
            type: transformer  # inferred from the key name
            size: 10           # tiny image width/height in px
            blur: 5            # blur radius
            quality: 30        # JPEG quality for blur image (1–100)

        # BlurHash placeholder — generates a BlurHash-based placeholder (requires kornrunner/blurhash)
        # blurhash:
        #     type: blurhash      # inferred from the key name
        #     components_x: 4    # horizontal components (1–9)
        #     components_y: 3    # vertical components (1–9)
        #     size: 32           # decoded placeholder image size in px

        # Custom placeholder — delegate to your own service
        # my_placeholder:
        #     type: service
        #     service: 'App\Image\MyPlaceholder'  # must implement PlaceholderInterface

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

> **Note:** The loader/transformer `type` is automatically inferred from the key name
> when it matches a known type (`filesystem`, `flysystem`, `vich`, `url`, `glide`, `imgix`).
> Use `type` explicitly when your key name differs from the type.

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

| Property        | Type     | Default | Description                                         |
| --------------- | -------- | ------- | --------------------------------------------------- |
| `src`           | `string` | —       | Image path relative to the loader's base            |
| `width`         | `int`    | auto    | Display width (auto-detected from source)           |
| `height`        | `int`    | auto    | Display height (auto-detected from source)          |
| `sizes`         | `string` | —       | Responsive `sizes` attribute                        |
| `sourceWidth`   | `int`    | auto    | Explicit source width (skips detection)             |
| `sourceHeight`  | `int`    | auto    | Explicit source height (skips detection)            |
| `loader`        | `string` | —       | Override default loader                             |
| `transformer`   | `string` | —       | Override default transformer                        |
| `quality`       | `int`    | 75      | Override quality (1–100)                            |
| `fit`           | `string` | contain | Fit mode: `contain`, `cover`, `crop`, `fill`        |
| `placeholder`   | `string\|bool` | —  | `true`/`false` to enable/disable, or a placeholder name |
| `placeholderData` | `string` | —     | Literal data URI, bypasses placeholder services     |
| `priority`      | `bool`   | false   | Eager loading, `fetchpriority="high"`, no placeholder |
| `loading`       | `string` | lazy    | `lazy` or `eager`. Auto-set when priority           |
| `fetchPriority` | `string` | —       | `high`, `low`, `auto`. Auto-set when priority       |
| `unoptimized`   | `bool`   | false   | Serve original image without transformation         |
| `context`       | `array`  | `[]`    | Extra context for the loader (e.g. Vich)            |

#### Placeholders

Placeholders generate a low-quality preview displayed while the full image loads.
The built-in `transformer` placeholder creates a tiny blurred version of the image
and inlines it as a CSS `background-image`. Once the full image loads, the placeholder
is removed via an `onload` handler.

Configure placeholders in your bundle config, then control them per-image via props:

```twig
{# Uses the default placeholder from config #}
<Picasso:Image src="photo.jpg" width="800" height="600" sizes="100vw" alt="Photo" />

{# Disable placeholder for this image #}
<Picasso:Image src="icon.png" width="64" height="64" :placeholder="false" />

{# Select a specific named placeholder #}
<Picasso:Image src="hero.jpg" width="1200" height="800" placeholder="my_blurhash" />

{# Pass a literal data URI directly #}
<Picasso:Image src="photo.jpg" width="800" height="600" placeholderData="data:image/png;base64,..." />
```

> **Note:** Placeholders are automatically disabled when `priority` is `true`, since priority images should load eagerly without placeholders.

#### Priority Images

For above-the-fold images (hero banners, LCP images), use the `priority` prop.
This sets `loading="eager"`, `fetchpriority="high"`, and disables the blur
placeholder:

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

The `picasso_image_url()` function generates a single transformed image URL.
Useful for backgrounds, meta tags, or anywhere you need a plain URL.

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

## Custom Loaders, Transformers, and Placeholders

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

```php
use Silarhi\PicassoBundle\Attribute\AsPlaceholder;
use Silarhi\PicassoBundle\Placeholder\PlaceholderInterface;
use Silarhi\PicassoBundle\Dto\Image;

#[AsPlaceholder('blurhash')]
class BlurHashPlaceholder implements PlaceholderInterface
{
    public function generate(Image $image, int $width, int $height, array $context = []): string
    {
        // Generate and return a data URI (e.g. blurhash, thumbhash, etc.)
        return 'data:image/png;base64,...';
    }
}
```

Then use them by name:

```twig
<Picasso:Image src="photo.jpg" loader="s3" transformer="cloudinary" width="800" alt="Photo" />
<Picasso:Image src="photo.jpg" placeholder="blurhash" width="800" alt="Photo with blurhash" />
```

Placeholders can also be configured via the bundle config with `type: service`:

```yaml
picasso:
    default_placeholder: blurhash
    placeholders:
        blurhash:
            type: service
            service: 'App\Image\BlurHashPlaceholder'
```

## Routes

The bundle registers a route for on-demand image transformation (used by Glide):

```text
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
