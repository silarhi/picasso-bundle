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
use Silarhi\PicassoBundle\DataCollector\CollectingImageHelper;
use Silarhi\PicassoBundle\DataCollector\PicassoDataCollector;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CollectingImageHelperTest extends TestCase
{
    public function testImageUrlForwardsToInnerAndRecordsCall(): void
    {
        $inner = $this->createMock(ImageHelperInterface::class);
        $inner->expects(self::once())
            ->method('imageUrl')
            ->with('hero.jpg', 800, 600, 'webp', 80, 'cover', null, null, 'filesystem', 'glide', ['ctx' => 1])
            ->willReturn('/result.webp');

        $collector = new PicassoDataCollector();
        $decorator = new CollectingImageHelper($inner, $collector);

        $result = $decorator->imageUrl(
            path: 'hero.jpg',
            width: 800,
            height: 600,
            format: 'webp',
            quality: 80,
            fit: 'cover',
            loader: 'filesystem',
            transformer: 'glide',
            context: ['ctx' => 1],
        );

        self::assertSame('/result.webp', $result);

        $collector->collect(new Request(), new Response());
        $urls = $collector->getUrls();
        self::assertCount(1, $urls);
        self::assertSame('hero.jpg', $urls[0]->src);
        self::assertSame('filesystem', $urls[0]->loader);
        self::assertSame('glide', $urls[0]->transformer);
        self::assertSame(800, $urls[0]->width);
        self::assertSame(600, $urls[0]->height);
        self::assertSame('webp', $urls[0]->format);
        self::assertSame(80, $urls[0]->quality);
        self::assertSame('cover', $urls[0]->fit);
        self::assertSame('/result.webp', $urls[0]->url);
        self::assertGreaterThanOrEqual(0.0, $urls[0]->duration);
    }

    public function testImageDataForwardsToInnerAndRecordsRender(): void
    {
        $renderData = new ImageRenderData(
            fallbackSrc: '/a.jpg',
            fallbackSrcset: null,
            sources: [],
            placeholderUri: null,
            width: 1920,
            height: 1080,
            loading: 'lazy',
            fetchPriority: null,
            sizes: null,
            unoptimized: false,
        );

        $inner = $this->createMock(ImageHelperInterface::class);
        $inner->expects(self::once())
            ->method('imageData')
            ->willReturn($renderData);

        $collector = new PicassoDataCollector();
        $decorator = new CollectingImageHelper($inner, $collector);

        $result = $decorator->imageData(
            src: 'hero.jpg',
            width: 1920,
            height: 1080,
            loader: 'filesystem',
            transformer: 'glide',
            placeholder: 'blur',
        );

        self::assertSame($renderData, $result);

        $collector->collect(new Request(), new Response());
        $renders = $collector->getRenders();
        self::assertCount(1, $renders);
        self::assertSame('hero.jpg', $renders[0]->src);
        self::assertSame('filesystem', $renders[0]->loader);
        self::assertSame('glide', $renders[0]->transformer);
        self::assertSame('blur', $renders[0]->placeholder);
        self::assertSame(1920, $renders[0]->width);
        self::assertSame(1080, $renders[0]->height);
        self::assertFalse($renders[0]->priority);
        self::assertFalse($renders[0]->hasPlaceholder);
    }
}
