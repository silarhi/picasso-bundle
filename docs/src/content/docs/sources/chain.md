---
title: 'Chain'
description: 'One loader name for images spread over several directories, storages or mappings.'
sidebar:
    order: 5
---

Groups several filesystem, flysystem or vich loaders under one name, for images spread over several directories, storages or VichUploader mappings. Each image is rendered with the first loader of the chain holding it:

- an image path goes to the first loader whose directory or storage has it (or to the first loader when none does: its URL then 404s, as with any loader); a storage that is unavailable counts as not having it, so pages still render during its outage;
- an entity goes to the first vich loader whose mapping one of its fields uses.

```yaml
picasso:
    loaders:
        uploads: { type: filesystem, path: '%kernel.project_dir%/public/uploads' }
        assets: { type: filesystem, path: '%kernel.project_dir%/assets/images' }
        images:
            type: chain
            loaders: [uploads, assets]
```

```twig
<twig:Picasso:Image src="logo.png" loader="images" width="200" height="80" alt="Logo" />
```

Generated URLs name the loader that holds the image (`/image/glide/assets/logo.png…`), never the chain, so a chain adds no work to serving. Finding the loader asks each source whether it has the image, until one does: list the loaders holding most images first, and keep in mind that this is a network call for a Flysystem storage on a remote bucket. Entities are matched by mapping, without asking any storage.

A chain is how 1.x configurations keep their loader name, and so their templates, when moving to one loader per directory or mapping:

```yaml
# 1.x
picasso:
    loaders:
        filesystem:
            paths: ['%kernel.project_dir%/public/uploads', '%kernel.project_dir%/assets/images']
        vich: ~ # every VichUploader mapping

# 2.0
picasso:
    loaders:
        uploads: { type: filesystem, path: '%kernel.project_dir%/public/uploads' }
        assets: { type: filesystem, path: '%kernel.project_dir%/assets/images' }
        filesystem: { type: chain, loaders: [uploads, assets] }
        product_image: { type: vich }
        user_avatar: { type: vich }
        vich: { type: chain, loaders: [product_image, user_avatar] }
```
