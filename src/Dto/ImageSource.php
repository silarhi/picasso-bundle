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
 * Immutable value object representing a <source> element inside a <picture> tag.
 */
final readonly class ImageSource
{
    public function __construct(
        public string $type,
        public string $srcset,
    ) {
    }
}
