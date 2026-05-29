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

namespace Silarhi\PicassoBundle\Tests\DataCollector;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Silarhi\PicassoBundle\DataCollector\PicassoDataCollector;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PicassoDataCollectorTest extends TestCase
{
    public function testEmptyCollectorReturnsZeroedTotals(): void
    {
        $collector = new PicassoDataCollector();
        $collector->collect(new Request(), new Response());

        $totals = $collector->getTotals();
        self::assertSame(0, $totals->renders);
        self::assertSame(0, $totals->urls);
        self::assertSame(0, $totals->placeholders);
        self::assertSame(0, $totals->metadata);
        self::assertSame(0, $totals->headline);
        self::assertSame(0.0, $totals->duration);

        self::assertSame([], $collector->getRenders());
        self::assertSame([], $collector->getUrls());
        self::assertSame([], $collector->getPlaceholders());
        self::assertSame([], $collector->getMetadata());
    }

    public function testGetTotalsWithoutCollectStillReturnsDefault(): void
    {
        $collector = new PicassoDataCollector();

        $totals = $collector->getTotals();
        self::assertSame(0, $totals->headline);
    }

    public function testCollectsImageRender(): void
    {
        $collector = new PicassoDataCollector();
        $data = new ImageRenderData(
            fallbackSrc: '/img/fallback.jpg',
            fallbackSrcset: '/img/fallback.jpg 100w',
            sources: [new ImageSource('image/webp', '/img/x.webp 100w')],
            placeholderUri: 'data:image/jpeg;base64,abc',
            width: 1920,
            height: 1080,
            loading: 'eager',
            fetchPriority: 'high',
            sizes: '100vw',
            unoptimized: false,
        );

        $collector->collectImageRender('hero.jpg', 'filesystem', 'glide', 'blur', $data, 12.5);
        $collector->collect(new Request(), new Response());

        $renders = $collector->getRenders();
        self::assertCount(1, $renders);
        self::assertSame('hero.jpg', $renders[0]->src);
        self::assertSame('filesystem', $renders[0]->loader);
        self::assertSame('glide', $renders[0]->transformer);
        self::assertSame('blur', $renders[0]->placeholder);
        self::assertSame(1920, $renders[0]->width);
        self::assertSame(1080, $renders[0]->height);
        self::assertTrue($renders[0]->priority);
        self::assertFalse($renders[0]->unoptimized);
        self::assertSame(12.5, $renders[0]->duration);
        self::assertSame(1, $renders[0]->sourcesCount);
        self::assertTrue($renders[0]->hasPlaceholder);
    }

    public function testCollectsImageUrl(): void
    {
        $collector = new PicassoDataCollector();
        $transformation = new ImageTransformation(
            width: 800,
            height: 600,
            format: 'webp',
            quality: 80,
            fit: 'cover',
        );

        $collector->collectImageUrl('photo.jpg', 'filesystem', 'glide', $transformation, '/img/result.webp', 3.2);
        $collector->collect(new Request(), new Response());

        $urls = $collector->getUrls();
        self::assertCount(1, $urls);
        self::assertSame('photo.jpg', $urls[0]->src);
        self::assertSame('filesystem', $urls[0]->loader);
        self::assertSame('glide', $urls[0]->transformer);
        self::assertSame(800, $urls[0]->width);
        self::assertSame(600, $urls[0]->height);
        self::assertSame('webp', $urls[0]->format);
        self::assertSame(80, $urls[0]->quality);
        self::assertSame('cover', $urls[0]->fit);
        self::assertSame('/img/result.webp', $urls[0]->url);
        self::assertSame(3.2, $urls[0]->duration);
    }

    public function testCollectsPlaceholderWithAndWithoutError(): void
    {
        $collector = new PicassoDataCollector();

        $collector->collectPlaceholder('blur', 'a.jpg', 1.5);
        $collector->collectPlaceholder('blurhash', 'b.jpg', 2.5, new RuntimeException('boom'));
        $collector->collect(new Request(), new Response());

        $placeholders = $collector->getPlaceholders();
        self::assertCount(2, $placeholders);
        self::assertNull($placeholders[0]->error);
        self::assertSame('boom', $placeholders[1]->error);
    }

    public function testCollectsMetadataGuess(): void
    {
        $collector = new PicassoDataCollector();

        $collector->collectMetadataGuess('filesystem:hero.jpg', 1920, 1080, 'image/jpeg', 0.8);
        $collector->collect(new Request(), new Response());

        $metadata = $collector->getMetadata();
        self::assertCount(1, $metadata);
        self::assertSame('filesystem:hero.jpg', $metadata[0]->key);
        self::assertSame(1920, $metadata[0]->width);
        self::assertSame(1080, $metadata[0]->height);
        self::assertSame('image/jpeg', $metadata[0]->mimeType);
    }

    public function testCollectAggregatesTotalsAndHeadlineEqualsRendersPlusUrls(): void
    {
        $collector = new PicassoDataCollector();
        $data = new ImageRenderData(
            fallbackSrc: null,
            fallbackSrcset: null,
            sources: [],
            placeholderUri: null,
            width: null,
            height: null,
            loading: 'lazy',
            fetchPriority: null,
            sizes: null,
            unoptimized: false,
        );
        $transformation = new ImageTransformation(width: 100);

        $collector->collectImageRender('a.jpg', null, null, null, $data, 1.0);
        $collector->collectImageRender('b.jpg', null, null, null, $data, 2.0);
        $collector->collectImageUrl('c.jpg', null, null, $transformation, '/c', 3.0);
        $collector->collectPlaceholder('blur', 'd.jpg', 4.0);
        $collector->collectMetadataGuess('key', 1, 1, 'image/jpeg', 5.0);

        $collector->collect(new Request(), new Response());

        $totals = $collector->getTotals();
        self::assertSame(2, $totals->renders);
        self::assertSame(1, $totals->urls);
        self::assertSame(1, $totals->placeholders);
        self::assertSame(1, $totals->metadata);
        self::assertSame(15.0, $totals->duration);
        self::assertSame(3, $totals->headline, 'headline is renders + urls');
    }

    public function testResetClearsAccumulatorsAndData(): void
    {
        $collector = new PicassoDataCollector();
        $collector->collectMetadataGuess('key', 1, 1, null, 1.0);
        $collector->collect(new Request(), new Response());
        self::assertCount(1, $collector->getMetadata());

        $collector->reset();

        self::assertSame([], $collector->getMetadata());
        self::assertSame(0, $collector->getTotals()->headline);
    }

    public function testGetNameAndTemplate(): void
    {
        self::assertSame('picasso', (new PicassoDataCollector())->getName());
        self::assertSame('@Picasso/Collector/picasso.html.twig', PicassoDataCollector::getTemplate());
    }
}
