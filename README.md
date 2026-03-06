# PicassoBundle

Responsive image component for Symfony, inspired by [Next.js Image](https://nextjs.org/docs/app/api-reference/components/image).

PicassoBundle automatically generates optimized, responsive `<picture>` elements with multiple formats (AVIF, WebP, JPEG),
srcset generation, and blur placeholders — all from a single Twig component.

## Features

- **Responsive `<picture>` output** with automatic srcset and multiple format generation
- **Blur placeholders** (LQIP) for smooth loading transitions
- **Pluggable loaders** — Filesystem, [Flysystem](https://flysystem.thephpleague.com/), [VichUploaderBundle](https://github.com/dustin10/VichUploaderBundle)
- **Pluggable transformers** — [Glide](https://glide.thephpleague.com/) (local) or [Imgix](https://imgix.com/) (CDN)
- **Twig component** (`<Picasso:Image>`) and **Twig function** (`picasso_image_url()`)
- **Automatic image dimension detection** from streams
- **URL signing** for secure on-demand transformation
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
# No extra package needed, just configure your Imgix domain
```

## Configuration

The bundle works out of the box with sensible defaults. A minimal setup with Glide:

```yaml
# config/packages/picasso.yaml
picasso:
    transformers:
        glide:
            enabled: true
            sign_key: '%env(PICASSO_SIGN_KEY)%'
```

That's it — images are loaded from `public/uploads` by default and transformed locally via Glide.

<details>
<summary>Full configuration reference</summary>

```yaml
picasso:
    default_loader: filesystem
    default_transformer: ~ # auto-detected when only one is enabled

    device_sizes: [640, 750, 828, 1080, 1200, 1920, 2048, 3840]
    image_sizes: [16, 32, 48, 64, 96, 128, 256, 384]
    formats: [avif, webp, jpg] # last entry is the <img> fallback
    default_quality: 75 # 1–100
    default_fit: contain # contain | cover | crop | fill

    placeholders:
        blur:
            enabled: true
            size: 10
            blur: 50
            quality: 30

    loaders:
        filesystem:
            enabled: true
            base_directory: '%kernel.project_dir%/public/uploads'
        flysystem:
            enabled: false
            service: ~ # Flysystem storage service ID
        vich:
            enabled: false

    transformers:
        glide:
            enabled: false
            sign_key: ~
            cache: '%kernel.project_dir%/var/glide-cache'
            driver: gd # gd | imagick
            max_image_size: ~
        imgix:
            enabled: false
            domain: ~ # your-source.imgix.net
            sign_key: ~
            use_https: true
```

</details>

## Usage

### Twig Component (recommended)

```twig
<Picasso:Image
    src="photo.jpg"
    width="800"
    height="600"
    sizes="(max-width: 768px) 100vw, 800px"
    alt="A beautiful landscape"
/>
```

This renders a `<picture>` element with `<source>` tags for each configured format and a responsive `srcset` on the fallback `<img>`.

### Component Properties

| Property       | Type     | Description                                                 |
| -------------- | -------- | ----------------------------------------------------------- |
| `src`          | `string` | Image path relative to the loader's base                    |
| `width`        | `int`    | Display width in pixels                                     |
| `height`       | `int`    | Display height in pixels                                    |
| `sizes`        | `string` | Responsive `sizes` attribute                                |
| `sourceWidth`  | `int`    | Explicit source width (skips detection)                     |
| `sourceHeight` | `int`    | Explicit source height (skips detection)                    |
| `loader`       | `string` | Override default loader (`filesystem`, `vich`, `flysystem`) |
| `transformer`  | `string` | Override default transformer (`glide`, `imgix`)             |
| `quality`      | `int`    | Override quality (1–100)                                    |
| `fit`          | `string` | Fit mode: `contain`, `cover`, `crop`, `fill`                |
| `placeholder`  | `bool`   | Enable/disable blur placeholder                             |
| `unoptimized`  | `bool`   | Serve the original image without transformation             |
| `context`      | `array`  | Extra context for the loader (e.g. Vich entity/field)       |

### Twig Function

For generating a single image URL:

```twig
<img src="{{ picasso_image_url('photo.jpg', {width: 300, format: 'webp'}) }}" alt="Thumbnail">
```

With explicit loader/transformer:

```twig
{{ picasso_image_url('photo.jpg', {width: 300, loader: 'vich', transformer: 'imgix'}) }}
```

Available parameters: `width`, `height`, `format`, `quality`, `fit`, `blur`, `dpr`, `loader`, `transformer`.

### VichUploaderBundle Integration

```yaml
# config/packages/picasso.yaml
picasso:
    loaders:
        vich:
            enabled: true
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

### Flysystem Integration

```yaml
# config/packages/picasso.yaml
picasso:
    loaders:
        flysystem:
            enabled: true
            service: 'default.storage' # Your Flysystem service ID
```

## Custom Loaders and Transformers

Register custom implementations using PHP attributes:

```php
use Silarhi\PicassoBundle\Attribute\AsImageLoader;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;

#[AsImageLoader('s3')]
class S3Loader implements ImageLoaderInterface
{
    // ...
}
```

```php
use Silarhi\PicassoBundle\Attribute\AsImageTransformer;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;

#[AsImageTransformer('cloudinary')]
class CloudinaryTransformer implements ImageTransformerInterface
{
    // ...
}
```

Then use them by name:

```twig
<Picasso:Image src="photo.jpg" loader="s3" transformer="cloudinary" width="800" />
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
```

## License

MIT License. See [LICENSE](LICENSE) for details.
