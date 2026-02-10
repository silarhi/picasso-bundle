<?php

namespace Silarhi\PicassoBundle\Tests\Twig\Extension;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\ImageParams;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Twig\Extension\PicassoExtension;

class PicassoExtensionTest extends TestCase
{
    private ImageLoaderInterface $imageLoader;
    private ContainerInterface $loaders;

    protected function setUp(): void
    {
        $this->imageLoader = $this->createMock(ImageLoaderInterface::class);

        $this->loaders = $this->createMock(ContainerInterface::class);
        $this->loaders->method('get')
            ->willReturnCallback(function (string $key): ImageLoaderInterface {
                if ($key === 'glide' || $key === 'imgix') {
                    return $this->imageLoader;
                }
                throw new \InvalidArgumentException("Unknown loader: $key");
            });
    }

    public function testImageUrlDelegatesToDefaultLoader(): void
    {
        $this->imageLoader->expects(self::once())
            ->method('getUrl')
            ->with(
                'photo.jpg',
                self::callback(fn (ImageParams $p) => $p->width === 300 && $p->format === 'webp'),
            )
            ->willReturn('/picasso/image/photo.jpg?w=300&fm=webp&s=abc');

        $extension = new PicassoExtension($this->loaders, 'glide');
        $result = $extension->imageUrl('photo.jpg', ['width' => 300, 'format' => 'webp']);

        self::assertSame('/picasso/image/photo.jpg?w=300&fm=webp&s=abc', $result);
    }

    public function testImageUrlPassesAllAgnosticParams(): void
    {
        $this->imageLoader->expects(self::once())
            ->method('getUrl')
            ->with(
                'photo.jpg',
                self::callback(fn (ImageParams $p) => $p->width === 300
                        && $p->height === 200
                        && $p->format === 'avif'
                        && $p->quality === 90
                        && $p->fit === 'crop'
                        && $p->blur === 50
                        && $p->dpr === 2),
            )
            ->willReturn('/url');

        $extension = new PicassoExtension($this->loaders, 'glide');
        $extension->imageUrl('photo.jpg', [
            'width' => 300,
            'height' => 200,
            'format' => 'avif',
            'quality' => 90,
            'fit' => 'crop',
            'blur' => 50,
            'dpr' => 2,
        ]);
    }

    public function testImageUrlUsesExplicitLoader(): void
    {
        $imgixLoader = $this->createMock(ImageLoaderInterface::class);
        $imgixLoader->expects(self::once())
            ->method('getUrl')
            ->with('photo.jpg', self::isInstanceOf(ImageParams::class))
            ->willReturn('https://cdn.imgix.net/photo.jpg?w=300');

        $loaders = $this->createMock(ContainerInterface::class);
        $loaders->expects(self::once())
            ->method('get')
            ->with('imgix')
            ->willReturn($imgixLoader);

        $extension = new PicassoExtension($loaders, 'glide');
        $result = $extension->imageUrl('photo.jpg', [
            'width' => 300,
            'format' => 'webp',
            'loader' => 'imgix',
        ]);

        self::assertSame('https://cdn.imgix.net/photo.jpg?w=300', $result);
    }

    public function testRegistersTwigFunction(): void
    {
        $extension = new PicassoExtension($this->loaders, 'glide');

        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('picasso_image_url', $functions[0]->getName());
    }
}
