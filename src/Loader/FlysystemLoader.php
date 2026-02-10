<?php

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\ImageDimensions;
use Silarhi\PicassoBundle\Dto\LoaderContext;

/**
 * Pass-through loader for remote or abstract storage (Flysystem, S3, etc.).
 *
 * Returns the path as-is. Does not detect dimensions since files
 * may not be available locally.
 */
class FlysystemLoader implements LoaderInterface
{
    public function resolvePath(LoaderContext $context): string
    {
        return ltrim($context->getSourceAsString(), '/');
    }

    public function getDimensions(LoaderContext $context): ?ImageDimensions
    {
        return null;
    }
}
