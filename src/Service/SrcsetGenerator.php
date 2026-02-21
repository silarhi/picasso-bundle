<?php

namespace Silarhi\PicassoBundle\Service;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Dto\SrcsetEntry;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;

class SrcsetGenerator
{
    /**
     * @param int[]    $deviceSizes Breakpoint widths for responsive images
     * @param int[]    $imageSizes  Smaller widths for fixed/icon images
     * @param string[] $formats     Ordered list of output formats (last = fallback)
     */
    public function __construct(
        private readonly array $deviceSizes,
        private readonly array $imageSizes,
        private readonly array $formats,
        private readonly int $defaultQuality,
    ) {
    }

    /**
     * Determine which widths to include in the srcset.
     *
     * @return int[]
     */
    public function getWidths(?string $sizes, ?int $width): array
    {
        if ($sizes !== null) {
            $widths = array_merge($this->deviceSizes, $this->imageSizes);
            sort($widths);

            return array_values(array_unique($widths));
        }

        if ($width !== null) {
            return [$width, $width * 2];
        }

        $widths = array_merge($this->deviceSizes, $this->imageSizes);
        sort($widths);

        return array_values(array_unique($widths));
    }

    /**
     * Generate srcset entries for a given image and format.
     *
     * @param array<string, mixed> $context
     *
     * @return SrcsetEntry[]
     */
    public function generateSrcset(
        ImageTransformerInterface $transformer,
        Image $image,
        string $format,
        ?int $width = null,
        ?int $height = null,
        ?string $sizes = null,
        ?int $quality = null,
        string $fit = 'contain',
        array $context = [],
    ): array {
        $quality ??= $this->defaultQuality;
        $widths = $this->getWidths($sizes, $width);
        $isFixed = $sizes === null && $width !== null;
        $entries = [];

        foreach ($widths as $index => $w) {
            $h = null;
            if ($isFixed && $width > 0 && $height !== null && $height > 0) {
                $h = (int) round($w * $height / $width);
            }

            $transformation = new ImageTransformation(
                width: $w,
                height: $h,
                format: $format,
                quality: $quality,
                fit: $fit,
            );

            $url = $transformer->url($image, $transformation, $context);
            $descriptor = $isFixed ? ($index + 1).'x' : $w.'w';

            $entries[] = new SrcsetEntry($url, $descriptor);
        }

        return $entries;
    }

    /**
     * Build the srcset attribute string from entries.
     *
     * @param SrcsetEntry[] $entries
     */
    public function buildSrcsetString(array $entries): string
    {
        return implode(', ', array_map(
            static fn (SrcsetEntry $entry) => $entry->toString(),
            $entries,
        ));
    }

    /**
     * Get the fallback src URL.
     *
     * @param array<string, mixed> $context
     */
    public function getFallbackUrl(
        ImageTransformerInterface $transformer,
        Image $image,
        string $format,
        ?int $width = null,
        ?int $height = null,
        ?int $quality = null,
        string $fit = 'contain',
        array $context = [],
    ): string {
        $quality ??= $this->defaultQuality;

        return $transformer->url($image, new ImageTransformation(
            width: $width,
            height: $height,
            format: $format,
            quality: $quality,
            fit: $fit,
        ), $context);
    }

    /**
     * @return string[]
     */
    public function getFormats(): array
    {
        return $this->formats;
    }
}
