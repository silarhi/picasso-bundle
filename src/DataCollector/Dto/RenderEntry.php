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

/**
 * Loader, transformer and placeholder hold resolved names from ImageRenderData,
 * not the raw call arguments (null when unoptimized or no placeholder applies).
 */
final readonly class RenderEntry
{
    public function __construct(
        public ?string $src,
        public ?string $loader,
        public ?string $transformer,
        public ?string $placeholder,
        public ?int $width,
        public ?int $height,
        public bool $priority,
        public bool $unoptimized,
        public float $duration,
        public int $sourcesCount,
        public bool $hasPlaceholder,
    ) {
    }
}
