<?php

namespace Silarhi\PicassoBundle\Tests\Twig\Extension;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Service\UrlGenerator;
use Silarhi\PicassoBundle\Twig\Extension\PicassoExtension;

class PicassoExtensionTest extends TestCase
{
    public function testImageUrlDelegatesToUrlGenerator(): void
    {
        $urlGenerator = $this->createMock(UrlGenerator::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('photo.jpg', ['w' => 300, 'fm' => 'webp'])
            ->willReturn('/picasso/image/photo.jpg?w=300&fm=webp&s=abc');

        $extension = new PicassoExtension($urlGenerator);
        $result = $extension->imageUrl('photo.jpg', ['w' => 300, 'fm' => 'webp']);

        self::assertSame('/picasso/image/photo.jpg?w=300&fm=webp&s=abc', $result);
    }

    public function testRegistersTwigFunction(): void
    {
        $urlGenerator = $this->createMock(UrlGenerator::class);
        $extension = new PicassoExtension($urlGenerator);

        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('picasso_image_url', $functions[0]->getName());
    }
}
