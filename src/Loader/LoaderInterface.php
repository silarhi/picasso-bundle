<?php

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\LoaderContext;

interface LoaderInterface
{
    /**
     * Resolves the source path for the image processing backend.
     */
    public function resolvePath(LoaderContext $context): string;

    /**
     * Returns [width, height] of the source image, or null if unknown.
     *
     * @return array{0: int, 1: int}|null
     */
    public function getDimensions(LoaderContext $context): ?array;
}
