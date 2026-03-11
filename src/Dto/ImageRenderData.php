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

use JsonSerializable;

/**
 * Immutable result of image rendering computation.
 *
 * Contains all data needed to render a responsive <picture> element
 * or to expose image metadata via a JSON API.
 */
final readonly class ImageRenderData implements JsonSerializable
{
    /**
     * @param ImageSource[]              $sources    <source> elements for the <picture> tag
     * @param array<string, scalar|null> $attributes Extra HTML attributes (alt, class, …)
     */
    public function __construct(
        public ?string $fallbackSrc,
        public ?string $fallbackSrcset,
        public array $sources,
        public ?string $placeholderUri,
        public ?int $width,
        public ?int $height,
        public string $loading,
        public ?string $fetchPriority,
        public ?string $sizes,
        public bool $unoptimized,
        public array $attributes = [],
    ) {
    }

    /**
     * @return array{
     *     fallbackSrc: string|null,
     *     fallbackSrcset: string|null,
     *     sources: list<array{type: string, srcset: string}>,
     *     placeholderUri: string|null,
     *     width: int|null,
     *     height: int|null,
     *     loading: string,
     *     fetchPriority: string|null,
     *     sizes: string|null,
     *     unoptimized: bool,
     *     attributes: array<string, scalar|null>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'fallbackSrc' => $this->fallbackSrc,
            'fallbackSrcset' => $this->fallbackSrcset,
            'sources' => array_values(array_map(
                static fn (ImageSource $source): array => [
                    'type' => $source->type,
                    'srcset' => $source->srcset,
                ],
                $this->sources,
            )),
            'placeholderUri' => $this->placeholderUri,
            'width' => $this->width,
            'height' => $this->height,
            'loading' => $this->loading,
            'fetchPriority' => $this->fetchPriority,
            'sizes' => $this->sizes,
            'unoptimized' => $this->unoptimized,
            'attributes' => $this->attributes,
        ];
    }
}
