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

use function array_key_exists;
use function is_array;
use function is_int;
use function is_string;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImagePreset;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\InvalidTransformationException;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;

use function sprintf;

use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @phpstan-import-type TransformerContext from ImageTransformerInterface
 */
final readonly class ImageHelper implements ImageHelperInterface
{
    /**
     * Keys an art-direction entry may set; it inherits the others from the image.
     */
    private const ART_DIRECTION_KEYS = ['src', 'context', 'width', 'height', 'sourceWidth', 'sourceHeight', 'sizes', 'quality', 'fit', 'modifiers', 'preset'];

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
        private ?ImagePreloader $preloader = null,
        private PresetRegistry $presetRegistry = new PresetRegistry(),
    ) {
    }

    /**
     * Generate a single image URL with named parameters.
     *
     * @param array<string, mixed>      $context         Extra context passed to the loader (e.g. entity, field for Vich).
     * @param string|null               $route           Application route serving the image (through ImageServer) instead of the bundle's
     * @param array<string, mixed>      $routeParameters Parameters of that route
     * @param array<string, int|string> $modifiers       Further manipulations, in Glide's vocabulary
     * @param string|null               $preset          Named preset filling the options not given
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
        array $modifiers = [],
        ?string $preset = null,
    ): string {
        $presetOptions = $this->preset($preset);
        $loader ??= $presetOptions?->loader;
        $transformer ??= $presetOptions?->transformer;

        $transformation = new ImageTransformation(
            width: $width ?? $presetOptions?->width,
            height: $height ?? $presetOptions?->height,
            format: $format,
            quality: $quality ?? $presetOptions->quality ?? $this->defaultQuality,
            fit: $fit ?? $presetOptions->fit ?? $this->defaultFit,
            blur: $blur,
            dpr: $dpr,
            modifiers: [...$presetOptions->modifiers ?? [], ...$modifiers],
        );

        return $this->pipeline->url(new ImageReference($path, $context), $transformation, $loader, $transformer, $route, $routeParameters);
    }

    /**
     * Compute all image rendering data (dimensions, sources, placeholder, loading attributes).
     *
     * Returns an immutable DTO suitable for both Twig component rendering and JSON API responses.
     *
     * @param array<string, mixed>                $context         Extra context for the loader
     * @param array<string, scalar|null>          $attributes      Extra HTML attributes (alt, class, …)
     * @param string|null                         $route           Application route serving the image (through ImageServer) instead of the bundle's
     * @param array<string, mixed>                $routeParameters Parameters of that route
     * @param array<string, int|string>           $modifiers       Further manipulations, in Glide's vocabulary
     * @param string|null                         $preset          Named preset filling the options not given
     * @param array<string, array<string, mixed>> $artDirection    Art-directed sources by media query
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
        array $modifiers = [],
        ?string $preset = null,
        array $artDirection = [],
    ): ImageRenderData {
        $presetOptions = $this->preset($preset);
        if ($presetOptions instanceof ImagePreset) {
            $width ??= $presetOptions->width;
            $height ??= $presetOptions->height;
            $sizes ??= $presetOptions->sizes;
            $loader ??= $presetOptions->loader;
            $transformer ??= $presetOptions->transformer;
            $quality ??= $presetOptions->quality;
            $fit ??= $presetOptions->fit;
            $placeholder ??= $presetOptions->placeholder;
            $modifiers = [...$presetOptions->modifiers, ...$modifiers];
        }

        $loading ??= $priority ? 'eager' : 'lazy';
        $fetchPriority ??= $priority ? 'high' : null;

        if ($unoptimized) {
            return $this->preload($priority, new ImageRenderData(
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
            ));
        }

        $loaderName = $this->pipeline->resolveLoaderName($loader);

        $effectiveResolveMetadata = $resolveMetadata
            ?? $this->loaderRegistry->getResolveMetadata($loaderName)
            ?? $this->defaultResolveMetadata;

        $image = $this->pipeline->load(
            new ImageReference($src, $context),
            $loader,
            $this->needsMetadata($width, $height, $sourceWidth, $sourceHeight, $effectiveResolveMetadata),
        );
        $requestedWidth = $width;
        $requestedHeight = $height;

        [$width, $height, $resolvedSourceWidth, $resolvedSourceHeight] = $this->resolveDimensions(
            $image, $loaderName, $width, $height, $sourceWidth, $sourceHeight, $effectiveResolveMetadata,
        );

        $transformerName = $this->pipeline->resolveTransformerName($transformer, $loaderName);
        $imageTransformer = $this->transformerRegistry->get($transformerName);
        // A delegating loader (e.g. a chain) names the loader that loaded the image, which serves it
        $servingLoader = $image->loader ?? $loaderName;
        $transformerContext = (new TransformerContextFactory($this->loaderRegistry, $this->transformerRegistry))
            ->create($image, $loaderName, $transformerName, $route, $routeParameters);

        $placeholderName = $this->resolvePlaceholderName($placeholder, $loaderName);
        $placeholderUri = $this->generatePlaceholder(
            $image, $transformerContext,
            $priority, $placeholderData, $placeholderName,
            $width, $height, $quality, $fit, $modifiers,
        );

        [$sources, $fallbackSrc, $fallbackSrcset] = $this->generateSources(
            $imageTransformer, $image, $transformerContext,
            $resolvedSourceWidth, $resolvedSourceHeight, $width, $height, $sizes, $quality, $fit, $modifiers,
        );

        $artDirectedSources = [];
        foreach ($artDirection as $media => $entry) {
            $artDirectedSources[] = $this->generateArtDirectedSources(
                $media, $entry, $imageTransformer, $loader, $loaderName, $transformerName,
                $route, $routeParameters, $effectiveResolveMetadata,
                $src, $context, $image, $transformerContext, $resolvedSourceWidth, $resolvedSourceHeight,
                $requestedWidth, $requestedHeight, $sizes, $quality, $fit, $modifiers,
            );
        }
        if ([] !== $artDirectedSources) {
            $sources = [...array_merge(...$artDirectedSources), ...$sources];
        }

        return $this->preload($priority, new ImageRenderData(
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
        ));
    }

    /**
     * Explicit display dimensions need no source dimensions, except to cap srcset candidates to the source width:
     * loaders resolving metadata (filesystem by default, a cheap local read) still read them.
     */
    private function needsMetadata(?int $width, ?int $height, ?int $sourceWidth, ?int $sourceHeight, bool $resolveMetadata): bool
    {
        $hasAllDisplayDims = null !== $width && null !== $height;
        $hasAllSourceDims = null !== $sourceWidth && null !== $sourceHeight;

        return !$hasAllSourceDims && (!$hasAllDisplayDims || $resolveMetadata);
    }

    /**
     * @throws \Silarhi\PicassoBundle\Exception\PresetNotFoundException
     */
    private function preset(?string $name): ?ImagePreset
    {
        return null !== $name ? $this->presetRegistry->get($name) : null;
    }

    /**
     * The <source> elements of one art-direction entry, one per format (the fallback format included: the <img>
     * fallback is the image's own). The entry inherits from the image every option it omits; its width and height
     * are inherited together, since setting one changes the aspect ratio.
     *
     * @param array<string, mixed>      $entry
     * @param array<string, mixed>      $routeParameters
     * @param array<string, mixed>      $context
     * @param TransformerContext        $transformerContext
     * @param array<string, int|string> $modifiers
     *
     * @return list<ImageSource>
     *
     * @throws InvalidTransformationException When the entry has an unknown key or a value of the wrong type
     */
    private function generateArtDirectedSources(
        string $media,
        array $entry,
        ImageTransformerInterface $imageTransformer,
        ?string $loader,
        string $loaderName,
        string $transformerName,
        ?string $route,
        array $routeParameters,
        bool $resolveMetadata,
        ?string $src,
        array $context,
        Image $image,
        array $transformerContext,
        ?int $sourceWidth,
        ?int $sourceHeight,
        ?int $width,
        ?int $height,
        ?string $sizes,
        ?int $quality,
        ?string $fit,
        array $modifiers,
    ): array {
        $unknown = array_diff(array_keys($entry), self::ART_DIRECTION_KEYS);
        if ([] !== $unknown) {
            throw new InvalidTransformationException(sprintf('Art direction "%s": unknown option "%s". Known options: "%s".', $media, implode('", "', $unknown), implode('", "', self::ART_DIRECTION_KEYS)));
        }

        $entryPreset = $this->preset($this->entryString($media, $entry, 'preset'));
        $entryWidth = $this->entryInt($media, $entry, 'width') ?? $entryPreset?->width;
        $entryHeight = $this->entryInt($media, $entry, 'height') ?? $entryPreset?->height;
        if (null === $entryWidth && null === $entryHeight) {
            $entryWidth = $width;
            $entryHeight = $height;
        }
        $sizes = $this->entryString($media, $entry, 'sizes') ?? $entryPreset->sizes ?? $sizes;
        $quality = $this->entryInt($media, $entry, 'quality') ?? $entryPreset->quality ?? $quality;
        $fit = $this->entryString($media, $entry, 'fit') ?? $entryPreset->fit ?? $fit;
        $modifiers = [...$modifiers, ...$entryPreset->modifiers ?? [], ...$this->entryModifiers($media, $entry)];

        // Another image (src or context of its own) is loaded and measured like the image itself; the same image
        // reuses the image's load and source dimensions
        if (array_key_exists('src', $entry) || array_key_exists('context', $entry)) {
            $entrySourceWidth = $this->entryInt($media, $entry, 'sourceWidth');
            $entrySourceHeight = $this->entryInt($media, $entry, 'sourceHeight');
            $image = $this->pipeline->load(
                new ImageReference($this->entryString($media, $entry, 'src') ?? $src, $this->entryContext($media, $entry) ?? $context),
                $loader,
                $this->needsMetadata($entryWidth, $entryHeight, $entrySourceWidth, $entrySourceHeight, $resolveMetadata),
            );
            $transformerContext = (new TransformerContextFactory($this->loaderRegistry, $this->transformerRegistry))
                ->create($image, $loaderName, $transformerName, $route, $routeParameters);
            [$entryWidth, $entryHeight, $sourceWidth, $sourceHeight] = $this->resolveDimensions(
                $image, $loaderName, $entryWidth, $entryHeight, $entrySourceWidth, $entrySourceHeight, $resolveMetadata,
            );
        } else {
            [$entryWidth, $entryHeight] = $this->resolveDimensions(
                $image, $loaderName, $entryWidth, $entryHeight, $sourceWidth, $sourceHeight, false,
            );
        }

        $hasDimensions = null !== $entryWidth && null !== $entryHeight;
        $sources = [];
        foreach ($this->formats as $format) {
            $entries = $this->srcsetGenerator->generateSrcset(
                transformer: $imageTransformer,
                image: $image,
                format: $format,
                width: $entryWidth,
                height: $entryHeight,
                sizes: $sizes,
                quality: $quality ?? $this->defaultQuality,
                fit: $fit ?? $this->defaultFit,
                context: $transformerContext,
                sourceWidth: $sourceWidth,
                sourceHeight: $sourceHeight,
                modifiers: $modifiers,
            );

            $sources[] = new ImageSource(
                type: $this->getMimeType($format),
                srcset: $this->srcsetGenerator->buildSrcsetString($entries),
                media: $media,
                sizes: $sizes,
                width: $hasDimensions ? $entryWidth : null,
                height: $hasDimensions ? $entryHeight : null,
            );
        }

        return $sources;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function entryInt(string $media, array $entry, string $key): ?int
    {
        $value = $entry[$key] ?? null;
        if (null !== $value && !is_int($value)) {
            throw new InvalidTransformationException(sprintf('Art direction "%s": "%s" must be an integer.', $media, $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function entryString(string $media, array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new InvalidTransformationException(sprintf('Art direction "%s": "%s" must be a string.', $media, $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>|null
     */
    private function entryContext(string $media, array $entry): ?array
    {
        $value = $entry['context'] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_array($value)) {
            throw new InvalidTransformationException(sprintf('Art direction "%s": "context" must be an array.', $media));
        }

        $context = [];
        foreach ($value as $key => $item) {
            $context[(string) $key] = $item;
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, int|string>
     */
    private function entryModifiers(string $media, array $entry): array
    {
        $value = $entry['modifiers'] ?? [];
        if (!is_array($value)) {
            throw new InvalidTransformationException(sprintf('Art direction "%s": "modifiers" must be an array.', $media));
        }

        $modifiers = [];
        foreach ($value as $key => $item) {
            if (!is_int($item) && !is_string($item)) {
                throw new InvalidTransformationException(sprintf('Art direction "%s": modifier "%s" must be an integer or a string.', $media, $key));
            }
            $modifiers[(string) $key] = $item;
        }

        return $modifiers;
    }

    private function preload(bool $priority, ImageRenderData $data): ImageRenderData
    {
        if ($priority) {
            $this->preloader?->preload($data);
        }

        return $data;
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
     * @param TransformerContext        $transformerContext
     * @param array<string, int|string> $modifiers
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
        array $modifiers,
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
                    modifiers: $modifiers,
                ), $transformerContext);
        }

        return null;
    }

    /**
     * @param TransformerContext        $transformerContext
     * @param array<string, int|string> $modifiers
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
        array $modifiers,
    ): array {
        $quality ??= $this->defaultQuality;
        $fit ??= $this->defaultFit;
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
                modifiers: $modifiers,
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
                    modifiers: $modifiers,
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
