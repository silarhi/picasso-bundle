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

namespace Silarhi\PicassoBundle\DataCollector;

use function count;

use Override;
use Silarhi\PicassoBundle\DataCollector\Dto\MetadataEntry;
use Silarhi\PicassoBundle\DataCollector\Dto\PlaceholderEntry;
use Silarhi\PicassoBundle\DataCollector\Dto\RenderEntry;
use Silarhi\PicassoBundle\DataCollector\Dto\Totals;
use Silarhi\PicassoBundle\DataCollector\Dto\UrlEntry;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Collects Picasso image operations for the Symfony web profiler.
 */
final class PicassoDataCollector extends AbstractDataCollector
{
    /** @var list<RenderEntry> */
    private array $renders = [];

    /** @var list<UrlEntry> */
    private array $urls = [];

    /** @var list<PlaceholderEntry> */
    private array $placeholders = [];

    /** @var list<MetadataEntry> */
    private array $metadata = [];

    public function collectImageRender(
        ?string $src,
        ?string $loader,
        ?string $transformer,
        string|bool|null $placeholder,
        ImageRenderData $data,
        float $duration,
    ): void {
        $this->renders[] = new RenderEntry(
            src: $src,
            loader: $loader,
            transformer: $transformer,
            placeholder: $placeholder,
            width: $data->width,
            height: $data->height,
            priority: 'eager' === $data->loading,
            unoptimized: $data->unoptimized,
            duration: $duration,
            sourcesCount: count($data->sources),
            hasPlaceholder: null !== $data->placeholderUri,
        );
    }

    public function collectImageUrl(
        string $src,
        ?string $loader,
        ?string $transformer,
        ImageTransformation $transformation,
        string $url,
        float $duration,
    ): void {
        $this->urls[] = new UrlEntry(
            src: $src,
            loader: $loader,
            transformer: $transformer,
            width: $transformation->width,
            height: $transformation->height,
            format: $transformation->format,
            quality: $transformation->quality,
            fit: $transformation->fit,
            duration: $duration,
            url: $url,
        );
    }

    public function collectPlaceholder(string $name, ?string $src, float $duration, ?Throwable $error = null): void
    {
        $this->placeholders[] = new PlaceholderEntry(
            name: $name,
            src: $src,
            duration: $duration,
            error: $error?->getMessage(),
        );
    }

    public function collectMetadataGuess(string $key, ?int $width, ?int $height, ?string $mimeType, float $duration): void
    {
        $this->metadata[] = new MetadataEntry(
            key: $key,
            width: $width,
            height: $height,
            mimeType: $mimeType,
            duration: $duration,
        );
    }

    #[Override]
    public function collect(Request $request, Response $response, ?Throwable $exception = null): void
    {
        $duration = 0.0;
        foreach ($this->renders as $entry) {
            $duration += $entry->duration;
        }
        foreach ($this->urls as $entry) {
            $duration += $entry->duration;
        }
        foreach ($this->placeholders as $entry) {
            $duration += $entry->duration;
        }
        foreach ($this->metadata as $entry) {
            $duration += $entry->duration;
        }

        $this->data = [
            'renders' => $this->renders,
            'urls' => $this->urls,
            'placeholders' => $this->placeholders,
            'metadata' => $this->metadata,
            'totals' => new Totals(
                renders: count($this->renders),
                urls: count($this->urls),
                placeholders: count($this->placeholders),
                metadata: count($this->metadata),
                duration: $duration,
                headline: count($this->renders) + count($this->urls),
            ),
        ];
    }

    #[Override]
    public function reset(): void
    {
        $this->renders = [];
        $this->urls = [];
        $this->placeholders = [];
        $this->metadata = [];
        $this->data = [];
    }

    #[Override]
    public function getName(): string
    {
        return 'picasso';
    }

    #[Override]
    public static function getTemplate(): string
    {
        return '@Picasso/Collector/picasso.html.twig';
    }

    /**
     * @return list<RenderEntry>
     */
    public function getRenders(): array
    {
        /** @var list<RenderEntry> $renders */
        $renders = $this->data['renders'] ?? [];

        return $renders;
    }

    /**
     * @return list<UrlEntry>
     */
    public function getUrls(): array
    {
        /** @var list<UrlEntry> $urls */
        $urls = $this->data['urls'] ?? [];

        return $urls;
    }

    /**
     * @return list<PlaceholderEntry>
     */
    public function getPlaceholders(): array
    {
        /** @var list<PlaceholderEntry> $placeholders */
        $placeholders = $this->data['placeholders'] ?? [];

        return $placeholders;
    }

    /**
     * @return list<MetadataEntry>
     */
    public function getMetadata(): array
    {
        /** @var list<MetadataEntry> $metadata */
        $metadata = $this->data['metadata'] ?? [];

        return $metadata;
    }

    public function getTotals(): Totals
    {
        /** @var Totals|null $totals */
        $totals = $this->data['totals'] ?? null;

        return $totals ?? new Totals(0, 0, 0, 0, 0.0, 0);
    }
}
