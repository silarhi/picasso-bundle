<?php

namespace Silarhi\PicassoBundle\Dto;

/**
 * Immutable value object representing a single srcset entry (url + descriptor).
 */
final class SrcsetEntry
{
    public function __construct(
        public readonly string $url,
        public readonly string $descriptor,
    ) {
    }

    public function toString(): string
    {
        return $this->url.' '.$this->descriptor;
    }
}
