<?php

namespace Silarhi\PicassoBundle\Tests\Twig\Extension;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Twig\Extension\PicassoExtension;

class PicassoExtensionTest extends TestCase
{
    public function testImageUrlDelegatesToPipeline(): void
    {
        $pipeline = $this->createMock(ImagePipeline::class);
        $pipeline->expects(self::once())
            ->method('url')
            ->with(
                self::callback(fn (ImageReference $r) => $r->path === 'photo.jpg'),
                self::callback(fn (ImageTransformation $t) => $t->width === 300 && $t->format === 'webp'),
                null,
                null,
            )
            ->willReturn('/picasso/glide/filesystem/photo.jpg?w=300&fm=webp&s=abc');

        $extension = new PicassoExtension($pipeline);
        $result = $extension->imageUrl('photo.jpg', ['width' => 300, 'format' => 'webp']);

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

        $extension = new PicassoExtension($pipeline);
        $result = $extension->imageUrl('photo.jpg', [
            'width' => 300,
            'loader' => 'vich',
            'transformer' => 'imgix',
        ]);

        self::assertSame('https://cdn.imgix.net/photo.jpg?w=300', $result);
    }

    public function testRegistersTwigFunction(): void
    {
        $pipeline = $this->createMock(ImagePipeline::class);
        $extension = new PicassoExtension($pipeline);

        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('picasso_image_url', $functions[0]->getName());
    }
}
