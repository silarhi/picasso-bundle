<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class AsImageTransformer
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}
