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
    /**
     * @param array<string, int|string> $modifiers Further manipulations, in Glide's vocabulary (e.g. ['flip' => 'h',
     *                                             'sharp' => 10]): Glide reads them as they are, other transformers
     *                                             translate them
     */
    public function __construct(
        public ?int $width = null,
        public ?int $height = null,
        public ?string $format = null,
        public ?int $quality = null,
        public ?string $fit = null,
        public ?int $blur = null,
        public ?int $dpr = null,
        public array $modifiers = [],
    ) {
    }
}
