---
title: 'Flysystem (S3, GCS, Azure…)'
description: 'Serve images from a Flysystem storage: S3, Google Cloud Storage, Azure and more.'
sidebar:
    order: 2
---

Reads images via a [Flysystem](https://flysystem.thephpleague.com/) storage, supporting S3, GCS, Azure, and more.

```bash
composer require league/flysystem-bundle
```

```yaml
picasso:
    loaders:
        my_s3:
            type: flysystem
            storage: 'default.storage' # your Flysystem service ID
```

```twig
<twig:Picasso:Image src="photo.jpg" loader="my_s3" width="800" height="600" alt="S3 image" />
```
