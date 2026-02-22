<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;

class ImagePipelineTest extends TestCase
{
    private \PHPUnit\Framework\MockObject\MockObject $loader;
    private \PHPUnit\Framework\MockObject\MockObject $transformer;
    private ImagePipeline $pipeline;

    protected function setUp(): void
    {
        $this->loader = $this->createMock(ImageLoaderInterface::class);
        $this->transformer = $this->createMock(ImageTransformerInterface::class);

        $loaders = $this->createMock(ContainerInterface::class);
        $loaders->method('get')
            ->willReturnCallback(fn (string $key): \PHPUnit\Framework\MockObject\MockObject => match ($key) {
                'filesystem' => $this->loader,
                default => throw new \InvalidArgumentException("Unknown loader: $key"),
            });

        $transformers = $this->createMock(ContainerInterface::class);
        $transformers->method('get')
            ->willReturnCallback(fn (string $key): \PHPUnit\Framework\MockObject\MockObject => match ($key) {
                'glide' => $this->transformer,
                default => throw new \InvalidArgumentException("Unknown transformer: $key"),
            });

        $this->pipeline = new ImagePipeline($loaders, $transformers, 'filesystem', 'glide');
    }

    public function testUrlLoadsImageAndTransforms(): void
    {
        $image = new Image(path: 'uploads/photo.jpg', width: 1920, height: 1080);
        $reference = new ImageReference('uploads/photo.jpg');
        $transformation = new ImageTransformation(width: 300, format: 'webp');

        $this->loader->expects(self::once())
            ->method('load')
            ->with($reference)
            ->willReturn($image);

        $this->transformer->expects(self::once())
            ->method('url')
            ->with($image, $transformation, ['loader' => 'filesystem'])
            ->willReturn('/picasso/glide/filesystem/uploads/photo.jpg?w=300&fm=webp&s=abc');

        $url = $this->pipeline->url($reference, $transformation);

        self::assertSame('/picasso/glide/filesystem/uploads/photo.jpg?w=300&fm=webp&s=abc', $url);
    }

    public function testLoadReturnsImage(): void
    {
        $image = new Image(path: 'photo.jpg', width: 800, height: 600);
        $reference = new ImageReference('photo.jpg');

        $this->loader->expects(self::once())
            ->method('load')
            ->with($reference, true)
            ->willReturn($image);

        $result = $this->pipeline->load($reference);

        self::assertSame($image, $result);
    }

    public function testLoadPassesWithMetadata(): void
    {
        $reference = new ImageReference('photo.jpg');

        $this->loader->expects(self::once())
            ->method('load')
            ->with($reference, false)
            ->willReturn(new Image(path: 'photo.jpg'));

        $this->pipeline->load($reference, withMetadata: false);
    }
}
