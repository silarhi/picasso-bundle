<?php

namespace Silarhi\PicassoBundle\Dto;

/**
 * Immutable value object representing image dimensions in pixels.
 */
final class ImageDimensions
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
    }
}
