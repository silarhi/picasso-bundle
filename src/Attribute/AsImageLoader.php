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

namespace Silarhi\PicassoBundle\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsImageLoader
{
    public function __construct(
        public string $name,
        public ?string $defaultPlaceholder = null,
        public ?string $defaultTransformer = null,
        public ?bool $resolveMetadata = null,
    ) {
    }
}
