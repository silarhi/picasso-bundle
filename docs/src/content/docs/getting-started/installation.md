---
title: 'Installation'
description: 'Requirements, optional packages and installation of PicassoBundle.'
sidebar:
    order: 1
---

## Requirements

| Dependency                                                                                    | Version         |
| --------------------------------------------------------------------------------------------- | --------------- |
| PHP                                                                                           | 8.2+            |
| Symfony                                                                                       | 6.4 / 7.0 / 8.0 |
| [Symfony UX Twig Component](https://symfony.com/bundles/ux-twig-component/current/index.html) | 2.13+           |

## Optional Dependencies

| Package                                   | Required for                                                                 |
| ----------------------------------------- | ---------------------------------------------------------------------------- |
| `league/glide`                            | Glide transformer (local image processing)                                   |
| `intervention/image-driver-vips`          | Glide `vips` driver ([libvips](/transformers/glide/#choosing-a-driver))                          |
| `symfony/lock`                            | Glide `lock` option ([one render per variant](/transformers/glide/#rendering-each-variant-once)) |
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

:::tip[Upgrading from 1.x]
2.0 contains breaking changes: follow the [upgrade guide](/upgrade/from-1x/).
:::
