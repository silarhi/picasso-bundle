<?php

namespace Silarhi\PicassoBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class AsImageResolver
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}
