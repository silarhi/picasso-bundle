---
title: 'Cache purge'
description: 'Remove the cached variants of an image after replacing it.'
sidebar:
    order: 8
---

Both built-in transformers support purging cached image variants via the `PurgableTransformerInterface`.

A purge throws a `PurgeException` when the cache can't be cleared: the Glide cache storage fails to delete the variants, or the Imgix API rejects the request. With Glide, purging an image that was never cached is a no-op.

## Glide

Glide cache is purged automatically — no extra configuration needed. In standard mode, `Server::deleteCache()` removes all cached variants. In public cache mode, the bundle deletes the cache directory for the specific transformer/loader/path combination (under `public_cache.prefix` when set). Purging does not reach a CDN in front of the cache: clear its edge caches separately.

## Imgix

Imgix purge requires an API key and a PSR-18 HTTP client:

```yaml
picasso:
    transformers:
        imgix:
            type: imgix
            base_url: 'https://my-source.imgix.net'
            api_key: '%env(IMGIX_API_KEY)%'
            # Optional: defaults to psr18.http_client for all three
            # http_client: 'psr18.http_client'
            # request_factory: 'psr18.http_client'
            # stream_factory: 'psr18.http_client'
```

## Programmatic Usage

Use the `ImagePipeline` service to purge from your code:

```php
use Silarhi\PicassoBundle\Service\ImagePipeline;

class ImageManager
{
    public function __construct(private ImagePipeline $pipeline) {}

    public function deleteImage(string $path): void
    {
        // Purge all cached variants (default loader, and its default_transformer
        // or else the global one, like when rendering)
        $this->pipeline->purge($path);

        // Or specify loader/transformer explicitly
        $this->pipeline->purge($path, loader: 'filesystem', transformer: 'glide');
    }
}
```

You can also use the `PurgableTransformerInterface` directly:

```php
use Silarhi\PicassoBundle\Transformer\PurgableTransformerInterface;

if ($transformer instanceof PurgableTransformerInterface) {
    $transformer->purge($path, ['loader' => 'filesystem', 'transformer' => 'glide']);
}
```
