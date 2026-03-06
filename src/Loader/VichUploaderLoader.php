<?php

declare(strict_types=1);

/*
 * This file is part of the Picasso Bundle package.
 *
 * (c) SILARHI <dev@silarhi.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Silarhi\PicassoBundle\Loader;

use function is_object;
use function is_string;

use League\Flysystem\FilesystemOperator;
use LogicException;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Vich\UploaderBundle\Storage\StorageInterface;

final class VichUploaderLoader implements ServableLoaderInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly VichMappingHelperInterface $mappingHelper,
        private readonly FlysystemRegistry $flysystemRegistry,
    ) {
    }

    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        $entity = $reference->context['entity'] ?? null;

        if (!is_object($entity)) {
            return new Image(path: ltrim($reference->path ?? '', '/'));
        }

        $field = $reference->context['field'] ?? null;
        $field = is_string($field) ? $field : null;
        $fileProperty = $this->mappingHelper->getFilePropertyName($entity, $field);

        if (null === $fileProperty) {
            return new Image(path: ltrim($reference->path ?? '', '/'));
        }

        $path = $this->storage->resolvePath($entity, $fileProperty, null, true);
        $uploadDestination = $this->mappingHelper->getUploadDestination($entity, $field);
        $metadata = null !== $uploadDestination ? ['upload_destination' => $uploadDestination] : [];

        $width = null;
        $height = null;
        $mimeType = null;

        if ($withMetadata) {
            $dimensions = $this->mappingHelper->readDimensions($entity, $field);
            if (null !== $dimensions) {
                [$width, $height] = $dimensions;
            }
            $mimeType = $this->mappingHelper->readMimeType($entity, $field);
        }

        return new Image(
            path: ltrim($path ?? '', '/'),
            stream: fn () => $this->storage->resolveStream($entity, $fileProperty),
            width: $width,
            height: $height,
            mimeType: $mimeType,
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $metadata */
    public function getSource(array $metadata): FilesystemOperator|string
    {
        $uploadDestination = $metadata['upload_destination'] ?? null;
        if (!is_string($uploadDestination)) {
            throw new LogicException('Upload destination is required to get the source.');
        }

        if ($this->flysystemRegistry->has($uploadDestination)) {
            return $this->flysystemRegistry->get($uploadDestination);
        }

        return $uploadDestination;
    }
}
