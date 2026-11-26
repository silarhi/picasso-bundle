---
title: 'Modifiers'
description: 'Rotate, flip, crop, adjust colours and add watermarks.'
sidebar:
    order: 11
---

Beyond size, format, quality and fit, `modifiers` takes further manipulations in [Glide's vocabulary](https://glide.thephpleague.com/2.0/api/quick-reference/). Glide reads them as they are; other transformers translate them, like [fit values](/guides/fit-modes/):

```twig
<twig:Picasso:Image src="photo.jpg" width="800" sizes="100vw" modifiers="{{ {flip: 'h', sharp: 10} }}" alt="…" />

{{ picasso_image_url('photo.jpg', width=300, modifiers={filt: 'greyscale'}) }}
```

Modifiers apply to every URL of the image (each srcset candidate, the fallback, the transformer placeholder).

## Available modifiers

| Modifier    | Glide                                        | Imgix                                    |
| ----------- | -------------------------------------------- | ---------------------------------------- |
| `or`        | Orientation: `auto`, `0`, `90`, `180`, `270` (counter-clockwise) | `orient` (`auto` is Imgix's default)     |
| `flip`      | `h`, `v` or `both`                           | `flip` (`both` → `hv`)                   |
| `crop`      | Rectangle `width,height,x,y`                 | `rect` (`x,y,width,height`)              |
| `bri`       | Brightness, -100 to 100                      | `bri`                                    |
| `con`       | Contrast, -100 to 100                        | `con`                                    |
| `gam`       | Gamma, 0.1 to 9.99                           | Not supported (another scale)            |
| `sharp`     | Sharpen, 0 to 100                            | `sharp`                                  |
| `pixel`     | Pixelate, 0 to 1000                          | `px`                                     |
| `filt`      | `greyscale` or `sepia`                       | `sat=-100`, `sepia=100`                  |
| `bg`        | Background colour                            | `bg`                                     |
| `border`    | `width,color,method`                         | `border` (`width,color`)                 |
| `mark`…     | [Watermarks](#watermarks)                    | `mark`, `mark-w`, `mark-align`…          |

With Imgix, a key outside this table is passed through as an Imgix parameter, e.g. `{auto: 'compress'}`.

A modifier naming a param that has its own option (`w`, `h`, `fm`, `q`, `fit`, `blur`, `dpr`), a modifier Glide does not know, or one Imgix cannot express throws an `InvalidTransformationException`.

With Glide's `public_cache`, modifiers are stored in the variant filename, so their values may only hold letters, digits, `.`, `_` and `-`: a `crop` rectangle (`10,10,0,0`) cannot be stored there.

## Watermarks

Glide reads watermark images from their own storage: set `watermarks` on the transformer to a directory or a Flysystem storage name, like `cache`:

```yaml
picasso:
    transformers:
        glide:
            sign_key: '%env(PICASSO_SIGN_KEY)%'
            watermarks: '%kernel.project_dir%/assets/watermarks'
```

`mark` names the watermark image in that storage, and the other `mark*` modifiers place it:

```twig
<twig:Picasso:Image
    src="{{ photo.path }}"
    width="1200"
    sizes="100vw"
    modifiers="{{ {mark: 'logo.png', markw: '20w', markpos: 'bottom-right', markpad: '2w', markalpha: 60} }}"
    alt="…"
/>
```

| Modifier    | Description                                                                  |
| ----------- | ---------------------------------------------------------------------------- |
| `mark`      | Watermark path in the `watermarks` storage                                   |
| `markw`     | Width, in pixels or relative (`20w`: 20% of the image width)                 |
| `markh`     | Height                                                                       |
| `markx`     | Horizontal offset                                                            |
| `marky`     | Vertical offset                                                              |
| `markpad`   | Offset from every edge                                                       |
| `markpos`   | `top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right` |
| `markalpha` | Opacity, 0 to 100                                                            |
| `markfit`   | Fit of the watermark in its `markw`/`markh` box                              |

A `mark*` modifier on a Glide transformer without `watermarks` throws an `InvalidTransformationException`: Glide would silently ignore it. With Imgix, `mark` is the watermark's absolute URL.

Watermarks are part of the variant, the original stays untouched. Glide URLs are signed, so a visitor cannot drop the `mark` from a URL: only the variants your templates generate are served. Set the watermark on every rendering of the images it protects, which a [preset](/guides/presets/) makes hard to forget.
