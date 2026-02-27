# CLAUDE.md

This file provides guidance for Claude Code when working on the PicassoBundle project.

## Project Overview

PicassoBundle is a Symfony bundle that provides responsive image components, inspired by Next.js Image. It generates optimized `<picture>` elements with multiple formats, srcset, and blur placeholders.

## Tech Stack

- **Language**: PHP 8.2+
- **Framework**: Symfony 6.4 / 7.0
- **Key dependency**: Symfony UX Twig Component 2.13+
- **Optional**: League Glide (local transforms), Imgix (CDN transforms), Flysystem, VichUploaderBundle

## Repository Structure

```
src/
├── Attribute/          # AsImageLoader, AsImageTransformer attributes
├── Controller/         # ImageController (serves transformed images)
├── Dto/                # Image, ImageReference, ImageSource, ImageTransformation, SrcsetEntry
├── Loader/             # FilesystemLoader, FlysystemLoader, VichUploaderLoader + interfaces
├── Service/            # ImagePipeline, LoaderRegistry, TransformerRegistry, SrcsetGenerator, MetadataGuesser, UrlEncryption
├── Transformer/        # GlideTransformer, ImgixTransformer + interfaces
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

# Static analysis
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

## Architecture Notes

- **Loaders** fetch image data from a source (filesystem, Flysystem, Vich). They implement `ImageLoaderInterface` and are registered via the `#[AsImageLoader('name')]` attribute or the `picasso.loader` service tag.
- **Transformers** generate URLs for on-demand image transformation (Glide locally, Imgix via CDN). They implement `ImageTransformerInterface` and are registered via the `#[AsImageTransformer('name')]` attribute or the `picasso.transformer` service tag.
- **Registries** (`LoaderRegistry`, `TransformerRegistry`) use Symfony service locators for lazy-loading.
- **ImagePipeline** orchestrates loader + transformer for the Twig function.
- **ImageComponent** is the main Twig component (`<Picasso:Image>`) that generates `<picture>` with `<source>` elements.
- **SrcsetGenerator** builds responsive srcset strings across configured widths and formats.
- All bundle configuration and service wiring lives in `PicassoBundle.php` (uses `AbstractBundle`).

## Coding Conventions

- Strict types everywhere: `declare(strict_types=1)` in all PHP files
- PSR-4 autoloading under `Silarhi\PicassoBundle\`
- Code style enforced by PHP-CS-Fixer (`.php-cs-fixer.dist.php`)
- Static analysis enforced by PHPStan (`phpstan.neon`)
- Twig style enforced by Twig-CS-Fixer (`.twig-cs-fixer.php`)
- Code modernization managed by Rector (`rector.php`)

## Common Patterns

- **Adding a new loader**: Create a class implementing `ImageLoaderInterface`, add `#[AsImageLoader('name')]`, and it auto-registers.
- **Adding a new transformer**: Create a class implementing `ImageTransformerInterface`, add `#[AsImageTransformer('name')]`, and it auto-registers.
- **Bundle configuration**: All config options are defined in `PicassoBundle::configure()` and wired in `PicassoBundle::loadExtension()`.
