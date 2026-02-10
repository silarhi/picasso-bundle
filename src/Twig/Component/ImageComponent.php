<?php

namespace Silarhi\PicassoBundle\Twig\Component;

use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Dto\LoaderContext;
use Silarhi\PicassoBundle\Loader\LoaderInterface;
use Silarhi\PicassoBundle\Service\BlurHashGenerator;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Url\ImageUrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent('Picasso:Image', template: '@Picasso/components/Image.html.twig')]
class ImageComponent
{
    /** The image source path (null renders nothing). */
    public ?string $src = null;

    /** Extra context passed to the loader. */
    public array $context = [];

    /** Explicit source width in pixels (skips dimension detection). */
    public ?int $sourceWidth = null;

    /** Explicit source height in pixels (skips dimension detection). */
    public ?int $sourceHeight = null;

    /** Display width in pixels (triggers fixed/retina mode when sizes is null). */
    public ?int $width = null;

    /** Display height in pixels. */
    public ?int $height = null;

    /** Responsive sizes attribute (triggers responsive mode with width descriptors). */
    public ?string $sizes = null;

    /** Which loader to use ('file', 'vich_uploader', 'flysystem'). */
    public ?string $loader = null;

    /** Which provider to use for URL generation ('glide', 'imgix'). */
    public ?string $provider = null;

    /** Override image quality (1-100). */
    public ?int $quality = null;

    /** Fit mode (agnostic: 'contain', 'cover', 'fill', 'crop'). */
    public string $fit = 'contain';

    /** Enable/disable blur placeholder for this image. */
    public ?bool $placeholder = null;

    /** Serve the image as-is, without optimization (no srcset, no formats, no blur). */
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

    public function __construct(
        private readonly SrcsetGenerator $srcsetGenerator,
        private readonly BlurHashGenerator $blurHashGenerator,
        private readonly ContainerInterface $loaders,
        private readonly ContainerInterface $providers,
        private readonly string $defaultLoader,
        private readonly string $defaultProvider,
        private readonly array $formats,
        private readonly int $defaultQuality,
    ) {
    }

    #[PostMount]
    public function computeImageData(): void
    {
        if ($this->src === null) {
            return;
        }

        // Unoptimized: serve image as-is (SVGs, GIFs, pre-optimized assets)
        if ($this->unoptimized) {
            $this->fallbackSrc = $this->src;
            return;
        }

        $loaderName = $this->loader ?? $this->defaultLoader;
        /** @var LoaderInterface $loader */
        $loader = $this->loaders->get($loaderName);

        $loaderContext = new LoaderContext(
            source: $this->src,
            extra: $this->context,
        );

        $this->resolvedPath = $loader->resolvePath($loaderContext);

        // Resolve dimensions: explicit props > loader detection
        $w = $this->sourceWidth;
        $h = $this->sourceHeight;

        if ($w === null || $h === null) {
            $dims = $loader->getDimensions($loaderContext);
            if ($dims !== null) {
                $w ??= $dims->width;
                $h ??= $dims->height;
            }
        }

        // Fall back to display dimensions
        $resolvedWidth = $w ?? $this->width;
        $resolvedHeight = $h ?? $this->height;

        $this->width ??= $resolvedWidth;
        $this->height ??= $resolvedHeight;

        // Generate blur placeholder
        $shouldBlur = $this->placeholder ?? $this->blurHashGenerator->isEnabled();
        if ($shouldBlur) {
            $this->blurDataUri = $this->blurHashGenerator->generate(
                $this->resolvedPath,
                $resolvedWidth,
                $resolvedHeight,
            );
        }

        // Resolve URL generator from provider
        $providerName = $this->provider ?? $this->defaultProvider;
        /** @var ImageUrlGeneratorInterface $urlGenerator */
        $urlGenerator = $this->providers->get($providerName);

        // Generate sources for each format
        $quality = $this->quality ?? $this->defaultQuality;
        $formats = $this->formats;
        $fallbackFormat = end($formats) ?: 'jpg';

        foreach ($this->formats as $format) {
            $entries = $this->srcsetGenerator->generateSrcset(
                urlGenerator: $urlGenerator,
                path: $this->resolvedPath,
                format: $format,
                width: $this->width,
                height: $this->height,
                sizes: $this->sizes,
                quality: $quality,
                fit: $this->fit,
            );

            $srcsetString = $this->srcsetGenerator->buildSrcsetString($entries);

            if ($format === $fallbackFormat) {
                $this->fallbackSrcset = $srcsetString;
                $this->fallbackSrc = $this->srcsetGenerator->getFallbackUrl(
                    urlGenerator: $urlGenerator,
                    path: $this->resolvedPath,
                    format: $format,
                    width: $this->width,
                    height: $this->height,
                    quality: $quality,
                    fit: $this->fit,
                );
            } else {
                $this->sources[] = new ImageSource(
                    type: self::getMimeType($format),
                    srcset: $srcsetString,
                );
            }
        }
    }

    private static function getMimeType(string $format): string
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
