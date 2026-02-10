<?php

namespace Silarhi\PicassoBundle\Dto;

final class ResolvedImage
{
    public function __construct(
        public readonly string $path,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {
    }
}
