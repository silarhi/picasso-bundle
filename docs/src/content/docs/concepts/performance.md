---
title: 'Performance'
description: 'Benchmarks of rendering, serving and transforming, and how to tune for crawlers.'
sidebar:
    order: 2
---

Numbers from `benchmarks/run.php` in the official `php:8.4-cli-alpine` image ([`benchmarks/Dockerfile`](/concepts/performance/#running-the-benchmarks): GD with its bundled libgd, Imagick 7.1 and libvips from Alpine, OPcache on), on an Apple M-series machine running Linux arm64 natively. Default bundle configuration (AVIF, WebP and JPEG, 16 widths), one PHP worker per core, league/glide 4.1. Sources are generated photo-like JPEGs: small (800×600), HD (1920×1080) and 4K (3840×2160). Expect other hardware to differ in scale, not in shape. The same benchmarks on macOS (PHP 8.5, Homebrew libraries) give the same picture for PHP-side work, but not for image encoding: see [the differences](#on-macos).

## Pages

| Operation                                        | Time   |
| ------------------------------------------------ | ------ |
| One URL (`picasso_image_url()`)                  | 6.0 µs |
| Image with fixed width and height (7 URLs)       | 35 µs  |
| Responsive image, `sizes` set (42 URLs)          | 170 µs |
| Responsive image + `resolveMetadata` (PSR-6 hit) | 166 µs |
| Twig page with 30 responsive images              | 5.5 ms |

Rendering never touches the cache storage: a crawler fetching HTML pages costs URL signing (plus one `stat()` per image with the filesystem loader).

## Cache hits

| Mode            | Cache storage             | Storage calls | 200                  | 304             |
| --------------- | ------------------------- | ------------- | -------------------- | --------------- |
| Glide cache     | local disk                | 2             | 116 µs (8,600 req/s) | 1 call, 95 µs   |
| Glide cache     | object store (10 ms/call) | 2             | 23.9 ms              | 1 call, 12.9 ms |
| public cache    | local disk                | 2             | 117 µs (8,561 req/s) | 1 call, 97 µs   |
| public cache    | object store (10 ms/call) | 2             | 25.4 ms              | 1 call, 13.1 ms |
| deferred writes | local disk                | 2             | 119 µs (8,420 req/s) | 1 call, 104 µs  |
| deferred writes | object store (10 ms/call) | 2             | 24.1 ms              | 1 call, 12.7 ms |

Before 2.0, a hit went through Glide's own response factory: 5 storage calls (6 with deferred writes), about twice the time on local disk and 60 ms (74 ms) on the object store, and a 304 still opened the variant. No memory or file descriptor growth over 5,000 hits in one worker.

## Junk URLs

| Request (all answered 404)                | Time   |
| ----------------------------------------- | ------ |
| Invalid signature                         | 681 µs |
| Valid signature, missing image            | 693 µs |
| Unknown loader                            | 457 µs |
| Tracking param appended (`&utm_source=x`) | 643 µs |

A 404 costs about six hits (Symfony's error handling, and an `error`-level log line per request): set [`cache_control.error_max_age`](/reference/routes/#error-responses) so a CDN absorbs them. Any query param the signature does not cover, tracking params included, invalidates the URL.

## Cache misses

Render time and peak memory (RSS) of one variant:

| Source | Width | Format | gd             | imagick        | vips           |
| ------ | ----- | ------ | -------------- | -------------- | -------------- |
| small  | 640   | jpg    | 14 ms, 77 MB   | 11 ms, 77 MB   | 9 ms, 77 MB    |
| small  | 640   | webp   | 30 ms, 77 MB   | 32 ms, 77 MB   | 17 ms, 77 MB   |
| small  | 640   | avif   | 66 ms, 77 MB   | 48 ms, 77 MB   | 27 ms, 94 MB   |
| hd     | 640   | jpg    | 18 ms, 77 MB   | 22 ms, 77 MB   | 19 ms, 87 MB   |
| hd     | 640   | webp   | 30 ms, 77 MB   | 45 ms, 77 MB   | 22 ms, 88 MB   |
| hd     | 640   | avif   | 76 ms, 77 MB   | 61 ms, 81 MB   | 31 ms, 94 MB   |
| hd     | 1920  | jpg    | 31 ms, 77 MB   | 28 ms, 101 MB  | 22 ms, 86 MB   |
| hd     | 1920  | webp   | 135 ms, 77 MB  | 170 ms, 120 MB | 73 ms, 83 MB   |
| hd     | 1920  | avif   | 195 ms, 110 MB | 238 ms, 133 MB | 57 ms, 163 MB  |
| 4k     | 640   | jpg    | 59 ms, 81 MB   | 76 ms, 148 MB  | 31 ms, 101 MB  |
| 4k     | 640   | webp   | 68 ms, 80 MB   | 94 ms, 148 MB  | 33 ms, 98 MB   |
| 4k     | 640   | avif   | 103 ms, 84 MB  | 106 ms, 155 MB | 38 ms, 107 MB  |
| 4k     | 3840  | jpg    | 120 ms, 115 MB | 102 ms, 264 MB | 57 ms, 120 MB  |
| 4k     | 3840  | webp   | 523 ms, 146 MB | 677 ms, 338 MB | 286 ms, 129 MB |
| 4k     | 3840  | avif   | 847 ms, 278 MB | 853 ms, 341 MB | 150 ms, 386 MB |

About 77 MB of each figure is the PHP process itself (kernel, autoloader).

A crawler fetching every srcset URL of one image that was never rendered:

| Source | Variants | gd    | imagick | vips  | Cache written |
| ------ | -------- | ----- | ------- | ----- | ------------- |
| small  | 33       | 0.6 s | 0.5 s   | 0.3 s | 0.7 MB        |
| hd     | 42       | 1.7 s | 1.8 s   | 0.8 s | 2.3 MB        |
| 4k     | 48       | 5.9 s | 6.9 s   | 2.2 s | 7.7 MB        |

Sixteen concurrent requests for the same missing variant (4K source, WebP, 1920 wide, gd), each in its own process:

| [`lock`](/transformers/glide/#rendering-each-variant-once) | Renders | Responses | CPU (all processes) | Wall time |
| -------------------------------------- | ------- | --------- | ------------------- | --------- |
| off                                    | 16      | 16 × 200  | 6.6 s               | 0.61 s    |
| on                                     | 1       | 16 × 200  | 1.0 s               | 0.32 s    |

## Memory

RSS of one long-running worker stays flat over 60 cold renders (HD source, 640/1080/1920 wide) with every driver and format: at most +0.02 MB per render, after a 15-render warm-up.

`memory_limit` only bounds part of a render. GD built with the libgd bundled in PHP (as in the official Docker images) allocates its pixel buffers through PHP, so they count; the encoders (libjpeg, libwebp, libavif) and the Imagick and libvips drivers allocate outside PHP's memory manager, and do not count:

| Render (4K source, 3840 wide) | gd                             | imagick             | vips                |
| ----------------------------- | ------------------------------ | ------------------- | ------------------- |
| JPEG, `memory_limit=96M`      | fatal error (memory exhausted) | 264 MB RSS, renders | 124 MB RSS, renders |
| JPEG, `memory_limit=128M`     | 130 MB RSS, renders            | 280 MB RSS, renders | 134 MB RSS, renders |
| AVIF, `memory_limit=128M`     | 277 MB RSS, renders            | 341 MB RSS, renders | 385 MB RSS, renders |

So size the worker pool by RSS, not by `memory_limit`, and give GD a `memory_limit` that fits the largest source you accept (about 15 bytes per source pixel: a 4K source needs 128M), or set `max_image_size`.

## On macOS

With Homebrew's libraries (GD 2.3.3 linked to the system libgd, libavif, libvips 8.18), PHP-side numbers are within 10% of the Linux ones, but encoding differs:

- libvips encodes AVIF two to three times slower than GD and Imagick (1,167 ms for a 4K source to 3840 wide) instead of five times faster: Homebrew's AV1 encoder settings differ. A full crawl of a 4K image takes about 5 s with every driver.
- GD's AVIF encoder leaks about 2.5 MB of RSS per render, which adds up in long-running workers. It does not on Alpine.
- GD's pixel buffers do not count toward `memory_limit`, as GD uses the system libgd.

Benchmark the image your production runs: encoders are where platforms differ.

## Tuning for crawlers

- **Put a CDN in front of the cache** ([Serving thumbnails from a CDN](/transformers/cdn/)): hits then never reach PHP, and misses are the only cost left.
- **Enable [`lock`](/transformers/glide/#rendering-each-variant-once)** with a store shared by your servers: a crawler or a CDN asking for a missing variant from several places renders it once.
- **Consider the [`vips` driver](/transformers/glide/#choosing-a-driver)** on Linux: it renders a whole srcset two to three times faster than GD or Imagick, and AVIF five times faster.
- **Trim what a crawler can ask for.** Every format and width is one more variant per image: the default 3 formats × 16 widths make up to 48. Drop the widths your layout never uses (the 3840-wide variants are the slowest by far) and consider dropping WebP when you serve AVIF: browsers that do not support AVIF fall back to JPEG.
- **Set `max_image_size`**, so a huge upload cannot be rendered at full size.
- **Size workers by RSS, not `memory_limit`**: a 4K AVIF render takes 280 to 390 MB depending on the driver.
- **Set [`cache_control.error_max_age`](/reference/routes/#error-responses)**, so repeated junk URLs are answered by the CDN.

## Running the benchmarks

```bash
XDEBUG_MODE=off php -d opcache.enable_cli=1 benchmarks/run.php            # every scenario, ~5 minutes
XDEBUG_MODE=off php -d opcache.enable_cli=1 benchmarks/run.php hit herd   # some of: render hit notfound miss crawl herd memory
```

Each scenario prints a Markdown table. Drivers are benchmarked when available (`imagick` extension; `intervention/image-driver-vips` with libvips and FFI). Generated images, caches and kernels go to `benchmarks/var/`, which can be deleted at any time.

To measure on Linux, as most production servers run, `benchmarks/Dockerfile` builds the official `php:8.4-cli-alpine` image with GD (bundled libgd, AVIF), Imagick and libvips (Alpine packages, AVIF included), FFI and OPcache:

```bash
docker build -f benchmarks/Dockerfile -t picasso-bench .
docker run --rm picasso-bench              # every scenario
docker run --rm picasso-bench miss memory  # some of them
```

Pass `--build-arg PHP_VERSION=8.2` to benchmark another PHP version, and `--cpus`/`--memory` to `docker run` to match your production limits.
