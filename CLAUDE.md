# CLAUDE.md

This file provides guidance for Claude Code when working on the PicassoBundle project.

## Project Overview

PicassoBundle is a Symfony bundle that provides responsive image components, inspired by Next.js Image. It generates optimized `<picture>` elements with multiple formats, srcset, and blur placeholders.

## Tech Stack

- **Language**: PHP 8.2+
- **Framework**: Symfony 6.4 / 7.0 / 8.0
- **Key dependency**: Symfony UX Twig Component 2.13+
- **Optional**: League Glide (local transforms), Imgix (CDN transforms), Flysystem, VichUploaderBundle

## Repository Structure

```
src/
├── Attribute/          # AsImageLoader, AsImageTransformer, AsPlaceholder attributes
├── Controller/         # ImageController (serves transformed images)
├── Dto/                # Image, ImageReference, ImageRenderData, ImageSource, ImageTransformation, SrcsetEntry
├── Exception/          # Domain exceptions (PicassoExceptionInterface and implementations)
├── Loader/             # FilesystemLoader, FlysystemLoader, FlysystemRegistry, UrlLoader,
│                       #   VichUploaderLoader, VichMappingHelper + interfaces
│                       #   (ImageLoaderInterface, ServableLoaderInterface, VichMappingHelperInterface)
├── Placeholder/        # TransformerPlaceholder, BlurHashPlaceholder + PlaceholderInterface
├── Service/            # CacheKeyGenerator, ImageHelper, ImageHelperInterface, ImagePipeline,
│                       #   LoaderRegistry, TransformerRegistry, PlaceholderRegistry,
│                       #   SrcsetGenerator, MetadataGuesser, MetadataGuesserInterface, UrlEncryption
├── Transformer/        # GlideTransformer, ImgixTransformer + interfaces
│                       #   (ImageTransformerInterface, LocalTransformerInterface)
├── Twig/
│   ├── Component/      # ImageComponent (Picasso:Image Twig component)
│   └── Extension/      # PicassoExtension (picasso_image_url Twig function)
└── PicassoBundle.php   # Bundle class with configuration tree and service wiring
config/
└── routes.php          # Bundle routes
templates/
└── components/
    └── Image.html.twig # Twig template for the Image component
tests/                  # PHPUnit tests mirroring src/ structure
```

## Build & Test Commands

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

## CI/CD

- Uses **Laminas CI Matrix Action** (`.github/workflows/continuous-integration.yml`)
- Configured extensions: `gd`, `pcov`
- Ignores PHP platform requirements for PHP 8.4+ (future versions)
- Runs on: `ubuntu-latest`

## Architecture Notes

- **Loaders** fetch image data from a source (filesystem, Flysystem, URL, Vich). They implement `ImageLoaderInterface` and are registered via the `#[AsImageLoader('name')]` attribute or the `picasso.loader` service tag.
  - `ServableLoaderInterface` extends `ImageLoaderInterface` for loaders that can provide direct filesystem access (used by local transformers like Glide).
  - `UrlLoader` loads images from remote URLs via Symfony HttpClient.
  - `FlysystemRegistry` manages multiple named Flysystem storage instances.
- **Transformers** generate URLs for on-demand image transformation (Glide locally, Imgix via CDN). They implement `ImageTransformerInterface` and are registered via the `#[AsImageTransformer('name')]` attribute or the `picasso.transformer` service tag.
  - `LocalTransformerInterface` extends `ImageTransformerInterface` for transformers that serve images locally (e.g., Glide) and need a loader to access source files.
- **Placeholders** generate placeholder data URIs or URLs for images (e.g., blurred thumbnails). They implement `PlaceholderInterface` and are registered via the `#[AsPlaceholder('name')]` attribute or the `picasso.placeholder` service tag.
  - `TransformerPlaceholder` reuses the configured transformer to generate a tiny blurred image URL.
  - `BlurHashPlaceholder` encodes the image as a BlurHash string and decodes it to a tiny PNG data URI (requires `kornrunner/blurhash`).
- **Registries** (`LoaderRegistry`, `TransformerRegistry`, `PlaceholderRegistry`) use Symfony service locators for lazy-loading.
- **ImagePipeline** orchestrates loader + transformer for the Twig function.
- **ImageHelper** provides a convenience API for generating single image URLs with named parameters.
- **ImageComponent** is the main Twig component (`<Picasso:Image>`) that generates `<picture>` with `<source>` elements.
- **SrcsetGenerator** builds responsive srcset strings across configured widths and formats.
- All bundle configuration and service wiring lives in `PicassoBundle.php` (uses `AbstractBundle`).

## Domain Exceptions

All bundle exceptions implement `PicassoExceptionInterface` (extends `Throwable`), allowing consumers to catch any bundle-level error with a single type. Always throw domain-specific exceptions rather than generic PHP exceptions (`LogicException`, `RuntimeException`, etc.).

| Exception                      | Extends                    | When to use                                               |
|-------------------------------|---------------------------|-----------------------------------------------------------|
| `LoaderNotFoundException`      | `InvalidArgumentException` | Requested loader name is unknown or missing from context  |
| `TransformerNotFoundException` | `InvalidArgumentException` | Requested transformer name is unknown or missing from context |
| `ImageNotFoundException`       | `RuntimeException`         | Source image could not be found or signature is invalid    |
| `EncryptionException`          | `RuntimeException`         | URL encryption/decryption failure                         |
| `InvalidMetadataException`     | `LogicException`           | Image metadata is malformed or invalid                    |
| `InvalidConfigurationException`| `LogicException`           | Invalid bundle configuration (missing type, missing package) |
| `ImageProcessingException`     | `RuntimeException`         | Image processing failure (stream read errors, encoding)   |
| `PlaceholderNotFoundException` | `InvalidArgumentException` | Requested placeholder name is unknown or missing from context |

## Coding Conventions

- Strict types everywhere: `declare(strict_types=1)` in all PHP files
- PSR-4 autoloading under `Silarhi\PicassoBundle\`
- Code style enforced by PHP-CS-Fixer (`.php-cs-fixer.dist.php`) — uses `@Symfony` and `@Symfony:risky` rulesets
- Static analysis enforced by PHPStan (`phpstan.neon`) at **level max**
- Twig style enforced by Twig-CS-Fixer (`.twig-cs-fixer.php`)
- Code modernization managed by Rector (`rector.php`) — targets PHP 8.2+, includes deadCode, codeQuality, and typeDeclarations rulesets

### PHPStan Custom Types

The project uses PHPStan custom type aliases to avoid duplicating complex type annotations across classes. When a structured type is used in multiple files, define it once and import it.

**Global type aliases** (defined in `phpstan.neon` via `typeAliases`):

| Alias              | Type                          | Used in                                   |
|-------------------|-------------------------------|-------------------------------------------|
| `TransformerParams` | `array<string, int\|string>` | `GlideTransformer`, `ImgixTransformer`    |

**Local type aliases** (defined with `@phpstan-type` on an interface, imported with `@phpstan-import-type` in implementations):

| Alias                  | Type                                                               | Defined on                    | Imported in           |
|-----------------------|-------------------------------------------------------------------|-------------------------------|-----------------------|
| `ImageGuessedMetadata` | `array{width: int\|null, height: int\|null, mimeType: string\|null}` | `MetadataGuesserInterface`    | `MetadataGuesser`     |
| `ImageDimensions`      | `array{0: int, 1: int}`                                          | `VichMappingHelperInterface`  | `VichMappingHelper`   |
| `TransformerContext`   | `array<string, mixed>`                                            | `ImageTransformerInterface`   | `GlideTransformer`, `ImgixTransformer`, `PlaceholderInterface`, `TransformerPlaceholder`, `BlurHashPlaceholder`, `SrcsetGenerator` |

**Guidelines for adding new custom types:**
- Use `@phpstan-type` on the canonical interface when the type is part of a contract (interface + implementations).
- Use `@phpstan-import-type from InterfaceName` in implementing classes to reference the type.
- Use `typeAliases` in `phpstan.neon` when the type is shared between unrelated classes (no common interface).
- Only extract a type alias when the same structured type (array shapes, complex unions) appears in 2+ files. Simple generic types like `array<string, mixed>` do not need aliases.

## Common Patterns

- **Adding a new loader**: Create a class implementing `ImageLoaderInterface` (or `ServableLoaderInterface` if it provides filesystem access), add `#[AsImageLoader('name')]`, and it auto-registers.
- **Adding a new transformer**: Create a class implementing `ImageTransformerInterface` (or `LocalTransformerInterface` for local serving), add `#[AsImageTransformer('name')]`, and it auto-registers.
- **Adding a new placeholder**: Create a class implementing `PlaceholderInterface`, add `#[AsPlaceholder('name')]`, and it auto-registers. Alternatively, configure via `type: service` in the `placeholders` config.
- **Bundle configuration**: All config options are defined in `PicassoBundle::configure()` and wired in `PicassoBundle::loadExtension()`.
