<?php

namespace Silarhi\PicassoBundle\Dto;

final class ImageTransformation
{
    public function __construct(
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?string $format = null,
        public readonly int $quality = 75,
        public readonly string $fit = 'contain',
        public readonly ?int $blur = null,
        public readonly ?int $dpr = null,
    ) {
    }
}
