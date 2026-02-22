<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Vich\UploaderBundle\Storage\StorageInterface;

class VichUploaderLoader implements ServableLoaderInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly VichMappingHelper $mappingHelper,
    ) {
    }

    public function load(ImageReference $reference, bool $withMetadata = true): Image
    {
        $entity = $reference->context['entity'] ?? null;

        if (!\is_object($entity)) {
            return new Image(path: ltrim($reference->path ?? '', '/'));
        }

        $field = $reference->context['field'] ?? null;
        $field = \is_string($field) ? $field : null;
        $fileProperty = $this->mappingHelper->getFilePropertyName($entity, $field);

        if (null === $fileProperty) {
            return new Image(path: ltrim($reference->path ?? '', '/'));
        }

        $path = $this->storage->resolvePath($entity, $fileProperty, null, true);
        $uploadDestination = $this->mappingHelper->getUploadDestination($entity, $field);
        $metadata = null !== $uploadDestination ? ['_source' => $uploadDestination] : [];

        if (!$withMetadata) {
            return new Image(path: ltrim($path ?? '', '/'), metadata: $metadata);
        }

        if (isset($reference->context['sourceWidth'], $reference->context['sourceHeight'])) {
            $sw = $reference->context['sourceWidth'];
            $sh = $reference->context['sourceHeight'];
            $mt = $reference->context['mimeType'] ?? null;

            return new Image(
                path: ltrim($path ?? '', '/'),
                width: \is_int($sw) ? $sw : null,
                height: \is_int($sh) ? $sh : null,
                mimeType: \is_string($mt) ? $mt : null,
                metadata: $metadata,
            );
        }

        $dimensions = $this->detectDimensions($entity, $fileProperty);

        return new Image(
            path: ltrim($path ?? '', '/'),
            width: $dimensions['width'],
            height: $dimensions['height'],
            metadata: $metadata,
        );
    }

    public function getSource(): object|string
    {
        throw new \LogicException('VichUploaderLoader passes its source via encrypted URL metadata. Use the _source query parameter instead.');
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    private function detectDimensions(object $entity, string $fileProperty): array
    {
        $dimensionsGetter = 'get'.ucfirst($fileProperty).'Dimensions';
        if (method_exists($entity, $dimensionsGetter)) {
            $dims = $entity->$dimensionsGetter();
            if (\is_array($dims) && 2 === \count($dims)) {
                $w = $dims[0];
                $h = $dims[1];
                if ((\is_int($w) || \is_float($w)) && (\is_int($h) || \is_float($h))) {
                    return ['width' => (int) $w, 'height' => (int) $h];
                }
            }
        }

        $fileGetter = 'get'.ucfirst($fileProperty);
        if (method_exists($entity, $fileGetter)) {
            $file = $entity->$fileGetter();
            if (\is_object($file) && method_exists($file, 'getDimensions')) {
                $dims = $file->getDimensions();
                if (\is_array($dims) && 2 === \count($dims)) {
                    $w = $dims[0];
                    $h = $dims[1];
                    if ((\is_int($w) || \is_float($w)) && (\is_int($h) || \is_float($h))) {
                        return ['width' => (int) $w, 'height' => (int) $h];
                    }
                }
            }
        }

        return ['width' => null, 'height' => null];
    }
}
