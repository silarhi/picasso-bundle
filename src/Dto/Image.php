<?php

declare(strict_types=1);

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
