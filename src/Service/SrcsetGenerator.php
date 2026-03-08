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

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Dto\SrcsetEntry;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;

/**
 * @phpstan-import-type TransformerContext from ImageTransformerInterface
 */
class SrcsetGenerator
{
    /**
     * @param int[] $deviceSizes Breakpoint widths for responsive images
     * @param int[] $imageSizes  Smaller widths for fixed/icon images
     */
    public function __construct(
        private readonly array $deviceSizes,
        private readonly array $imageSizes,
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
        if (null !== $width && null === $sizes) {
            return [$width, $width * 2];
        }

        return $this->getAllWidths();
    }

    /**
     * @return int[]
     */
    private function getAllWidths(): array
    {
        $widths = array_merge($this->deviceSizes, $this->imageSizes);
        sort($widths);

        return array_values(array_unique($widths));
    }

    /**
     * Generate srcset entries for a given image and format.
     *
     * @param TransformerContext $context
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
        ?int $sourceWidth = null,
    ): array {
        $quality ??= $this->defaultQuality;
        $widths = $this->getWidths($sizes, $width);
        $isFixed = null === $sizes && null !== $width;

        // Prevent upscaling: cap widths to source width
        if (null !== $sourceWidth && $sourceWidth > 0) {
            $widths = array_values(array_unique(array_map(
                static fn (int $w): int => min($w, $sourceWidth),
                $widths,
            )));
        }
        $entries = [];

        foreach ($widths as $index => $w) {
            $h = null;
            if ($isFixed && $width > 0 && null !== $height && $height > 0) {
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
            $descriptor = $isFixed ? ($index + 1) . 'x' : $w . 'w';

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
            static fn (SrcsetEntry $entry): string => $entry->toString(),
            $entries,
        ));
    }

    /**
     * Get the fallback src URL.
     *
     * @param TransformerContext $context
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
}
