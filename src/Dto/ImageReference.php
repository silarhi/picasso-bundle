<?php

namespace Silarhi\PicassoBundle\Dto;

final class ImageReference
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly ?string $path = null,
        public readonly array $context = [],
    ) {
    }
}
