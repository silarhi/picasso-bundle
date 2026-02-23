<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Dto;

final readonly class ImageTransformation
{
    public function __construct(
        public ?int $width = null,
        public ?int $height = null,
        public ?string $format = null,
        public int $quality = 75,
        public string $fit = 'contain',
        public ?int $blur = null,
        public ?int $dpr = null,
    ) {
    }
}
