<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Loader;

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
}
