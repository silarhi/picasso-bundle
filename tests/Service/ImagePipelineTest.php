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
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;
use Silarhi\PicassoBundle\Exception\TransformerNotFoundException;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Transformer\PurgableTransformerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

class ImagePipelineTest extends TestCase
{
    private MockObject&ImageLoaderInterface $loader;
    private MockObject&ImageTransformerInterface $transformer;
    private ImagePipeline $pipeline;

    protected function setUp(): void
    {
        $this->loader = $this->createMock(ImageLoaderInterface::class);
        $this->transformer = $this->createMock(ImageTransformerInterface::class);

        $loaderLocator = self::createStub(ContainerInterface::class);
        $loaderLocator->method('has')->willReturnCallback(static fn (string $key): bool => 'filesystem' === $key);
        $loaderLocator->method('get')->willReturnCallback(fn (string $key): MockObject => match ($key) {
            'filesystem' => $this->loader,
            default => throw new InvalidArgumentException("Unknown loader: $key"),
        });

        $transformerLocator = self::createStub(ContainerInterface::class);
        $transformerLocator->method('has')->willReturnCallback(static fn (string $key): bool => 'glide' === $key);
        $transformerLocator->method('get')->willReturnCallback(fn (string $key): MockObject => match ($key) {
            'glide' => $this->transformer,
            default => throw new InvalidArgumentException("Unknown transformer: $key"),
        });

        $this->pipeline = new ImagePipeline(
            new LoaderRegistry($loaderLocator),
            new TransformerRegistry($transformerLocator),
            'filesystem',
            'glide',
        );
    }

    public function testUrlLoadsImageAndTransforms(): void
    {
        $image = new Image(path: 'uploads/photo.jpg');
        $reference = new ImageReference('uploads/photo.jpg');
        $transformation = new ImageTransformation(width: 300, format: 'webp');

        $this->loader->expects(self::once())
            ->method('load')
            ->with($reference)
            ->willReturn($image);

        $this->transformer->expects(self::once())
            ->method('url')
            ->with($image, $transformation, ['loader' => 'filesystem', 'transformer' => 'glide'])
            ->willReturn('/picasso/glide/filesystem/uploads/photo.jpg?w=300&fm=webp&s=abc');

        $url = $this->pipeline->url($reference, $transformation);

        self::assertSame('/picasso/glide/filesystem/uploads/photo.jpg?w=300&fm=webp&s=abc', $url);
    }

    public function testLoadReturnsImage(): void
    {
        $image = new Image(path: 'photo.jpg');
        $reference = new ImageReference('photo.jpg');

        $this->loader->expects(self::once())
            ->method('load')
            ->with($reference, false)
            ->willReturn($image);

        $result = $this->pipeline->load($reference);

        self::assertSame($image, $result);
    }

    public function testLoadPassesWithMetadata(): void
    {
        $image = new Image(path: 'photo.jpg', width: 800, height: 600);
        $reference = new ImageReference('photo.jpg');

        $this->loader->expects(self::once())
            ->method('load')
            ->with($reference, true)
            ->willReturn($image);

        $result = $this->pipeline->load($reference, withMetadata: true);

        self::assertSame(800, $result->width);
        self::assertSame(600, $result->height);
    }

    public function testPurgeDelegatesToTransformerWithDefaultNames(): void
    {
        $transformer = $this->createMock(PurgableTransformerInterface::class);
        $transformer->expects(self::once())
            ->method('purge')
            ->with('uploads/photo.jpg', ['loader' => 'filesystem', 'transformer' => 'glide']);

        $this->expectNoLoadOrUrlOnSetUpMocks();

        $this->createPurgePipeline(['glide' => $transformer])->purge('uploads/photo.jpg');
    }

    public function testPurgeUsesExplicitLoaderAndTransformerNames(): void
    {
        $transformer = $this->createMock(PurgableTransformerInterface::class);
        $transformer->expects(self::once())
            ->method('purge')
            ->with('products/1.jpg', ['loader' => 'vich', 'transformer' => 'imgix']);

        $this->expectNoLoadOrUrlOnSetUpMocks();

        $this->createPurgePipeline(['imgix' => $transformer])->purge('products/1.jpg', 'vich', 'imgix');
    }

    public function testPurgeThrowsWhenTransformerDoesNotSupportPurging(): void
    {
        // Purging never loads the source image nor builds a URL
        $this->expectNoLoadOrUrlOnSetUpMocks();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Transformer "glide" does not support cache purging.');

        $this->pipeline->purge('uploads/photo.jpg');
    }

    public function testUrlUsesLoaderDefaultTransformer(): void
    {
        $image = new Image(path: 'contracts/42.jpg');
        $reference = new ImageReference('contracts/42.jpg');
        $transformation = new ImageTransformation(width: 300);

        $this->loader->expects(self::once())->method('load')->with($reference)->willReturn($image);
        $privateGlide = $this->createMock(ImageTransformerInterface::class);
        $privateGlide->expects(self::once())
            ->method('url')
            ->with($image, $transformation, ['loader' => 'private_files', 'transformer' => 'private_glide'])
            ->willReturn('/image/private_glide/private_files/contracts/42.jpg?w=300&s=abc');
        $this->transformer->expects(self::never())->method('url');

        $pipeline = $this->createPipeline(
            ['private_files' => $this->loader],
            ['glide' => $this->transformer, 'private_glide' => $privateGlide],
            ['private_files' => 'private_glide'],
        );

        self::assertSame(
            '/image/private_glide/private_files/contracts/42.jpg?w=300&s=abc',
            $pipeline->url($reference, $transformation, 'private_files'),
        );
    }

    public function testUrlUsesDefaultLoaderDefaultTransformer(): void
    {
        $image = new Image(path: 'photo.jpg');

        $this->loader->expects(self::once())->method('load')->willReturn($image);
        $imgix = $this->createMock(ImageTransformerInterface::class);
        $imgix->expects(self::once())->method('url')->willReturn('https://cdn.example/photo.jpg');
        $this->transformer->expects(self::never())->method('url');

        $pipeline = $this->createPipeline(
            ['filesystem' => $this->loader],
            ['glide' => $this->transformer, 'imgix' => $imgix],
            ['filesystem' => 'imgix'],
        );

        self::assertSame('https://cdn.example/photo.jpg', $pipeline->url(new ImageReference('photo.jpg'), new ImageTransformation()));
    }

    public function testUrlExplicitTransformerOverridesLoaderDefault(): void
    {
        $image = new Image(path: 'photo.jpg');

        $this->loader->expects(self::once())->method('load')->willReturn($image);
        $imgix = $this->createMock(ImageTransformerInterface::class);
        $imgix->expects(self::never())->method('url');
        $this->transformer->expects(self::once())
            ->method('url')
            ->with($image, self::anything(), ['loader' => 'filesystem', 'transformer' => 'glide'])
            ->willReturn('/image/glide/filesystem/photo.jpg');

        $pipeline = $this->createPipeline(
            ['filesystem' => $this->loader],
            ['glide' => $this->transformer, 'imgix' => $imgix],
            ['filesystem' => 'imgix'],
        );

        self::assertSame('/image/glide/filesystem/photo.jpg', $pipeline->url(new ImageReference('photo.jpg'), new ImageTransformation(), transformer: 'glide'));
    }

    public function testPurgeUsesLoaderDefaultTransformer(): void
    {
        $privateGlide = $this->createMock(PurgableTransformerInterface::class);
        $privateGlide->expects(self::once())
            ->method('purge')
            ->with('contracts/42.jpg', ['loader' => 'private_files', 'transformer' => 'private_glide']);
        $this->expectNoLoadOrUrlOnSetUpMocks();

        $pipeline = $this->createPipeline([], ['glide' => $this->transformer, 'private_glide' => $privateGlide], ['private_files' => 'private_glide']);

        $pipeline->purge('contracts/42.jpg', 'private_files');
    }

    public function testResolveTransformerName(): void
    {
        $this->expectNoLoadOrUrlOnSetUpMocks();
        $pipeline = $this->createPipeline([], [], ['private_files' => 'private_glide']);

        self::assertSame('imgix', $pipeline->resolveTransformerName('imgix', 'private_files'), 'the given transformer wins');
        self::assertSame('private_glide', $pipeline->resolveTransformerName(null, 'private_files'), 'then the loader default');
        self::assertSame('glide', $pipeline->resolveTransformerName(null, 'filesystem'), 'then the global default');
        self::assertSame('glide', $pipeline->resolveTransformerName(), 'no loader name skips loader defaults');
    }

    public function testResolveTransformerNameThrowsWithoutAnyDefault(): void
    {
        $this->expectNoLoadOrUrlOnSetUpMocks();
        $pipeline = new ImagePipeline(new LoaderRegistry(new ServiceLocator([])), new TransformerRegistry(new ServiceLocator([])), 'filesystem', null);

        $this->expectException(TransformerNotFoundException::class);

        $pipeline->resolveTransformerName(null, 'filesystem');
    }

    private function expectNoLoadOrUrlOnSetUpMocks(): void
    {
        $this->loader->expects(self::never())->method('load');
        $this->transformer->expects(self::never())->method('url');
    }

    /**
     * @param array<string, ImageTransformerInterface> $transformers
     */
    private function createPurgePipeline(array $transformers): ImagePipeline
    {
        return new ImagePipeline(
            new LoaderRegistry(new ServiceLocator([])),
            new TransformerRegistry(new ServiceLocator(array_map(
                static fn (ImageTransformerInterface $transformer): Closure => static fn (): ImageTransformerInterface => $transformer,
                $transformers,
            ))),
            'filesystem',
            'glide',
        );
    }

    /**
     * @param array<string, ImageLoaderInterface>      $loaders
     * @param array<string, ImageTransformerInterface> $transformers
     * @param array<string, string>                    $defaultTransformers Loader name → default transformer name
     */
    private function createPipeline(array $loaders, array $transformers, array $defaultTransformers): ImagePipeline
    {
        return new ImagePipeline(
            new LoaderRegistry(new ServiceLocator(array_map(
                static fn (ImageLoaderInterface $loader): Closure => static fn (): ImageLoaderInterface => $loader,
                $loaders,
            )), defaultTransformers: $defaultTransformers),
            new TransformerRegistry(new ServiceLocator(array_map(
                static fn (ImageTransformerInterface $transformer): Closure => static fn (): ImageTransformerInterface => $transformer,
                $transformers,
            ))),
            'filesystem',
            'glide',
        );
    }
}
