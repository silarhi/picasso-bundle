---
title: 'Art direction'
description: 'Show another crop, or another image, on small screens.'
sidebar:
    order: 10
---

Responsive images serve the same picture at several sizes. Art direction changes the picture itself: a square crop of a wide hero on phones, a portrait photo in portrait orientation. The browser picks the first `<source>` whose `media` query matches, so art-directed sources come before the image's own.

Give `artDirection` the sources by media query:

```twig
<twig:Picasso:Image
    src="hero.jpg"
    width="1600"
    height="600"
    fit="cover"
    sizes="100vw"
    artDirection="{{ {
        '(max-width: 640px)': {width: 640, height: 640, fit: 'crop-50-30'},
    } }}"
    alt="Our team"
/>
```

```html
<picture>
    <source media="(max-width: 640px)" type="image/avif" srcset="…" sizes="100vw" width="640" height="640" />
    <source media="(max-width: 640px)" type="image/webp" srcset="…" sizes="100vw" width="640" height="640" />
    <source media="(max-width: 640px)" type="image/jpeg" srcset="…" sizes="100vw" width="640" height="640" />
    <source type="image/avif" srcset="…" sizes="100vw" />
    <source type="image/webp" srcset="…" sizes="100vw" />
    <img src="…" srcset="…" sizes="100vw" width="1600" height="600" … />
</picture>
```

Each art-directed source has one `<source>` per format, the fallback format included: the `<img>` belongs to the image itself. The `width` and `height` of a source let the browser reserve the right space for that crop, so the layout does not shift.

`artDirection` works the same with `picasso_image()` and `ImageHelperInterface::imageData()`.

## What an entry inherits

An entry inherits from the image every option it omits, so most only set what changes:

| Option                          | When omitted                                                             |
| ------------------------------- | ------------------------------------------------------------------------ |
| `src`, `context`                | The image's: the same image, cropped differently                         |
| `width`, `height`               | The image's, **together**: set either one and the other follows the source ratio |
| `sourceWidth`, `sourceHeight`   | The image's when the entry has no `src`/`context`, else read from the source |
| `sizes`, `quality`, `fit`       | The image's                                                              |
| `modifiers`                     | The image's, merged with the entry's                                     |
| `preset`                        | None: a [preset](/guides/presets/) fills what the entry does not set     |

The loader and transformer are the image's. Another image (`src`, or a `context` naming another entity) is loaded with the same loader:

```twig
{{ picasso_image(src='banner-wide.jpg', sizes='100vw', artDirection={
    '(orientation: portrait)': {src: 'banner-tall.jpg', preset: 'portrait'},
}, attributes={alt: 'Summer sale'}) }}
```

An unknown option, or a value of the wrong type, throws an `InvalidTransformationException`.

## Priority images

A [priority image](/guides/priority-images/) preloads the first source of each media query, with that `media` attribute. The image's own preload gets the negated queries (`not all and (max-width: 640px)`), so a phone does not download both crops. Only media conditions can be negated as a whole: when a query starts with a media type (`screen and …`) or is a list, the image's own preload stays unconditional.
