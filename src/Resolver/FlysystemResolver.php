<?php

namespace Silarhi\PicassoBundle\Resolver;

use Silarhi\PicassoBundle\Dto\ResolvedImage;

/**
 * Resolver for remote/abstract storage (Flysystem, S3, etc.).
 *
 * Accepts an optional "storage" key in context to identify
 * which Flysystem storage the image belongs to.
 *
 * Does not detect dimensions since files may not be available locally.
 */
class FlysystemResolver implements ImageResolverInterface
{
    public function resolve(string $source, array $context = []): ResolvedImage
    {
        return new ResolvedImage(ltrim($source, '/'));
    }
}
