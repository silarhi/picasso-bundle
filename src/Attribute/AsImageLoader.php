<?php

namespace Silarhi\PicassoBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class AsImageLoader
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}
