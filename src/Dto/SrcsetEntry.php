<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Dto;

/**
 * Immutable value object representing a single srcset entry (url + descriptor).
 */
final readonly class SrcsetEntry
{
    public function __construct(
        public string $url,
        public string $descriptor,
    ) {
    }

    public function toString(): string
    {
        return $this->url.' '.$this->descriptor;
    }
}
