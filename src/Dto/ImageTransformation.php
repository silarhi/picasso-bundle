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

final readonly class ImageTransformation
{
    public function __construct(
        public ?int $width = null,
        public ?int $height = null,
        public ?string $format = null,
        public ?int $quality = 75,
        public string $fit = 'contain',
        public ?int $blur = null,
        public ?int $dpr = null,
    ) {
    }
}
