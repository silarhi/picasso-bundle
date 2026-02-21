<?php

namespace Silarhi\PicassoBundle\Dto;

final class Image
{
    public function __construct(
        public readonly ?string $path = null,
        public readonly ?string $url = null,
        /** @var resource|null */
        public readonly mixed $stream = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?string $mimeType = null,
    ) {
    }
}
