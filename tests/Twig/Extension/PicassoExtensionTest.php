<?php

namespace Silarhi\PicassoBundle\Tests\Twig\Extension;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageParams;
use Silarhi\PicassoBundle\Twig\Extension\PicassoExtension;
use Silarhi\PicassoBundle\Url\ImageUrlGeneratorInterface;

class PicassoExtensionTest extends TestCase
{
    public function testImageUrlDelegatesToUrlGenerator(): void
    {
        $urlGenerator = $this->createMock(ImageUrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                'photo.jpg',
                self::callback(fn (ImageParams $p) => $p->width === 300 && $p->format === 'webp'),
            )
            ->willReturn('/picasso/image/photo.jpg?w=300&fm=webp&s=abc');

        $extension = new PicassoExtension($urlGenerator);
        $result = $extension->imageUrl('photo.jpg', ['width' => 300, 'format' => 'webp']);

        self::assertSame('/picasso/image/photo.jpg?w=300&fm=webp&s=abc', $result);
    }

    public function testImageUrlPassesAllAgnosticParams(): void
    {
        $urlGenerator = $this->createMock(ImageUrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
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

        $extension = new PicassoExtension($urlGenerator);
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

    public function testRegistersTwigFunction(): void
    {
        $urlGenerator = $this->createMock(ImageUrlGeneratorInterface::class);
        $extension = new PicassoExtension($urlGenerator);

        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('picasso_image_url', $functions[0]->getName());
    }
}
