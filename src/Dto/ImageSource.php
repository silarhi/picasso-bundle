<?php

declare(strict_types=1);

/*
 * This file is part of the Picasso Bundle package.
 *
 * (c) SILARHI <dev@silarhi.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Silarhi\PicassoBundle\Dto;

/**
 * Immutable value object representing a <source> element inside a <picture> tag.
 */
final readonly class ImageSource
{
    /**
     * @param string|null $media  Media query of an art-directed source; null for the sources of the image itself
     * @param string|null $sizes  Sizes of an art-directed source; null uses the sizes of the image
     * @param int|null    $width  Display width of an art-directed source, set together with its height
     * @param int|null    $height Display height of an art-directed source, set together with its width
     */
    public function __construct(
        public string $type,
        public string $srcset,
        public ?string $media = null,
        public ?string $sizes = null,
        public ?int $width = null,
        public ?int $height = null,
    ) {
    }
}
