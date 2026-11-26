---
title: 'Background images'
description: 'CSS image-set() backgrounds, and the render data for markup of your own.'
sidebar:
    order: 12
---

A `<picture>` does not fit everywhere: CSS backgrounds, or markup the component does not render.

## CSS backgrounds: `picasso_image_set()`

`picasso_image_set()` returns a CSS [`image-set()`](https://developer.mozilla.org/en-US/docs/Web/CSS/image/image-set) of the image: one candidate per configured format and pixel density, so the browser picks the format it decodes and the resolution of the screen:

```twig
<section
    class="hero"
    style="background-image: url('{{ picasso_image_url('hero.jpg', width=1440, format='jpg') }}'); background-image: {{ picasso_image_set(src='hero.jpg', width=1440) }};"
>
```

```css
background-image: image-set(
    url('/image/glide/filesystem/hero.jpg?w=1440&fm=avif…') 1x type('image/avif'),
    url('/image/glide/filesystem/hero.jpg?w=2880&fm=avif…') 2x type('image/avif'),
    url('/image/glide/filesystem/hero.jpg?w=1440&fm=webp…') 1x type('image/webp'),
    …
);
```

The first declaration is the fallback for browsers without `image-set()` and `type()`.

`width` and `height` are CSS pixels; each density multiplies them (`densities`, default `[1, 2]`), capped to the source width when it is known so the image is never upscaled. It takes the options of `picasso_image_url()` (`loader`, `transformer`, `quality`, `fit`, `modifiers`, `preset`, `context`, `route`…), and `src` may be omitted when the loader finds the image from the `context` (a VichUploader entity).

The URLs are percent-encoded where they could end the `url('…')` or the HTML attribute, so the value can be printed as is in a `style` attribute or a `<style>` element.

## Markup of your own: `picasso_image_data()`

`picasso_image_data()` returns the data the component renders (an `ImageRenderData`) instead of the HTML, with the same arguments as `picasso_image()`:

```twig
{% set image = picasso_image_data(src=product.image, preset='card') %}

<img
    src="{{ image.fallbackSrc }}"
    srcset="{{ image.fallbackSrcset }}"
    sizes="{{ image.sizes }}"
    width="{{ image.width }}"
    height="{{ image.height }}"
    class="product-card__image"
    alt="{{ product.name }}"
>
```

It is what Next.js calls `getImageProps()`. Outside Twig, `ImageHelperInterface::imageData()` returns the same object, also serializable to [JSON](/guides/json-api/).
