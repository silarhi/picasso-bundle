---
title: 'Custom transformer'
description: 'Plug any image CDN or service in as a transformer.'
sidebar:
    order: 4
---

Create a custom transformer by implementing `ImageTransformerInterface`:

```php
use Silarhi\PicassoBundle\Attribute\AsImageTransformer;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;

#[AsImageTransformer('cloudinary')]
class CloudinaryTransformer implements ImageTransformerInterface
{
    public function url(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        // Build and return a Cloudinary URL
    }
}
```

Or register it via configuration:

```yaml
picasso:
    transformers:
        cloudinary:
            type: service
            service: 'App\Image\CloudinaryTransformer'
```
