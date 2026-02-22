<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Twig\Component;

use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
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

    /** Serve the image as-is, without optimization. */
    public bool $unoptimized = false;

    // --- Computed state (set in PostMount, used by template) ---

    /** @internal */
    public string $resolvedPath = '';

    /** @internal */
    public ?string $blurDataUri = null;

    /**
     * @internal
     *
     * @var ImageSource[]
     */
    public array $sources = [];

    /** @internal */
    public string $fallbackSrc = '';

    /** @internal */
    public string $fallbackSrcset = '';

    /**
     * @param string[] $formats
     */
    public function __construct(
        private readonly SrcsetGenerator $srcsetGenerator,
        private readonly ContainerInterface $loaders,
        private readonly ContainerInterface $transformers,
        private readonly MetadataGuesserInterface $metadataGuesser,
        private readonly string $defaultLoader,
        private readonly string $defaultTransformer,
        private readonly array $formats,
        private readonly int $defaultQuality,
        private readonly string $defaultFit,
        private readonly bool $blurEnabled,
        private readonly int $blurSize,
        private readonly int $blurAmount,
        private readonly int $blurQuality,
    ) {
    }

    #[PostMount]
    public function computeImageData(): void
    {
        if (null === $this->src) {
            return;
        }

        if ($this->unoptimized) {
            $this->fallbackSrc = $this->src;

            return;
        }

        $loaderName = $this->loader ?? $this->defaultLoader;
        /** @var ImageLoaderInterface $imageLoader */
        $imageLoader = $this->loaders->get($loaderName);

        $reference = new ImageReference($this->src, $this->context);
        $image = $imageLoader->load($reference);

        $this->resolvedPath = $image->path ?? '';

        // Resolve dimensions: explicit props > stream detection > display dims
        $w = $this->sourceWidth;
        $h = $this->sourceHeight;

        if ((null === $w || null === $h) && null !== $image->stream) {
            $guessed = $this->metadataGuesser->guess($image->stream);
            $w ??= $guessed['width'];
            $h ??= $guessed['height'];
        }

        $resolvedWidth = $w ?? $this->width;
        $resolvedHeight = $h ?? $this->height;

        $this->width ??= $resolvedWidth;
        $this->height ??= $resolvedHeight;

        // Resolve transformer
        $transformerName = $this->transformer ?? $this->defaultTransformer;
        /** @var ImageTransformerInterface $imageTransformer */
        $imageTransformer = $this->transformers->get($transformerName);
        $transformerContext = ['loader' => $loaderName];

        // Resolve fit from prop or config default
        $fit = $this->fit ?? $this->defaultFit;

        // Generate blur placeholder URL via the transformer
        $shouldBlur = $this->placeholder ?? $this->blurEnabled;
        if ($shouldBlur) {
            $tinyWidth = $this->blurSize;
            $tinyHeight = $this->blurSize;

            if (null !== $resolvedWidth && null !== $resolvedHeight && $resolvedWidth > 0) {
                $tinyHeight = max(1, (int) round($tinyWidth * $resolvedHeight / $resolvedWidth));
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
            default => 'image/'.$format,
        };
    }
}
