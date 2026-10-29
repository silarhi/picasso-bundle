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
| Symfony                                                                                       | 6.4, 7.x or 8.x |

## Optional Dependencies

| Package                                   | Required for                                                                 |
| ----------------------------------------- | ---------------------------------------------------------------------------- |
| `symfony/ux-twig-component` 2.13+         | The [`<twig:Picasso:Image>` component](/reference/component/)         |
| `symfony/twig-bundle`                     | The [`picasso_image()` and `picasso_image_url()` Twig functions](/reference/twig-functions/) |
| `league/glide`                            | Glide transformer (local image processing)                                   |
| `intervention/image-driver-vips`          | Glide `vips` driver ([libvips](/transformers/glide/#choosing-a-driver))                          |
| `symfony/lock`                            | Glide `lock` option ([one render per variant](/transformers/glide/#rendering-each-variant-once)) |
| `symfony/web-link`                        | [Preloading priority images](/guides/priority-images/#preloading)          |
| `kornrunner/blurhash` + `imagine/imagine` | BlurHash placeholder                                                         |
| `league/flysystem-bundle`                 | Flysystem loader                                                             |
| `vich/uploader-bundle`                    | VichUploader loader                                                          |
| `symfony/asset-mapper`                    | [AssetMapper loader](/sources/asset-mapper/)                                 |
| `symfony/http-client` + `psr/http-client` + `nyholm/psr7` | URL loader, Imgix cache purge ([PSR-18 client](/sources/remote-urls/))   |

## Installation

```bash
composer require silarhi/picasso-bundle

# the <twig:Picasso:Image> component
composer require symfony/ux-twig-component
```

Twig is optional: without it, the bundle still generates and serves images, from PHP through `ImageHelperInterface`
([Images in a JSON API](/guides/json-api/)).

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
