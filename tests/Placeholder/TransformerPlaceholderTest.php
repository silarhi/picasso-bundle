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

namespace Silarhi\PicassoBundle\Tests\Placeholder;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Placeholder\TransformerPlaceholder;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;

class TransformerPlaceholderTest extends TestCase
{
    public function testGenerateCreatesBlurUrl(): void
    {
        $transformer = $this->createMock(ImageTransformerInterface::class);
        $transformer->expects(self::once())
            ->method('url')
            ->with(
                self::isInstanceOf(Image::class),
                self::callback(static fn (ImageTransformation $t): bool => 10 === $t->width
                    && 6 === $t->height
                    && 'jpg' === $t->format
                    && 30 === $t->quality
                    && 'crop' === $t->fit
                    && 5 === $t->blur),
                ['loader' => 'filesystem', 'transformer' => 'glide'],
            )
            ->willReturn('/picasso/blur.jpg');

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('has')->with('glide')->willReturn(true);
        $locator->method('get')->with('glide')->willReturn($transformer);
        $registry = new TransformerRegistry($locator);

        $placeholder = new TransformerPlaceholder($registry);
        $result = $placeholder->generate(
            new Image(path: 'photo.jpg'),
            new ImageTransformation(width: 1920, height: 1080),
            ['loader' => 'filesystem', 'transformer' => 'glide'],
        );

        self::assertSame('/picasso/blur.jpg', $result);
    }

    public function testGenerateWithCustomSettings(): void
    {
        $transformer = $this->createMock(ImageTransformerInterface::class);
        $transformer->expects(self::once())
            ->method('url')
            ->with(
                self::isInstanceOf(Image::class),
                self::callback(static fn (ImageTransformation $t): bool => 20 === $t->width
                    && 10 === $t->height
                    && 50 === $t->quality
                    && 15 === $t->blur),
                self::anything(),
            )
            ->willReturn('/picasso/blur-custom.jpg');

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('has')->with('glide')->willReturn(true);
        $locator->method('get')->with('glide')->willReturn($transformer);
        $registry = new TransformerRegistry($locator);

        $placeholder = new TransformerPlaceholder($registry, size: 20, blur: 15, quality: 50);
        $result = $placeholder->generate(
            new Image(path: 'photo.jpg'),
            new ImageTransformation(width: 800, height: 400),
            ['loader' => 'filesystem', 'transformer' => 'glide'],
        );

        self::assertSame('/picasso/blur-custom.jpg', $result);
    }

    public function testGenerateThrowsWithoutTransformerInContext(): void
    {
        $locator = $this->createMock(ContainerInterface::class);
        $registry = new TransformerRegistry($locator);

        $placeholder = new TransformerPlaceholder($registry);

        $this->expectException(InvalidArgumentException::class);
        $placeholder->generate(new Image(path: 'photo.jpg'), new ImageTransformation(width: 100, height: 100));
    }
}
