<?php

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\ImageDimensions;
use Silarhi\PicassoBundle\Dto\LoaderContext;

interface LoaderInterface
{
    /**
     * Resolves the source path for the image processing backend.
     */
    public function resolvePath(LoaderContext $context): string;

    /**
     * Returns the dimensions of the source image, or null if unknown.
     */
    public function getDimensions(LoaderContext $context): ?ImageDimensions;
}
