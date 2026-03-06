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

final readonly class Image
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ?string $path = null,
        public ?string $url = null,
        /** @var resource|null */
        public mixed $stream = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $mimeType = null,
        public array $metadata = [],
    ) {
    }
}
