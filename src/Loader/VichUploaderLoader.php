<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;
use Vich\UploaderBundle\Storage\StorageInterface;

class VichUploaderLoader implements ServableLoaderInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly VichMappingHelperInterface $mappingHelper,
        private readonly MetadataGuesserInterface $metadataGuesser,
    ) {
    }

    public function load(ImageReference $reference, bool $withMetadata = false): Image
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

        $stream = null;

        try {
            $resolved = $this->storage->resolveStream($entity, $fileProperty);
            $stream = \is_resource($resolved) ? $resolved : null;
        } catch (\Throwable) {
            // Stream not available
        }

        $width = null;
        $height = null;
        $mimeType = null;

        if ($withMetadata && null !== $stream) {
            $guessed = $this->metadataGuesser->guess($stream);
            $width = $guessed['width'];
            $height = $guessed['height'];
            $mimeType = $guessed['mimeType'];
        }

        return new Image(
            path: ltrim($path ?? '', '/'),
            stream: $stream,
            width: $width,
            height: $height,
            mimeType: $mimeType,
            metadata: $metadata,
        );
    }

    public function getSource(): object|string
    {
        throw new \LogicException('VichUploaderLoader passes its source via encrypted URL metadata.');
    }
}
