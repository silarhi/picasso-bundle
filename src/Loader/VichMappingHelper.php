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

use function is_array;
use function is_string;

use Vich\UploaderBundle\Mapping\PropertyMapping;
use Vich\UploaderBundle\Mapping\PropertyMappingFactory;

/**
 * @phpstan-import-type ImageDimensions from VichMappingHelperInterface
 */
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

    public function readMimeType(object $entity, ?string $field): ?string
    {
        $mapping = $this->getMapping($entity, $field);
        if (!$mapping instanceof PropertyMapping) {
            return null;
        }

        $value = $mapping->readProperty($entity, 'mimeType');

        return is_string($value) ? $value : null;
    }

    public function readDimensions(object $entity, ?string $field): ?array
    {
        $mapping = $this->getMapping($entity, $field);
        if (!$mapping instanceof PropertyMapping) {
            return null;
        }

        $value = $mapping->readProperty($entity, 'dimensions');

        if (!is_array($value) || !isset($value[0], $value[1]) || !is_numeric($value[0]) || !is_numeric($value[1])) {
            return null;
        }

        return [(int) $value[0], (int) $value[1]];
    }

    private function getMapping(object $entity, ?string $field): ?PropertyMapping
    {
        if (null !== $field) {
            return $this->factory->fromField($entity, $field);
        }

        $mappings = $this->factory->fromObject($entity);

        return $mappings[0] ?? null;
    }
}
