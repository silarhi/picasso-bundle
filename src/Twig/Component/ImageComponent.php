<?php

namespace Silarhi\PicassoBundle\Twig\Component;

use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Loader\LoaderInterface;
use Silarhi\PicassoBundle\Service\BlurHashGenerator;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent('Picasso:Image', template: '@Picasso/components/Image.html.twig')]
class ImageComponent
{
    /** The image source: a path string or an entity object (for VichUploader). */
    public string|object $src;

    /** VichUploader field name (required when src is an entity). */
    public ?string $field = null;

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

    /** Alt text for the image. */
    public string $alt = '';

    /** Loading strategy: 'lazy' or 'eager'. */
    public string $loading = 'lazy';

    /** Which loader to use ('file', 'vich_uploader'). */
    public ?string $loader = null;

    /** Override image quality (1–100). */
    public ?int $quality = null;

    /** Glide fit mode. */
    public string $fit = 'contain';

    /** Enable/disable blur placeholder for this image. */
    public ?bool $placeholder = null;

    // --- Computed state (set in PostMount, used by template) ---

    /** @internal */
    public string $resolvedPath = '';

    /** @internal */
    public ?string $blurDataUri = null;

    /**
     * @internal
     *
     * @var array<array{type: string, srcset: string}>
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
        private readonly string $defaultLoader,
        private readonly array $formats,
        private readonly int $defaultQuality,
    ) {
    }

    #[PostMount]
    public function computeImageData(): void
    {
        $loaderName = $this->loader ?? $this->defaultLoader;
        /** @var LoaderInterface $loader */
        $loader = $this->loaders->get($loaderName);

        $this->resolvedPath = $loader->resolvePath($this->src, $this->field);

        // Resolve dimensions: explicit props > loader detection
        $w = $this->sourceWidth;
        $h = $this->sourceHeight;

        if ($w === null || $h === null) {
            $dims = $loader->getDimensions($this->src, $this->field);
            if ($dims !== null) {
                $w ??= $dims[0];
                $h ??= $dims[1];
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

        // Generate sources for each format
        $quality = $this->quality ?? $this->defaultQuality;
        $formats = $this->formats;
        $fallbackFormat = end($formats) ?: 'jpg';

        foreach ($this->formats as $format) {
            $entries = $this->srcsetGenerator->generateSrcset(
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
                    path: $this->resolvedPath,
                    format: $format,
                    width: $this->width,
                    height: $this->height,
                    quality: $quality,
                    fit: $this->fit,
                );
            } else {
                $this->sources[] = [
                    'type' => self::getMimeType($format),
                    'srcset' => $srcsetString,
                ];
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
