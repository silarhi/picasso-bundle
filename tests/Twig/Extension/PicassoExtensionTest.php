<?php

namespace Silarhi\PicassoBundle\Tests\Twig\Extension;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\ImageParams;
use Silarhi\PicassoBundle\Twig\Extension\PicassoExtension;
use Silarhi\PicassoBundle\Url\ImageUrlGeneratorInterface;

class PicassoExtensionTest extends TestCase
{
    private ImageUrlGeneratorInterface $urlGenerator;
    private ContainerInterface $providers;

    protected function setUp(): void
    {
        $this->urlGenerator = $this->createMock(ImageUrlGeneratorInterface::class);

        $this->providers = $this->createMock(ContainerInterface::class);
        $this->providers->method('get')
            ->willReturnCallback(function (string $key): ImageUrlGeneratorInterface {
                if ($key === 'glide' || $key === 'imgix') {
                    return $this->urlGenerator;
                }
                throw new \InvalidArgumentException("Unknown provider: $key");
            });
    }

    public function testImageUrlDelegatesToDefaultProvider(): void
    {
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                'photo.jpg',
                self::callback(fn (ImageParams $p) => $p->width === 300 && $p->format === 'webp'),
            )
            ->willReturn('/picasso/image/photo.jpg?w=300&fm=webp&s=abc');

        $extension = new PicassoExtension($this->providers, 'glide');
        $result = $extension->imageUrl('photo.jpg', ['width' => 300, 'format' => 'webp']);

        self::assertSame('/picasso/image/photo.jpg?w=300&fm=webp&s=abc', $result);
    }

    public function testImageUrlPassesAllAgnosticParams(): void
    {
        $this->urlGenerator->expects(self::once())
            ->method('generate')
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

        $extension = new PicassoExtension($this->providers, 'glide');
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

    public function testImageUrlUsesExplicitProvider(): void
    {
        $imgixGenerator = $this->createMock(ImageUrlGeneratorInterface::class);
        $imgixGenerator->expects(self::once())
            ->method('generate')
            ->with('photo.jpg', self::isInstanceOf(ImageParams::class))
            ->willReturn('https://cdn.imgix.net/photo.jpg?w=300');

        $providers = $this->createMock(ContainerInterface::class);
        $providers->expects(self::once())
            ->method('get')
            ->with('imgix')
            ->willReturn($imgixGenerator);

        $extension = new PicassoExtension($providers, 'glide');
        $result = $extension->imageUrl('photo.jpg', [
            'width' => 300,
            'format' => 'webp',
            'provider' => 'imgix',
        ]);

        self::assertSame('https://cdn.imgix.net/photo.jpg?w=300', $result);
    }

    public function testRegistersTwigFunction(): void
    {
        $extension = new PicassoExtension($this->providers, 'glide');

        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('picasso_image_url', $functions[0]->getName());
    }
}
