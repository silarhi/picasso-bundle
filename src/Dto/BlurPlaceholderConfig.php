<?php

namespace Silarhi\PicassoBundle\Dto;

/**
 * Immutable configuration for blur placeholder generation.
 */
final class BlurPlaceholderConfig
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly int $size = 10,
        public readonly int $blur = 50,
        public readonly int $quality = 30,
    ) {
    }
}
