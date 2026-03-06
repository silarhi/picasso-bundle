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

namespace Silarhi\PicassoBundle\Tests\Twig\Component;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Dto\SrcsetEntry;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Twig\Component\ImageComponent;

class ImageComponentTest extends TestCase
{
    private MockObject&SrcsetGenerator $srcsetGenerator;
    private MockObject&ImagePipeline $pipeline;
    private TransformerRegistry $transformerRegistry;
    private MockObject&ImageTransformerInterface $glideTransformer;
    private MockObject&MetadataGuesserInterface $metadataGuesser;

    protected function setUp(): void
    {
        $this->srcsetGenerator = $this->createMock(SrcsetGenerator::class);
        $this->metadataGuesser = $this->createMock(MetadataGuesserInterface::class);
        $this->glideTransformer = $this->createMock(ImageTransformerInterface::class);

        $transformerLocator = $this->createMock(ContainerInterface::class);
        $transformerLocator->method('has')->with('glide')->willReturn(true);
        $transformerLocator->method('get')->with('glide')->willReturn($this->glideTransformer);
        $this->transformerRegistry = new TransformerRegistry($transformerLocator);

        $this->pipeline = $this->createMock(ImagePipeline::class);
        $this->pipeline->method('resolveLoaderName')->willReturn('filesystem');
        $this->pipeline->method('resolveTransformerName')->willReturn('glide');
    }

    private function createComponent(bool $blurEnabled = false): ImageComponent
    {
        return new ImageComponent(
            srcsetGenerator: $this->srcsetGenerator,
            pipeline: $this->pipeline,
            transformerRegistry: $this->transformerRegistry,
            metadataGuesser: $this->metadataGuesser,
            formats: ['avif', 'webp', 'jpg'],
            defaultQuality: 75,
            defaultFit: 'contain',
            blurEnabled: $blurEnabled,
            blurSize: 10,
            blurAmount: 50,
            blurQuality: 30,
        );
    }

    public function testComputeImageDataLoadsImageFromLoader(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'uploads/photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'uploads/photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertNotNull($component->fallbackSrc);
        self::assertSame(1920, $component->width);
        self::assertSame(1080, $component->height);
    }

    public function testComputeImageDataHandlesNullSrc(): void
    {
        $this->configureSrcsetGenerator();
        $this->pipeline->method('load')
            ->willReturn(new Image());

        $component = $this->createComponent();
        $component->computeImageData();

        // Even with null src, pipeline processes the image
        self::assertNotNull($component->fallbackSrc);
    }

    public function testComputeImageDataUsesSourceWidthHeight(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg'));
        $this->metadataGuesser->expects(self::never())->method('guess');
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->sourceWidth = 800;
        $component->sourceHeight = 600;
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame(800, $component->width);
        self::assertSame(600, $component->height);
    }

    public function testComputeImageDataUsesLoaderMetadata(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', width: 1600, height: 900));
        $this->metadataGuesser->expects(self::never())->method('guess');
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame(1600, $component->width);
        self::assertSame(900, $component->height);
    }

    public function testComputeImageDataUsesMetadataGuesserForDimensions(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->expects(self::once())
            ->method('guess')
            ->with($stream)
            ->willReturn(['width' => 1024, 'height' => 768, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame(1024, $component->width);
        self::assertSame(768, $component->height);
    }

    public function testComputeImageDataGeneratesBlurPlaceholder(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $this->glideTransformer->method('url')
            ->with(
                self::isInstanceOf(Image::class),
                self::callback(static fn (ImageTransformation $t): bool => 10 === $t->width
                    && 6 === $t->height
                    && 'jpg' === $t->format
                    && 30 === $t->quality
                    && 'crop' === $t->fit
                    && 50 === $t->blur),
                ['loader' => 'filesystem'],
            )
            ->willReturn('/picasso/glide/filesystem/photo.jpg?w=10&h=6&fm=jpg&q=30&blur=50');

        $component = $this->createComponent(blurEnabled: true);
        $component->src = 'photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('/picasso/glide/filesystem/photo.jpg?w=10&h=6&fm=jpg&q=30&blur=50', $component->blurDataUri);
    }

    public function testComputeImageDataSkipsBlurWhenPlaceholderFalse(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']);
        $this->glideTransformer->expects(self::never())->method('url');
        $this->configureSrcsetGenerator();

        $component = $this->createComponent(blurEnabled: true);
        $component->src = 'photo.jpg';
        $component->placeholder = false;
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertNull($component->blurDataUri);
    }

    public function testComputeImageDataSkipsBlurWhenConfigDisabled(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']);
        $this->glideTransformer->expects(self::never())->method('url');
        $this->configureSrcsetGenerator();

        $component = $this->createComponent(blurEnabled: false);
        $component->src = 'photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertNull($component->blurDataUri);
    }

    public function testComputeImageDataGeneratesSourcesAndFallback(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg'));

        $this->srcsetGenerator->method('generateSrcset')
            ->willReturnCallback(static fn (ImageTransformerInterface $t, Image $img, string $format): array => [
                new SrcsetEntry("/img/{$img->path}?fm={$format}&w=640", '640w'),
                new SrcsetEntry("/img/{$img->path}?fm={$format}&w=1080", '1080w'),
            ]);

        $this->srcsetGenerator->method('buildSrcsetString')
            ->willReturnCallback(static fn (array $entries): string => implode(', ', array_map(
                static fn (mixed $e): string => ($e instanceof SrcsetEntry) ? $e->toString() : '',
                $entries,
            )));

        $this->srcsetGenerator->method('getFallbackUrl')
            ->willReturn('/img/photo.jpg?fm=jpg&w=800');

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->width = 800;
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertCount(2, $component->sources);
        self::assertInstanceOf(ImageSource::class, $component->sources[0]);
        self::assertInstanceOf(ImageSource::class, $component->sources[1]);
        self::assertSame('image/avif', $component->sources[0]->type);
        self::assertSame('image/webp', $component->sources[1]->type);

        self::assertSame('/img/photo.jpg?fm=jpg&w=800', $component->fallbackSrc);
        self::assertNotNull($component->fallbackSrcset);
        self::assertStringContainsString('fm=jpg', $component->fallbackSrcset);
    }

    public function testComputeImageDataUsesCustomLoader(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $pipeline = $this->createMock(ImagePipeline::class);
        $pipeline->method('resolveLoaderName')->with('custom')->willReturn('custom');
        $pipeline->method('resolveTransformerName')->willReturn('glide');
        $pipeline->method('load')
            ->willReturn(new Image(path: 'custom/photo.jpg', stream: $stream));

        $metadataGuesser = $this->createMock(MetadataGuesserInterface::class);
        $metadataGuesser->method('guess')
            ->willReturn(['width' => 500, 'height' => 500, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $component = new ImageComponent(
            srcsetGenerator: $this->srcsetGenerator,
            pipeline: $pipeline,
            transformerRegistry: $this->transformerRegistry,
            metadataGuesser: $metadataGuesser,
            formats: ['avif', 'webp', 'jpg'],
            defaultQuality: 75,
            defaultFit: 'contain',
            blurEnabled: false,
            blurSize: 10,
            blurAmount: 50,
            blurQuality: 30,
        );

        $component->src = 'photo.jpg';
        $component->loader = 'custom';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame(500, $component->width);
        self::assertSame(500, $component->height);
    }

    public function testUnoptimizedServesRawSrc(): void
    {
        $this->srcsetGenerator->expects(self::never())->method('generateSrcset');
        $this->srcsetGenerator->expects(self::never())->method('getFallbackUrl');

        $component = $this->createComponent();
        $component->src = '/images/logo.svg';
        $component->unoptimized = true;
        $component->width = 200;
        $component->height = 50;
        $component->computeImageData();

        self::assertSame('/images/logo.svg', $component->fallbackSrc);
        self::assertNull($component->fallbackSrcset);
        self::assertSame([], $component->sources);
        self::assertNull($component->blurDataUri);
    }

    public function testDefaultValues(): void
    {
        $component = $this->createComponent();

        self::assertNull($component->src);
        self::assertNull($component->loader);
        self::assertNull($component->transformer);
        self::assertNull($component->quality);
        self::assertNull($component->fit);
        self::assertNull($component->placeholder);
        self::assertFalse($component->priority);
        self::assertNull($component->loading);
        self::assertNull($component->fetchPriority);
        self::assertFalse($component->unoptimized);
        self::assertSame([], $component->context);
    }

    public function testPlaceholderPropOverridesConfig(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 800, 'height' => 600, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $this->glideTransformer->method('url')
            ->willReturn('/picasso/glide/filesystem/photo.jpg?w=10&h=8&fm=jpg&q=30&blur=50');

        $component = $this->createComponent(blurEnabled: false);
        $component->src = 'photo.jpg';
        $component->placeholder = true;
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertNotNull($component->blurDataUri);
    }

    public function testSkipsMetadataGuesserWhenNoStream(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg'));
        $this->metadataGuesser->expects(self::never())->method('guess');
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertNull($component->width);
        self::assertNull($component->height);
    }

    public function testFitDefaultsToConfigValue(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg'));

        $this->srcsetGenerator->expects(self::atLeastOnce())
            ->method('generateSrcset')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                'cover',
                self::anything(),
                self::anything(),
            )
            ->willReturn([new SrcsetEntry('/img/photo.jpg?w=640', '640w')]);
        $this->srcsetGenerator->method('buildSrcsetString')->willReturn('/img/photo.jpg?w=640 640w');
        $this->srcsetGenerator->method('getFallbackUrl')->willReturn('/img/photo.jpg');

        $component = new ImageComponent(
            srcsetGenerator: $this->srcsetGenerator,
            pipeline: $this->pipeline,
            transformerRegistry: $this->transformerRegistry,
            metadataGuesser: $this->metadataGuesser,
            formats: ['jpg'],
            defaultQuality: 75,
            defaultFit: 'cover',
            blurEnabled: false,
            blurSize: 10,
            blurAmount: 50,
            blurQuality: 30,
        );

        $component->src = 'photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();
    }

    public function testPriorityDisablesBlurAndSetsEagerLoading(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']);
        $this->glideTransformer->expects(self::never())->method('url');
        $this->configureSrcsetGenerator();

        $component = $this->createComponent(blurEnabled: true);
        $component->src = 'photo.jpg';
        $component->priority = true;
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertNull($component->blurDataUri);
        self::assertSame('eager', $component->loading);
        self::assertSame('high', $component->fetchPriority);
    }

    public function testLoadingPropOverridesPriority(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg'));
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->priority = true;
        $component->loading = 'lazy';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('lazy', $component->loading);
        self::assertSame('high', $component->fetchPriority);
    }

    public function testNonPriorityDefaultsToLazy(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg'));
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('lazy', $component->loading);
        self::assertNull($component->fetchPriority);
    }

    private function configureSrcsetGenerator(): void
    {
        $this->srcsetGenerator->method('generateSrcset')->willReturn([
            new SrcsetEntry('/img/photo.jpg?w=640', '640w'),
        ]);
        $this->srcsetGenerator->method('buildSrcsetString')->willReturn('/img/photo.jpg?w=640 640w');
        $this->srcsetGenerator->method('getFallbackUrl')->willReturn('/img/photo.jpg');
    }
}
