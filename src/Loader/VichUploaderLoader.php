<?php

namespace Silarhi\PicassoBundle\Loader;

use Vich\UploaderBundle\Templating\Helper\UploaderHelperInterface;

class VichUploaderLoader implements LoaderInterface
{
    public function __construct(
        private readonly UploaderHelperInterface $uploaderHelper,
    ) {
    }

    public function resolvePath(string|object $source, ?string $field = null): string
    {
        if (\is_string($source)) {
            return ltrim($source, '/');
        }

        $path = $this->uploaderHelper->asset($source, $field);

        return ltrim($path ?? '', '/');
    }

    public function getDimensions(string|object $source, ?string $field = null): ?array
    {
        if (!\is_object($source)) {
            return null;
        }

        $fieldName = $field ?? 'image';

        // Try get<Field>Dimensions() method (individual properties approach)
        $dimensionsGetter = 'get'.ucfirst($fieldName).'Dimensions';
        if (method_exists($source, $dimensionsGetter)) {
            $dims = $source->$dimensionsGetter();
            if (\is_array($dims) && \count($dims) === 2) {
                return [(int) $dims[0], (int) $dims[1]];
            }
        }

        // Try embedded File object approach
        $fileGetter = 'get'.ucfirst($fieldName);
        if (method_exists($source, $fileGetter)) {
            $file = $source->$fileGetter();
            if ($file !== null && method_exists($file, 'getDimensions')) {
                $dims = $file->getDimensions();
                if (\is_array($dims) && \count($dims) === 2) {
                    return [(int) $dims[0], (int) $dims[1]];
                }
            }
        }

        return null;
    }
}
