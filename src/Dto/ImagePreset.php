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
 * A named set of rendering options (the `presets` configuration), applied by the `preset` option of the
 * component, the Twig functions and ImageHelperInterface. Options given explicitly win over the preset's.
 */
final readonly class ImagePreset
{
    /**
     * @param array<string, int|string> $modifiers Merged under the modifiers given explicitly
     */
    public function __construct(
        public ?int $width = null,
        public ?int $height = null,
        public ?string $sizes = null,
        public ?int $quality = null,
        public ?string $fit = null,
        public string|bool|null $placeholder = null,
        public ?string $loader = null,
        public ?string $transformer = null,
        public array $modifiers = [],
    ) {
    }
}
