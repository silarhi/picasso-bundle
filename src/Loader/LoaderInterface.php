<?php

namespace Silarhi\PicassoBundle\Loader;

interface LoaderInterface
{
    /**
     * Resolves the source path that Glide can use to locate the image.
     *
     * @param string|object $source A file path string or an entity object (for VichUploader)
     * @param string|null   $field  The uploadable field name (only for entity-based loaders)
     */
    public function resolvePath(string|object $source, ?string $field = null): string;

    /**
     * Returns [width, height] of the source image, or null if unknown.
     *
     * @return array{0: int, 1: int}|null
     */
    public function getDimensions(string|object $source, ?string $field = null): ?array;
}
