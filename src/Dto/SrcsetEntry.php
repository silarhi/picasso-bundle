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
        return $this->url . ' ' . $this->descriptor;
    }
}
