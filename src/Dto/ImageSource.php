<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Dto;

/**
 * Immutable value object representing a <source> element inside a <picture> tag.
 */
final readonly class ImageSource
{
    public function __construct(
        public string $type,
        public string $srcset,
    ) {
    }
}
