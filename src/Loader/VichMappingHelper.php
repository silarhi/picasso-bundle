<?php

namespace Silarhi\PicassoBundle\Loader;

use Vich\UploaderBundle\Mapping\PropertyMappingFactory;

class VichMappingHelper
{
    public function __construct(
        private readonly PropertyMappingFactory $factory,
    ) {
    }

    /**
     * Resolves the file property name for an entity, optionally given a field.
     * When field is null, auto-detects from the entity's first VichUploader mapping.
     */
    public function getFilePropertyName(object $entity, ?string $field): ?string
    {
        if ($field !== null) {
            $mapping = $this->factory->fromField($entity, $field);

            return $mapping?->getFilePropertyName();
        }

        $mappings = $this->factory->fromObject($entity);

        return isset($mappings[0]) ? $mappings[0]->getFilePropertyName() : null;
    }
}
