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

namespace Silarhi\PicassoBundle\DataCollector;

use Override;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Silarhi\PicassoBundle\Service\ImagePipeline;

/**
 * Decorates ImageHelperInterface to record calls into the Picasso data collector.
 *
 * Only registered when the bundle's `collector` option is enabled.
 */
final readonly class CollectingImageHelper implements ImageHelperInterface
{
    public function __construct(
        private ImageHelperInterface $inner,
        private PicassoDataCollector $collector,
        private ImagePipeline $pipeline,
    ) {
    }

    #[Override]
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
        $start = microtime(true);
        $url = $this->inner->imageUrl(
            path: $path,
            width: $width,
            height: $height,
            format: $format,
            quality: $quality,
            fit: $fit,
            blur: $blur,
            dpr: $dpr,
            loader: $loader,
            transformer: $transformer,
            context: $context,
        );
        $duration = (microtime(true) - $start) * 1000;

        // The inner call resolved these same names without throwing, so resolution cannot fail here.
        $this->collector->collectImageUrl(
            src: $path,
            loader: $this->pipeline->resolveLoaderName($loader),
            transformer: $this->pipeline->resolveTransformerName($transformer),
            transformation: new ImageTransformation(
                width: $width,
                height: $height,
                format: $format,
                quality: $quality,
                fit: $fit,
                blur: $blur,
                dpr: $dpr,
            ),
            url: $url,
            duration: $duration,
        );

        return $url;
    }

    #[Override]
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
        ?bool $resolveMetadata = null,
        array $context = [],
        array $attributes = [],
    ): ImageRenderData {
        $start = microtime(true);
        $data = $this->inner->imageData(
            src: $src,
            width: $width,
            height: $height,
            sizes: $sizes,
            loader: $loader,
            transformer: $transformer,
            quality: $quality,
            fit: $fit,
            placeholder: $placeholder,
            placeholderData: $placeholderData,
            priority: $priority,
            loading: $loading,
            fetchPriority: $fetchPriority,
            unoptimized: $unoptimized,
            sourceWidth: $sourceWidth,
            sourceHeight: $sourceHeight,
            resolveMetadata: $resolveMetadata,
            context: $context,
            attributes: $attributes,
        );
        $duration = (microtime(true) - $start) * 1000;

        $this->collector->collectImageRender(
            src: $src,
            data: $data,
            duration: $duration,
        );

        return $data;
    }
}
