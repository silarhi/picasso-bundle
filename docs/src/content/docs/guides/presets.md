---
title: 'Presets'
description: 'Name a set of rendering options once and reuse it in every template.'
sidebar:
    order: 9
---

The same image shapes come back across templates: product cards, avatars, heroes. A preset names their options once:

```yaml
# config/packages/picasso.yaml
picasso:
    presets:
        card:
            width: 400
            height: 300
            fit: cover
            sizes: '(max-width: 768px) 100vw, 400px'
        avatar:
            width: 64
            height: 64
            fit: crop
            placeholder: false
        og:
            width: 1200
            height: 630
            fit: crop
            quality: 80
```

Then name the preset where the image is rendered:

```twig
<twig:Picasso:Image src="{{ product.image }}" preset="card" alt="{{ product.name }}" />

{{ picasso_image(src=user.avatar, preset='avatar', attributes={alt: user.name}) }}

<meta property="og:image" content="{{ absolute_url(picasso_image_url(article.cover, preset='og', format='jpg')) }}">
```

`preset` works on the component, on every Twig function and on `ImageHelperInterface` (`imageUrl()`, `imageData()`), and in [art-direction](/guides/art-direction/) entries.

## What a preset sets

| Option        | Description                                                     |
| ------------- | --------------------------------------------------------------- |
| `width`       | Display width                                                   |
| `height`      | Display height                                                  |
| `sizes`       | Responsive `sizes` attribute                                    |
| `quality`     | Quality (1–100)                                                 |
| `fit`         | [Fit mode](/guides/fit-modes/), focal points included           |
| `placeholder` | `true`, `false`, or a placeholder name                          |
| `loader`      | Loader name                                                     |
| `transformer` | Transformer name                                                |
| `modifiers`   | [Modifiers](/guides/modifiers/), e.g. `{ sharp: 10 }`           |

`picasso_image_url()` and `imageUrl()` ignore `sizes` and `placeholder`, which only apply to a `<picture>`.

## Overriding a preset

Options given explicitly win over the preset's, and the preset's win over the loader and global defaults:

```twig
{# A card, wider #}
<twig:Picasso:Image src="{{ product.image }}" preset="card" width="600" alt="…" />
```

Modifiers are merged: the preset's apply, and those given explicitly replace them key by key.

An unknown preset name throws a `PresetNotFoundException` listing the configured ones.
