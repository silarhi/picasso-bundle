---
title: 'Twig functions'
description: 'picasso_image() and picasso_image_url(): the component as a function call, and single image URLs.'
sidebar:
    order: 2
---

Two Twig functions are available:

- **`picasso_image()`** — renders a full responsive `<picture>` element (same
  output as the `<twig:Picasso:Image>` component) as a function call, for
  places where the component syntax is awkward: macros, `{{ }}` expressions,
  variables built in Twig, or a string returned by a filter.
- **`picasso_image_url()`** — generates a single transformed image URL.

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
`attributes`, `route`, `routeParameters`.

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
