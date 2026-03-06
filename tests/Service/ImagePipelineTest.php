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

use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;

class ImagePipelineTest extends TestCase
{
    private MockObject&ImageLoaderInterface $loader;
    private MockObject&ImageTransformerInterface $transformer;
    private ImagePipeline $pipeline;

    protected function setUp(): void
    {
        $this->loader = $this->createMock(ImageLoaderInterface::class);
        $this->transformer = $this->createMock(ImageTransformerInterface::class);

        $loaderLocator = $this->createMock(ContainerInterface::class);
        $loaderLocator->method('has')->willReturnCallback(static fn (string $key): bool => 'filesystem' === $key);
        $loaderLocator->method('get')->willReturnCallback(fn (string $key) => match ($key) {
            'filesystem' => $this->loader,
            default => throw new InvalidArgumentException("Unknown loader: $key"),
        });

        $transformerLocator = $this->createMock(ContainerInterface::class);
        $transformerLocator->method('has')->willReturnCallback(static fn (string $key): bool => 'glide' === $key);
        $transformerLocator->method('get')->willReturnCallback(fn (string $key) => match ($key) {
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
}
