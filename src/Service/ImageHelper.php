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

use function is_string;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @phpstan-import-type TransformerContext from ImageTransformerInterface
 */
final readonly class ImageHelper implements ImageHelperInterface
{
    /**
     * @param string[] $formats
     */
    public function __construct(
        private ImagePipeline $pipeline,
        private SrcsetGenerator $srcsetGenerator,
        private TransformerRegistry $transformerRegistry,
        private MetadataGuesserInterface $metadataGuesser,
        private PlaceholderRegistry $placeholderRegistry,
        private LoaderRegistry $loaderRegistry,
        private array $formats,
        private ?int $defaultQuality,
        private string $defaultFit,
        private ?string $defaultPlaceholder = null,
        private bool $defaultResolveMetadata = false,
        private ?Stopwatch $stopwatch = null,
    ) {
    }

    /**
     * Generate a single image URL with named parameters.
     *
     * @param array<string, mixed> $context         Extra context passed to the loader (e.g. entity, field for Vich).
     * @param string|null          $route           Application route serving the image (through ImageServer) instead of the bundle's
     * @param array<string, mixed> $routeParameters Parameters of that route
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
        ?string $route = null,
        array $routeParameters = [],
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

        return $this->pipeline->url(new ImageReference($path, $context), $transformation, $loader, $transformer, $route, $routeParameters);
    }

    /**
     * Compute all image rendering data (dimensions, sources, placeholder, loading attributes).
     *
     * Returns an immutable DTO suitable for both Twig component rendering and JSON API responses.
     *
     * @param array<string, mixed>       $context         Extra context for the loader
     * @param array<string, scalar|null> $attributes      Extra HTML attributes (alt, class, …)
     * @param string|null                $route           Application route serving the image (through ImageServer) instead of the bundle's
     * @param array<string, mixed>       $routeParameters Parameters of that route
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
        ?bool $resolveMetadata = null,
        array $context = [],
        array $attributes = [],
        ?string $route = null,
        array $routeParameters = [],
    ): ImageRenderData {
        $loading ??= $priority ? 'eager' : 'lazy';
        $fetchPriority ??= $priority ? 'high' : null;

        if ($unoptimized) {
            return new ImageRenderData(
                fallbackSrc: $src,
                fallbackSrcset: null,
                sources: [],
                placeholderUri: null,
                width: $width,
                height: $height,
                loading: $loading,
                fetchPriority: $fetchPriority,
                sizes: $sizes,
                unoptimized: true,
                attributes: $attributes,
            );
        }

        $loaderName = $this->pipeline->resolveLoaderName($loader);

        $effectiveResolveMetadata = $resolveMetadata
            ?? $this->loaderRegistry->getResolveMetadata($loaderName)
            ?? $this->defaultResolveMetadata;

        $reference = new ImageReference($src, $context);
        $hasAllDisplayDims = null !== $width && null !== $height;
        $hasAllSourceDims = null !== $sourceWidth && null !== $sourceHeight;
        // Explicit display dimensions need no source dimensions, except to cap srcset candidates to the
        // source width: loaders resolving metadata (filesystem by default, a cheap local read) still read them
        $needsMetadata = !$hasAllSourceDims && (!$hasAllDisplayDims || $effectiveResolveMetadata);
        $image = $this->pipeline->load($reference, $loader, $needsMetadata);

        [$width, $height, $resolvedSourceWidth, $resolvedSourceHeight] = $this->resolveDimensions(
            $image, $loaderName, $width, $height, $sourceWidth, $sourceHeight, $effectiveResolveMetadata,
        );

        $transformerName = $this->resolveTransformerName($transformer, $loaderName);
        $imageTransformer = $this->transformerRegistry->get($transformerName);
        // A delegating loader (e.g. a chain) names the loader that loaded the image, which serves it
        $servingLoader = $image->loader ?? $loaderName;
        $transformerContext = (new TransformerContextFactory($this->loaderRegistry, $this->transformerRegistry))
            ->create($image, $loaderName, $transformerName, $route, $routeParameters);

        $placeholderName = $this->resolvePlaceholderName($placeholder, $loaderName);
        $placeholderUri = $this->generatePlaceholder(
            $image, $transformerContext,
            $priority, $placeholderData, $placeholderName,
            $width, $height, $quality, $fit,
        );

        [$sources, $fallbackSrc, $fallbackSrcset] = $this->generateSources(
            $imageTransformer, $image, $transformerContext,
            $resolvedSourceWidth, $resolvedSourceHeight, $width, $height, $sizes, $quality, $fit,
        );

        return new ImageRenderData(
            fallbackSrc: $fallbackSrc,
            fallbackSrcset: $fallbackSrcset,
            sources: $sources,
            placeholderUri: $placeholderUri,
            width: $width,
            height: $height,
            loading: $loading,
            fetchPriority: $fetchPriority,
            sizes: $sizes,
            unoptimized: false,
            attributes: $attributes,
            loader: $servingLoader,
            transformer: $transformerName,
            placeholder: $placeholderName,
        );
    }

    /**
     * Resolve source and display dimensions from explicit props, loader metadata, or stream detection.
     *
     * @return array{0: int|null, 1: int|null, 2: int|null, 3: int|null} [width, height, sourceWidth, sourceHeight]
     */
    private function resolveDimensions(
        Image $image,
        string $loaderName,
        ?int $width,
        ?int $height,
        ?int $sourceWidth,
        ?int $sourceHeight,
        bool $resolveMetadata,
    ): array {
        $w = $sourceWidth ?? $image->width;
        $h = $sourceHeight ?? $image->height;

        if (null !== $width && null !== $height) {
            if ($resolveMetadata && null === $w && null !== $image->stream) {
                // Both display dimensions are given: the source dimensions only cap the srcset
                // candidates (no upscaling), the displayed dimensions stay as given
                $guessed = $this->guessDimensions($image, $loaderName);

                return [$width, $height, $guessed['width'], $guessed['height']];
            }
        } else {
            if ($resolveMetadata && (null === $w || null === $h) && null !== $image->stream) {
                $guessed = $this->guessDimensions($image, $loaderName);
                $w ??= $guessed['width'];
                $h ??= $guessed['height'];
            }

            // Preserve aspect ratio when exactly one display dimension is provided
            $ratio = (null !== $w && null !== $h && $w > 0) ? $h / $w : null;

            // The enclosing guard guarantees at least one of them is null, so the
            // dimension not tested for here is always the one being derived.
            if (null !== $ratio) {
                if (null !== $width) {
                    $height = (int) round($width * $ratio);
                } elseif (null !== $height) {
                    $width = (int) round($height / $ratio);
                }
            }

            // Anything still unresolved falls back to the source dimensions
            $width ??= $w;
            $height ??= $h;
        }

        // Prevent upscaling beyond source dimensions
        if (null !== $w && null !== $width && $width > $w) {
            $width = $w;
        }
        if (null !== $h && null !== $height && $height > $h) {
            $height = $h;
        }

        return [$width, $height, $w, $h];
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    private function guessDimensions(Image $image, string $loaderName): array
    {
        $this->stopwatch?->start('picasso.metadata_guess', 'picasso');
        $guessed = $this->metadataGuesser->guess(
            $image->resolveStream(...),
            $loaderName . ':' . $image->path,
        );
        $this->stopwatch?->stop('picasso.metadata_guess');

        return ['width' => $guessed['width'], 'height' => $guessed['height']];
    }

    /**
     * @param TransformerContext $transformerContext
     */
    private function generatePlaceholder(
        Image $image,
        array $transformerContext,
        bool $priority,
        ?string $placeholderData,
        ?string $placeholderName,
        ?int $width,
        ?int $height,
        ?int $quality,
        ?string $fit,
    ): ?string {
        if ($priority) {
            return null;
        }

        if (null !== $placeholderData) {
            return $placeholderData;
        }

        if (null !== $placeholderName) {
            return $this->placeholderRegistry
                ->get($placeholderName)
                ->generate($image, new ImageTransformation(
                    width: $width,
                    height: $height,
                    quality: $quality ?? $this->defaultQuality,
                    fit: $fit ?? $this->defaultFit,
                ), $transformerContext);
        }

        return null;
    }

    /**
     * @param TransformerContext $transformerContext
     *
     * @return array{0: ImageSource[], 1: string|null, 2: string|null} [sources, fallbackSrc, fallbackSrcset]
     */
    private function generateSources(
        ImageTransformerInterface $imageTransformer,
        Image $image,
        array $transformerContext,
        ?int $sourceWidth,
        ?int $sourceHeight,
        ?int $width,
        ?int $height,
        ?string $sizes,
        ?int $quality,
        ?string $fit,
    ): array {
        $quality = $quality ?? $this->defaultQuality;
        $fit = $fit ?? $this->defaultFit;
        $formats = $this->formats;
        $fallbackFormat = false !== end($formats) ? end($formats) : 'jpg';

        $sources = [];
        $fallbackSrc = null;
        $fallbackSrcset = null;

        foreach ($this->formats as $format) {
            $entries = $this->srcsetGenerator->generateSrcset(
                transformer: $imageTransformer,
                image: $image,
                format: $format,
                width: $width,
                height: $height,
                sizes: $sizes,
                quality: $quality,
                fit: $fit,
                context: $transformerContext,
                sourceWidth: $sourceWidth,
                sourceHeight: $sourceHeight,
            );

            $srcsetString = $this->srcsetGenerator->buildSrcsetString($entries);

            if ($format === $fallbackFormat) {
                $fallbackSrcset = $srcsetString;
                $fallbackSrc = $this->srcsetGenerator->getFallbackUrl(
                    transformer: $imageTransformer,
                    image: $image,
                    format: $format,
                    width: $width,
                    height: $height,
                    quality: $quality,
                    fit: $fit,
                    context: $transformerContext,
                );
            } else {
                $sources[] = new ImageSource(
                    type: $this->getMimeType($format),
                    srcset: $srcsetString,
                );
            }
        }

        return [$sources, $fallbackSrc, $fallbackSrcset];
    }

    private function resolveTransformerName(?string $transformer, string $loaderName): string
    {
        if (null !== $transformer) {
            return $this->pipeline->resolveTransformerName($transformer);
        }

        return $this->loaderRegistry->getDefaultTransformer($loaderName)
            ?? $this->pipeline->resolveTransformerName(null);
    }

    private function resolvePlaceholderName(string|bool|null $placeholder, string $loaderName): ?string
    {
        if (false === $placeholder) {
            return null;
        }

        if (is_string($placeholder)) {
            return $placeholder;
        }

        // placeholder === true or null: use loader default, then global default
        return $this->loaderRegistry->getDefaultPlaceholder($loaderName) ?? $this->defaultPlaceholder;
    }

    private function getMimeType(string $format): string
    {
        return match ($format) {
            'avif' => 'image/avif',
            'webp' => 'image/webp',
            'jpg', 'jpeg', 'pjpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => 'image/' . $format,
        };
    }
}
