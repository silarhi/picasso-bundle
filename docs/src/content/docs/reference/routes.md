---
title: 'Routes, URLs and errors'
description: 'The image route, URL aliases, and the responses of the image controller.'
sidebar:
    order: 3
---

The bundle registers a route for on-demand image transformation (used by Glide and other local transformers):

```text
GET /image/{transformer}/{loader}/{path}
```

Import the routes in your application:

```yaml
# config/routes/picasso.yaml
picasso:
    resource: '@PicassoBundle/config/routes.php'
```

:::note
Routes are only required when using a local transformer
like Glide. CDN-based transformers (Imgix) generate external URLs
and do not need this route.
:::

## URL Aliases

By default, image URLs name the transformer and the loader: `/image/glide/product_image/photo.jpg`. Give either one a `url_alias` to replace its name in the URL with a shorter one, or with one that keeps your configuration names private:

```yaml
picasso:
    transformers:
        glide: { url_alias: g }
    loaders:
        product_image: { type: vich, url_alias: pi }
# → /image/g/pi/photo.jpg
```

Loaders and transformers registered with the attributes take it as `urlAlias`: `#[AsImageLoader('s3', urlAlias: 's')]`, `#[AsImageTransformer('cloudinary', urlAlias: 'c')]`.

- An alias only changes URLs. Templates, `default_loader`, `default_transformer` and purges keep using the names.
- URLs naming a loader or transformer by its name keep being served after it gets an alias, so adding one does not break the URLs already published.
- An alias may contain letters, digits, `_` and `-`. It must not be the alias or the name of another loader (or transformer, for a transformer alias). A loader and a transformer can share one, since they fill different URL segments. Conflicts fail at container build.
- With `public_cache`, cache keys use the aliases as well, so they keep mirroring the URL path. Setting or changing an alias moves the cache keys like renaming would: purge the variants first, or let the old ones be.

## Error Responses

The image controller answers a `404 Not Found` whenever an image cannot be served, and wraps the reason in a `NotFoundHttpException` whose previous exception tells the cases apart:

| Cause                                                                                   | Previous exception          |
| --------------------------------------------------------------------------------------- | --------------------------- |
| Source file missing, invalid signature, malformed public-cache path                     | `ImageNotFoundException`    |
| Source file exists but is not a decodable image (truncated upload, PDF named `.jpg`...) | `UndecodableImageException` |

This lets a `kernel.exception` listener react to one case only. For instance, an application redirecting unservable image URLs to the original file should do so for `ImageNotFoundException` only: for an `UndecodableImageException` the original _is_ the broken file.

```php
if ($throwable instanceof NotFoundHttpException
    && $throwable->getPrevious() instanceof ImageNotFoundException) {
    // Safe to redirect to the original file
}
```

When the storage holding the source is unavailable (an `ImageSourceUnavailableException`: unreachable, timing out, answering `5xx` or `429`), the image may well exist: the controller, and `ImageServer::serve()`, answer a `503 Service Unavailable` instead, with `Retry-After: 30` and `Cache-Control: no-store` whatever `cache_control` says, so no CDN keeps it after the outage. It is a `ServiceUnavailableHttpException` whose previous exception is the `ImageSourceUnavailableException`. Symfony logs 5xx exceptions as `critical`; to keep an outage of your storage out of your error tracker, lower it:

```yaml
# config/packages/framework.yaml
framework:
    exceptions:
        Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException:
            log_level: warning
```

This needs Glide 3 or later: Glide 2 drops the source's exception, so such a read stays a `500` there.

The controller, not the transformer, owns the `Cache-Control` of what it serves, configured under `picasso.cache_control`:

- Served images (and their `304 Not Modified`) get `public, max-age=<max_age>` plus `immutable` when enabled; a transformer's `Expires` is dropped so it cannot contradict `max-age`. With `max_age: ~`, the transformer's own headers are kept. Redirects keep theirs.
- 404s are not cacheable by default. Set `error_max_age` to let clients and CDNs keep them for that many seconds (`Cache-Control: public, max-age=…`).

When two requests render the same variant at once and the cache storage rejects the second write (S3-compatible storages may answer `409 Conflict`), the request is still answered with the variant the first one cached, instead of an error.
