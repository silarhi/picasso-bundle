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

namespace Silarhi\PicassoBundle\Service;

use Silarhi\PicassoBundle\Dto\ImageRenderData;

interface ImageHelperInterface
{
    /**
     * Generate a single image URL with named parameters.
     *
     * @param array<string, mixed> $context Extra context passed to the loader (e.g. entity, field for Vich).
     */
    public function imageUrl(
        string $path,
        ?int $width = null,
        ?int $height = null,
        ?string $format = null,
        ?int $quality = null,
        ?string $fit = null,
        ?int $blur = null,
        ?int $dpr = null,
        ?string $loader = null,
        ?string $transformer = null,
        array $context = [],
    ): string;

    /**
     * Compute all image rendering data (dimensions, sources, placeholder, loading attributes).
     *
     * Returns an immutable DTO suitable for both Twig component rendering and JSON API responses.
     *
     * @param array<string, mixed>       $context    Extra context for the loader
     * @param array<string, scalar|null> $attributes Extra HTML attributes (alt, class, …)
     */
    public function imageData(
        ?string $src = null,
        ?int $width = null,
        ?int $height = null,
        ?string $sizes = null,
        ?string $loader = null,
        ?string $transformer = null,
        ?int $quality = null,
        ?string $fit = null,
        string|bool|null $placeholder = null,
        ?string $placeholderData = null,
        bool $priority = false,
        ?string $loading = null,
        ?string $fetchPriority = null,
        bool $unoptimized = false,
        ?int $sourceWidth = null,
        ?int $sourceHeight = null,
        array $context = [],
        array $attributes = [],
    ): ImageRenderData;
}
