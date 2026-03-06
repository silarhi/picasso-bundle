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

use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
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

    /** Enable/disable blur placeholder for this image. */
    public ?bool $placeholder = null;

    /** Mark as high-priority (above the fold): disables lazy loading and blur placeholder. */
    public bool $priority = false;

    /** Loading attribute ('lazy' or 'eager'). Resolved in PostMount: defaults to 'eager' when priority, 'lazy' otherwise. */
    public ?string $loading = null;

    /** Fetch priority attribute ('high', 'low', 'auto'). Resolved in PostMount: defaults to 'high' when priority. */
    public ?string $fetchPriority = null;

    /** Serve the image as-is, without optimization. */
    public bool $unoptimized = false;

    // --- Computed state (set in PostMount, used by template) ---

    /** @internal */
    public ?string $blurDataUri = null;

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
        private readonly array $formats,
        private readonly int $defaultQuality,
        private readonly string $defaultFit,
        private readonly bool $blurEnabled,
        private readonly int $blurSize,
        private readonly int $blurAmount,
        private readonly int $blurQuality,
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

        // Resolve dimensions: explicit props > loader metadata > stream detection > display dims
        $w = $this->sourceWidth ?? $image->width;
        $h = $this->sourceHeight ?? $image->height;

        if ((null === $w || null === $h) && null !== $image->stream) {
            $this->stopwatch?->start('picasso.metadata_guess', 'picasso');
            $stream = $image->resolveStream();
            if (null !== $stream) {
                $guessed = $this->metadataGuesser->guess($stream);
                $w ??= $guessed['width'];
                $h ??= $guessed['height'];
            }
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

        // URL-based images bypass transformation (no local serving)
        if (null !== $image->url) {
            $this->fallbackSrc = $image->url;

            return;
        }

        // Resolve transformer
        $transformerName = $this->pipeline->resolveTransformerName($this->transformer);
        $imageTransformer = $this->transformerRegistry->get($transformerName);
        $transformerContext = ['loader' => $loaderName];

        // Resolve fit from prop or config default
        $fit = $this->fit ?? $this->defaultFit;

        // Generate blur placeholder URL via the transformer (priority disables blur)
        $shouldBlur = !$this->priority && ($this->placeholder ?? $this->blurEnabled);
        if ($shouldBlur) {
            $tinyWidth = $this->blurSize;
            $tinyHeight = $this->blurSize;

            if (null !== $this->width && null !== $this->height && $this->width > 0) {
                $tinyHeight = max(1, (int) round($tinyWidth * $this->height / $this->width));
            }

            $this->blurDataUri = $imageTransformer->url($image, new ImageTransformation(
                width: $tinyWidth,
                height: $tinyHeight,
                format: 'jpg',
                quality: $this->blurQuality,
                fit: 'crop',
                blur: $this->blurAmount,
            ), $transformerContext);
        }

        // Generate sources for each format
        $quality = $this->quality ?? $this->defaultQuality;
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
                sourceWidth: $w,
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
