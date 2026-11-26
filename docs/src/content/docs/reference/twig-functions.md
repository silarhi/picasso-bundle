---
title: 'Twig functions'
description: 'picasso_image(), picasso_image_url(), picasso_image_data() and picasso_image_set().'
sidebar:
    order: 2
---

- **`picasso_image()`** — renders a full responsive `<picture>` element (same
  output as the `<twig:Picasso:Image>` component) as a function call, for
  places where the component syntax is awkward: macros, `{{ }}` expressions,
  variables built in Twig, or a string returned by a filter.
- **`picasso_image_url()`** — generates a single transformed image URL.
- **`picasso_image_data()`** — returns the data of the `<picture>` instead of its HTML, for markup of your own.
- **`picasso_image_set()`** — generates a CSS `image-set()`, for backgrounds.

## `picasso_image()`

Renders a complete responsive `<picture>` element with all configured formats,
srcsets, placeholder, and `<img>` fallback. It accepts the same named arguments
as the `<twig:Picasso:Image>` Twig component.

```twig
{# Same output as <twig:Picasso:Image src="photo.jpg" width="800" height="600" sizes="100vw" alt="A photo" /> #}
{{ picasso_image(
    src='photo.jpg',
    width=800,
    height=600,
    sizes='100vw',
    attributes={alt: 'A photo', class: 'rounded shadow-lg'}
) }}
```

Extra HTML attributes (`alt`, `class`, `id`, `data-*`, …) are passed via the
`attributes` named argument and forwarded to the inner `<img>` tag.

All available parameters mirror the `<twig:Picasso:Image>` component:
`src`, `width`, `height`, `sizes`, `sourceWidth`, `sourceHeight`, `loader`,
`transformer`, `quality`, `fit`, `placeholder`, `placeholderData`, `priority`,
`loading`, `fetchPriority`, `unoptimized`, `resolveMetadata`, `context`,
`attributes`, `route`, `routeParameters`, `preset`, `modifiers`, `artDirection`.

See [Component Properties](/reference/component/#properties) for the full reference.

## `picasso_image_url()`

Generates a single transformed image URL.
Useful for backgrounds, meta tags, Open Graph images, or anywhere you need a plain URL.

```twig
{# Simple thumbnail #}
<img src="{{ picasso_image_url('photo.jpg', width=300, format='webp') }}" alt="Thumbnail">

{# Open Graph meta tag #}
<meta property="og:image" content="{{ picasso_image_url('hero.jpg', width=1200, height=630, format='jpg', fit='crop') }}">

{# CSS background image #}
<div style="background-image: url('{{ picasso_image_url('bg.jpg', width=1920, format='webp', quality=80) }}')">
```

All available parameters:

```twig
{{ picasso_image_url(
    'photo.jpg',
    width=800,
    height=600,
    format='webp',
    quality=85,
    fit='crop',
    blur=10,
    dpr=2,
    loader='product_image',
    transformer='imgix',
    context={ entity: product }
) }}
```

| Parameter         | Type     | Description                                                          |
| ----------------- | -------- | -------------------------------------------------------------------- |
| `width`           | `int`    | Target width in pixels                                               |
| `height`          | `int`    | Target height in pixels                                              |
| `format`          | `string` | Output format (`avif`, `webp`, `jpg`, etc.)                          |
| `quality`         | `int`    | Output quality (1–100)                                               |
| `fit`             | `string` | Fit mode (`contain`, `cover`, `crop`, `fill`)                        |
| `blur`            | `int`    | Blur radius                                                          |
| `dpr`             | `int`    | Device pixel ratio                                                   |
| `loader`          | `string` | Override default loader                                              |
| `transformer`     | `string` | Override default transformer                                         |
| `context`         | `array`  | Extra context for the loader                                         |
| `route`           | `string` | Your route serving the image (see [Private Images](/guides/private-images/)) |
| `routeParameters` | `array`  | Parameters of that route                                             |
| `modifiers`       | `array`  | [Modifiers](/guides/modifiers/) in Glide's vocabulary                |
| `preset`          | `string` | Named [preset](/guides/presets/) filling the parameters not given    |

## `picasso_image_data()`

Returns the `ImageRenderData` the component renders, with the arguments of `picasso_image()`: `fallbackSrc`, `fallbackSrcset`, `sources` (each with `type`, `srcset`, and for art-directed ones `media`, `sizes`, `width`, `height`), `placeholderUri`, `width`, `height`, `loading`, `fetchPriority`, `sizes`.

```twig
{% set image = picasso_image_data(src=product.image, preset='card') %}
<img src="{{ image.fallbackSrc }}" srcset="{{ image.fallbackSrcset }}" sizes="{{ image.sizes }}" width="{{ image.width }}" height="{{ image.height }}" alt="{{ product.name }}">
```

See [Background images](/guides/background-images/#markup-of-your-own-picasso_image_data).

## `picasso_image_set()`

Returns a CSS `image-set()` of the image, one candidate per configured format and density:

```twig
<div style="background-image: {{ picasso_image_set(src='hero.jpg', width=1440) }}">
```

| Parameter         | Type        | Description                                                        |
| ----------------- | ----------- | ------------------------------------------------------------------ |
| `src`             | `string`    | Image path (omit it when the loader reads the `context`)           |
| `width`           | `int`       | Width in CSS pixels (auto-detected from the source)                |
| `height`          | `int`       | Height in CSS pixels (follows the source ratio when omitted)       |
| `densities`       | `list<int>` | Pixel densities of the candidates, default `[1, 2]`                |
| `quality`, `fit`, `modifiers`, `loader`, `transformer`, `sourceWidth`, `sourceHeight`, `resolveMetadata`, `context`, `route`, `routeParameters`, `preset` | | As for `picasso_image_url()` and the component |

See [Background images](/guides/background-images/).
