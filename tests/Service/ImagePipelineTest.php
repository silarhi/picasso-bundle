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
}
