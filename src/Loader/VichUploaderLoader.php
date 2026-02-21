<?php

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Vich\UploaderBundle\Storage\StorageInterface;

class VichUploaderLoader implements ServableLoaderInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly VichMappingHelper $mappingHelper,
        private readonly object|string|null $source = null,
    ) {
    }

    public function load(ImageReference $reference, bool $withMetadata = true): Image
    {
        $entity = $reference->context['entity'] ?? null;

        if ($entity === null) {
            return new Image(path: ltrim($reference->path ?? '', '/'));
        }

        $field = $reference->context['field'] ?? null;
        $fileProperty = $this->mappingHelper->getFilePropertyName($entity, $field);

        if ($fileProperty === null) {
            return new Image(path: ltrim($reference->path ?? '', '/'));
        }

        $path = $this->storage->resolvePath($entity, $fileProperty, null, true);

        if (!$withMetadata) {
            return new Image(path: ltrim($path ?? '', '/'));
        }

        if (isset($reference->context['sourceWidth'], $reference->context['sourceHeight'])) {
            return new Image(
                path: ltrim($path ?? '', '/'),
                width: $reference->context['sourceWidth'],
                height: $reference->context['sourceHeight'],
                mimeType: $reference->context['mimeType'] ?? null,
            );
        }

        $dimensions = self::detectDimensions($entity, $fileProperty);

        return new Image(
            path: ltrim($path ?? '', '/'),
            width: $dimensions['width'],
            height: $dimensions['height'],
        );
    }

    public function getSource(): object|string
    {
        if ($this->source === null) {
            throw new \LogicException('VichUploaderLoader cannot serve images without a configured source. Set "source" in the vich loader configuration.');
        }

        return $this->source;
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    private static function detectDimensions(object $entity, string $fileProperty): array
    {
        $dimensionsGetter = 'get'.ucfirst($fileProperty).'Dimensions';
        if (method_exists($entity, $dimensionsGetter)) {
            $dims = $entity->$dimensionsGetter();
            if (\is_array($dims) && \count($dims) === 2) {
                return ['width' => (int) $dims[0], 'height' => (int) $dims[1]];
            }
        }

        $fileGetter = 'get'.ucfirst($fileProperty);
        if (method_exists($entity, $fileGetter)) {
            $file = $entity->$fileGetter();
            if ($file !== null && method_exists($file, 'getDimensions')) {
                $dims = $file->getDimensions();
                if (\is_array($dims) && \count($dims) === 2) {
                    return ['width' => (int) $dims[0], 'height' => (int) $dims[1]];
                }
            }
        }

        return ['width' => null, 'height' => null];
    }
}
