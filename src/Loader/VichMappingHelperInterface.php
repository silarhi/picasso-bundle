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

/**
 * @phpstan-type ImageDimensions array{0: int, 1: int}
 */
interface VichMappingHelperInterface
{
    /**
     * Resolves the file property name for an entity, optionally given a field.
     * When field is null, auto-detects from the entity's first VichUploader mapping.
     */
    public function getFilePropertyName(object $entity, ?string $field): ?string;

    /**
     * Returns the upload destination directory for an entity's mapping.
     */
    public function getUploadDestination(object $entity, ?string $field): ?string;

    /**
     * Reads the mime type from the entity's mapped property.
     */
    public function readMimeType(object $entity, ?string $field): ?string;

    /**
     * Reads the dimensions from the entity's mapped property.
     *
     * @return ImageDimensions|null [width, height] or null
     */
    public function readDimensions(object $entity, ?string $field): ?array;
}
