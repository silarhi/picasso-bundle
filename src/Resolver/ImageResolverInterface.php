<?php

namespace Silarhi\PicassoBundle\Resolver;

use Silarhi\PicassoBundle\Dto\ResolvedImage;

interface ImageResolverInterface
{
    /**
     * Resolve a logical source reference to a path the loader can use.
     *
     * @param string $source  The image source identifier (file path, asset name, etc.)
     * @param array  $context Arbitrary context (entity, field, storage name, etc.)
     */
    public function resolve(string $source, array $context = []): ResolvedImage;
}
