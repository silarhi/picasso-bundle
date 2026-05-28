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

namespace Silarhi\PicassoBundle\DataCollector\Dto;

final readonly class UrlEntry
{
    public function __construct(
        public string $src,
        public ?string $loader,
        public ?string $transformer,
        public ?int $width,
        public ?int $height,
        public ?string $format,
        public ?int $quality,
        public ?string $fit,
        public float $duration,
        public string $url,
    ) {
    }
}
