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

<p align="center">
    📖 <a href="https://picasso-bundle.silarhi.dev"><strong>Documentation</strong></a>
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

|                         | Without PicassoBundle                 | With PicassoBundle                                |
| ----------------------- | ------------------------------------- | ------------------------------------------------- |
| **Format negotiation**  | Manual AVIF/WebP/JPEG `<source>` tags | Automatic from config                             |
| **Responsive srcset**   | Hand-crafted per breakpoint           | Generated from `sizes` prop                       |
| **Blur placeholders**   | DIY or skip it                        | Built-in (LQIP, BlurHash, or custom)              |
| **Dimension detection** | Hardcoded or forgotten                | Auto-detected from image stream                   |
| **LCP optimization**    | Manually set loading/fetchpriority    | One `priority` prop                               |
| **Image sources**       | Filesystem only                       | Filesystem, S3, Flysystem, Vich, URL, AssetMapper |
| **CDN support**         | Build your own integration            | Imgix out of the box, or plug in any CDN          |

---

## Features

- **One component, full optimization** — `<twig:Picasso:Image>` renders a complete `<picture>` with AVIF, WebP, and JPEG sources
- **Automatic responsive srcset** — generates width descriptors for all configured breakpoints, no manual work
- **Blur placeholders** — built-in LQIP and [BlurHash](https://blurha.sh/) support for instant perceived loading
- **Smart dimension detection** — reads image dimensions from the stream automatically, preserves aspect ratio
- **Priority images** — one prop for `loading="eager"` + `fetchpriority="high"` (LCP optimization)
- **Multiple image sources** — Local filesystem, [Flysystem](https://flysystem.thephpleague.com/) (S3, GCS, Azure),
  [VichUploaderBundle](https://github.com/dustin10/VichUploaderBundle), remote URLs, AssetMapper assets
- **Local or CDN transforms** —
  [Glide](https://glide.thephpleague.com/) for self-hosted,
  [Imgix](https://imgix.com/) for CDN, or bring your own
- **Signed URLs** — HMAC-signed transformation URLs prevent abuse
- **Private images** — serve thumbnails from your own routes, behind your security voters, with the same srcset and formats
- **PSR-6 metadata caching** — dimension detection and BlurHash results cached
- **Fully extensible** — add custom loaders, transformers, or placeholders
  with PHP attributes (`#[AsImageLoader]`, `#[AsImageTransformer]`,
  `#[AsPlaceholder]`)

## Requirements

- PHP 8.2+
- Symfony 6.4, 7.x or 8.x
- [Symfony UX Twig Component](https://symfony.com/bundles/ux-twig-component/current/index.html) 2.13+

Glide, BlurHash, Flysystem, VichUploaderBundle, AssetMapper and remote URLs each need an optional package: see
[Installation](https://picasso-bundle.silarhi.dev/getting-started/installation/).

## Installation

```bash
composer require silarhi/picasso-bundle

# and a transformer: Glide renders the images locally (Imgix needs no extra package)
composer require league/glide
```

Upgrading from 1.x? Follow [UPGRADE-2.0.md](UPGRADE-2.0.md), or the [upgrade guide](https://picasso-bundle.silarhi.dev/upgrade/from-1x/).

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
`placeholders` entry, images render without one (see [Placeholders](https://picasso-bundle.silarhi.dev/guides/placeholders/)).

## Documentation

Everything else is on **[picasso-bundle.silarhi.dev](https://picasso-bundle.silarhi.dev)**:

- **Guides**: [responsive images](https://picasso-bundle.silarhi.dev/guides/responsive-images/), [fit modes](https://picasso-bundle.silarhi.dev/guides/fit-modes/), [placeholders](https://picasso-bundle.silarhi.dev/guides/placeholders/), [priority images](https://picasso-bundle.silarhi.dev/guides/priority-images/), [private images](https://picasso-bundle.silarhi.dev/guides/private-images/), [cache purge](https://picasso-bundle.silarhi.dev/guides/cache-purge/), [JSON API](https://picasso-bundle.silarhi.dev/guides/json-api/)
- **Image sources**: [filesystem](https://picasso-bundle.silarhi.dev/sources/filesystem/), [Flysystem](https://picasso-bundle.silarhi.dev/sources/flysystem/), [VichUploaderBundle](https://picasso-bundle.silarhi.dev/sources/vichuploader/), [AssetMapper](https://picasso-bundle.silarhi.dev/sources/asset-mapper/), [remote URLs](https://picasso-bundle.silarhi.dev/sources/remote-urls/), [chain](https://picasso-bundle.silarhi.dev/sources/chain/), [custom](https://picasso-bundle.silarhi.dev/sources/custom/)
- **Transformers**: [Glide](https://picasso-bundle.silarhi.dev/transformers/glide/), [Imgix](https://picasso-bundle.silarhi.dev/transformers/imgix/), [serving from a CDN](https://picasso-bundle.silarhi.dev/transformers/cdn/), [custom](https://picasso-bundle.silarhi.dev/transformers/custom/)
- **Reference**: [configuration](https://picasso-bundle.silarhi.dev/reference/configuration/), [component](https://picasso-bundle.silarhi.dev/reference/component/), [Twig functions](https://picasso-bundle.silarhi.dev/reference/twig-functions/), [routes](https://picasso-bundle.silarhi.dev/reference/routes/)
- **Concepts**: [how it works](https://picasso-bundle.silarhi.dev/concepts/how-it-works/), [performance](https://picasso-bundle.silarhi.dev/concepts/performance/)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Release notes are in [CHANGELOG.md](CHANGELOG.md).

## License

MIT License. See [LICENSE](LICENSE) for details.

---

<p align="center">
    Built with care by <a href="https://github.com/silarhi">SILARHI</a>.<br>
    If PicassoBundle saves you time, consider giving it a star on GitHub.
</p>
