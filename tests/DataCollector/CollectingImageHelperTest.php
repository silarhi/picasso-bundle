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

use function array_slice;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\DataCollector\CollectingImageHelper;
use Silarhi\PicassoBundle\DataCollector\PicassoDataCollector;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CollectingImageHelperTest extends TestCase
{
    public function testImageUrlForwardsToInnerAndRecordsCall(): void
    {
        $inner = $this->createMock(ImageHelperInterface::class);
        $inner->expects(self::once())
            ->method('imageUrl')
            ->with('hero.jpg', 800, 600, 'webp', 80, 'cover', null, null, 'filesystem', 'glide', ['ctx' => 1], 'document_image', ['id' => 42])
            ->willReturn('/result.webp');

        $collector = new PicassoDataCollector();
        $decorator = new CollectingImageHelper($inner, $collector, $this->createPipeline());

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
            route: 'document_image',
            routeParameters: ['id' => 42],
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

    public function testImageUrlRecordsResolvedDefaultNames(): void
    {
        $inner = $this->createMock(ImageHelperInterface::class);
        $inner->method('imageUrl')->willReturn('/result.webp');

        $collector = new PicassoDataCollector();
        $decorator = new CollectingImageHelper($inner, $collector, $this->createPipeline());

        $decorator->imageUrl(path: 'hero.jpg');

        $collector->collect(new Request(), new Response());
        $urls = $collector->getUrls();
        self::assertCount(1, $urls);
        self::assertSame('filesystem', $urls[0]->loader, 'null loader is recorded under its resolved default name');
        self::assertSame('glide', $urls[0]->transformer, 'null transformer is recorded under its resolved default name');
    }

    public function testImageUrlRecordsLoaderDefaultTransformer(): void
    {
        $inner = $this->createMock(ImageHelperInterface::class);
        $inner->expects(self::exactly(3))->method('imageUrl')->willReturn('/result.webp');

        $collector = new PicassoDataCollector();
        $pipeline = new ImagePipeline(
            new LoaderRegistry(new ServiceLocator([]), defaultTransformers: ['private_files' => 'private_glide']),
            new TransformerRegistry(new ServiceLocator([])),
            'filesystem',
            'glide',
        );
        $decorator = new CollectingImageHelper($inner, $collector, $pipeline);

        $decorator->imageUrl(path: 'contracts/42.jpg', loader: 'private_files');
        $decorator->imageUrl(path: 'contracts/42.jpg', loader: 'private_files', transformer: 'glide');
        $decorator->imageUrl(path: 'photo.jpg');

        $collector->collect(new Request(), new Response());
        $urls = $collector->getUrls();
        self::assertCount(3, $urls);
        self::assertSame('private_glide', $urls[0]->transformer, 'the loader default_transformer is recorded, like ImagePipeline::url() uses it');
        self::assertSame('glide', $urls[1]->transformer, 'an explicit transformer overrides the loader default');
        self::assertSame('glide', $urls[2]->transformer, 'a loader without default_transformer falls back to the global default');
    }

    public function testImageDataForwardsToInnerAndRecordsResolvedRender(): void
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
            loader: 'filesystem',
            transformer: 'glide',
            placeholder: 'blur',
        );

        $forwarded = [];
        $inner = $this->createMock(ImageHelperInterface::class);
        $inner->expects(self::once())
            ->method('imageData')
            ->willReturnCallback(static function (mixed ...$arguments) use (&$forwarded, $renderData): ImageRenderData {
                $forwarded = $arguments;

                return $renderData;
            });

        $collector = new PicassoDataCollector();
        $decorator = new CollectingImageHelper($inner, $collector, $this->createPipeline());

        $result = $decorator->imageData(src: 'hero.jpg', width: 1920, height: 1080, route: 'document_image', routeParameters: ['id' => 42]);

        self::assertSame($renderData, $result);
        self::assertSame(['document_image', ['id' => 42]], array_slice($forwarded, -2), 'the route reaches the inner helper');

        $collector->collect(new Request(), new Response());
        $renders = $collector->getRenders();
        self::assertCount(1, $renders);
        self::assertSame('hero.jpg', $renders[0]->src);
        self::assertSame('filesystem', $renders[0]->loader, 'loader name comes from the resolved render data');
        self::assertSame('glide', $renders[0]->transformer, 'transformer name comes from the resolved render data');
        self::assertSame('blur', $renders[0]->placeholder, 'placeholder name comes from the resolved render data');
        self::assertSame(1920, $renders[0]->width);
        self::assertSame(1080, $renders[0]->height);
        self::assertFalse($renders[0]->priority);
        self::assertFalse($renders[0]->hasPlaceholder);
    }

    private function createPipeline(): ImagePipeline&MockObject
    {
        $pipeline = $this->createMock(ImagePipeline::class);
        $pipeline->method('resolveLoaderName')
            ->willReturnCallback(static fn (?string $loader): string => $loader ?? 'filesystem');
        $pipeline->method('resolveTransformerName')
            ->willReturnCallback(static fn (?string $transformer, ?string $loaderName = null): string => $transformer ?? 'glide');

        return $pipeline;
    }
}
