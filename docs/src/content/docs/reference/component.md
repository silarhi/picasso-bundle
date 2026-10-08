---
title: 'Twig component'
description: 'Every property of the <twig:Picasso:Image> component.'
sidebar:
    order: 1
---

The `<twig:Picasso:Image>` component renders a responsive `<picture>` element with a `<source>` tag for each configured format and a fallback `<img>` with a full srcset.

```twig
<twig:Picasso:Image
    src="photo.jpg"
    width="800"
    height="600"
    sizes="(max-width: 768px) 100vw, 800px"
    alt="A beautiful landscape"
/>
```

## Properties

| Property          | Type           | Default | Description                                                          |
| ----------------- | -------------- | ------- | -------------------------------------------------------------------- |
| `src`             | `string`       | —       | Image path relative to the loader's base                             |
| `width`           | `int`          | auto    | Display width (auto-detected from source)                            |
| `height`          | `int`          | auto    | Display height (auto-detected from source)                           |
| `sizes`           | `string`       | —       | Responsive `sizes` attribute                                         |
| `sourceWidth`     | `int`          | auto    | Explicit source width (skips detection)                              |
| `sourceHeight`    | `int`          | auto    | Explicit source height (skips detection)                             |
| `loader`          | `string`       | —       | Override default loader                                              |
| `transformer`     | `string`       | —       | Override default transformer                                         |
| `quality`         | `int`          | 75      | Override quality (1–100)                                             |
| `fit`             | `string`       | contain | Fit mode: `contain`, `cover`, `crop`, `fill`                         |
| `placeholder`     | `string\|bool` | —       | `true`/`false` to enable/disable, or a placeholder name              |
| `placeholderData` | `string`       | —       | Literal data URI, bypasses placeholder services                      |
| `priority`        | `bool`         | false   | Eager loading, `fetchpriority="high"`, no placeholder                |
| `loading`         | `string`       | lazy    | `lazy` or `eager`. Auto-set when priority                            |
| `fetchPriority`   | `string`       | —       | `high`, `low`, `auto`. Auto-set when priority                        |
| `unoptimized`     | `bool`         | false   | Serve original image without transformation                          |
| `resolveMetadata` | `bool`         | —       | Override metadata resolution (see [below](/reference/component/#metadata-resolution))     |
| `context`         | `array`        | `[]`    | Extra context for the loader (e.g. Vich)                             |
| `route`           | `string`       | —       | Your route serving the image (see [Private Images](/guides/private-images/)) |
| `routeParameters` | `array`        | `[]`    | Parameters of that route                                             |

### Automatic Dimension Detection

When `width` and `height` are not provided, PicassoBundle can
detect them from the image stream. You can also provide `sourceWidth`
and `sourceHeight` to skip detection entirely, which is useful for
performance when you already know the image dimensions:

```twig
{# Auto-detected dimensions (requires resolve_metadata enabled) #}
<twig:Picasso:Image src="photo.jpg" sizes="100vw" alt="Photo" />

{# Explicit source dimensions (skips stream detection) #}
<twig:Picasso:Image src="photo.jpg" sourceWidth="{{ 4000 }}" sourceHeight="{{ 3000 }}" width="800" height="600" alt="Photo" />
```

The component also preserves aspect ratio when only one display dimension is provided:

```twig
{# height is calculated automatically from the source aspect ratio #}
<twig:Picasso:Image src="photo.jpg" width="800" sizes="100vw" alt="Photo" />
```

### Metadata Resolution

To reduce **Cumulative Layout Shift (CLS)**, `width` and `height`
attributes are only rendered in the HTML when **both** are available.
If only one dimension is provided and the other cannot be resolved,
neither is output — preventing the browser from reserving incorrect
space.

Metadata resolution (reading the image stream to detect dimensions) is
controlled at three levels, with this precedence: **runtime > per-loader > global**.

| Level      | Option             | Default                                        |
| ---------- | ------------------ | ---------------------------------------------- |
| Global     | `resolve_metadata` | `false`                                        |
| Per-loader | `resolve_metadata` | `null` (inherit global); `true` for filesystem |
| Runtime    | `resolveMetadata`  | `null` (inherit per-loader/global)             |

```twig
{# Force metadata resolution for this image, regardless of config #}
<twig:Picasso:Image src="photo.jpg" resolveMetadata="{{ true }}" width="800" sizes="100vw" alt="Photo" />

{# Disable metadata resolution for this image #}
<twig:Picasso:Image src="photo.jpg" resolveMetadata="{{ false }}" width="800" height="600" alt="Photo" />
```

Filesystem loaders default to `resolve_metadata: true` because reading
local files is cheap. For remote loaders (URL, Flysystem with remote
backends), it defaults to `false` to avoid unnecessary network requests.

When metadata resolution is enabled, the source dimensions are read even if
both `width` and `height` are given: they only cap the `srcset` candidates to
the source dimensions (width, and height for a crop), so an image is never
upscaled, while the rendered `width` and `height` stay as given. With resolution disabled, giving both display
dimensions skips the read.

In responsive mode (`sizes`), every `srcset` candidate keeps the aspect ratio
of the given `width` and `height`, like the fallback `src`.

### Extra HTML Attributes

The component forwards any extra attributes to the inner `<img>` tag:

```twig
<twig:Picasso:Image
    src="photo.jpg"
    width="400"
    height="300"
    class="rounded shadow-lg"
    id="main-photo"
    data-controller="lightbox"
    alt="Photo"
/>
```

### Unoptimized Mode

Use `unoptimized` to serve the image as-is, without any transformation. The `src` value is passed directly to the `<img>` tag:

```twig
<twig:Picasso:Image src="/images/logo.svg" unoptimized="{{ true }}" alt="Logo" />
```
