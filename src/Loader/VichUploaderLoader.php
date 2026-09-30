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

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Exception\InvalidImageReferenceException;
use Silarhi\PicassoBundle\Source\FlysystemImageSource;
use Silarhi\PicassoBundle\Source\ImageSourceInterface;
use Silarhi\PicassoBundle\Source\LocalImageSource;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Reads the images of one VichUploader mapping.
 *
 * The upload field is found on the entity from the mapping, so the "field"
 * context key is only needed when an entity has several fields using it.
 */
final readonly class VichUploaderLoader implements ServableLoaderInterface
{
    /**
     * @param string $mapping           VichUploader mapping served by this loader
     * @param string $uploadDestination The mapping's upload destination: a local directory or a Flysystem storage name
     */
    public function __construct(
        private StorageInterface $storage,
        private VichMappingHelperInterface $mappingHelper,
        private string $mapping,
        private string $uploadDestination,
        private ?FlysystemRegistry $flysystemRegistry = null,
    ) {
    }

    /**
     * @throws InvalidImageReferenceException When the entity has no field using this loader's mapping,
     *                                        several of them and no "field" context key, or when the
     *                                        given field uses another mapping
     */
    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        $entity = $reference->context['entity'] ?? null;

        if (!is_object($entity)) {
            return new Image(path: ltrim($reference->path ?? '', '/'));
        }

        $field = $reference->context['field'] ?? null;
        $field = $this->mappingHelper->resolveField($entity, $this->mapping, is_string($field) ? $field : null);

        $path = $this->storage->resolvePath($entity, $field, null, true);

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
            stream: fn () => $this->storage->resolveStream($entity, $field),
            width: $width,
            height: $height,
            mimeType: $mimeType,
        );
    }

    public function getSource(): ImageSourceInterface
    {
        if (null !== $this->flysystemRegistry && $this->flysystemRegistry->has($this->uploadDestination)) {
            return new FlysystemImageSource($this->flysystemRegistry->get($this->uploadDestination));
        }

        return new LocalImageSource($this->uploadDestination);
    }
}
