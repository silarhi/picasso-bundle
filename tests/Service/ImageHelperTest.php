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

namespace Silarhi\PicassoBundle\Tests\Service;

use Closure;

use function in_array;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Dto\SrcsetEntry;
use Silarhi\PicassoBundle\Placeholder\PlaceholderInterface;
use Silarhi\PicassoBundle\Service\ImageHelper;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;
use Silarhi\PicassoBundle\Service\PlaceholderRegistry;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;

class ImageHelperTest extends TestCase
{
    private MockObject&SrcsetGenerator $srcsetGenerator;
    private MockObject&ImagePipeline $pipeline;
    private TransformerRegistry $transformerRegistry;
    private MockObject&ImageTransformerInterface $glideTransformer;
    private MockObject&MetadataGuesserInterface $metadataGuesser;
    private PlaceholderRegistry $placeholderRegistry;
    private LoaderRegistry $loaderRegistry;
    private MockObject&PlaceholderInterface $mockPlaceholder;

    protected function setUp(): void
    {
        $this->srcsetGenerator = $this->createMock(SrcsetGenerator::class);
        $this->metadataGuesser = $this->createMock(MetadataGuesserInterface::class);
        $this->glideTransformer = $this->createMock(ImageTransformerInterface::class);

        $transformerLocator = $this->createMock(ContainerInterface::class);
        $transformerLocator->method('has')->with('glide')->willReturn(true);
        $transformerLocator->method('get')->with('glide')->willReturn($this->glideTransformer);
        $this->transformerRegistry = new TransformerRegistry($transformerLocator);

        $this->mockPlaceholder = $this->createMock(PlaceholderInterface::class);
        $placeholderLocator = $this->createMock(ContainerInterface::class);
        $placeholderLocator->method('has')->willReturnCallback(static fn (string $name): bool => 'blur' === $name);
        $placeholderLocator->method('get')->with('blur')->willReturn($this->mockPlaceholder);
        $this->placeholderRegistry = new PlaceholderRegistry($placeholderLocator);

        $loaderLocator = $this->createMock(ContainerInterface::class);
        $loaderLocator->method('has')->willReturn(false);
        $this->loaderRegistry = new LoaderRegistry($loaderLocator, [], [], ['filesystem' => true]);

        $this->pipeline = $this->createMock(ImagePipeline::class);
        $this->pipeline->method('resolveLoaderName')->willReturn('filesystem');
        $this->pipeline->method('resolveTransformerName')->willReturn('glide');
    }

    private function createHelper(?string $defaultPlaceholder = null, ?LoaderRegistry $loaderRegistry = null): ImageHelper
    {
        return new ImageHelper(
            pipeline: $this->pipeline,
            srcsetGenerator: $this->srcsetGenerator,
            transformerRegistry: $this->transformerRegistry,
            metadataGuesser: $this->metadataGuesser,
            placeholderRegistry: $this->placeholderRegistry,
            loaderRegistry: $loaderRegistry ?? $this->loaderRegistry,
            formats: ['avif', 'webp', 'jpg'],
            defaultQuality: 75,
            defaultFit: 'contain',
            defaultPlaceholder: $defaultPlaceholder,
        );
    }

    // --- imageUrl() tests ---

    public function testImageUrlDelegatesToPipeline(): void
    {
        $pipeline = $this->createMock(ImagePipeline::class);
        $pipeline->expects(self::once())
            ->method('url')
            ->with(
                self::callback(static fn (ImageReference $r): bool => 'photo.jpg' === $r->path),
                self::callback(static fn (ImageTransformation $t): bool => 300 === $t->width && 'webp' === $t->format),
                null,
                null,
            )
            ->willReturn('/picasso/glide/filesystem/photo.jpg?w=300&fm=webp&s=abc');

        $helper = new ImageHelper(
            pipeline: $pipeline,
            srcsetGenerator: $this->srcsetGenerator,
            transformerRegistry: $this->transformerRegistry,
            metadataGuesser: $this->metadataGuesser,
            placeholderRegistry: $this->placeholderRegistry,
            loaderRegistry: $this->loaderRegistry,
            formats: ['avif', 'webp', 'jpg'],
            defaultQuality: 75,
            defaultFit: 'contain',
        );
        $result = $helper->imageUrl('photo.jpg', width: 300, format: 'webp');

        self::assertSame('/picasso/glide/filesystem/photo.jpg?w=300&fm=webp&s=abc', $result);
    }

    public function testImageUrlPassesLoaderAndTransformer(): void
    {
        $pipeline = $this->createMock(ImagePipeline::class);
        $pipeline->expects(self::once())
            ->method('url')
            ->with(
                self::isInstanceOf(ImageReference::class),
                self::isInstanceOf(ImageTransformation::class),
                'vich',
                'imgix',
            )
            ->willReturn('https://cdn.imgix.net/photo.jpg?w=300');

        $helper = new ImageHelper(
            pipeline: $pipeline,
            srcsetGenerator: $this->srcsetGenerator,
            transformerRegistry: $this->transformerRegistry,
            metadataGuesser: $this->metadataGuesser,
            placeholderRegistry: $this->placeholderRegistry,
            loaderRegistry: $this->loaderRegistry,
            formats: ['avif', 'webp', 'jpg'],
            defaultQuality: 75,
            defaultFit: 'contain',
        );
        $result = $helper->imageUrl('photo.jpg', width: 300, loader: 'vich', transformer: 'imgix');

        self::assertSame('https://cdn.imgix.net/photo.jpg?w=300', $result);
    }

    public function testImageUrlUsesConfiguredDefaults(): void
    {
        $pipeline = $this->createMock(ImagePipeline::class);
        $pipeline->expects(self::once())
            ->method('url')
            ->with(
                self::isInstanceOf(ImageReference::class),
                self::callback(static fn (ImageTransformation $t): bool => 90 === $t->quality && 'cover' === $t->fit),
                null,
                null,
            )
            ->willReturn('/img/photo.jpg');

        $helper = new ImageHelper(
            pipeline: $pipeline,
            srcsetGenerator: $this->srcsetGenerator,
            transformerRegistry: $this->transformerRegistry,
            metadataGuesser: $this->metadataGuesser,
            placeholderRegistry: $this->placeholderRegistry,
            loaderRegistry: $this->loaderRegistry,
            formats: ['avif', 'webp', 'jpg'],
            defaultQuality: 90,
            defaultFit: 'cover',
        );
        $helper->imageUrl('photo.jpg');
    }

    // --- imageData() tests ---

    public function testImageDataLoadsImageAndResolvesDimensions(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'uploads/photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(src: 'uploads/photo.jpg', sizes: '100vw');

        self::assertNotNull($data->fallbackSrc);
        self::assertSame(1920, $data->width);
        self::assertSame(1080, $data->height);
        self::assertSame('lazy', $data->loading);
        self::assertNull($data->fetchPriority);
        self::assertFalse($data->unoptimized);
    }

    public function testImageDataHandlesNullSrc(): void
    {
        $this->configureSrcsetGenerator();
        $this->pipeline->method('load')
            ->willReturn(new Image());

        $helper = $this->createHelper();
        $data = $helper->imageData();

        self::assertNotNull($data->fallbackSrc);
    }

    public function testImageDataUsesSourceWidthHeight(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg'));
        $this->metadataGuesser->expects(self::never())->method('guess');
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(
            src: 'photo.jpg',
            sourceWidth: 800,
            sourceHeight: 600,
            sizes: '100vw',
        );

        self::assertSame(800, $data->width);
        self::assertSame(600, $data->height);
    }

    public function testImageDataUsesLoaderMetadata(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', width: 1600, height: 900));
        $this->metadataGuesser->expects(self::never())->method('guess');
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(src: 'photo.jpg', sizes: '100vw');

        self::assertSame(1600, $data->width);
        self::assertSame(900, $data->height);
    }

    public function testImageDataUsesMetadataGuesserForDimensions(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->expects(self::once())
            ->method('guess')
            ->with(self::isInstanceOf(Closure::class))
            ->willReturn(['width' => 1024, 'height' => 768, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(src: 'photo.jpg', sizes: '100vw');

        self::assertSame(1024, $data->width);
        self::assertSame(768, $data->height);
    }

    public function testImageDataGeneratesPlaceholder(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $this->mockPlaceholder->expects(self::once())
            ->method('generate')
            ->with(
                self::isInstanceOf(Image::class),
                self::callback(static fn (ImageTransformation $t): bool => 1920 === $t->width
                    && 1080 === $t->height
                    && 75 === $t->quality
                    && 'contain' === $t->fit),
                ['loader' => 'filesystem', 'transformer' => 'glide'],
            )
            ->willReturn('/picasso/glide/filesystem/photo.jpg?w=10&h=6&fm=jpg&q=30&blur=50');

        $helper = $this->createHelper(defaultPlaceholder: 'blur');
        $data = $helper->imageData(src: 'photo.jpg', sizes: '100vw');

        self::assertSame('/picasso/glide/filesystem/photo.jpg?w=10&h=6&fm=jpg&q=30&blur=50', $data->placeholderUri);
    }

    public function testImageDataSkipsPlaceholderWhenPlaceholderFalse(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']);
        $this->mockPlaceholder->expects(self::never())->method('generate');
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper(defaultPlaceholder: 'blur');
        $data = $helper->imageData(src: 'photo.jpg', placeholder: false, sizes: '100vw');

        self::assertNull($data->placeholderUri);
    }

    public function testImageDataSkipsPlaceholderWhenNoDefault(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']);
        $this->mockPlaceholder->expects(self::never())->method('generate');
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(src: 'photo.jpg', sizes: '100vw');

        self::assertNull($data->placeholderUri);
    }

    public function testLoaderDefaultPlaceholderOverridesGlobalDefault(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $this->mockPlaceholder->expects(self::once())
            ->method('generate')
            ->willReturn('data:image/png;base64,placeholder');

        $loaderLocator = $this->createMock(ContainerInterface::class);
        $loaderLocator->method('has')->willReturn(false);
        $loaderRegistry = new LoaderRegistry($loaderLocator, ['filesystem' => 'blur'], [], ['filesystem' => true]);

        $helper = $this->createHelper(defaultPlaceholder: null, loaderRegistry: $loaderRegistry);
        $data = $helper->imageData(src: 'photo.jpg', sizes: '100vw');

        self::assertSame('data:image/png;base64,placeholder', $data->placeholderUri);
    }

    public function testGlobalDefaultPlaceholderUsedWhenLoaderHasNone(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $this->mockPlaceholder->expects(self::once())
            ->method('generate')
            ->willReturn('data:image/png;base64,global');

        $helper = $this->createHelper(defaultPlaceholder: 'blur');
        $data = $helper->imageData(src: 'photo.jpg', sizes: '100vw');

        self::assertSame('data:image/png;base64,global', $data->placeholderUri);
    }

    public function testLoaderDefaultTransformerOverridesGlobalDefault(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $imgixTransformer = $this->createMock(ImageTransformerInterface::class);
        $transformerLocator = $this->createMock(ContainerInterface::class);
        $transformerLocator->method('has')->willReturnCallback(static fn (string $name): bool => in_array($name, ['glide', 'imgix'], true));
        $transformerLocator->method('get')->willReturnCallback(fn (string $name) => match ($name) {
            'imgix' => $imgixTransformer,
            default => $this->glideTransformer,
        });
        $transformerRegistry = new TransformerRegistry($transformerLocator);

        $pipeline = $this->createMock(ImagePipeline::class);
        $pipeline->method('resolveLoaderName')->willReturn('filesystem');
        $pipeline->method('resolveTransformerName')->with(null)->willReturn('glide');
        $pipeline->method('load')->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 800, 'height' => 600, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $loaderLocator = $this->createMock(ContainerInterface::class);
        $loaderLocator->method('has')->willReturn(false);
        $loaderRegistry = new LoaderRegistry($loaderLocator, [], ['filesystem' => 'imgix'], ['filesystem' => true]);

        $helper = new ImageHelper(
            pipeline: $pipeline,
            srcsetGenerator: $this->srcsetGenerator,
            transformerRegistry: $transformerRegistry,
            metadataGuesser: $this->metadataGuesser,
            placeholderRegistry: $this->placeholderRegistry,
            loaderRegistry: $loaderRegistry,
            formats: ['jpg'],
            defaultQuality: 75,
            defaultFit: 'contain',
        );

        $data = $helper->imageData(src: 'photo.jpg', sizes: '100vw');

        self::assertNotNull($data->fallbackSrc);
    }

    public function testGlobalDefaultTransformerUsedWhenLoaderHasNone(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 800, 'height' => 600, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(src: 'photo.jpg', sizes: '100vw');

        self::assertNotNull($data->fallbackSrc);
    }

    public function testExplicitTransformerOverridesLoaderDefault(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $pipeline = $this->createMock(ImagePipeline::class);
        $pipeline->method('resolveLoaderName')->willReturn('filesystem');
        $pipeline->method('resolveTransformerName')->with('glide')->willReturn('glide');
        $pipeline->method('load')->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 800, 'height' => 600, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $loaderLocator = $this->createMock(ContainerInterface::class);
        $loaderLocator->method('has')->willReturn(false);
        $loaderRegistry = new LoaderRegistry($loaderLocator, [], ['filesystem' => 'imgix'], ['filesystem' => true]);

        $helper = new ImageHelper(
            pipeline: $pipeline,
            srcsetGenerator: $this->srcsetGenerator,
            transformerRegistry: $this->transformerRegistry,
            metadataGuesser: $this->metadataGuesser,
            placeholderRegistry: $this->placeholderRegistry,
            loaderRegistry: $loaderRegistry,
            formats: ['jpg'],
            defaultQuality: 75,
            defaultFit: 'contain',
        );

        $data = $helper->imageData(src: 'photo.jpg', transformer: 'glide', sizes: '100vw');

        self::assertNotNull($data->fallbackSrc);
    }

    public function testImageDataGeneratesSourcesAndFallback(): void
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

        $helper = $this->createHelper();
        $data = $helper->imageData(src: 'photo.jpg', width: 800, sizes: '100vw');

        self::assertCount(2, $data->sources);
        self::assertInstanceOf(ImageSource::class, $data->sources[0]);
        self::assertInstanceOf(ImageSource::class, $data->sources[1]);
        self::assertSame('image/avif', $data->sources[0]->type);
        self::assertSame('image/webp', $data->sources[1]->type);

        self::assertSame('/img/photo.jpg?fm=jpg&w=800', $data->fallbackSrc);
        self::assertNotNull($data->fallbackSrcset);
        self::assertStringContainsString('fm=jpg', $data->fallbackSrcset);
    }

    public function testUnoptimizedServesRawSrc(): void
    {
        $this->srcsetGenerator->expects(self::never())->method('generateSrcset');
        $this->srcsetGenerator->expects(self::never())->method('getFallbackUrl');

        $helper = $this->createHelper();
        $data = $helper->imageData(
            src: '/images/logo.svg',
            unoptimized: true,
            width: 200,
            height: 50,
        );

        self::assertSame('/images/logo.svg', $data->fallbackSrc);
        self::assertNull($data->fallbackSrcset);
        self::assertSame([], $data->sources);
        self::assertNull($data->placeholderUri);
        self::assertTrue($data->unoptimized);
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

        $this->mockPlaceholder->method('generate')
            ->willReturn('/picasso/glide/filesystem/photo.jpg?blur=50');

        $helper = $this->createHelper(defaultPlaceholder: 'blur');
        $data = $helper->imageData(src: 'photo.jpg', placeholder: true, sizes: '100vw');

        self::assertNotNull($data->placeholderUri);
    }

    public function testPlaceholderStringSelectsNamedPlaceholder(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 800, 'height' => 600, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $this->mockPlaceholder->expects(self::once())
            ->method('generate')
            ->willReturn('/picasso/blur-url');

        $helper = $this->createHelper();
        $data = $helper->imageData(src: 'photo.jpg', placeholder: 'blur', sizes: '100vw');

        self::assertSame('/picasso/blur-url', $data->placeholderUri);
    }

    public function testPlaceholderDataBypassesPlaceholderService(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 800, 'height' => 600, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $this->mockPlaceholder->expects(self::never())->method('generate');

        $helper = $this->createHelper(defaultPlaceholder: 'blur');
        $data = $helper->imageData(
            src: 'photo.jpg',
            placeholderData: 'data:image/png;base64,iVBORw0KGgo=',
            sizes: '100vw',
        );

        self::assertSame('data:image/png;base64,iVBORw0KGgo=', $data->placeholderUri);
    }

    public function testSkipsMetadataGuesserWhenNoStream(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg'));
        $this->metadataGuesser->expects(self::never())->method('guess');
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(src: 'photo.jpg', sizes: '100vw');

        self::assertNull($data->width);
        self::assertNull($data->height);
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

        $helper = new ImageHelper(
            pipeline: $this->pipeline,
            srcsetGenerator: $this->srcsetGenerator,
            transformerRegistry: $this->transformerRegistry,
            metadataGuesser: $this->metadataGuesser,
            placeholderRegistry: $this->placeholderRegistry,
            loaderRegistry: $this->loaderRegistry,
            formats: ['jpg'],
            defaultQuality: 75,
            defaultFit: 'cover',
        );

        $helper->imageData(src: 'photo.jpg', sizes: '100vw');
    }

    public function testPriorityDisablesPlaceholderAndSetsEagerLoading(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->method('guess')
            ->willReturn(['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']);
        $this->mockPlaceholder->expects(self::never())->method('generate');
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper(defaultPlaceholder: 'blur');
        $data = $helper->imageData(src: 'photo.jpg', priority: true, sizes: '100vw');

        self::assertNull($data->placeholderUri);
        self::assertSame('eager', $data->loading);
        self::assertSame('high', $data->fetchPriority);
    }

    public function testLoadingPropOverridesPriority(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg'));
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(
            src: 'photo.jpg',
            priority: true,
            loading: 'lazy',
            sizes: '100vw',
        );

        self::assertSame('lazy', $data->loading);
        self::assertSame('high', $data->fetchPriority);
    }

    public function testNonPriorityDefaultsToLazy(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg'));
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(src: 'photo.jpg', sizes: '100vw');

        self::assertSame('lazy', $data->loading);
        self::assertNull($data->fetchPriority);
    }

    public function testGetMimeTypeForGifAndUnknownFormats(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'animation.gif'));

        $this->srcsetGenerator->method('generateSrcset')
            ->willReturnCallback(static fn (ImageTransformerInterface $t, Image $img, string $format): array => [
                new SrcsetEntry("/img/{$img->path}?fm={$format}&w=640", '640w'),
            ]);

        $this->srcsetGenerator->method('buildSrcsetString')
            ->willReturnCallback(static fn (array $entries): string => implode(', ', array_map(
                static fn (mixed $e): string => ($e instanceof SrcsetEntry) ? $e->toString() : '',
                $entries,
            )));

        $this->srcsetGenerator->method('getFallbackUrl')
            ->willReturn('/img/animation.gif?fm=gif&w=640');

        $helper = new ImageHelper(
            pipeline: $this->pipeline,
            srcsetGenerator: $this->srcsetGenerator,
            transformerRegistry: $this->transformerRegistry,
            metadataGuesser: $this->metadataGuesser,
            placeholderRegistry: $this->placeholderRegistry,
            loaderRegistry: $this->loaderRegistry,
            formats: ['gif', 'tiff', 'bmp'],
            defaultQuality: 75,
            defaultFit: 'contain',
        );

        $data = $helper->imageData(src: 'animation.gif', width: 640, sizes: '100vw');

        self::assertCount(2, $data->sources);
        self::assertSame('image/gif', $data->sources[0]->type);
        self::assertSame('image/tiff', $data->sources[1]->type);
    }

    public function testSkipsStreamResolutionWhenBothDisplayDimensionsProvided(): void
    {
        $this->pipeline->expects(self::once())
            ->method('load')
            ->with(self::anything(), self::anything(), false)
            ->willReturn(new Image(path: 'photo.jpg'));
        $this->metadataGuesser->expects(self::never())->method('guess');
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(
            src: 'photo.jpg',
            width: 400,
            height: 300,
            sizes: '100vw',
        );

        self::assertSame(400, $data->width);
        self::assertSame(300, $data->height);
    }

    public function testUpscalingPreventionWorksWithExplicitSourceAndDisplayDimensions(): void
    {
        $this->pipeline->expects(self::once())
            ->method('load')
            ->with(self::anything(), self::anything(), false)
            ->willReturn(new Image(path: 'photo.jpg'));
        $this->metadataGuesser->expects(self::never())->method('guess');
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(
            src: 'photo.jpg',
            width: 800,
            height: 600,
            sourceWidth: 640,
            sourceHeight: 480,
            sizes: '100vw',
        );

        self::assertSame(640, $data->width);
        self::assertSame(480, $data->height);
    }

    public function testImageDataIncludesAttributes(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg'));
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(
            src: 'photo.jpg',
            width: 400,
            height: 300,
            attributes: ['alt' => 'A photo', 'class' => 'hero-image'],
        );

        self::assertSame(['alt' => 'A photo', 'class' => 'hero-image'], $data->attributes);
    }

    public function testImageDataIncludesSizes(): void
    {
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg'));
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(src: 'photo.jpg', width: 400, height: 300, sizes: '(max-width: 768px) 100vw, 50vw');

        self::assertSame('(max-width: 768px) 100vw, 50vw', $data->sizes);
    }

    public function testResolveMetadataFalseSkipsGuesser(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->expects(self::never())->method('guess');
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(src: 'photo.jpg', resolveMetadata: false, sizes: '100vw');

        self::assertNull($data->width);
        self::assertNull($data->height);
    }

    public function testResolveMetadataTrueEnablesGuesser(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->expects(self::once())
            ->method('guess')
            ->willReturn(['width' => 1024, 'height' => 768, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $loaderLocator = $this->createMock(ContainerInterface::class);
        $loaderLocator->method('has')->willReturn(false);
        $loaderRegistry = new LoaderRegistry($loaderLocator);

        $helper = $this->createHelper(loaderRegistry: $loaderRegistry);
        $data = $helper->imageData(src: 'photo.jpg', resolveMetadata: true, sizes: '100vw');

        self::assertSame(1024, $data->width);
        self::assertSame(768, $data->height);
    }

    public function testResolveMetadataPerLoaderOverridesGlobal(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->expects(self::once())
            ->method('guess')
            ->willReturn(['width' => 1024, 'height' => 768, 'mimeType' => 'image/jpeg']);
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(src: 'photo.jpg', sizes: '100vw');

        self::assertSame(1024, $data->width);
        self::assertSame(768, $data->height);
    }

    public function testResolveMetadataGlobalDefaultFalseSkipsGuesser(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->expects(self::never())->method('guess');
        $this->configureSrcsetGenerator();

        $loaderLocator = $this->createMock(ContainerInterface::class);
        $loaderLocator->method('has')->willReturn(false);
        $loaderRegistry = new LoaderRegistry($loaderLocator);

        $helper = $this->createHelper(loaderRegistry: $loaderRegistry);
        $data = $helper->imageData(src: 'photo.jpg', sizes: '100vw');

        self::assertNull($data->width);
        self::assertNull($data->height);
    }

    public function testResolveMetadataRuntimeOverridesPerLoader(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->pipeline->method('load')
            ->willReturn(new Image(path: 'photo.jpg', stream: $stream));
        $this->metadataGuesser->expects(self::never())->method('guess');
        $this->configureSrcsetGenerator();

        $helper = $this->createHelper();
        $data = $helper->imageData(src: 'photo.jpg', resolveMetadata: false, sizes: '100vw');

        self::assertNull($data->width);
        self::assertNull($data->height);
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
