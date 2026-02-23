<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Dto;

final readonly class ImageReference
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public ?string $path = null,
        public array $context = [],
    ) {
    }
}
