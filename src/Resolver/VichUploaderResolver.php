<?php

namespace Silarhi\PicassoBundle\Resolver;

use Silarhi\PicassoBundle\Dto\ResolvedImage;
use Vich\UploaderBundle\Storage\StorageInterface;

class VichUploaderResolver implements ImageResolverInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly VichMappingHelper $mappingHelper,
    ) {
    }

    public function resolve(string $source, array $context = []): ResolvedImage
    {
        $entity = $context['entity'] ?? null;

        if ($entity === null) {
            return new ResolvedImage(ltrim($source, '/'));
        }

        $field = $context['field'] ?? null;
        $fileProperty = $this->mappingHelper->getFilePropertyName($entity, $field);

        if ($fileProperty === null) {
            return new ResolvedImage(ltrim($source, '/'));
        }

        $path = $this->storage->resolvePath($entity, $fileProperty, null, true);

        // Skip dimension detection when source dimensions are provided
        if (isset($context['sourceWidth'], $context['sourceHeight'])) {
            return new ResolvedImage(
                ltrim($path ?? '', '/'),
                $context['sourceWidth'],
                $context['sourceHeight'],
            );
        }

        return new ResolvedImage(
            ltrim($path ?? '', '/'),
            ...self::detectDimensions($entity, $fileProperty),
        );
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    private static function detectDimensions(object $entity, string $fileProperty): array
    {
        // Try get<FileProperty>Dimensions() method
        $dimensionsGetter = 'get'.ucfirst($fileProperty).'Dimensions';
        if (method_exists($entity, $dimensionsGetter)) {
            $dims = $entity->$dimensionsGetter();
            if (\is_array($dims) && \count($dims) === 2) {
                return ['width' => (int) $dims[0], 'height' => (int) $dims[1]];
            }
        }

        // Try embedded File object approach
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
