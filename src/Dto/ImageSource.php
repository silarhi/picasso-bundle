<?php

namespace Silarhi\PicassoBundle\Dto;

/**
 * Immutable value object representing a <source> element inside a <picture> tag.
 */
final class ImageSource
{
    public function __construct(
        public readonly string $type,
        public readonly string $srcset,
    ) {
    }
}
