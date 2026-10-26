---
title: 'AssetMapper'
description: 'Serve the images of your assets directory, by the logical path you give to asset().'
sidebar:
    order: 4
---

Reads the images [AssetMapper](https://symfony.com/doc/current/frontend/asset_mapper.html) maps, by their logical path: the same path you would give to `asset()`. Images keep living next to your styles and scripts, in `assets/` or any other AssetMapper path, and no directory is repeated in the Picasso configuration.

```bash
composer require symfony/asset-mapper
```

```yaml
picasso:
    loaders:
        asset_mapper: ~ # the type is inferred from the name
```

```twig
{# assets/images/hero.jpg #}
<twig:Picasso:Image src="images/hero.jpg" loader="asset_mapper" sizes="100vw" alt="Hero" />
```

- Glide reads the original file (its `sourcePath`), not the copy `asset-map:compile` writes to `public/assets/`, so the source files must be deployed, as they are for AssetMapper itself.
- Only mapped assets are found: paths AssetMapper excludes (`excluded_patterns`) and paths with a `..` segment are missing images.
- Like the [filesystem](/sources/filesystem/) loader, it reads image dimensions by default (`resolve_metadata: true`): it is a local read.
- It can be a member of a [chain](/sources/chain/), e.g. to look in your uploads first, then in your assets.
