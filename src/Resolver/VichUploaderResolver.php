<?php

namespace Silarhi\PicassoBundle\Resolver;

use Silarhi\PicassoBundle\Dto\ResolvedImage;
use Vich\UploaderBundle\Storage\StorageInterface;

class VichUploaderResolver implements ImageResolverInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
    ) {
    }

    public function resolve(string $source, array $context = []): ResolvedImage
    {
        $entity = $context['entity'] ?? null;
        $field = $context['field'] ?? null;

        if ($entity !== null && $field !== null) {
            $path = $this->storage->resolvePath($entity, $field, null, true);

            return new ResolvedImage(
                ltrim($path ?? '', '/'),
                ...self::detectDimensions($entity, $field),
            );
        }

        return new ResolvedImage(ltrim($source, '/'));
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    private static function detectDimensions(object $entity, string $field): array
    {
        // Try get<Field>Dimensions() method
        $dimensionsGetter = 'get'.ucfirst($field).'Dimensions';
        if (method_exists($entity, $dimensionsGetter)) {
            $dims = $entity->$dimensionsGetter();
            if (\is_array($dims) && \count($dims) === 2) {
                return ['width' => (int) $dims[0], 'height' => (int) $dims[1]];
            }
        }

        // Try embedded File object approach
        $fileGetter = 'get'.ucfirst($field);
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
