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
                self::callback(static fn (ImageReference $r): bool => 'photo.jpg' === $r->path),
                self::callback(static fn (ImageTransformation $t): bool => 300 === $t->width && 'webp' === $t->format),
                null,
                null,
            )
            ->willReturn('/picasso/glide/filesystem/photo.jpg?w=300&fm=webp&s=abc');

        $extension = new PicassoExtension($pipeline, 75, 'contain');
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

        $extension = new PicassoExtension($pipeline, 75, 'contain');
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
        $extension = new PicassoExtension($pipeline, 75, 'contain');

        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('picasso_image_url', $functions[0]->getName());
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

        $extension = new PicassoExtension($pipeline, 90, 'cover');
        $extension->imageUrl('photo.jpg');
    }
}
