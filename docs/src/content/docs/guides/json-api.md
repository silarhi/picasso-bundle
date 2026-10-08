---
title: 'Images in a JSON API'
description: 'Generate image URLs and full responsive image data from PHP, for controllers and headless frontends.'
sidebar:
    order: 7
---

The `picasso_image_url()` Twig function delegates to `ImageHelperInterface`, which you can also inject directly in your PHP code:

```php
use Silarhi\PicassoBundle\Service\ImageHelperInterface;

class MyController
{
    public function __construct(private ImageHelperInterface $imageHelper) {}

    public function index(): Response
    {
        $url = $this->imageHelper->imageUrl(
            path: 'photo.jpg',
            width: 300,
            format: 'webp',
        );

        // ...
    }
}
```

## Image Data for JSON APIs

The `imageData()` method returns an `ImageRenderData` DTO containing all
rendering data (sources, srcset, placeholder, dimensions, loading attributes)
plus the resolved loader, transformer and placeholder names.
It implements `JsonSerializable`, making it ideal for headless / API-driven
frontends (React, Vue, mobile apps, etc.):

```php
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class ImageApiController
{
    public function __construct(private ImageHelperInterface $imageHelper) {}

    public function show(): JsonResponse
    {
        $data = $this->imageHelper->imageData(
            src: 'hero.jpg',
            width: 1200,
            height: 800,
            sizes: '100vw',
            placeholder: true,
        );

        return new JsonResponse($data);
    }
}
```

The JSON response contains everything a frontend needs to render a responsive `<picture>` element:

```json
{
    "fallbackSrc": "/image/glide/filesystem/hero.jpg?w=1200&h=800&fm=jpg&s=...",
    "fallbackSrcset": "/image/glide/.../hero.jpg?w=640&fm=jpg&s=... 640w, ... 1920w",
    "sources": [
        { "type": "image/avif", "srcset": "..." },
        { "type": "image/webp", "srcset": "..." }
    ],
    "placeholderUri": "data:image/jpeg;base64,...",
    "width": 1200,
    "height": 800,
    "loading": "lazy",
    "fetchPriority": null,
    "sizes": "100vw",
    "unoptimized": false,
    "attributes": {},
    "loader": "filesystem",
    "transformer": "glide",
    "placeholder": "blur"
}
```

`imageData()` accepts the same parameters as the `<twig:Picasso:Image>` Twig component (`src`, `width`, `height`, `sizes`, `quality`, `fit`, `placeholder`, `priority`, `loader`, `transformer`, etc.).
