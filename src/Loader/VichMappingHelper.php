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

use function count;
use function is_array;
use function is_string;

use Silarhi\PicassoBundle\Exception\InvalidImageReferenceException;

use function sprintf;

use Vich\UploaderBundle\Exception\NotUploadableException;
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

    public function resolveField(object $entity, string $mapping, ?string $field): string
    {
        try {
            if (null !== $field) {
                $propertyMapping = $this->factory->fromField($entity, $field);

                if (null === $propertyMapping) {
                    throw new InvalidImageReferenceException(sprintf('"%s::$%s" is not a VichUploader upload field.', $entity::class, $field));
                }

                if ($mapping !== $propertyMapping->getMappingName()) {
                    throw new InvalidImageReferenceException(sprintf('"%s::$%s" uses the VichUploader mapping "%s", but this loader serves the mapping "%s". Use the loader configured for "%s".', $entity::class, $field, $propertyMapping->getMappingName(), $mapping, $propertyMapping->getMappingName()));
                }

                return $propertyMapping->getFilePropertyName();
            }

            $fields = [];
            foreach ($this->factory->fromObject($entity, null, $mapping) as $propertyMapping) {
                $fields[] = $propertyMapping->getFilePropertyName();
            }
        } catch (NotUploadableException $e) {
            throw new InvalidImageReferenceException(sprintf('"%s" is not a VichUploader uploadable class.', $entity::class), $e->getCode(), previous: $e);
        }

        if ([] === $fields) {
            throw new InvalidImageReferenceException(sprintf('"%s" has no VichUploader field using the mapping "%s".', $entity::class, $mapping));
        }

        if (count($fields) > 1) {
            throw new InvalidImageReferenceException(sprintf('"%s" has several fields using the VichUploader mapping "%s" (%s). Pass the one to use as the "field" context key.', $entity::class, $mapping, implode(', ', $fields)));
        }

        return $fields[0];
    }

    public function readMimeType(object $entity, string $field): ?string
    {
        $value = $this->factory->fromField($entity, $field)?->readProperty($entity, 'mimeType');

        return is_string($value) ? $value : null;
    }

    public function readDimensions(object $entity, string $field): ?array
    {
        $value = $this->factory->fromField($entity, $field)?->readProperty($entity, 'dimensions');

        if (!is_array($value) || !isset($value[0], $value[1]) || !is_numeric($value[0]) || !is_numeric($value[1])) {
            return null;
        }

        return [(int) $value[0], (int) $value[1]];
    }
}
