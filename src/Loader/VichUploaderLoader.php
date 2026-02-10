<?php

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\ImageDimensions;
use Silarhi\PicassoBundle\Dto\LoaderContext;
use Vich\UploaderBundle\Storage\StorageInterface;

class VichUploaderLoader implements LoaderInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
    ) {
    }

    public function resolvePath(LoaderContext $context): string
    {
        if (!$context->isEntity()) {
            return ltrim($context->getSourceAsString(), '/');
        }

        // Get the relative filesystem path within the storage directory.
        // This is what Glide (or any local processor) needs to locate the source file.
        $path = $this->storage->resolvePath($context->source, $context->field, null, true);

        return ltrim($path ?? '', '/');
    }

    public function getDimensions(LoaderContext $context): ?ImageDimensions
    {
        if (!$context->isEntity()) {
            return null;
        }

        $fieldName = $context->field ?? 'image';
        $source = $context->source;

        // Try get<Field>Dimensions() method (individual properties approach)
        $dimensionsGetter = 'get'.ucfirst($fieldName).'Dimensions';
        if (method_exists($source, $dimensionsGetter)) {
            $dims = $source->$dimensionsGetter();
            if (\is_array($dims) && \count($dims) === 2) {
                return new ImageDimensions((int) $dims[0], (int) $dims[1]);
            }
        }

        // Try embedded File object approach
        $fileGetter = 'get'.ucfirst($fieldName);
        if (method_exists($source, $fileGetter)) {
            $file = $source->$fileGetter();
            if ($file !== null && method_exists($file, 'getDimensions')) {
                $dims = $file->getDimensions();
                if (\is_array($dims) && \count($dims) === 2) {
                    return new ImageDimensions((int) $dims[0], (int) $dims[1]);
                }
            }
        }

        return null;
    }
}
