---
title: 'Custom loader'
description: 'Write your own loader, and let Glide serve its images.'
sidebar:
    order: 6
---

Create a custom loader by implementing `ImageLoaderInterface` and tagging it with `#[AsImageLoader]`:

```php
use Silarhi\PicassoBundle\Attribute\AsImageLoader;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;

#[AsImageLoader('s3')]
class S3Loader implements ImageLoaderInterface
{
    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        // Fetch from S3, return an Image DTO
    }
}
```

A loader that hands images over to other loaders, as a chain does, returns them with `Image::$loader` set to the name of the loader that loaded them: generated URLs then name that loader, which serves them.

If local transformers (like Glide) should serve your loader's images, implement `ServableLoaderInterface` instead. Its `getSource()` method returns the `ImageSourceInterface` all its originals are read from when a transformed image is requested: the loader name in the image URL is all that is needed to find the original again, so a servable loader reads from a single source. Two implementations ship with the bundle:

- `LocalImageSource` reads from a local directory (paths escaping it via `..` are treated as missing).
- `FlysystemImageSource` reads from a Flysystem storage. A storage that cannot be reached or answers with a transient error (`5xx`, `429`, a timeout) is not a missing file: it throws an `ImageSourceUnavailableException`, answered with a `503` (see [Error Responses](/reference/routes/#error-responses)).

Any other storage works by implementing the two methods of `ImageSourceInterface` yourself, without writing a Flysystem adapter. Throw `ImageNotFoundException` for a missing file, and `ImageSourceUnavailableException` when the storage cannot tell right now:

```php
use Silarhi\PicassoBundle\Attribute\AsImageLoader;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Source\ImageSourceInterface;

final class BlobImageSource implements ImageSourceInterface
{
    public function __construct(private BlobRepository $blobs) {}

    public function exists(string $path): bool
    {
        return $this->blobs->has($path);
    }

    public function readStream(string $path)
    {
        return $this->blobs->openStream($path)
            ?? throw new ImageNotFoundException(sprintf('Blob "%s" not found.', $path));
    }
}

#[AsImageLoader('blob')]
final class BlobLoader implements ServableLoaderInterface
{
    public function __construct(private BlobImageSource $source) {}

    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        $path = $reference->path ?? '';

        return new Image(path: $path, stream: fn () => $this->source->readStream($path));
    }

    public function getSource(): ImageSourceInterface
    {
        return $this->source;
    }
}
```
