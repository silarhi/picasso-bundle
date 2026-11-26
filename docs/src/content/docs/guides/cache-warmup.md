---
title: 'Cache warm-up'
description: 'Render the variants of your images before their first visitor asks for them.'
sidebar:
    order: 13
---

Glide renders a variant on its first request, and that visitor waits for it. `picasso:warmup` renders them ahead of time, the way pages render them: every URL of the `<picture>` (each srcset candidate of each format, the fallback, the [transformer placeholder](/guides/placeholders/)) is served through the bundle controller, which stores what it renders.

```bash
php bin/console picasso:warmup products/shoe.jpg products/bag.jpg --loader=uploads --preset=card --preset=hero
```

Each `--preset` renders the images once more; without one, give the options of the component instead (`--width`, `--height`, `--sizes`, `--fit`, `--quality`). Paths are read from the standard input when no argument is given, one per line:

```bash
find public/uploads -name '*.jpg' -printf '%P\n' | php bin/console picasso:warmup --loader=uploads --preset=card
```

Already cached variants are served from the cache, so warming up again is cheap. With `defer_cache_write`, the variants are uploaded after each image. Missing or undecodable images are listed and make the command fail, after the others are warmed up.

Only local transformers (Glide) can be warmed up: Imgix renders its variants on its own CDN, and the command warns when no URL is served by the bundle. Images of [private routes](/guides/private-images/) are skipped, since only your route serves them.

## Purging

`picasso:purge` deletes the cached variants of images, through the transformer that renders them (see [Cache purge](/guides/cache-purge/)):

```bash
php bin/console picasso:purge products/shoe.jpg --loader=uploads
```
