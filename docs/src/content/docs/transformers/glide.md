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
            sign_key: '%env(PICASSO_SIGN_KEY)%' # required: a key of 32+ characters, or a list of them (see "Signing key")
            accept_legacy_signatures: true # also serve URLs signed before 2.7 (see "Signing key")
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

## Signing key

Every Glide URL carries a signature (`s`), so only your application can ask your server for a rendering. `sign_key` is the secret it is computed with. It is required, and should be at least 32 characters long. Generate one and keep it out of your repository:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;' # 64 characters
```

```dotenv
# .env.local, or your secret store
PICASSO_SIGN_KEY=…
```

A missing or empty key is rejected: when the container is built for a key written in the configuration, at the first use of the transformer for a key read from an environment variable, so an empty `PICASSO_SIGN_KEY=` fails the first image request instead of signing URLs anyone could compute. A key shorter than 32 characters still works, but triggers a deprecation: 3.0 will require at least 32. That is the length of the SHA-256 output (32 bytes), the key length HMAC asks for, and what a 32-character hexadecimal secret such as Symfony's generated `APP_SECRET` provides.

The signature is an HMAC-SHA256 of the image path and its sorted query string, compared in constant time. Before 2.7, Picasso used Glide's own signature (an MD5 hash prefixed with the key), which `accept_legacy_signatures` keeps accepting by default, so the URLs already in your pages, HTTP caches and CDNs keep working; only HMAC signatures are generated. Set it to `false` once those URLs are gone (after your longest page and CDN cache lifetime): 3.0 removes it, and the MD5 signatures with it.

### Rotating the key

Changing the key would break every URL already published. `sign_key` also takes a list of keys: the first one signs new URLs, and every one of them validates (with the MD5 signature too, while `accept_legacy_signatures` is on). The 1.x URLs [still redirected](/upgrade/from-1x/#1x-urls) keep working too, whichever key minted them. To rotate the key, or to replace a short one:

1. Generate a new key and put it **in front** of the current one, then deploy: new pages carry URLs signed with the new key, and the published URLs keep working.
2. Wait until every URL signed with the old key is gone: your longest HTML page cache, HTTP cache and CDN lifetime (with the default [`cache_control`](/reference/routes/#error-responses), CDNs may keep an image for a year, but they only ask your server again for URLs that pages still reference).
3. Remove the old key, then deploy.

Keep the keys in one environment variable, comma-separated, and read it with Symfony's `csv` processor, so rotating only changes the environment:

```yaml
picasso:
    transformers:
        glide:
            sign_key: '%env(csv:PICASSO_SIGN_KEYS)%'
```

```dotenv
PICASSO_SIGN_KEYS=<new key>,<old key>
```

Hexadecimal keys need no quoting. A YAML list works as well, one environment variable per key (`sign_key: ['%env(PICASSO_SIGN_KEY)%', '%env(PICASSO_OLD_SIGN_KEY)%']`), but each listed key must then be set: an empty one is rejected like an empty `sign_key`.

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

Public-cache variants end with an image extension, since the web server picks their content type from it: the extension of the output format (`fm`), else of the source name when it is one Glide can write (`jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`, `tif`, `tiff`, `heic`, `jxl`), else `jpg`. An upload named `page.html` is stored as `page.html/_untransformed.jpg`, never as an HTML file. Before 2.7 its extension came from the source name: such URLs are now redirected to the image extension, but the files already stored under another extension are still served by the web server: list them, then delete them (here with `cache: '%kernel.project_dir%/public/image'`):

```bash
find public/image -type f ! -iregex '.*\.\(avif\|gif\|heic\|jpe?g\|jxl\|png\|tiff?\|webp\)'
```

With a local path as `cache`, `public_cache` writes world-readable variants (`0644` files, `0755` directories): the web server serves them itself, and may run as another user than PHP (shared hosting), so Flysystem's default `0700` directories would make it answer `403`. With a local Flysystem storage as `cache`, give it the same permissions (`directory_visibility: public` in flysystem-bundle).
:::
