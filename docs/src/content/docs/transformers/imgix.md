---
title: 'Imgix (CDN)'
description: 'Transform images on the Imgix CDN.'
sidebar:
    order: 3
---

[Imgix](https://imgix.com/) processes images via their CDN. No local processing is needed.

```yaml
picasso:
    transformers:
        imgix:
            base_url: 'https://my-source.imgix.net'
            sign_key: '%env(IMGIX_SIGN_KEY)%' # optional
            api_key: '%env(IMGIX_API_KEY)%' # optional, enables cache purge
```

Imgix fetches the sources itself: no route to import, no local processing. See [Cache purge](/guides/cache-purge/#imgix) to purge its caches.
