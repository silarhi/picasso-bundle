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

use Silarhi\PicassoBundle\Exception\InvalidImageReferenceException;

/**
 * @phpstan-type ImageDimensions array{0: int, 1: int}
 */
interface VichMappingHelperInterface
{
    /**
     * Resolves the upload field of an entity that uses the given mapping.
     *
     * Without a field, the entity's only field using the mapping is returned.
     * A given field is checked to use the mapping.
     *
     * @return string The file property name (e.g. "imageFile")
     *
     * @throws InvalidImageReferenceException When the entity is not uploadable, has no field using the mapping,
     *                                        several of them and no field was given, or when the given
     *                                        field is not an upload field or uses another mapping
     */
    public function resolveField(object $entity, string $mapping, ?string $field): string;

    /**
     * Reads the mime type from the entity's mapped property.
     */
    public function readMimeType(object $entity, string $field): ?string;

    /**
     * Reads the dimensions from the entity's mapped property.
     *
     * @return ImageDimensions|null [width, height] or null
     */
    public function readDimensions(object $entity, string $field): ?array;
}
