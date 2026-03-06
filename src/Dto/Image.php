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

use Closure;
use Throwable;

final readonly class Image
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ?string $path = null,
        public ?string $url = null,
        /** @var (Closure(): (resource|null))|resource|null */
        public mixed $stream = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $mimeType = null,
        public array $metadata = [],
    ) {
    }

    /**
     * Resolves the stream: invokes lazy closures and catches errors gracefully.
     *
     * @return resource|null
     */
    public function resolveStream()
    {
        try {
            return $this->stream instanceof Closure ? ($this->stream)() : $this->stream;
        } catch (Throwable) {
            return null;
        }
    }
}
