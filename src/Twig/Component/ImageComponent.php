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

namespace Silarhi\PicassoBundle\Twig\Component;

use function is_string;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;
use Silarhi\PicassoBundle\Service\PlaceholderRegistry;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent('Picasso:Image', template: '@Picasso/components/Image.html.twig')]
class ImageComponent
{
    /** The image source path (null renders nothing). */
    public ?string $src = null;

    /** @var array<string, mixed> Extra context passed to the loader (e.g. entity, field for Vich). */
    public array $context = [];

    /** Explicit source width in pixels (skips metadata detection). */
    public ?int $sourceWidth = null;

    /** Explicit source height in pixels (skips metadata detection). */
    public ?int $sourceHeight = null;

    /** Display width in pixels. */
    public ?int $width = null;

    /** Display height in pixels. */
    public ?int $height = null;

    /** Responsive sizes attribute. */
    public ?string $sizes = null;

    /** Which loader to use ('filesystem', 'vich', 'flysystem'). */
    public ?string $loader = null;

    /** Which transformer to use ('glide', 'imgix'). */
    public ?string $transformer = null;

    /** Override image quality (1-100). */
    public ?int $quality = null;

    /** Fit mode ('contain', 'cover', 'fill', 'crop'). Null defaults to config value. */
    public ?string $fit = null;

    /** Enable/disable/select placeholder. Boolean to enable/disable, string to select a named placeholder. */
    public string|bool|null $placeholder = null;

    /** Literal placeholder data URI or URL, bypasses placeholder services. */
    public ?string $placeholderData = null;

    /** Mark as high-priority (above the fold): disables lazy loading and placeholder. */
    public bool $priority = false;

    /** Loading attribute ('lazy' or 'eager'). Resolved in PostMount: defaults to 'eager' when priority, 'lazy' otherwise. */
    public ?string $loading = null;

    /** Fetch priority attribute ('high', 'low', 'auto'). Resolved in PostMount: defaults to 'high' when priority. */
    public ?string $fetchPriority = null;

    /** Serve the image as-is, without optimization. */
    public bool $unoptimized = false;

    // --- Computed state (set in PostMount, used by template) ---

    /** @internal */
    public ?string $placeholderUri = null;

    /**
     * @internal
     *
     * @var ImageSource[]
     */
    public array $sources = [];

    /** @internal */
    public ?string $fallbackSrc = null;

    /** @internal */
    public ?string $fallbackSrcset = null;

    /**
     * @param string[] $formats
     */
    public function __construct(
        private readonly SrcsetGenerator $srcsetGenerator,
        private readonly ImagePipeline $pipeline,
        private readonly TransformerRegistry $transformerRegistry,
        private readonly MetadataGuesserInterface $metadataGuesser,
        private readonly PlaceholderRegistry $placeholderRegistry,
        private readonly LoaderRegistry $loaderRegistry,
        private readonly array $formats,
        private readonly ?int $defaultQuality,
        private readonly string $defaultFit,
        private readonly ?string $defaultPlaceholder = null,
        private readonly ?Stopwatch $stopwatch = null,
    ) {
    }

    #[PostMount]
    public function computeImageData(): void
    {
        $this->loading ??= $this->priority ? 'eager' : 'lazy';
        $this->fetchPriority ??= $this->priority ? 'high' : null;

        if ($this->unoptimized) {
            $this->fallbackSrc = $this->src;

            return;
        }

        $loaderName = $this->pipeline->resolveLoaderName($this->loader);

        $reference = new ImageReference($this->src, $this->context);
        $needsMetadata = null === $this->sourceWidth || null === $this->sourceHeight;
        $image = $this->pipeline->load($reference, $this->loader, $needsMetadata);

        $sourceWidth = $this->resolveDimensions($image, $loaderName);

        // Resolve transformer
        $transformerName = $this->pipeline->resolveTransformerName($this->transformer);
        $imageTransformer = $this->transformerRegistry->get($transformerName);
        $transformerContext = ['loader' => $loaderName, 'transformer' => $transformerName];

        $this->generatePlaceholder($image, $loaderName, $transformerContext);
        $this->generateSources($imageTransformer, $image, $transformerContext, $sourceWidth);
    }

    /**
     * Resolve source and display dimensions from explicit props, loader metadata, or stream detection.
     *
     * @return int|null The resolved source width (used to prevent upscaling in srcset)
     */
    private function resolveDimensions(Image $image, string $loaderName): ?int
    {
        $w = $this->sourceWidth ?? $image->width;
        $h = $this->sourceHeight ?? $image->height;

        if ((null === $w || null === $h) && null !== $image->stream) {
            $this->stopwatch?->start('picasso.metadata_guess', 'picasso');
            $guessed = $this->metadataGuesser->guess(
                $image->resolveStream(...),
                $loaderName . ':' . $image->path,
            );
            $w ??= $guessed['width'];
            $h ??= $guessed['height'];
            $this->stopwatch?->stop('picasso.metadata_guess');
        }

        // Preserve aspect ratio when only one display dimension is provided
        $ratio = (null !== $w && null !== $h && $w > 0) ? $h / $w : null;
        $this->width ??= null !== $ratio && null !== $this->height ? (int) round($this->height / $ratio) : $w;
        $this->height ??= null !== $ratio && null !== $this->width ? (int) round($this->width * $ratio) : $h;

        // Prevent upscaling beyond source dimensions
        if (null !== $w && null !== $this->width && $this->width > $w) {
            $this->width = $w;
        }
        if (null !== $h && null !== $this->height && $this->height > $h) {
            $this->height = $h;
        }

        return $w;
    }

    /**
     * @param array<string, string> $transformerContext
     */
    private function generatePlaceholder(Image $image, string $loaderName, array $transformerContext): void
    {
        if ($this->priority) {
            return;
        }

        if (null !== $this->placeholderData) {
            $this->placeholderUri = $this->placeholderData;

            return;
        }

        $placeholderName = $this->resolvePlaceholderName($loaderName);
        if (null !== $placeholderName) {
            $this->placeholderUri = $this->placeholderRegistry
                ->get($placeholderName)
                ->generate($image, new ImageTransformation(
                    width: $this->width,
                    height: $this->height,
                    quality: $this->quality ?? $this->defaultQuality,
                    fit: $this->fit ?? $this->defaultFit,
                ), $transformerContext);
        }
    }

    /**
     * @param array<string, string> $transformerContext
     */
    private function generateSources(ImageTransformerInterface $imageTransformer, Image $image, array $transformerContext, ?int $sourceWidth): void
    {
        $quality = $this->quality ?? $this->defaultQuality;
        $fit = $this->fit ?? $this->defaultFit;
        $formats = $this->formats;
        $fallbackFormat = end($formats) ?: 'jpg';

        foreach ($this->formats as $format) {
            $entries = $this->srcsetGenerator->generateSrcset(
                transformer: $imageTransformer,
                image: $image,
                format: $format,
                width: $this->width,
                height: $this->height,
                sizes: $this->sizes,
                quality: $quality,
                fit: $fit,
                context: $transformerContext,
                sourceWidth: $sourceWidth,
            );

            $srcsetString = $this->srcsetGenerator->buildSrcsetString($entries);

            if ($format === $fallbackFormat) {
                $this->fallbackSrcset = $srcsetString;
                $this->fallbackSrc = $this->srcsetGenerator->getFallbackUrl(
                    transformer: $imageTransformer,
                    image: $image,
                    format: $format,
                    width: $this->width,
                    height: $this->height,
                    quality: $quality,
                    fit: $fit,
                    context: $transformerContext,
                );
            } else {
                $this->sources[] = new ImageSource(
                    type: $this->getMimeType($format),
                    srcset: $srcsetString,
                );
            }
        }
    }

    private function resolvePlaceholderName(string $loaderName): ?string
    {
        if (false === $this->placeholder) {
            return null;
        }

        if (is_string($this->placeholder)) {
            return $this->placeholder;
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
