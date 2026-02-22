<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Loader;

use Vich\UploaderBundle\Mapping\PropertyMappingFactory;

final readonly class VichMappingHelper implements VichMappingHelperInterface
{
    public function __construct(
        private PropertyMappingFactory $factory,
    ) {
    }

    /**
     * Resolves the file property name for an entity, optionally given a field.
     * When field is null, auto-detects from the entity's first VichUploader mapping.
     */
    public function getFilePropertyName(object $entity, ?string $field): ?string
    {
        if (null !== $field) {
            $mapping = $this->factory->fromField($entity, $field);

            return $mapping?->getFilePropertyName();
        }

        $mappings = $this->factory->fromObject($entity);

        return isset($mappings[0]) ? $mappings[0]->getFilePropertyName() : null;
    }

    /**
     * Returns the upload destination directory for an entity's mapping.
     */
    public function getUploadDestination(object $entity, ?string $field): ?string
    {
        if (null !== $field) {
            $mapping = $this->factory->fromField($entity, $field);

            return $mapping?->getUploadDestination();
        }

        $mappings = $this->factory->fromObject($entity);

        return isset($mappings[0]) ? $mappings[0]->getUploadDestination() : null;
    }
}
