<?php

namespace Silarhi\PicassoBundle\Service;

class SrcsetGenerator
{
    /**
     * @param int[]    $deviceSizes Breakpoint widths for responsive images
     * @param int[]    $imageSizes  Smaller widths for fixed/icon images
     * @param string[] $formats     Ordered list of output formats (last = fallback)
     */
    public function __construct(
        private readonly UrlGenerator $urlGenerator,
        private readonly array $deviceSizes,
        private readonly array $imageSizes,
        private readonly array $formats,
        private readonly int $defaultQuality,
    ) {
    }

    /**
     * Determine which widths to include in the srcset.
     *
     * Responsive mode (sizes given): all deviceSizes + imageSizes, merged and sorted.
     * Fixed mode (sizes null, width given): 1x and 2x of the specified width.
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
     * Generate srcset entries for a given source path and format.
     *
     * @return array<array{url: string, descriptor: string}>
     */
    public function generateSrcset(
        string $path,
        string $format,
        ?int $width = null,
        ?int $height = null,
        ?string $sizes = null,
        ?int $quality = null,
        string $fit = 'contain',
    ): array {
        $quality ??= $this->defaultQuality;
        $widths = $this->getWidths($sizes, $width);
        $isFixed = $sizes === null && $width !== null;
        $entries = [];

        foreach ($widths as $index => $w) {
            $params = [
                'w' => $w,
                'fm' => $format,
                'q' => $quality,
                'fit' => $fit,
            ];

            if ($isFixed && $width > 0 && $height !== null && $height > 0) {
                $params['h'] = (int) round($w * $height / $width);
            }

            $url = $this->urlGenerator->generate($path, $params);
            $descriptor = $isFixed ? ($index + 1).'x' : $w.'w';

            $entries[] = [
                'url' => $url,
                'descriptor' => $descriptor,
            ];
        }

        return $entries;
    }

    /**
     * Build the srcset attribute string from entries.
     *
     * @param array<array{url: string, descriptor: string}> $entries
     */
    public function buildSrcsetString(array $entries): string
    {
        return implode(', ', array_map(
            static fn (array $e) => $e['url'].' '.$e['descriptor'],
            $entries,
        ));
    }

    /**
     * Get the fallback src URL (used on the <img> tag's src attribute).
     */
    public function getFallbackUrl(
        string $path,
        string $format,
        ?int $width = null,
        ?int $height = null,
        ?int $quality = null,
        string $fit = 'contain',
    ): string {
        $quality ??= $this->defaultQuality;
        $params = ['fm' => $format, 'q' => $quality, 'fit' => $fit];

        if ($width !== null) {
            $params['w'] = $width;
        }
        if ($height !== null) {
            $params['h'] = $height;
        }

        return $this->urlGenerator->generate($path, $params);
    }

    /**
     * @return string[]
     */
    public function getFormats(): array
    {
        return $this->formats;
    }
}
