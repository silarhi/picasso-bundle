---
title: 'Remote URLs'
description: 'Transform images hosted on other servers, safely.'
sidebar:
    order: 4
---

Loads and transforms remote images by URL. Requires a PSR-18 HTTP client and a PSR-17 request factory.

```bash
composer require symfony/http-client psr/http-client nyholm/psr7
```

By default the loader uses Symfony's `psr18.http_client` service for both. FrameworkBundle only registers it when `psr/http-client` is installed, and it needs a PSR-17 implementation such as `nyholm/psr7` (or `php-http/discovery` with one). To use another client, set `http_client` and `request_factory` to your own service IDs.

```yaml
picasso:
    loaders:
        url: ~ # type inferred from key name
```

```twig
<twig:Picasso:Image
    src="https://example.com/remote-image.jpg"
    loader="url"
    width="800"
    height="600"
    alt="Remote image"
/>
```

With Imgix, the CDN fetches the remote image itself. With Glide, your server downloads it on the first request of each variant, then serves the cached variant. So any URL your server can reach could be fetched:

- set a `sign_key` on the Glide transformer: without it, anyone can forge valid image URLs;
- list the hosts your images come from in `allowed_hosts`: Glide 404s on images from any other host, without requesting them. `*.example.com` allows its subdomains, not `example.com` itself.

```yaml
picasso:
    loaders:
        url:
            allowed_hosts: ['images.example.com', '*.cdn.example.com'] # empty: any host
    transformers:
        glide:
            sign_key: '%env(PICASSO_SIGN_KEY)%'
```

`allowed_hosts` only applies to what Glide serves: rendering, and Imgix, are not restricted. The HTTP client follows redirects as configured (Symfony's follows up to 20 by default), so an allowed host redirecting elsewhere is followed: pass a client with `max_redirects: 0` as `http_client` if that matters to you.
