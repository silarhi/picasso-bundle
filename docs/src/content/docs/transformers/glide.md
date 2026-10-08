---
title: 'Glide (local)'
description: 'Transform images on your own servers with Glide: drivers, render lock, cache storage.'
sidebar:
    order: 1
---

[Glide](https://glide.thephpleague.com/) processes images locally using GD, Imagick or libvips. Glide 2, 3 and 4 are supported.

```bash
composer require league/glide
```

```yaml
picasso:
    transformers:
        glide:
            sign_key: '%env(PICASSO_SIGN_KEY)%'
            cache: '%kernel.project_dir%/var/glide-cache'
            driver: gd # gd | imagick | vips
            max_image_size: ~ # optional: max pixel count (width x height)
            base_url: ~ # optional: e.g. https://img.example.com to point image URLs at a CDN
            defer_cache_write: false # store a cache miss after the response is sent
            lock:
                enabled: false # render a missing variant once (see "Rendering each variant once")
            public_cache:
                enabled: false # serve from public dir for better performance
                prefix: '' # optional: path prepended to cache keys (see "Serving thumbnails from a CDN")
```

:::caution
When using Glide, you must [import the bundle routes](/reference/routes/) so that the image controller can serve transformed images.
:::

Glide URLs are stable: the same image and transformation always produce the same URL and signature. A thumbnail used several times in a page, or across pages, is fetched once and caches cleanly in browsers and CDNs.

A cached variant costs two calls to the cache storage (its size, then its contents), and a conditional request (`If-Modified-Since`) one: the content type is read from the image's first bytes. That matters when the cache is an object store, where each call is an HTTP request. On such a storage, responses carry no `Last-Modified` header, which would cost one more call: signed URLs never change, and [`cache_control`](/reference/routes/#error-responses) makes them cacheable for a year anyway.

## Choosing a driver

| Driver    | Needs                                                                                                |
| --------- | ---------------------------------------------------------------------------------------------------- |
| `gd`      | The `gd` extension. The default.                                                                     |
| `imagick` | The `imagick` extension.                                                                             |
| `vips`    | `composer require intervention/image-driver-vips` (Glide 3 or later), libvips, and the FFI extension |

[libvips](https://www.libvips.org/) is the fastest driver on Linux: in the official Alpine image it renders a whole srcset two to three times faster than GD and Imagick, and AVIF about five times faster (see [Performance](/concepts/performance/)). Glide hands every driver the whole source file, so libvips cannot shrink large sources while decoding them, and uses about as much memory as the others. The Intervention driver calls it through [FFI](https://www.php.net/manual/en/book.ffi.php):

- Install libvips: `apt install libvips42` (Debian/Ubuntu), `apk add vips vips-heif` (Alpine; `vips-heif` adds AVIF), `brew install vips` (macOS).
- Enable FFI for the web SAPI: the default `ffi.enable=preload` only allows it on the command line and in preloaded scripts, so set `ffi.enable=true` for PHP-FPM, FrankenPHP or Apache.

Composer picks the driver version matching your Glide version (1.x for Glide 3, 4.x for Glide 4). A missing package fails at container build and says what to install.

## Rendering each variant once

Nothing stops several requests from rendering the same missing variant at the same time: a crawler fetching a page's images, a burst of visitors after a newsletter, or a CDN with many edge locations each asking the origin. Every one of them decodes the source and encodes the variant, so 16 concurrent requests cost 16 renders (and their memory).

With `lock`, the first request renders the variant while the others wait for it, then serve it from the cache:

```yaml
framework:
    lock: '%env(LOCK_DSN)%' # e.g. redis://redis:6379, or flock on a single server

picasso:
    transformers:
        glide:
            lock:
                enabled: true
                # factory: lock.factory # the LockFactory framework.lock configures, or your own service
                # ttl: 30 # seconds a lock outlives a renderer that crashed
                # wait: 10 # seconds a miss waits for a render in progress
```

- Install the component: `composer require symfony/lock`. Use a store shared by every server rendering images (Redis, a database...). `flock` only serializes the renders of one server.
- Hits take no lock: only a miss locks, keyed by the variant's cache path.
- With `defer_cache_write`, the lock is held until the variant is uploaded: before that, the waiting requests would not find it in the cache.
- If the renderer dies, its lock expires after `ttl` and a waiting request renders the variant itself. Keep `ttl` above your slowest render (plus its upload with `defer_cache_write`).
- A request waits at most `wait` seconds, then renders the variant itself, without the lock: a render held up (e.g. by an upload to a stalled storage) never keeps the others waiting until `max_execution_time`, a fatal error that also ends a long-running worker (FrankenPHP, RoadRunner). Keep `wait` well under `max_execution_time`, leaving room for the render itself.
- Waiting requests hold a PHP worker while they wait, idle instead of rendering: they cost a worker slot, not CPU or memory.

## Storing the Glide cache on Flysystem

The `cache` option is polymorphic: pass a local path _or_ the name of a [Flysystem bundle](https://github.com/thephpleague/flysystem-bundle) storage. The bundle resolves the name at boot via its internal `FlysystemRegistry` (the same one used by the Vich loader) — if no storage matches, the value is treated as a path. This lets you keep sources and derivatives on different Flysystem instances:

```yaml
# config/packages/flysystem.yaml
flysystem:
    storages:
        sources.storage:
            adapter: 'aws'
            options:
                client: 'aws_s3_client'
                bucket: 'my-originals'
        thumbs.storage:
            adapter: 'aws'
            options:
                client: 'aws_s3_client'
                bucket: 'my-thumbs'
```

```yaml
# config/packages/picasso.yaml
picasso:
    loaders:
        flysystem:
            storage: 'sources.storage' # source images
    transformers:
        glide:
            sign_key: '%env(PICASSO_SIGN_KEY)%'
            cache: 'thumbs.storage' # rendered cache on a different Flysystem
            driver: gd
```

:::note
`public_cache: enabled: true` writes rendered files at a path your web server is expected to serve directly. Combined with a remote Flysystem cache, that only works if the underlying bucket is publicly served at the matching URL prefix (see [Serving thumbnails from a CDN](/transformers/cdn/)) — otherwise leave `public_cache` disabled and let the bundle's controller stream the cached file.
:::
>
> With a local path as `cache`, `public_cache` writes world-readable variants (`0644` files, `0755` directories): the web server serves them itself, and may run as another user than PHP (shared hosting), so Flysystem's default `0700` directories would make it answer `403`. With a local Flysystem storage as `cache`, give it the same permissions (`directory_visibility: public` in flysystem-bundle).
