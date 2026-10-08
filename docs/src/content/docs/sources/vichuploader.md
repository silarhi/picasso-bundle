---
title: 'VichUploaderBundle'
description: 'Render the images uploaded with VichUploaderBundle from the entity.'
sidebar:
    order: 3
---

Loads images managed by [VichUploaderBundle](https://github.com/dustin10/VichUploaderBundle).

```bash
composer require vich/uploader-bundle
```

Both VichUploaderBundle `2.9+` and `3.0+` are supported. VichUploader v3 requires PHP `8.3+`, so Composer resolves to v2 on PHP 8.2.

A vich loader serves **one VichUploader mapping**. Name the loader after the mapping, and that is all the configuration it needs:

```yaml
vich_uploader:
    mappings:
        product_image:
            upload_destination: '%kernel.project_dir%/public/uploads/products'
            uri_prefix: /uploads/products
        user_avatar:
            upload_destination: avatars.storage # a Flysystem storage works too
            uri_prefix: /uploads/avatars

picasso:
    loaders:
        product_image: { type: vich } # serves the "product_image" mapping
        avatars: { type: vich, mapping: user_avatar } # or pick the mapping explicitly
```

When VichUploader has a single mapping, `vich: ~` is enough: the loader serves that mapping.

Pass the entity as `context`. The upload field is found from the loader's mapping:

```twig
<twig:Picasso:Image
    loader="product_image"
    context="{{ { entity: product } }}"
    width="400"
    height="300"
    alt="Product image"
/>
```

Only when an entity has several fields using the same mapping do you need to name one with `field` (e.g. `:context="{ entity: gallery, field: 'coverFile' }"`).

Mistakes are reported with the fix:

- At container build: a `mapping` that does not exist, or a vich loader that could serve several mappings, lists the available mappings.
- At render time: an entity without a field using the loader's mapping, a `field` using another mapping, or an ambiguous field throws an `InvalidImageReferenceException` naming the entity, the field and the mappings involved.
