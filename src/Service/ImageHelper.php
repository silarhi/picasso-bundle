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

use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageTransformation;

final readonly class ImageHelper
{
    public function __construct(
        private ImagePipeline $pipeline,
        private ?int $defaultQuality,
        private ?string $defaultFit,
    ) {
    }

    /**
     * Generate a single image URL with named parameters.
     */
    /**
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
    ): string {
        $transformation = new ImageTransformation(
            width: $width,
            height: $height,
            format: $format,
            quality: $quality ?? $this->defaultQuality,
            fit: $fit ?? $this->defaultFit,
            blur: $blur,
            dpr: $dpr,
        );

        return $this->pipeline->url(new ImageReference($path, $context), $transformation, $loader, $transformer);
    }
}
