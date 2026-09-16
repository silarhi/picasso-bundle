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

use Vich\UploaderBundle\Mapping\PropertyMappingFactory;

/**
 * @phpstan-import-type ImageDimensions from VichMappingHelperInterface
 *
 * Resolved mappings are deliberately kept in local variables rather than returned
 * from a shared private method: VichUploader 2.x resolves them to PropertyMapping
 * while 3.x resolves them to PropertyMappingInterface, and neither type can be
 * named in a signature that works on both. Every method called here exists on both.
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
        $mapping = null !== $field
            ? $this->factory->fromField($entity, $field)
            : ($this->factory->fromObject($entity)[0] ?? null);

        return $mapping?->getFilePropertyName();
    }

    /**
     * Returns the upload destination directory for an entity's mapping.
     */
    public function getUploadDestination(object $entity, ?string $field): ?string
    {
        $mapping = null !== $field
            ? $this->factory->fromField($entity, $field)
            : ($this->factory->fromObject($entity)[0] ?? null);

        return $mapping?->getUploadDestination();
    }

    public function readMimeType(object $entity, ?string $field): ?string
    {
        $value = $this->readMappedProperty($entity, $field, 'mimeType');

        return is_string($value) ? $value : null;
    }

    public function readDimensions(object $entity, ?string $field): ?array
    {
        $value = $this->readMappedProperty($entity, $field, 'dimensions');

        if (!is_array($value) || !isset($value[0], $value[1]) || !is_numeric($value[0]) || !is_numeric($value[1])) {
            return null;
        }

        return [(int) $value[0], (int) $value[1]];
    }

    /**
     * Reads a property from the entity's mapping, or null when no mapping matches.
     */
    private function readMappedProperty(object $entity, ?string $field, string $property): mixed
    {
        $mapping = null !== $field
            ? $this->factory->fromField($entity, $field)
            : ($this->factory->fromObject($entity)[0] ?? null);

        return $mapping?->readProperty($entity, $property);
    }
}
